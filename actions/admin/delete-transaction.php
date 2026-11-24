<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/transactions');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/transactions');
}

$orderNumber = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_POST['order_number'] ?? '')));

if ($orderNumber === '') {
    flash('error', 'Order tidak valid.', 'error');
    redirect('/admin/transactions');
}

try {
    $pdo = getDB();

    // Verifikasi order ada
    $stmt = $pdo->prepare('SELECT id, order_number, status FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('error', 'Order tidak ditemukan.', 'error');
        redirect('/admin/transactions');
    }

    // Hapus permanen. Relasi dependent (order_items, payments, commissions,
    // coupon_usages, member_access) ikut terhapus via FK ON DELETE CASCADE.
    // CATATAN: menghapus order LUNAS juga mencabut akses member & menghapus
    // histori komisi/pembayaran terkait — ini disengaja sesuai permintaan admin.
    $isPaid = $order['status'] === 'paid';
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$order['id']]);

    logActivity('admin', 'transaction.delete', 'order', (int) $order['id'],
        'Order ' . $order['order_number'] . ' (' . $order['status'] . ') dihapus'
        . ($isPaid ? ' — akses member, komisi & pembayaran terkait ikut terhapus.' : '.'));
    flash('success', $isPaid
        ? 'Order lunas "' . $order['order_number'] . '" dihapus beserta akses member & data terkaitnya.'
        : 'Order "' . $order['order_number'] . '" berhasil dihapus.');

} catch (Exception $e) {
    error_log('Delete transaction error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menghapus transaksi.', 'error');
}

redirect('/admin/transactions');
