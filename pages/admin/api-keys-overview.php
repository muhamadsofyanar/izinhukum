<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'API Keys Overview';
$topbarTitle = 'API Keys Overview';

$pdo = getDB();

// ─── Definisi semua integrasi ────────────────────────────────
$integrations = [
    // Payment Gateways
    ['name' => 'Duitku',      'type' => 'Payment',   'sql' => "SELECT is_active FROM payment_gateways WHERE provider='duitku' LIMIT 1",  'link' => '/admin/integrations'],
    ['name' => 'Xendit',      'type' => 'Payment',   'sql' => "SELECT is_active FROM payment_gateways WHERE provider='xendit' LIMIT 1",  'link' => '/admin/integrations'],
    ['name' => 'Moota',       'type' => 'Payment',   'sql' => "SELECT is_active FROM payment_gateways WHERE provider='moota' LIMIT 1",   'link' => '/admin/integrations'],
    // Email
    ['name' => 'Mailketing',  'type' => 'Email',     'sql' => "SELECT IF(setting_value != '', 1, 0) AS is_active FROM settings WHERE setting_key='mailketing_api_key' LIMIT 1", 'link' => '/admin/notifications'],
    // WhatsApp
    ['name' => 'Fonte',       'type' => 'WhatsApp',  'sql' => "SELECT IF(setting_value != '', 1, 0) AS is_active FROM settings WHERE setting_key='fonte_api_key' LIMIT 1",      'link' => '/admin/integrations'],
    ['name' => 'OneSender',   'type' => 'WhatsApp',  'sql' => "SELECT IF(setting_value != '', 1, 0) AS is_active FROM settings WHERE setting_key='onesender_api_key' LIMIT 1",  'link' => '/admin/integrations'],
    ['name' => 'StarSender',  'type' => 'WhatsApp',  'sql' => "SELECT IF(setting_value != '', 1, 0) AS is_active FROM settings WHERE setting_key='starsender_api_key' LIMIT 1", 'link' => '/admin/integrations'],
    ['name' => 'DripSender',  'type' => 'WhatsApp',  'sql' => "SELECT IF(setting_value != '', 1, 0) AS is_active FROM settings WHERE setting_key='dripsender_api_key' LIMIT 1", 'link' => '/admin/integrations'],
    ['name' => 'Kirimdev',    'type' => 'WhatsApp',  'sql' => "SELECT IF(setting_value != '', 1, 0) AS is_active FROM settings WHERE setting_key='kirimdev_api_key' LIMIT 1",   'link' => '/admin/integrations'],
    // Tracking. CATATAN: Meta Pixel & Meta CAPI kini disimpan sebagai SETTING
    // (meta_pixel_enabled / meta_capi_enabled — mendukung multi-pixel), BUKAN lagi
    // di tabel tracking_pixels (yang tinggal legacy + dipakai TikTok). Membaca
    // status Meta dari tabel lama = keliru/usang → baca dari settings.
    ['name' => 'Meta Pixel',  'type' => 'Tracking',  'sql' => "SELECT IF(setting_value='1',1,0) AS is_active FROM settings WHERE setting_key='meta_pixel_enabled' LIMIT 1", 'link' => '/admin/tracking-pixels'],
    ['name' => 'Meta CAPI',   'type' => 'Tracking',  'sql' => "SELECT IF(setting_value='1',1,0) AS is_active FROM settings WHERE setting_key='meta_capi_enabled' LIMIT 1",  'link' => '/admin/tracking-pixels'],
    ['name' => 'TikTok Pixel','type' => 'Tracking',  'sql' => "SELECT is_active FROM tracking_pixels WHERE platform='tiktok_pixel' LIMIT 1", 'link' => '/admin/tracking-pixels'],
    ['name' => 'TikTok CAPI', 'type' => 'Tracking',  'sql' => "SELECT is_active FROM tracking_pixels WHERE platform='tiktok_capi' LIMIT 1",  'link' => '/admin/tracking-pixels'],
];

// ─── Jalankan setiap query status ────────────────────────────
foreach ($integrations as &$item) {
    try {
        $row = $pdo->query($item['sql'])->fetch(PDO::FETCH_ASSOC);
        $item['active'] = $row && (int)($row['is_active'] ?? 0) === 1;
        // Query berhasil → tabel ADA. Baris kosong = belum dikonfigurasi (Belum Diset),
        // BUKAN "tabel tidak ada". "Tabel tidak ada" hanya benar saat query melempar
        // (tabel/kolom benar-benar hilang) — ditangani di catch.
        $item['found']  = true;
    } catch (Exception $e) {
        $item['active'] = false;
        $item['found']  = false;
    }
}
unset($item);

// ─── Group by type ────────────────────────────────────────────
$grouped = [];
foreach ($integrations as $item) {
    $grouped[$item['type']][] = $item;
}

// ─── Statistik ringkasan ──────────────────────────────────────
$totalActive   = count(array_filter($integrations, fn($i) => $i['active']));
$totalInactive = count($integrations) - $totalActive;

// ─── Log terbaru (integration_logs + payment_transaction_logs) ─
$recentLogs = [];
try {
    $recentLogs = $pdo->query(
        "SELECT 'integration' AS log_type, provider, action AS event_type, status, created_at
         FROM integration_logs
         UNION ALL
         SELECT 'payment'     AS log_type, gateway_provider AS provider, event_type, status, created_at
         FROM payment_transaction_logs
         ORDER BY created_at DESC
         LIMIT 10"
    )->fetchAll();
} catch (Exception $e) {
    error_log('API Keys Overview logs error: ' . $e->getMessage());
}

// ─── Ikon dan warna per type ──────────────────────────────────
$typeConfig = [
    'Payment'  => ['bg' => 'bg-blue-50 dark:bg-blue-900/20',   'icon_color' => 'text-blue-600 dark:text-blue-400',   'label_bg' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'],
    'Email'    => ['bg' => 'bg-purple-50 dark:bg-purple-900/20','icon_color' => 'text-purple-600 dark:text-purple-400','label_bg' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300'],
    'WhatsApp' => ['bg' => 'bg-green-50 dark:bg-green-900/20',  'icon_color' => 'text-green-600 dark:text-green-400',  'label_bg' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'],
    'Tracking' => ['bg' => 'bg-orange-50 dark:bg-orange-900/20','icon_color' => 'text-orange-600 dark:text-orange-400','label_bg' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300'],
];
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
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">API Keys Overview</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Status semua integrasi dalam satu tampilan</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-1.5 px-3 py-1.5 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
            <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
            <span class="text-xs font-medium text-green-700 dark:text-green-400"><?= $totalActive ?> Aktif</span>
          </div>
          <div class="flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <span class="w-2 h-2 rounded-full bg-gray-400 shrink-0"></span>
            <span class="text-xs font-medium text-gray-600 dark:text-gray-400"><?= $totalInactive ?> Belum Diset</span>
          </div>
        </div>
      </div>

      <!-- Integration Groups -->
      <div class="space-y-5 mb-6">
        <?php foreach ($grouped as $typeName => $items):
            $tc = $typeConfig[$typeName] ?? $typeConfig['Tracking'];
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <!-- Group header -->
          <div class="flex items-center gap-3 px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $tc['label_bg'] ?>">
              <?= e($typeName) ?>
            </span>
            <span class="text-xs text-gray-400 dark:text-gray-500">
              <?= count(array_filter($items, fn($i) => $i['active'])) ?>/<?= count($items) ?> aktif
            </span>
          </div>
          <!-- Items -->
          <div class="divide-y divide-gray-50 dark:divide-gray-800">
            <?php foreach ($items as $item): ?>
            <div class="flex items-center justify-between px-5 py-3.5">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg <?= $tc['bg'] ?> flex items-center justify-center shrink-0">
                  <svg class="w-4 h-4 <?= $tc['icon_color'] ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <?php if ($typeName === 'Payment'): ?>
                    <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
                    <?php elseif ($typeName === 'Email'): ?>
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                    <?php elseif ($typeName === 'WhatsApp'): ?>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 8h2a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2h-2v4l-4-4H9a1.994 1.994 0 0 1-1.414-.586m0 0L11 14h4a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2v4l.586-.586z"/>
                    <?php else: ?>
                    <circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h3M18 12h3M12 3v3M12 18v3"/>
                    <?php endif; ?>
                  </svg>
                </div>
                <span class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= e($item['name']) ?></span>
              </div>
              <div class="flex items-center gap-3">
                <?php if (!$item['found']): ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400">
                  <span class="w-1.5 h-1.5 rounded-full bg-yellow-500"></span>
                  Tabel tidak ada
                </span>
                <?php elseif ($item['active']): ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400">
                  <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                  Aktif
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                  <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                  Belum Diset
                </span>
                <?php endif; ?>
                <a href="<?= e(APP_URL) ?><?= e($item['link']) ?>"
                   class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 transition-colors">
                  Kelola
                  <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/>
                  </svg>
                </a>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Recent Logs -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Log Aktivitas Integrasi Terbaru</h3>
          <span class="text-xs text-gray-400">10 terakhir</span>
        </div>
        <?php if (empty($recentLogs)): ?>
        <div class="px-5 py-8 text-center text-sm text-gray-400 dark:text-gray-500">Belum ada aktivitas tercatat.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <th class="text-left px-5 py-2.5 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Tipe</th>
                <th class="text-left px-5 py-2.5 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Provider</th>
                <th class="text-left px-5 py-2.5 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Event</th>
                <th class="text-left px-5 py-2.5 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Status</th>
                <th class="text-left px-5 py-2.5 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Waktu</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
              <?php foreach ($recentLogs as $log): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                <td class="px-5 py-3">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $log['log_type'] === 'payment' ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' : 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-400' ?>">
                    <?= $log['log_type'] === 'payment' ? 'Payment' : 'Integration' ?>
                  </span>
                </td>
                <td class="px-5 py-3 font-medium text-gray-800 dark:text-gray-200"><?= e($log['provider'] ?? '-') ?></td>
                <td class="px-5 py-3 text-gray-500 dark:text-gray-400"><?= e($log['event_type'] ?? '-') ?></td>
                <td class="px-5 py-3">
                  <?php if ($log['status'] === 'success' || $log['status'] === 'sent'): ?>
                  <span class="inline-flex items-center gap-1 text-xs font-medium text-green-600 dark:text-green-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 shrink-0"></span> <?= e($log['status']) ?>
                  </span>
                  <?php elseif ($log['status'] === 'failed'): ?>
                  <span class="inline-flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0"></span> failed
                  </span>
                  <?php else: ?>
                  <span class="text-xs text-gray-400"><?= e($log['status'] ?? '-') ?></span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap"><?= e(date('d M Y H:i', strtotime($log['created_at']))) ?></td>
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

<?php include __DIR__ . '/../../partials/footer.php'; ?>
