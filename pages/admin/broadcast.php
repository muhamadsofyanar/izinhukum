<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('broadcast')) { redirect('/admin/no-access'); }

/**
 * Render pemilih produk (checkbox) dengan pencarian + Semua/Kosongkan + penghitung.
 * Dipakai bersama oleh "Pembeli Produk" (target) & "Kecualikan Pembeli Produk".
 */
function renderBroadcastProductPicker(array $products, string $inputName, string $placeholder): void
{
    if (empty($products)) {
        echo '<div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 px-3 py-2.5"><p class="text-xs text-gray-400">Belum ada produk aktif.</p></div>';
        return;
    }
    ?>
    <div class="bcpp" data-product-picker>
      <div class="bcpp-bar">
        <input type="text" data-pp-search placeholder="<?= e($placeholder) ?>"
               class="bcpp-search px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
        <button type="button" data-pp-all class="shrink-0 px-2 py-1.5 text-[11px] font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Semua</button>
        <button type="button" data-pp-none class="shrink-0 px-2 py-1.5 text-[11px] font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Kosongkan</button>
      </div>
      <div class="bcpp-list" data-pp-list>
        <?php foreach ($products as $p): ?>
        <label class="bcpp-item" data-pp-item data-pp-name="<?= e(mb_strtolower($p['name'])) ?>">
          <input type="checkbox" name="<?= e($inputName) ?>[]" value="<?= (int) $p['id'] ?>" class="shrink-0" style="accent-color:var(--brand-color)">
          <span><?= e($p['name']) ?></span>
        </label>
        <?php endforeach; ?>
        <p class="bcpp-empty text-xs text-gray-400 px-2 py-2" data-pp-empty hidden>Tak ada produk cocok.</p>
      </div>
      <div class="bcpp-foot"><span data-pp-count class="font-semibold text-gray-600 dark:text-gray-300">0</span> dipilih</div>
    </div>
    <?php
}

$pageTitle   = 'Broadcast';
$topbarTitle = 'Broadcast Campaign';

$pdo = getDB();

// ─── Load campaigns ───────────────────────────────────────────
$campaigns = [];
try {
    $campaigns = $pdo->query(
        "SELECT bc.*, ml.name AS level_name, lm.title AS lead_magnet_title,
                (SELECT COUNT(*) FROM broadcast_recipients r WHERE r.campaign_id = bc.id AND r.opened_at IS NOT NULL)  AS uniq_opens,
                (SELECT COUNT(*) FROM broadcast_recipients r WHERE r.campaign_id = bc.id AND r.clicked_at IS NOT NULL) AS uniq_clicks
         FROM broadcast_campaigns bc
         LEFT JOIN membership_levels ml ON ml.id = bc.target_membership_level_id
         LEFT JOIN lead_magnets lm ON lm.id = bc.target_lead_magnet_id
         ORDER BY bc.created_at DESC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Broadcast load error: ' . $e->getMessage());
}

// Campaign aktif (sending → auto-pump; paused → panel dijeda) saat halaman dibuka.
$sendingCampaign = null;
foreach ($campaigns as $c) { if (in_array($c['status'] ?? '', ['sending', 'paused'], true)) { $sendingCampaign = $c; break; } }

// ─── Data draft untuk Edit + Preview (hanya status draft yang editable) ─
$bcEditData = [];
foreach ($campaigns as $c) {
    if (($c['status'] ?? '') !== 'draft') continue;
    $bcEditData[(int) $c['id']] = [
        'name'                       => $c['name'] ?? '',
        'channel'                    => $c['channel'] ?? 'email',
        'provider'                   => $c['provider'] ?? '',
        'subject'                    => $c['subject'] ?? '',
        'body'                       => $c['body'] ?? '',
        'body_format'                => $c['body_format'] ?? 'text',
        'target_segment'             => $c['target_segment'] ?? 'all_users',
        'engagement_days'            => isset($c['engagement_days']) && $c['engagement_days'] !== null ? (int) $c['engagement_days'] : '',
        'target_membership_level_id' => !empty($c['target_membership_level_id']) ? (int) $c['target_membership_level_id'] : '',
        'target_lead_magnet_id'      => !empty($c['target_lead_magnet_id']) ? (int) $c['target_lead_magnet_id'] : '',
        'target_products'            => array_values(array_filter(array_map('intval', explode(',', (string) ($c['target_product_ids'] ?? ''))))),
        'exclude_products'           => array_values(array_filter(array_map('intval', explode(',', (string) ($c['exclude_product_ids'] ?? ''))))),
        'skip_failed_emails'         => (int) ($c['skip_failed_emails'] ?? 0),
        'scheduled_at'               => $c['scheduled_at'] ?? '',
    ];
}

// ─── Load membership levels untuk dropdown ─────────────────────
$membershipLevels = [];
try {
    $membershipLevels = $pdo->query("SELECT id, name FROM membership_levels ORDER BY sort_order ASC")->fetchAll();
} catch (Exception $e) {
    error_log('Broadcast load levels error: ' . $e->getMessage());
}

// ─── Load lead magnets untuk dropdown (target leads_by_magnet) ──
$leadMagnets = [];
try {
    $leadMagnets = $pdo->query("SELECT id, title FROM lead_magnets ORDER BY created_at DESC")->fetchAll();
} catch (Exception $e) {
    error_log('Broadcast load lead magnets error: ' . $e->getMessage());
}

// ─── Produk (untuk "Kecualikan Pembeli Produk") ────────────────
$products = [];
try {
    $products = $pdo->query("SELECT id, name FROM products WHERE status='active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    error_log('Broadcast load products error: ' . $e->getMessage());
}

// ─── Provider aktif per channel ─────────────────────────────
$activeProviders = ['email' => [], 'whatsapp' => []];
try {
    $mkKey = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='mailketing_api_key' LIMIT 1")->fetchColumn();
    if (!empty($mkKey)) $activeProviders['email'][] = ['value' => 'mailketing', 'label' => 'Mailketing'];

    $waKeys = [
        'fonte'      => 'fonte_api_key',
        'onesender'  => 'onesender_api_key',
        'starsender' => 'starsender_api_key',
        'dripsender' => 'dripsender_api_key',
        'kirimdev'   => 'kirimdev_api_key',
    ];
    $waLabels = [
        'fonte'      => 'Fonte',
        'onesender'  => 'OneSender',
        'starsender' => 'StarSender',
        'dripsender' => 'DripSender',
        'kirimdev'   => 'Kirimdev',
    ];
    foreach ($waKeys as $provider => $key) {
        $stmtKey = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        $stmtKey->execute([$key]);
        $apiKey = $stmtKey->fetchColumn();

        $stmtEnabled = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        $stmtEnabled->execute([$provider . '_enabled']);
        $isEnabled = $stmtEnabled->fetchColumn();

        if (!empty($apiKey) && $isEnabled === '1') {
            $activeProviders['whatsapp'][] = ['value' => $provider, 'label' => $waLabels[$provider]];
        }
    }
} catch (Exception $e) {
    error_log('Broadcast load providers error: ' . $e->getMessage());
}


$segmentLabels = [
    'all_users'       => 'Semua User',
    'active_members'  => 'Member Aktif',
    'specific_level'  => 'Level Spesifik',
    'leads_all'       => 'Semua Leads',
    'leads_by_magnet' => 'Leads per Lead Magnet',
    'product_buyers'  => 'Pembeli Produk',
];

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

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Broadcast Campaign</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kirim email atau WhatsApp ke segmen user secara massal</p>
        </div>
        <button type="button" id="toggleCreateForm"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white rounded-lg transition-colors"
                style="background-color:var(--brand-color)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Buat Campaign Baru
        </button>
      </div>

      <!-- ─── Panel Progress Broadcast (SSE) ───────────────────── -->
      <div id="bcProgressPanel" class="hidden mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h3 id="bcProgressTitle" class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-2">
              <span id="bcProgressDot" class="relative flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-60" style="background:var(--brand-color)"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background:var(--brand-color)"></span>
              </span>
              <svg id="bcProgressDoneIcon" class="hidden w-4 h-4 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
              </svg>
              <svg id="bcProgressErrIcon" class="hidden w-4 h-4 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
              </svg>
              <span id="bcProgressText">Broadcast sedang berjalan…</span>
            </h3>
            <span id="bcProgressPct" class="text-sm font-bold" style="color:var(--brand-color)">0%</span>
          </div>
          <div class="w-full h-2.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
            <div id="bcProgressBar" class="h-full rounded-full transition-all duration-300" style="width:0%;background:var(--brand-color)"></div>
          </div>
          <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
            <p class="text-gray-500 dark:text-gray-400">Mengirim ke: <span id="bcCurrentRecipient" class="font-medium text-gray-800 dark:text-gray-200">—</span></p>
            <p class="text-gray-500 dark:text-gray-400"><span id="bcCounter">0 dari 0</span> penerima · <span id="bcFailCounter" class="text-red-500">0 gagal</span></p>
          </div>
          <p id="bcProgressError" class="hidden mt-3 text-xs text-red-500 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2"></p>
          <div id="bcProgressDone" class="hidden mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-3">
            <p id="bcSummary" class="text-sm text-gray-700 dark:text-gray-300"></p>
            <div class="flex items-center gap-2 ml-auto">
              <button type="button" id="bcViewLog" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Lihat Log</button>
              <button type="button" id="bcCloseProgress" class="px-3 py-1.5 text-xs font-semibold text-white rounded-lg transition" style="background-color:var(--brand-color)">Selesai &amp; Muat Ulang</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Pemilih produk (target/kecualikan): layout via style lokal agar tak
           bergantung kelas Tailwind yang belum ter-compile. -->
      <style>
        .bcpp { border:1px solid #e5e7eb; border-radius:.5rem; background:#f9fafb; overflow:hidden; }
        .bcpp-bar { display:flex; align-items:center; gap:.375rem; padding:.375rem .5rem; border-bottom:1px solid #e5e7eb; background:rgba(255,255,255,.65); }
        .bcpp-search { flex:1; min-width:0; }
        .bcpp-list { max-height:15rem; overflow-y:auto; padding:.375rem; display:grid; grid-template-columns:1fr; gap:.125rem .75rem; }
        @media (min-width:640px){ .bcpp-list { grid-template-columns:1fr 1fr; } }
        @media (min-width:1024px){ .bcpp-list { grid-template-columns:1fr 1fr 1fr; } }
        .bcpp-item { display:flex; align-items:center; gap:.5rem; padding:.3rem .4rem; border-radius:.375rem; cursor:pointer; font-size:.875rem; color:#374151; min-width:0; }
        .bcpp-item:hover { background:#fff; }
        .bcpp-item span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        /* [hidden] harus mengalahkan .bcpp-item{display:flex} saat difilter JS. */
        .bcpp-list [hidden] { display:none; }
        .bcpp-empty { grid-column:1 / -1; }
        .bcpp-foot { padding:.375rem .625rem; border-top:1px solid #e5e7eb; font-size:11px; color:#9ca3af; }
        .dark .bcpp { border-color:#374151; background:rgba(31,41,55,.4); }
        .dark .bcpp-bar { border-color:#374151; background:rgba(17,24,39,.35); }
        .dark .bcpp-item { color:#e5e7eb; }
        .dark .bcpp-item:hover { background:rgba(55,65,81,.45); }
        .dark .bcpp-foot { border-color:#374151; }
      </style>

      <!-- ─── Form Buat Campaign (collapsible) ─────────────────── -->
      <div id="createFormWrap" class="hidden mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3" style="background: color-mix(in srgb, var(--brand-color) 4%, transparent);">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background: color-mix(in srgb, var(--brand-color) 12%, transparent); color: var(--brand-color);">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49M7.76 16.24a6 6 0 0 1 0-8.49M19.07 4.93a10 10 0 0 1 0 14.14M4.93 19.07a10 10 0 0 1 0-14.14"/></svg>
            </div>
            <div>
              <h3 id="bcFormTitle" class="text-sm font-semibold text-gray-900 dark:text-white">Campaign Baru</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Susun pesan, pilih channel &amp; segmen penerima</p>
            </div>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-broadcast" id="broadcastForm" data-loading class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="campaign_id" id="bcCampaignId" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <!-- Nama -->
              <div class="md:col-span-2">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                  <span>Nama Campaign <span class="text-red-500">*</span></span>
                </label>
                <input type="text" name="name" required placeholder="Contoh: Promo Ramadan 2025"
                       class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
              </div>

              <!-- Channel -->
              <div>
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                  <span>Channel <span class="text-red-500">*</span></span>
                </label>
                <select name="channel" id="bcChannel" required
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                  <option value="email">Email</option>
                  <option value="whatsapp">WhatsApp</option>
                </select>
              </div>

              <!-- Provider -->
              <div>
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                  <span>Provider <span class="text-red-500">*</span></span>
                </label>
                <select name="provider" id="bcProvider" required
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                  <!-- Diisi via JS sesuai channel -->
                  <?php foreach ($activeProviders['email'] as $p): ?>
                  <option value="<?= e($p['value']) ?>" data-channel="email"><?= e($p['label']) ?></option>
                  <?php endforeach; ?>
                  <?php if (empty($activeProviders['email'])): ?>
                  <option value="" disabled>— Belum ada provider email aktif —</option>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Subject (email only) -->
              <div class="md:col-span-2" id="subjectRow">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                  <span>Subject Email</span>
                </label>
                <input type="text" name="subject" placeholder="Contoh: Ada promo spesial untukmu!"
                       class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
              </div>

              <!-- Body -->
              <div class="md:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-1.5">
                  <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                    <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    <span>Pesan <span class="text-red-500">*</span></span>
                  </label>
                  <div id="htmlToggleWrap" class="hidden items-center gap-3">
                    <button type="button" id="bcPreviewBtn"
                            class="hidden text-xs font-semibold text-[var(--brand-color)] hover:underline">Preview</button>
                    <label class="inline-flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-400 cursor-pointer select-none">
                      <input type="checkbox" name="body_format" id="bcHtmlToggle" value="html"
                             class="w-3.5 h-3.5 rounded border-gray-300 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
                      Konten HTML (embed template)
                    </label>
                  </div>
                </div>
                <textarea name="body" id="bcBody" rows="5" required
                          placeholder="Halo {{name}}, ..."
                          class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] resize-y transition"></textarea>
                <p id="bcBodyHint" class="text-xs text-gray-400 mt-1">Placeholder tersedia: <code class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">{{name}}</code></p>
                <p id="bcHtmlHint" class="hidden text-xs text-gray-400 mt-1">Tempel <strong>HTML template email</strong> lengkap di sini — dikirim apa adanya. Gunakan <code class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">{{name}}</code> untuk personalisasi. Klik <strong>Preview</strong> untuk melihat hasil.</p>
              </div>

              <!-- Target Segment -->
              <div>
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                  <span>Target Segment <span class="text-red-500">*</span></span>
                </label>
                <select name="target_segment" id="bcSegment" required
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                  <optgroup label="User / Member">
                    <option value="all_users">Semua User</option>
                    <option value="active_members">Member Aktif</option>
                    <option value="specific_level">Level Spesifik</option>
                  </optgroup>
                  <optgroup label="Leads (Lead Magnet)">
                    <option value="leads_all">Semua Leads</option>
                    <option value="leads_by_magnet">Leads per Lead Magnet</option>
                    <option value="product_buyers">Pembeli Produk</option>
                  </optgroup>
                  <optgroup label="Engagement Email">
                    <option value="email_openers">Pernah buka email</option>
                    <option value="email_clickers">Pernah klik link email</option>
                  </optgroup>
                </select>
              </div>

              <!-- Rentang waktu engagement (email_openers / email_clickers) -->
              <div id="engagementRow" class="hidden">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <span>Rentang Waktu</span>
                </label>
                <select name="engagement_days" id="bcEngagementDays"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                  <option value="30">Buka/klik dalam 30 hari terakhir</option>
                  <option value="60">Buka/klik dalam 60 hari terakhir</option>
                  <option value="90">Buka/klik dalam 90 hari terakhir</option>
                  <option value="0">Kapan saja</option>
                </select>
                <p class="text-[11px] text-gray-400 mt-1">Hanya channel <strong>Email</strong>. Angka "buka" bisa menggelembung karena proteksi privasi Apple Mail — segmen <strong>klik</strong> lebih akurat.</p>
              </div>

              <!-- Membership Level (specific_level only) -->
              <div id="levelRow" class="hidden">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                  <span>Membership Level</span>
                </label>
                <select name="target_membership_level_id"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                  <option value="">— Pilih Level —</option>
                  <?php foreach ($membershipLevels as $level): ?>
                  <option value="<?= e($level['id']) ?>"><?= e($level['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Lead Magnet (leads_by_magnet only) -->
              <div id="leadMagnetRow" class="hidden">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8"/></svg>
                  <span>Lead Magnet</span>
                </label>
                <select name="target_lead_magnet_id"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                  <option value="">— Pilih Lead Magnet —</option>
                  <?php foreach ($leadMagnets as $lm): ?>
                  <option value="<?= (int) $lm['id'] ?>"><?= e($lm['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Target Pembeli Produk (segment=product_buyers) -->
              <div id="productBuyersRow" class="hidden md:col-span-2">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                  <span>Produk (yang membeli jadi target)</span>
                </label>
                <?php renderBroadcastProductPicker($products, 'target_products', 'Cari produk untuk ditarget...'); ?>
                <p class="text-[11px] text-gray-400 mt-1">Hanya user yang sudah membeli (order lunas) produk terpilih yang menerima broadcast. Berlaku untuk email & WhatsApp.</p>
              </div>

              <!-- Kecualikan Pembeli Produk (opsional) — hanya berlaku untuk segmen
                   berbasis akun user; disembunyikan utk segmen Leads (lihat applySegmentUI). -->
              <div id="excludeProductsRow" class="md:col-span-2">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64A9 9 0 1 1 5.64 6.64"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                  <span>Kecualikan Pembeli Produk <span class="text-gray-400 font-normal">(opsional)</span></span>
                </label>
                <?php renderBroadcastProductPicker($products, 'exclude_products', 'Cari produk untuk dikecualikan...'); ?>
                <p class="text-[11px] text-gray-400 mt-1">User yang sudah membeli produk terpilih tidak akan menerima broadcast ini (segmen user).</p>
              </div>

              <!-- Skip email gagal (hanya channel email) -->
              <div id="skipFailedRow">
                <label class="flex items-start gap-2 cursor-pointer">
                  <input type="checkbox" name="skip_failed_emails" id="bcSkipFailed" value="1" class="mt-0.5" style="accent-color:var(--brand-color)">
                  <span class="text-sm text-gray-700 dark:text-gray-300">Lewati email yang <strong>sebelumnya gagal / tidak aktif</strong>
                    <span class="block text-[11px] text-gray-400 font-normal">Berdasarkan riwayat kirim (broadcast &amp; notifikasi) yang berstatus gagal. Hemat kuota &amp; jaga reputasi pengirim. (Channel email)</span>
                  </span>
                </label>
              </div>

              <!-- Jadwalkan -->
              <div>
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                  <span>Kirim</span>
                </label>
                <select name="send_mode" id="bcSendMode"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                  <option value="now">Simpan sebagai Draft</option>
                  <option value="scheduled">Jadwalkan</option>
                </select>
              </div>

              <!-- Scheduled At -->
              <div id="scheduledAtRow" class="hidden">
                <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                  <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <span>Waktu Kirim</span>
                </label>
                <input type="datetime-local" name="scheduled_at"
                       class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
              </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-gray-100 dark:border-gray-800">
              <div class="flex items-center gap-2.5">
                <button type="button" id="bcEstimateBtn"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17v-6M12 17V7M15 17v-3"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                  Cek estimasi penerima
                </button>
                <span id="bcEstimateResult" class="text-xs text-gray-500 dark:text-gray-400"></span>
              </div>
              <div class="flex items-center gap-3">
                <button type="button" id="cancelCreate"
                        class="px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg hover:bg-gray-50 transition">
                  Batal
                </button>
                <button type="submit" id="bcSubmitBtn" data-loading-text="Menyimpan..."
                        class="px-5 py-2 text-sm font-semibold text-white rounded-lg transition-colors"
                        style="background-color:var(--brand-color)">
                  Simpan Campaign
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- ─── Tabel Campaigns ───────────────────────────────────── -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Semua Campaign</h3>
        </div>

        <?php if (empty($campaigns)): ?>
        <div class="px-5 py-12 text-center">
          <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
            <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z"/>
            </svg>
          </div>
          <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Belum ada campaign</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Buat campaign pertama untuk mulai kirim broadcast</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Nama</th>
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Channel</th>
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Target</th>
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Status</th>
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Penerima</th>
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Terkirim</th>
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Dibuka</th>
                <th class="text-left px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Diklik</th>
                <th class="text-right px-5 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
              <?php foreach ($campaigns as $c): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                <td class="px-5 py-3.5">
                  <p class="font-medium text-gray-900 dark:text-white"><?= e($c['name']) ?></p>
                  <p class="text-xs text-gray-400 mt-0.5"><?= e(date('d M Y H:i', strtotime($c['created_at']))) ?></p>
                </td>
                <td class="px-5 py-3.5">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $c['channel'] === 'email' ? 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-400' : 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' ?>">
                    <?= ucfirst(e($c['channel'])) ?>
                  </span>
                  <span class="text-xs text-gray-400 ml-1"><?= e($c['provider']) ?></span>
                </td>
                <td class="px-5 py-3.5 text-gray-500 dark:text-gray-400">
                  <?php if ($c['target_segment'] === 'specific_level'): ?>
                    <?= e($c['level_name'] ?? 'Level #' . $c['target_membership_level_id']) ?>
                  <?php elseif ($c['target_segment'] === 'leads_by_magnet'): ?>
                    <span class="inline-flex items-center gap-1">
                      <svg class="w-3.5 h-3.5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8"/><rect x="3" y="5" width="18" height="14" rx="2"/></svg>
                      <?= e($c['lead_magnet_title'] ?? 'Lead Magnet #' . $c['target_lead_magnet_id']) ?>
                    </span>
                  <?php else: ?>
                    <?= e($segmentLabels[$c['target_segment']] ?? $c['target_segment']) ?>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5">
                  <?= renderStatusBadge($c['status'], 'broadcast') ?>
                </td>
                <td class="px-5 py-3.5 text-gray-700 dark:text-gray-300"><?= number_format($c['total_recipients']) ?></td>
                <td class="px-5 py-3.5">
                  <?php if ($c['total_recipients'] > 0): ?>
                  <span class="text-green-600 dark:text-green-400"><?= number_format($c['total_sent']) ?></span>
                  <?php if ($c['total_failed'] > 0): ?>
                  <span class="text-red-400 text-xs ml-1">(<?= $c['total_failed'] ?> gagal)</span>
                  <?php endif; ?>
                  <?php else: ?>
                  <span class="text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <?php
                  $isHtmlEmail = ($c['channel'] ?? '') === 'email' && ($c['body_format'] ?? '') === 'html';
                  $sentN  = (int) $c['total_sent'];
                  $uOpen  = (int) ($c['uniq_opens'] ?? 0);
                  $uClick = (int) ($c['uniq_clicks'] ?? 0);
                  $trackable = $isHtmlEmail && $sentN > 0;
                ?>
                <td class="px-5 py-3.5">
                  <?php if ($trackable): ?>
                  <span class="text-gray-700 dark:text-gray-300 font-medium"><?= number_format($uOpen) ?></span>
                  <span class="text-xs text-gray-400 ml-1"><?= round($uOpen / $sentN * 100) ?>%</span>
                  <?php else: ?>
                  <span class="text-gray-400" title="Tracking hanya untuk broadcast email HTML">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5">
                  <?php if ($trackable): ?>
                  <span class="text-gray-700 dark:text-gray-300 font-medium"><?= number_format($uClick) ?></span>
                  <span class="text-xs text-gray-400 ml-1"><?= round($uClick / $sentN * 100) ?>%</span>
                  <?php else: ?>
                  <span class="text-gray-400" title="Tracking hanya untuk broadcast email HTML">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <div class="inline-flex items-center gap-2">
                    <?php if (in_array($c['status'], ['draft', 'scheduled', 'failed'])): ?>
                    <button type="button" data-bc-send="<?= (int)$c['id'] ?>" data-bc-name="<?= e($c['name']) ?>"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white rounded-lg transition-colors"
                            style="background-color:var(--brand-color)">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                      </svg>
                      Kirim
                    </button>
                    <?php elseif ($c['status'] === 'sending'): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/pause-broadcast" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="action" value="pause">
                      <button type="submit" title="Jeda pengiriman (bisa dilanjutkan)"
                              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
                        Jeda
                      </button>
                    </form>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/broadcast-finalize" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                      <button type="button"
                              onclick="confirmAndRun({title:'Tandai Selesai?',message:'Sisa penerima yang belum terkirim akan dihitung gagal, lalu campaign ditandai selesai.',confirmText:'Ya, Tandai Selesai',variant:'warning'}, () => this.closest('form').submit())"
                              class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        Tandai Selesai
                      </button>
                    </form>
                    <?php elseif ($c['status'] === 'paused'): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/pause-broadcast" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="action" value="resume">
                      <button type="submit" title="Lanjutkan pengiriman"
                              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white rounded-lg transition-colors"
                              style="background-color:var(--brand-color)">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Mulai Lagi
                      </button>
                    </form>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/broadcast-finalize" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                      <button type="button"
                              onclick="confirmAndRun({title:'Tandai Selesai?',message:'Sisa penerima yang belum terkirim akan dihitung gagal, lalu campaign ditandai selesai.',confirmText:'Ya, Tandai Selesai',variant:'warning'}, () => this.closest('form').submit())"
                              class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        Tandai Selesai
                      </button>
                    </form>
                    <?php elseif ($c['status'] === 'sent'): ?>
                    <span class="text-xs text-gray-400">Selesai <?= e(date('d M Y', strtotime($c['sent_at'] ?? $c['created_at']))) ?></span>
                    <?php endif; ?>

                    <?php if ($c['status'] === 'draft'): ?>
                    <button type="button" data-bc-preview="<?= (int)$c['id'] ?>" title="Preview isi pesan"
                            class="inline-flex items-center justify-center w-7 h-7 text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition-colors">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <button type="button" data-bc-edit="<?= (int)$c['id'] ?>" title="Edit draft"
                            class="inline-flex items-center justify-center w-7 h-7 text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition-colors">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <?php endif; ?>

                    <?php if ((int)$c['total_recipients'] > 0 || in_array($c['status'], ['sending', 'sent', 'failed'])): ?>
                    <button type="button" data-bc-detail="<?= (int)$c['id'] ?>" title="Lihat log penerima"
                            class="inline-flex items-center justify-center w-7 h-7 text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition-colors">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <?php endif; ?>

                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-broadcast" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                      <button type="button"
                              onclick="confirmAndRun({title:'Hapus Campaign?',message:'Campaign <?= e(addslashes($c['name'])) ?> beserta semua data penerimanya akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'}, () => this.closest('form').submit())"
                              class="inline-flex items-center justify-center w-7 h-7 text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                              title="Hapus campaign">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <polyline points="3 6 5 6 21 6"/><path stroke-linecap="round" stroke-linejoin="round" d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path stroke-linecap="round" stroke-linejoin="round" d="M10 11v6M14 11v6"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 6V4h6v2"/>
                        </svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<!-- ─── Modal Preview HTML Email ─────────────────────────────── -->
<div id="bcPreviewModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeBcPreview()"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl flex flex-col" style="max-height:90vh">
      <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800 shrink-0">
        <h3 class="text-sm font-bold text-gray-900 dark:text-white">Preview Email HTML</h3>
        <button type="button" onclick="closeBcPreview()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="p-4 overflow-auto bg-gray-100 dark:bg-gray-950">
        <iframe id="bcPreviewFrame" sandbox="allow-same-origin" class="w-full bg-white rounded-lg border border-gray-200 dark:border-gray-700" style="height:65vh"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- ─── Modal Detail Log Penerima ────────────────────────────── -->
<div id="bcDetailModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeBcDetail()"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl flex flex-col" style="max-height:85vh">
      <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800 shrink-0">
        <h3 id="bcDetailTitle" class="text-sm font-bold text-gray-900 dark:text-white truncate">Log Penerima</h3>
        <button type="button" onclick="closeBcDetail()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 shrink-0">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="px-5 py-2.5 border-b border-gray-100 dark:border-gray-800 shrink-0 flex items-center gap-2">
        <button type="button" id="bcDetailAll" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition">Semua</button>
        <button type="button" id="bcDetailFailed" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition">Gagal saja</button>
      </div>
      <div id="bcDetailList" class="overflow-y-auto divide-y divide-gray-50 dark:divide-gray-800"></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

<script>
function closeBcPreview() {
    document.getElementById('bcPreviewModal').classList.add('hidden');
}
(function () {
    var toggleBtn   = document.getElementById('toggleCreateForm');
    var formWrap    = document.getElementById('createFormWrap');
    var cancelBtn   = document.getElementById('cancelCreate');
    var bcForm      = document.getElementById('broadcastForm');
    var campaignIdInput = document.getElementById('bcCampaignId');
    var formTitle   = document.getElementById('bcFormTitle');
    var submitBtn   = document.getElementById('bcSubmitBtn');
    var channelSel  = document.getElementById('bcChannel');
    var providerSel = document.getElementById('bcProvider');
    var segmentSel  = document.getElementById('bcSegment');
    var sendModeSel = document.getElementById('bcSendMode');
    var levelRow      = document.getElementById('levelRow');
    var leadMagnetRow = document.getElementById('leadMagnetRow');
    var engagementRow = document.getElementById('engagementRow');
    var engagementSel = document.getElementById('bcEngagementDays');
    var subjectRow    = document.getElementById('subjectRow');
    var schedRow      = document.getElementById('scheduledAtRow');
    var htmlWrap      = document.getElementById('htmlToggleWrap');
    var htmlToggle    = document.getElementById('bcHtmlToggle');
    var bcBody        = document.getElementById('bcBody');
    var bcBodyHint    = document.getElementById('bcBodyHint');
    var bcHtmlHint    = document.getElementById('bcHtmlHint');
    var previewBtn    = document.getElementById('bcPreviewBtn');

    // Semua provider per channel
    var allProviders = {
        email:    <?= json_encode($activeProviders['email']) ?>,
        whatsapp: <?= json_encode($activeProviders['whatsapp']) ?>
    };

    // Data draft (untuk Edit + Preview). Flag JSON_HEX_* mencegah body HTML
    // memecah tag <script>.
    var bcEditData = <?= json_encode($bcEditData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Kembalikan form ke mode CREATE (dipakai saat buka form baru / batal).
    function resetToCreate() {
        if (bcForm) bcForm.reset();
        if (campaignIdInput) campaignIdInput.value = '';
        if (formTitle) formTitle.textContent = 'Campaign Baru';
        if (submitBtn) submitBtn.textContent = 'Simpan Campaign';
        updateProviders();
        levelRow.classList.add('hidden');
        leadMagnetRow.classList.add('hidden');
        var pbRow = document.getElementById('productBuyersRow');
        if (pbRow) pbRow.classList.add('hidden');
        if (engagementRow) engagementRow.classList.add('hidden');
        // Segmen default form baru = all_users (segmen user) → blok exclude tampil.
        var exRow = document.getElementById('excludeProductsRow');
        if (exRow) exRow.classList.remove('hidden');
        applyChannelLock(false);
        schedRow.classList.add('hidden');
        var er = document.getElementById('bcEstimateResult');
        if (er) er.textContent = '';
        resetPickerUI();
    }

    function setChecks(name, ids) {
        var set = {};
        (ids || []).forEach(function (v) { set[parseInt(v, 10)] = 1; });
        bcForm.querySelectorAll('input[name="' + name + '[]"]').forEach(function (cb) {
            cb.checked = !!set[parseInt(cb.value, 10)];
        });
    }

    // Isi form dari data draft → mode EDIT.
    function fillFormForEdit(id) {
        var d = bcEditData[id];
        if (!d) return;
        bcForm.reset();
        campaignIdInput.value = id;
        if (formTitle) formTitle.textContent = 'Edit Campaign (Draft)';
        if (submitBtn) submitBtn.textContent = 'Perbarui Campaign';

        var nameEl = bcForm.querySelector('[name="name"]');
        if (nameEl) nameEl.value = d.name || '';

        channelSel.value = d.channel || 'email';
        updateProviders();                 // bangun opsi provider + visibilitas subject/HTML
        providerSel.value = d.provider || '';

        var subjEl = bcForm.querySelector('[name="subject"]');
        if (subjEl) subjEl.value = d.subject || '';

        htmlToggle.checked = (d.channel === 'email' && d.body_format === 'html');
        applyHtmlMode();
        bcBody.value = d.body || '';

        segmentSel.value = d.target_segment || 'all_users';
        segmentSel.dispatchEvent(new Event('change'));

        if (engagementSel) engagementSel.value = (d.engagement_days == null || d.engagement_days === '' ? '0' : String(d.engagement_days));

        var lvlEl = bcForm.querySelector('[name="target_membership_level_id"]');
        if (lvlEl) lvlEl.value = d.target_membership_level_id || '';
        var lmEl = bcForm.querySelector('[name="target_lead_magnet_id"]');
        if (lmEl) lmEl.value = d.target_lead_magnet_id || '';

        setChecks('target_products', d.target_products);
        setChecks('exclude_products', d.exclude_products);
        refreshAllPickerCounts();

        var sf = document.getElementById('bcSkipFailed');
        if (sf) sf.checked = !!d.skip_failed_emails;

        if (d.scheduled_at) {
            sendModeSel.value = 'scheduled';
            schedRow.classList.remove('hidden');
            var dtEl = bcForm.querySelector('[name="scheduled_at"]');
            if (dtEl) dtEl.value = String(d.scheduled_at).replace(' ', 'T').slice(0, 16);
        } else {
            sendModeSel.value = 'now';
            schedRow.classList.add('hidden');
        }

        formWrap.classList.remove('hidden');
        formWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function renderInPreviewFrame(html) {
        document.getElementById('bcPreviewModal').classList.remove('hidden');
        var frame = document.getElementById('bcPreviewFrame');
        try {
            var doc = frame.contentDocument || frame.contentWindow.document;
            doc.open(); doc.write(html); doc.close();
        } catch (e) {
            frame.srcdoc = html;
        }
    }

    // Preview isi draft. HTML → render apa adanya; teks → escape + jaga baris.
    function previewDraft(id) {
        var d = bcEditData[id];
        if (!d) return;
        var raw = (d.body || '').replace(/\{\{name\}\}/g, 'Nama Pelanggan');
        var content;
        if (raw.trim() === '') {
            content = '<p style="font-family:sans-serif;color:#94a3b8;padding:24px">Body masih kosong.</p>';
        } else if (d.body_format === 'html') {
            content = raw;
        } else {
            content = '<pre style="font-family:sans-serif;white-space:pre-wrap;word-wrap:break-word;padding:24px;margin:0;color:#0f172a;font-size:14px;line-height:1.6">'
                    + escapeHtml(raw) + '</pre>';
        }
        renderInPreviewFrame(content);
    }

    function toggleForm() {
        if (formWrap.classList.contains('hidden')) {
            resetToCreate();
            formWrap.classList.remove('hidden');
            formWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            formWrap.classList.add('hidden');
        }
    }

    if (toggleBtn) toggleBtn.addEventListener('click', toggleForm);
    if (cancelBtn) cancelBtn.addEventListener('click', function () { formWrap.classList.add('hidden'); resetToCreate(); });

    document.querySelectorAll('[data-bc-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () { fillFormForEdit(parseInt(this.getAttribute('data-bc-edit'), 10)); });
    });
    document.querySelectorAll('[data-bc-preview]').forEach(function (btn) {
        btn.addEventListener('click', function () { previewDraft(parseInt(this.getAttribute('data-bc-preview'), 10)); });
    });

    function updateProviders() {
        var channel = channelSel.value;
        var providers = allProviders[channel] || [];
        providerSel.innerHTML = '';
        if (providers.length === 0) {
            providerSel.innerHTML = '<option value="" disabled>— Belum ada provider aktif —</option>';
        } else {
            providers.forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = p.value;
                opt.textContent = p.label;
                providerSel.appendChild(opt);
            });
        }
        var isEmail = channel === 'email';
        subjectRow.style.display = isEmail ? '' : 'none';
        // Toggle "Konten HTML" hanya untuk email
        htmlWrap.classList.toggle('hidden', !isEmail);
        htmlWrap.classList.toggle('flex', isEmail);
        if (!isEmail && htmlToggle.checked) { htmlToggle.checked = false; }
        // "Skip email gagal" khusus email → sembunyikan & uncheck saat WhatsApp.
        var skipFailedRow = document.getElementById('skipFailedRow');
        if (skipFailedRow) {
            skipFailedRow.classList.toggle('hidden', !isEmail);
            if (!isEmail) { var sf = document.getElementById('bcSkipFailed'); if (sf) sf.checked = false; }
        }
        applyHtmlMode();
    }

    function applyHtmlMode() {
        var on = htmlToggle.checked && channelSel.value === 'email';
        previewBtn.classList.toggle('hidden', !on);
        bcHtmlHint.classList.toggle('hidden', !on);
        bcBodyHint.classList.toggle('hidden', on);
        bcBody.classList.toggle('font-mono', on);
        bcBody.classList.toggle('text-xs', on);
        bcBody.rows = on ? 14 : 5;
        bcBody.placeholder = on
            ? '<!DOCTYPE html><html><body>... template email kamu, pakai {{name}} untuk personalisasi ...</body></html>'
            : 'Halo {{name}}, ...';
    }

    channelSel.addEventListener('change', updateProviders);
    updateProviders();

    function isEngSeg(v) { return v === 'email_openers' || v === 'email_clickers'; }

    // Segmen engagement email → paksa channel Email + matikan opsi WhatsApp.
    function applyChannelLock(lock) {
        var waOpt = channelSel.querySelector('option[value="whatsapp"]');
        if (lock) {
            if (channelSel.value !== 'email') { channelSel.value = 'email'; updateProviders(); }
            if (waOpt) waOpt.disabled = true;
            channelSel.title = 'Segmen engagement email hanya untuk channel Email';
        } else {
            if (waOpt) waOpt.disabled = false;
            channelSel.title = '';
        }
    }

    function applySegmentUI() {
        var v = segmentSel.value;
        levelRow.classList.toggle('hidden', v !== 'specific_level');
        leadMagnetRow.classList.toggle('hidden', v !== 'leads_by_magnet');
        var pbRow = document.getElementById('productBuyersRow');
        if (pbRow) pbRow.classList.toggle('hidden', v !== 'product_buyers');
        var eng = isEngSeg(v);
        if (engagementRow) engagementRow.classList.toggle('hidden', !eng);
        // "Kecualikan Pembeli Produk" berbasis user_id → tak berlaku utk segmen Leads
        // (recipient lead dikenali via email/WA, bukan akun). Sembunyikan agar admin
        // tak mengira pembeli tersaring padahal tidak. Pilihan tetap tersimpan bila
        // admin beralih kembali ke segmen user.
        var exRow = document.getElementById('excludeProductsRow');
        if (exRow) exRow.classList.toggle('hidden', v === 'leads_all' || v === 'leads_by_magnet');
        applyChannelLock(eng);
    }

    segmentSel.addEventListener('change', applySegmentUI);

    sendModeSel.addEventListener('change', function () {
        schedRow.classList.toggle('hidden', this.value !== 'scheduled');
    });

    htmlToggle.addEventListener('change', applyHtmlMode);

    previewBtn.addEventListener('click', function () {
        var html = (bcBody.value || '').replace(/\{\{name\}\}/g, 'Nama Pelanggan');
        if (html.trim() === '') html = '<p style="font-family:sans-serif;color:#94a3b8;padding:24px">Body masih kosong.</p>';
        document.getElementById('bcPreviewModal').classList.remove('hidden');
        var frame = document.getElementById('bcPreviewFrame');
        try {
            var doc = frame.contentDocument || frame.contentWindow.document;
            doc.open(); doc.write(html); doc.close();
        } catch (e) {
            frame.srcdoc = html;
        }
    });

    // ── Estimasi jumlah penerima (memakai resolver asli di server) ──
    var estimateBtn    = document.getElementById('bcEstimateBtn');
    var estimateResult = document.getElementById('bcEstimateResult');
    if (estimateBtn) estimateBtn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('csrf_token', '<?= e($_SESSION['csrf_token']) ?>');
        fd.append('channel', channelSel.value);
        fd.append('target_segment', segmentSel.value);
        var lvl = bcForm.querySelector('[name="target_membership_level_id"]');
        if (lvl) fd.append('target_membership_level_id', lvl.value || '');
        var lm = bcForm.querySelector('[name="target_lead_magnet_id"]');
        if (lm) fd.append('target_lead_magnet_id', lm.value || '');
        if (engagementSel) fd.append('engagement_days', engagementSel.value || '0');
        var sf = document.getElementById('bcSkipFailed');
        if (sf && sf.checked) fd.append('skip_failed_emails', '1');
        bcForm.querySelectorAll('input[name="target_products[]"]:checked').forEach(function (cb) { fd.append('target_products[]', cb.value); });
        bcForm.querySelectorAll('input[name="exclude_products[]"]:checked').forEach(function (cb) { fd.append('exclude_products[]', cb.value); });

        estimateResult.className = 'text-xs text-gray-400';
        estimateResult.textContent = 'Menghitung…';
        estimateBtn.disabled = true;
        fetch('<?= e(rtrim(APP_URL, "/")) ?>/actions/admin/broadcast-estimate', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                estimateBtn.disabled = false;
                if (d.error) { estimateResult.className = 'text-xs text-red-500'; estimateResult.textContent = d.error; return; }
                var valid = d.valid || 0, skipped = (d.total || 0) - valid;
                estimateResult.className = 'text-xs text-gray-600 dark:text-gray-300';
                estimateResult.innerHTML = 'Perkiraan <strong>' + valid.toLocaleString('id-ID') + '</strong> penerima valid'
                    + (skipped > 0 ? ' <span class="text-gray-400">(' + skipped.toLocaleString('id-ID') + ' dilewati / tanpa kontak)</span>' : '');
            })
            .catch(function () { estimateBtn.disabled = false; estimateResult.className = 'text-xs text-red-500'; estimateResult.textContent = 'Gagal menghitung.'; });
    });

    // ── Pemilih produk: pencarian + Semua/Kosongkan + penghitung ──
    function pickerUpdateCount(picker) {
        var el = picker.querySelector('[data-pp-count]');
        if (el) el.textContent = picker.querySelectorAll('input[type="checkbox"]:checked').length;
    }
    function refreshAllPickerCounts() {
        document.querySelectorAll('[data-product-picker]').forEach(pickerUpdateCount);
    }
    function resetPickerUI() {
        document.querySelectorAll('[data-product-picker]').forEach(function (p) {
            var s = p.querySelector('[data-pp-search]'); if (s) s.value = '';
            p.querySelectorAll('[data-pp-item]').forEach(function (it) { it.hidden = false; });
            var em = p.querySelector('[data-pp-empty]'); if (em) em.hidden = true;
            pickerUpdateCount(p);
        });
    }
    document.querySelectorAll('[data-product-picker]').forEach(function (picker) {
        var search = picker.querySelector('[data-pp-search]');
        var items  = Array.prototype.slice.call(picker.querySelectorAll('[data-pp-item]'));
        var empty  = picker.querySelector('[data-pp-empty]');
        if (search) search.addEventListener('input', function () {
            var q = (search.value || '').trim().toLowerCase(), shown = 0;
            items.forEach(function (it) {
                var ok = q === '' || (it.getAttribute('data-pp-name') || '').indexOf(q) !== -1;
                it.hidden = !ok; if (ok) shown++;
            });
            if (empty) empty.hidden = shown !== 0;
        });
        var allBtn = picker.querySelector('[data-pp-all]');
        var noneBtn = picker.querySelector('[data-pp-none]');
        if (allBtn) allBtn.addEventListener('click', function () {
            items.forEach(function (it) { if (!it.hidden) { var cb = it.querySelector('input[type="checkbox"]'); if (cb) cb.checked = true; } });
            pickerUpdateCount(picker);
        });
        if (noneBtn) noneBtn.addEventListener('click', function () {
            picker.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
            pickerUpdateCount(picker);
        });
        picker.addEventListener('change', function () { pickerUpdateCount(picker); });
        pickerUpdateCount(picker);
    });
})();
</script>

<!-- ─── Broadcast: Kirim via SSE + Detail Log ─────────────────── -->
<script>
(function () {
    var APP  = '<?= e(rtrim(APP_URL, '/')) ?>';
    var CSRF = '<?= e($_SESSION['csrf_token']) ?>';

    var panel    = document.getElementById('bcProgressPanel');
    var bar      = document.getElementById('bcProgressBar');
    var pct      = document.getElementById('bcProgressPct');
    var dot      = document.getElementById('bcProgressDot');
    var doneIcon = document.getElementById('bcProgressDoneIcon');
    var errIcon  = document.getElementById('bcProgressErrIcon');
    var textEl   = document.getElementById('bcProgressText');
    var curEl    = document.getElementById('bcCurrentRecipient');
    var counter  = document.getElementById('bcCounter');
    var failC    = document.getElementById('bcFailCounter');
    var doneBox  = document.getElementById('bcProgressDone');
    var summary  = document.getElementById('bcSummary');
    var errEl    = document.getElementById('bcProgressError');
    var viewLog  = document.getElementById('bcViewLog');
    var closeBtn = document.getElementById('bcCloseProgress');

    var lastId = null, failed = 0, finished = false;

    function escapeHtml(s) {
        return (s || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // state: 'running' | 'done' | 'error' — tampilkan ikon SVG sesuai status.
    function setState(state, label) {
        dot.classList.toggle('hidden', state !== 'running');
        doneIcon.classList.toggle('hidden', state !== 'done');
        errIcon.classList.toggle('hidden', state !== 'error');
        textEl.textContent = label;
    }

    function resetPanel(total) {
        failed = 0; finished = false;
        bar.style.width = '0%'; pct.textContent = '0%';
        curEl.textContent = '—';
        counter.textContent = '0 dari ' + total;
        failC.textContent = '0 gagal';
        setState('running', 'Broadcast sedang berjalan…');
        doneBox.classList.add('hidden');
        errEl.classList.add('hidden');
        panel.classList.remove('hidden');
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function showError(msg) {
        errEl.textContent = msg;
        errEl.classList.remove('hidden');
        setState('error', 'Broadcast terhenti');
    }

    function finish(d) {
        finished = true;
        bar.style.width = '100%'; pct.textContent = '100%';
        setState('done', 'Broadcast selesai!');
        summary.innerHTML = '<strong class="text-green-600 dark:text-green-400">' + d.sent + ' terkirim</strong>' +
            (d.failed > 0 ? ' · <strong class="text-red-500">' + d.failed + ' gagal</strong>' : '');
        doneBox.classList.remove('hidden');
        if (d.campaign_id) lastId = d.campaign_id;
    }

    // ── Pump loop: proses batch berulang via /broadcast-pump (realtime + resumable).
    //    Pengiriman TETAP jalan di server (cron + pump global) walau tab ini ditutup.
    var pumpTimer = null, pumping = false;
    function stopPump() { if (pumpTimer) { clearTimeout(pumpTimer); pumpTimer = null; } pumping = false; }
    function pumpLoop(id) {
        stopPump(); pumping = true; lastId = id; finished = false;
        (function tick() {
            if (!pumping) return;
            var fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('campaign_id', id);
            fd.append('limit', '6'); // batch kecil → request pendek (sesi tak lama tertahan)
            fetch(APP + '/actions/admin/broadcast-pump', {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!pumping) return;
                if (d.success === false) { showError(d.message || 'Gagal memproses pengiriman.'); stopPump(); return; }
                var total = d.total || 0;
                var done  = (d.sent || 0) + (d.failed || 0);
                bar.style.width = (d.percent || 0) + '%';
                pct.textContent = (d.percent || 0) + '%';
                curEl.textContent = d.last || '—';
                counter.textContent = done + ' dari ' + total;
                failC.textContent = (d.failed || 0) + ' gagal';
                if (d.status === 'paused') { showPausedPanel(total, d.sent || 0, d.failed || 0); return; }
                if (d.status === 'sent' || (total > 0 && (d.pending || 0) === 0)) {
                    finish({ sent: d.sent || 0, failed: d.failed || 0, campaign_id: id });
                    stopPump(); return;
                }
                if (d.status === 'failed') { showError('Broadcast gagal — cek provider/log.'); stopPump(); return; }
                pumpTimer = setTimeout(tick, 500);
            })
            .catch(function () {
                // Error jaringan sesaat → coba lagi. Pengiriman di server tak terpengaruh.
                if (pumping) pumpTimer = setTimeout(tick, 2500);
            });
        })();
    }

    var startingCampaigns = Object.create(null);
    function startBroadcast(id) {
        if (startingCampaigns[id]) return;
        startingCampaigns[id] = true;
        var btn = document.querySelector('[data-bc-send="' + id + '"]');
        var originalLabel = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.textContent = 'Memulai…';
        }
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('campaign_id', id);
        fetch(APP + '/actions/admin/send-broadcast', {
            method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                delete startingCampaigns[id];
                if (btn) {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btn.innerHTML = originalLabel;
                }
                showToast(data.message || 'Gagal memulai broadcast.', 'error');
                return;
            }
            // Ambil ulang status dan aksi dari server: Draft/Kirim menjadi Mengirim/Jeda.
            // Halaman baru otomatis melanjutkan pump campaign yang sedang berjalan.
            window.location.reload();
        })
        .catch(function () {
            // Respons hilang belum berarti gagal: server mungkin sudah memulai campaign.
            // Periksa status dahulu agar klik berikutnya tidak memulai pengiriman ulang.
            if (btn) {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
                btn.setAttribute('data-bc-check-status', '1');
                btn.textContent = 'Periksa Status';
            }
            showToast('Status pengiriman belum dapat dipastikan. Klik Periksa Status untuk memuat ulang campaign.', 'warning');
        });
    }

    document.querySelectorAll('[data-bc-send]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (this.hasAttribute('data-bc-check-status')) { window.location.reload(); return; }
            var id = this.getAttribute('data-bc-send');
            if (startingCampaigns[id]) return;
            var nm = this.getAttribute('data-bc-name') || '';
            confirmAndRun({
                title:       'Kirim Broadcast?',
                message:     'Broadcast "' + nm + '" akan langsung dikirim ke semua penerima pada segmen yang dipilih.',
                confirmText: 'Ya, Kirim Sekarang',
                variant:     'warning'
            }, function () { startBroadcast(id); });
        });
    });

    document.querySelectorAll('[data-bc-resume]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-bc-resume');
            lastId = id;
            resetPanel(parseInt(this.getAttribute('data-bc-total') || '0', 10) || 0);
            pumpLoop(id); // lanjut proses recipient yang masih pending
        });
    });

    // Panel dijeda (frozen, tanpa pump) — dipakai saat status 'paused'.
    function showPausedPanel(total, sent, failed) {
        stopPump(); finished = true;
        var done = (sent || 0) + (failed || 0);
        var pctv = total > 0 ? Math.round(done / total * 100) : 0;
        bar.style.width = pctv + '%'; pct.textContent = pctv + '%';
        curEl.textContent = '—';
        counter.textContent = done + ' dari ' + total;
        failC.textContent = (failed || 0) + ' gagal';
        setState('error', 'Broadcast dijeda — klik "Mulai Lagi" untuk melanjutkan');
        errIcon.className = errIcon.className; // no-op (ikon jeda pakai state error/oranye)
        doneBox.classList.add('hidden'); errEl.classList.add('hidden');
        panel.classList.remove('hidden');
    }

    // Auto-resume: bila ada broadcast aktif saat halaman dibuka — 'sending' → tampilkan
    // progress & lanjutkan pump; 'paused' → tampilkan panel dijeda (tanpa pump).
<?php if ($sendingCampaign): ?>
  <?php if (($sendingCampaign['status'] ?? '') === 'sending'): ?>
    resetPanel(<?= (int) $sendingCampaign['total_recipients'] ?>);
    pumpLoop('<?= (int) $sendingCampaign['id'] ?>');
  <?php else: ?>
    lastId = '<?= (int) $sendingCampaign['id'] ?>';
    showPausedPanel(<?= (int) $sendingCampaign['total_recipients'] ?>, <?= (int) $sendingCampaign['total_sent'] ?>, <?= (int) $sendingCampaign['total_failed'] ?>);
  <?php endif; ?>
<?php endif; ?>

    if (closeBtn) closeBtn.addEventListener('click', function () { location.reload(); });
    if (viewLog)  viewLog.addEventListener('click', function () { if (lastId) openDetail(lastId); });

    // ── Modal Detail Log ──
    var detailModal = document.getElementById('bcDetailModal');
    var detailList  = document.getElementById('bcDetailList');
    var detailTitle = document.getElementById('bcDetailTitle');
    var tabAll      = document.getElementById('bcDetailAll');
    var tabFailed   = document.getElementById('bcDetailFailed');
    var detailData  = [], detailFilter = 'all';

    function setTab(active) {
        [tabAll, tabFailed].forEach(function (t) {
            t.classList.remove('text-white');
            t.style.backgroundColor = '';
            t.classList.add('text-gray-600', 'dark:text-gray-300', 'border', 'border-gray-200', 'dark:border-gray-700');
        });
        active.classList.add('text-white');
        active.classList.remove('text-gray-600', 'dark:text-gray-300', 'border', 'border-gray-200', 'dark:border-gray-700');
        active.style.backgroundColor = 'var(--brand-color)';
    }

    function statusIcon(status) {
        var cls = 'inline-block w-3.5 h-3.5 mr-1 align-[-2px] ';
        if (status === 'sent') {
            return '<svg class="' + cls + 'text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
        }
        if (status === 'failed') {
            return '<svg class="' + cls + 'text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        }
        return '<svg class="' + cls + 'text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>';
    }

    function renderDetail() {
        var rows = detailData.filter(function (r) { return detailFilter === 'all' || r.status === 'failed'; });
        if (!rows.length) {
            detailList.innerHTML = '<p class="text-sm text-gray-400 py-8 text-center">Tidak ada data.</p>';
            return;
        }
        detailList.innerHTML = rows.map(function (r) {
            var color = r.status === 'sent' ? 'text-green-600 dark:text-green-400'
                      : (r.status === 'failed' ? 'text-red-500' : 'text-gray-400');
            return '<div class="flex items-start justify-between gap-3 px-5 py-2.5">'
                + '<div class="min-w-0">'
                + '<p class="text-sm text-gray-800 dark:text-gray-200 truncate">' + statusIcon(r.status) + escapeHtml(r.recipient || '—') + '</p>'
                + (r.name ? '<p class="text-xs text-gray-400">' + escapeHtml(r.name) + '</p>' : '')
                + (r.error_message ? '<p class="text-xs text-red-400 mt-0.5">' + escapeHtml(r.error_message) + '</p>' : '')
                + '</div>'
                + '<span class="text-xs font-medium ' + color + ' shrink-0 capitalize">' + escapeHtml(r.status) + '</span>'
                + '</div>';
        }).join('');
    }

    window.openDetail = function (id) {
        detailFilter = 'all';
        detailList.innerHTML = '<p class="text-sm text-gray-400 py-8 text-center">Memuat…</p>';
        detailTitle.textContent = 'Log Penerima';
        setTab(tabAll);
        detailModal.classList.remove('hidden');
        fetch(APP + '/actions/admin/broadcast-recipients?id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    detailList.innerHTML = '<p class="text-sm text-red-500 py-8 text-center">' + escapeHtml(data.message || 'Gagal memuat.') + '</p>';
                    return;
                }
                detailData = data.recipients || [];
                if (data.campaign) {
                    detailTitle.textContent = 'Log: ' + data.campaign.name + ' (' + data.campaign.total_sent + ' terkirim, ' + data.campaign.total_failed + ' gagal)';
                }
                renderDetail();
            })
            .catch(function (e) {
                detailList.innerHTML = '<p class="text-sm text-red-500 py-8 text-center">Gagal memuat: ' + escapeHtml(e.message) + '</p>';
            });
    };
    window.closeBcDetail = function () { detailModal.classList.add('hidden'); };

    tabAll.addEventListener('click', function () { detailFilter = 'all'; setTab(tabAll); renderDetail(); });
    tabFailed.addEventListener('click', function () { detailFilter = 'failed'; setTab(tabFailed); renderDetail(); });

    document.querySelectorAll('[data-bc-detail]').forEach(function (btn) {
        btn.addEventListener('click', function () { openDetail(this.getAttribute('data-bc-detail')); });
    });
})();
</script>
