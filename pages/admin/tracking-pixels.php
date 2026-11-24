<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Tracking Pixels';
$topbarTitle = 'Tracking Pixels';

// Load config pixel lama (untuk TikTok + migrasi Meta).
$pixels = [];
try {
    $stmt = getDB()->query("SELECT * FROM tracking_pixels ORDER BY id ASC");
    while ($row = $stmt->fetch()) {
        $pixels[$row['platform']] = $row;
    }
} catch (Exception $e) {
    error_log('Tracking pixels load error: ' . $e->getMessage());
}
$p = function(string $platform, string $key, string $default = '') use ($pixels): string {
    return $pixels[$platform][$key] ?? $default;
};
$hasTiktokCapiToken = trim($p('tiktok_capi', 'access_token')) !== '';

// ── Migrasi config Meta lama (rows) → meta_pixels_json (sekali) ──
$metaListRaw = (string) getSetting('meta_pixels_json', '');
if ($metaListRaw === '') {
    $oldPid = trim($p('meta_capi', 'pixel_id')) ?: trim($p('meta_pixel', 'pixel_id'));
    if ($oldPid !== '') {
        $seed = [[
            'id'              => 'px_1',
            'enabled'         => '1',
            'pixel_id'        => preg_replace('/[^0-9]/', '', $oldPid),
            'token_enc'       => (string) $p('meta_capi', 'access_token'),
            'test_event_code' => (string) $p('meta_capi', 'test_event_code'),
        ]];
        setSetting('meta_pixels_json', json_encode($seed), 'tracking');
        setSetting('meta_pixel_enabled', ((int) ($pixels['meta_pixel']['is_active'] ?? 0) ? '1' : '0'), 'tracking');
        setSetting('meta_capi_enabled', ((int) ($pixels['meta_capi']['is_active'] ?? 0) ? '1' : '0'), 'tracking');
        $metaListRaw = (string) getSetting('meta_pixels_json', '');
    }
}
$metaList = json_decode($metaListRaw, true);
if (!is_array($metaList)) $metaList = [];
$metaPixelEnabled = (string) getSetting('meta_pixel_enabled', '0') === '1';
$metaCapiEnabled  = (string) getSetting('meta_capi_enabled', '0') === '1';
$metaFullFunnel   = (string) getSetting('meta_full_funnel_enabled', '0') === '1';
$metaCapiLastAt   = (string) getSetting('meta_capi_last_at', '');
$metaCapiLastHttp = (string) getSetting('meta_capi_last_http', '');
$metaCapiLastErr  = (string) getSetting('meta_capi_last_error', '');

$metaEventOptions = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Lead', 'Purchase', 'CompleteRegistration', 'Subscribe', 'Contact', 'CustomEvent'];
$metaStages = [
    'checkout' => ['Halaman Checkout', 'AddToCart'],
    'invoice'  => ['Order Dibuat',     'Lead'],
    'paid'     => ['Pembayaran Sukses', 'Purchase'],
];

$inp = 'w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition';

generateCSRF();
$csrf = $_SESSION['csrf_token'];
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <?php
      $metaActive   = $metaPixelEnabled || $metaCapiEnabled;
      $tkPixelOn    = (bool) ($pixels['tiktok_pixel']['is_active'] ?? 0);
      $tkCapiOn     = (bool) ($pixels['tiktok_capi']['is_active'] ?? 0);
      $tiktokActive = $tkPixelOn || $tkCapiOn;
      $statPill = fn(bool $on): string => $on
        ? 'background:color-mix(in srgb,var(--brand-color) 14%,transparent);color:var(--brand-color)'
        : 'background:rgba(148,163,184,.16);color:#94a3b8';
      ?>
      <style>
        .tp-tab{display:flex;align-items:center;gap:8px;padding:10px 16px;font-size:13px;font-weight:600;color:#64748b;background:none;border:0;border-bottom:2px solid transparent;margin-bottom:-1px;cursor:pointer;transition:color .15s}
        .tp-tab:hover{color:#334155}
        .tp-tab.active{color:#0f172a;border-bottom-color:var(--brand-color)}
        html.dark .tp-tab{color:#94a3b8} html.dark .tp-tab:hover{color:#cbd5e1} html.dark .tp-tab.active{color:#fff}
        .tp-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px}
      </style>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Tracking Pixels</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kirim konversi ke Meta &amp; TikTok — Pixel (browser) + Conversions API (server). Event ke semua pixel aktif dgn <code class="text-xs px-1 rounded bg-gray-100 dark:bg-gray-800">event_id</code> sama (auto-dedup), token terenkripsi.</p>
      </div>

      <!-- Tab provider -->
      <div class="flex gap-1 border-b border-gray-200 dark:border-gray-800 mb-5">
        <button type="button" data-tab="meta" class="tp-tab active">
          <svg class="w-4 h-4 text-[#1877f2]" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12c0-5.523-4.477-10-10-10z"/></svg>
          Meta <span class="tp-badge" style="<?= $statPill($metaActive) ?>"><?= $metaActive ? 'Aktif' : 'Nonaktif' ?></span>
        </button>
        <button type="button" data-tab="tiktok" class="tp-tab">
          <svg class="w-4 h-4 text-gray-800 dark:text-gray-200" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.25 8.25 0 0 0 4.83 1.56V6.8a4.86 4.86 0 0 1-1.06-.11z"/></svg>
          TikTok <span class="tp-badge" style="<?= $statPill($tiktokActive) ?>"><?= $tiktokActive ? 'Aktif' : 'Nonaktif' ?></span>
        </button>
      </div>

      <!-- ═══ PANEL META ═══ -->
      <div id="tp-panel-meta">
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-meta-tracking" data-loading class="mb-2">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-blue-50 dark:bg-blue-900/20 shrink-0">
                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12c0-5.523-4.477-10-10-10z"/></svg>
              </div>
              <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Meta Conversion API</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Pixel (browser) + CAPI (server), multi-pixel</p>
              </div>
            </div>
          </div>

          <div class="p-5 space-y-5">
            <!-- Aktivasi: dua toggle utama sejajar -->
            <div>
              <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Aktivasi</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-3.5 py-3 cursor-pointer">
                  <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">Meta Pixel <span class="text-gray-400 font-normal">(browser)</span></span>
                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Skrip pixel di halaman publik</span>
                  </span>
                  <span class="relative shrink-0">
                    <input type="hidden" name="meta_pixel_enabled_global" value="0">
                    <input type="checkbox" name="meta_pixel_enabled_global" value="1" class="sr-only peer" <?= $metaPixelEnabled ? 'checked' : '' ?>>
                    <span class="block w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></span>
                    <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                  </span>
                </label>
                <label class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-3.5 py-3 cursor-pointer">
                  <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">Meta CAPI <span class="text-gray-400 font-normal">(server)</span></span>
                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Event server-side, tahan ad-blocker</span>
                  </span>
                  <span class="relative shrink-0">
                    <input type="hidden" name="meta_capi_enabled_global" value="0">
                    <input type="checkbox" id="meta-capi-toggle" name="meta_capi_enabled_global" value="1" class="sr-only peer" <?= $metaCapiEnabled ? 'checked' : '' ?>>
                    <span class="block w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></span>
                    <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                  </span>
                </label>
              </div>

              <!-- Sub-opsi dependen: menjorok di bawah CAPI -->
              <div class="flex gap-2.5 mt-2.5 pl-1">
                <div class="w-0.5 rounded shrink-0 ml-5" style="background:var(--brand-color);opacity:.45"></div>
                <label id="meta-fullfunnel-block" class="flex-1 flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/30 px-3.5 py-3 cursor-pointer transition-opacity">
                  <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">Corong penuh ke Meta <span class="text-gray-400 font-normal">ViewContent + checkout</span></span>
                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Kirim event tahap awal funnel (kunjungan sales page &amp; checkout) via CAPI, bukan cuma Purchase. Termasuk LP eksternal. <span class="meta-capi-hint">Butuh CAPI aktif.</span></span>
                  </span>
                  <span class="relative shrink-0">
                    <input type="hidden" name="meta_full_funnel_enabled" value="0">
                    <input type="checkbox" id="meta-fullfunnel-toggle" name="meta_full_funnel_enabled" value="1" class="sr-only peer" <?= $metaFullFunnel ? 'checked' : '' ?>>
                    <span class="block w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></span>
                    <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                  </span>
                </label>
              </div>
            </div>

            <?php if ($metaCapiLastAt !== ''): ?>
            <div class="text-xs rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40 px-3 py-2 text-gray-500 dark:text-gray-400">
              Event CAPI terakhir: <strong class="<?= $metaCapiLastHttp === '200' ? 'text-green-600 dark:text-green-400' : 'text-red-500' ?>">HTTP <?= e($metaCapiLastHttp) ?></strong> · <?= e($metaCapiLastAt) ?>
              <?php if ($metaCapiLastErr !== ''): ?><br><span class="text-red-500"><?= e($metaCapiLastErr) ?></span><?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Pixels -->
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-semibold text-gray-900 dark:text-white">Pixels</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Beberapa Pixel ID + Access Token. Event dikirim ke semua yang aktif.</p>
              </div>
              <button type="button" id="meta-add-pixel" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold text-white" style="background-color:var(--brand-color)">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Tambah Pixel
              </button>
            </div>

            <div id="meta-pixels-list" class="space-y-3">
              <?php
              $rowsUi = $metaList ?: [['id' => 'px_1', 'enabled' => '1', 'pixel_id' => '', 'token_enc' => '', 'test_event_code' => '']];
              $n = 0;
              foreach ($rowsUi as $r):
                $n++;
                $rid = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($r['id'] ?? ('px_' . $n))) ?: ('px_' . $n);
                $rpid = preg_replace('/[^0-9]/', '', (string) ($r['pixel_id'] ?? ''));
                $ren  = ((string) ($r['enabled'] ?? '1')) === '1';
                $rtest = (string) ($r['test_event_code'] ?? '');
                $rHasTok = trim((string) ($r['token_enc'] ?? '')) !== '';
              ?>
              <div class="meta-pixel-card rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/30 p-4" data-row-id="<?= e($rid) ?>">
                <input type="hidden" name="meta_pixel_order[]" value="<?= e($rid) ?>">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                  <span class="text-sm font-bold text-gray-900 dark:text-white meta-pixel-title">Pixel #<?= $n ?></span>
                  <div class="flex items-center gap-2">
                    <label class="relative shrink-0 cursor-pointer" title="Aktif/Nonaktif">
                      <input type="hidden" name="meta_pixel_enabled[<?= e($rid) ?>]" value="0">
                      <input type="checkbox" name="meta_pixel_enabled[<?= e($rid) ?>]" value="1" class="sr-only peer" <?= $ren ? 'checked' : '' ?>>
                      <span class="block w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></span>
                      <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                    </label>
                    <button type="button" class="meta-test-btn px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Test</button>
                    <button type="button" class="meta-del-btn px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-900/40 text-xs font-bold text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">Hapus</button>
                  </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Pixel ID</label>
                    <input type="text" name="meta_pixel_id[<?= e($rid) ?>]" value="<?= e($rpid) ?>" placeholder="123456789012345" class="<?= $inp ?>">
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Access Token</label>
                    <input type="password" name="meta_pixel_token[<?= e($rid) ?>]" value="" autocomplete="new-password" placeholder="<?= $rHasTok ? '•••••• tersimpan — kosongkan utk biarkan' : 'CAPI Access Token' ?>" class="<?= $inp ?>">
                  </div>
                  <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Test Event Code <span class="text-gray-400 font-normal">(opsional)</span></label>
                    <input type="text" name="meta_pixel_test_event_code[<?= e($rid) ?>]" value="<?= e($rtest) ?>" placeholder="TEST12345" class="<?= $inp ?>">
                  </div>
                </div>
                <p class="meta-test-result hidden mt-2 text-xs px-1"></p>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Event funnel mapping (lanjutan, dilipat) -->
            <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
              <button type="button" id="meta-adv-toggle" class="flex items-center gap-2 w-full text-left" aria-expanded="false">
                <svg id="meta-adv-chevron" class="w-4 h-4 text-gray-400 shrink-0 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 6 15 12 9 18"/></svg>
                <span class="text-sm font-semibold text-gray-900 dark:text-white">Pengaturan lanjutan — Event Funnel Mapping</span>
                <span class="ml-auto text-xs text-gray-400 dark:text-gray-500 tabular-nums">AddToCart · Lead · Purchase</span>
              </button>
              <div id="meta-adv-body" class="hidden mt-3">
              <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">Nama event Meta untuk tiap tahap. Berlaku untuk Pixel &amp; CAPI. Ubah hanya jika kamu paham penamaan event Meta.</p>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <?php foreach ($metaStages as $stage => $meta): [$label, $def] = $meta;
                  $cur = (string) getSetting('meta_event_' . $stage, $def);
                  $curCustom = (string) getSetting('meta_event_' . $stage . '_custom', '');
                ?>
                <div>
                  <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1"><?= e($label) ?></label>
                  <select name="meta_event_<?= $stage ?>" data-stage="<?= $stage ?>" class="meta-event-select <?= $inp ?>">
                    <?php foreach ($metaEventOptions as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= $cur === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="meta_event_<?= $stage ?>_custom" value="<?= e($curCustom) ?>" placeholder="Nama Custom Event" class="meta-event-custom <?= $inp ?> mt-2 <?= $cur === 'CustomEvent' ? '' : 'hidden' ?>">
                </div>
                <?php endforeach; ?>
              </div>
              </div><!-- /meta-adv-body -->
            </div>
          </div>

          <div class="px-5 pb-5">
            <button type="submit" data-loading-text="Menyimpan..." class="w-full px-4 py-2.5 text-sm font-semibold text-white rounded-lg transition-colors" style="background-color:var(--brand-color)">Simpan Meta</button>
          </div>
        </div>
      </form>
      </div><!-- /tp-panel-meta -->

      <!-- ═══ PANEL TIKTOK (satu kartu, satu save) ═══ -->
      <div id="tp-panel-tiktok" class="hidden">
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-tracking-pixel" data-loading class="mb-2">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="platform" value="tiktok">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-gray-100 dark:bg-gray-800 shrink-0">
              <svg class="w-5 h-5 text-gray-700 dark:text-gray-300" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.25 8.25 0 0 0 4.83 1.56V6.8a4.86 4.86 0 0 1-1.06-.11z"/></svg>
            </div>
            <div>
              <h3 class="text-sm font-semibold text-gray-900 dark:text-white">TikTok</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400">Pixel (browser) + Conversions API (server)</p>
            </div>
          </div>

          <div class="p-5 space-y-5">
            <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 -mb-2">Aktivasi</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <label class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-3.5 py-3 cursor-pointer">
                <span class="min-w-0">
                  <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">TikTok Pixel <span class="text-gray-400 font-normal">(browser)</span></span>
                  <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">PageView &amp; CompletePayment</span>
                </span>
                <span class="relative shrink-0">
                  <input type="hidden" name="tk_pixel_active" value="0">
                  <input type="checkbox" name="tk_pixel_active" value="1" class="sr-only peer" <?= $tkPixelOn ? 'checked' : '' ?>>
                  <span class="block w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></span>
                  <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                </span>
              </label>
              <label class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-3.5 py-3 cursor-pointer">
                <span class="min-w-0">
                  <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">TikTok CAPI <span class="text-gray-400 font-normal">(server)</span></span>
                  <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Events API server-side</span>
                </span>
                <span class="relative shrink-0">
                  <input type="hidden" name="tk_capi_active" value="0">
                  <input type="checkbox" name="tk_capi_active" value="1" class="sr-only peer" <?= $tkCapiOn ? 'checked' : '' ?>>
                  <span class="block w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></span>
                  <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span>
                </span>
              </label>
            </div>

            <div class="border-t border-gray-100 dark:border-gray-800 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Pixel ID <span class="text-gray-400 font-normal">(browser)</span></label>
                <input type="text" name="tk_pixel_id" value="<?= e($p('tiktok_pixel','pixel_id')) ?>" placeholder="Contoh: C3D4E5F6G7H8I9J0" class="<?= $inp ?>">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Pixel ID <span class="text-gray-400 font-normal">(CAPI)</span></label>
                <input type="text" name="tk_capi_id" value="<?= e($p('tiktok_capi','pixel_id')) ?>" placeholder="Contoh: C3D4E5F6G7H8I9J0" class="<?= $inp ?>">
              </div>
              <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Access Token <span class="text-gray-400 font-normal">(CAPI)</span></label>
                <input type="password" name="tk_capi_token" value="" autocomplete="new-password" placeholder="<?= $hasTiktokCapiToken ? '•••••• tersimpan — kosongkan utk biarkan' : 'Long-term Access Token dari TikTok' ?>" class="<?= $inp ?>">
              </div>
            </div>
          </div>

          <div class="px-5 pb-5">
            <button type="submit" data-loading-text="Menyimpan..." class="w-full px-4 py-2.5 text-sm font-semibold text-white rounded-lg transition-colors" style="background-color:var(--brand-color)">Simpan TikTok</button>
          </div>
        </div>
      </form>
      </div><!-- /tp-panel-tiktok -->

    </main>
  </div>
</div>

<script>
(function () {
  var TEST_URL = '<?= e(APP_URL) ?>/actions/admin/save-meta-tracking';
  var CSRF = <?= json_encode($csrf) ?>;
  var list = document.getElementById('meta-pixels-list');
  var addBtn = document.getElementById('meta-add-pixel');
  var INP = <?= json_encode($inp) ?>;

  function renumber() {
    list.querySelectorAll('.meta-pixel-card').forEach(function (c, i) {
      var t = c.querySelector('.meta-pixel-title'); if (t) t.textContent = 'Pixel #' + (i + 1);
    });
  }
  function bind(card) {
    var del = card.querySelector('.meta-del-btn');
    var test = card.querySelector('.meta-test-btn');
    var res = card.querySelector('.meta-test-result');
    if (del) del.addEventListener('click', function () {
      if (list.querySelectorAll('.meta-pixel-card').length <= 1) { card.querySelector('input[name^="meta_pixel_id"]').value = ''; return; }
      card.remove(); renumber();
    });
    if (test) test.addEventListener('click', function () {
      var rid = card.getAttribute('data-row-id');
      test.disabled = true; var t = test.textContent; test.textContent = '...';
      res.classList.remove('hidden'); res.className = 'meta-test-result mt-2 text-xs px-1 text-gray-500'; res.textContent = 'Mengirim test event...';
      var body = 'action=test_meta_pixel&csrf_token=' + encodeURIComponent(CSRF) + '&pixel_row_id=' + encodeURIComponent(rid);
      fetch(TEST_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: body })
        .then(function (r) { return r.json(); }).then(function (d) {
          var ok = d.status === 'success';
          res.className = 'meta-test-result mt-2 text-xs px-1 ' + (ok ? 'text-green-600 dark:text-green-400' : 'text-red-500');
          res.textContent = (ok ? '✓ ' : '✗ ') + (d.message || '');
        }).catch(function () { res.className = 'meta-test-result mt-2 text-xs px-1 text-red-500'; res.textContent = '✗ Gangguan koneksi.'; })
        .finally(function () { test.disabled = false; test.textContent = t; });
    });
  }
  function ridNew() { return 'px_' + Math.random().toString(16).slice(2, 10) + Date.now().toString(16).slice(-4); }

  addBtn.addEventListener('click', function () {
    var rid = ridNew();
    var div = document.createElement('div');
    div.className = 'meta-pixel-card rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/30 p-4';
    div.setAttribute('data-row-id', rid);
    div.innerHTML =
      '<input type="hidden" name="meta_pixel_order[]" value="' + rid + '">' +
      '<div class="flex flex-wrap items-center justify-between gap-2 mb-3"><span class="text-sm font-bold text-gray-900 dark:text-white meta-pixel-title">Pixel</span>' +
        '<div class="flex items-center gap-2">' +
          '<label class="relative shrink-0 cursor-pointer"><input type="hidden" name="meta_pixel_enabled[' + rid + ']" value="0"><input type="checkbox" name="meta_pixel_enabled[' + rid + ']" value="1" class="sr-only peer" checked><span class="block w-10 h-5 bg-gray-200 dark:bg-gray-700 rounded-full peer-checked:bg-[var(--brand-color)] transition-colors"></span><span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></span></label>' +
          '<button type="button" class="meta-test-btn px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Test</button>' +
          '<button type="button" class="meta-del-btn px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-900/40 text-xs font-bold text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">Hapus</button>' +
        '</div></div>' +
      '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">' +
        '<div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Pixel ID</label><input type="text" name="meta_pixel_id[' + rid + ']" value="" placeholder="123456789012345" class="' + INP + '"></div>' +
        '<div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Access Token</label><input type="password" name="meta_pixel_token[' + rid + ']" value="" autocomplete="new-password" placeholder="CAPI Access Token" class="' + INP + '"></div>' +
        '<div class="sm:col-span-2"><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Test Event Code (opsional)</label><input type="text" name="meta_pixel_test_event_code[' + rid + ']" value="" placeholder="TEST12345" class="' + INP + '"></div>' +
      '</div><p class="meta-test-result hidden mt-2 text-xs px-1"></p>';
    list.appendChild(div); bind(div); renumber();
  });

  list.querySelectorAll('.meta-pixel-card').forEach(bind);

  document.querySelectorAll('.meta-event-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var custom = sel.parentElement.querySelector('.meta-event-custom');
      if (custom) custom.classList.toggle('hidden', sel.value !== 'CustomEvent');
    });
  });

  // ── Tab provider (Meta / TikTok) ─────────────────────────────
  var tabs = document.querySelectorAll('.tp-tab');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var name = tab.getAttribute('data-tab');
      tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
      var meta = document.getElementById('tp-panel-meta');
      var tik  = document.getElementById('tp-panel-tiktok');
      if (meta) meta.classList.toggle('hidden', name !== 'meta');
      if (tik)  tik.classList.toggle('hidden', name !== 'tiktok');
    });
  });

  // ── Pengaturan lanjutan (Event Mapping) — collapse ───────────
  var advBtn = document.getElementById('meta-adv-toggle');
  if (advBtn) advBtn.addEventListener('click', function () {
    var body = document.getElementById('meta-adv-body');
    var chev = document.getElementById('meta-adv-chevron');
    var open = body.classList.toggle('hidden') === false;
    advBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (chev) chev.style.transform = open ? 'rotate(90deg)' : '';
  });

  // ── Dependensi: Corong penuh butuh CAPI aktif ────────────────
  var capiToggle = document.getElementById('meta-capi-toggle');
  var ffBlock = document.getElementById('meta-fullfunnel-block');
  var ffToggle = document.getElementById('meta-fullfunnel-toggle');
  function syncFullFunnel() {
    if (!capiToggle || !ffBlock) return;
    var on = capiToggle.checked;
    ffBlock.style.opacity = on ? '' : '.5';
    ffBlock.style.pointerEvents = on ? '' : 'none';
    if (!on && ffToggle) ffToggle.checked = false;   // CAPI mati → corong penuh ikut mati
    var hint = ffBlock.querySelector('.meta-capi-hint');
    if (hint) hint.style.color = on ? '' : '#f59e0b';
  }
  if (capiToggle) { capiToggle.addEventListener('change', syncFullFunnel); syncFullFunnel(); }
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
