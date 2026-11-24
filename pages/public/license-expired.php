<?php
// Halaman ini bisa diakses meski lisensi expired — tidak di-enforce oleh middleware.
// Sengaja tidak memanggil requireLogin() agar admin yang logout pun tetap bisa lihat info ini.

// Kontak vendor & link perpanjangan diatur terpusat di License Server (Vendor Panel)
// dan disinkron ke sini lewat respons API. Fallback ke konstanta config bila belum sync.
$vendorEmail = getSetting('vendor_support_email', defined('VENDOR_EMAIL') ? VENDOR_EMAIL : '');
$vendorWa    = getSetting('vendor_support_whatsapp', defined('VENDOR_WHATSAPP') ? VENDOR_WHATSAPP : '');
$vendorWa    = ($vendorWa !== null && trim($vendorWa) !== '') ? $vendorWa : null;
$renewUrl    = trim((string) getSetting('vendor_renew_url', ''));
// Abaikan URL dev/localhost (mis. dari license server lokal) → pakai URL renew vendor produksi.
if ($renewUrl === '' || stripos($renewUrl, 'localhost') !== false || stripos($renewUrl, 'averion-license') !== false) {
    $renewUrl = 'https://darmutiara.com';
}
$appName     = getSetting('app_name', 'STIFIn Mulia');

// Source lisensi: 'direct' (vendor) atau 'agency'. Jika dikelola agency,
// tampilkan kontak agency, bukan tombol perpanjang/kontak vendor.
$licenseSource = getSetting('license_source', 'direct');
$agencyWa      = getSetting('agency_contact_wa', '');
$agencyWa      = ($agencyWa !== null && trim($agencyWa) !== '') ? preg_replace('/[^0-9]/', '', $agencyWa) : '';
$agencyEmail   = getSetting('agency_contact_email', '');
$agencyEmail   = ($agencyEmail !== null && trim($agencyEmail) !== '') ? trim($agencyEmail) : '';
$isAgency      = ($licenseSource === 'agency' && ($agencyWa !== '' || $agencyEmail !== ''));

$license = null;
try {
    $license = getDB()->query('SELECT status, plan, expires_at FROM license_info ORDER BY id ASC LIMIT 1')->fetch();
} catch (Exception $e) {
    // Tidak kritis — tampilkan halaman tanpa detail
}

$statusLabels = [
    'expired'   => 'Lisensi Anda telah kadaluarsa.',
    'suspended' => 'Lisensi Anda sedang disuspend.',
    'cancelled' => 'Lisensi Anda telah dibatalkan.',
    'unknown'   => 'Status lisensi tidak dapat diverifikasi.',
];
$statusMessage = $statusLabels[$license['status'] ?? 'unknown'] ?? $statusLabels['unknown'];

// Langkah perpanjangan menyesuaikan: self-service (ada link) vs manual (kontak).
$steps = [
    'Hubungi pengurus Darmutiara untuk memeriksa lisensi.',
    'Sebutkan domain instalasi.',
    'Setelah lisensi siap, masuk sebagai admin dan buka halaman aktivasi.',
];

$brandColor = getSetting('brand_color', '#6366f1');
// Favicon SVG inline — encode '#' agar valid di data URI.
$faviconColor = str_replace('#', '%23', $brandColor);

// ── Varian "toko sementara tutup" (checkout dikunci saat plan lisensi habis) ──
// Pengunjung publik (BUKAN pemilik/admin) yang mencoba checkout diarahkan ke sini
// dengan ?store=closed. Mereka bukan pemegang lisensi → JANGAN bocorkan detail
// lisensi, langkah perpanjangan, atau tautan panel admin. Cukup pesan netral.
if ((($_GET['store'] ?? '') === 'closed') && !isAdmin()):
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Toko Sementara Tutup — <?= e($appName) ?></title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='<?= $faviconColor ?>'/><rect x='10' y='14.5' width='12' height='9.5' rx='2.2' fill='white'/><path d='M12 14.5v-2.5a4 4 0 0 1 8 0v2.5' fill='none' stroke='white' stroke-width='2.2'/><circle cx='16' cy='18.7' r='1.4' fill='<?= $faviconColor ?>'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <style> body { font-family: Inter, sans-serif; }</style>
</head>
<body class="min-h-screen bg-gradient-to-b from-gray-50 to-gray-100 flex items-center justify-center p-4">
  <div class="max-w-sm w-full text-center">
    <div class="w-12 h-12 rounded-2xl bg-gray-100 ring-1 ring-gray-200 flex items-center justify-center mx-auto mb-4">
      <svg class="w-6 h-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72l1.189-1.19A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72M6.75 21v-3.75a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21"/>
      </svg>
    </div>
    <h1 class="text-xl font-bold text-gray-900">Toko Sementara Tutup</h1>
    <p class="text-sm text-gray-500 mt-2" style="line-height:1.5rem;">
      Toko ini untuk sementara tidak menerima pesanan baru.<br>Silakan coba lagi nanti.
    </p>
  </div>
</body>
</html>
<?php
return;
endif;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Lisensi Diperlukan — <?= e($appName) ?></title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='<?= $faviconColor ?>'/><rect x='10' y='14.5' width='12' height='9.5' rx='2.2' fill='white'/><path d='M12 14.5v-2.5a4 4 0 0 1 8 0v2.5' fill='none' stroke='white' stroke-width='2.2'/><circle cx='16' cy='18.7' r='1.4' fill='<?= $faviconColor ?>'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
  <!-- Tailwind compiled lokal (tanpa CDN) — regenerate: tools/build-tailwind.php -->
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/tailwind.css') ?: '1' ?>">
  <style>
  body { font-family: Inter, sans-serif; }</style>
</head>
<body class="min-h-screen bg-gradient-to-b from-gray-50 to-gray-100 flex items-center justify-center p-4">
  <div class="max-w-sm w-full">

    <!-- Header -->
    <div class="text-center mb-5">
      <div class="w-12 h-12 rounded-2xl bg-red-50 ring-1 ring-red-100 flex items-center justify-center mx-auto mb-3">
        <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
        </svg>
      </div>
      <h1 class="text-xl font-bold text-gray-900">Lisensi Tidak Aktif</h1>
      <p class="text-sm text-gray-400 mt-1"><?= e($statusMessage) ?></p>
    </div>

    <?php if ($isAgency): ?>
    <!-- Tampilan untuk klien agency -->
    <div class="bg-white rounded-2xl border border-gray-200/80 shadow-sm overflow-hidden">
      <?php if ($license && $license['expires_at']): ?>
      <div class="flex items-center justify-between gap-3 px-5 py-3 bg-gray-50/70 border-b border-gray-100 text-xs">
        <?php if ($license['plan']): ?>
        <span class="text-gray-400">Plan <span class="ml-1 font-semibold text-gray-700 capitalize"><?= e($license['plan']) ?></span></span>
        <?php endif; ?>
        <span class="text-gray-400">Kadaluarsa <span class="ml-1 font-semibold text-red-600"><?= e(date('d M Y', strtotime($license['expires_at']))) ?></span></span>
      </div>
      <?php endif; ?>
      <div class="px-5 py-5">
        <p class="text-sm font-medium text-gray-800 mb-3">
          Lisensi kamu dikelola oleh agency. Hubungi agency kamu untuk perpanjangan:
        </p>
        <div class="bg-gray-50 rounded-xl p-4 space-y-2.5">
          <?php if ($agencyWa !== ''): ?>
          <a href="https://wa.me/<?= e($agencyWa) ?>" target="_blank" rel="noopener noreferrer"
             class="flex items-center gap-2.5 text-sm font-medium text-green-700 hover:underline">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/></svg>
            WhatsApp Agency
          </a>
          <?php endif; ?>
          <?php if ($agencyEmail !== ''): ?>
          <a href="mailto:<?= e($agencyEmail) ?>"
             class="flex items-center gap-2.5 text-sm font-medium text-blue-600 hover:underline">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <?= e($agencyEmail) ?>
          </a>
          <?php endif; ?>
        </div>
        <p class="text-xs text-gray-400 mt-3">
          Perpanjangan dikelola oleh agency kamu — tidak perlu menghubungi vendor langsung.
        </p>
      </div>
    </div>

    <?php else: ?>
    <!-- Main card -->
    <div class="bg-white rounded-2xl border border-gray-200/80 shadow-sm overflow-hidden">

      <?php if ($license && $license['expires_at']): ?>
      <div class="flex items-center justify-between gap-3 px-5 py-3 bg-gray-50/70 border-b border-gray-100 text-xs">
        <?php if ($license['plan']): ?>
        <span class="text-gray-400">Plan <span class="ml-1 font-semibold text-gray-700 capitalize"><?= e($license['plan']) ?></span></span>
        <?php endif; ?>
        <span class="text-gray-400">Kadaluarsa <span class="ml-1 font-semibold text-red-600"><?= e(date('d M Y', strtotime($license['expires_at']))) ?></span></span>
      </div>
      <?php endif; ?>

      <!-- Cara Perpanjang -->
      <div class="px-5 py-4">
        <h2 class="text-sm font-semibold text-gray-900 mb-3">Cara Perpanjang Lisensi</h2>
        <ol class="space-y-2.5">
          <?php foreach ($steps as $i => $step): ?>
          <li class="flex items-start gap-2.5">
            <span class="flex-none w-5 h-5 rounded-full bg-gray-900 text-white text-[11px] font-semibold flex items-center justify-center mt-px"><?= $i + 1 ?></span>
            <span class="text-sm text-gray-600 leading-5"><?= $step ?></span>
          </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <?php if ($renewUrl): ?>
      <div class="px-5 pb-5">
        <a href="<?= e($renewUrl) ?>" target="_blank" rel="noopener noreferrer"
           class="flex items-center justify-center gap-2 w-full px-4 py-3 rounded-xl text-sm font-semibold text-white shadow-sm transition hover:opacity-90 active:opacity-80"
           style="background-color: <?= e($brandColor) ?>">
          Perpanjang Lisensi Sekarang
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Bantuan -->
    <div class="mt-4">
      <p class="text-center text-xs text-gray-400 mb-2.5">Butuh bantuan?</p>
      <div class="grid <?= $vendorWa ? 'grid-cols-2' : 'grid-cols-1' ?> gap-2.5">
        <a href="mailto:<?= e($vendorEmail) ?>"
           class="flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl border border-gray-200 bg-white text-xs font-medium text-gray-600 hover:bg-gray-50 hover:border-gray-300 transition">
          <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
          Email
        </a>
        <?php if ($vendorWa): ?>
        <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $vendorWa)) ?>"
           target="_blank" rel="noopener noreferrer"
           class="flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl border border-green-200 bg-green-50 text-xs font-medium text-green-700 hover:bg-green-100 transition">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
          </svg>
          WhatsApp
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <p class="text-center mt-4">
      <a href="<?= e(APP_URL) ?>/admin/license"
         class="text-xs font-medium text-gray-400 hover:text-gray-600 hover:underline transition">
        → Kelola lisensi di panel admin
      </a>
    </p>

  </div>
</body>
</html>
