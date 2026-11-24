<?php
// ─── Ikon PWA (di-generate GD) ────────────────────────────────────────────
// Disajikan di /pwa-icon-<size>.png (route di index.php). Full-bleed warna brand
// + segitiga putih (mark Averion, identik favicon fallback) → aman sebagai ikon
// MASKABLE (OS boleh memotong tepi; mark ada di zona aman tengah). White-label:
// warna ikut setting brand_color. bootstrap.php sudah dimuat oleh index.php.

if (getSetting('pwa_enabled', '1') !== '1') {
    http_response_code(404);
    echo 'Not found';
    return;
}

$size    = (int) ($_GET['size'] ?? 512);
$allowed = [180, 192, 256, 384, 512];
if (!in_array($size, $allowed, true)) {
    $size = 512;
}

// ─── Ikon KUSTOM (setting pwa_icon) — menggantikan segitiga bawaan ─────────
// White-label: admin upload ikon sendiri di Branding. Disajikan di-resize persegi
// (contain) di atas latar brand → aman utk 'any' maupun 'maskable'. Hanya file di
// dalam assets/uploads yang diizinkan (anti path-traversal).
$pwaIcon = trim((string) getSetting('pwa_icon', ''));
if ($pwaIcon !== '') {
    $iconPath = (string) parse_url($pwaIcon, PHP_URL_PATH);
    $appBase  = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
    if ($appBase !== '' && strpos($iconPath, $appBase . '/') === 0) {
        $iconPath = substr($iconPath, strlen($appBase));
    }
    $cand        = realpath(__DIR__ . '/../../' . ltrim($iconPath, '/'));
    $uploadsReal = realpath(__DIR__ . '/../../assets/uploads');
    if ($cand && $uploadsReal && strpos($cand, $uploadsReal) === 0 && is_file($cand)) {
        if (!function_exists('imagecreatefromstring')) {
            // Tanpa GD: sajikan file mentah (browser menskalakan).
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=604800');
            readfile($cand);
            return;
        }
        $src = @imagecreatefromstring((string) file_get_contents($cand));
        if ($src !== false) {
            $sw = imagesx($src); $sh = imagesy($src);
            $cbRaw = (string) getSetting('brand_color', '#6366f1');
            if (!preg_match('/^#([0-9a-fA-F]{6})$/', $cbRaw, $cm)) { $cm = [1 => '6366f1']; }
            $cr = hexdec(substr($cm[1], 0, 2)); $cg = hexdec(substr($cm[1], 2, 2)); $cbl = hexdec(substr($cm[1], 4, 2));
            $out = imagecreatetruecolor($size, $size);
            imagefilledrectangle($out, 0, 0, $size, $size, imagecolorallocate($out, $cr, $cg, $cbl));
            $scale = min($size / $sw, $size / $sh);
            $dw = max(1, (int) round($sw * $scale)); $dh = max(1, (int) round($sh * $scale));
            $dx = (int) (($size - $dw) / 2); $dy = (int) (($size - $dh) / 2);
            imagecopyresampled($out, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=604800');
            imagepng($out);
            imagedestroy($out); imagedestroy($src);
            return;
        }
        // Gagal decode → jatuh ke ikon bawaan di bawah.
    }
}

$brand = (string) getSetting('brand_color', '#6366f1');
if (!preg_match('/^#([0-9a-fA-F]{6})$/', $brand, $mm)) {
    $mm = [1 => '6366f1'];
}
$hex = $mm[1];
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));

if (!function_exists('imagecreatetruecolor')) {
    // GD tak tersedia di sebagian hosting → JANGAN 404 (ikon kosong = PWA tak bisa
    // di-install). Sajikan ikon fallback STATIS (brand default) yang sudah di-ship.
    $pick = 512;
    foreach ([180, 192, 512] as $fs) { if ($size <= $fs) { $pick = $fs; break; } }
    $fb = __DIR__ . '/../../assets/img/pwa-fallback-' . $pick . '.png';
    if (is_file($fb)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=604800');
        readfile($fb);
    } else {
        http_response_code(404);
        echo 'icon unavailable';
    }
    return;
}

header('Content-Type: image/png');
// Ikon jarang berubah; aman long-cache karena URL-nya pakai cache-bust ?v= dari manifest.
header('Cache-Control: public, max-age=604800');

$im = imagecreatetruecolor($size, $size);
$bg = imagecolorallocate($im, $r, $g, $b);
imagefilledrectangle($im, 0, 0, $size, $size, $bg);

// Segitiga putih — koordinat viewBox 32 (M16 7 L25 25 L7 25) diskalakan ke $size.
// Rentang 7..25 dari 32 ≈ 56% lebar, terpusat → berada di zona aman maskable (±40%).
$white = imagecolorallocate($im, 255, 255, 255);
$s     = $size / 32;
$points = [
    16 * $s, 7 * $s,   // puncak atas
    25 * $s, 25 * $s,  // kanan bawah
    7 * $s,  25 * $s,  // kiri bawah
];
// PHP 8.1: imagefilledpolygon(image, points, color) — tanpa num_points (deprecated).
imagefilledpolygon($im, $points, $white);

imagepng($im);
imagedestroy($im);
