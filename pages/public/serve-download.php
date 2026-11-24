<?php
// Serve file download terproteksi via token sekali-pakai.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$token = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['token'] ?? ''));
if ($token === '') { flash('error', 'Token tidak valid.', 'error'); redirect('/downloads'); }

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT dt.id AS token_id, dt.user_id, dt.expires_at, dt.used_at,
                pd.id AS download_id, pd.product_id, pd.filename_stor, pd.filename_orig, pd.mime_type, pd.file_size
         FROM download_tokens dt
         JOIN product_downloads pd ON pd.id = dt.download_id
         WHERE dt.token = ? LIMIT 1"
    );
    $stmt->execute([$token]);
    $rec = $stmt->fetch();

    if (!$rec)                                                   { flash('error', 'Link download tidak valid.', 'error'); redirect('/downloads'); }
    if ($rec['used_at'] !== null)                               { flash('error', 'Link download sudah digunakan. Klik Download lagi untuk link baru.', 'error'); redirect('/downloads'); }
    if (strtotime($rec['expires_at']) < time())                 { flash('error', 'Link download kadaluarsa. Klik Download lagi.', 'error'); redirect('/downloads'); }
    if ((int) $rec['user_id'] !== (int) $_SESSION['user_id'])   { flash('error', 'Token tidak valid untuk akun ini.', 'error'); redirect('/downloads'); }
    if (!isAdminOrStaff() && !canAccessDownload((int) $_SESSION['user_id'], (int) $rec['download_id'])) { flash('error', 'Akses produk kamu sudah berakhir.', 'error'); redirect('/downloads'); }

    $filePath = DOWNLOAD_STORAGE_PATH . $rec['filename_stor'];
    if (!is_file($filePath) || !is_readable($filePath)) {
        error_log('serve-download: file missing ' . $filePath);
        flash('error', 'File tidak ditemukan. Hubungi admin.', 'error'); redirect('/downloads');
    }

    // Tandai token used + log + counter (sebelum stream)
    $pdo->prepare("UPDATE download_tokens SET used_at = NOW() WHERE id = ?")->execute([(int) $rec['token_id']]);
    $pdo->prepare("INSERT INTO download_logs (download_id, user_id, token_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")
        ->execute([(int) $rec['download_id'], (int) $_SESSION['user_id'], (int) $rec['token_id'], $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    $pdo->prepare("UPDATE product_downloads SET download_count = download_count + 1 WHERE id = ?")->execute([(int) $rec['download_id']]);

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: ' . $rec['mime_type']);
    header('Content-Disposition: attachment; filename="' . addslashes($rec['filename_orig']) . '"');
    header('Content-Length: ' . (int) $rec['file_size']);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    $h = fopen($filePath, 'rb');
    if ($h) { while (!feof($h)) { echo fread($h, 8192); flush(); } fclose($h); }
    exit;
} catch (Exception $e) {
    error_log('serve-download error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat mengunduh file.', 'error');
    redirect('/downloads');
}
