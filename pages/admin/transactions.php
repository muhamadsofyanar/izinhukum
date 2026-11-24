<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('transactions')) { redirect('/admin/no-access'); }

$pageTitle   = 'Transaksi';
$topbarTitle = 'Manajemen Transaksi';

require_once __DIR__ . '/../../helpers/order-expiry-check.php';
$expiredOrderCount = getExpiredOrderCount();

require_once __DIR__ . '/../../helpers/transaction-filter.php';
$filter       = buildTransactionFilters($_GET);
$search       = $filter['search'];
$statusFilter = $filter['statusFilter'];
$itemFilter   = $filter['itemFilter'];
$period       = $filter['period'];   // today|yesterday|7|30|lifetime|custom
$dateFrom     = $filter['from'];
$dateTo       = $filter['to'];
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 20;

$statuses = ['pending', 'paid', 'failed', 'cancelled'];

$orders     = [];
$totalCount = 0;
$totalPages = 1;

$totalRevenue  = 0.0;
$pendingCount  = 0;
$statusCounts  = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'cancelled' => 0, 'expired' => 0];

$allFilterProducts = [];
$allFilterBundles  = [];

try {
    $pdo = getDB();

    $allFilterProducts = $pdo->query("SELECT id, name, type FROM products ORDER BY name ASC")->fetchAll();
    $allFilterBundles  = $pdo->query("SELECT id, name FROM product_bundles ORDER BY name ASC")->fetchAll();

    // Stat cards. Revenue IKUT PERIODE/filter (paid), pending = alert global.
    $revWhereSQL  = $filter['where_revenue'] !== '' ? 'WHERE ' . $filter['where_revenue'] : '';
    $revStmt      = $pdo->prepare("SELECT COALESCE(SUM(o.total),0) FROM orders o JOIN users u ON o.user_id = u.id $revWhereSQL");
    $revStmt->execute($filter['params_revenue']);
    $totalRevenue = (float) $revStmt->fetchColumn();
    $pendingCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status='pending'"
    )->fetchColumn();

    // Ringkasan jumlah per status utk periode + filter aktif (abaikan dropdown status),
    // dipakai chip ringkasan di atas tabel → klik chip = set filter status.
    $statusCounts = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'cancelled' => 0, 'expired' => 0];
    $scWhereSQL = $filter['where_nostatus'] !== '' ? 'WHERE ' . $filter['where_nostatus'] : '';
    $scStmt = $pdo->prepare("SELECT o.status, COUNT(*) AS c FROM orders o JOIN users u ON o.user_id = u.id $scWhereSQL GROUP BY o.status");
    $scStmt->execute($filter['params_nostatus']);
    foreach ($scStmt->fetchAll() as $scRow) {
        $statusCounts[$scRow['status']] = (int) $scRow['c'];
    }

    // Filter dibangun terpusat (helpers/transaction-filter.php) → identik dgn Export CSV.
    $whereSQL = $filter['where'] !== '' ? 'WHERE ' . $filter['where'] : '';
    $params   = $filter['params'];

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id = u.id $whereSQL");
    $cntStmt->execute($params);
    $totalCount = (int) $cntStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                u.role AS user_role, u.status AS user_status,
                GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') AS item_names,
                COUNT(oi.id) AS item_count,
                MAX(COALESCE(oi.is_bundle, 0)) AS has_bundle_item,
                (SELECT pb.name FROM order_items oib
                    JOIN product_bundles pb ON pb.id = oib.bundle_id
                    WHERE oib.order_id = o.id AND oib.is_bundle = 1 AND oib.bundle_id IS NOT NULL
                    ORDER BY oib.id LIMIT 1) AS bundle_name,
                (SELECT p.proof_file FROM payments p WHERE p.order_id = o.id AND p.method = 'manual_transfer' AND p.proof_file IS NOT NULL LIMIT 1) AS mt_proof,
                (SELECT au.name FROM affiliates af JOIN users au ON au.id = af.user_id
                    WHERE o.referral_code IS NOT NULL AND o.referral_code <> '' AND af.code = o.referral_code
                    LIMIT 1) AS affiliate_name
         FROM orders o
         JOIN users u ON o.user_id = u.id
         LEFT JOIN order_items oi ON oi.order_id = o.id
         $whereSQL
         GROUP BY o.id
         ORDER BY o.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Admin transactions error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data transaksi.', 'error');
}

// Badge & label via renderStatusBadge() dari helpers.php
$statusLabel = fn(string $s): string => match ($s) {
    'paid' => 'Lunas', 'pending' => 'Menunggu', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan', 'expired' => 'Kedaluwarsa', default => ucfirst($s)
};

// ─── Data untuk modal Transaksi Manual ──────────────────────
// Member TIDAK lagi dimuat inline (dulu LIMIT 2000 — member di atasnya tak
// tercari sama sekali). Pencarian member kini server-side via
// actions/admin/search-users. Produk tetap inline (jumlah kecil).
$productsList = [];
try {
    $pdo = $pdo ?? getDB();
    $productsList = $pdo->query("SELECT id, name, price, setup_fee, access_duration_days FROM products WHERE status='active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    error_log('Manual transaction lists error: ' . $e->getMessage());
}

generateCSRF();

// ── Helper URL: pertahankan filter aktif saat pindah tab periode / paginasi ──
$buildTxnUrl = function (array $overrides) use ($search, $statusFilter, $itemFilter, $period, $dateFrom, $dateTo): string {
    $qs = array_merge([
        'q' => $search, 'status' => $statusFilter, 'item' => $itemFilter,
        'period' => $period, 'from' => $dateFrom, 'to' => $dateTo,
    ], $overrides);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return APP_URL . '/admin/transactions' . ($qs ? '?' . http_build_query($qs) : '');
};
// Querystring filter aktif (utk kembali ke tampilan sama setelah aksi massal & Export).
$currentQs = http_build_query(array_filter([
    'q' => $search, 'status' => $statusFilter, 'item' => $itemFilter,
    'period' => $period, 'from' => $dateFrom, 'to' => $dateTo,
], fn($v) => $v !== '' && $v !== null));
$periodTabs = ['today' => 'Hari ini', 'yesterday' => 'Kemarin', '7' => '7 Hari', '30' => '30 Hari', 'lifetime' => 'Lifetime'];
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <?php if ($expiredOrderCount > 0): ?>
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3">
        <div class="flex items-center gap-2 text-sm text-red-700 dark:text-red-400">
          <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span>Ada <strong><?= (int) $expiredOrderCount ?> order pending</strong> yang sudah melewati batas waktu dan perlu di-cancel.</span>
        </div>
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/run-order-expiry" class="shrink-0" id="expire-cancel-form">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
          <button type="button" onclick="confirmAndSubmit('expire-cancel-form', {title:'Cancel Order Expired', message:'Cancel <?= (int) $expiredOrderCount ?> order pending yang sudah lewat batas waktu?', confirmText:'Cancel Sekarang', variant:'danger'})"
                  class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 text-white transition-colors">
            Cancel Sekarang
          </button>
        </form>
      </div>
      <?php endif; ?>

      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Transaksi</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= number_format($totalCount) ?> order ditemukan</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
          <div class="text-right hidden sm:block">
            <?php $revLabel = $period === 'custom'
                ? 'Revenue ' . ($dateFrom ?: '…') . '–' . ($dateTo ?: '…')
                : 'Revenue · ' . ($periodTabs[$period] ?? 'Lifetime'); ?>
            <p class="text-xs text-gray-400 dark:text-gray-600"><?= e($revLabel) ?></p>
            <p class="text-sm font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($totalRevenue)) ?></p>
          </div>
          <?php if ($pendingCount > 0): ?>
          <a href="<?= e($buildTxnUrl(['status' => 'pending', 'period' => 'lifetime', 'from' => '', 'to' => '', 'page' => ''])) ?>"
             title="Jumlah order pending seluruh waktu (di luar filter periode) — klik untuk melihat"
             class="inline-flex items-center gap-1 whitespace-nowrap px-3 py-1.5 rounded-lg text-xs font-semibold bg-yellow-100 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400 hover:opacity-80 transition">
            <span class="w-1.5 h-1.5 rounded-full bg-yellow-500 animate-pulse"></span>
            <?= $pendingCount ?> menunggu · semua waktu
          </a>
          <?php endif; ?>
          <a href="<?= e(APP_URL) ?>/actions/admin/export-data?type=transactions<?= $currentQs !== '' ? '&amp;' . e($currentQs) : '' ?>"
             title="Export sesuai filter & periode yang sedang aktif"
             class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition shadow-sm">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="7 10 12 15 17 10"/>
              <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Export CSV
          </a>
          <button type="button" onclick="openManualTxn()"
             class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white transition shadow-sm hover:opacity-90"
             style="background-color:var(--brand-color)">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Transaksi Manual
          </button>
        </div>
      </div>

      <!-- Tab periode + rentang kustom -->
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <?php foreach ($periodTabs as $pk => $plabel): $activeTab = ((string) $pk === (string) $period); ?>
        <a href="<?= e($buildTxnUrl(['period' => (string) $pk, 'from' => '', 'to' => '', 'page' => ''])) ?>"
           class="period-tab px-3.5 py-1.5 rounded-lg text-xs font-semibold border transition-all duration-150 <?= $activeTab ? 'text-white border-transparent shadow-sm' : 'text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600' ?>"
           <?= $activeTab ? 'style="background:var(--brand-color)"' : '' ?>><?= e($plabel) ?></a>
        <?php endforeach; ?>

        <form method="GET" class="flex flex-wrap items-center gap-1.5 w-full sm:w-auto">
          <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= e($search) ?>"><?php endif; ?>
          <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
          <?php if ($itemFilter !== ''): ?><input type="hidden" name="item" value="<?= e($itemFilter) ?>"><?php endif; ?>
          <input type="hidden" name="period" value="custom">
          <input type="date" name="from" value="<?= e($dateFrom) ?>" aria-label="Dari tanggal"
                 class="min-w-0 flex-1 px-2.5 py-1.5 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
          <span class="text-xs text-gray-400">—</span>
          <input type="date" name="to" value="<?= e($dateTo) ?>" aria-label="Sampai tanggal"
                 class="min-w-0 flex-1 px-2.5 py-1.5 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
          <button type="submit" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold border transition <?= $period === 'custom' ? 'text-white border-transparent' : 'text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800' ?>"
                  <?= $period === 'custom' ? 'style="background:var(--brand-color)"' : '' ?>>Terapkan</button>
        </form>
      </div>

      <!-- Ringkasan jumlah per status (sesuai periode & filter aktif) → klik = filter status -->
      <?php
      $chipTotal = array_sum($statusCounts);
      // Warna titik indikator per status (hanya aksen kecil; chip aktif pakai warna brand).
      $chipDot = ['paid' => 'bg-green-500', 'pending' => 'bg-yellow-500', 'failed' => 'bg-gray-400', 'cancelled' => 'bg-red-500', 'expired' => 'bg-orange-500'];
      $chipBase = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all duration-150';
      ?>
      <div class="flex flex-wrap items-center gap-2 mb-5">
        <a href="<?= e($buildTxnUrl(['status' => '', 'page' => ''])) ?>"
           class="status-chip <?= $chipBase ?> <?= $statusFilter === '' ? 'text-white border-transparent shadow-sm' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800' ?>"
           <?= $statusFilter === '' ? 'style="background:var(--brand-color)"' : '' ?>>
          Semua <span class="tabular-nums opacity-80"><?= number_format($chipTotal) ?></span>
        </a>
        <?php foreach (['paid', 'pending', 'failed', 'cancelled'] as $sc):
          if ($statusCounts[$sc] <= 0 && $statusFilter !== $sc) continue;
          $chipActive = ($statusFilter === $sc); ?>
        <a href="<?= e($buildTxnUrl(['status' => $sc, 'page' => ''])) ?>"
           class="status-chip <?= $chipBase ?> <?= $chipActive ? 'text-white border-transparent shadow-sm' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800' ?>"
           <?= $chipActive ? 'style="background:var(--brand-color)"' : '' ?>>
          <?php if (!$chipActive): ?><span class="w-1.5 h-1.5 rounded-full <?= $chipDot[$sc] ?>"></span><?php endif; ?>
          <?= $statusLabel($sc) ?> <span class="tabular-nums opacity-80"><?= number_format($statusCounts[$sc]) ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Filter -->
      <form method="GET" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-5 flex flex-wrap gap-3 items-end shadow-sm">
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <?php if ($period === 'custom'): ?><input type="hidden" name="from" value="<?= e($dateFrom) ?>"><input type="hidden" name="to" value="<?= e($dateTo) ?>"><?php endif; ?>
        <div class="flex-1 min-w-[200px]">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Cari</label>
          <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="No. order, nama, email, produk..."
                   class="w-full pl-9 pr-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>
        </div>
        <div class="w-48">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk / Bundle</label>
          <select name="item" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Produk & Bundle</option>
            <?php if (!empty($allFilterProducts)): ?>
            <optgroup label="Produk Digital & Kelas">
              <?php foreach ($allFilterProducts as $p): ?>
              <option value="p_<?= $p['id'] ?>" <?= $itemFilter === 'p_' . $p['id'] || $itemFilter === (string)$p['id'] ? 'selected' : '' ?>>
                <?= e($p['name']) ?> (<?= e(strtoupper($p['type'])) ?>)
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if (!empty($allFilterBundles)): ?>
            <optgroup label="Product Bundle">
              <?php foreach ($allFilterBundles as $b): ?>
              <option value="b_<?= $b['id'] ?>" <?= $itemFilter === 'b_' . $b['id'] ? 'selected' : '' ?>>
                <?= e($b['name']) ?> (BUNDLE)
              </option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
        </div>
        <div class="w-40">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Status</label>
          <select name="status" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Status</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $statusLabel($s) ?></option>
            <?php endforeach; ?>
            <option value="awaiting_manual" <?= $statusFilter === 'awaiting_manual' ? 'selected' : '' ?>>Menunggu Konfirmasi Manual</option>
          </select>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90 transition">Filter</button>
          <?php if ($search || $statusFilter || $itemFilter || $period !== '7'): ?>
          <a href="<?= e(APP_URL) ?>/admin/transactions" class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Bulk action bar (muncul saat ada transaksi yang dicentang) -->
      <div id="bulkBar" class="hidden mb-3 flex flex-wrap items-center gap-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-4 py-2.5 shadow-sm">
        <span class="text-sm text-gray-600 dark:text-gray-300"><strong id="bulkCount">0</strong> transaksi dipilih</span>
        <div class="flex items-center gap-2 ml-auto">
          <button type="button" id="bulkConfirmBtn" onclick="bulkRun('confirm')" class="hidden inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-green-600 hover:bg-green-700 transition">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
            Konfirmasi Lunas
          </button>
          <button type="button" id="bulkCancelBtn" onclick="bulkRun('cancel')" class="hidden inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Batalkan
          </button>
          <button type="button" id="bulkDeleteBtn" onclick="bulkRun('delete')" class="hidden inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            Hapus Terpilih
          </button>
          <button type="button" onclick="bulkClear()" class="px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Batal pilih</button>
        </div>
      </div>
      <form id="bulkForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/bulk-transaction" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
        <input type="hidden" name="back_to" value="<?= e($currentQs) ?>">
        <div id="bulkIdsContainer"></div>
      </form>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($orders)): ?>
        <div class="py-16 text-center">
          <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
          </svg>
          <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada transaksi</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-3 w-10">
                  <input type="checkbox" id="checkAll" onclick="bulkToggleAll(this)" title="Pilih semua di halaman ini"
                         class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)] cursor-pointer align-middle">
                </th>
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Order</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Waktu</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pembeli</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">WhatsApp</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Affiliate</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($orders as $order):
                $isPending   = ($order['status'] ?? '') === 'pending';
                $isCancelled = ($order['status'] ?? '') === 'cancelled';
                $waRaw = trim((string) ($order['user_phone'] ?? ''));
                $waNum = $waRaw !== '' ? normalizeWaNumber($waRaw) : '';
              ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-4 py-3.5 align-top">
                  <?php if ($isPending || $isCancelled): ?>
                  <input type="checkbox" value="<?= (int) $order['id'] ?>" data-status="<?= e($order['status']) ?>" onclick="bulkOnCheck()"
                         class="txn-check w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)] cursor-pointer">
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5">
                  <a href="<?= e(APP_URL) ?>/admin/transactions/<?= e($order['order_number']) ?>"
                     class="font-mono text-xs font-bold text-[var(--brand-color)] hover:underline block">
                    <?= e($order['order_number']) ?>
                  </a>
                </td>
                <td class="px-4 py-3.5 whitespace-nowrap">
                  <?php $ts = strtotime($order['created_at']); ?>
                  <span class="inline-flex items-center gap-1 text-sm font-semibold text-gray-800 dark:text-gray-200 tabular-nums">
                    <svg class="w-3.5 h-3.5 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>
                    <?= e(date('H:i', $ts)) ?>
                  </span>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= e(date('d M Y', $ts)) ?></p>
                </td>
                <td class="px-4 py-3.5">
                  <p class="font-medium text-gray-800 dark:text-gray-200"><?= e($order['user_name']) ?></p>
                  <p class="text-xs text-gray-400 dark:text-gray-600"><?= e($order['user_email']) ?></p>
                </td>
                <td class="px-4 py-3.5">
                  <?php if ($waNum !== ''): ?>
                  <div class="flex items-center gap-1.5">
                    <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center gap-1 text-xs font-medium text-green-600 dark:text-green-400 hover:underline" title="Chat via WhatsApp">
                      <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0 0 20.885 3.488"/></svg>
                      <?= e($waNum) ?>
                    </a>
                    <button type="button" onclick="copyWa(this,'<?= e($waNum) ?>')" title="Salin nomor" aria-label="Salin nomor"
                            class="shrink-0 w-5 h-5 rounded flex items-center justify-center text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                  </div>
                  <?php else: ?>
                  <span class="text-xs text-gray-300 dark:text-gray-600">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5">
                  <?php
                  // Order bundle: semua order_items is_bundle=1 → tampilkan NAMA BUNDLE,
                  // bukan gabungan nama produk di dalamnya. Fallback ke item_names bila
                  // bundle-nya sudah dihapus (bundle_name null tapi has_bundle_item=1).
                  $isBundleOrder = !empty($order['bundle_name']) || (int) ($order['has_bundle_item'] ?? 0) === 1;
                  $itemLabel = !empty($order['bundle_name'])
                      ? 'Bundle ' . $order['bundle_name']
                      : ($order['item_names'] ?? '-');
                  ?>
                  <p class="text-gray-700 dark:text-gray-300 truncate max-w-[180px]"><?= e($itemLabel) ?></p>
                  <?php if ($isBundleOrder): ?>
                  <p class="text-xs text-gray-400">Paket &middot; <?= (int)$order['item_count'] ?> produk</p>
                  <?php elseif ((int)$order['item_count'] > 1): ?>
                  <p class="text-xs text-gray-400"><?= (int)$order['item_count'] ?> item</p>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5">
                  <?php $refCode = trim((string) ($order['referral_code'] ?? '')); ?>
                  <?php if ($refCode !== ''): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold"
                          style="background:color-mix(in srgb, var(--brand-color) 12%, transparent); color:var(--brand-color)">
                      <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                      Affiliate
                    </span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate max-w-[160px]"
                       title="<?= e($order['affiliate_name'] ?: $refCode) ?>">
                      <?= e($order['affiliate_name'] ?: $refCode) ?>
                    </p>
                  <?php else: ?>
                    <span class="text-xs text-gray-300 dark:text-gray-600">Langsung</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5 text-right font-semibold text-gray-900 dark:text-white">
                  <?= (float)$order['total'] > 0 ? e(formatRupiah((float)$order['total'])) : '<span class="text-green-600 dark:text-green-400 font-medium text-xs">Gratis</span>' ?>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <?= renderStatusBadge($order['status'], 'order') ?>
                  <?php if (!empty($order['mt_proof']) && $order['status'] === 'pending'): ?>
                  <span class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400" title="Bukti transfer manual menunggu konfirmasi">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>Ada Bukti
                  </span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <a href="<?= e(APP_URL) ?>/admin/transactions/<?= e($order['order_number']) ?>"
                       class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition" title="Detail">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                      </svg>
                    </a>
                    <a href="<?= e(APP_URL) ?>/invoice/<?= e($order['order_number']) ?>" target="_blank"
                       class="p-1.5 rounded-lg text-gray-400 hover:text-brand hover:bg-gray-100 dark:hover:bg-gray-800 transition" title="Invoice PDF">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                      </svg>
                    </a>
                    <?php if (($order['user_role'] ?? '') === 'user' && ($order['user_status'] ?? '') === 'active'): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/impersonate-start" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$order['user_id'] ?>">
                      <button type="submit" title="Lihat sebagai user ini"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($order['status'] === 'pending'): ?>
                    <form id="pay-<?= e($order['order_number']) ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/update-transaction" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
                      <input type="hidden" name="status" value="paid">
                      <button type="button" title="Konfirmasi Bayar"
                              onclick="confirmAndSubmit('pay-<?= e($order['order_number']) ?>',{title:'Konfirmasi Pembayaran?',message:'Order <?= e($order['order_number']) ?> akan ditandai lunas.',confirmText:'Ya, Konfirmasi',variant:'success'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-green-600 dark:hover:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <polyline points="20 6 9 17 4 12"/>
                        </svg>
                      </button>
                    </form>
                    <form id="cancel-<?= e($order['order_number']) ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/update-transaction" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
                      <input type="hidden" name="status" value="cancelled">
                      <button type="button" title="Batalkan"
                              onclick="confirmAndSubmit('cancel-<?= e($order['order_number']) ?>',{title:'Batalkan Order?',message:'Order <?= e($order['order_number']) ?> akan dibatalkan.',confirmText:'Ya, Batalkan',variant:'danger'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php
                    $delMsg = $order['status'] === 'paid'
                        ? 'PERHATIAN: Order ' . $order['order_number'] . ' sudah LUNAS. Menghapusnya akan mencabut akses member pembeli serta menghapus histori komisi & pembayaran terkait. Tindakan ini tidak dapat dibatalkan.'
                        : 'Order ' . $order['order_number'] . ' akan dihapus permanen beserta itemnya. Tindakan ini tidak dapat dibatalkan.';
                    ?>
                    <form id="del-order-<?= e($order['order_number']) ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-transaction" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
                      <button type="button" title="Hapus"
                              onclick="confirmAndSubmit('del-order-<?= e($order['order_number']) ?>',{title:'Hapus Order?',message:'<?= e($delMsg) ?>',confirmText:'Ya, Hapus',variant:'danger'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>
                        </svg>
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
        // Paginasi WAJIB mempertahankan SEMUA filter aktif (period/from/to/item/q/
        // status) — pakai helper terpusat. Sebelumnya hanya bawa q+status → pindah
        // halaman di "Lifetime" dkk loncat balik ke "Hari ini" (default period).
        $buildUrl = function(int $p) use ($buildTxnUrl): string {
            return $buildTxnUrl(['page' => $p > 1 ? $p : null]);
        };
        ?>
        <div class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
          <p class="text-xs text-gray-500 dark:text-gray-400">Halaman <?= $page ?> dari <?= $totalPages ?> &middot; <?= number_format($totalCount) ?> total</p>
          <div class="flex items-center gap-1">
            <?php if ($page > 1): ?>
            <a href="<?= e($buildUrl($page - 1)) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">‹ Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <a href="<?= e($buildUrl($p)) ?>"
               class="px-3 py-1.5 text-xs rounded-lg border transition <?= $p === $page ? 'border-[var(--brand-color)] text-[var(--brand-color)] font-semibold' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
              <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= e($buildUrl($page + 1)) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Next ›</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<?php
// ─── ADMIN ORDER NOTIFICATION (SP-05) ───────────────────────────────────────
// Dipindah ke partials/admin-order-notif.php (di-include global via sidebar-admin.php
// agar muncul di SEMUA halaman admin, bukan hanya halaman ini). Blok inline di sini
// dihapus untuk mencegah dobel polling/suara.
?>

<script>
// ── Aksi massal transaksi pending + salin WA ──
function bulkChecks() { return Array.prototype.slice.call(document.querySelectorAll('.txn-check')); }
function bulkSelected() { return bulkChecks().filter(function (c) { return c.checked; }); }
function bulkStatusOf(c) { return c.getAttribute('data-status') || ''; }
function bulkToggleBtn(id, show) { var b = document.getElementById(id); if (b) b.classList.toggle('hidden', !show); }
function bulkOnCheck() {
  var sel = bulkSelected(), all = bulkChecks();
  var bar = document.getElementById('bulkBar');
  document.getElementById('bulkCount').textContent = sel.length;
  bar.classList.toggle('hidden', sel.length === 0);
  // Tombol kontekstual: konfirmasi/batalkan hanya untuk pending, hapus hanya untuk dibatalkan.
  var nPending   = sel.filter(function (c) { return bulkStatusOf(c) === 'pending'; }).length;
  var nCancelled = sel.filter(function (c) { return bulkStatusOf(c) === 'cancelled'; }).length;
  bulkToggleBtn('bulkConfirmBtn', nPending > 0);
  bulkToggleBtn('bulkCancelBtn',  nPending > 0);
  bulkToggleBtn('bulkDeleteBtn',  nCancelled > 0);
  var checkAll = document.getElementById('checkAll');
  if (checkAll) checkAll.checked = all.length > 0 && sel.length === all.length;
}
function bulkToggleAll(cb) { bulkChecks().forEach(function (c) { c.checked = cb.checked; }); bulkOnCheck(); }
function bulkClear() { bulkChecks().forEach(function (c) { c.checked = false; }); var ca = document.getElementById('checkAll'); if (ca) ca.checked = false; bulkOnCheck(); }
function bulkRun(action) {
  // Kirim HANYA id yang relevan dgn aksi (server tetap memfilter ulang): pending
  // utk konfirmasi/batalkan, cancelled utk hapus → hitungan di modal selalu akurat.
  var relevant = action === 'delete' ? 'cancelled' : 'pending';
  var ids = bulkSelected()
    .filter(function (c) { return bulkStatusOf(c) === relevant; })
    .map(function (c) { return c.value; });
  if (!ids.length) return;
  var opts;
  if (action === 'confirm') {
    opts = { title: 'Konfirmasi ' + ids.length + ' Transaksi?', message: 'Tandai ' + ids.length + ' transaksi pending sebagai LUNAS. Akses pembeli diaktifkan & notifikasi dikirim untuk masing-masing. Lanjutkan?', confirmText: 'Ya, Konfirmasi Lunas', variant: 'success' };
  } else if (action === 'cancel') {
    opts = { title: 'Batalkan ' + ids.length + ' Transaksi?', message: 'Batalkan ' + ids.length + ' transaksi pending. Akses terkait dicabut & pembeli diberi tahu. Tindakan ini tidak dapat dikembalikan ke pending.', confirmText: 'Ya, Batalkan', variant: 'danger' };
  } else {
    opts = { title: 'Hapus ' + ids.length + ' Transaksi Dibatalkan?', message: ids.length + ' order berstatus Dibatalkan akan dihapus PERMANEN beserta itemnya. Order lunas/pending tidak akan ikut terhapus. Tindakan ini tidak dapat dibatalkan.', confirmText: 'Ya, Hapus Permanen', variant: 'danger' };
  }
  confirmAndRun(opts, function () {
    var box = document.getElementById('bulkIdsContainer');
    box.innerHTML = '';
    ids.forEach(function (id) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'order_ids[]'; i.value = id;
      box.appendChild(i);
    });
    document.getElementById('bulkActionInput').value = action;
    document.getElementById('bulkForm').submit();
  });
}
function copyWa(btn, num) {
  var done = function () { if (window.showToast) showToast('Nomor WA disalin', 'success'); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(num).then(done, function () { done(); });
  } else { done(); }
}

// ── Smooth: umpan balik visual saat pindah tab periode / chip status ──
// (halaman reload penuh; ini memberi kesan transisi mulus, bukan "beku").
(function () {
  document.querySelectorAll('.period-tab, .status-chip').forEach(function (el) {
    el.addEventListener('click', function () {
      el.style.opacity = '0.65';
      var tbl = document.querySelector('.overflow-x-auto');
      if (tbl) { tbl.style.transition = 'opacity .15s ease'; tbl.style.opacity = '0.4'; }
    });
  });
})();
</script>

<!-- ─── Modal: Transaksi Manual ──────────────────────────────── -->
<div id="manualTxnModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeManualTxn()"></div>
  <div class="absolute inset-0 flex items-start sm:items-center justify-center p-4 overflow-y-auto">
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg my-8">
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/create-transaction">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <h3 class="font-bold text-gray-900 dark:text-white">Input Transaksi Manual</h3>
          <button type="button" onclick="closeManualTxn()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <div class="px-5 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
          <!-- Pembeli: Member Lama / Member Baru -->
          <div>
            <input type="hidden" name="buyer_mode" id="mtBuyerMode" value="existing">
            <div class="flex gap-1 mb-2">
              <button type="button" id="mtTabExisting" onclick="mtBuyerTab('existing')" class="mt-tab mt-tab-active">Member Lama</button>
              <button type="button" id="mtTabNew" onclick="mtBuyerTab('new')" class="mt-tab">Member Baru</button>
            </div>
            <!-- Member Lama (combobox pencarian: nama / email / WA) -->
            <div id="mtExistingBox">
              <div class="relative">
                <input type="hidden" name="user_id" id="mtUserId" value="">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" id="mtUserSearch" class="mt-inp" style="padding-left:2.25rem" autocomplete="off"
                       placeholder="Cari nama / email / nomor WA member...">
                <button type="button" id="mtUserClear" onclick="mtUserSelect(null)"
                        class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <div id="mtUserDrop" class="hidden absolute z-20 mt-1 w-full max-h-64 overflow-y-auto bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg"></div>
              </div>
              <p id="mtUserErr" class="hidden text-xs text-red-500 mt-1">Pilih member dari hasil pencarian dulu.</p>
            </div>
            <!-- Member Baru -->
            <div id="mtNewBox" class="hidden space-y-2">
              <div class="grid grid-cols-2 gap-3">
                <input type="text" name="buyer_name" id="mtBuyerName" class="mt-inp" placeholder="Nama pembeli">
                <input type="text" name="buyer_wa" id="mtBuyerWa" class="mt-inp" placeholder="WhatsApp (08xxx)" oninput="mtDupCheck()">
              </div>
              <input type="email" name="buyer_email" id="mtBuyerEmail" class="mt-inp" placeholder="Email pembeli" oninput="mtDupCheck()">
              <!-- Deteksi akun existing: email/WA yang diketik dicek ke daftar member -->
              <div id="mtDupHint" class="hidden rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/50 px-3 py-2.5">
                <p class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed"><span id="mtDupText"></span></p>
                <button type="button" id="mtDupUse" class="mt-1.5 text-xs font-semibold underline text-amber-800 dark:text-amber-300">Pakai akun ini (pindah ke Member Lama)</button>
              </div>
              <p class="text-[11px] text-gray-400 dark:text-gray-500">Akun member dibuat otomatis; password sementara &amp; info login dikirim ke pembeli. Bila email sudah terdaftar, order dikaitkan ke akun itu.</p>
            </div>
          </div>

          <!-- Produk (combobox pencarian) -->
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Produk <span class="text-red-500">*</span></label>
            <div class="relative">
              <input type="hidden" name="product_id" id="mtProduct" value="">
              <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
              <input type="text" id="mtProductSearch" class="mt-inp" style="padding-left:2.25rem" autocomplete="off"
                     placeholder="Cari nama produk...">
              <button type="button" id="mtProductClear" onclick="mtProductSelect(null)"
                      class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
              <div id="mtProductDrop" class="hidden absolute z-20 mt-1 w-full max-h-64 overflow-y-auto bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg"></div>
            </div>
            <p id="mtProductErr" class="hidden text-xs text-red-500 mt-1">Pilih produk dari hasil pencarian dulu.</p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Harga Satuan (<?= e(currencySymbol()) ?>)</label>
              <input type="number" name="price" id="mtPrice" min="0" step="any" value="0" oninput="mtCalc()" class="mt-inp">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Jumlah</label>
              <input type="number" name="quantity" id="mtQty" min="1" step="1" value="1" oninput="mtCalc()" class="mt-inp">
            </div>
          </div>

          <!-- Info langganan + biaya awal (muncul utk produk berjangka / ber-setup-fee) -->
          <div id="mtSubNote" class="hidden text-xs text-[var(--brand-color)] bg-[color-mix(in_srgb,var(--brand-color)_8%,transparent)] rounded-lg px-3 py-2"></div>

          <!-- Tanggal Mulai Langganan (opsional, hanya produk berjangka) — utk migrasi
               langganan yg sudah berjalan sebelumnya (mis. dari Sejoli/platform lain). -->
          <div id="mtStartDateRow" class="hidden">
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Tanggal Mulai Langganan <span class="text-gray-400 font-normal">(opsional)</span></label>
            <input type="date" name="access_start_date" id="mtStartDate" max="<?= e(date('Y-m-d')) ?>" class="mt-inp">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Kosongkan = mulai hari ini (normal). Isi dengan tanggal pembayaran terakhir di platform lama untuk memindahkan langganan yang sudah berjalan — masa berlaku &amp; reminder perpanjangan dihitung dari tanggal ini.</p>
          </div>

          <label id="mtSetupRow" class="hidden items-center gap-2.5 cursor-pointer select-none">
            <input type="checkbox" name="include_setup_fee" id="mtSetupFee" value="1" checked onchange="mtCalc()"
                   class="w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600">
            <span class="text-sm text-gray-600 dark:text-gray-300">Sertakan biaya awal <strong id="mtSetupAmt"></strong> <span class="text-gray-400 font-normal">(pembelian pertama)</span></span>
          </label>

          <div class="flex items-center justify-between rounded-lg bg-gray-50 dark:bg-gray-800/60 px-4 py-3">
            <span class="text-sm text-gray-500 dark:text-gray-400">Total</span>
            <span id="mtTotal" class="text-base font-bold text-gray-900 dark:text-white"><?= e(formatRupiah(0)) ?></span>
          </div>

          <!-- Status -->
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Status</label>
            <select name="status" id="mtStatus" onchange="mtStatusNote()" class="mt-inp">
              <option value="pending">Pending (belum bayar)</option>
              <option value="paid">Lunas (langsung aktifkan akses)</option>
            </select>
            <p id="mtPaidNote" class="hidden text-xs text-green-600 dark:text-green-400 mt-1.5">✓ Akses produk akan diaktifkan & notifikasi pembayaran dikirim ke pembeli.</p>
          </div>

          <!-- Assign Komisi ke Affiliate (opsional, combobox pencarian) -->
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Assign Komisi ke Affiliate <span class="text-gray-400 font-normal">(opsional)</span></label>
            <div class="relative">
              <input type="hidden" name="affiliate_id" id="mtAff" value="">
              <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
              <input type="text" id="mtAffSearch" class="mt-inp" style="padding-left:2.25rem" autocomplete="off"
                     placeholder="Cari nama / email / kode affiliate...">
              <button type="button" id="mtAffClear" onclick="mtAffSelect(null)"
                      class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
              <div id="mtAffDrop" class="hidden absolute z-20 mt-1 w-full max-h-64 overflow-y-auto bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg"></div>
            </div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Komisi dibuat berstatus <strong>pending</strong> (masuk approval di tab Pencairan). Kosongkan bila transaksi ini bukan dari affiliate. Tidak bisa diassign ke akun pembeli sendiri.</p>
          </div>

          <!-- Senyapkan Notifikasi -->
          <label class="flex items-start gap-2.5 cursor-pointer select-none">
            <input type="checkbox" name="mute_notifications" id="mtMute" value="1" onchange="mtStatusNote(); mtSyncVendorLic()"
                   class="mt-0.5 w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600">
            <span class="text-sm text-gray-600 dark:text-gray-300">Senyapkan notifikasi <span class="text-gray-400 font-normal">(jangan kirim WA &amp; email — termasuk info akun bila Member Baru)</span></span>
          </label>

          <!-- Terbitkan lisensi vendor (hanya produk Averion berlisensi di install vendor).
               Default cerdas: penjualan asli (tak di-mute) → nyala; migrasi (di-mute) → mati. -->
          <label id="mtVendorLicRow" class="hidden items-start gap-2.5 cursor-pointer select-none">
            <input type="checkbox" name="issue_vendor_license" id="mtVendorLic" value="1"
                   class="mt-0.5 w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600">
            <span class="text-sm text-gray-600 dark:text-gray-300">Terbitkan lisensi vendor ke server lisensi <span class="text-gray-400 font-normal">(nyalakan untuk penjualan asli; matikan untuk migrasi data — pembeli sudah punya lisensi lama)</span></span>
          </label>

          <!-- Catatan -->
          <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Catatan <span class="text-gray-400 font-normal">(opsional)</span></label>
            <textarea name="notes" rows="2" class="mt-inp" placeholder="Mis. pembayaran transfer manual / offline"></textarea>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100 dark:border-gray-800">
          <button type="button" onclick="closeManualTxn()" class="px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Batal</button>
          <button type="submit" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90" style="background-color:var(--brand-color)">Simpan Transaksi</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .mt-inp { width:100%; padding:0.55rem 0.75rem; border-radius:0.625rem; font-size:0.875rem;
            border:1px solid rgb(229 231 235); background:#fff; color:rgb(17 24 39); }
  .dark .mt-inp { border-color:rgb(55 65 81); background:rgb(17 24 39); color:#fff; }
  .mt-inp:focus { outline:none; border-color:var(--brand-color); }
  .mt-tab { padding:0.4rem 0.9rem; border-radius:0.5rem; font-size:0.8rem; font-weight:600;
            color:rgb(107 114 128); background:transparent; border:1px solid transparent; transition:all .15s; }
  .mt-tab:hover { color:rgb(55 65 81); }
  .dark .mt-tab { color:rgb(156 163 175); }
  .mt-tab-active { color:var(--brand-color); background:color-mix(in srgb, var(--brand-color) 10%, transparent);
                   border-color:color-mix(in srgb, var(--brand-color) 25%, transparent); }
</style>

<script>
// Produk untuk pencarian client-side (jumlah kecil). Member dicari server-side
// via /actions/admin/search-users (member bisa ribuan, inline mentok di limit).
var MT_PRODUCTS = <?= json_encode(array_map(fn($pr) => [
    'id'       => (int) $pr['id'],
    'name'     => (string) $pr['name'],
    'price'    => (float) $pr['price'],
    'setup'    => (float) ($pr['setup_fee'] ?? 0),
    'duration' => (int) ($pr['access_duration_days'] ?? 0),
    // Produk berlisensi vendor? (hanya true di install vendor yg memetakan produk ini →
    // checkbox "Terbitkan lisensi vendor" muncul; di install pembeli selalu false).
    'vendor'   => (function_exists('vfAvailable') && vfAvailable() && function_exists('vfPlanForProduct') && vfPlanForProduct((int) $pr['id']) !== ''),
], $productsList), JSON_UNESCAPED_UNICODE) ?>;

function openManualTxn() { document.getElementById('manualTxnModal').classList.remove('hidden'); }
function closeManualTxn() { document.getElementById('manualTxnModal').classList.add('hidden'); }

function mtBuyerTab(mode) {
  document.getElementById('mtBuyerMode').value = mode;
  var isNew = mode === 'new';
  document.getElementById('mtExistingBox').classList.toggle('hidden', isNew);
  document.getElementById('mtNewBox').classList.toggle('hidden', !isNew);
  document.getElementById('mtTabExisting').classList.toggle('mt-tab-active', !isNew);
  document.getElementById('mtTabNew').classList.toggle('mt-tab-active', isNew);
}

function mtEsc(s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
  });
}
function mtDigits(s) { return String(s).replace(/\D/g, ''); }
// Samakan variasi 08xx / 628xx / 8xx agar pencarian & cek duplikat WA cocok
// walau format simpan berbeda.
function mtNormWa(s) {
  var d = mtDigits(s);
  if (d.slice(0, 2) === '62') d = d.slice(2);
  else if (d[0] === '0')      d = d.slice(1);
  return d;
}

// ── Combobox generik: input pencarian + dropdown hasil ──
function mtCombo(cfg) {
  var input = document.getElementById(cfg.inputId);
  var drop  = document.getElementById(cfg.dropId);

  function render(items) {
    if (!items.length) {
      drop.innerHTML = '<div class="px-3.5 py-3 text-sm text-gray-400 dark:text-gray-500">Tidak ada hasil.</div>';
    } else {
      drop.innerHTML = items.slice(0, 30).map(function (it) {
        return '<button type="button" data-id="' + it.id + '" class="mt-drop-item block w-full text-left px-3.5 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800">'
             + cfg.renderItem(it) + '</button>';
      }).join('');
    }
    drop.classList.remove('hidden');
  }
  input.addEventListener('input', function () {
    cfg.onClear();
    var q = input.value.trim().toLowerCase();
    render(q === '' ? cfg.items() : cfg.items().filter(function (it) { return cfg.match(it, q); }));
  });
  input.addEventListener('focus', function () {
    if (input.readOnly) return;
    var q = input.value.trim().toLowerCase();
    render(q === '' ? cfg.items() : cfg.items().filter(function (it) { return cfg.match(it, q); }));
  });
  // Tutup saat klik di luar (mousedown agar menang dari blur).
  document.addEventListener('mousedown', function (e) {
    if (!drop.contains(e.target) && e.target !== input) drop.classList.add('hidden');
  });
  drop.addEventListener('click', function (e) {
    var btn = e.target.closest('.mt-drop-item');
    if (!btn) return;
    var it = cfg.items().find(function (x) { return String(x.id) === btn.dataset.id; });
    if (it) cfg.onSelect(it);
    drop.classList.add('hidden');
  });
}

// ── Member Lama (pencarian server-side, debounce 250ms) ──
var mtSelUser = null;
function mtUserSelect(u) {
  mtSelUser = u;
  var input = document.getElementById('mtUserSearch');
  document.getElementById('mtUserId').value = u ? u.id : '';
  document.getElementById('mtUserClear').classList.toggle('hidden', !u);
  document.getElementById('mtUserErr').classList.add('hidden');
  input.value    = u ? (u.name + ' — ' + u.email) : '';
  input.readOnly = !!u; // terkunci saat terpilih; klik X untuk ganti
  if (!u) input.focus();
}
// Cache respons per kata kunci (hindari request ulang saat mengetik bolak-balik)
var mtUserCache = {};
function mtSearchUsers(q) {
  if (mtUserCache[q]) return Promise.resolve(mtUserCache[q]);
  return fetch('<?= e(APP_URL) ?>/actions/admin/search-users?q=' + encodeURIComponent(q), {credentials: 'same-origin'})
    .then(function (r) { return r.json(); })
    .then(function (d) { mtUserCache[q] = d.users || []; return mtUserCache[q]; })
    .catch(function () { return []; });
}
(function () {
  var input = document.getElementById('mtUserSearch');
  var drop  = document.getElementById('mtUserDrop');
  var timer = null, reqSeq = 0;

  function render(users) {
    if (!users.length) {
      drop.innerHTML = '<div class="px-3.5 py-3 text-sm text-gray-400 dark:text-gray-500">Tidak ada hasil.</div>';
    } else {
      drop.innerHTML = users.map(function (u) {
        return '<button type="button" data-id="' + u.id + '" class="mt-drop-item block w-full text-left px-3.5 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800">'
             + '<span class="font-medium text-gray-800 dark:text-gray-200">' + mtEsc(u.name) + '</span>'
             + '<span class="block text-xs text-gray-400 dark:text-gray-500">' + mtEsc(u.email) + (u.phone ? ' · ' + mtEsc(u.phone) : '') + '</span>'
             + '</button>';
      }).join('');
    }
    drop.classList.remove('hidden');
    drop.dataset.users = JSON.stringify(users);
  }
  function search() {
    var q   = input.value.trim();
    var seq = ++reqSeq;
    drop.innerHTML = '<div class="px-3.5 py-3 text-sm text-gray-400 dark:text-gray-500">Mencari...</div>';
    drop.classList.remove('hidden');
    mtSearchUsers(q).then(function (users) {
      if (seq === reqSeq) render(users); // buang respons basi (race ketikan cepat)
    });
  }
  input.addEventListener('input', function () {
    mtSelUser = null;
    document.getElementById('mtUserId').value = '';
    document.getElementById('mtUserClear').classList.add('hidden');
    clearTimeout(timer);
    timer = setTimeout(search, 250);
  });
  input.addEventListener('focus', function () { if (!input.readOnly) search(); });
  document.addEventListener('mousedown', function (e) {
    if (!drop.contains(e.target) && e.target !== input) drop.classList.add('hidden');
  });
  drop.addEventListener('click', function (e) {
    var btn = e.target.closest('.mt-drop-item');
    if (!btn) return;
    var users = JSON.parse(drop.dataset.users || '[]');
    var u = users.find(function (x) { return String(x.id) === btn.dataset.id; });
    if (u) mtUserSelect(u);
    drop.classList.add('hidden');
  });
})();

// ── Assign Affiliate (opsional; pencarian server-side, pola sama dgn member) ──
function mtAffSelect(a) {
  var input = document.getElementById('mtAffSearch');
  document.getElementById('mtAff').value = a ? a.id : '';
  document.getElementById('mtAffClear').classList.toggle('hidden', !a);
  input.value    = a ? (a.name + ' — ' + a.code) : '';
  input.readOnly = !!a;
  if (!a) input.focus();
}
var mtAffCache = {};
function mtSearchAff(q) {
  if (mtAffCache[q]) return Promise.resolve(mtAffCache[q]);
  return fetch('<?= e(APP_URL) ?>/actions/admin/search-affiliates?q=' + encodeURIComponent(q), {credentials: 'same-origin'})
    .then(function (r) { return r.json(); })
    .then(function (d) { mtAffCache[q] = d.affiliates || []; return mtAffCache[q]; })
    .catch(function () { return []; });
}
(function () {
  var input = document.getElementById('mtAffSearch');
  var drop  = document.getElementById('mtAffDrop');
  if (!input || !drop) return;
  var timer = null, reqSeq = 0;
  function render(list) {
    if (!list.length) {
      drop.innerHTML = '<div class="px-3.5 py-3 text-sm text-gray-400 dark:text-gray-500">Tidak ada affiliate.</div>';
    } else {
      drop.innerHTML = list.map(function (a) {
        return '<button type="button" data-id="' + a.id + '" class="mt-drop-item block w-full text-left px-3.5 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800">'
             + '<span class="font-medium text-gray-800 dark:text-gray-200">' + mtEsc(a.name) + '</span>'
             + '<span class="block text-xs text-gray-400 dark:text-gray-500">' + mtEsc(a.email) + ' · ' + mtEsc(a.code) + '</span>'
             + '</button>';
      }).join('');
    }
    drop.classList.remove('hidden');
    drop.dataset.list = JSON.stringify(list);
  }
  function search() {
    var q = input.value.trim(), seq = ++reqSeq;
    drop.innerHTML = '<div class="px-3.5 py-3 text-sm text-gray-400 dark:text-gray-500">Mencari...</div>';
    drop.classList.remove('hidden');
    mtSearchAff(q).then(function (list) { if (seq === reqSeq) render(list); });
  }
  input.addEventListener('input', function () {
    document.getElementById('mtAff').value = '';
    document.getElementById('mtAffClear').classList.add('hidden');
    clearTimeout(timer);
    timer = setTimeout(search, 250);
  });
  input.addEventListener('focus', function () { if (!input.readOnly) search(); });
  document.addEventListener('mousedown', function (e) {
    if (!drop.contains(e.target) && e.target !== input) drop.classList.add('hidden');
  });
  drop.addEventListener('click', function (e) {
    var btn = e.target.closest('.mt-drop-item');
    if (!btn) return;
    var list = JSON.parse(drop.dataset.list || '[]');
    var a = list.find(function (x) { return String(x.id) === btn.dataset.id; });
    if (a) mtAffSelect(a);
    drop.classList.add('hidden');
  });
})();

// ── Produk ──
var mtSelProduct = null;
function mtProductSelect(p) {
  mtSelProduct = p;
  var input = document.getElementById('mtProductSearch');
  document.getElementById('mtProduct').value = p ? p.id : '';
  document.getElementById('mtProductClear').classList.toggle('hidden', !p);
  document.getElementById('mtProductErr').classList.add('hidden');
  input.value    = p ? (p.name + ' (' + window.fmtMoney(p.price) + ')') : '';
  input.readOnly = !!p;
  mtSyncPrice();
  if (!p) input.focus();
}
mtCombo({
  inputId: 'mtProductSearch', dropId: 'mtProductDrop',
  items: function () { return MT_PRODUCTS; },
  match: function (p, q) { return p.name.toLowerCase().indexOf(q) !== -1; },
  renderItem: function (p) {
    return '<span class="font-medium text-gray-800 dark:text-gray-200">' + mtEsc(p.name) + '</span>'
         + '<span class="block text-xs text-gray-400 dark:text-gray-500">' + window.fmtMoney(p.price)
         + (p.duration > 0 ? ' · ' + p.duration + ' hari' : '') + '</span>';
  },
  onSelect: mtProductSelect,
  onClear:  function () { mtSelProduct = null; document.getElementById('mtProduct').value = ''; document.getElementById('mtProductClear').classList.add('hidden'); mtSyncPrice(); }
});

// ── Member Baru: cek akun existing saat email/WA diketik (server-side) ──
var mtDupUser = null, mtDupTimer = null, mtDupSeq = 0;
function mtDupCheck() {
  clearTimeout(mtDupTimer);
  mtDupTimer = setTimeout(mtDupCheckNow, 350);
}
function mtDupShow(u) {
  mtDupUser = u;
  var hint = document.getElementById('mtDupHint');
  if (u) {
    document.getElementById('mtDupText').innerHTML =
      'Sudah terdaftar atas nama <strong>' + mtEsc(u.name) + '</strong> (' + mtEsc(u.email) + '). Order sebaiknya dikaitkan ke akun itu.';
    hint.classList.remove('hidden');
  } else {
    hint.classList.add('hidden');
  }
}
function mtDupCheckNow() {
  var email = document.getElementById('mtBuyerEmail').value.trim().toLowerCase();
  var wa    = mtNormWa(document.getElementById('mtBuyerWa').value);
  var seq   = ++mtDupSeq;

  var byEmail = email.length >= 5
    ? mtSearchUsers(email).then(function (users) {
        return users.find(function (u) { return u.email.toLowerCase() === email; }) || null;
      })
    : Promise.resolve(null);

  byEmail.then(function (found) {
    if (found || wa.length < 9) return found;
    return mtSearchUsers(wa).then(function (users) {
      return users.find(function (u) { return u.phone && mtNormWa(u.phone) === wa; }) || null;
    });
  }).then(function (found) {
    if (seq === mtDupSeq) mtDupShow(found); // buang hasil basi
  });
}
document.getElementById('mtDupUse').addEventListener('click', function () {
  if (!mtDupUser) return;
  mtBuyerTab('existing');
  mtUserSelect(mtDupUser);
});

// ── Validasi submit: pastikan pilihan dari combobox, bukan teks bebas ──
document.querySelector('#manualTxnModal form').addEventListener('submit', function (e) {
  var ok = true;
  if (document.getElementById('mtBuyerMode').value === 'existing' && !document.getElementById('mtUserId').value) {
    document.getElementById('mtUserErr').classList.remove('hidden'); ok = false;
  }
  if (!document.getElementById('mtProduct').value) {
    document.getElementById('mtProductErr').classList.remove('hidden'); ok = false;
  }
  if (!ok) e.preventDefault();
});

function mtSyncPrice() {
  var price = mtSelProduct ? mtSelProduct.price : 0;
  document.getElementById('mtPrice').value = isNaN(price) ? 0 : price;

  var setup = mtSelProduct ? mtSelProduct.setup : 0;
  var dur   = mtSelProduct ? mtSelProduct.duration : 0;

  var note = document.getElementById('mtSubNote');
  var startRow = document.getElementById('mtStartDateRow');
  if (dur > 0) {
    note.textContent = 'Produk berlangganan · akses ' + dur + ' hari sejak tanggal mulai (default hari ini, bisa di-backdate di bawah).';
    note.classList.remove('hidden');
    startRow.classList.remove('hidden');
  } else {
    note.classList.add('hidden');
    startRow.classList.add('hidden');
    document.getElementById('mtStartDate').value = '';
  }

  var row = document.getElementById('mtSetupRow');
  if (setup > 0) {
    document.getElementById('mtSetupAmt').textContent = window.fmtMoney(setup);
    document.getElementById('mtSetupFee').checked = true;
    row.classList.remove('hidden'); row.classList.add('flex');
  } else { row.classList.add('hidden'); row.classList.remove('flex'); }

  mtSyncVendorLic();
  mtCalc();
}
// Checkbox "Terbitkan lisensi vendor": tampil hanya untuk produk berlisensi vendor.
// Default cerdas — penjualan asli (tak di-mute) → tercentang; migrasi (di-mute) → tidak.
function mtSyncVendorLic() {
  var row = document.getElementById('mtVendorLicRow');
  var cb  = document.getElementById('mtVendorLic');
  if (!row || !cb) return;
  var isVendor = !!(mtSelProduct && mtSelProduct.vendor);
  row.classList.toggle('hidden', !isVendor);
  row.classList.toggle('flex', isVendor);
  if (isVendor) cb.checked = !document.getElementById('mtMute').checked;
}
function mtCalc() {
  var price = parseFloat(document.getElementById('mtPrice').value || '0');
  var qty   = parseInt(document.getElementById('mtQty').value || '1', 10);
  if (isNaN(price) || price < 0) price = 0;
  if (isNaN(qty) || qty < 1) qty = 1;
  var total = price * qty;

  var setup = mtSelProduct ? mtSelProduct.setup : 0;
  var row   = document.getElementById('mtSetupRow');
  var cb    = document.getElementById('mtSetupFee');
  if (setup > 0 && !row.classList.contains('hidden') && cb.checked) total += setup;

  document.getElementById('mtTotal').textContent = window.fmtMoney(total);
}
function mtStatusNote() {
  document.getElementById('mtPaidNote').classList.toggle('hidden', document.getElementById('mtStatus').value !== 'paid');
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
