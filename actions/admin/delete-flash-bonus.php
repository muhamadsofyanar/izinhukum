<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/flash-bonus');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/flash-bonus');
}

$offerId   = (int) ($_POST['offer_id']   ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);

if ($offerId <= 0) {
    flash('error', 'Bonus tidak valid.', 'error');
    redirect('/admin/flash-bonus' . ($productId ? '?product_id=' . $productId : ''));
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("DELETE FROM flash_bonus_offers WHERE id = ?");
    $stmt->execute([$offerId]);

    if ($stmt->rowCount() > 0) {
        flash('success', 'Bonus berhasil dihapus.');
    } else {
        flash('error', 'Bonus tidak ditemukan.', 'error');
    }

} catch (Exception $e) {
    error_log('Delete flash bonus error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menghapus bonus.', 'error');
}

redirect('/admin/flash-bonus' . ($productId ? '?product_id=' . $productId : ''));
