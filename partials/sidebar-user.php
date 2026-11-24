<?php
// Tentukan path aktif saat ini
$_uri      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
$_basePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/');
if ($_basePath && str_starts_with($_uri, $_basePath)) {
    $_uri = substr($_uri, strlen($_basePath));
}
if (empty($_uri)) $_uri = '/';
if (!str_starts_with($_uri, '/')) $_uri = '/' . $_uri;

$navClass = function(string $path, bool $exact = false) use ($_uri): string {
    $active = $exact
        ? (rtrim($_uri, '/') === rtrim($path, '/'))
        : (str_starts_with($_uri, $path));

    if ($active) {
        return 'nav-item-active group flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold';
    }
    return 'group flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800/60 hover:text-gray-900 dark:hover:text-white transition-colors duration-150';
};

$iconClass = function(string $path, bool $exact = false) use ($_uri): string {
    $active = $exact
        ? (rtrim($_uri, '/') === rtrim($path, '/'))
        : (str_starts_with($_uri, $path));
    return $active
        ? 'w-5 h-5 shrink-0 text-[var(--brand-color)]'
        : 'w-5 h-5 shrink-0 text-gray-400 dark:text-gray-500 group-hover:text-gray-600 dark:group-hover:text-gray-300 transition-colors';
};

$appName   = getSetting('app_name', 'STIFIn Mulia');
$appLogo   = getSetting('app_logo', '');

// Tampilkan menu "Lisensi Saya" hanya jika user punya minimal 1 license.
$hasLicense = false;
try {
    $_lc = getDB()->prepare("SELECT id FROM product_licenses WHERE user_id = ? LIMIT 1");
    $_lc->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $hasLicense = (bool) $_lc->fetch();
} catch (Exception $e) {
    // Tabel belum ada (migration belum jalan) → sembunyikan menu.
    $hasLicense = false;
}

// Lisensi vendor yang masih diantre/gagal BELUM punya baris di product_licenses.
// Tanpa cek ini, menu tersembunyi tepat pada saat pembeli paling butuh: sudah
// bayar, kunci belum terbit → dia tak punya jalan melihat status "sedang
// disiapkan" & mengira lisensinya hangus.
if (!$hasLicense && function_exists('vfEnabled') && vfEnabled()) {
    try {
        $_fc = getDB()->prepare(
            "SELECT id FROM license_fulfillments WHERE user_id = ? AND status IN ('pending','failed') LIMIT 1"
        );
        $_fc->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $hasLicense = (bool) $_fc->fetch();
    } catch (Exception $e) {
        // Tabel antrean belum ada → biarkan tersembunyi.
    }
}

// Tampilkan menu "Pendapatan JV" hanya jika user pernah tercatat sbg partner JV.
$isJvPartner = false;
try {
    $_jv = getDB()->prepare("SELECT id FROM jv_earnings WHERE partner_user_id = ? LIMIT 1");
    $_jv->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $isJvPartner = (bool) $_jv->fetch();
} catch (Exception $e) {
    // Tabel belum ada (migrasi belum jalan) → sembunyikan menu.
    $isJvPartner = false;
}

// Menu "Penawaran" hanya bila admin mengaktifkan & member punya penawaran upsell.
$offersCount = function_exists('memberOffersCount')
    ? memberOffersCount((int) ($_SESSION['user_id'] ?? 0)) : 0;

// Menu "Tiket Support" hanya untuk pembeli. Badge = balasan admin belum dibaca.
$showTickets   = function_exists('ticketIsBuyer') && ticketIsBuyer((int) ($_SESSION['user_id'] ?? 0));
$ticketsUnread = 0;
if ($showTickets) {
    try {
        $_tk = getDB()->prepare(
            "SELECT COUNT(*) FROM support_tickets
             WHERE user_id = ? AND last_reply_by = 'admin'
               AND (user_seen_at IS NULL OR user_seen_at < last_reply_at)"
        );
        $_tk->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $ticketsUnread = (int) $_tk->fetchColumn();
    } catch (Throwable $e) { $ticketsUnread = 0; }
}
?>
<!-- Sidebar User -->
<aside id="sidebar" class="sidebar darmutiara-sidebar fixed inset-y-0 left-0 z-40 w-60 flex flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 lg:translate-x-0 -translate-x-full">

  <!-- Logo / App Name -->
  <div class="dmi-brand flex items-center gap-3 px-5 py-4 border-b border-gray-200 dark:border-gray-800 shrink-0">
    <?php if (!empty($appLogo)): ?>
      <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-8 w-auto object-contain">
    <?php else: ?>
      <span class="dmi-mark" aria-hidden="true">D</span>
    <?php endif; ?>
    <span class="dmi-brand-name"><?= e($appName) ?></span>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">

    <!-- Dashboard -->
    <a href="<?= e(APP_URL) ?>/dashboard" class="<?= $navClass('/dashboard', true) ?>">
      <svg class="<?= $iconClass('/dashboard', true) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9 22 9 12 15 12 15 22"/>
      </svg>
      Dashboard
    </a>

    <a href="<?= e(APP_URL) ?>/kajian" class="<?= $navClass('/kajian') ?>">
      <svg class="<?= $iconClass('/kajian') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
      Jadwal Kajian
    </a>

    <!-- My Products / Member Area -->
    <a href="<?= e(APP_URL) ?>/member" class="<?= $navClass('/member') ?>">
      <svg class="<?= $iconClass('/member') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
      </svg>
      Area Member
    </a>

    <!-- My Licenses (hanya jika punya license) -->
    <?php if ($hasLicense): ?>
    <a href="<?= e(APP_URL) ?>/member/my-licenses" class="<?= $navClass('/member/my-licenses') ?>">
      <svg class="<?= $iconClass('/member/my-licenses') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
      </svg>
      Lisensi Saya
    </a>
    <?php endif; ?>

    <!-- Katalog produk (etalase untuk member) -->
    <a href="<?= e(APP_URL) ?>/katalog" class="<?= $navClass('/katalog') ?>">
      <svg class="<?= $iconClass('/katalog') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9h18M3 9l1.5-4.5A2 2 0 0 1 6.4 3h11.2a2 2 0 0 1 1.9 1.5L21 9M3 9v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9"/>
      </svg>
      Katalog
    </a>

    <!-- Penawaran Spesial (hanya bila fitur aktif & ada penawaran untuk member) -->
    <?php if ($offersCount > 0): ?>
    <a href="<?= e(APP_URL) ?>/penawaran" class="<?= $navClass('/penawaran') ?>">
      <svg class="<?= $iconClass('/penawaran') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>
      </svg>
      <span class="flex-1">Penawaran</span>
      <span class="sb-help-badge"><?= $offersCount > 9 ? '9+' : (int) $offersCount ?></span>
    </a>
    <?php endif; ?>

    <!-- Poin Reward (hanya bila fitur aktif) -->
    <?php if (function_exists('pointsEnabled') && pointsEnabled()): ?>
    <a href="<?= e(APP_URL) ?>/rewards" class="<?= $navClass('/rewards') ?>">
      <svg class="<?= $iconClass('/rewards') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>
      </svg>
      <?= e(pointsMenuLabel()) ?>
    </a>
    <?php endif; ?>

    <?php // Agency SEO Engine (vendor-only): hanya tampil bila punya kuota paket.
    if (function_exists('agencySeoAvailable') && agencySeoAvailable()
        && agencySeoQuotasForUser((int) ($_SESSION['user_id'] ?? 0))): ?>
    <a href="<?= e(APP_URL) ?>/agency-seo" class="<?= $navClass('/agency-seo') ?>">
      <svg class="<?= $iconClass('/agency-seo') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/>
      </svg>
      Agency SEO
    </a>
    <?php endif; ?>

    <?php require_once __DIR__ . '/../helpers/stifin-promoters.php';
    $stifinSidebarPromoter = stifinLinkedPromoterCode(getDB(), (int) ($_SESSION['user_id'] ?? 0));
    if ($stifinSidebarPromoter !== null): ?>
    <a href="<?= e(APP_URL) ?>/member/stifin-saya" class="<?= $navClass('/member/stifin-saya') ?>"><svg aria-hidden="true" class="<?= $iconClass('/member/stifin-saya') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>
      <span>STIFIn Saya</span></a>
    <?php endif; ?>
    <?php if (stifinVoucherProductId() > 0 || $stifinSidebarPromoter !== null): ?>
    <a href="<?= e(APP_URL) ?>/member/voucher-tes" class="<?= $navClass('/member/voucher-tes') ?>">
      <svg aria-hidden="true" class="<?= $iconClass('/member/voucher-tes') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4zM15 8v2m0 4v2"/></svg>
      <span>Voucher Tes</span>
    </a>
    <?php endif; ?>
    <!-- Transactions -->
    <a href="<?= e(APP_URL) ?>/transactions" class="<?= $navClass('/transactions') ?>">
      <svg class="<?= $iconClass('/transactions') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="1" y="4" width="22" height="16" rx="2"/>
        <line x1="1" y1="10" x2="23" y2="10"/>
      </svg>
      Transaksi
    </a>

    <!-- Downloads (bisa disembunyikan dari Pengaturan → Menu Dashboard Member) -->
    <?php if (getSetting('member_menu_downloads', '1') !== '0'): ?>
    <a href="<?= e(APP_URL) ?>/downloads" class="<?= $navClass('/downloads') ?>">
      <svg class="<?= $iconClass('/downloads') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
      </svg>
      File Downloads
    </a>
    <?php endif; ?>

    <!-- Sertifikat (bisa disembunyikan dari Pengaturan → Menu Dashboard Member) -->
    <?php if (getSetting('member_menu_certificates', '1') !== '0'): ?>
    <a href="<?= e(APP_URL) ?>/certificates" class="<?= $navClass('/certificates') ?>">
      <svg class="<?= $iconClass('/certificates') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="8" r="6"/><path stroke-linecap="round" stroke-linejoin="round" d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
      </svg>
      Sertifikat Saya
    </a>
    <?php endif; ?>

    <!-- Affiliate (tampil hanya bila program aktif DAN user benar-benar berafiliasi:
         admin/staff selalu; member biasa harus eligible (gate mode 'buyers') DAN
         salah satu: (a) sudah punya aktivitas afiliat nyata (>=1 referral/komisi),
         atau (b) ditargetkan Aturan Komisi khusus (rate per level membership-nya,
         atau rule resell atas produk yang dimilikinya). Halaman /affiliate sendiri
         tetap bisa dibuka via URL agar member bisa mengambil link pertamanya;
         begitu ada aktivitas, menu muncul otomatis. -->
    <?php
      $_affUid        = (int) ($_SESSION['user_id'] ?? 0);
      $_affPrivileged = in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true);
      // Admin boleh memaksa menu afiliasi tampil ke semua member yang memenuhi
      // mode (affiliate_menu_show_all), melewati syarat "punya aktivitas/komisi".
      $_affShowAll    = getSetting('affiliate_menu_show_all', '0') === '1';
      $_affQualified  = $_affShowAll
          || (function_exists('affiliateHasActivity') && affiliateHasActivity($_affUid))
          || (function_exists('affiliateHasCommissionEntitlement') && affiliateHasCommissionEntitlement($_affUid));
      $_showAffiliate = affiliateProgramEnabled()
          && ($_affPrivileged
              || (function_exists('affiliateEligibleUser')
                  && affiliateEligibleUser($_affUid)
                  && $_affQualified));
    ?>
    <?php if ($_showAffiliate): ?>
    <a href="<?= e(APP_URL) ?>/affiliate" class="<?= $navClass('/affiliate') ?>">
      <svg class="<?= $iconClass('/affiliate') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
      </svg>
      Affiliate
    </a>
    <?php endif; ?>

    <!-- Reseller (otomatis tampil hanya bila user punya Link Salespage Siap Pakai) -->
    <?php if (function_exists('userHasResellerSalespages') && userHasResellerSalespages((int) ($_SESSION['user_id'] ?? 0))): ?>
    <a href="<?= e(APP_URL) ?>/reseller" class="<?= $navClass('/reseller') ?>">
      <svg class="<?= $iconClass('/reseller') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9l1-5h16l1 5"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M9 22V12h6v10"/>
      </svg>
      Reseller
    </a>
    <?php endif; ?>

    <!-- Pendapatan JV (hanya jika user tercatat sbg partner JV) -->
    <?php if ($isJvPartner): ?>
    <a href="<?= e(APP_URL) ?>/jv-earnings" class="<?= $navClass('/jv-earnings') ?>">
      <svg class="<?= $iconClass('/jv-earnings') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
      </svg>
      Pendapatan JV
    </a>
    <?php endif; ?>

    <!-- Menu "Profil" sengaja DIHAPUS dari sidebar — sudah tersedia di dropdown
         akun pojok kanan atas (topbar): "Profil Saya" + "Keluar". -->

  </nav>

  <!-- Pusat Bantuan: dua halaman terpisah, di atas pemisah Keluar. -->
  <div class="shrink-0 px-3 pb-2">
    <?php
    $panduanActive = $_uri === '/panduan' || str_starts_with($_uri, '/panduan/');
    $ticketsActive = $_uri === '/bantuan' || str_starts_with($_uri, '/bantuan/');
    $helpActive = $panduanActive || $ticketsActive;
    ?>
    <style>
      .sb-help > summary { list-style:none; cursor:pointer; color:#6b7280; border-color:#e5e7eb; border-radius:6px; }
      .sb-help > summary::-webkit-details-marker { display:none; }
      .dark .sb-help > summary { color:#9ca3af; border-color:#374151; }
      .sb-help > summary:hover, .sb-help[open] > summary { color:var(--brand-color); border-color:var(--brand-color); background:color-mix(in srgb,var(--brand-color) 6%,transparent); }
      .sb-help > summary:focus-visible, .sb-help-link:focus-visible { outline:2px solid var(--brand-color); outline-offset:2px; }
      .sb-help-chevron { transition:transform .18s ease; }
      .sb-help[open] .sb-help-chevron { transform:rotate(180deg); }
      .sb-help-links { margin:8px 0 0 20px; padding-left:10px; border-left:1px solid #e5e7eb; max-height:30vh; overflow-y:auto; }
      .dark .sb-help-links { border-color:#374151; }
      .sb-help-link { display:flex; align-items:center; gap:8px; padding:9px 10px; font-size:13px; color:#6b7280; border-radius:4px; }
      .dark .sb-help-link { color:#9ca3af; }
      .sb-help-link:hover, .sb-help-link[aria-current="page"] { color:var(--brand-color); background:color-mix(in srgb,var(--brand-color) 8%,transparent); }
      .sb-help-link[aria-current="page"] { font-weight:600; }
      .sb-help-badge { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 4px; border-radius:4px; background:var(--brand-color); color:#fff; font-size:10px; font-weight:600; line-height:1; }
      @media (prefers-reduced-motion:reduce) { .sb-help-chevron { transition:none; } }
    </style>
    <details class="sb-help"<?= $helpActive ? ' open' : '' ?>>
      <summary class="flex items-center gap-2.5 px-3 py-2 border bg-transparent text-sm font-medium transition-colors">
        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M3 14v-3a9 9 0 0 1 18 0v6a4 4 0 0 1-4 4h-3"/>
          <rect x="3" y="10" width="4" height="8" rx="1.5"/><rect x="17" y="10" width="4" height="8" rx="1.5"/><path d="M10 21h4"/>
        </svg>
        <span class="flex-1">Pusat Bantuan</span>
        <?php if ($ticketsUnread > 0): ?>
        <span class="sb-help-badge" aria-label="<?= (int) $ticketsUnread ?> tiket dengan balasan belum dibaca"><?= $ticketsUnread > 9 ? '9+' : $ticketsUnread ?></span>
        <?php endif; ?>
        <svg class="sb-help-chevron w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
      </summary>
      <nav class="sb-help-links" aria-label="Pusat Bantuan">
        <a href="<?= e(APP_URL) ?>/panduan" class="sb-help-link"<?= $panduanActive ? ' aria-current="page"' : '' ?>>
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
          Panduan
        </a>
        <?php if ($showTickets): ?>
        <a href="<?= e(APP_URL) ?>/bantuan" class="sb-help-link"<?= $ticketsActive ? ' aria-current="page"' : '' ?>>
          <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/></svg>
          <span class="flex-1">Tiket Support</span>
          <?php if ($ticketsUnread > 0): ?>
          <span class="sb-help-badge" aria-label="<?= (int) $ticketsUnread ?> tiket dengan balasan belum dibaca"><?= $ticketsUnread > 9 ? '9+' : $ticketsUnread ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>
      </nav>
    </details>
  </div>

  <!-- Logout (di BAWAH garis pemisah) -->
  <div class="dmi-logout shrink-0 border-t border-gray-200 dark:border-gray-800 px-3 py-3">
    <button type="button"
       onclick="confirmAndRun({title:'Keluar dari Akun?',message:'Anda akan keluar dari akun ini.',confirmText:'Ya, Keluar',variant:'warning'}, () => { document.getElementById('logout-form').submit(); })"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600 transition-colors font-medium w-full text-left">
      <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Keluar
    </button>
  </div>

</aside>

<!-- Overlay mobile -->
<div id="sidebarOverlay" class="fixed inset-0 z-30 bg-black/40 hidden lg:hidden" onclick="document.getElementById('sidebar').classList.add('-translate-x-full'); this.classList.add('hidden');"></div>
