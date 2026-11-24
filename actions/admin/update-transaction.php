<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('transactions')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/transactions');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/transactions');
}

$orderNumber  = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_POST['order_number'] ?? '')));
$newStatus    = trim($_POST['status'] ?? '');
$notes        = trim($_POST['notes'] ?? '');
$redirectBack = trim($_POST['redirect_to'] ?? '') === 'detail';

$allowedStatuses = ['paid', 'pending', 'failed', 'cancelled'];
if ($orderNumber === '' || !in_array($newStatus, $allowedStatuses, true)) {
    flash('error', 'Data tidak valid.', 'error');
    redirect('/admin/transactions');
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT o.*, GROUP_CONCAT(oi.product_id) AS product_ids
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.order_number = ?
         GROUP BY o.id
         LIMIT 1'
    );
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('error', 'Order tidak ditemukan.', 'error');
        redirect('/admin/transactions');
    }

    if ($order['status'] === $newStatus) {
        flash('info', 'Status order sudah ' . $newStatus . '.', 'info');
        redirect('/admin/transactions');
    }

    // Pipeline lengkap dipusatkan di changeOrderStatus() (dipakai bersama aksi
    // massal) — lock, update orders+payments, jalur paid/cancelled, notifikasi.
    $res = changeOrderStatus($pdo, $order, $newStatus, $notes);

    if (!$res['ok']) {
        flash('error', 'Terjadi kesalahan saat memperbarui transaksi. Silakan coba lagi.', 'error');
        redirect($redirectBack ? '/admin/transactions/' . $orderNumber : '/admin/transactions');
    }
    if (!$res['changed']) {
        flash('info', 'Status order sudah ' . $newStatus . ' (diproses oleh request lain).', 'info');
        redirect($redirectBack ? '/admin/transactions/' . $orderNumber : '/admin/transactions');
    }

    if ($newStatus === 'paid') {
        logActivity('admin', 'order.mark_paid', 'order', (int) $order['id'], 'Order ditandai paid: ' . $orderNumber);
    }

    $label = match ($newStatus) {
        'paid'      => 'dikonfirmasi sebagai LUNAS',
        'cancelled' => 'dibatalkan',
        'failed'    => 'ditandai GAGAL',
        'pending'   => 'dikembalikan ke PENDING',
        default     => 'diperbarui',
    };

    flash('success', 'Order ' . $orderNumber . ' berhasil ' . $label . '.');

} catch (Exception $e) {
    error_log('Update transaction error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat memperbarui transaksi. Silakan coba lagi.', 'error');
}

redirect($redirectBack ? '/admin/transactions/' . $orderNumber : '/admin/transactions');
