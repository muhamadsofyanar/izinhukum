<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/product-bumps');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/product-bumps');
}

$bumpId    = (int) ($_POST['bump_id']    ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);

if ($bumpId <= 0) {
    flash('error', 'Bump tidak valid.', 'error');
    redirect('/admin/product-bumps' . ($productId ? '?product_id=' . $productId : ''));
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("DELETE FROM product_bumps WHERE id = ?");
    $stmt->execute([$bumpId]);

    if ($stmt->rowCount() > 0) {
        flash('success', 'Bump berhasil dihapus.');
    } else {
        flash('error', 'Bump tidak ditemukan.', 'error');
    }

} catch (Exception $e) {
    error_log('Delete product bump error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menghapus bump.', 'error');
}

redirect('/admin/product-bumps' . ($productId ? '?product_id=' . $productId : ''));
