<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/bundles'); }

$bundleId = (int) ($_POST['bundle_id'] ?? 0);
$pdo = getDB();
$b = $pdo->prepare("SELECT id, name FROM product_bundles WHERE id=?"); $b->execute([$bundleId]);
$bundle = $b->fetch();
if (!$bundle) { flash('error', 'Bundle tidak ditemukan.', 'error'); redirect('/admin/bundles'); }

$used = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE bundle_id=?"); $used->execute([$bundleId]);
if ((int) $used->fetchColumn() > 0) {
    $pdo->prepare("UPDATE product_bundles SET status='inactive' WHERE id=?")->execute([$bundleId]);
    flash('warning', 'Bundle sudah punya transaksi → diubah ke nonaktif (tidak dihapus).', 'warning');
} else {
    $pdo->prepare("DELETE FROM product_bundles WHERE id=?")->execute([$bundleId]);
    flash('success', 'Bundle dihapus.');
}
logActivity('admin', 'bundle.delete', 'bundle', $bundleId, 'Bundle: ' . $bundle['name']);
redirect('/admin/bundles');
