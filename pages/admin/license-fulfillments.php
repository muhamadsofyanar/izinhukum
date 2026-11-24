<?php
/**
 * Rekonsiliasi antrean Lisensi Vendor — jawaban atas satu pertanyaan:
 * "ada order lunas yang lisensinya belum sampai ke pembeli?"
 *
 * Halaman khusus instalasi vendor (lihat vfAvailable()).
 */
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if (!function_exists('vfAvailable') || !vfAvailable()) {
    redirect('/admin/integrations');
}

$pageTitle   = 'Antrean Lisensi Vendor';
$topbarTitle = 'Antrean Lisensi Vendor';

$status = in_array($_GET['status'] ?? '', ['pending', 'done', 'failed'], true) ? $_GET['status'] : '';
$search = trim($_GET['q'] ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$rows = [];
$totalCount = 0;
$totalPages = 1;
$stats = ['pending' => 0, 'done' => 0, 'failed' => 0];

try {
    $pdo = getDB();

    $stats = $pdo->query(
        "SELECT COALESCE(SUM(status='pending'),0) AS pending,
                COALESCE(SUM(status='done'),0)    AS done,
                COALESCE(SUM(status='failed'),0)  AS failed
           FROM license_fulfillments"
    )->fetch() ?: $stats;

    $where = []; $params = [];
    if ($status !== '') { $where[] = 'f.status = ?'; $params[] = $status; }
    if ($search !== '') {
        $where[]  = '(f.order_number LIKE ? OR f.license_key LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
        $like     = '%' . $search . '%';
        $params   = array_merge($params, [$like, $like, $like, $like]);
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM license_fulfillments f JOIN users u ON u.id = f.user_id $whereSQL");
    $cnt->execute($params);
    $totalCount = (int) $cnt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT f.*, u.name AS user_name, u.email AS user_email, p.name AS product_name
           FROM license_fulfillments f
           JOIN users u    ON u.id = f.user_id
           LEFT JOIN products p ON p.id = f.product_id
           $whereSQL
          ORDER BY f.id DESC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('license-fulfillments page error: ' . $e->getMessage());
    flash('error', 'Gagal memuat antrean lisensi.', 'error');
}

generateCSRF();
$badge = function (string $s): string {
    return match ($s) {
        'done'    => '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Terbit</span>',
        'failed'  => '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">Gagal</span>',
        default   => '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Diantre</span>',
    };
};
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Antrean Lisensi Vendor</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Pesanan lisensi ke server vendor — <?= number_format($totalCount) ?> baris</p>
        </div>
        <a href="<?= e(APP_URL) ?>/admin/integrations" class="text-sm font-semibold text-[var(--brand-color)] hover:underline">Pengaturan Integrasi →</a>
      </div>

      <!-- Stat -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
        <a href="?status=pending" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 shadow-sm hover:border-[var(--brand-color)] transition">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Diantre</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= number_format((int) $stats['pending']) ?></p>
        </a>
        <a href="?status=failed" class="bg-white dark:bg-gray-900 rounded-xl border <?= (int) $stats['failed'] > 0 ? 'border-red-300 dark:border-red-800' : 'border-gray-200 dark:border-gray-800' ?> p-4 shadow-sm hover:border-red-400 transition">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Gagal</p>
          <p class="text-2xl font-bold <?= (int) $stats['failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' ?> mt-1"><?= number_format((int) $stats['failed']) ?></p>
        </a>
        <a href="?status=done" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 shadow-sm hover:border-[var(--brand-color)] transition">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Terbit</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= number_format((int) $stats['done']) ?></p>
        </a>
      </div>

      <form method="GET" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-5 flex flex-wrap gap-3 items-end shadow-sm">
        <div class="flex-1 min-w-[180px]">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Cari</label>
          <input type="text" name="q" value="<?= e($search) ?>" placeholder="No. order, license key, nama, email..."
                 class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
        </div>
        <div class="w-40">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Status</label>
          <select name="status" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Status</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Diantre</option>
            <option value="failed"  <?= $status === 'failed'  ? 'selected' : '' ?>>Gagal</option>
            <option value="done"    <?= $status === 'done'    ? 'selected' : '' ?>>Terbit</option>
          </select>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90 transition">Filter</button>
          <?php if ($search || $status): ?>
          <a href="<?= e(APP_URL) ?>/admin/license-fulfillments" class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($rows)): ?>
        <div class="py-16 text-center">
          <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada pesanan lisensi</p>
          <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Baris muncul otomatis begitu ada order lunas untuk produk yang dipetakan.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Order / Pembeli</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">License Key</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($rows as $r): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-5 py-3.5">
                  <p class="font-mono text-xs font-semibold text-gray-900 dark:text-white"><?= e($r['order_number']) ?></p>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= e($r['user_name']) ?> · <?= e($r['user_email']) ?></p>
                </td>
                <td class="px-4 py-3.5">
                  <p class="text-gray-700 dark:text-gray-300"><?= e($r['product_name'] ?? '—') ?></p>
                  <p class="text-xs text-gray-400 font-mono mt-0.5">plan: <?= e($r['plan']) ?></p>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <span class="text-xs font-medium text-gray-600 dark:text-gray-400"><?= $r['action'] === 'renew' ? 'Perpanjang' : 'Baru' ?></span>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <?= $badge($r['status']) ?>
                  <?php if ((int) $r['attempts'] > 0 && $r['status'] !== 'done'): ?>
                  <p class="text-[11px] text-gray-400 mt-1"><?= (int) $r['attempts'] ?>x dicoba</p>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5">
                  <?php if (!empty($r['license_key'])): ?>
                  <code class="text-xs font-mono text-gray-700 dark:text-gray-300"><?= e($r['license_key']) ?></code>
                  <?php else: ?>
                  <span class="text-xs text-gray-400">—</span>
                  <?php endif; ?>
                  <?php if (!empty($r['last_error']) && $r['status'] !== 'done'): ?>
                  <p class="text-[11px] text-red-600 dark:text-red-400 mt-1 max-w-xs break-words"><?= e($r['last_error']) ?></p>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <?php if ($r['status'] !== 'done'): ?>
                  <form id="retry-<?= (int) $r['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/retry-fulfillment" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="button"
                            onclick="confirmAndSubmit('retry-<?= (int) $r['id'] ?>',{title:'Coba Terbitkan Lagi?',message:'Kirim ulang permintaan ke server vendor untuk order <?= e(addslashes($r['order_number'])) ?>. Aman diulang — order yang sama tak akan pernah menerbitkan lisensi dua kali.',confirmText:'Ya, coba lagi',variant:'info'})"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-[var(--brand-color)] bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] hover:opacity-80 transition">
                      Coba Lagi
                    </button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
          <p class="text-xs text-gray-500 dark:text-gray-400">Halaman <?= $page ?> dari <?= $totalPages ?></p>
          <div class="flex items-center gap-1">
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <a href="?page=<?= $p ?>&status=<?= urlencode($status) ?>&q=<?= urlencode($search) ?>"
               class="px-3 py-1.5 text-xs rounded-lg border transition <?= $p === $page ? 'border-[var(--brand-color)] text-[var(--brand-color)] font-semibold' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
