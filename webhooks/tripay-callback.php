<?php
/**
 * Tripay Payment Callback (Webhook)
 *
 * Endpoint PUBLIC dipanggil server Tripay. Body = JSON. WAJIB verifikasi
 * header X-Callback-Signature = HMAC-SHA256(raw body, PrivateKey).
 * Daftarkan URL ini di Dashboard Tripay → Merchant → Callback URL.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../payment_gateways/TripayGateway.php';

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
$sig  = $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] ?? '';

if (!is_array($data) || empty($data['merchant_ref'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

try {
    $tripay = new TripayGateway();

    // ── STEP 1: Verifikasi signature (raw body) ──
    if (!$tripay->verifyCallbackSignature($raw, (string) $sig)) {
        error_log('Tripay callback: INVALID SIGNATURE, ditolak. Ref: ' . ($data['merchant_ref'] ?? '-'));
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid signature']);
        exit;
    }

    $orderNumber = (string) $data['merchant_ref'];
    $status      = (string) ($data['status'] ?? '');
    $mapped      = $tripay->mapStatus($status);

    // ── STEP 2: Idempotency (per order + status) ──
    $idempotencyKey = hash('sha256', 'tripay_' . $orderNumber . '_' . strtoupper($status));

    $pdo       = getDB();
    $checkStmt = $pdo->prepare("SELECT id FROM webhook_idempotency WHERE idempotency_key = ? LIMIT 1");
    $checkStmt->execute([$idempotencyKey]);
    if ($checkStmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Already processed']);
        exit;
    }

    // ── STEP 3: Proses dalam transaksi DB ──
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO webhook_idempotency (idempotency_key) VALUES (?)")->execute([$idempotencyKey]);

    $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? LIMIT 1");
    $orderStmt->execute([$orderNumber]);
    $order = $orderStmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        error_log('Tripay callback: order tidak ditemukan: ' . $orderNumber);
        echo json_encode(['success' => true, 'message' => 'Order not found']); // 200 agar tak retry selamanya
        exit;
    }

    if ($order['status'] === 'paid') {
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Order already paid']);
        exit;
    }

    if ($mapped === 'paid') {
        // Defense-in-depth: nominal transaksi HARUS sama dengan total order (IDR bulat).
        $paidAmount = (float) ($data['total_amount'] ?? $data['amount_received'] ?? 0);
        if ((int) round($paidAmount) !== (int) round((float) $order['total'])) {
            $pdo->rollBack();
            error_log('Tripay callback: AMOUNT MISMATCH order=' . $orderNumber
                . ' expected=' . $order['total'] . ' got=' . $paidAmount . ' — ditolak.');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Amount mismatch']);
            exit;
        }

        // Tandai paid ATOMIK (anti double-process saat 2 callback balapan): hanya
        // satu yang rowCount=1; yang kalah berhenti tanpa proses ganda.
        $upd = $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ? AND status <> 'paid'");
        $upd->execute([$order['id']]);
        if ($upd->rowCount() === 0) {
            $pdo->commit();
            error_log('Tripay callback: order ' . $orderNumber . ' sudah paid oleh proses lain (race), dilewati.');
            echo json_encode(['success' => true, 'message' => 'Order already paid']);
            exit;
        }
        $pdo->prepare(
            "UPDATE payments SET status = 'success', paid_at = NOW(), gateway_ref = ? WHERE order_id = ?"
        )->execute([(string) ($data['reference'] ?? ''), $order['id']]);

        $paidCtx = onOrderPaid($pdo, $order, ['commission_fallback' => true]);
        $pdo->commit();

        // Balas Tripay SEKARANG (harus body "success": true); notifikasi di background.
        lsRespondThenContinue(null, json_encode(['success' => true]));

        if (function_exists('logActivity')) {
            logActivity('system', 'order.paid_via_gateway', 'order', (int) $order['id'],
                'Pembayaran Tripay sukses: ' . $orderNumber);
        }
        onOrderPaidPostCommit($order, $paidCtx, ['capi' => true, 'notify_access' => true]);
        exit;

    } elseif ($mapped === 'failed' || $mapped === 'expired') {
        $newStatus = $mapped === 'expired' ? 'expired' : 'failed';
        $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$newStatus, $order['id']]);
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE order_id = ?")->execute([$order['id']]);
        $pdo->commit();
        error_log('Tripay callback: order=' . $orderNumber . ' status=' . $status . ' → ' . $newStatus);

    } else {
        // UNPAID / pending → biarkan pending.
        $pdo->commit();
    }

    echo json_encode(['success' => true, 'message' => 'OK']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Tripay callback fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error']);
}
