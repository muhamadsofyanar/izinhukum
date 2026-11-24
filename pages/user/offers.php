<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// Fitur harus diaktifkan admin; bila mati, tak ada halaman ini.
if (!function_exists('memberOffersEnabled') || !memberOffersEnabled()) {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}

$pageTitle   = 'Penawaran Spesial';
$topbarTitle = 'Penawaran Spesial';

$userId = (int) $_SESSION['user_id'];
$offers = getMemberUpsellOffers($userId); // semua penawaran (tanpa batas)
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Header -->
      <div class="mb-6 flex items-center gap-3">
        <svg class="w-9 h-9 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/></svg>
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white"><?= e(memberOffersTitle()) ?></h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Penawaran eksklusif dengan harga khusus untuk kamu.</p>
        </div>
      </div>

      <?php if (empty($offers)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm py-16 text-center">
        <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/></svg>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Belum ada penawaran untukmu saat ini.</p>
        <a href="<?= e(APP_URL) ?>/katalog" class="mt-2 inline-block text-xs font-medium text-[var(--brand-color)] hover:underline">Jelajahi katalog →</a>
      </div>
      <?php else: ?>
      <?php include __DIR__ . '/../../partials/_member-offers.php'; ?>
      <?php endif; ?>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
