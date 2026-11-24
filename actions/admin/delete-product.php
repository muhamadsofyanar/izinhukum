<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/products');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/products');
}

$productId = (int) ($_POST['product_id'] ?? 0);
if ($productId <= 0) {
    flash('error', 'Produk tidak valid.', 'error');
    redirect('/admin/products');
}

try {
    $pdo = getDB();

    // Cek produk ada
    $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        flash('error', 'Produk tidak ditemukan.', 'error');
        redirect('/admin/products');
    }

    // Cek apakah ada order aktif (status pending/paid) untuk produk ini
    $checkOrders = $pdo->prepare(
        "SELECT COUNT(*) FROM order_items oi
         JOIN orders o ON oi.order_id = o.id
         WHERE oi.product_id = ?
           AND o.status IN ('pending', 'paid')"
    );
    $checkOrders->execute([$productId]);
    $activeOrderCount = (int) $checkOrders->fetchColumn();

    if ($activeOrderCount > 0) {
        flash('error',
            'Produk "' . $product['name'] . '" tidak dapat dihapus karena masih memiliki ' .
            $activeOrderCount . ' order aktif.',
            'error'
        );
        redirect('/admin/products');
    }

    // Aman dihapus — hapus data terkait dalam transaksi
    $pdo->beginTransaction();

    // Hapus kelas produk ini (materials ter-cascade via FK)
    $pdo->prepare('DELETE FROM classes WHERE product_id = ?')->execute([$productId]);

    // Hapus member access yang terkait
    $pdo->prepare('DELETE FROM member_access WHERE product_id = ?')->execute([$productId]);

    // Hapus order items (hanya dari order cancelled/failed, karena active/paid sudah dicek)
    $pdo->prepare('DELETE oi FROM order_items oi
                   JOIN orders o ON oi.order_id = o.id
                   WHERE oi.product_id = ? AND o.status IN (\'cancelled\', \'failed\')')
        ->execute([$productId]);

    // Hapus produk
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);

    $pdo->commit();

    flash('success', 'Produk "' . $product['name'] . '" berhasil dihapus.');

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Delete product error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menghapus produk. Silakan coba lagi.', 'error');
}

redirect('/admin/products');
