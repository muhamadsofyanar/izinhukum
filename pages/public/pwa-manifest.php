<?php
// ─── Web App Manifest (PWA) ───────────────────────────────────────────────
// Disajikan di /manifest.webmanifest (route di index.php). DINAMIS dari setting
// branding supaya tiap pembeli (white-label) punya nama/warna/ikon sendiri.
// bootstrap.php sudah dimuat oleh index.php sebelum route ini di-require.

if (getSetting('pwa_enabled', '1') !== '1') {
    http_response_code(404);
    echo 'Not found';
    return;
}

header('Content-Type: application/manifest+json; charset=utf-8');
// Manifest boleh di-cache singkat; perubahan branding jarang & ikon pakai cache-bust ?v=.
header('Cache-Control: public, max-age=3600');

$appName = trim((string) getSetting('app_name', 'STIFIn Mulia')) ?: 'STIFIn Mulia';
$brand   = (string) getSetting('brand_color', '#6366f1');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand)) {
    $brand = '#6366f1';
}

// short_name maksimal ~12 karakter (anjuran platform) agar label ikon tak terpotong.
$shortName = mb_substr($appName, 0, 12);

// Cache-bust ikon saat warna brand / versi aplikasi berubah.
$iconVer = substr(md5($brand . '|' . trim((string) getSetting('pwa_icon', '')) . '|' . (defined('APP_VERSION') ? APP_VERSION : '')), 0, 8);
// Path TANPA ekstensi .png (lihat index.php) agar ikon lolos di nginx/LiteSpeed,
// bukan cuma Apache. Content-Type image/png di-set oleh pwa-icon.php.
$icon = static function (int $size) use ($iconVer): string {
    return APP_URL . '/pwa-icon/' . $size . '?v=' . $iconVer;
};

$manifest = [
    'id'               => APP_URL . '/',
    'name'             => $appName,
    'short_name'       => $shortName,
    'description'      => trim((string) getSetting('homepage_subheadline', '')) ?: $appName,
    'start_url'        => APP_URL . '/dashboard',
    'scope'            => APP_URL . '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#ffffff',
    'theme_color'      => $brand,
    'lang'             => 'id',
    'dir'              => 'ltr',
    'icons'            => [
        ['src' => $icon(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $icon(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
