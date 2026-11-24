<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/bundles'); }

$bundleId  = (int) ($_POST['bundle_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);
$pdo = getDB();
try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM product_bundle_items WHERE bundle_id=? AND product_id=?")->execute([$bundleId, $productId]);
    $sum = $pdo->prepare("SELECT COALESCE(SUM(p.price),0) FROM product_bundle_items pbi JOIN products p ON p.id=pbi.product_id WHERE pbi.bundle_id=?");
    $sum->execute([$bundleId]);
    $pdo->prepare("UPDATE product_bundles SET original_price=?, updated_at=NOW() WHERE id=?")->execute([(float) $sum->fetchColumn(), $bundleId]);
    $pdo->commit();
    flash('success', 'Produk dihapus dari bundle.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('remove-bundle-item error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus produk.', 'error');
}
redirect('/admin/bundles/edit?id=' . $bundleId);
