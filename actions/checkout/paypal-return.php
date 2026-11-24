<?php
/**
 * PayPal Return Handler — pembeli diarahkan balik ke sini setelah approve di PayPal
 * (GET ?token={paypalOrderId}). Capture + verifikasi COMPLETED + cocokkan order +
 * nominal, lalu tandai lunas via pipeline onOrderPaid (sama seperti webhook gateway).
 * Redirect cepat ke halaman transaksi; notifikasi dijalankan di background.
 *
 * Cancel di PayPal → cancel_url langsung ke /transactions/{order}?paypal=cancel
 * (tidak lewat sini), order tetap pending.
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../payment_gateways/PayPalGateway.php';

$token = trim((string) ($_GET['token'] ?? ''));   // = PayPal order id
$pdo   = getDB();

function pp_redirect(string $path): void { redirect($path); }

if ($token === '') { pp_redirect('/dashboard'); }

// ── Cocokkan ke order kita (payment_reference = PayPal order id) ──
try {
    $st = $pdo->prepare(
        "SELECT * FROM orders WHERE payment_reference = ? AND payment_gateway = 'paypal' LIMIT 1"
    );
    $st->execute([$token]);
    $order = $st->fetch();
} catch (Exception $e) {
    error_log('paypal-return load order: ' . $e->getMessage());
    $order = null;
}
if (!$order) { pp_redirect('/dashboard'); }

$orderNumber = (string) $order['order_number'];
$txUrl = '/transactions/' . rawurlencode($orderNumber);

// Sudah lunas → idempoten, langsung ke halaman transaksi.
if ($order['status'] === 'paid') { pp_redirect($txUrl); }
if ($order['status'] !== 'pending') { pp_redirect($txUrl); }

// ── Ambil ekspektasi nominal USD dari payments.gateway_response ──
$expectedUsd = null;
try {
    $pr = $pdo->prepare("SELECT gateway_response FROM payments WHERE order_id = ? LIMIT 1");
    $pr->execute([(int) $order['id']]);
    $gr = $pr->fetchColumn();
    if ($gr) { $d = json_decode((string) $gr, true); if (is_array($d) && isset($d['usd_amount'])) $expectedUsd = (float) $d['usd_amount']; }
} catch (Exception $e) { /* abaikan; verifikasi custom_id tetap jalan */ }

// ── Capture di PayPal ──
$paypal = new PayPalGateway();
$cap = $paypal->captureOrder($token);

if (empty($cap['completed'])) {
    error_log('paypal-return: capture belum COMPLETED order=' . $orderNumber . ' msg=' . ($cap['message'] ?? ''));
    pp_redirect($txUrl . '?paypal=failed');
}

// ── Verifikasi kecocokan: custom_id = order kita, nominal sesuai ──
if ((string) ($cap['custom_id'] ?? '') !== $orderNumber) {
    error_log('paypal-return: custom_id mismatch order=' . $orderNumber . ' got=' . ($cap['custom_id'] ?? ''));
    pp_redirect($txUrl . '?paypal=failed');
}
if (($cap['currency'] ?? '') !== 'USD') {
    error_log('paypal-return: currency bukan USD order=' . $orderNumber);
    pp_redirect($txUrl . '?paypal=failed');
}
if ($expectedUsd !== null && abs((float) $cap['amount'] - $expectedUsd) > 0.01) {
    error_log('paypal-return: AMOUNT MISMATCH order=' . $orderNumber . ' expected=' . $expectedUsd . ' got=' . $cap['amount']);
    pp_redirect($txUrl . '?paypal=failed');
}

$captureId = (string) ($cap['capture_id'] ?? '');

// ── Tandai lunas (pola sama webhook) ──
$paidCtx = null;
try {
    // Idempotency: cegah dobel bila return dipanggil dua kali.
    $idemKey = hash('sha256', 'paypal_capture_' . ($captureId !== '' ? $captureId : $token));
    $chk = $pdo->prepare("SELECT id FROM webhook_idempotency WHERE idempotency_key = ? LIMIT 1");
    $chk->execute([$idemKey]);
    if ($chk->fetch()) { pp_redirect($txUrl); }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO webhook_idempotency (idempotency_key) VALUES (?)")->execute([$idemKey]);

    // Re-load order dalam transaksi + guard status.
    $ro = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $ro->execute([(int) $order['id']]);
    $order = $ro->fetch();
    if (!$order || $order['status'] === 'paid') { $pdo->commit(); pp_redirect($txUrl); }

    // Tandai paid ATOMIK (anti double-process bila return/webhook balapan): bila
    // rowCount=0 berarti proses lain sudah menandai paid → jangan proses ganda.
    $upd = $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ? AND status <> 'paid'");
    $upd->execute([(int) $order['id']]);
    if ($upd->rowCount() === 0) { $pdo->commit(); pp_redirect($txUrl); }

    // Simpan detail capture ke payments.gateway_response (merge).
    $mergeJson = json_encode([
        'provider'        => 'paypal',
        'paypal_order_id' => $token,
        'capture_id'      => $captureId,
        'currency'        => 'USD',
        'usd_amount'      => (float) $cap['amount'],
        'expected_idr'    => (float) $order['total'],
        'captured_at'     => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("UPDATE payments SET status = 'success', paid_at = NOW(), gateway_ref = ?, gateway_response = ? WHERE order_id = ?")
        ->execute([$captureId, $mergeJson, (int) $order['id']]);

    // Pipeline paid terpusat (akses, sequence, lisensi, komisi, membership).
    $paidCtx = onOrderPaid($pdo, $order, ['commission_fallback' => true]);

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('paypal-return mark-paid error: ' . $e->getMessage());
    pp_redirect($txUrl . '?paypal=failed');
}

// ── Redirect cepat, lalu post-commit (notifikasi) di background ──
lsRespondThenContinue($txUrl);

try {
    if (function_exists('logActivity')) {
        logActivity('system', 'order.paid_via_gateway', 'order', (int) $order['id'],
            'Pembayaran PayPal sukses: ' . $orderNumber . ' (capture ' . $captureId . ')');
    }
    if ($paidCtx !== null) {
        onOrderPaidPostCommit($order, $paidCtx, ['capi' => true, 'notify_access' => true]);
    }
} catch (Throwable $e) {
    error_log('paypal-return post-commit error: ' . $e->getMessage());
}
