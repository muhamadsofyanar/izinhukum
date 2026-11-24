<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/bundles'); }

$id          = (int) ($_POST['id'] ?? 0);
$name        = trim($_POST['name'] ?? '');
$slug        = trim($_POST['slug'] ?? '');
$tagline     = trim($_POST['tagline'] ?? '');
$description = trim($_POST['description'] ?? '');
$thumbnail   = trim($_POST['thumbnail'] ?? '');
$bundlePrice = (float) ($_POST['bundle_price'] ?? 0);
$sortOrder       = (int) ($_POST['sort_order'] ?? 0);
$status          = in_array($_POST['status'] ?? '', ['active','inactive','draft'], true) ? $_POST['status'] : 'draft';
$hideFromCatalog = (int) ($_POST['hide_from_catalog'] ?? 0);

if (strlen($name) < 3)  { flash('error', 'Nama bundle minimal 3 karakter.', 'error'); redirect('/admin/bundles/edit' . ($id ? '?id='.$id : '')); }
if ($bundlePrice < 0)   { flash('error', 'Harga tidak boleh negatif.', 'error'); redirect('/admin/bundles/edit' . ($id ? '?id='.$id : '')); }

if ($slug === '') $slug = $name;
$slug = trim(preg_replace('/[^a-z0-9\-]+/', '-', strtolower($slug)), '-');

$pdo = getDB();
$sc = $pdo->prepare("SELECT id FROM product_bundles WHERE slug = ? AND id != ?");
$sc->execute([$slug, $id]);
if ($sc->fetchColumn()) { flash('error', 'Slug sudah dipakai.', 'error'); redirect('/admin/bundles/edit' . ($id ? '?id='.$id : '')); }

if ($id) {
    // Total harga item saat ini — dipakai untuk validasi & original_price.
    $sum = $pdo->prepare("SELECT COALESCE(SUM(p.price),0) FROM product_bundle_items pbi JOIN products p ON p.id=pbi.product_id WHERE pbi.bundle_id=?");
    $sum->execute([$id]);
    $itemTotal = (float) $sum->fetchColumn();

    // Harga bundle tidak boleh melebihi total harga item (diskon negatif).
    // Dilewati bila bundle belum punya item (masih dibangun bertahap).
    if ($itemTotal > 0 && $bundlePrice > $itemTotal) {
        flash('error', 'Harga bundle (' . formatRupiah($bundlePrice) . ') melebihi total harga item (' . formatRupiah($itemTotal) . '). Bundle harus lebih murah dari beli satuan.', 'error');
        redirect('/admin/bundles/edit?id=' . $id);
    }

    $pdo->prepare("UPDATE product_bundles SET name=?, slug=?, tagline=?, description=?, thumbnail=?, bundle_price=?, original_price=?, status=?, sort_order=?, hide_from_catalog=?, updated_at=NOW() WHERE id=?")
        ->execute([$name, $slug, $tagline ?: null, $description ?: null, $thumbnail ?: null, $bundlePrice, $itemTotal, $status, $sortOrder, $hideFromCatalog, $id]);
    logActivity('admin', 'bundle.update', 'bundle', $id, 'Update bundle: ' . $name);
    flash('success', 'Bundle diperbarui.');
    redirect('/admin/bundles/edit?id=' . $id);
} else {
    $pdo->prepare("INSERT INTO product_bundles (name, slug, tagline, description, thumbnail, bundle_price, original_price, status, sort_order, hide_from_catalog) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)")
        ->execute([$name, $slug, $tagline ?: null, $description ?: null, $thumbnail ?: null, $bundlePrice, $status, $sortOrder, $hideFromCatalog]);
    $newId = (int) $pdo->lastInsertId();
    logActivity('admin', 'bundle.create', 'bundle', $newId, 'Buat bundle: ' . $name);
    flash('success', 'Bundle dibuat. Tambahkan produk ke bundle.');
    redirect('/admin/bundles/edit?id=' . $newId);
}
