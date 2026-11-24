<?php
// Hapus satu pendaftar waitlist. Admin/staff (modul products).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/waitlist');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/waitlist');
}

$entryId = (int) ($_POST['entry_id'] ?? 0);
if ($entryId <= 0) {
    flash('error', 'Pendaftar tidak valid.', 'error');
    redirect('/admin/waitlist');
}

try {
    getDB()->prepare('DELETE FROM product_waitlist WHERE id = ?')->execute([$entryId]);
    flash('success', 'Pendaftar waitlist dihapus.');
} catch (Exception $e) {
    error_log('delete-waitlist-entry error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus pendaftar.', 'error');
}

redirect('/admin/waitlist');
