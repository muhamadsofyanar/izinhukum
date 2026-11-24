<?php
// Upload satu gambar ke Galeri Media (AJAX, JSON). Dipanggil dropzone / picker.
// Simpan ke assets/uploads/media/ (publik + .htaccess uploads hardened), kompres,
// catat metadata ke media_files. Kembalikan URL & embed siap-salin.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}
// Upload melebihi post_max_size hosting → PHP mengosongkan $_POST total (termasuk
// csrf_token) SEBELUM skrip ini jalan. Deteksi SEBELUM cek CSRF supaya pesannya jujur
// (bukan "sesi kedaluwarsa" yang menyesatkan). Pola sama dgn save-canvas.php.
if (postBodyTooLarge()) {
    $limit = phpUploadLimitBytes();
    http_response_code(413);
    echo json_encode(['ok' => false, 'message' => 'File terlalu besar — melebihi batas upload server'
        . ($limit > 0 ? ' (maks ' . formatFileSize($limit) . ')' : '')
        . '. Perkecil file, atau naikkan post_max_size & upload_max_filesize di pengaturan PHP hosting.']);
    exit;
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Sesi kedaluwarsa. Muat ulang halaman.']);
    exit;
}

$MEDIA_MAX = 8 * 1024 * 1024; // batas sumber 8MB (dikompres setelahnya)
$MEDIA_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

$file = $_FILES['file'] ?? null;
if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['ok' => false, 'message' => 'Tidak ada berkas.']);
    exit;
}
if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => 'Gagal mengunggah berkas.']);
    exit;
}
$tmp = (string) ($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    echo json_encode(['ok' => false, 'message' => 'Berkas tidak valid.']);
    exit;
}
if ((int) ($file['size'] ?? 0) > $MEDIA_MAX) {
    echo json_encode(['ok' => false, 'message' => 'Ukuran maksimal 8MB per gambar.']);
    exit;
}

// MIME sebenarnya (jangan percaya ekstensi/klien).
$mime = '';
if (function_exists('finfo_open')) {
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $fi ? (string) finfo_file($fi, $tmp) : '';
    if ($fi) finfo_close($fi);
}
if (!isset($MEDIA_MIME[$mime])) {
    echo json_encode(['ok' => false, 'message' => 'Format harus JPG, PNG, WEBP, atau GIF.']);
    exit;
}
$ext = $MEDIA_MIME[$mime];

$dirAbs = rtrim(UPLOAD_PATH, '/\\') . '/media';
if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyiapkan folder media.']);
    exit;
}

$fname = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destAbs = $dirAbs . '/' . $fname;
if (!@move_uploaded_file($tmp, $destAbs)) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan gambar.']);
    exit;
}

// Kompres (jpg/png/webp; gif dilewati) — format tetap, otomatis sampai ≤ 400 KB.
compressImageToTarget($destAbs, 400 * 1024);

// Dimensi + ukuran akhir.
$w = $h = null;
$info = @getimagesize($destAbs);
if ($info) { $w = (int) $info[0]; $h = (int) $info[1]; }
$size = (int) @filesize($destAbs);

$original = substr((string) ($file['name'] ?? ''), 0, 255);

try {
    $pdo = getDB();
    // Grup opsional: upload langsung ke sebuah grup media. Validasi grup ada;
    // tak valid / 0 → Tanpa grup (NULL). Fail-soft: kolom group_id mungkin belum ada
    // (pra-migrasi) → fallback INSERT tanpa group_id.
    $groupId = (int) ($_POST['group_id'] ?? 0);
    if ($groupId > 0) {
        $gchk = $pdo->prepare('SELECT 1 FROM media_groups WHERE id = ? LIMIT 1');
        $gchk->execute([$groupId]);
        if (!$gchk->fetchColumn()) $groupId = 0;
    }
    try {
        $pdo->prepare(
            'INSERT INTO media_files (filename, original_name, mime, size, width, height, uploaded_by, group_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$fname, ($original ?: null), $mime, $size, $w, $h, (int) ($_SESSION['user_id'] ?? 0) ?: null, $groupId > 0 ? $groupId : null]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1054) { // Unknown column group_id (pra-migrasi)
            $pdo->prepare(
                'INSERT INTO media_files (filename, original_name, mime, size, width, height, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$fname, ($original ?: null), $mime, $size, $w, $h, (int) ($_SESSION['user_id'] ?? 0) ?: null]);
        } else { throw $e; }
    }
    $id = (int) $pdo->lastInsertId();
} catch (Exception $e) {
    @unlink($destAbs); // rollback file bila DB gagal
    error_log('upload-media error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Gagal mencatat gambar. Pastikan migrasi dijalankan.']);
    exit;
}

if (function_exists('logActivity')) {
    logActivity('admin', 'media.uploaded', 'media_files', $id, 'Gambar diunggah ke Galeri Media.');
}

$url = rtrim(UPLOAD_URL, '/') . '/media/' . $fname;
echo json_encode([
    'ok' => true,
    'media' => [
        'id'       => $id,
        'filename' => $fname,
        'url'      => $url,
        'embed'    => '<img src="' . $url . '" alt="" loading="lazy">',
        'width'    => $w,
        'height'   => $h,
        'size'     => $size,
        'alt'      => '',
    ],
]);
