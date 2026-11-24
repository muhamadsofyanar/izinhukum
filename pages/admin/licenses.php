<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Manajemen License';
$topbarTitle = 'Manajemen License';

// ─── Filter & Pagination ──────────────────────────────────────
$statusFilter  = $_GET['status']     ?? 'all';
$productFilter = (int) ($_GET['product_id'] ?? 0);
$search        = trim($_GET['q']     ?? '');
$page          = max(1, (int) ($_GET['page'] ?? 1));
$perPage       = 20;

$statuses = ['active', 'suspended', 'revoked', 'expired'];

$licenses    = [];
$totalCount  = 0;
$totalPages  = 1;
$productList = [];

try {
    $pdo = getDB();

    // Produk yang punya lisensi aktif (untuk dropdown filter)
    $productList = $pdo->query(
        "SELECT DISTINCT p.id, p.name
         FROM products p
         JOIN product_licenses pl ON pl.product_id = p.id
         ORDER BY p.name ASC"
    )->fetchAll();

    $where  = [];
    $params = [];

    if ($statusFilter !== 'all' && in_array($statusFilter, $statuses, true)) {
        $where[]  = 'pl.status = ?';
        $params[] = $statusFilter;
    }
    if ($productFilter > 0) {
        $where[]  = 'pl.product_id = ?';
        $params[] = $productFilter;
    }
    if ($search !== '') {
        $where[]  = '(pl.license_key LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM product_licenses pl
         JOIN users u ON pl.user_id = u.id
         $whereSQL"
    );
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT pl.*,
                u.name AS buyer_name, u.email AS buyer_email,
                p.name AS product_name,
                o.order_number
         FROM product_licenses pl
         JOIN users u    ON pl.user_id    = u.id
         JOIN products p ON pl.product_id = p.id
         JOIN orders o   ON pl.order_id   = o.id
         $whereSQL
         ORDER BY pl.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $licenses = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Admin licenses list error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data license.', 'error');
}

// ─── Helper badge status license ──────────────────────────────
$licenseBadge = function (string $status): string {
    $map = [
        'active'    => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Aktif'],
        'suspended' => ['bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400', 'Disuspend'],
        'revoked'   => ['bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 line-through', 'Direvoke'],
        'expired'   => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Expired'],
    ];
    $c = $map[$status] ?? ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', $status];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' . $c[0] . '">' . e($c[1]) . '</span>';
};

// Masked key: AVRN-····-····-····-XXXX (4 char terakhir terlihat)
$maskKey = function (string $key): string {
    $last4 = substr($key, -4);
    return 'AVRN-••••-••••-••••-' . $last4;
};

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

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Manajemen License</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= number_format($totalCount) ?> license diterbitkan</p>
        </div>
      </div>

      <!-- Filter Bar -->
      <form method="GET" action="" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-5 flex flex-wrap gap-3 items-end shadow-sm">
        <div class="flex-1 min-w-[180px]">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Cari</label>
          <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="License key, email, atau nama buyer..."
                   class="w-full pl-9 pr-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>
        </div>
        <div class="w-44">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Status</label>
          <select name="status" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua Status</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-52">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk</label>
          <select name="product_id" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Produk</option>
            <?php foreach ($productList as $pr): ?>
            <option value="<?= (int) $pr['id'] ?>" <?= $productFilter === (int) $pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90 transition">Filter</button>
          <?php if ($search || $statusFilter !== 'all' || $productFilter): ?>
          <a href="<?= e(APP_URL) ?>/admin/licenses" class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($licenses)): ?>
        <div class="py-16 text-center">
          <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
          </svg>
          <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada license diterbitkan</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">License Key</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Buyer</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Seats</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Berlaku</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Issue</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($licenses as $lic): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-5 py-3.5">
                  <a href="<?= e(APP_URL) ?>/admin/licenses/<?= (int) $lic['id'] ?>"
                     class="group inline-block font-mono text-xs text-gray-700 dark:text-gray-300 hover:text-[var(--brand-color)]"
                     title="<?= e($lic['license_key']) ?>">
                    <span class="group-hover:hidden"><?= e($maskKey($lic['license_key'])) ?></span>
                    <span class="hidden group-hover:inline"><?= e($lic['license_key']) ?></span>
                  </a>
                </td>
                <td class="px-4 py-3.5 text-gray-700 dark:text-gray-300"><?= e($lic['product_name']) ?></td>
                <td class="px-4 py-3.5">
                  <p class="font-medium text-gray-900 dark:text-white"><?= e($lic['buyer_name']) ?></p>
                  <p class="text-xs text-gray-400 dark:text-gray-600"><?= e($lic['buyer_email']) ?></p>
                </td>
                <td class="px-4 py-3.5 text-center text-gray-700 dark:text-gray-300">
                  <?= (int) $lic['used_seats'] ?>/<?= (int) $lic['max_seats'] ?>
                </td>
                <td class="px-4 py-3.5 text-center"><?= $licenseBadge($lic['status']) ?></td>
                <td class="px-4 py-3.5 text-xs text-gray-500 dark:text-gray-400">
                  <?= $lic['expires_at'] ? e(date('d M Y', strtotime($lic['expires_at']))) : 'Selamanya' ?>
                </td>
                <td class="px-4 py-3.5 text-xs text-gray-500 dark:text-gray-400">
                  <?= e(date('d M Y', strtotime($lic['issued_at']))) ?>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <div class="inline-flex items-center justify-end gap-2">
                    <a href="<?= e(APP_URL) ?>/admin/licenses/<?= (int) $lic['id'] ?>"
                       class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-[var(--brand-color)] bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] hover:bg-[color-mix(in_srgb,var(--brand-color)_18%,transparent)] transition">
                      Detail
                    </a>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-license" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="license_id" value="<?= (int) $lic['id'] ?>">
                      <button type="button" title="Hapus license"
                        onclick="confirmAndRun({title:'Hapus License?',message:'License <?= e($maskKey($lic['license_key'])) ?> beserta semua aktivasinya akan dihapus permanen. Tindakan ini tidak bisa dibatalkan.',confirmText:'Ya, Hapus',variant:'danger'}, () => this.closest('form').submit())"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <?php
        $buildPageUrl = function (int $p) use ($search, $statusFilter, $productFilter): string {
            $q = http_build_query(array_filter([
                'q'          => $search ?: null,
                'status'     => $statusFilter !== 'all' ? $statusFilter : null,
                'product_id' => $productFilter ?: null,
                'page'       => $p > 1 ? $p : null,
            ]));
            return APP_URL . '/admin/licenses' . ($q ? '?' . $q : '');
        };
        $rangeStart = max(1, $page - 2);
        $rangeEnd   = min($totalPages, $page + 2);
        ?>
        <div class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
          <p class="text-xs text-gray-500 dark:text-gray-400">Halaman <?= $page ?> dari <?= $totalPages ?> &middot; <?= number_format($totalCount) ?> total</p>
          <div class="flex items-center gap-1">
            <?php if ($page > 1): ?>
            <a href="<?= e($buildPageUrl($page - 1)) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">‹ Prev</a>
            <?php endif; ?>
            <?php for ($p = $rangeStart; $p <= $rangeEnd; $p++): ?>
            <a href="<?= e($buildPageUrl($p)) ?>"
               class="px-3 py-1.5 text-xs rounded-lg border transition <?= $p === $page ? 'border-[var(--brand-color)] text-[var(--brand-color)] font-semibold' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
              <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= e($buildPageUrl($page + 1)) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Next ›</a>
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
