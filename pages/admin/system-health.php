<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('notifications')) { redirect('/admin/no-access'); }

$pageTitle   = 'Kesehatan Sistem';
$topbarTitle = 'Kesehatan Sistem';

$pdo    = getDB();
$health = systemHealthSnapshot();
$cron   = $health['cron'];
$notif  = $health['notif'];
$backup = $health['backup'];

$cronInterval = (int) (getSetting('cron_auto_interval_seconds', '300') ?: 300);
$cronSecret   = (string) getSetting('cron_secret', '');
$isAdminRole  = ($_SESSION['role'] ?? '') === 'admin';

// Daftar kegagalan terbaru (30 hari, maks 50 baris).
$failed = [];
try {
    $failed = $pdo->query(
        "SELECT id, event_key, channel, provider, recipient, response, created_at,
                (body IS NOT NULL) AS retryable
         FROM notification_logs
         WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 30 DAY
         ORDER BY id DESC LIMIT 50"
    )->fetchAll();
} catch (Exception $e) {
    // kolom body belum ada (pra-migrasi) — tampilkan tanpa info retryable
    try {
        $failed = $pdo->query(
            "SELECT id, event_key, channel, provider, recipient, response, created_at, 0 AS retryable
             FROM notification_logs
             WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 30 DAY
             ORDER BY id DESC LIMIT 50"
        )->fetchAll();
    } catch (Exception $e2) {}
}

$fmtAge = function (int $sec): string {
    if ($sec < 60)    return $sec . ' detik lalu';
    if ($sec < 3600)  return floor($sec / 60) . ' menit lalu';
    if ($sec < 86400) return floor($sec / 3600) . ' jam ' . floor(($sec % 3600) / 60) . ' menit lalu';
    return floor($sec / 86400) . ' hari lalu';
};

[$cronBadgeCls, $cronBadgeText, $cronCardCls] = match ($cron['level']) {
    'ok'   => ['bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400', 'Sehat', 'border-gray-200 dark:border-gray-800'],
    'warn' => ['bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400', 'Perlu Perhatian', 'border-amber-200 dark:border-amber-800/50'],
    default => ['bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400', 'Bermasalah', 'border-red-200 dark:border-red-900/50'],
};

$csrf = generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-4xl mx-auto">

        <div class="mb-5">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Kesehatan Sistem</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Pantau proses otomatis (cron) dan pengiriman notifikasi — kegagalan senyap jadi kelihatan di sini.
          </p>
        </div>

        <!-- ═══ Kartu Status Cron ═══ -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border <?= $cronCardCls ?> shadow-sm overflow-hidden mb-5">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Proses Otomatis (Cron)</h3>
            <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold <?= $cronBadgeCls ?>"><?= $cronBadgeText ?></span>
          </div>
          <div class="p-5">
            <?php if ($cron['age'] < 0): ?>
            <p class="text-sm text-gray-600 dark:text-gray-300">Belum ada catatan cron berjalan. Buka beberapa halaman situs (Auto-Cron menumpang kunjungan) atau setup cron asli di bawah.</p>
            <?php else: ?>
            <div class="flex items-center gap-4 flex-wrap">
              <div>
                <p class="text-xs text-gray-400 dark:text-gray-600">Terakhir berjalan</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white mt-0.5">
                  <?= e($fmtAge($cron['age'])) ?>
                  <span class="font-normal text-gray-400 dark:text-gray-600">(<?= e(date('d M Y H:i:s', $cron['last'])) ?>)</span>
                </p>
              </div>
              <div>
                <p class="text-xs text-gray-400 dark:text-gray-600">Sumber</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white mt-0.5"><?= $cron['source'] === 'cron' ? 'Cron server' : 'Auto-Cron (menumpang kunjungan)' ?></p>
              </div>
              <div>
                <p class="text-xs text-gray-400 dark:text-gray-600">Interval Auto-Cron</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white mt-0.5"><?= (int) ($cronInterval / 60) ?> menit</p>
              </div>
            </div>
            <?php endif; ?>

            <p class="text-xs text-gray-400 dark:text-gray-600 mt-4 leading-relaxed">
              Cron menggerakkan: email sequence, abandoned cart, reminder renewal &amp; pelunasan,
              waitlist launch, booking hangus, dan backup harian.
              <strong>Auto-Cron hanya berjalan saat situs dikunjungi</strong> — situs sepi = proses tertunda.
            </p>

            <?php if ($cron['level'] !== 'ok'): ?>
            <div class="mt-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/40 rounded-xl p-4">
              <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">Rekomendasi: setup cron asli di hosting (sekali saja)</p>
              <ol class="text-xs text-amber-700 dark:text-amber-400 space-y-1.5 list-decimal pl-4 leading-relaxed">
                <li>Buka cPanel hosting kamu → menu <strong>Cron Jobs</strong>.</li>
                <li>Tambahkan jadwal <strong>tiap 5 menit</strong> (<code class="font-mono">*/5 * * * *</code>) dengan perintah:</li>
              </ol>
              <?php if ($isAdminRole && $cronSecret !== ''): ?>
              <code class="block mt-2 px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-amber-200 dark:border-amber-800/40 text-xs font-mono text-gray-800 dark:text-gray-200 overflow-x-auto whitespace-nowrap">wget -qO- "<?= e(rtrim(APP_URL, '/')) ?>/cron/process?token=<?= e($cronSecret) ?>" &gt;/dev/null 2&gt;&amp;1</code>
              <?php elseif ($isAdminRole): ?>
              <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">Isi dulu setting <code class="font-mono">cron_secret</code> di <code class="font-mono">/admin/settings</code>, lalu kembali ke sini untuk melihat perintah lengkapnya.</p>
              <?php else: ?>
              <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">Minta admin utama untuk melakukan setup ini.</p>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ═══ Statistik Notifikasi ═══ -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
          <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
            <p class="text-xs text-gray-400 dark:text-gray-600">Terkirim (7 hari)</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= (int) $notif['sent_7d'] ?></p>
          </div>
          <div class="bg-white dark:bg-gray-900 rounded-2xl border <?= $notif['failed_7d'] > 0 ? 'border-red-200 dark:border-red-900/50' : 'border-gray-200 dark:border-gray-800' ?> shadow-sm p-5">
            <p class="text-xs text-gray-400 dark:text-gray-600">Gagal (7 hari)</p>
            <p class="text-2xl font-bold <?= $notif['failed_7d'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' ?> mt-1"><?= (int) $notif['failed_7d'] ?></p>
          </div>
          <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
            <p class="text-xs text-gray-400 dark:text-gray-600">Backup terakhir</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1 leading-tight">
              <?= $backup['last'] === null ? '—' : e($fmtAge((int) $backup['last'])) ?>
            </p>
            <?php if ($isAdminRole): ?>
            <a href="<?= e(APP_URL) ?>/admin/backups" class="text-xs text-[var(--brand-color)] hover:underline">Kelola backup →</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- ═══ Daftar Notifikasi Gagal ═══ -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-start justify-between gap-3">
            <div>
              <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Notifikasi Gagal (30 hari terakhir)</h3>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Penyebab umum: token provider WA kedaluwarsa, kuota email habis, atau nomor/email tujuan tidak valid.</p>
            </div>
            <?php if (!empty($failed)): ?>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-notification-log" id="clearFailedLogs" class="shrink-0">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="scope" value="all_failed">
              <button type="button"
                      onclick="confirmAndSubmit('clearFailedLogs',{title:'Bersihkan Semua Log Gagal?',message:'Semua log notifikasi berstatus gagal akan dihapus permanen (tidak memengaruhi pengiriman).',confirmText:'Ya, Bersihkan',variant:'danger'})"
                      class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition whitespace-nowrap">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Bersihkan Semua
              </button>
            </form>
            <?php endif; ?>
          </div>
          <?php if (empty($failed)): ?>
          <div class="py-12 text-center">
            <svg class="w-10 h-10 mx-auto text-green-300 dark:text-green-800 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Tidak ada kegagalan — semua notifikasi terkirim</p>
          </div>
          <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-gray-400 dark:text-gray-600 border-b border-gray-100 dark:border-gray-800">
                  <th class="px-5 py-2.5 font-medium">Waktu</th>
                  <th class="px-3 py-2.5 font-medium">Event</th>
                  <th class="px-3 py-2.5 font-medium">Channel</th>
                  <th class="px-3 py-2.5 font-medium">Tujuan</th>
                  <th class="px-3 py-2.5 font-medium">Error</th>
                  <th class="px-5 py-2.5 font-medium text-right">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                <?php foreach ($failed as $f): ?>
                <tr>
                  <td class="px-5 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= e(date('d M H:i', strtotime($f['created_at']))) ?></td>
                  <td class="px-3 py-3"><span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 font-mono"><?= e((string) $f['event_key']) ?></span></td>
                  <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300"><?= e((string) $f['channel']) ?><span class="text-gray-400 dark:text-gray-600"> · <?= e((string) $f['provider']) ?></span></td>
                  <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300 max-w-[160px] truncate" title="<?= e((string) $f['recipient']) ?>"><?= e((string) $f['recipient']) ?></td>
                  <td class="px-3 py-3 text-xs max-w-[220px] truncate <?= trim((string) $f['response']) !== '' ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-600 italic' ?>" title="<?= e((string) $f['response']) ?>"><?= trim((string) $f['response']) !== '' ? e(mb_substr((string) $f['response'], 0, 120)) : 'tanpa detail dari provider' ?></td>
                  <td class="px-5 py-3 text-right">
                    <div class="inline-flex items-center justify-end gap-1.5">
                    <?php if (!empty($f['retryable'])): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/retry-notification" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                      <button type="submit" data-loading data-loading-text="Mengirim..."
                              class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><polyline points="21 3 21 8 16 8"/></svg>
                        Kirim Ulang
                      </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-notification-log" id="delnl-<?= (int) $f['id'] ?>" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                      <button type="button" title="Hapus log ini"
                              onclick="confirmAndSubmit('delnl-<?= (int) $f['id'] ?>',{title:'Hapus Log?',message:'Baris log notifikasi gagal ini akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})"
                              class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
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

      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
