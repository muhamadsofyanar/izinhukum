<?php
/**
 * POST /actions/admin/save-manual-payment-settings
 * Simpan konfigurasi "Manual Payment Details": gambar QR + teks instruksi global.
 * Reuse tabel settings (key-value). Rekening bank tetap dikelola di halaman
 * Rekening Bank (tabel bank_accounts) — tidak disentuh di sini.
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/bank-accounts');
}

// ── 1. Teks instruksi ──
$instruction = trim($_POST['manual_payment_instruction'] ?? '');
if (mb_strlen($instruction) > 1000) $instruction = mb_substr($instruction, 0, 1000);
setSetting('manual_payment_instruction', $instruction, 'payment');

// ── 2. Hapus QR (bila diminta) ──
$uploadDir = __DIR__ . '/../../assets/uploads/manual-payment/';
$deleteOldFile = function (?string $rel) use ($uploadDir) {
    if (!$rel) return;
    // Hanya izinkan hapus file di dalam folder manual-payment (cegah path traversal).
    $base = basename($rel);
    $path = $uploadDir . $base;
    if ($base !== '' && is_file($path)) @unlink($path);
};

$currentQr = getSetting('manual_qr_image'); // path relatif dari APP_URL, mis. assets/uploads/manual-payment/xxx.png

if (!empty($_POST['remove_qr'])) {
    $deleteOldFile($currentQr);
    setSetting('manual_qr_image', null, 'payment');
    flash('success', 'Pengaturan pembayaran manual diperbarui. QR dihapus.');
    redirect('/admin/bank-accounts');
}

// ── 3. Upload QR baru (opsional) ──
if (!empty($_FILES['manual_qr']) && ($_FILES['manual_qr']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $file = $_FILES['manual_qr'];

    // Ukuran maksimal 2MB.
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        flash('error', 'Ukuran QR maksimal 2MB.', 'error');
        redirect('/admin/bank-accounts');
    }

    // Validasi MIME asli file (bukan sekadar ekstensi) — dua lapis: finfo + getimagesize.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $info = @getimagesize($file['tmp_name']);

    if (!isset($allowed[$mime]) || $info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        flash('error', 'File QR harus gambar JPG atau PNG yang valid.', 'error');
        redirect('/admin/bank-accounts');
    }

    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

    // Nama file acak (BUKAN nama asli) → cegah tebak-path & tabrakan.
    $ext      = $allowed[$mime];
    $filename = 'qr_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        flash('error', 'Gagal menyimpan file QR di server.', 'error');
        redirect('/admin/bank-accounts');
    }

    // Hapus QR lama dari disk setelah yang baru tersimpan.
    $deleteOldFile($currentQr);
    setSetting('manual_qr_image', 'assets/uploads/manual-payment/' . $filename, 'payment');
}

logActivity('admin', 'settings.manual_payment', null, null, 'Update pengaturan pembayaran manual (QR/instruksi).');
flash('success', 'Pengaturan pembayaran manual berhasil disimpan.');
redirect('/admin/bank-accounts');
