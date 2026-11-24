<?php
// POST /actions/admin/set-funnel-default
// Pin / lepas "produk default" Funnel Analytics. Produk/bundle default muncul
// PALING ATAS di dropdown & otomatis terbuka saat halaman Funnel dibuka — meng-
// ganti perilaku lama (produk terbaru). Disimpan di `settings` (tanpa migrasi).
// Toggle: bila target yang dikirim SUDAH jadi default → kosongkan (lepas pin).
// Hanya admin.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$period = (string) ($_POST['period'] ?? '7');
$target = (string) ($_POST['target'] ?? '');

// URL balik yang aman: selalu path internal halaman Funnel (anti open-redirect).
$valid = preg_match('/^[pb]\d+$/', $target) ? $target : '';
$back  = rtrim(APP_URL, '/') . '/admin/funnel-analytics';
if ($valid !== '') {
    $back .= '?target=' . rawurlencode($valid) . '&period=' . rawurlencode($period);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    header('Location: ' . $back);
    exit;
}

// Toggle terhadap nilai saat ini.
$current = (string) getSetting('funnel_default_target', '');
$newVal  = ($valid !== '' && $current === $valid) ? '' : $valid;

try {
    setSetting('funnel_default_target', $newVal, 'funnel');
} catch (Throwable $e) {
    error_log('set-funnel-default error: ' . $e->getMessage());
}

header('Location: ' . $back);
exit;
