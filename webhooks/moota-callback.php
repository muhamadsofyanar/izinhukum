<?php
/**
 * Moota Mutation Webhook
 *
 * Endpoint PUBLIC, dipanggil server Moota saat ada mutasi masuk di rekening.
 * Moota berbeda dari Duitku/Xendit: bukan payment gateway, melainkan monitoring
 * mutasi bank. Alur: customer transfer manual → Moota deteksi mutasi → kirim webhook.
 *
 * Verifikasi RESMI (Moota V2): header `Signature` = HMAC-SHA256(raw_body, secret_token).
 * Fallback: header `Authorization: Bearer <secret_token>`. (?token= di URL tidak
 * didukung — bocor via log/history.) Daftarkan URL di dashboard Moota: Webhook > Add Endpoint.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../payment_gateways/MootaGateway.php';

header('Content-Type: application/json');

error_log('Moota callback diterima dari IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

try {
    // Raw body dibaca DULU — dipakai apa adanya untuk verifikasi signature
    // (jangan decode lalu re-encode, hash-nya akan beda).
    $rawInput = file_get_contents('php://input');

    $moota = new MootaGateway();

    // ── STEP 1: Verifikasi ─────────────────────────────────────
    // Utama: Signature header (HMAC) sesuai dokumentasi Moota.
    $signature = $_SERVER['HTTP_SIGNATURE'] ?? '';
    if ($signature === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $hk => $hv) {
            if (strcasecmp($hk, 'Signature') === 0) { $signature = $hv; break; }
        }
    }

    $verified = false;
    if ($signature !== '') {
        $verified = $moota->verifyWebhookSignature($rawInput, $signature);
    } else {
        // Fallback: token via header Authorization: Bearer <token>.
        // (Fallback ?token= di query string SENGAJA DIHAPUS — bocor lewat access log,
        //  browser history, & proxy. Pakai header Signature HMAC atau Bearer.)
        $authHeader    = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $receivedToken = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';
        $verified = $receivedToken !== '' && $moota->verifyCallbackToken($receivedToken);
    }

    if (!$verified) {
        error_log('Moota callback: verifikasi GAGAL (signature/token), ditolak.');
        http_response_code(403);
        echo json_encode(['message' => 'Invalid signature']);
        exit;
    }

    // Moota kirim JSON body
    $payload = json_decode($rawInput, true);

    if (empty($payload) || !is_array($payload)) {
        http_response_code(400);
        echo json_encode(['message' => 'Invalid JSON payload']);
        exit;
    }

    // Moota bisa kirim array of mutations atau single mutation
    $mutations = isset($payload[0]) ? $payload : [$payload];

    $pdo = getDB();

    // Balas Moota SEKARANG — verifikasi lolos & payload valid; idempotency per-mutasi
    // menjaga keamanan retry. Loop di bawah bisa memicu banyak HTTP call notifikasi
    // (email+WA per order paid, timeout adapter 15-20 dtk each); dijalankan sinkron
    // sebelum respons dulu bikin webhook Moota timeout & retry berulang.
    lsRespondThenContinue(null, json_encode(['message' => 'OK']));

    foreach ($mutations as $mutation) {
        $mutationId = $mutation['id'] ?? $mutation['mutation_id'] ?? uniqid('moota_', true);
        $amount     = (float) ($mutation['amount'] ?? 0);
        $type       = strtoupper($mutation['type'] ?? ''); // CR = kredit/masuk, DB = debit/keluar

        // Hanya proses mutasi masuk (kredit)
        if ($type === 'DB') {
            error_log('Moota callback: mutasi debit diabaikan, id=' . $mutationId);
            continue;
        }

        // ── STEP 2: Idempotency check per mutasi ──────────────
        $idempotencyKey = hash('sha256', 'moota_' . $mutationId);

        $checkStmt = $pdo->prepare("SELECT id FROM webhook_idempotency WHERE idempotency_key = ? LIMIT 1");
        $checkStmt->execute([$idempotencyKey]);

        if ($checkStmt->fetch()) {
            error_log('Moota callback: mutasi DUPLICATE diabaikan, id=' . $mutationId);
            continue;
        }

        // Coba cocokkan mutasi dengan order pending
        $order = $moota->matchMutationToOrder($mutation);

        if (!$order) {
            // Mutasi tidak cocok dengan order apapun — ini wajar (bisa transfer lain masuk)
            // Tetap catat idempotency agar tidak diproses lagi jika Moota retry
            $pdo->prepare("INSERT INTO webhook_idempotency (idempotency_key) VALUES (?)")
                ->execute([$idempotencyKey]);

            error_log('Moota callback: mutasi id=' . $mutationId .
                ', amount=' . $amount . ' — tidak ada order yang cocok, dilewati.');
            continue;
        }

        // ── STEP 3: Proses dalam satu database transaction ─────
        $pdo->beginTransaction();

        $pdo->prepare("INSERT INTO webhook_idempotency (idempotency_key) VALUES (?)")
            ->execute([$idempotencyKey]);

        if ($order['status'] === 'paid') {
            $pdo->commit();
            error_log('Moota callback: order=' . $order['order_number'] . ' sudah paid, dilewati.');
            continue;
        }

        // Defense-in-depth: nominal mutasi HARUS sama dengan total order. Penting
        // untuk Strategi-1 (cocok via nomor INV di keterangan) yang tidak cek nominal
        // → cegah underpayment (transfer kurang tapi sebut INV di berita transfer).
        if ((int) round($amount) !== (int) round((float) $order['total'])) {
            $pdo->rollBack();
            error_log('Moota callback: AMOUNT MISMATCH order=' . $order['order_number']
                . ' expected=' . $order['total'] . ' got=' . $amount . ' mutasi=' . $mutationId . ' — dilewati.');
            continue;
        }

        // ── Pembayaran SUKSES ──────────────────────────────────

        // Tandai paid ATOMIK (anti double-process saat mutasi/callback balapan):
        // hanya satu yang rowCount=1; yang kalah lewati iterasi ini.
        $upd = $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW(), payment_gateway = 'moota' WHERE id = ? AND status <> 'paid'");
        $upd->execute([$order['id']]);
        if ($upd->rowCount() === 0) {
            $pdo->commit();
            error_log('Moota callback: order=' . $order['order_number'] . ' sudah paid oleh proses lain (race), dilewati.');
            continue;
        }

        $pdo->prepare(
            "UPDATE payments SET status = 'success', paid_at = NOW(), gateway_ref = ? WHERE order_id = ?"
        )->execute([$mutationId, $order['id']]);

        // Jalur paid terpusat: aktivasi akses (guard booking di dalam), sequence,
        // lisensi, komisi afiliasi, membership level. Lihat helpers.php.
        $paidCtx = onOrderPaid($pdo, $order, ['commission_fallback' => true]);

        $pdo->commit();

        // Aksi post-commit

        if (function_exists('logActivity')) {
            logActivity('system', 'order.paid_via_gateway', 'order', (int) $order['id'],
                'Pembayaran Moota (mutasi bank) sukses: ' . $order['order_number'] .
                ', mutasi_id=' . $mutationId . ', amount=' . $amount);
        }

        onOrderPaidPostCommit($order, $paidCtx, ['capi' => true, 'notify_access' => true]);

        error_log('Moota callback: order=' . $order['order_number'] . ' berhasil diproses.');
    }

    // Respons OK sudah dikirim via lsRespondThenContinue di atas.
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Moota callback fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    // Respons error hanya bila belum terlanjur membalas OK (error pra-loop).
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(['message' => 'Internal error']);
    }
}
