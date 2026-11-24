<?php
/**
 * Duitku Payment Callback (Webhook)
 *
 * Endpoint PUBLIC ini dipanggil server Duitku secara server-to-server.
 * TIDAK pakai CSRF (bukan request dari browser), tapi WAJIB verifikasi signature HMAC-SHA256.
 * URL ini dikirim otomatis sebagai callbackUrl setiap create invoice (bukan
 * didaftarkan manual di dashboard Duitku, beda dengan Xendit/Moota).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../payment_gateways/DuitkuGateway.php';

header('Content-Type: application/json');

// Log raw request untuk debugging awal (hapus di production jika noise)
error_log('Duitku callback diterima: ' . json_encode($_POST));

try {
    // Duitku mengirim callback sebagai POST form-data
    $callbackData = $_POST;

    if (empty($callbackData['merchantOrderId'])) {
        http_response_code(400);
        echo json_encode(['message' => 'Invalid payload: merchantOrderId missing']);
        exit;
    }

    $duitku = new DuitkuGateway();

    // ── STEP 1: Verifikasi signature ───────────────────────────
    // JANGAN proses apapun jika signature tidak valid
    if (!$duitku->verifyCallbackSignature($callbackData)) {
        error_log('Duitku callback: INVALID SIGNATURE, ditolak. Order: ' . $callbackData['merchantOrderId']);
        http_response_code(403);
        echo json_encode(['message' => 'Invalid signature']);
        exit;
    }

    $orderNumber = $callbackData['merchantOrderId'];
    $resultCode  = $callbackData['resultCode'] ?? ''; // '00' = sukses di Duitku

    // ── STEP 2: Idempotency check ──────────────────────────────
    // Cegah proses ganda jika Duitku mengirim callback yang sama lebih dari sekali
    $idempotencyKey = hash('sha256', 'duitku_' . $orderNumber . '_' . $resultCode);

    $pdo       = getDB();
    $checkStmt = $pdo->prepare("SELECT id FROM webhook_idempotency WHERE idempotency_key = ? LIMIT 1");
    $checkStmt->execute([$idempotencyKey]);

    if ($checkStmt->fetch()) {
        error_log('Duitku callback: DUPLICATE diabaikan. Order: ' . $orderNumber);
        // Tetap return 200 agar Duitku tidak retry terus-menerus
        echo json_encode(['message' => 'Already processed']);
        exit;
    }

    // ── STEP 3: Proses dalam satu database transaction ─────────
    $pdo->beginTransaction();

    // Insert idempotency key SEGERA agar request paralel/duplikat tidak lolos
    $pdo->prepare("INSERT INTO webhook_idempotency (idempotency_key) VALUES (?)")
        ->execute([$idempotencyKey]);

    // Load order berdasarkan order_number
    $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? LIMIT 1");
    $orderStmt->execute([$orderNumber]);
    $order = $orderStmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        error_log('Duitku callback: order tidak ditemukan: ' . $orderNumber);
        // Return 200 tetap (bukan 404) supaya Duitku tidak retry
        echo json_encode(['message' => 'Order not found']);
        exit;
    }

    if ($order['status'] === 'paid') {
        // Order sudah paid sebelumnya (misal dikonfirmasi admin manual duluan)
        $pdo->commit();
        echo json_encode(['message' => 'Order already paid']);
        exit;
    }

    if ($resultCode === '00') {
        // ── Pembayaran SUKSES ──────────────────────────────────

        // Defense-in-depth: nominal yang dibayar HARUS sama dengan total order.
        // (IDR = bilangan bulat; bandingkan sebagai integer rupiah.)
        $paidAmount = (float) ($callbackData['amount'] ?? 0);
        if ((int) round($paidAmount) !== (int) round((float) $order['total'])) {
            $pdo->rollBack();
            error_log('Duitku callback: AMOUNT MISMATCH order=' . $orderNumber
                . ' expected=' . $order['total'] . ' got=' . $paidAmount . ' — ditolak.');
            http_response_code(400);
            echo json_encode(['message' => 'Amount mismatch']);
            exit;
        }

        // Tandai paid ATOMIK (anti double-process saat 2 callback balapan): hanya
        // satu yang rowCount=1; yang kalah berhenti tanpa proses ganda.
        $upd = $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ? AND status <> 'paid'");
        $upd->execute([$order['id']]);
        if ($upd->rowCount() === 0) {
            $pdo->commit();
            error_log('Duitku callback: order ' . $orderNumber . ' sudah paid oleh proses lain (race), dilewati.');
            echo json_encode(['message' => 'Order already paid']);
            exit;
        }

        $pdo->prepare(
            "UPDATE payments SET status = 'success', paid_at = NOW(), gateway_ref = ? WHERE order_id = ?"
        )->execute([$callbackData['reference'] ?? '', $order['id']]);

        // Jalur paid terpusat: aktivasi akses (guard booking di dalam), sequence,
        // lisensi, komisi afiliasi, membership level. Lihat helpers.php.
        $paidCtx = onOrderPaid($pdo, $order, ['commission_fallback' => true]);

        $pdo->commit();

        // Balas Duitku SEKARANG — order sudah paid & idempotency tercatat.
        // Notifikasi (s/d 3 HTTP call, timeout 15-20 dtk each) jalan setelahnya,
        // agar callback gateway tidak timeout & retry berulang.
        lsRespondThenContinue(null, json_encode(['message' => 'OK']));

        // ── Aksi setelah commit (background — gateway sudah dapat respons) ─

        if (function_exists('logActivity')) {
            logActivity('system', 'order.paid_via_gateway', 'order', (int) $order['id'],
                'Pembayaran Duitku sukses: ' . $orderNumber);
        }

        onOrderPaidPostCommit($order, $paidCtx, ['capi' => true, 'notify_access' => true]);
        exit;

    } else {
        // ── Pembayaran GAGAL / dibatalkan ─────────────────────
        $pdo->prepare("UPDATE orders SET status = 'failed' WHERE id = ?")->execute([$order['id']]);
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE order_id = ?")->execute([$order['id']]);
        $pdo->commit();

        error_log('Duitku callback: payment failed, resultCode=' . $resultCode . ', order=' . $orderNumber);
    }

    echo json_encode(['message' => 'OK']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Duitku callback fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['message' => 'Internal error']);
}
