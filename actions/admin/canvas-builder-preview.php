<?php
// ════════════════════════════════════════════════════════════════
// Averion — Action: Pratinjau Landing Builder
// Render envelope builder (dari editor, BELUM disimpan) → HTML untuk iframe.
// Admin-only + CSRF. Tidak menyimpan apa pun, tidak di-cache.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !validateCSRF($_POST['csrf_token'] ?? '')
    || !function_exists('avLbRenderPage')) {
    http_response_code(400);
    echo '<!DOCTYPE html><meta charset="utf-8"><body style="font-family:system-ui;padding:24px;color:#64748b">Pratinjau tidak tersedia.</body>';
    exit;
}

$raw = (string) ($_POST['builder_json'] ?? '');
if (($_POST['builder_json_encoding'] ?? '') === 'base64') {
    $dec = base64_decode($raw, true);
    if ($dec !== false) $raw = $dec;
}

// Sanitasi sama seperti saat simpan → pratinjau jujur mencerminkan hasil akhir.
$content = avLbSanitizeEnvelope($raw);

$page = [
    'id'        => 0,
    'title'     => trim((string) ($_POST['title'] ?? 'Pratinjau')),
    'meta_desc' => '',
    'mode'      => 'builder',
    'content'   => $content,
];

echo avLbRenderPage($page);
exit;
