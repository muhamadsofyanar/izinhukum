<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Transaksi Saya';
$topbarTitle = 'Transaksi';

$userId  = (int) $_SESSION['user_id'];
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$orders     = [];
$totalCount = 0;
$totalPages = 1;

try {
    $pdo = getDB();

    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
    $cntStmt->execute([$userId]);
    $totalCount = (int) $cntStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT o.*,
                GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR \', \') AS item_names,
                COUNT(oi.id) AS item_count
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ?
         GROUP BY o.id
         ORDER BY o.created_at DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('User transactions error: ' . $e->getMessage());
}

// Badge via renderStatusBadge() dari helpers.php
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Transaksi Saya</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= number_format($totalCount) ?> total transaksi</p>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">

        <?php if (empty($orders)): ?>
        <div class="py-16 text-center">
          <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
          </svg>
          <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada transaksi</p>
          <a href="<?= e(APP_URL) ?>/products" class="mt-3 inline-block text-sm text-[var(--brand-color)] hover:underline font-medium">Jelajahi produk →</a>
        </div>

        <?php else: ?>

        <!-- Mobile: card list -->
        <div class="divide-y divide-gray-100 dark:divide-gray-800 lg:hidden">
          <?php foreach ($orders as $order): ?>
          <a href="<?= e(APP_URL) ?>/transactions/<?= e($order['order_number']) ?>" class="block p-4 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition">
            <div class="flex items-start justify-between gap-3 mb-2">
              <p class="font-mono text-xs font-bold text-[var(--brand-color)]"><?= e($order['order_number']) ?></p>
              <?= renderStatusBadge($order['status'], 'order') ?>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300 font-medium truncate"><?= e($order['item_names'] ?? '-') ?></p>
            <div class="flex items-center justify-between mt-2">
              <span class="text-xs text-gray-400 dark:text-gray-600"><?= e(date('d M Y', strtotime($order['created_at']))) ?></span>
              <span class="text-sm font-bold text-gray-900 dark:text-white"><?= $order['total'] > 0 ? e(formatRupiah((float)$order['total'])) : '<span class="text-green-600 dark:text-green-400">Gratis</span>' ?></span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>

        <!-- Desktop: table -->
        <div class="hidden lg:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">No. Order</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tanggal</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($orders as $order): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-5 py-3.5">
                  <a href="<?= e(APP_URL) ?>/transactions/<?= e($order['order_number']) ?>"
                     class="font-mono text-xs font-bold text-[var(--brand-color)] hover:underline">
                    <?= e($order['order_number']) ?>
                  </a>
                </td>
                <td class="px-4 py-3.5">
                  <p class="font-medium text-gray-800 dark:text-gray-200 truncate max-w-[240px]"><?= e($order['item_names'] ?? '-') ?></p>
                  <?php if ((int)$order['item_count'] > 1): ?>
                  <p class="text-xs text-gray-400 mt-0.5"><?= (int)$order['item_count'] ?> item</p>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5 text-right font-semibold text-gray-900 dark:text-white">
                  <?= $order['total'] > 0 ? e(formatRupiah((float)$order['total'])) : '<span class="text-green-600 dark:text-green-400">Gratis</span>' ?>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <?= renderStatusBadge($order['status'], 'order') ?>
                </td>
                <td class="px-5 py-3.5 text-right text-xs text-gray-400 dark:text-gray-600">
                  <?= e(date('d M Y', strtotime($order['created_at']))) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
          <p class="text-xs text-gray-500 dark:text-gray-400">Halaman <?= $page ?> dari <?= $totalPages ?></p>
          <div class="flex items-center gap-1">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">‹ Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <a href="?page=<?= $p ?>"
               class="px-3 py-1.5 text-xs rounded-lg border transition <?= $p === $page ? 'border-[var(--brand-color)] text-[var(--brand-color)] font-semibold' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
              <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Next ›</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
