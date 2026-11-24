<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('transactions')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/transactions');
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$reason  = trim($_POST['rejection_reason'] ?? '');
if ($reason === '') $reason = 'Bukti transfer tidak valid.';
$reason  = mb_substr($reason, 0, 255);

try {
    $pdo = getDB();

    $oStmt = $pdo->prepare("SELECT id, status, order_number FROM orders WHERE id = ? LIMIT 1");
    $oStmt->execute([$orderId]);
    $order = $oStmt->fetch();

    if (!$order) {
        flash('error', 'Order tidak ditemukan.', 'error');
        redirect('/admin/transactions');
    }
    if ($order['status'] !== 'pending') {
        flash('error', 'Order sudah diproses, tidak bisa menolak bukti.', 'error');
        redirect('/admin/transactions/' . $order['order_number']);
    }

    // Order tetap pending — hapus bukti & catat alasan agar user bisa upload ulang.
    $pdo->prepare(
        "UPDATE payments SET proof_file = NULL, proof_uploaded_at = NULL, rejection_reason = ? WHERE order_id = ? AND method = 'manual_transfer'"
    )->execute([$reason, $orderId]);

    logActivity('admin', 'payment.reject_proof', 'order', $orderId, 'Tolak bukti transfer order ' . $order['order_number'] . ': ' . $reason);
    flash('success', 'Bukti transfer ditolak. User dapat mengupload ulang.');
    redirect('/admin/transactions/' . $order['order_number']);

} catch (Exception $e) {
    error_log('reject-payment error: ' . $e->getMessage());
    flash('error', 'Gagal menolak bukti.', 'error');
    redirect('/admin/transactions');
}
