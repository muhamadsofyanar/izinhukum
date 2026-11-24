<?php
// Tentukan path aktif saat ini
$_uri      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
$_basePath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/');
if ($_basePath && str_starts_with($_uri, $_basePath)) {
    $_uri = substr($_uri, strlen($_basePath));
}
if (empty($_uri)) $_uri = '/';
if (!str_starts_with($_uri, '/')) $_uri = '/' . $_uri;

// Helper boolean cek path aktif
$isActive = function(string $path, bool $exact = false) use ($_uri): bool {
    return $exact
        ? ($_uri === $path || rtrim($_uri, '/') === rtrim($path, '/'))
        : str_starts_with($_uri, $path);
};

// CSS class untuk flat nav item — border-l-2 selalu ada (transparent saat inactive)
$navClass = function(string $path, bool $exact = false) use ($isActive): string {
    if ($isActive($path, $exact)) {
        return 'nav-item-active group flex items-center gap-3 border-l-2 border-[var(--brand-color)] px-3 py-2.5 rounded-lg text-sm font-medium transition-colors';
    }
    return 'group flex items-center gap-3 border-l-2 border-transparent px-3 py-2.5 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800/50 hover:text-gray-900 dark:hover:text-white transition-colors';
};

// Icon class untuk flat nav item
$iconClass = function(string $path, bool $exact = false) use ($isActive): string {
    return $isActive($path, $exact)
        ? 'w-5 h-5 shrink-0 text-[var(--brand-color)]'
        : 'w-5 h-5 shrink-0 text-gray-400 dark:text-gray-500 group-hover:text-gray-600 dark:group-hover:text-gray-300 transition-colors';
};

// CSS class untuk submenu item
$subNavClass = function(string $path) use ($isActive): string {
    if ($isActive($path)) {
        return 'nav-item-active group flex items-center gap-2.5 border-l-2 border-[var(--brand-color)] px-3 py-2 rounded-lg text-sm font-medium transition-colors';
    }
    return 'group flex items-center gap-2.5 border-l-2 border-transparent px-3 py-2 rounded-lg text-sm text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800/50 hover:text-gray-700 dark:hover:text-gray-200 transition-colors';
};

// Icon class untuk submenu item
$subIconClass = function(string $path) use ($isActive): string {
    return $isActive($path)
        ? 'w-[18px] h-[18px] shrink-0 text-[var(--brand-color)]'
        : 'w-[18px] h-[18px] shrink-0 text-gray-400 dark:text-gray-500 group-hover:text-gray-600 dark:group-hover:text-gray-300 transition-colors';
};

// Apakah group punya child aktif? (untuk auto-expand saat load)
$isContentActive     = $isActive('/admin/content') || $isActive('/admin/class-categories')
                    || $isActive('/admin/media')
                    || $isActive('/admin/downloads') || $isActive('/admin/certificates') || $isActive('/admin/reviews');
$isMarketingActive   = $isActive('/admin/subscriptions')
                    || $isActive('/admin/bundles')
                    || $isActive('/admin/affiliate-displays')
                    || $isActive('/admin/coupons')
                    || $isActive('/admin/affiliate-finance')
                    || $isActive('/admin/commissions')
                    || $isActive('/admin/payouts')
                    || $isActive('/admin/jv-partner')
                    || $isActive('/admin/commission-rules')
                    || $isActive('/admin/leaderboard')
                    || $isActive('/admin/product-bumps')
                    || $isActive('/admin/flash-bonus')
                    || $isActive('/admin/upsells')
                    || $isActive('/admin/broadcast')
                    || $isActive('/admin/info-member')
                    || $isActive('/admin/lead-magnets')
                    || $isActive('/admin/funnel-gate')
                    || $isActive('/admin/chat-leads')
                    || $isActive('/admin/social-proof')
                    || $isActive('/admin/support-page')
                    || $isActive('/admin/email-sequence')
                    || $isActive('/admin/abandoned-carts');
$isKonfigurasiActive = $isActive('/admin/notifications')
                    || $isActive('/admin/membership-levels')
                    || $isActive('/admin/branding')
                    || $isActive('/admin/settings')
                    || $isActive('/admin/integrations')
                    || $isActive('/admin/hermes-agent')
                    || $isActive('/admin/webhooks')
                    || $isActive('/admin/studio-integration')
                    || $isActive('/admin/ai-chat')
                    || $isActive('/admin/bank-accounts')
                    || $isActive('/admin/staff')
                    || $isActive('/admin/checkout-templates')
                    || $isActive('/admin/tracking-pixels')
                    || $isActive('/admin/api-keys-overview')
                    || $isActive('/admin/licenses')
                    || $isActive('/admin/update')
                    || $isActive('/admin/backups')
                    || $isActive('/admin/data-import')
                    || $isActive('/admin/system-health');

$appName  = getSetting('app_name', 'STIFIn Mulia');
$appLogo  = getSetting('app_logo', '');
$userName  = $_SESSION['name'] ?? 'Admin';
$userEmail = $_SESSION['email'] ?? '';
?>
<style>
  /* Menu premium "Integrasi Claude" — background brand transparan, border tipis, glow halus.
     Semua warna diturunkan dari var(--brand-color) agar konsisten lintas tema/branding. */
  .nav-claude{
    background: color-mix(in srgb, var(--brand-color) 7%, transparent);
    border: 1px solid color-mix(in srgb, var(--brand-color) 18%, transparent);
    border-radius: 6px;
    box-shadow: 0 1px 2px -1px color-mix(in srgb, var(--brand-color) 14%, transparent);
    color: var(--brand-color);
    transition: background .18s ease, border-color .18s ease, box-shadow .18s ease;
  }
  .nav-claude .nav-claude-label{ color: var(--brand-color); font-weight: 600; }
  /* Badge "Ai" — kotak transparan, border tipis brand (mengikuti var(--brand-color)) */
  .nav-claude-badge{
    display: inline-flex; align-items: center; line-height: 1;
    padding: 2px 6px;
    font-size: 10px; font-weight: 600; letter-spacing: .01em;
    color: var(--brand-color);
    background: transparent;
    border: 1px solid color-mix(in srgb, var(--brand-color) 32%, transparent);
    border-radius: 5px;
  }
  .nav-claude:hover{
    background: color-mix(in srgb, var(--brand-color) 11%, transparent);
    border-color: color-mix(in srgb, var(--brand-color) 30%, transparent);
    box-shadow: 0 1px 3px -1px color-mix(in srgb, var(--brand-color) 16%, transparent);
  }
  .nav-claude.nav-claude-active{
    background: color-mix(in srgb, var(--brand-color) 12%, transparent);
    border-color: color-mix(in srgb, var(--brand-color) 38%, transparent);
    box-shadow: none;
  }
</style>
<!-- Sidebar Admin -->
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
  <nav id="sidebarNav" class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">

    <!-- Dashboard -->
    <a href="<?= e(APP_URL) ?>/admin" class="<?= $navClass('/admin', true) ?>">
      <svg class="<?= $iconClass('/admin', true) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="7" height="7" rx="1.5"/>
        <rect x="14" y="3" width="7" height="7" rx="1.5"/>
        <rect x="3" y="14" width="7" height="7" rx="1.5"/>
        <rect x="14" y="14" width="7" height="7" rx="1.5"/>
      </svg>
      Dasbor
    </a>

    <?php if (staffCan('content')): ?>
          <a href="<?= e(APP_URL) ?>/admin/kajian" class="<?= $navClass('/admin/kajian') ?>">
            <svg class="<?= $iconClass('/admin/kajian') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            Kelola Kajian
          </a>
    <?php endif; ?>

    <!-- Users -->
    <a href="<?= e(APP_URL) ?>/admin/users" class="<?= $navClass('/admin/users') ?>">
      <svg class="<?= $iconClass('/admin/users') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      Pengguna
    </a>

    <!-- Products -->
    <a href="<?= e(APP_URL) ?>/admin/products" class="<?= $navClass('/admin/products') ?>">
      <svg class="<?= $iconClass('/admin/products') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
        <line x1="12" y1="22.08" x2="12" y2="12"/>
      </svg>
      Produk
    </a>

    <!-- Canvas -->
    <a href="<?= e(APP_URL) ?>/admin/canvas" class="<?= $navClass('/admin/canvas') ?>">
      <svg class="<?= $iconClass('/admin/canvas') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="18" height="18" rx="2"/>
        <line x1="9" y1="3" x2="9" y2="21"/>
      </svg>
      Canvas
    </a>

    <?php if (isAdmin()): ?>
    <a href="<?= e(APP_URL) ?>/admin/kantor-cabang" class="<?= $navClass('/admin/kantor-cabang') ?>">
      <svg aria-hidden="true" class="<?= $iconClass('/admin/kantor-cabang') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V5l11-2v18M15 9h5v12M2 21h20M8 8h3M8 12h3M8 16h3"/></svg>
      <span>Kantor Virtual Cabang</span>
    </a>
    <a href="<?= e(APP_URL) ?>/admin/peserta-tes" class="<?= $navClass('/admin/peserta-tes') ?>">
      <svg aria-hidden="true" class="<?= $iconClass('/admin/peserta-tes') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18v14H3zM7 3v6m10-6v6M3 11h18m-13 5 2 2 5-5"/></svg>
      <span>Peserta &amp; Jadwal Tes</span>
    </a>
    <a href="<?= e(APP_URL) ?>/admin/data-resmi-cabang" class="<?= $navClass('/admin/data-resmi-cabang') ?>">
      <svg aria-hidden="true" class="<?= $iconClass('/admin/data-resmi-cabang') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h9l5 5v13H5zM14 3v6h5M8 13h8m-8 4h6"/></svg>
        <span>Data Resmi App Cabang</span>
    </a>
    <a href="<?= e(APP_URL) ?>/admin/laporan-cabang" class="<?= $navClass('/admin/laporan-cabang') ?>">
      <svg aria-hidden="true" class="<?= $iconClass('/admin/laporan-cabang') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18M7 17v-5m5 5V6m5 11v-8"/></svg>
      <span>Laporan Cabang</span>
    </a>
    <a href="<?= e(APP_URL) ?>/admin/promotor-cabang" class="<?= $navClass('/admin/promotor-cabang') ?>">
      <svg aria-hidden="true" class="<?= $iconClass('/admin/promotor-cabang') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a5 5 0 0 0-10 0v2M15 8a4 4 0 1 1-8 0 4 4 0 0 1 8 0m2 4 2 2 3-4"/></svg>
      <span>Promotor Cabang</span>
    </a>

    <!-- Transactions -->
    <?php if (stifinVoucherProductId() > 0): ?>
    <a href="<?= e(APP_URL) ?>/admin/voucher-tes" class="<?= $navClass('/admin/voucher-tes') ?>">
      <svg aria-hidden="true" class="<?= $iconClass('/admin/voucher-tes') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4zM15 8v2m0 4v2"/></svg>
      <span>Stok Voucher Tes</span>
    </a>
    <?php endif; ?>
    <?php endif; ?>
    <a href="<?= e(APP_URL) ?>/admin/transactions" class="<?= $navClass('/admin/transactions') ?>">
      <svg class="<?= $iconClass('/admin/transactions') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="1" y="4" width="22" height="16" rx="2"/>
        <line x1="1" y1="10" x2="23" y2="10"/>
      </svg>
      Transaksi
    </a>

    <!-- Tiket Support -->
    <a href="<?= e(APP_URL) ?>/admin/tickets" class="<?= $navClass('/admin/tickets') ?>">
      <svg class="<?= $iconClass('/admin/tickets') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <span class="flex-1">Tiket Bantuan</span>
      <?php
      $ticketOpen = 0;
      try {
          $ticketOpen = (int) getDB()->query(
              "SELECT COUNT(*) FROM support_tickets WHERE status = 'open'"
          )->fetchColumn();
      } catch (Throwable $e) {}
      ?>
      <?php if ($ticketOpen > 0): ?>
      <span class="inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full text-[9px] font-bold text-white normal-case tracking-normal" style="background:var(--brand-color)"><?= $ticketOpen > 9 ? '9+' : $ticketOpen ?></span>
      <?php endif; ?>
    </a>

    <!-- Funnel Analytics -->
    <a href="<?= e(APP_URL) ?>/admin/funnel-analytics" class="<?= $navClass('/admin/funnel-analytics') ?>">
      <svg class="<?= $iconClass('/admin/funnel-analytics') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V20l-4-2v-5.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
      </svg>
      Analitik Penjualan
    </a>

    <!-- Integrasi Claude — fitur premium, standalone (brand-tinted + glow tipis) -->
    <a href="<?= e(APP_URL) ?>/admin/mcp-integration"
       class="nav-claude<?= $isActive('/admin/mcp-integration') ? ' nav-claude-active' : '' ?> group flex items-center gap-3 px-3 py-2.5 mt-0.5 rounded-lg text-sm">
      <img src="<?= e(APP_URL) ?>/assets/img/claude-symbol.webp" alt="Claude" class="w-5 h-5 shrink-0 object-contain">
      <span class="inline-flex items-center gap-2">
        <span class="nav-claude-label">Claude</span>
        <span class="nav-claude-badge">Ai</span>
      </span>
    </a>

    <!-- Content group -->
    <div>
      <button type="button"
              class="sidebar-group-toggle w-full flex items-center justify-between px-3 pt-5 pb-1 text-[10px] font-semibold text-gray-500 dark:text-gray-500 uppercase tracking-widest hover:text-gray-700 dark:hover:text-gray-300 transition-colors rounded-lg"
              data-group="content">
        <span>Konten</span>
        <svg class="w-3.5 h-3.5 transition-transform duration-200 shrink-0" id="chevron-content"
             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>
      <div class="sidebar-submenu overflow-hidden"
           id="submenu-content"
           style="max-height:0; opacity:0; transition:max-height 0.25s ease-out, opacity 0.2s ease;"
           <?= $isContentActive ? 'data-has-active="true"' : '' ?>>
        <div class="ml-2 space-y-0.5 pb-1 pt-0.5">
          <a href="<?= e(APP_URL) ?>/admin/content" class="<?= $subNavClass('/admin/content') ?>">
            <svg class="<?= $subIconClass('/admin/content') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
              <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
            </svg>
            Modul &amp; Materi
          </a>
          <a href="<?= e(APP_URL) ?>/admin/class-categories" class="<?= $subNavClass('/admin/class-categories') ?>">
            <svg class="<?= $subIconClass('/admin/class-categories') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
            Kategori Modul
          </a>
          <a href="<?= e(APP_URL) ?>/admin/media" class="<?= $subNavClass('/admin/media') ?>">
            <svg class="<?= $subIconClass('/admin/media') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <circle cx="8.5" cy="8.5" r="1.5"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 15l-5-5L5 21"/>
            </svg>
            Galeri Media
          </a>
          <?php if (staffCan('downloads')): ?>
          <a href="<?= e(APP_URL) ?>/admin/downloads" class="<?= $subNavClass('/admin/downloads') ?>">
            <svg class="<?= $subIconClass('/admin/downloads') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
            Kelola Unduhan
          </a>
          <?php endif; ?>
          <?php if (staffCan('certificates')): ?>
          <a href="<?= e(APP_URL) ?>/admin/certificates" class="<?= $subNavClass('/admin/certificates') ?>">
            <svg class="<?= $subIconClass('/admin/certificates') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <circle cx="12" cy="8" r="6"/><path stroke-linecap="round" stroke-linejoin="round" d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
            </svg>
            Sertifikat
          </a>
          <?php endif; ?>
          <?php if (staffCan('products')): ?>
          <a href="<?= e(APP_URL) ?>/admin/reviews" class="<?= $subNavClass('/admin/reviews') ?>">
            <svg class="<?= $subIconClass('/admin/reviews') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
            </svg>
            Ulasan
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ═══ Group: Marketing & Growth ═══ -->
    <?php
    // Badge perhatian grup: jumlah permintaan pencairan (payout) afiliasi berstatus
    // 'pending' yang menunggu diproses admin. Ditampilkan di header grup DAN di item
    // "Komisi & Payout" (halaman tujuannya) + tooltip, supaya admin tahu artinya apa
    // dan harus ke mana. Gagal query = diam (badge tak tampil).
    $mgAttention = 0;
    try {
        $mgAttention = (int) getDB()->query("SELECT COUNT(*) FROM payout_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {}
    $mgAttentionTitle = $mgAttention > 0
        ? ($mgAttention . ' permintaan pencairan (payout) afiliasi menunggu diproses — buka Komisi & Payout')
        : '';
    ?>
    <div>
      <button type="button"
              class="sidebar-group-toggle w-full flex items-center justify-between px-3 pt-5 pb-1 text-[10px] font-semibold text-gray-500 dark:text-gray-500 uppercase tracking-widest hover:text-gray-700 dark:hover:text-gray-300 transition-colors rounded-lg"
              data-group="marketing"<?= $mgAttention > 0 ? ' title="' . e($mgAttentionTitle) . '"' : '' ?>>
        <span class="flex items-center gap-1.5">Marketing &amp; Growth
          <?php if ($mgAttention > 0): ?>
          <span title="<?= e($mgAttentionTitle) ?>" aria-label="<?= e($mgAttentionTitle) ?>" class="inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full text-[9px] font-bold text-white normal-case tracking-normal" style="background:var(--brand-color)"><?= $mgAttention > 9 ? '9+' : $mgAttention ?></span>
          <?php endif; ?>
        </span>
        <svg class="w-3.5 h-3.5 transition-transform duration-200 shrink-0" id="chevron-marketing"
             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>

      <div class="sidebar-submenu overflow-hidden"
           id="submenu-marketing"
           style="max-height:0; opacity:0; transition:max-height 0.25s ease-out, opacity 0.2s ease;"
           <?= $isMarketingActive ? 'data-has-active="true"' : '' ?>>
        <div class="ml-2 space-y-0.5 pb-1 pt-0.5">

          <!-- Langganan -->
          <a href="<?= e(APP_URL) ?>/admin/subscriptions" class="<?= $subNavClass('/admin/subscriptions') ?>">
            <svg class="<?= $subIconClass('/admin/subscriptions') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/><path d="M21 22v-6h-6"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/>
            </svg>
            Langganan
          </a>

          <?php if (staffCan('products')): ?>
          <!-- Bundles -->
          <a href="<?= e(APP_URL) ?>/admin/bundles" class="<?= $subNavClass('/admin/bundles') ?>">
            <svg class="<?= $subIconClass('/admin/bundles') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
            </svg>
            Paket Produk
          </a>
          <?php endif; ?>

          <?php if (staffCan('products')): ?>
          <!-- Display Affiliate -->
          <a href="<?= e(APP_URL) ?>/admin/affiliate-displays" class="<?= $subNavClass('/admin/affiliate-displays') ?>">
            <svg class="<?= $subIconClass('/admin/affiliate-displays') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            </svg>
            Tampilan Afiliasi
          </a>
          <?php endif; ?>

          <!-- Coupons -->
          <a href="<?= e(APP_URL) ?>/admin/coupons" class="<?= $subNavClass('/admin/coupons') ?>">
            <svg class="<?= $subIconClass('/admin/coupons') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
              <line x1="7" y1="7" x2="7.01" y2="7"/>
            </svg>
            Kupon
          </a>

          <!-- Komisi & Payout (gabungan) -->
          <a href="<?= e(APP_URL) ?>/admin/affiliate-finance" class="<?= $subNavClass('/admin/affiliate-finance') ?>"<?= $mgAttention > 0 ? ' title="' . e($mgAttentionTitle) . '"' : '' ?>>
            <svg class="<?= $subIconClass('/admin/affiliate-finance') ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 12V7H5a2 2 0 010-4h14v4M3 5v14a2 2 0 002 2h16v-5M18 12a2 2 0 000 4h4v-4h-4z"/>
            </svg>
            Komisi &amp; Payout
            <?php if ($mgAttention > 0): ?>
            <span aria-label="<?= e($mgAttentionTitle) ?>" class="ml-auto inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full text-[9px] font-bold text-white" style="background:var(--brand-color)"><?= $mgAttention > 9 ? '9+' : $mgAttention ?></span>
            <?php endif; ?>
          </a>

          <!-- Link Afiliasi Saya (halaman afiliasi admin sendiri) -->
          <a href="<?= e(APP_URL) ?>/affiliate" class="<?= $subNavClass('/affiliate') ?>">
            <svg class="<?= $subIconClass('/affiliate') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 0 0-5.656 0l-4 4a4 4 0 1 0 5.656 5.656l1.102-1.101"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.172 13.828a4 4 0 0 0 5.656 0l4-4a4 4 0 0 0-5.656-5.656l-1.1 1.1"/>
            </svg>
            Link Afiliasi Saya
          </a>

          <!-- JV Partner -->
          <a href="<?= e(APP_URL) ?>/admin/jv-partner" class="<?= $subNavClass('/admin/jv-partner') ?>">
            <svg class="<?= $subIconClass('/admin/jv-partner') ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
            </svg>
            JV Partner
          </a>

          <!-- Commission Rules (sliders icon) -->
          <a href="<?= e(APP_URL) ?>/admin/commission-rules" class="<?= $subNavClass('/admin/commission-rules') ?>">
            <svg class="<?= $subIconClass('/admin/commission-rules') ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 21V14M4 10V3M12 21V12M12 8V3M20 21V16M20 12V3M2 14h4M10 8h4M18 16h4"/>
            </svg>
            Aturan Komisi
          </a>

          <!-- Leaderboard -->
          <a href="<?= e(APP_URL) ?>/admin/leaderboard" class="<?= $subNavClass('/admin/leaderboard') ?>">
            <svg class="<?= $subIconClass('/admin/leaderboard') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <line x1="18" y1="20" x2="18" y2="10"/>
              <line x1="12" y1="20" x2="12" y2="4"/>
              <line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
            Peringkat Afiliasi
          </a>

          <!-- Product Bumps -->
          <a href="<?= e(APP_URL) ?>/admin/product-bumps" class="<?= $subNavClass('/admin/product-bumps') ?>">
            <svg class="<?= $subIconClass('/admin/product-bumps') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 2L2 7l10 5 10-5-10-5z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2 17l10 5 10-5"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2 12l10 5 10-5"/>
            </svg>
            Product Bumps
          </a>

          <!-- Bonus Kilat -->
          <a href="<?= e(APP_URL) ?>/admin/flash-bonus" class="<?= $subNavClass('/admin/flash-bonus') ?>">
            <svg class="<?= $subIconClass('/admin/flash-bonus') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 12v10H4V12"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2 7h20v5H2z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 22V7"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
            </svg>
            Bonus Kilat
          </a>

          <!-- Upsell -->
          <a href="<?= e(APP_URL) ?>/admin/upsells" class="<?= $subNavClass('/admin/upsells') ?>">
            <svg class="<?= $subIconClass('/admin/upsells') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <polygon stroke-linecap="round" stroke-linejoin="round" points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
            Upsell
          </a>

          <!-- Broadcast -->
          <a href="<?= e(APP_URL) ?>/admin/broadcast" class="<?= $subNavClass('/admin/broadcast') ?>">
            <svg class="<?= $subIconClass('/admin/broadcast') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <line x1="22" y1="2" x2="11" y2="13"/>
              <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
            Siaran
          </a>

          <!-- Info Member -->
          <a href="<?= e(APP_URL) ?>/admin/info-member" class="<?= $subNavClass('/admin/info-member') ?>">
            <svg class="<?= $subIconClass('/admin/info-member') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/>
            </svg>
            Info Member
          </a>

          <?php if (staffCan('email_sequences')): ?>
          <a href="<?= e(APP_URL) ?>/admin/email-sequences" class="<?= $subNavClass('/admin/email-sequence') ?>">
            <svg class="<?= $subIconClass('/admin/email-sequence') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6"/>
            </svg>
            Rangkaian Email
          </a>
          <?php endif; ?>
          <?php if (staffCan('abandoned_carts')): ?>
          <a href="<?= e(APP_URL) ?>/admin/abandoned-carts" class="<?= $subNavClass('/admin/abandoned-carts') ?>">
            <svg class="<?= $subIconClass('/admin/abandoned-carts') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path stroke-linecap="round" stroke-linejoin="round" d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            Pesanan Terbengkalai
          </a>
          <?php endif; ?>

          <!-- Lead Magnet -->
          <a href="<?= e(APP_URL) ?>/admin/lead-magnets" class="<?= $subNavClass('/admin/lead-magnets') ?>">
            <svg class="<?= $subIconClass('/admin/lead-magnets') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8"/>
              <rect x="3" y="5" width="18" height="14" rx="2"/>
            </svg>
            Lead Magnet
          </a>

          <!-- Funnel Gate -->
          <a href="<?= e(APP_URL) ?>/admin/funnel-gate" class="<?= $subNavClass('/admin/funnel-gate') ?>">
            <svg class="<?= $subIconClass('/admin/funnel-gate') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V20l-4-2v-5.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
            </svg>
            Funnel Gate
          </a>

          <!-- Leads AI Chat -->
          <a href="<?= e(APP_URL) ?>/admin/chat-leads" class="<?= $subNavClass('/admin/chat-leads') ?>">
            <svg class="<?= $subIconClass('/admin/chat-leads') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
            </svg>
            Leads AI Chat
          </a>

          <!-- Waitlist -->
          <a href="<?= e(APP_URL) ?>/admin/waitlist" class="<?= $subNavClass('/admin/waitlist') ?>">
            <svg class="<?= $subIconClass('/admin/waitlist') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            Daftar Tunggu
          </a>

          <!-- Social Proof -->
          <a href="<?= e(APP_URL) ?>/admin/social-proof" class="<?= $subNavClass('/admin/social-proof') ?>">
            <svg class="<?= $subIconClass('/admin/social-proof') ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            Social Proof
          </a>

          <!-- Halaman Dukung -->
          <a href="<?= e(APP_URL) ?>/admin/support-page" class="<?= $subNavClass('/admin/support-page') ?>">
            <svg class="<?= $subIconClass('/admin/support-page') ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
            Halaman Dukung
          </a>

        </div>
      </div>
    </div>

    <!-- ═══ Group: Konfigurasi ═══ -->
    <div>
      <button type="button"
              class="sidebar-group-toggle w-full flex items-center justify-between px-3 pt-5 pb-1 text-[10px] font-semibold text-gray-500 dark:text-gray-500 uppercase tracking-widest hover:text-gray-700 dark:hover:text-gray-300 transition-colors rounded-lg"
              data-group="konfigurasi">
        <span>Konfigurasi</span>
        <svg class="w-3.5 h-3.5 transition-transform duration-200 shrink-0" id="chevron-konfigurasi"
             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
      </button>

      <div class="sidebar-submenu overflow-hidden"
           id="submenu-konfigurasi"
           style="max-height:0; opacity:0; transition:max-height 0.25s ease-out, opacity 0.2s ease;"
           <?= $isKonfigurasiActive ? 'data-has-active="true"' : '' ?>>
        <div class="ml-2 space-y-0.5 pb-1 pt-0.5">

          <!-- Notifications -->
          <a href="<?= e(APP_URL) ?>/admin/notifications" class="<?= $subNavClass('/admin/notifications') ?>">
            <svg class="<?= $subIconClass('/admin/notifications') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            Notifikasi
          </a>

          <!-- Membership Levels -->
          <a href="<?= e(APP_URL) ?>/admin/membership-levels" class="<?= $subNavClass('/admin/membership-levels') ?>">
            <svg class="<?= $subIconClass('/admin/membership-levels') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <circle cx="12" cy="8" r="6"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
            </svg>
            Tingkat Keanggotaan
          </a>

          <!-- Branding -->
          <a href="<?= e(APP_URL) ?>/admin/branding" class="<?= $subNavClass('/admin/branding') ?>">
            <svg class="<?= $subIconClass('/admin/branding') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/>
              <circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/>
              <circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/>
              <circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>
            </svg>
            Identitas Merek
          </a>

          <!-- Settings -->
          <a href="<?= e(APP_URL) ?>/admin/settings" class="<?= $subNavClass('/admin/settings') ?>">
            <svg class="<?= $subIconClass('/admin/settings') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <circle cx="12" cy="12" r="3"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            Pengaturan
          </a>

          <?php if (isAdmin()): ?>
          <!-- Staff Management (admin only) -->
          <a href="<?= e(APP_URL) ?>/admin/staff" class="<?= $subNavClass('/admin/staff') ?>">
            <svg class="<?= $subIconClass('/admin/staff') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Staf
          </a>
          <?php endif; ?>

          <!-- Integrations -->
          <a href="<?= e(APP_URL) ?>/admin/integrations" class="<?= $subNavClass('/admin/integrations') ?>">
            <svg class="<?= $subIconClass('/admin/integrations') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
            </svg>
            Integrasi
          </a>

          <!-- Hermes Agent (Ingest API) -->
          <a href="<?= e(APP_URL) ?>/admin/hermes-agent" class="<?= $subNavClass('/admin/hermes-agent') ?>">
            <svg class="<?= $subIconClass('/admin/hermes-agent') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <rect x="4" y="8" width="16" height="12" rx="2"/><path d="M12 8V4M9 4h6"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>
            </svg>
            Hermes Agent
          </a>

          <!-- Integrasi Webhook (outgoing) -->
          <a href="<?= e(APP_URL) ?>/admin/webhooks" class="<?= $subNavClass('/admin/webhooks') ?>">
            <svg class="<?= $subIconClass('/admin/webhooks') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/>
            </svg>
            Integrasi Webhook
          </a>

          <!-- Integrasi Averion Studio -->
          <a href="<?= e(APP_URL) ?>/admin/studio-integration" class="<?= $subNavClass('/admin/studio-integration') ?>">
            <svg class="<?= $subIconClass('/admin/studio-integration') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
              <path stroke-linecap="round" d="M20 3v4M22 5h-4"/>
            </svg>
            Integrasi Studio
          </a>

          <?php if (function_exists('vfAvailable') && vfAvailable()): ?>
          <!-- Antrean Lisensi Vendor — hanya instalasi vendor (konstanta config.php) -->
          <a href="<?= e(APP_URL) ?>/admin/license-fulfillments" class="<?= $subNavClass('/admin/license-fulfillments') ?>">
            <svg class="<?= $subIconClass('/admin/license-fulfillments') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
            </svg>
            Antrean Lisensi
            <?php
            // Badge merah HANYA saat ada yang gagal — kegagalan tak boleh cuma
            // tersimpan di DB & menunggu admin kebetulan membuka halamannya.
            $vfFailBadge = 0;
            try {
                $vfFailBadge = (int) getDB()->query("SELECT COUNT(*) FROM license_fulfillments WHERE status='failed'")->fetchColumn();
            } catch (Exception $e) { $vfFailBadge = 0; }
            ?>
            <?php if ($vfFailBadge > 0): ?>
            <span class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-red-500 text-white"><?= $vfFailBadge ?></span>
            <?php endif; ?>
          </a>
          <?php endif; ?>

          <!-- AI Chat Widget -->
          <a href="<?= e(APP_URL) ?>/admin/ai-chat" class="<?= $subNavClass('/admin/ai-chat') ?>">
            <svg class="<?= $subIconClass('/admin/ai-chat') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 9h8M8 13h5"/>
            </svg>
            AI Chat
          </a>

          <!-- Rekening Bank (Manual Transfer) -->
          <a href="<?= e(APP_URL) ?>/admin/bank-accounts" class="<?= $subNavClass('/admin/bank-accounts') ?>">
            <svg class="<?= $subIconClass('/admin/bank-accounts') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <line x1="3" y1="21" x2="21" y2="21"/><line x1="3" y1="10" x2="21" y2="10"/>
              <polyline points="5 6 12 3 19 6"/><line x1="4" y1="10" x2="4" y2="21"/>
              <line x1="20" y1="10" x2="20" y2="21"/><line x1="8" y1="14" x2="8" y2="17"/>
              <line x1="12" y1="14" x2="12" y2="17"/><line x1="16" y1="14" x2="16" y2="17"/>
            </svg>
            Rekening Bank
          </a>

          <!-- Checkout Templates -->
          <a href="<?= e(APP_URL) ?>/admin/checkout-templates" class="<?= $subNavClass('/admin/checkout-templates') ?>">
            <svg class="<?= $subIconClass('/admin/checkout-templates') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <rect x="2" y="3" width="20" height="18" rx="2"/>
              <path stroke-linecap="round" d="M8 3v18M16 9h2M16 13h2M16 17h2"/>
            </svg>
            Templat Checkout
          </a>

          <!-- Tracking Pixels -->
          <a href="<?= e(APP_URL) ?>/admin/tracking-pixels" class="<?= $subNavClass('/admin/tracking-pixels') ?>">
            <svg class="<?= $subIconClass('/admin/tracking-pixels') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <circle cx="12" cy="12" r="3"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 12h3M18 12h3M12 3v3M12 18v3"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M5.636 5.636l2.122 2.122M16.243 16.243l2.121 2.121M5.636 18.364l2.122-2.121M16.243 7.757l2.121-2.121"/>
            </svg>
            Piksel Pelacakan
          </a>

          <!-- API Keys Overview -->
          <a href="<?= e(APP_URL) ?>/admin/api-keys-overview" class="<?= $subNavClass('/admin/api-keys-overview') ?>">
            <svg class="<?= $subIconClass('/admin/api-keys-overview') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z"/>
            </svg>
            Daftar Kunci API
          </a>

          <!-- Lisensi Produk (license key untuk produk digital yang dijual) -->
          <a href="<?= e(APP_URL) ?>/admin/licenses" class="<?= $subNavClass('/admin/licenses') ?>">
            <svg class="<?= $subIconClass('/admin/licenses') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
            </svg>
            Lisensi Produk
          </a>

          <!-- Update Sistem -->
          <?php
          // Badge dari cek manual hari ini (session) ATAU hasil cek harian
          // Auto-Cron (setting, persist antar hari/login).
          $updateAvailable = !empty($_SESSION['update_check_' . date('Ymd')]['has_update'])
              || (function_exists('updateAvailableVersion') && updateAvailableVersion() !== '');
          ?>
          <a href="<?= e(APP_URL) ?>/admin/update" class="<?= $subNavClass('/admin/update') ?>">
            <svg class="<?= $subIconClass('/admin/update') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-3-6.7L21 8"/>
              <polyline stroke-linecap="round" stroke-linejoin="round" points="21 3 21 8 16 8"/>
            </svg>
            Update Sistem
            <?php if ($updateAvailable): ?>
            <span class="ml-auto inline-flex items-center justify-center w-5 h-5 rounded-full text-[11px] font-bold bg-red-500 text-white">!</span>
            <?php endif; ?>
          </a>

          <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <!-- Backup Database (khusus admin) -->
          <a href="<?= e(APP_URL) ?>/admin/backups" class="<?= $subNavClass('/admin/backups') ?>">
            <svg class="<?= $subIconClass('/admin/backups') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <ellipse cx="12" cy="5" rx="9" ry="3"/>
              <path stroke-linecap="round" d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
              <path stroke-linecap="round" d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
            </svg>
            Cadangan Database
          </a>

          <!-- Import / Migrasi Data (khusus admin) -->
          <a href="<?= e(APP_URL) ?>/admin/data-import" class="<?= $subNavClass('/admin/data-import') ?>">
            <svg class="<?= $subIconClass('/admin/data-import') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline stroke-linecap="round" stroke-linejoin="round" points="7 10 12 15 17 10"/>
              <line stroke-linecap="round" x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Import Data
          </a>
          <?php endif; ?>

          <?php if (staffCan('notifications')): ?>
          <!-- Kesehatan Sistem -->
          <?php
          $healthAlert = false;
          try {
              $hs = systemHealthSnapshot();
              $healthAlert = ($hs['cron']['level'] === 'danger') || ($hs['notif']['failed_24h'] > 0);
          } catch (Throwable $e) {}
          ?>
          <a href="<?= e(APP_URL) ?>/admin/system-health" class="<?= $subNavClass('/admin/system-health') ?>">
            <svg class="<?= $subIconClass('/admin/system-health') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            Kesehatan Sistem
            <?php if ($healthAlert): ?>
            <span class="ml-auto inline-flex items-center justify-center w-5 h-5 rounded-full text-[11px] font-bold bg-red-500 text-white">!</span>
            <?php endif; ?>
          </a>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <!-- Pemisah grup pakai whitespace, bukan garis -->
    <div class="pt-4"></div>

    <!-- Dokumentasi (mandiri, di luar grup) -->
    <a href="<?= e(APP_URL) ?>/admin/docs" class="<?= $navClass('/admin/docs') ?>">
      <svg class="<?= $iconClass('/admin/docs') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
      </svg>
      Dokumentasi
    </a>

    <!-- Activity Logs -->
    <a href="<?= e(APP_URL) ?>/admin/activity-logs" class="<?= $navClass('/admin/activity-logs') ?>">
      <svg class="<?= $iconClass('/admin/activity-logs') ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
      </svg>
      Log Aktivitas
    </a>

    <!-- License (lisensi Averion itu sendiri) -->
    <a href="<?= e(APP_URL) ?>/admin/license" class="<?= $navClass('/admin/license', true) ?>">
      <svg class="<?= $iconClass('/admin/license', true) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        <polyline points="9 12 11 14 15 10"/>
      </svg>
      Lisensi
    </a>

  </nav>

  <!-- Logout -->
  <div class="dmi-logout shrink-0 border-t border-gray-200 dark:border-gray-800 px-3 py-3">
    <button type="button"
       onclick="confirmAndRun({title:'Keluar dari Akun?',message:'Anda akan keluar dari akun ini.',confirmText:'Ya, Keluar',variant:'warning'}, () => { document.getElementById('logout-form').submit(); })"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-500 dark:text-gray-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600 dark:hover:text-red-400 transition-colors font-medium w-full text-left">
      <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Keluar
    </button>
  </div>

</aside>

<!-- Overlay untuk mobile -->
<div id="sidebarOverlay" class="fixed inset-0 z-30 bg-black/40 hidden lg:hidden" onclick="document.getElementById('sidebar').classList.add('-translate-x-full'); this.classList.add('hidden');"></div>

<script>
// Accordion sidebar (single-open) — inline, jalan synchronous setelah HTML ada di DOM.
// Saat load: grup yang sedang DIPAKAI (punya menu aktif) tetap terbuka; grup lain
// tertutup & hanya terbuka bila di-klik. Membuka satu grup otomatis menutup grup lain.
(function () {
  var KEYS   = ['content', 'marketing', 'konfigurasi'];
  var groups = {};

  KEYS.forEach(function (key) {
    var btn     = document.querySelector('[data-group="' + key + '"]');
    var submenu = document.getElementById('submenu-' + key);
    var chevron = document.getElementById('chevron-' + key);
    if (btn && submenu && chevron) {
      groups[key] = { btn: btn, submenu: submenu, chevron: chevron };
    }
  });

  function setState(key, open) {
    var g = groups[key];
    if (!g) return;
    if (open) {
      // Tinggi konten asli (+buffer) agar submenu panjang tidak terpotong.
      g.submenu.style.maxHeight = (g.submenu.scrollHeight + 16) + 'px';
      g.submenu.style.opacity   = '1';
      g.chevron.style.transform = 'rotate(180deg)';
    } else {
      g.submenu.style.maxHeight = '0px';
      g.submenu.style.opacity   = '0';
      g.chevron.style.transform = 'rotate(0deg)';
    }
  }

  // Buka `key` saja; tutup semua yang lain. key === null → semua tertutup.
  function openOnly(key) {
    Object.keys(groups).forEach(function (k) { setState(k, k === key); });
  }

  function isOpen(key) {
    var mh = groups[key].submenu.style.maxHeight;
    return mh !== '' && mh !== '0px';
  }

  // Saat load: buka HANYA grup yang sedang dipakai (punya menu aktif = halaman
  // sekarang); grup lain tertutup. Tidak mengingat state open/close lama.
  var active = null;
  Object.keys(groups).forEach(function (k) {
    if (groups[k].submenu.getAttribute('data-has-active') === 'true') active = k;
  });

  var nav        = document.getElementById('sidebarNav');
  var SCROLL_KEY = 'sidebar-scroll';

  // Buka grup aktif TANPA animasi saat load → tinggi konten langsung final,
  // supaya restore posisi scroll akurat (tidak terpotong animasi max-height).
  Object.keys(groups).forEach(function (k) { groups[k].submenu.style.transition = 'none'; });
  openOnly(active);

  if (nav) {
    void nav.offsetHeight; // paksa reflow (tinggi sudah final)

    // Restore posisi scroll sidebar → menu yang tadi dipakai tetap terlihat,
    // sidebar tidak loncat ke atas saat reload/pindah halaman.
    var savedScroll = null;
    try { savedScroll = sessionStorage.getItem(SCROLL_KEY); } catch (e) {}
    if (savedScroll !== null) nav.scrollTop = parseInt(savedScroll, 10) || 0;

    // Simpan posisi scroll (throttle via rAF) setiap sidebar di-scroll.
    var ticking = false;
    nav.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        try { sessionStorage.setItem(SCROLL_KEY, String(nav.scrollTop)); } catch (e) {}
        ticking = false;
      });
    });
  }

  // Aktifkan lagi animasi untuk interaksi klik berikutnya.
  requestAnimationFrame(function () {
    Object.keys(groups).forEach(function (k) {
      groups[k].submenu.style.transition = 'max-height 0.25s ease-out, opacity 0.2s ease';
    });
  });

  // Klik: grup terbuka → tutup; grup tertutup → buka & tutup grup lain (accordion).
  Object.keys(groups).forEach(function (key) {
    groups[key].btn.addEventListener('click', function () {
      openOnly(isOpen(key) ? null : key);
    });
  });
})();
</script>

<?php
// ─── Toast update tersedia (compact, khusus admin) ─────────────
// Sumber: cek harian Auto-Cron (updateAvailableVersion). Dismiss per-versi
// via localStorage — versi lebih baru memunculkannya lagi. Tidak tampil di
// halaman Update Sistem sendiri.
$updToastVer = (($_SESSION['role'] ?? '') === 'admin' && function_exists('updateAvailableVersion'))
    ? updateAvailableVersion() : '';
if ($updToastVer !== '' && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/update') === false):
?>
<div id="upd-toast" style="position:fixed;bottom:1.25rem;right:1.25rem;z-index:60;display:none;max-width:20rem;transform:translateY(8px);opacity:0;transition:all .35s ease"
     class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-lg p-4">
  <div class="flex items-start gap-3">
    <span class="shrink-0 w-9 h-9 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent)">
      <svg class="w-5 h-5" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><polyline points="21 3 21 8 16 8"/></svg>
    </span>
    <div class="min-w-0 flex-1">
      <p class="text-sm font-semibold text-gray-900 dark:text-white">Update v<?= e($updToastVer) ?> tersedia</p>
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Versi terbaru siap dipasang lewat Update Sistem.</p>
      <div class="flex items-center gap-2 mt-2.5">
        <a href="<?= e(APP_URL) ?>/admin/update" class="inline-flex px-3 py-1.5 rounded-lg text-xs font-semibold text-white hover:opacity-90 transition" style="background:var(--brand-color)">Update Sekarang</a>
        <button type="button" onclick="updToastDismiss()" class="px-2 py-1.5 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">Nanti</button>
      </div>
    </div>
    <button type="button" onclick="updToastDismiss()" class="shrink-0 text-gray-300 hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400 transition" aria-label="Tutup">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>
<script>
(function () {
  var key = 'averion_upd_dismiss', ver = <?= json_encode($updToastVer) ?>, el = document.getElementById('upd-toast');
  if (!el || localStorage.getItem(key) === ver) return;
  el.style.display = 'block';
  requestAnimationFrame(function () { requestAnimationFrame(function () { el.style.transform = 'translateY(0)'; el.style.opacity = '1'; }); });
  window.updToastDismiss = function () {
    localStorage.setItem(key, ver);
    el.style.transform = 'translateY(8px)'; el.style.opacity = '0';
    setTimeout(function () { el.remove(); }, 350);
  };
})();
</script>
<?php endif; ?>

<?php
// Notifikasi Order Masuk (Admin) — popup + suara persisten di SEMUA halaman admin.
// Partial ber-guard sendiri (isAdmin() + sp_notif_order_enabled) & idempotent.
include __DIR__ . '/admin-order-notif.php';
?>

