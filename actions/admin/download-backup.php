<?php
// GET /actions/admin/download-backup?file=... — stream file backup ke admin.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$name = (string) ($_GET['file'] ?? '');

// Nama file WAJIB persis pola backup kita — menutup path traversal sepenuhnya.
if (!preg_match('/^averion-db-\d{8}-\d{6}-[a-f0-9]{6}\.sql\.gz$/', $name)) {
    flash('error', 'Nama file tidak valid.', 'error');
    redirect('/admin/backups');
}

$dir  = dbBackupDir();
$path = $dir !== null ? $dir . DIRECTORY_SEPARATOR . $name : '';

if ($path === '' || !is_file($path)) {
    flash('error', 'File backup tidak ditemukan.', 'error');
    redirect('/admin/backups');
}

logActivity('admin', 'backup.download', null, null, 'Download backup DB: ' . $name);

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
exit;
