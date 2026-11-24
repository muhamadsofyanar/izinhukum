<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('downloads')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }

// Upload melebihi post_max_size → PHP mengosongkan $_POST total (termasuk
// csrf_token). Deteksi SEBELUM cek CSRF supaya pesannya jujur.
if (postBodyTooLarge()) {
    $limit = phpUploadLimitBytes();
    flash('error', 'File terlalu besar — melebihi batas upload server'
        . ($limit > 0 ? ' (maks ' . formatFileSize($limit) . ')' : '')
        . '. Perkecil file, atau naikkan post_max_size & upload_max_filesize di pengaturan PHP hosting.', 'error');
    redirect('/admin/downloads');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/downloads');
}

$id          = (int) ($_POST['id'] ?? 0);
$productId   = (int) ($_POST['product_id'] ?? 0);
$title       = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$version     = trim($_POST['version'] ?? '');
$sortOrder   = (int) ($_POST['sort_order'] ?? 0);
$status      = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

if (strlen($title) < 3) { flash('error', 'Judul minimal 3 karakter.', 'error'); redirect('/admin/downloads'); }

$pdo = getDB();

if ($id) {
    // Update metadata saja
    $pdo->prepare("UPDATE product_downloads SET title=?, description=?, version=?, sort_order=?, status=?, updated_at=NOW() WHERE id=?")
        ->execute([$title, $description, $version, $sortOrder, $status, $id]);
    logActivity('admin', 'download.update', 'download', $id, 'Update download: ' . $title);
    flash('success', 'File berhasil diperbarui.');
    redirect('/admin/downloads');
}

// Create — wajib file
$chk = $pdo->prepare("SELECT id FROM products WHERE id = ?");
$chk->execute([$productId]);
if (!$chk->fetchColumn()) { flash('error', 'Produk tidak valid.', 'error'); redirect('/admin/downloads'); }

if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash('error', 'File wajib di-upload.', 'error'); redirect('/admin/downloads');
}
$file = $_FILES['file'];
if ($file['size'] > DOWNLOAD_MAX_SIZE) { flash('error', 'File terlalu besar (maks 100MB).', 'error'); redirect('/admin/downloads'); }

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = [
    'application/pdf','application/zip','application/x-zip-compressed','application/x-zip',
    'application/x-rar-compressed','application/vnd.rar',
    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'video/mp4','video/x-matroska','image/jpeg','image/png','image/gif','image/webp',
    'text/plain','application/octet-stream',
];
if (!in_array($mimeType, $allowedMimes, true)) { flash('error', 'Tipe file tidak diizinkan: ' . $mimeType, 'error'); redirect('/admin/downloads'); }

$ext = strtolower(preg_replace('/[^a-z0-9]/', '', pathinfo($file['name'], PATHINFO_EXTENSION)));
$filenameStorage = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');

if (!is_dir(DOWNLOAD_STORAGE_PATH)) { @mkdir(DOWNLOAD_STORAGE_PATH, 0750, true); }
if (!move_uploaded_file($file['tmp_name'], DOWNLOAD_STORAGE_PATH . $filenameStorage)) {
    flash('error', 'Gagal menyimpan file. Periksa permission folder storage/downloads.', 'error'); redirect('/admin/downloads');
}

$pdo->prepare(
    "INSERT INTO product_downloads (product_id, title, description, filename_orig, filename_stor, mime_type, file_size, version, sort_order, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
)->execute([$productId, $title, $description, $file['name'], $filenameStorage, $mimeType, (int) $file['size'], $version, $sortOrder, $status]);

logActivity('admin', 'download.create', 'download', (int) $pdo->lastInsertId(), 'Upload file: ' . $title);
flash('success', 'File berhasil di-upload.');
redirect('/admin/downloads');
