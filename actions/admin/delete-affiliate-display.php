<?php
// POST /actions/admin/delete-affiliate-display — hapus 1 Display Produk Affiliate.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/affiliate-displays');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/affiliate-displays');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    try {
        getDB()->prepare("DELETE FROM affiliate_displays WHERE id = ?")->execute([$id]);
        flash('success', 'Display affiliate dihapus.');
        logActivity('admin', 'affiliate_display.delete', 'affiliate_display', $id, 'Hapus display');
    } catch (Exception $e) {
        error_log('delete-affiliate-display error: ' . $e->getMessage());
        flash('error', 'Gagal menghapus display.', 'error');
    }
}
redirect('/admin/affiliate-displays');
