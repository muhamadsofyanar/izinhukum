<?php
// Variabel yang bisa diset oleh halaman yang meng-include:
// $pageTitle (string) — judul tab browser
$pageTitle       = $pageTitle       ?? getSetting('app_name', 'STIFIn Mulia');
$brandColor      = getSetting('brand_color', '#043F2B');
$brandColorLight = getSetting('brand_color_secondary', '#496B59');
// Dark mode disimpan di cookie (di-set oleh JS) agar server bisa render
// <html class="dark"> sejak byte pertama → cegah kedip putih saat pindah menu.
// Fallback ke setting dark_mode_default bila cookie belum ada.
$darkPref = $_COOKIE['averion_dark'] ?? '';
if ($darkPref === '1')     $darkDefault = 'dark';
elseif ($darkPref === '0') $darkDefault = '';
else                       $darkDefault = getSetting('dark_mode_default', '0') === '1' ? 'dark' : '';
$appName         = getSetting('app_name', 'STIFIn Mulia');
$appFavicon      = getSetting('app_favicon', '');

// ── UI Theme / Appearance ──────────────────────────────────────
// Theme hanya diterapkan di area dashboard; halaman publik tidak terpengaruh.
require_once __DIR__ . '/../helpers/theme-config.php';
$themeConfig    = getThemeConfig();
$themeOnPage    = isDashboardContext();
$htmlThemeClass = $themeOnPage
    ? trim('darmutiara-dashboard dash-themed ' . $themeConfig['preview_class'] . ' sidebar-' . $themeConfig['sidebar_style'])
    : (!empty($darmutiaraKajianTheme) ? 'darmutiara-dashboard' : '');
?>
<!DOCTYPE html>
<html lang="id" class="<?= e(trim($darkDefault . ' ' . $htmlThemeClass)) ?>">
<head>
  <meta charset="UTF-8">
  <!-- Anti-FOUC: terapkan dark class SEBELUM browser paint pertama.
       Harus render-blocking (tanpa async/defer) dan posisi paling atas <head>.
       Key 'averion_dark' harus sama persis dengan yang dipakai toggleDarkMode() di app.js. -->
  <script>!function(){var d=localStorage.getItem('averion_dark');if(d==='1')document.documentElement.classList.add('dark');else if(d==='0')document.documentElement.classList.remove('dark');if(d==='1'||d==='0'){document.cookie='averion_dark='+d+';path=/;max-age=31536000;samesite=lax';}}();</script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($pageTitle) ?> — <?= e($appName) ?></title>
  <?php if (!empty($appFavicon)): ?>
  <link rel="icon" href="<?= e($appFavicon) ?>">
  <?php else: ?>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%23<?= e(ltrim($brandColor, '#')) ?>'/><path d='M16 7 L25 25 L7 25 Z' fill='white'/></svg>">
  <?php endif; ?>

  <?php if (getSetting('pwa_enabled', '1') === '1'): ?>
  <!-- PWA: aplikasi bisa di-install ke HP/desktop. Manifest dinamis (white-label)
       + ikon brand. Service worker pass-through (tanpa cache) diregister di bawah. -->
  <link rel="manifest" href="<?= e(APP_URL) ?>/manifest.webmanifest">
  <meta name="theme-color" content="<?= e($brandColor) ?>">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
  <link rel="apple-touch-icon" href="<?= e(APP_URL) ?>/pwa-icon/180">
  <?php endif; ?>

  <!-- Google Fonts: Inter — dimuat NON-BLOCKING (media=print → onload=all) agar
       halaman tak menunggu font eksternal sebelum paint. Tanpa ini, saat koneksi ke
       Google lambat, browser menahan render → tampak KEDIP PUTIH tiap pindah menu
       (parah di lokal). Fallback system-font tampil dulu, Inter menyusul (display=swap). -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
        media="print" onload="this.media='all';this.onload=null">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"></noscript>

  <!-- Tailwind CSS (compiled lokal — TANPA dependensi CDN saat runtime).
       Warna brand dipetakan ke var(--brand-color) di :root bawah, jadi tetap
       dinamis mengikuti setting. Regenerate: tools/build-tailwind.php. -->
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../assets/css/tailwind.css') ?: '1' ?>">

  <!-- CSS Variables + anti-flash base -->
  <style>
    /* Font Inter dimuat via <link> di atas (preconnect) — bukan @import lagi. */
    :root {
      --brand-color:       <?= e($brandColor) ?>;
      --brand-color-light: <?= e($brandColorLight) ?>;
      /* UI Theme variables (lihat helpers/theme-config.php) */
      --bg-canvas:      <?= e($themeConfig['--bg-canvas']) ?>;
      --card-radius:    <?= e($themeConfig['--card-radius']) ?>;
      --control-radius: <?= e($themeConfig['--control-radius'] ?? '0.375rem') ?>;
      --card-shadow:    <?= e($themeConfig['--card-shadow']) ?>;
      --sidebar-radius: <?= e($themeConfig['--sidebar-radius']) ?>;
    }
    /* Warna dasar diterapkan INSTAN (sebelum Tailwind CDN meng-generate style),
       mencegah kedip putih saat pindah halaman — terutama di dark mode.
       color-scheme membuat kanvas browser ikut gelap sejak frame pertama. */
    html       { background-color: #f9fafb; color-scheme: light; }
    html.dark  { background-color: #030712; color-scheme: dark; }
    /* Opsi <select> native: paksa warna eksplisit. Di Chrome Windows (apalagi saat
       OS dark-mode tapi app light), teks <option> bisa hilang kontras / tak terlihat
       di highlight. Pola fix yang sama sudah dipakai di funnel-gate. */
    option           { background-color: #ffffff; color: #111827; }
    html.dark option { background-color: #1f2937; color: #f3f4f6; }
  </style>

  <!-- Konfigurasi mata uang untuk sisi JS (window.fmtMoney di app.js) -->
  <script>window.APP_CURRENCY = <?= currencyJsConfig() ?>;</script>

  <!-- App CSS -->
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: '1' ?>">
<?php if ($themeOnPage || !empty($darmutiaraKajianTheme)): ?>
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/stifin.css?v=<?= @filemtime(__DIR__ . '/../assets/css/stifin.css') ?: '1' ?>">
<?php endif; ?>
<?php if (getSetting('pwa_enabled', '1') === '1'): ?>
  <!-- Registrasi service worker PWA (pass-through, tanpa cache) + tangkap event
       install lebih awal (beforeinstallprompt bisa menyala sebelum app.js termuat).
       app.js yang menampilkan tombol Install & memproses klik (pakai showToast). -->
  <script>
    if('serviceWorker' in navigator){addEventListener('load',function(){navigator.serviceWorker.register('<?= e(APP_URL) ?>/sw.js').catch(function(){});});}
    window.__pwaDeferred=null;
    window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();window.__pwaDeferred=e;var b=document.getElementById('pwaInstallBtn');if(b)b.hidden=false;});
  </script>
<?php endif; ?>
<?php
require_once __DIR__ . '/../tracking/PixelHelper.php';
echo PixelHelper::renderClientScripts();
?>
<?php if (!empty($muliaPageStyle)): ?><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/mulia-pages.css?v=1"><?php endif; ?>
</head>
