<?php
// Naikkan / turunkan urutan tampil produk di homepage (menukar posisi dengan
// tetangganya dalam urutan kanonik sort_order ASC). Karena banyak produk lama
// masih sort_order=0 (seri, dipecah created_at), swap nilai saja tidak cukup —
// jadi seluruh daftar ditulis ulang rapat 1..n dalam satu transaksi (tie-proof).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/products');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/products');
}

$productId = (int) ($_POST['product_id'] ?? 0);
$dir       = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
$page      = max(1, (int) ($_POST['page'] ?? 1));
$backUrl   = '/admin/products' . ($page > 1 ? '?page=' . $page : '');

if ($productId <= 0) {
    redirect($backUrl);
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // Urutan kanonik = persis urutan tampil homepage & daftar admin.
    $ids = $pdo->query(
        "SELECT id FROM products ORDER BY sort_order ASC, created_at DESC, id ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $pos = array_search($productId, array_map('intval', $ids), true);
    if ($pos === false) {
        $pdo->rollBack();
        flash('error', 'Produk tidak ditemukan.', 'error');
        redirect($backUrl);
    }

    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($ids)) {
        // Sudah di ujung — tidak ada yang perlu diubah.
        $pdo->commit();
        redirect($backUrl);
    }

    // Tukar posisi lalu tulis ulang sort_order rapat (1..n).
    $tmp = $ids[$pos];
    $ids[$pos]  = $ids[$swap];
    $ids[$swap] = $tmp;

    $upd = $pdo->prepare("UPDATE products SET sort_order = ? WHERE id = ?");
    foreach ($ids as $idx => $id) {
        $upd->execute([$idx + 1, (int) $id]);
    }

    $pdo->commit();
    logActivity('admin', 'product.reorder', 'product', $productId, 'Ubah urutan produk (' . $dir . ')');
    flash('success', 'Urutan produk diperbarui.', 'success');
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Reorder product error: ' . $e->getMessage());
    flash('error', 'Gagal memperbarui urutan produk.', 'error');
}

redirect($backUrl);
