<?php
// POST /actions/admin/delete-backup — hapus satu file backup.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/backups');
}

$name = (string) ($_POST['file'] ?? '');
if (!preg_match('/^averion-db-\d{8}-\d{6}-[a-f0-9]{6}\.sql\.gz$/', $name)) {
    flash('error', 'Nama file tidak valid.', 'error');
    redirect('/admin/backups');
}

$dir  = dbBackupDir();
$path = $dir !== null ? $dir . DIRECTORY_SEPARATOR . $name : '';

if ($path !== '' && is_file($path) && @unlink($path)) {
    logActivity('admin', 'backup.delete', null, null, 'Hapus backup DB: ' . $name);
    flash('success', 'Backup ' . $name . ' dihapus.');
} else {
    flash('error', 'File backup tidak ditemukan / gagal dihapus.', 'error');
}
redirect('/admin/backups');
