<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
require_once __DIR__ . '/../../helpers/darmutiara-updates.php';

$latest = null;
$error = null;
if (($_GET['check'] ?? '') === '1') {
    try {
        $latest = darmutiaraLatestVersion();
    } catch (RuntimeException $failure) {
        $error = $failure->getMessage();
    }
}
$configured = darmutiaraDeployConfig() !== null;
$deploy = darmutiaraUpdateState();
$requested = (string) ($deploy['version'] ?? '');
$installed = $requested !== '' && version_compare(APP_VERSION, $requested, '>=');
$pending = !$installed && $requested !== '' && ($deploy['status'] ?? '') !== 'rejected'
    && (int) ($deploy['at'] ?? 0) > time() - 900;

$pageTitle   = 'Update Sistem';
$topbarTitle = 'Update Sistem';

$history = [];
try {
    $pdo = getDB();
    $history = $pdo->query("SELECT * FROM update_history ORDER BY applied_at DESC LIMIT 10")->fetchAll();
} catch (Exception $e) {
    error_log('update page error: ' . $e->getMessage());
}

$statusBadge = [
    'success' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    'failed'  => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    'partial' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
];
$statusLabel = ['success' => 'Sukses', 'failed' => 'Gagal', 'partial' => 'Partial'];

$partialUpdate = ($history[0]['status'] ?? '') === 'partial' ? $history[0] : null;
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-4 sm:p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Update Sistem</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Pembaruan STIFIn Mulia dipasang melalui GitHub dan Coolify.</p>
      </div>

      <div class="max-w-2xl space-y-4">
        <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-5">
          <p class="text-sm text-gray-500 dark:text-gray-400">Versi terpasang</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">v<?= e(APP_VERSION) ?></p>
          <div class="flex flex-wrap items-center gap-3 mt-4">
            <a href="<?= e(APP_URL) ?>/admin/update?check=1" class="inline-flex rounded-lg px-4 py-2 font-semibold text-white" style="background-color:var(--brand-color)">Cek Update</a>
            <a href="<?= e(APP_URL) ?>/admin/changelog" class="inline-flex rounded-lg border px-4 py-2 font-semibold text-gray-900 dark:text-white">Lihat Catatan Rilis</a>
            <?php if ($configured && !$pending && $latest !== null && version_compare($latest, APP_VERSION, '>')): ?>
            <form action="<?= e(APP_URL) ?>/actions/admin/apply-update" method="post">
              <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
              <input type="hidden" name="version" value="<?= e($latest) ?>">
              <button class="rounded-lg border px-4 py-2 font-semibold text-gray-900 dark:text-white">Pasang Update v<?= e($latest) ?></button>
            </form>
            <?php endif; ?>
          </div>
          <?php if (!$configured): ?>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">Pengaturan deploy Coolify diperlukan untuk mengaktifkan tombol Pasang Update.</p>
          <?php endif; ?>
          <?php if ($error !== null): ?>
            <p role="alert" class="mt-3 text-sm text-red-700 dark:text-red-400"><?= e($error) ?></p>
          <?php elseif ($latest !== null): ?>
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300"><?= version_compare($latest, APP_VERSION, '>') ? 'Versi v' . e($latest) . ' tersedia di GitHub.' : 'Versi terpasang sudah yang terbaru.' ?></p>
          <?php endif; ?>
          <?php if ($installed): ?>
            <p class="mt-3 text-sm text-green-700 dark:text-green-400">Versi v<?= e($requested) ?> sudah terpasang.</p>
          <?php elseif ($requested !== '' && ($deploy['status'] ?? '') !== 'rejected'): ?>
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">Versi v<?= e($requested) ?> belum terkonfirmasi. Lihat Deployments di Coolify untuk progres atau detail kegagalan.</p>
          <?php endif; ?>
        </section>
        <?php if ($partialUpdate): ?>
        <p class="p-4 rounded-xl bg-yellow-50 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-300 text-sm" role="status">
          Pembaruan lama ke v<?= e($partialUpdate['to_version']) ?> tercatat selesai sebagian. Periksa hasil migrasi database sebelum melanjutkan.
        </p>
        <?php endif; ?>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Riwayat pembaruan lama</h3>
            <div class="flex items-center gap-3">
              <?php if (!empty($history)): ?>
              <span class="text-xs text-gray-400 dark:text-gray-600"><?= count($history) ?> terakhir</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if (empty($history)): ?>
          <div class="py-12 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-200 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada riwayat dari pembaru lama.</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($history as $h): ?>
            <?php $ok = ($h['status'] ?? '') === 'success'; ?>
            <div class="flex items-center gap-3.5 px-4 sm:px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
              <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0
                          <?= $ok ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                                  : (($h['status'] ?? '') === 'partial'
                                      ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400'
                                      : 'bg-red-100 dark:bg-red-900/30 text-red-500 dark:text-red-400') ?>">
                <?php if ($ok): ?>
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?php elseif (($h['status'] ?? '') === 'partial'): ?>
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                <?php else: ?>
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?php endif; ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 dark:text-white">
                  <span class="font-mono text-gray-400 dark:text-gray-500">v<?= e($h['from_version']) ?></span>
                  <svg class="inline w-3.5 h-3.5 mx-0.5 text-gray-300 dark:text-gray-600 -mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                  <span class="font-mono font-bold">v<?= e($h['to_version']) ?></span>
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5"><?= e(date('d M Y H:i', strtotime($h['applied_at']))) ?></p>
              </div>
              <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap <?= $statusBadge[$h['status']] ?? '' ?>"><?= e($statusLabel[$h['status']] ?? ucfirst($h['status'])) ?></span>
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
