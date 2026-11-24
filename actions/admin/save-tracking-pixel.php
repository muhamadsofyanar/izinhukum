<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/tracking-pixels');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/tracking-pixels');
}

$allowedPlatforms = ['meta_pixel', 'meta_capi', 'tiktok_pixel', 'tiktok_capi'];
$platform = trim($_POST['platform'] ?? '');

// ── Simpan gabungan TikTok (Pixel browser + CAPI server) dalam SATU form ──
if ($platform === 'tiktok') {
    $tkUpsert = function (string $plat, int $active, string $pixelId, string $tokenIn): void {
        try {
            $st = getDB()->prepare("SELECT access_token FROM tracking_pixels WHERE platform = ? LIMIT 1");
            $st->execute([$plat]);
            $existing = (string) ($st->fetchColumn() ?: '');
        } catch (Exception $e) { $existing = ''; }
        // Token dikosongkan = pertahankan token lama (jangan terhapus).
        $token = $tokenIn !== '' ? pixel_encrypt($tokenIn) : $existing;
        getDB()->prepare(
            "INSERT INTO tracking_pixels (platform, is_active, pixel_id, access_token, test_event_code)
             VALUES (?, ?, ?, ?, '')
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), pixel_id = VALUES(pixel_id), access_token = VALUES(access_token)"
        )->execute([$plat, $active, $pixelId, $token]);
    };
    try {
        $tkUpsert('tiktok_pixel', (($_POST['tk_pixel_active'] ?? '0') === '1') ? 1 : 0, trim($_POST['tk_pixel_id'] ?? ''), '');
        $tkUpsert('tiktok_capi',  (($_POST['tk_capi_active']  ?? '0') === '1') ? 1 : 0, trim($_POST['tk_capi_id'] ?? ''), trim($_POST['tk_capi_token'] ?? ''));
        flash('success', 'Konfigurasi TikTok berhasil disimpan.');
    } catch (Exception $e) {
        error_log('Save tiktok combined error: ' . $e->getMessage());
        flash('error', 'Gagal menyimpan konfigurasi TikTok.', 'error');
    }
    redirect('/admin/tracking-pixels');
}

if (!in_array($platform, $allowedPlatforms, true)) {
    flash('error', 'Platform tidak valid.', 'error');
    redirect('/admin/tracking-pixels');
}

$isActive       = isset($_POST['is_active']) ? 1 : 0;
$pixelId        = trim($_POST['pixel_id']        ?? '');
$accessTokenIn  = trim($_POST['access_token']    ?? '');
$testEventCode  = trim($_POST['test_event_code'] ?? '');

// Token disimpan terenkripsi. Bila field dikosongkan, pertahankan token lama
// (mencegah token terhapus saat admin hanya mengubah field lain).
$accessToken = '';
try {
    $st = getDB()->prepare("SELECT access_token FROM tracking_pixels WHERE platform = ? LIMIT 1");
    $st->execute([$platform]);
    $existingToken = (string) ($st->fetchColumn() ?: '');
} catch (Exception $e) {
    $existingToken = '';
}
$accessToken = $accessTokenIn !== '' ? pixel_encrypt($accessTokenIn) : $existingToken;

try {
    getDB()->prepare(
        "INSERT INTO tracking_pixels (platform, is_active, pixel_id, access_token, test_event_code)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           is_active = VALUES(is_active),
           pixel_id  = VALUES(pixel_id),
           access_token   = VALUES(access_token),
           test_event_code = VALUES(test_event_code)"
    )->execute([$platform, $isActive, $pixelId, $accessToken, $testEventCode]);

    flash('success', 'Konfigurasi ' . strtoupper(str_replace('_', ' ', $platform)) . ' berhasil disimpan.');
} catch (Exception $e) {
    error_log('Save tracking pixel error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan konfigurasi.', 'error');
}

redirect('/admin/tracking-pixels');
