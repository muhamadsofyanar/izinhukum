<?php
// Pixel open broadcast — catat open lalu balas GIF 1×1 transparan.
// Publik (tanpa login). Selalu balas gambar apa pun hasilnya (tak bocorkan validitas).
require_once __DIR__ . '/../bootstrap.php';

$tok = (string) ($_GET['tok'] ?? '');
$rid = broadcastVerifyToken($tok, null);
if ($rid > 0) {
    try {
        getDB()->prepare(
            "UPDATE broadcast_recipients SET opened_at = COALESCE(opened_at, NOW()), open_count = open_count + 1 WHERE id = ?"
        )->execute([$rid]);
    } catch (Throwable $e) {
        error_log('bt-open: ' . $e->getMessage());
    }
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
header('Content-Length: ' . strlen($gif));
echo $gif;
exit;
