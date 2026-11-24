<?php
/**
 * Midtrans Payment Notification (Webhook)
 *
 * Endpoint PUBLIC dipanggil server Midtrans (HTTP Notification / Payment Notification).
 * Body = JSON. TIDAK pakai CSRF (bukan request browser), tapi WAJIB verifikasi
 * signature SHA512(order_id + status_code + gross_amount + serverKey).
 *
 * Daftarkan URL ini di Dashboard Midtrans → Settings → Configuration →
 * "Payment Notification URL".
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../payment_gateways/MidtransGateway.php';

header('Content-Type: application/json');

// Body Midtrans = JSON (bukan form-data).
$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);

if (!is_array($data) || empty($data['order_id'])) {
    http_response_code(400);
    echo json_encode(['message' => 'Invalid payload']);
    exit;
}

try {
    $midtrans = new MidtransGateway();

    // ── STEP 1: Verifikasi signature — JANGAN proses apa pun bila gagal ──
    if (!$midtrans->verifyNotificationSignature($data)) {
        error_log('Midtrans callback: INVALID SIGNATURE, ditolak. Order: ' . ($data['order_id'] ?? '-'));
        http_response_code(403);
        echo json_encode(['message' => 'Invalid signature']);
        exit;
    }

    $orderNumber       = (string) $data['order_id'];
    $transactionStatus = (string) ($data['transaction_status'] ?? '');
    $fraudStatus       = (string) ($data['fraud_status'] ?? '');
    $mapped            = $midtrans->mapStatus($transactionStatus, $fraudStatus);

    // ── STEP 2: Idempotency (per order + transaction_status) ──
    // Midtrans bisa kirim beberapa notifikasi (pending → settlement); keying pada
    // transaction_status membedakan transisi sah dari duplikat identik.
    $idempotencyKey = hash('sha256', 'midtrans_' . $orderNumber . '_' . $transactionStatus);

    $pdo       = getDB();
    $checkStmt = $pdo->prepare("SELECT id FROM webhook_idempotency WHERE idempotency_key = ? LIMIT 1");
    $checkStmt->execute([$idempotencyKey]);
    if ($checkStmt->fetch()) {
        error_log('Midtrans callback: DUPLICATE diabaikan. Order: ' . $orderNumber . ' status: ' . $transactionStatus);
        echo json_encode(['message' => 'Already processed']);
        exit;
    }

    // ── STEP 3: Proses dalam satu transaksi DB ──
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO webhook_idempotency (idempotency_key) VALUES (?)")->execute([$idempotencyKey]);

    $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ? LIMIT 1");
    $orderStmt->execute([$orderNumber]);
    $order = $orderStmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        error_log('Midtrans callback: order tidak ditemukan: ' . $orderNumber);
        echo json_encode(['message' => 'Order not found']); // 200 agar tidak di-retry selamanya
        exit;
    }

    if ($order['status'] === 'paid') {
        // Sudah lunas (no downgrade) — mis. dikonfirmasi admin/gateway lebih dulu.
        $pdo->commit();
        echo json_encode(['message' => 'Order already paid']);
        exit;
    }

    if ($mapped === 'paid') {
        // Defense-in-depth: nominal dibayar HARUS sama dengan total order (IDR bulat).
        $paidAmount = (float) ($data['gross_amount'] ?? 0);
        if ((int) round($paidAmount) !== (int) round((float) $order['total'])) {
            $pdo->rollBack();
            error_log('Midtrans callback: AMOUNT MISMATCH order=' . $orderNumber
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
            error_log('Midtrans callback: order ' . $orderNumber . ' sudah paid oleh proses lain (race), dilewati.');
            echo json_encode(['message' => 'Order already paid']);
            exit;
        }
        $pdo->prepare(
            "UPDATE payments SET status = 'success', paid_at = NOW(), gateway_ref = ? WHERE order_id = ?"
        )->execute([(string) ($data['transaction_id'] ?? ''), $order['id']]);

        // Jalur paid terpusat: aktivasi akses (guard booking), sequence, lisensi,
        // komisi afiliasi, membership level.
        $paidCtx = onOrderPaid($pdo, $order, ['commission_fallback' => true]);

        $pdo->commit();

        // Balas Midtrans SEKARANG; notifikasi (email/WA) di background.
        lsRespondThenContinue(null, json_encode(['message' => 'OK']));

        if (function_exists('logActivity')) {
            logActivity('system', 'order.paid_via_gateway', 'order', (int) $order['id'],
                'Pembayaran Midtrans sukses: ' . $orderNumber);
        }
        onOrderPaidPostCommit($order, $paidCtx, ['capi' => true, 'notify_access' => true]);
        exit;

    } elseif ($mapped === 'failed' || $mapped === 'expired') {
        $newStatus = $mapped === 'expired' ? 'expired' : 'failed';
        $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$newStatus, $order['id']]);
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE order_id = ?")->execute([$order['id']]);
        $pdo->commit();
        error_log('Midtrans callback: order=' . $orderNumber . ' status=' . $transactionStatus . ' → ' . $newStatus);

    } else {
        // pending → biarkan order tetap pending (belum ada aksi).
        $pdo->commit();
    }

    echo json_encode(['message' => 'OK']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Midtrans callback fatal error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['message' => 'Internal error']);
}
