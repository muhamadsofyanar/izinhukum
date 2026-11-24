<?php
/**
 * Xendit Payment Callback (Webhook)
 *
 * Endpoint PUBLIC, dipanggil server Xendit secara server-to-server.
 * TIDAK pakai CSRF. Verifikasi pakai header x-callback-token (bukan HMAC signature).
 * Daftarkan URL ini di dashboard Xendit: Settings > Callbacks
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../payment_gateways/XenditGateway.php';

header('Content-Type: application/json');

error_log('Xendit callback diterima dari IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

try {
    // ── STEP 1: Verifikasi token header ───────────────────────
    // Xendit mengirim token di header x-callback-token (lowercase setelah PHP normalisasi)
    $receivedToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';

    $xendit = new XenditGateway();

    if (!$xendit->verifyCallbackToken($receivedToken)) {
        error_log('Xendit callback: INVALID TOKEN, ditolak.');
        http_response_code(403);
        echo json_encode(['message' => 'Invalid token']);
        exit;
    }

    // Xendit kirim payload sebagai JSON body
    $rawInput     = file_get_contents('php://input');
    $callbackData = json_decode($rawInput, true);

    if (empty($callbackData) || !is_array($callbackData)) {
        http_response_code(400);
        echo json_encode(['message' => 'Invalid JSON payload']);
        exit;
    }

    $orderNumber = $callbackData['external_id'] ?? '';
    $status      = $callbackData['status'] ?? '';     // 'PAID' = sukses

    if (empty($orderNumber)) {
        http_response_code(400);
        echo json_encode(['message' => 'external_id missing']);
        exit;
    }

    // Log raw callback untuk audit
    error_log('Xendit callback: order=' . $orderNumber . ', status=' . $status);

    // ── STEP 2: Idempotency check ──────────────────────────────
    $idempotencyKey = hash('sha256', 'xendit_' . $orderNumber . '_' . $status);

    $pdo       = getDB();
    $checkStmt = $pdo->prepare("SELECT id FROM webhook_idempotency WHERE idempotency_key = ? LIMIT 1");
    $checkStmt->execute([$idempotencyKey]);

    if ($checkStmt->fetch()) {
        error_log('Xendit callback: DUPLICATE diabaikan. Order: ' . $orderNumber);
        echo json_encode(['message' => 'Already processed']);
        exit;
    }

    // ── STEP 3: Proses dalam satu database transaction ─────────
    $pdo->beginTransaction();

    // Insert idempotency key SEGERA
    $pdo->prepare("INSERT INTO webhook_idempotency (idempotency_key) VALUES (?)")
        ->execute([$idempotencyKey]);

    $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? LIMIT 1");
    $orderStmt->execute([$orderNumber]);
    $order = $orderStmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        error_log('Xendit callback: order tidak ditemukan: ' . $orderNumber);
        echo json_encode(['message' => 'Order not found']);
        exit;
    }

    if ($order['status'] === 'paid') {
        $pdo->commit();
        echo json_encode(['message' => 'Order already paid']);
        exit;
    }

    if ($status === 'PAID') {
        // ── Pembayaran SUKSES ──────────────────────────────────

        // Defense-in-depth: nominal yang dibayar HARUS sama dengan total order.
        $paidAmount = (float) ($callbackData['amount'] ?? 0);
        if ((int) round($paidAmount) !== (int) round((float) $order['total'])) {
            $pdo->rollBack();
            error_log('Xendit callback: AMOUNT MISMATCH order=' . $orderNumber
                . ' expected=' . $order['total'] . ' got=' . $paidAmount . ' — ditolak.');
            http_response_code(400);
            echo json_encode(['message' => 'Amount mismatch']);
            exit;
        }

        $xenditRef = $callbackData['id'] ?? '';

        // Tandai paid ATOMIK: hanya berhasil bila status masih != 'paid'. Bila 2
        // callback nyaris bersamaan lolos guard di atas (race), hanya SATU yang
        // rowCount=1 (row-lock MySQL); yang kalah rowCount=0 → berhenti tanpa
        // memproses ganda (cegah notif/komisi/aktivasi dobel).
        $upd = $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ? AND status <> 'paid'");
        $upd->execute([$order['id']]);
        if ($upd->rowCount() === 0) {
            $pdo->commit();
            error_log('Xendit callback: order ' . $orderNumber . ' sudah paid oleh proses lain (race), dilewati.');
            echo json_encode(['message' => 'Order already paid']);
            exit;
        }

        $pdo->prepare(
            "UPDATE payments SET status = 'success', paid_at = NOW(), gateway_ref = ? WHERE order_id = ?"
        )->execute([$xenditRef, $order['id']]);

        // Jalur paid terpusat: aktivasi akses (guard booking di dalam), sequence,
        // lisensi, komisi afiliasi, membership level. Lihat helpers.php.
        $paidCtx = onOrderPaid($pdo, $order, ['commission_fallback' => true]);

        $pdo->commit();

        // Balas Xendit SEKARANG — order sudah paid & idempotency tercatat. Aksi
        // post-commit di bawah memicu s/d 3 HTTP call notifikasi (email+WA buyer
        // + WA admin, timeout adapter 15-20 dtk masing-masing); dijalankan sinkron
        // sebelum respons dulu bikin callback Xendit timeout & retry berulang.
        lsRespondThenContinue(null, json_encode(['message' => 'OK']));

        // Aksi post-commit (background — buyer/gateway sudah dapat respons)

        if (function_exists('logActivity')) {
            logActivity('system', 'order.paid_via_gateway', 'order', (int) $order['id'],
                'Pembayaran Xendit sukses: ' . $orderNumber);
        }

        onOrderPaidPostCommit($order, $paidCtx, ['capi' => true, 'notify_access' => true]);
        exit;

    } elseif (in_array($status, ['EXPIRED', 'FAILED'], true)) {
        // ── Pembayaran GAGAL / expired ─────────────────────────
        $pdo->prepare("UPDATE orders SET status = 'failed' WHERE id = ?")->execute([$order['id']]);
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE order_id = ?")->execute([$order['id']]);
        $pdo->commit();

        error_log('Xendit callback: payment ' . $status . ', order=' . $orderNumber);

    } else {
        // Status lain (misal PENDING) — tidak perlu aksi, lepas transaction
        $pdo->commit();
        error_log('Xendit callback: status=' . $status . ' (ignored), order=' . $orderNumber);
    }

    echo json_encode(['message' => 'OK']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Xendit callback fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['message' => 'Internal error']);
}
