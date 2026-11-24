<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/transactions');
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$userId  = (int) $_SESSION['user_id'];

$pdo = getDB();

// Order harus milik user yang login & masih pending.
$oStmt = $pdo->prepare("SELECT id, status, order_number FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
$oStmt->execute([$orderId, $userId]);
$order = $oStmt->fetch();

if (!$order) {
    flash('error', 'Order tidak ditemukan.', 'error');
    redirect('/transactions');
}
$backUrl = '/transactions/' . $order['order_number'];

if ($order['status'] !== 'pending') {
    flash('error', 'Order ini sudah diproses, tidak bisa upload bukti.', 'error');
    redirect($backUrl);
}

// Pastikan memang order manual_transfer.
$pStmt = $pdo->prepare("SELECT id FROM payments WHERE order_id = ? AND method = 'manual_transfer' LIMIT 1");
$pStmt->execute([$orderId]);
if (!$pStmt->fetch()) {
    flash('error', 'Order ini bukan pembayaran transfer manual.', 'error');
    redirect($backUrl);
}

// Validasi file.
$file = $_FILES['proof_file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash('error', 'File tidak valid atau gagal diupload.', 'error');
    redirect($backUrl);
}

$maxSize = 3 * 1024 * 1024; // 3MB
if ($file['size'] > $maxSize) {
    flash('error', 'Ukuran file maksimal 3MB.', 'error');
    redirect($backUrl);
}

// Ekstensi DITURUNKAN dari MIME terdeteksi (bukan dari nama file user) → cegah .php/.phar.
$mimeToExt = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($mimeToExt[$mimeType])) {
    flash('error', 'Format file tidak didukung. Gunakan JPG, PNG, atau PDF.', 'error');
    redirect($backUrl);
}
$ext = $mimeToExt[$mimeType];

$dir = rtrim(UPLOAD_PATH, '/') . '/proofs/';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

$filename = 'proof_' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$destPath = $dir . $filename;
$destUrl  = rtrim(UPLOAD_URL, '/') . '/proofs/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    error_log('upload-proof: gagal move ke ' . $destPath);
    flash('error', 'Gagal menyimpan file. Coba lagi.', 'error');
    redirect($backUrl);
}
compressUploadedImage($destPath); // foto bukti transfer dari kamera HP bisa >2MB; PDF dilewati otomatis

// Update payment: simpan bukti, reset alasan penolakan lama (jika ada).
$pdo->prepare(
    "UPDATE payments SET proof_file = ?, proof_uploaded_at = NOW(), rejection_reason = NULL WHERE order_id = ?"
)->execute([$destUrl, $orderId]);

logActivity('user', 'payment.proof_upload', 'order', $orderId, 'Upload bukti transfer order ' . $order['order_number']);
flash('success', 'Bukti transfer berhasil diupload. Menunggu konfirmasi admin.');
redirect($backUrl);
