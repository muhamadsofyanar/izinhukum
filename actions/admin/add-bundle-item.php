<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/bundles'); }

$bundleId  = (int) ($_POST['bundle_id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);
$pdo = getDB();
$b = $pdo->prepare("SELECT id FROM product_bundles WHERE id=?"); $b->execute([$bundleId]);
$p = $pdo->prepare("SELECT id FROM products WHERE id=? AND status='active'"); $p->execute([$productId]);
if (!$b->fetchColumn() || !$p->fetchColumn()) { flash('error', 'Bundle/produk tidak valid.', 'error'); redirect('/admin/bundles/edit?id=' . $bundleId); }

$dup = $pdo->prepare("SELECT id FROM product_bundle_items WHERE bundle_id=? AND product_id=?"); $dup->execute([$bundleId, $productId]);
if ($dup->fetchColumn()) { flash('warning', 'Produk sudah ada di bundle.', 'warning'); redirect('/admin/bundles/edit?id=' . $bundleId); }

try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO product_bundle_items (bundle_id, product_id, sort_order) VALUES (?, ?, (SELECT COALESCE(MAX(s.sort_order),0)+1 FROM (SELECT sort_order FROM product_bundle_items WHERE bundle_id=?) s))")
        ->execute([$bundleId, $productId, $bundleId]);
    $sum = $pdo->prepare("SELECT COALESCE(SUM(p.price),0) FROM product_bundle_items pbi JOIN products p ON p.id=pbi.product_id WHERE pbi.bundle_id=?");
    $sum->execute([$bundleId]);
    $pdo->prepare("UPDATE product_bundles SET original_price=?, updated_at=NOW() WHERE id=?")->execute([(float) $sum->fetchColumn(), $bundleId]);
    $pdo->commit();
    flash('success', 'Produk ditambahkan ke bundle.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('add-bundle-item error: ' . $e->getMessage());
    flash('error', 'Gagal menambah produk.', 'error');
}
redirect('/admin/bundles/edit?id=' . $bundleId);
