<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('affiliates')) { redirect('/admin/no-access'); }

// Gabungan Komisi + Pencairan dalam satu halaman ber-tab (dulu /admin/commissions
// & /admin/payouts terpisah → membingungkan). Kedua route lama kini redirect ke sini.
$tab = ($_GET['tab'] ?? 'commissions') === 'payouts' ? 'payouts' : 'commissions';

$pageTitle   = 'Komisi & Payout';
$topbarTitle = 'Komisi & Payout';

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

      <div class="mb-5">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Komisi &amp; Payout</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kelola komisi affiliate &amp; proses pencairannya di satu tempat</p>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 mb-5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-1 w-fit shadow-sm">
        <a href="<?= e(APP_URL) ?>/admin/affiliate-finance?tab=commissions"
           class="px-4 py-1.5 rounded-lg text-sm font-medium transition-colors <?= $tab === 'commissions' ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' ?>">
          Komisi
        </a>
        <a href="<?= e(APP_URL) ?>/admin/affiliate-finance?tab=payouts"
           class="px-4 py-1.5 rounded-lg text-sm font-medium transition-colors <?= $tab === 'payouts' ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' ?>">
          Pencairan
        </a>
      </div>

      <?php
      if ($tab === 'payouts') {
          include __DIR__ . '/_affiliate-payouts.php';
      } else {
          include __DIR__ . '/_affiliate-commissions.php';
      }
      ?>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
