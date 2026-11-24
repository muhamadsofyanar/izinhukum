<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('downloads')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/downloads');
}

$id  = (int) ($_POST['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, filename_stor, title FROM product_downloads WHERE id = ?");
$stmt->execute([$id]);
$download = $stmt->fetch();
if (!$download) { flash('error', 'File tidak ditemukan.', 'error'); redirect('/admin/downloads'); }

$filePath = DOWNLOAD_STORAGE_PATH . $download['filename_stor'];
if (is_file($filePath)) { @unlink($filePath); }

$pdo->prepare("DELETE FROM product_downloads WHERE id = ?")->execute([$id]);

logActivity('admin', 'download.delete', 'download', $id, 'Hapus file download: ' . $download['title']);
flash('success', 'File berhasil dihapus.');
redirect('/admin/downloads');
