<?php
// ─── Helper fungsi lokal installer ─────────────────────────────
// Berjalan sebelum main app ter-setup, jadi didefinisikan sendiri.

if (!function_exists('e')) {
    function e(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getSetting')) {
    function getSetting(string $key, ?string $default = null): ?string
    {
        $defaults = [
            'app_name'    => 'STIFIn Mulia',
            'brand_color' => '#043F2B',
        ];
        return $defaults[$key] ?? $default;
    }
}

$_appName    = getSetting('app_name', 'STIFIn Mulia');
$_brand      = getSetting('brand_color', '#043F2B');
$_initials   = strtoupper(substr($_appName, 0, 1));
$_totalSteps = 4;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Installer — <?= e($_appName) ?></title>
  <link rel="stylesheet" href="<?= e(str_replace('/views', '', dirname($_SERVER['SCRIPT_NAME']))) ?>/assets/installer.css">
  <!-- Tailwind compiled lokal (tanpa CDN — installer harus jalan walau koneksi
       keluar diblokir). Warna brand via var (kompatibel CSS hasil compile). -->
  <?php $_twBase = preg_replace('#/installer(/views)?$#', '', dirname($_SERVER['SCRIPT_NAME'])); ?>
  <link rel="stylesheet" href="<?= e($_twBase) ?>/assets/css/tailwind.css">
  <style>
    :root { --brand-color: <?= e($_brand) ?>; --brand-color-light: <?= e($_brand) ?>; }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-50 to-slate-100 flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="bg-white rounded-3xl shadow-xl shadow-indigo-100/60 p-8">

      <!-- Header: logo + nama app -->
      <div class="flex flex-col items-center mb-6">
        <div class="w-12 h-12 bg-brand rounded-xl flex items-center justify-center mb-3 shadow-sm">
          <span class="text-white font-bold text-xl"><?= e($_initials) ?></span>
        </div>
        <span class="font-bold text-lg text-gray-900"><?= e($_appName) ?></span>
        <p class="text-xs text-gray-400 mt-0.5">Pemasangan aplikasi</p>
      </div>

      <!-- Progress bar segmented -->
      <div class="flex gap-1.5 mb-7">
        <?php for ($i = 1; $i <= $_totalSteps; $i++): ?>
          <div class="flex-1 h-1.5 rounded-full transition-colors duration-300 <?= $i <= ($currentStep ?? 1) ? 'bg-brand' : 'bg-gray-100' ?>"></div>
        <?php endfor; ?>
      </div>

      <?php if (!empty($stepTitle)): ?>
        <h2 class="text-xl font-bold text-gray-900 mb-5">
          Langkah <?= (int) ($currentStep ?? 1) ?> — <?= e($stepTitle) ?>
        </h2>
      <?php endif; ?>
