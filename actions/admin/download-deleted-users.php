<?php
/**
 * Unduh cadangan CSV hasil hapus massal pengguna (storage/deleted-users/).
 * Admin/staff ber-izin users saja. Nama file diketatkan (anti path traversal).
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('users')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

$file = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/^deleted-users-\d{8}-\d{6}\.csv$/', $file)) {
    http_response_code(404);
    exit('File tidak valid.');
}

$path = __DIR__ . '/../../storage/deleted-users/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
