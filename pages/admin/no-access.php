<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

$pageTitle   = 'Akses Terbatas';
$topbarTitle = 'Akses Terbatas';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6 flex items-center justify-center">
      <div class="text-center max-w-md">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
          <svg class="w-8 h-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1.5">Akses Terbatas</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Kamu tidak memiliki izin untuk mengakses halaman ini. Hubungi admin jika membutuhkan akses lebih.</p>
        <?php if (isStaff()):
          $myPerms = $_SESSION['staff_permissions'] ?? []; ?>
          <?php if (!empty($myPerms)): ?>
          <div class="flex flex-wrap justify-center gap-1.5 mb-5">
            <?php foreach ($myPerms as $p): ?>
            <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"><?= e($p) ?></span>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <p class="text-xs text-gray-400 mb-5">Kamu belum memiliki permission apapun.</p>
          <?php endif; ?>
        <?php endif; ?>
        <a href="<?= e(APP_URL) ?>/admin" class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">← Kembali ke Dashboard</a>
      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
