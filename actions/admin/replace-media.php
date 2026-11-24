<?php
// Ganti Gambar (Replace) — timpa berkas media DI PATH YANG SAMA sehingga URL/embed
// yang sudah dipasang di mana pun TIDAK berubah. Filename dipertahankan; hanya isi
// berkas + metadata (ukuran/dimensi) yang diperbarui. Format dipertahankan (bila
// unggahan beda format → re-encode ke format asli agar konsisten dgn ekstensi/URL).
// AJAX (JSON). Cermin validasi upload-media.php.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}
// Upload melebihi post_max_size hosting → PHP mengosongkan $_POST total (termasuk
// csrf_token) SEBELUM skrip ini jalan. Deteksi SEBELUM cek CSRF supaya pesannya jujur.
// Pola sama dgn save-canvas.php / upload-media.php.
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

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    echo json_encode(['ok' => false, 'message' => 'ID media tidak valid.']);
    exit;
}

$MEDIA_MAX  = 8 * 1024 * 1024;
$MEDIA_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

// Ambil berkas lama dari DB.
try {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT filename, mime FROM media_files WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
} catch (Exception $e) {
    error_log('replace-media lookup: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Gagal membaca data media.']);
    exit;
}
if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Media tidak ditemukan.']);
    exit;
}
$fname   = (string) $row['filename'];
$origExt = strtolower(pathinfo($fname, PATHINFO_EXTENSION));

// Validasi unggahan baru (cermin upload-media).
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
$newExt = $MEDIA_MIME[$mime];

$dirAbs  = rtrim(UPLOAD_PATH, '/\\') . '/media';
$destAbs = $dirAbs . '/' . $fname;
if (!is_file($destAbs)) {
    // Berkas asli hilang (mis. terhapus manual) — tetap tulis di path yg sama agar URL konsisten.
    error_log('replace-media: berkas asli tak ada, ditulis ulang: ' . $destAbs);
}

// Tulis ke berkas sementara di folder media dulu (aman: berkas asli tak disentuh
// sampai penulisan baru sukses), lalu rename menimpa (atomik) → URL tak pernah kosong.
$stageAbs = $dirAbs . '/.replace_' . bin2hex(random_bytes(6)) . '.' . $origExt;

$reencodeOk = true;
if ($newExt === $origExt) {
    // Format sama → salin byte apa adanya (pertahankan persis, termasuk animasi GIF).
    if (!@copy($tmp, $stageAbs)) { $reencodeOk = false; }
} else {
    // Format beda → re-encode ke format ASLI agar isi cocok dgn ekstensi/URL.
    $reencodeOk = _reencodeImageTo($tmp, $stageAbs, $origExt);
}
if (!$reencodeOk || !is_file($stageAbs)) {
    @unlink($stageAbs);
    echo json_encode(['ok' => false, 'message' => 'Gagal memproses gambar baru.']);
    exit;
}

// Kompres (jpg/png/webp; gif dilewati) — sama seperti upload.
compressUploadedImage($stageAbs);

// Timpa berkas asli (rename atomik).
if (!@rename($stageAbs, $destAbs)) {
    @unlink($stageAbs);
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan gambar pengganti.']);
    exit;
}

// Metadata baru (mime TETAP = format asli; filename TETAP).
$w = $h = null;
$info = @getimagesize($destAbs);
if ($info) { $w = (int) $info[0]; $h = (int) $info[1]; }
$size = (int) @filesize($destAbs);

try {
    $pdo->prepare('UPDATE media_files SET size = ?, width = ?, height = ? WHERE id = ?')
        ->execute([$size, $w, $h, $id]);
} catch (Exception $e) {
    error_log('replace-media update: ' . $e->getMessage());
    // Berkas sudah diganti; metadata gagal update bukan fatal utk tampilan.
}

if (function_exists('logActivity')) {
    logActivity('admin', 'media.replaced', 'media_files', $id, 'Gambar diganti (URL tetap): ' . $fname);
}

$url = rtrim(UPLOAD_URL, '/') . '/media/' . $fname;
echo json_encode([
    'ok' => true,
    'media' => [
        'id'        => $id,
        'filename'  => $fname,
        'url'       => $url,                 // URL/embed TIDAK berubah
        'width'     => $w,
        'height'    => $h,
        'size'      => $size,
        'cachebust' => (int) @filemtime($destAbs), // utk refresh preview admin saja
    ],
]);
exit;

/**
 * Re-encode gambar sumber ke format tujuan ($targetExt: jpg|png|webp|gif) via GD.
 * Dipakai saat unggahan beda format dari berkas asli — supaya isi cocok ekstensi/URL.
 */
function _reencodeImageTo(string $srcPath, string $destPath, string $targetExt): bool
{
    if (!function_exists('imagecreatefromstring')) return false;
    $data = @file_get_contents($srcPath);
    if ($data === false) return false;
    $img = @imagecreatefromstring($data);
    if (!$img) return false;
    // Jaga transparansi utk png/webp/gif.
    if (in_array($targetExt, ['png', 'webp', 'gif'], true)) {
        @imagealphablending($img, false);
        @imagesavealpha($img, true);
    }
    $ok = false;
    switch ($targetExt) {
        case 'jpg':  $ok = function_exists('imagejpeg') && @imagejpeg($img, $destPath, 85); break;
        case 'png':  $ok = function_exists('imagepng')  && @imagepng($img, $destPath, 6);   break;
        case 'webp': $ok = function_exists('imagewebp') && @imagewebp($img, $destPath, 85); break;
        case 'gif':  $ok = function_exists('imagegif')  && @imagegif($img, $destPath);      break;
    }
    imagedestroy($img);
    return (bool) $ok;
}
