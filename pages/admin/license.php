<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Cache-Control: no-store');

$pageTitle   = 'Lisensi';
$topbarTitle = 'Lisensi';

$license = _licenseInfoRow();
$installationDomain = parseDomainFromAppUrl();
$key = (string) ($license['license_key'] ?? '');
$isDarmutiaraKey = str_starts_with($key, 'DRM-');
$maskedKey = $key === '' ? 'Belum dipasang' : (strlen($key) > 8 ? substr($key, 0, 4) . '••••••••' . substr($key, -4) : '••••••••');
$proof = $isDarmutiaraKey && !empty($license['signed_token'])
    ? darmutiaraLicenseClaims((string) $license['signed_token'], $installationDomain)
    : null;
$appName    = getSetting('app_name', 'STIFIn Mulia');
// Versi mengikuti version.php (APP_VERSION) secara otomatis — bukan setting lama yang statis.
$appVersion = defined('APP_VERSION') ? APP_VERSION : getSetting('app_version', '1.0.0');
$renewUrl   = trim((string) getSetting('vendor_renew_url', ''));
// Abaikan URL dev/localhost (mis. dari license server lokal) → pakai URL renew vendor produksi.
if ($renewUrl === '' || stripos($renewUrl, 'localhost') !== false || stripos($renewUrl, 'averion-license') !== false) {
    $renewUrl = 'https://darmutiara.com';
}

// Sumber lisensi: 'direct' (vendor) atau 'agency'. Bila dikelola agency, JANGAN
// arahkan perpanjangan ke vendor (memotong jalur reseller) — tampilkan kontak
// agency. Data ini disimpan saat cek lisensi (lihat actions/admin/check-license.php)
// & dipakai juga di pages/public/license-expired.php agar polanya seragam.
$licenseSource = getSetting('license_source', 'direct');
$agencyWa      = getSetting('agency_contact_wa', '');
$agencyWa      = ($agencyWa !== null && trim($agencyWa) !== '') ? preg_replace('/[^0-9]/', '', $agencyWa) : '';
$agencyEmail   = getSetting('agency_contact_email', '');
$agencyEmail   = ($agencyEmail !== null && trim($agencyEmail) !== '') ? trim($agencyEmail) : '';

// State lisensi efektif (hardening A1/A4) — sumber badge & alert.
// Saat LICENSE_ENFORCEMENT_ENABLED mati, ini selalu 'valid' (bypass transisi).
$ls         = _resolveCurrentLicenseState();
// plan_expired (vendor menandai status 'expired' EKSPLISIT = masa aktif HABIS) dibawa
// token dengan valid_until SEGAR → $ls['state'] di atasnya 'valid'. Flag inilah
// pembedanya; tanpa ini badge/alert keliru menampilkan "Aktif" saat terkunci.
// Beda dari 'expired' token-aging (jaringan) yang butuh re-aktivasi, bukan perpanjang.
$lsState    = $ls['suspended'] ? 'suspended'
            : (!empty($ls['plan_expired']) ? 'plan_expired' : $ls['state']);
$lsBadgeMap = [
    'valid'              => ['Aktif', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'],
    'grace'              => ['Perlu Re-validasi', 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'],
    'expired'            => ['Admin Panel Terkunci', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'],
    'plan_expired'       => ['Masa Aktif Berakhir', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'],
    'pending_activation' => ['Belum Diaktifkan', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'],
    'suspended'          => ['Disuspend', 'bg-red-200 text-red-900 dark:bg-red-900/50 dark:text-red-300'],
];
[$lsLabel, $lsBadgeClass] = $lsBadgeMap[$lsState] ?? $lsBadgeMap['pending_activation'];
$lsDaysLeft = $ls['days_until_expiry'] !== null ? ((int) $ls['days_until_expiry'] + LICENSE_OFFLINE_GRACE_DAYS) : null;

generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Lisensi</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kelola dan verifikasi lisensi <?= e($appName) ?></p>
      </div>

      <?php if ($lsState !== 'valid'): ?>
      <div class="max-w-4xl mb-5">
        <?php if ($lsState === 'suspended'): ?>
        <div class="flex items-start gap-2.5 px-4 py-3.5 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/40 text-red-800 dark:text-red-300 text-sm">
          <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="5.6" y1="5.6" x2="18.4" y2="18.4"/></svg>
          <div><p class="font-medium">Lisensi disuspend</p><p class="text-xs mt-0.5">Akses aplikasi dihentikan oleh penyedia platform. Hubungi support untuk pemulihan.</p></div>
        </div>
        <?php elseif ($lsState === 'plan_expired'): ?>
        <?php // Masa aktif lisensi BENAR-BENAR habis (status 'expired' eksplisit vendor).
              // Aksi yang benar = PERPANJANG (bukan re-aktivasi). CTA mengikuti sumber
              // lisensi: agency → kontak agency; direct → URL renew vendor. ?>
        <div class="flex items-start justify-between gap-3 px-4 py-3.5 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/40 text-red-800 dark:text-red-300 text-sm">
          <div class="flex items-start gap-2.5">
            <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
            <div><p class="font-medium">Masa aktif lisensi berakhir</p><p class="text-xs mt-0.5">Checkout &amp; admin panel terkunci. Perpanjang lisensi, lalu klik <strong>Sudah Perpanjang? Cek &amp; Buka Kembali</strong> di bawah untuk membuka akses.</p></div>
          </div>
          <?php if ($licenseSource === 'agency' && $agencyWa !== ''): ?>
          <a href="https://wa.me/<?= e($agencyWa) ?>" target="_blank" rel="noopener noreferrer" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background-color:var(--brand-color)">Perpanjang via Agency</a>
          <?php elseif ($licenseSource === 'agency' && $agencyEmail !== ''): ?>
          <a href="mailto:<?= e($agencyEmail) ?>" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background-color:var(--brand-color)">Perpanjang via Agency</a>
          <?php elseif ($renewUrl): ?>
          <a href="<?= e($renewUrl) ?>" target="_blank" rel="noopener noreferrer" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background-color:var(--brand-color)">Perpanjang Lisensi</a>
          <?php endif; ?>
        </div>
        <?php elseif ($lsState === 'expired'): ?>
        <div class="flex items-start justify-between gap-3 px-4 py-3.5 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/40 text-red-800 dark:text-red-300 text-sm">
          <div class="flex items-start gap-2.5">
            <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
            <div><p class="font-medium">Admin panel terkunci</p><p class="text-xs mt-0.5">Lisensi belum tervalidasi ulang. Re-aktivasi untuk membuka kembali akses admin.</p></div>
          </div>
          <a href="<?= e(APP_URL) ?>/admin/license-activate" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background-color:var(--brand-color)">Re-aktivasi</a>
        </div>
        <?php elseif ($lsState === 'pending_activation'): ?>
        <div class="flex items-start justify-between gap-3 px-4 py-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 text-sm">
          <div class="flex items-start gap-2.5">
            <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div><p class="font-medium">Lisensi belum diaktifkan</p><p class="text-xs mt-0.5">Aktifkan lisensi untuk membuka akses penuh.</p></div>
          </div>
          <a href="<?= e(APP_URL) ?>/admin/license-activate" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background-color:var(--brand-color)">Aktivasi</a>
        </div>
        <?php elseif ($lsState === 'grace'): ?>
        <div class="flex items-start gap-2.5 px-4 py-3.5 rounded-xl bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800/40 text-yellow-800 dark:text-yellow-300 text-sm">
          <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <div><p class="font-medium">Lisensi perlu re-validasi</p><p class="text-xs mt-0.5"><?= $lsDaysLeft !== null ? 'Sisa sekitar ' . (int) $lsDaysLeft . ' hari sebelum admin panel terkunci.' : 'Segera re-validasi sebelum admin panel terkunci.' ?></p></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="max-w-4xl space-y-5">

        <!-- ─── Status Lisensi ──────────────────────────────────── -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3" style="background:color-mix(in srgb, var(--brand-color) 5%, transparent);">
            <div class="flex items-center gap-2.5 min-w-0">
              <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg shrink-0" style="background:color-mix(in srgb, var(--brand-color) 14%, transparent); color:var(--brand-color);">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
              </span>
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Status Lisensi</h3>
            </div>
            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold shrink-0 <?= $lsBadgeClass ?>">
              <?= e($lsLabel) ?>
            </span>
          </div>
          <div class="p-5">
            <?php if ($license): ?>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3.5 text-sm">
              <dt class="text-gray-500 dark:text-gray-400">Kunci lisensi</dt>
              <dd class="font-mono text-gray-900 dark:text-white text-sm break-all"><?= e($maskedKey) ?></dd>
              <dt class="text-gray-500 dark:text-gray-400">Domain instalasi</dt>
              <dd class="font-medium text-gray-900 dark:text-white break-all"><?= e($installationDomain ?: 'Belum dikonfigurasi') ?></dd>
              <?php if (!empty($license['domain']) && $license['domain'] !== $installationDomain): ?>
              <dt class="text-gray-500 dark:text-gray-400">Domain lisensi tersimpan</dt>
              <dd class="font-medium text-orange-700 dark:text-orange-400 break-all"><?= e($license['domain']) ?></dd>
              <?php endif; ?>
              <?php if ($isDarmutiaraKey): ?>
              <dt class="text-gray-500 dark:text-gray-400">Bukti verifikasi berlaku hingga</dt>
              <dd class="font-medium text-gray-900 dark:text-white">
                <?= $proof === null ? 'Perlu verifikasi ulang' : e(gmdate('d M Y H:i', $proof['exp']) . ' UTC') ?>
              </dd>
              <?php endif; ?>
              <?php if ($license['plan']): ?>
              <dt class="text-gray-500 dark:text-gray-400">Plan</dt>
              <dd class="font-medium text-gray-900 dark:text-white"><?= e(ucfirst($license['plan'])) ?></dd>
              <?php endif; ?>

              <?php if ($license['activated_at']): ?>
              <dt class="text-gray-500 dark:text-gray-400">Diaktifkan</dt>
              <dd class="font-medium text-gray-900 dark:text-white"><?= e(date('d M Y', strtotime($license['activated_at']))) ?></dd>
              <?php endif; ?>

              <?php if (($license['plan'] ?? '') === 'lifetime'): ?>
              <dt class="text-gray-500 dark:text-gray-400">Kadaluarsa</dt>
              <dd class="font-medium text-indigo-600 dark:text-indigo-400">Seumur Hidup</dd>
              <?php elseif ($license['expires_at']): ?>
              <dt class="text-gray-500 dark:text-gray-400">Kadaluarsa</dt>
              <dd class="font-medium <?= strtotime($license['expires_at']) < time() ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' ?>">
                <?= e(date('d M Y', strtotime($license['expires_at']))) ?>
                <?php
                $daysLeft = (int) ceil((strtotime($license['expires_at']) - time()) / 86400);
                if ($daysLeft > 0 && $daysLeft <= 30):
                ?>
                <span class="ml-1 text-xs text-orange-500">(<?= $daysLeft ?> hari lagi)</span>
                <?php elseif ($daysLeft <= 0): ?>
                <span class="ml-1 text-xs text-red-500">(Kadaluarsa)</span>
                <?php endif; ?>
              </dd>
              <?php endif; ?>

              <?php if ($license['last_checked_at']): ?>
              <dt class="text-gray-500 dark:text-gray-400">Terakhir Dicek</dt>
              <dd class="text-gray-600 dark:text-gray-400"><?= e(date('d M Y H:i', strtotime($license['last_checked_at']))) ?></dd>
              <?php endif; ?>
            </dl>
            <?php else: ?>
            <div class="text-center py-6">
              <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
                <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
              </div>
              <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Belum Ada Lisensi</p>
              <p class="text-xs text-gray-400 dark:text-gray-600">Masukkan license key di bawah untuk mengaktifkan</p>
            </div>
            <?php endif; ?>
          </div>
          <?php
          // "Cek Ulang" = re-sync satu-klik ke server (/validate) tanpa mengetik
          // ulang key. Tampilkan juga saat expired/grace — justru di situ buyer
          // paling butuh: sesudah perpanjangan, satu klik langsung membuka kembali.
          if ($license && !empty($license['license_key']) && in_array($lsState, ['valid', 'grace', 'expired', 'plan_expired', 'pending_activation'], true)):
            $isExpiredState = in_array($lsState, ['expired', 'grace', 'plan_expired'], true);
          ?>
          <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/check-license">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="recheck">
              <button type="submit" data-loading data-loading-text="Mengecek..."
                      class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition <?= $isExpiredState
                          ? 'text-white hover:opacity-90'
                          : 'border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 font-medium' ?>"
                      <?= $isExpiredState ? 'style="background-color:var(--brand-color)"' : '' ?>>
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>
                <?= $isExpiredState ? 'Sudah Perpanjang? Cek &amp; Buka Kembali' : 'Cek Ulang Lisensi' ?>
              </button>
            </form>
            <?php if ($isExpiredState): ?>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Sudah menyelesaikan perpanjangan? Klik untuk mengecek ke server &amp; membuka akses tanpa mengetik ulang license key.</p>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- ─── Aktivasi / Ganti Lisensi ───────────────────────── -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-start gap-2.5" style="background:color-mix(in srgb, var(--brand-color) 5%, transparent);">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg shrink-0 mt-0.5" style="background:color-mix(in srgb, var(--brand-color) 14%, transparent); color:var(--brand-color);">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z"/></svg>
            </span>
            <div class="min-w-0">
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm"><?= $license ? 'Ganti kunci lisensi' : 'Aktivasi Lisensi' ?></h3>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Masukkan kunci dari penerbit lisensi</p>
            </div>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/check-license">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="activate">
            <div class="p-5 space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5" for="replacement-key">Kunci lisensi DRM</label>
                <input id="replacement-key" type="password" name="license_key" required placeholder="DRM-…" maxlength="47"
                       class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-300 dark:placeholder-gray-600 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                       autocomplete="new-password" autocapitalize="off" spellcheck="false" aria-describedby="replacement-help">
                <p id="replacement-help" class="text-sm text-gray-600 dark:text-gray-400 mt-1.5">
                  Kunci diawali <code>DRM-</code> dan harus diterbitkan untuk domain
                  <strong class="break-all"><?= e($installationDomain ?: 'yang dikonfigurasi di APP_URL') ?></strong>.
                </p>
              </div>
            </div>
            <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex flex-wrap justify-end gap-2">
              <?php if ($licenseSource === 'agency'): ?>
              <?php /* Lisensi dikelola agency → arahkan perpanjangan ke agency, bukan vendor. */ ?>
                <?php if ($agencyWa !== ''): ?>
                <a href="https://wa.me/<?= e($agencyWa) ?>" target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium border border-green-200 dark:border-green-800/50 text-green-700 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition">
                  <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/></svg>
                  Perpanjang via Agency
                </a>
                <?php elseif ($agencyEmail !== ''): ?>
                <a href="mailto:<?= e($agencyEmail) ?>"
                   class="inline-flex items-center gap-1.5 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium border border-blue-200 dark:border-blue-800/50 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                  Perpanjang via Agency
                </a>
                <?php endif; ?>
              <?php elseif ($renewUrl): ?>
              <a href="<?= e($renewUrl) ?>" target="_blank" rel="noopener noreferrer"
                 class="inline-flex items-center gap-1.5 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                Perpanjang Lisensi
              </a>
              <?php endif; ?>
              <button type="submit" data-loading data-loading-text="Mengaktifkan..."
                      class="whitespace-nowrap px-5 py-2 rounded-lg text-sm font-semibold text-white hover:opacity-90 transition"
                      style="background-color: var(--brand-color);">
                <?= $license ? 'Ganti &amp; Verifikasi' : 'Aktivasi Lisensi' ?>
              </button>
            </div>
          </form>
        </div>

        <!-- ─── Info Produk ─────────────────────────────────────── -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2.5" style="background:color-mix(in srgb, var(--brand-color) 5%, transparent);">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg shrink-0" style="background:color-mix(in srgb, var(--brand-color) 14%, transparent); color:var(--brand-color);">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            </span>
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Informasi Produk</h3>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
              <span class="inline-flex items-center gap-2.5 text-sm text-gray-500 dark:text-gray-400">
                <svg class="w-4 h-4 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                Aplikasi
              </span>
              <span class="inline-flex items-center gap-2 min-w-0">
                <span class="font-semibold text-gray-900 dark:text-white truncate"><?= e($appName) ?></span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold font-mono shrink-0" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent); color:var(--brand-color);">v<?= e($appVersion) ?></span>
              </span>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
              <span class="inline-flex items-center gap-2.5 text-sm text-gray-500 dark:text-gray-400">
                <svg class="w-4 h-4 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                PHP
              </span>
              <span class="font-mono text-sm text-gray-700 dark:text-gray-300"><?= e(PHP_VERSION) ?></span>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
              <span class="inline-flex items-center gap-2.5 text-sm text-gray-500 dark:text-gray-400">
                <svg class="w-4 h-4 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                Domain
              </span>
              <span class="font-mono text-xs text-gray-700 dark:text-gray-300 truncate max-w-[55%] text-right"><?= e($installationDomain ?: 'Belum dikonfigurasi') ?></span>
            </div>
          </div>
        </div>



      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
