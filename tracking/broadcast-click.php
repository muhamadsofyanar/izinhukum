<?php
// Redirect klik broadcast — catat klik lalu 302 ke URL asli.
// Publik. Anti open-redirect: hanya redirect ke URL yang KITA tandatangani.
require_once __DIR__ . '/../bootstrap.php';

$tok = (string) ($_GET['tok'] ?? '');
$url = (string) ($_GET['u'] ?? '');

$rid = ($url !== '') ? broadcastVerifyToken($tok, $url) : 0;
if ($rid > 0) {
    try {
        getDB()->prepare(
            "UPDATE broadcast_recipients SET clicked_at = COALESCE(clicked_at, NOW()), click_count = click_count + 1 WHERE id = ?"
        )->execute([$rid]);
    } catch (Throwable $e) {
        error_log('bt-click: ' . $e->getMessage());
    }
    $dest = $url;
} else {
    // Token/URL tak sah → jangan jadi open-redirector; kembali ke beranda.
    $dest = rtrim(APP_URL, '/') . '/';
}

if (!preg_match('~^https?://~i', $dest)) $dest = rtrim(APP_URL, '/') . '/';
header('Location: ' . $dest, true, 302);
exit;
