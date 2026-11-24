<?php
// Simpan (tambah/edit) penawaran upsell — 1 penawaran per produk pemicu.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/upsells');
}

$id        = (int) ($_POST['id'] ?? 0);
$mainId    = (int) ($_POST['main_product_id'] ?? 0);
$upsellId  = (int) ($_POST['upsell_product_id'] ?? 0);
$price     = (float) ($_POST['upsell_price'] ?? 0);
$headline  = substr(trim((string) ($_POST['headline'] ?? '')), 0, 200);
$desc      = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
$status    = (($_POST['status'] ?? '') === 'active') ? 'active' : 'inactive';

if ($mainId <= 0 || $upsellId <= 0 || $price <= 0) {
    flash('error', 'Produk pemicu, produk penawaran, dan harga spesial wajib diisi.', 'error');
    redirect('/admin/upsells');
}
if ($mainId === $upsellId) {
    flash('error', 'Produk penawaran tidak boleh sama dengan produk pemicu.', 'error');
    redirect('/admin/upsells');
}

try {
    $pdo = getDB();

    // Kedua produk harus ada & aktif.
    $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN (?, ?) AND status = 'active'");
    $st->execute([$mainId, $upsellId]);
    if ((int) $st->fetchColumn() !== 2) {
        flash('error', 'Produk tidak ditemukan atau tidak aktif.', 'error');
        redirect('/admin/upsells');
    }

    // 1 penawaran per produk pemicu — cegah duplikat pada tambah/pindah pemicu.
    $st = $pdo->prepare("SELECT id FROM product_upsells WHERE main_product_id = ? LIMIT 1");
    $st->execute([$mainId]);
    $existingId = (int) ($st->fetchColumn() ?: 0);
    if ($existingId && $existingId !== $id) {
        flash('error', 'Produk pemicu itu sudah punya penawaran upsell. Edit yang sudah ada.', 'error');
        redirect('/admin/upsells');
    }

    if ($id > 0) {
        $pdo->prepare(
            "UPDATE product_upsells
             SET main_product_id = ?, upsell_product_id = ?, upsell_price = ?, headline = ?, description = ?, status = ?
             WHERE id = ?"
        )->execute([$mainId, $upsellId, $price, $headline ?: null, $desc ?: null, $status, $id]);
        flash('success', 'Penawaran upsell diperbarui.');
    } else {
        $pdo->prepare(
            "INSERT INTO product_upsells (main_product_id, upsell_product_id, upsell_price, headline, description, status)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$mainId, $upsellId, $price, $headline ?: null, $desc ?: null, $status]);
        flash('success', 'Penawaran upsell dibuat.');
    }
} catch (Exception $e) {
    error_log('save-upsell error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan. Pastikan update database v1.1.54 sudah berjalan.', 'error');
}

redirect('/admin/upsells');
