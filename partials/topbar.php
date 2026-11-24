<?php
// $topbarTitle diset oleh halaman yang meng-include
$topbarTitle = $topbarTitle ?? '';
$userName    = $_SESSION['name']  ?? 'User';
$userRole    = $_SESSION['role']  ?? 'user';
$profileUrl  = $userRole === 'admin' ? APP_URL . '/admin/settings' : APP_URL . '/profile';

// ── Topbar Style (Appearance V2) ──
require_once __DIR__ . '/../helpers/theme-config.php';
$topbarClasses = getTopbarClasses();
$topbarStyle   = getSetting('ui_topbar_style', 'default');
?>
<?php if (!empty($_SESSION['impersonating'])): ?>
<?php require __DIR__ . '/impersonate-banner.php'; ?>
<?php endif; ?>
<?php if (defined('LICENSE_GRACE_WARNING')): ?>
<div class="bg-yellow-50 border-b border-yellow-200 px-4 py-2 text-xs text-yellow-800 text-center font-medium flex items-center justify-center gap-1.5">
  <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  <span><?= LICENSE_GRACE_WARNING ?></span>
</div>
<?php endif; ?>
<?php if (defined('LICENSE_CRYPTO_UNVERIFIED_WARNING')): ?>
<div class="bg-amber-50 border-b border-amber-200 px-4 py-2 text-xs text-amber-800 text-center font-medium flex items-center justify-center gap-1.5">
  <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <span><?= LICENSE_CRYPTO_UNVERIFIED_WARNING ?></span>
</div>
<?php endif; ?>
<header id="topbar-main"
        class="topbar dmi-topbar topbar-<?= e($topbarStyle) ?> shrink-0 h-14 flex items-center justify-between px-4 gap-4 z-20 <?= e($topbarClasses['bg']) ?> <?= e($topbarClasses['border']) ?> <?= e($topbarClasses['text']) ?> <?= e($topbarClasses['shadow']) ?>"
        <?= $topbarClasses['inline_style'] ? 'style="' . e($topbarClasses['inline_style']) . '"' : '' ?>>

  <!-- Kiri: Hamburger + Page Title -->
  <div class="flex items-center gap-3 min-w-0">
    <!-- Hamburger (mobile only) -->
    <button id="sidebarToggle"
            class="lg:hidden flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors shrink-0"
            aria-label="Buka atau tutup menu">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6"  x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>

    <!-- Page Title -->
    <?php if (!empty($topbarTitle)): ?>
    <h1 class="topbar-title text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($topbarTitle) ?></h1>
    <?php endif; ?>
  </div>

  <!-- Kanan: Actions -->
  <div class="flex items-center gap-1 shrink-0">

    <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)): ?>
    <!-- Global Search (Ctrl+K) — modal & logika di sidebar-admin.php -->
    <button type="button" onclick="if (window.openGlobalSearch) openGlobalSearch()"
            class="topbar-search hidden sm:flex items-center gap-2 h-9 px-3 rounded-lg text-xs text-gray-400 dark:text-gray-500 border border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            aria-label="Cari (Ctrl+K)">
      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <span>Cari</span>
      <kbd class="px-1.5 py-0.5 rounded border border-gray-200 dark:border-gray-700 text-[10px] font-mono">Ctrl K</kbd>
    </button>
    <?php endif; ?>

    <!-- Lonceng Info Member (fail-safe: tak render bila tabel belum ada) -->
    <?php include __DIR__ . '/info-bell.php'; ?>

    <?php if (getSetting('pwa_enabled', '1') === '1'): ?>
    <!-- Install Aplikasi (PWA) — tersembunyi; ditampilkan oleh app.js saat bisa di-install
         (Android/desktop Chrome) atau di iOS (panduan Add to Home Screen). -->
    <button id="pwaInstallBtn" type="button" hidden onclick="if(window.pwaPromptInstall)pwaPromptInstall()"
            class="flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            aria-label="Install aplikasi" title="Install aplikasi">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 3v11m0 0l-4-4m4 4l4-4" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M5 17v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>
    <?php endif; ?>

    <!-- Dark Mode Toggle (partial reusable) -->
    <?php include __DIR__ . '/dark-toggle.php'; ?>

    <!-- User Dropdown -->
    <div class="relative" data-dropdown>
      <button data-dropdown-trigger
              class="flex items-center gap-2 h-9 pl-1.5 pr-2.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
        <!-- Avatar -->
        <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"
             style="background:var(--brand-color)">
          <?= e(strtoupper(substr($userName, 0, 1))) ?>
        </div>
        <!-- Name -->
        <span class="hidden sm:block text-sm font-medium text-gray-700 dark:text-gray-300 max-w-[120px] truncate">
          <?= e($userName) ?>
        </span>
        <!-- Chevron -->
        <svg class="w-3.5 h-3.5 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </button>

      <!-- Dropdown Menu -->
      <div data-dropdown-menu
           class="hidden absolute right-0 top-full mt-1 w-48 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg py-1 z-50">

        <!-- User info header -->
        <div class="px-3 py-2 border-b border-gray-100 dark:border-gray-700">
          <p class="text-xs font-semibold text-gray-900 dark:text-white truncate"><?= e($userName) ?></p>
          <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?= e($_SESSION['email'] ?? '') ?></p>
        </div>

        <!-- Menu items -->
        <a href="<?= e($profileUrl) ?>"
           class="flex items-center gap-2.5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
          <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
          </svg>
          Profil Saya
        </a>

        <?php if ($userRole === 'admin'): ?>
        <a href="<?= e(APP_URL) ?>/admin"
           class="flex items-center gap-2.5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
          <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
          </svg>
          Admin Panel
        </a>
        <?php endif; ?>

        <div class="border-t border-gray-100 dark:border-gray-700 mt-1 pt-1">
          <button type="button"
             onclick="confirmAndRun({title:'Keluar dari Akun?',message:'Anda akan keluar dari akun ini.',confirmText:'Ya, Keluar',variant:'warning'}, () => { document.getElementById('logout-form').submit(); })"
             class="flex items-center gap-2.5 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors w-full text-left">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
              <polyline points="16 17 21 12 16 7"/>
              <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Keluar
          </button>
        </div>
      </div>
    </div>

  </div>
</header>

<?php
// Modal + JS Global Search (Ctrl+K). Di-include DI SINI, bukan di
// sidebar-admin.php, karena tombol Cari & shortcut Ctrl+K hidup di topbar yang
// dipakai SEMUA area dashboard — termasuk area member (sidebar-user.php). Gate
// role sama persis dengan tombol Cari di atas.
if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    require __DIR__ . '/global-search.php';
}

// ─── Pumper Broadcast Global ──────────────────────────────────
// Bila ada broadcast berstatus 'sending', jaga pengiriman TETAP jalan di latar
// dari halaman admin mana pun (pindah menu tak menghentikan kirim). Ringan: 1
// query terindeks; script hanya dirender saat memang ada yang berjalan. Lock
// per-campaign (GET_LOCK) menjaga korrektness bila halaman broadcast juga memompa.
if (function_exists('staffCan') && staffCan('broadcast')) {
    $__bcSendingId = 0;
    try { $__bcSendingId = (int) getDB()->query("SELECT id FROM broadcast_campaigns WHERE status='sending' ORDER BY id ASC LIMIT 1")->fetchColumn(); }
    catch (Throwable $e) { $__bcSendingId = 0; }
    if ($__bcSendingId > 0):
        generateCSRF();
?>
<script>
(function () {
  var APP = <?= json_encode(rtrim(APP_URL, '/')) ?>, CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>, ID = <?= (int) $__bcSendingId ?>;
  // Jangan pompa dari halaman broadcast (halaman itu punya pump sendiri + progress bar).
  if (/\/admin\/broadcast(\b|$)/.test(location.pathname)) return;
  var stop = false;
  function pump() {
    if (stop || document.hidden) { setTimeout(pump, 2500); return; }
    var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('campaign_id', ID); fd.append('limit', '4'); // ringan (sesi tak lama tertahan)
    fetch(APP + '/actions/admin/broadcast-pump', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && (d.status === 'sent' || d.status === 'failed')) { stop = true; return; } setTimeout(pump, 3000); })
      .catch(function () { setTimeout(pump, 5000); });
  }
  setTimeout(pump, 1500);
})();
</script>
<?php
    endif;
}
?>
