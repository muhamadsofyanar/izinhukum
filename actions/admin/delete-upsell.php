<?php
// Hapus penawaran upsell.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/upsells');
}

$id = (int) ($_POST['id'] ?? 0);
try {
    getDB()->prepare("DELETE FROM product_upsells WHERE id = ?")->execute([$id]);
    flash('success', 'Penawaran upsell dihapus.');
} catch (Exception $e) {
    error_log('delete-upsell error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus.', 'error');
}
redirect('/admin/upsells');
