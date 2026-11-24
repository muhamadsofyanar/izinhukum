<?php
// Member — Pendapatan JV: rekap bagi hasil milik user login sebagai partner JV.
// Read-only. Pembayaran dilakukan manual oleh admin di /admin/jv-partner.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Pendapatan JV';
$topbarTitle = 'Pendapatan JV';

$userId = (int) $_SESSION['user_id'];

$summary = ['unpaid' => 0.0, 'paid' => 0.0, 'count' => 0];
$rows    = [];
try {
    $pdo = getDB();
    $s = $pdo->prepare(
        "SELECT SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END) AS unpaid_total,
                SUM(CASE WHEN status = 'paid'   THEN amount ELSE 0 END) AS paid_total,
                COUNT(*) AS cnt
         FROM jv_earnings WHERE partner_user_id = ?"
    );
    $s->execute([$userId]);
    if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
        $summary = ['unpaid' => (float) $row['unpaid_total'], 'paid' => (float) $row['paid_total'], 'count' => (int) $row['cnt']];
    }

    $st = $pdo->prepare(
        "SELECT je.*, p.name AS product_name
         FROM jv_earnings je JOIN products p ON p.id = je.product_id
         WHERE je.partner_user_id = ?
         ORDER BY je.created_at DESC
         LIMIT 100"
    );
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('jv-earnings: ' . $e->getMessage()); }

$cardCls = 'bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Pendapatan JV</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Bagi hasil dari produk yang kamu joint venture-kan. Pembayaran dilakukan manual oleh admin.</p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="<?= $cardCls ?> p-5">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Belum Dibayar</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= e(formatRupiah($summary['unpaid'])) ?></p>
        </div>
        <div class="<?= $cardCls ?> p-5">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Sudah Dibayar</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= e(formatRupiah($summary['paid'])) ?></p>
        </div>
      </div>

      <div class="<?= $cardCls ?> overflow-hidden">
        <?php if (!$rows): ?>
        <div class="p-10 text-center">
          <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada catatan</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Riwayat bagi hasil akan muncul di sini setiap ada penjualan.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500 border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-3">Produk</th>
                <th class="px-4 py-3 text-right">Jatah</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Tanggal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $badge = ['unpaid' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'paid' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'cancelled' => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'][$r['status']] ?? '';
                $label = ['unpaid' => 'Belum Dibayar', 'paid' => 'Sudah Dibayar', 'cancelled' => 'Dibatalkan'][$r['status']] ?? $r['status'];
              ?>
              <tr class="border-b border-gray-50 dark:border-gray-800/60">
                <td class="px-4 py-3.5 text-gray-700 dark:text-gray-300 max-w-[220px] truncate"><?= e($r['product_name']) ?></td>
                <td class="px-4 py-3.5 text-right font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah((float) $r['amount'])) ?></td>
                <td class="px-4 py-3.5"><span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $badge ?>"><?= e($label) ?></span></td>
                <td class="px-4 py-3.5 text-gray-500 dark:text-gray-400"><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
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
