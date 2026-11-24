<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin(); // berisi seluruh isi database — khusus admin, bukan staff

$pageTitle   = 'Backup Database';
$topbarTitle = 'Backup Database';

$backups      = listDbBackups();
$backupDir    = dbBackupDir();
$enabled      = getSetting('db_backup_enabled', '1') === '1';
$retention    = (int) (getSetting('db_backup_retention_days', '7') ?: 7);
$lastAutoFile = getSetting('db_backup_last_file', '');
$backupTimes = array_column($backups, 'mtime', 'name');
$lastRun = $backupTimes[$lastAutoFile] ?? null;
$totalSize    = array_sum(array_column($backups, 'size'));
$dirInWebroot = $backupDir !== null && strpos($backupDir, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR) === 0;

$fmtSize = function (int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
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

        <div class="flex items-start justify-between gap-4 mb-5 flex-wrap">
          <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Backup Database</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
              Backup otomatis harian + manual. Simpan salinannya di komputer kamu secara berkala.
            </p>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/create-backup">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button type="submit" data-loading data-loading-text="Membuat backup..."
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
              </svg>
              Backup Sekarang
            </button>
          </form>
        </div>

        <!-- Pengaturan -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-5">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Pengaturan</h3>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-backup-settings" class="p-5">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
              <label class="flex items-center gap-2.5 cursor-pointer sm:pb-2.5">
                <input type="checkbox" name="db_backup_enabled" value="1" <?= $enabled ? 'checked' : '' ?>
                       class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
                <span class="text-sm text-gray-700 dark:text-gray-300">Backup otomatis harian</span>
              </label>
              <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Retensi (hari)</label>
                <input type="number" name="db_backup_retention_days" min="1" max="90" value="<?= $retention ?>"
                       class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Backup lebih tua dihapus otomatis.</p>
              </div>
              <div class="sm:text-right">
                <button type="submit" data-loading data-loading-text="Menyimpan..."
                        class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
              </div>
            </div>
          </form>
        </div>

        <!-- Info lokasi -->
        <div class="rounded-xl border <?= $backupDir ? 'border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900' : 'border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-900/10' ?> px-4 py-3 mb-5 flex items-start gap-2.5">
          <svg class="w-4 h-4 shrink-0 mt-0.5 <?= $backupDir ? 'text-gray-400' : 'text-red-500' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
          </svg>
          <div class="text-xs leading-relaxed <?= $backupDir ? 'text-gray-500 dark:text-gray-400' : 'text-red-600 dark:text-red-400' ?>">
            <?php if ($backupDir): ?>
              Lokasi: <span class="font-mono"><?= e($backupDir) ?></span>
              <?= $dirInWebroot ? ' <strong>(di dalam webroot — dilindungi .htaccess)</strong>' : ' (di luar webroot — aman dari akses browser)' ?>.
              Total <?= count($backups) ?> file &middot; <?= e($fmtSize($totalSize)) ?>.
              <?php if ($lastRun !== null): ?>
                Backup otomatis terakhir berhasil: <?= e(date('d M Y H:i', $lastRun)) ?>.
              <?php elseif ($enabled): ?>
                Cadangan otomatis terakhir belum terverifikasi.
              <?php endif; ?>
              Backup di server yang sama tidak melindungi dari kehilangan server total — unduh salinannya secara berkala.
            <?php else: ?>
              Folder backup tidak bisa dibuat/ditulis. Periksa permission folder <span class="font-mono">backups/</span> di hosting kamu.
            <?php endif; ?>
          </div>
        </div>

        <!-- Daftar backup -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">File Backup</h3>
          </div>
          <?php if (empty($backups)): ?>
          <div class="py-12 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
            </svg>
            <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada backup</p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Klik &ldquo;Backup Sekarang&rdquo; atau tunggu backup otomatis harian.</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($backups as $b): ?>
            <div class="flex items-center gap-3 px-5 py-3.5">
              <div class="w-9 h-9 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                </svg>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate font-mono"><?= e($b['name']) ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= e(date('d M Y H:i', $b['mtime'])) ?> &middot; <?= e($fmtSize($b['size'])) ?></p>
              </div>
              <div class="flex items-center gap-1.5 shrink-0">
                <a href="<?= e(APP_URL) ?>/actions/admin/download-backup?file=<?= e(rawurlencode($b['name'])) ?>"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                  </svg>
                  Download
                </a>
                <form id="del-bk-<?= e(md5($b['name'])) ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-backup" class="inline">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="file" value="<?= e($b['name']) ?>">
                  <button type="button"
                          onclick="confirmAndSubmit('del-bk-<?= e(md5($b['name'])) ?>',{title:'Hapus Backup?',message:'File <?= e($b['name']) ?> akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})"
                          class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                  </button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
