<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('coupons')) { redirect('/admin/no-access'); }

$pdo = getDB();

// ─── Filter & Pagination ──────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status'] ?? '';
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = 'c.code LIKE ?';
    $params[] = '%' . $search . '%';
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}

$whereStr = implode(' AND ', $where);

try {
    $total = (int) $pdo->prepare("SELECT COUNT(*) FROM coupons c WHERE $whereStr")->execute($params) ?
        $pdo->prepare("SELECT COUNT(*) FROM coupons c WHERE $whereStr") : 0;
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM coupons c WHERE $whereStr");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = (int) ceil($totalRows / $perPage);

    $listStmt = $pdo->prepare(
        "SELECT c.*, p.name AS product_name
         FROM coupons c
         LEFT JOIN products p ON p.id = c.applicable_product_id
         WHERE $whereStr
         ORDER BY c.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $listStmt->execute($params);
    $coupons = $listStmt->fetchAll();

    // Untuk dropdown produk di modal form
    $productStmt = $pdo->query("SELECT id, name, price FROM products WHERE status='active' ORDER BY name ASC");
    $allProducts = $productStmt->fetchAll();

} catch (Exception $e) {
    error_log('Coupons list error: ' . $e->getMessage());
    $coupons    = [];
    $totalRows  = 0;
    $totalPages = 1;
    $allProducts = [];
}

$now = date('Y-m-d H:i:s');
generateCSRF();

$pageTitle   = 'Kupon Diskon';
$topbarTitle = 'Kupon Diskon';
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
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-xl font-bold text-gray-900 dark:text-white">Kupon Diskon</h1>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= number_format($totalRows) ?> kupon ditemukan</p>
        </div>
        <button onclick="openCouponModal()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition hover:opacity-90"
                style="background-color:var(--brand-color)">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Buat Kupon
        </button>
      </div>

      <!-- Filter Bar -->
      <form method="GET" class="flex flex-wrap gap-3 mb-5">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Cari kode kupon..."
               class="px-3.5 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none w-56">
        <select name="status"
                class="px-3.5 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
          <option value="">Semua Status</option>
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktif</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
        </select>
        <button type="submit"
                class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 hover:opacity-80 transition">
          Filter
        </button>
        <?php if ($search || $status): ?>
        <a href="<?= e(APP_URL) ?>/admin/coupons"
           class="px-4 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
          Reset
        </a>
        <?php endif; ?>
      </form>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <th class="text-left px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Kode</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Nilai Diskon</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Min. Belanja</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Penggunaan</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Berlaku Untuk</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Periode</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Status</th>
                <th class="text-right px-4 py-3 font-semibold text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wide">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
              <?php if (empty($coupons)): ?>
              <tr>
                <td colspan="8" class="px-4 py-12 text-center text-sm text-gray-400 dark:text-gray-600">
                  Belum ada kupon<?= ($search || $status) ? ' yang cocok dengan filter' : '' ?>.
                </td>
              </tr>
              <?php else: ?>
              <?php foreach ($coupons as $c): ?>
              <?php
                // Status efektif: Nonaktif (pilihan admin) > Kedaluwarsa (tanggal lewat)
                // > Kuota habis (usage_limit tercapai) > Aktif. Memperjelas kenapa kupon
                // tak bisa dipakai (sebelumnya "Expired" saja, membingungkan).
                $isExpired = $c['expires_at'] && $c['expires_at'] < $now;
                $isSoldOut = $c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit'];
                if ($c['status'] !== 'active') {
                    $badgeLabel = 'Nonaktif';
                    $badgeClass = 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400';
                } elseif ($isExpired) {
                    $badgeLabel = 'Kedaluwarsa';
                    $badgeClass = 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400';
                } elseif ($isSoldOut) {
                    $badgeLabel = 'Kuota habis';
                    $badgeClass = 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400';
                } else {
                    $badgeLabel = 'Aktif';
                    $badgeClass = 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400';
                }
              ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition">
                <td class="px-4 py-3">
                  <span class="font-mono font-bold text-gray-900 dark:text-white text-sm"><?= e($c['code']) ?></span>
                </td>
                <td class="px-4 py-3">
                  <?php if ($c['type'] === 'percentage'): ?>
                  <span class="font-semibold text-[var(--brand-color)]"><?= (float)$c['value'] ?>%</span>
                  <?php if ($c['max_discount']): ?>
                  <span class="text-xs text-gray-400 block">maks <?= e(formatRupiah($c['max_discount'])) ?></span>
                  <?php endif; ?>
                  <?php else: ?>
                  <span class="font-semibold text-[var(--brand-color)]"><?= e(formatRupiah($c['value'])) ?></span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600 dark:text-gray-300 text-xs">
                  <?= $c['min_purchase'] > 0 ? e(formatRupiah($c['min_purchase'])) : '<span class="text-gray-400">—</span>' ?>
                </td>
                <td class="px-4 py-3">
                  <span class="text-gray-900 dark:text-white font-medium"><?= (int)$c['used_count'] ?></span>
                  <span class="text-gray-400"> / </span>
                  <span class="text-gray-500 dark:text-gray-400"><?= $c['usage_limit'] !== null ? (int)$c['usage_limit'] : '∞' ?></span>
                </td>
                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                  <?= $c['product_name'] ? e($c['product_name']) : '<span class="text-gray-400">Semua Produk</span>' ?>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                  <?php if ($c['starts_at'] || $c['expires_at']): ?>
                  <?= $c['starts_at'] ? date('d/m/Y', strtotime($c['starts_at'])) : '—' ?>
                  <span class="text-gray-300 dark:text-gray-600"> s/d </span>
                  <?= $c['expires_at'] ? date('d/m/Y', strtotime($c['expires_at'])) : '—' ?>
                  <?php else: ?>
                  <span class="text-gray-400">Tidak terbatas</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $badgeClass ?>">
                    <?= e($badgeLabel) ?>
                  </span>
                </td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-0.5">
                    <button onclick='openCouponModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'
                            class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                    </button>
                    <form id="del-coupon-<?= (int)$c['id'] ?>" method="POST"
                          action="<?= e(APP_URL) ?>/actions/admin/delete-coupon" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="coupon_id" value="<?= (int)$c['id'] ?>">
                      <button type="button"
                              onclick="confirmAndSubmit('del-coupon-<?= (int)$c['id'] ?>',{title:'Hapus Kupon?',message:'Kupon <?= e($c['code']) ?> akan dihapus atau dinonaktifkan.',confirmText:'Ya, Hapus',variant:'danger'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <polyline points="3 6 5 6 21 6"/>
                          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                          <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                        </svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between">
          <p class="text-xs text-gray-500 dark:text-gray-400">
            Halaman <?= $page ?> dari <?= $totalPages ?>
          </p>
          <div class="flex gap-1">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?page=<?= $p ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status ? '&status=' . urlencode($status) : '' ?>"
               class="px-3 py-1 rounded-lg text-xs font-medium transition <?= $p === $page ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800' ?>">
              <?= $p ?>
            </a>
            <?php endfor; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- ─── Modal Form Kupon ───────────────────────────────────────── -->
<div id="couponModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
  <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="closeCouponModal()"></div>
  <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-100 dark:border-gray-800 w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900 z-10">
      <h3 id="couponModalTitle" class="font-bold text-gray-900 dark:text-white text-base">Buat Kupon</h3>
      <button onclick="closeCouponModal()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="couponForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-coupon" class="p-5 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="coupon_id" id="couponId" value="">

      <!-- Kode -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
          Kode Kupon <span class="text-red-500">*</span>
        </label>
        <input type="text" name="code" id="couponCode" required maxlength="50"
               placeholder="DISKON10"
               oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9-]/g,'')"
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        <p class="text-xs text-gray-400 mt-1">Hanya huruf kapital, angka, dan tanda hubung (-)</p>
      </div>

      <!-- Tipe -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
          Tipe Diskon <span class="text-red-500">*</span>
        </label>
        <div class="flex gap-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" name="type" id="typePercentage" value="percentage" checked
                   onchange="toggleMaxDiscount()"
                   class="accent-[var(--brand-color)]">
            <span class="text-sm text-gray-700 dark:text-gray-300">Persentase (%)</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" name="type" id="typeFixed" value="fixed"
                   onchange="toggleMaxDiscount()"
                   class="accent-[var(--brand-color)]">
            <span class="text-sm text-gray-700 dark:text-gray-300">Nominal (<?= e(currencySymbol()) ?>)</span>
          </label>
        </div>
      </div>

      <!-- Nilai & Max Diskon -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Nilai <span class="text-red-500">*</span>
          </label>
          <div class="relative">
            <span id="valuePrefix" class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">%</span>
            <input type="number" name="value" id="couponValue" required min="0.01" step="0.01"
                   class="w-full pl-7 pr-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
          </div>
        </div>
        <div id="maxDiscountWrap">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Maks. Diskon <span class="text-xs text-gray-400 font-normal">(opsional)</span>
          </label>
          <input type="number" name="max_discount" id="couponMaxDiscount" min="0" step="1"
                 placeholder="Tanpa batas"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        </div>
      </div>

      <!-- Min Pembelian & Usage Limit -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Min. Pembelian
          </label>
          <input type="number" name="min_purchase" id="couponMinPurchase" min="0" step="1" value="0"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Limit Penggunaan <span class="text-xs text-gray-400 font-normal">(kosong=∞)</span>
          </label>
          <input type="number" name="usage_limit" id="couponUsageLimit" min="1" step="1"
                 placeholder="Unlimited"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        </div>
      </div>

      <!-- Berlaku Untuk Produk -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
          Berlaku Untuk
        </label>
        <select name="applicable_product_id" id="couponProductId"
                class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
          <option value="">Semua Produk</option>
          <?php foreach ($allProducts as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-price="<?= (float)$p['price'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Preview diskon (live) — bantu admin lihat potongan & harga akhir sesungguhnya -->
      <div id="couponPreview" class="hidden rounded-lg p-3 text-sm"
           style="border:1px solid color-mix(in srgb, var(--brand-color) 30%, transparent); background:color-mix(in srgb, var(--brand-color) 7%, transparent)">
        <div class="flex items-start gap-2">
          <svg class="w-4 h-4 mt-0.5 shrink-0" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
          </svg>
          <div id="couponPreviewBody" class="text-gray-700 dark:text-gray-300 leading-relaxed"></div>
        </div>
      </div>

      <!-- Status -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Status</label>
        <select name="status" id="couponStatus"
                class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
          <option value="active">Aktif</option>
          <option value="inactive">Nonaktif</option>
        </select>
      </div>

      <!-- Periode -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Mulai <span class="text-xs text-gray-400 font-normal">(opsional)</span>
          </label>
          <input type="datetime-local" name="starts_at" id="couponStartsAt"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Berakhir <span class="text-xs text-gray-400 font-normal">(opsional)</span>
          </label>
          <input type="datetime-local" name="expires_at" id="couponExpiresAt"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
        </div>
      </div>

      <!-- Submit -->
      <div class="flex gap-2.5 pt-2">
        <button type="button" onclick="closeCouponModal()"
                class="flex-1 px-4 py-2.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
          Batal
        </button>
        <button type="submit"
                class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
                style="background-color:var(--brand-color)">
          Simpan Kupon
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openCouponModal(coupon) {
    const modal = document.getElementById('couponModal');
    const title = document.getElementById('couponModalTitle');

    // Reset form
    document.getElementById('couponForm').reset();
    document.getElementById('couponId').value = '';
    toggleMaxDiscount();

    if (coupon) {
        title.textContent = 'Edit Kupon';
        document.getElementById('couponId').value = coupon.id;
        document.getElementById('couponCode').value = coupon.code;
        document.getElementById('couponValue').value = coupon.value;
        document.getElementById('couponMinPurchase').value = coupon.min_purchase;
        document.getElementById('couponUsageLimit').value = coupon.usage_limit || '';
        document.getElementById('couponMaxDiscount').value = coupon.max_discount || '';
        document.getElementById('couponProductId').value = coupon.applicable_product_id || '';
        document.getElementById('couponStatus').value = coupon.status;

        // Tipe
        if (coupon.type === 'fixed') {
            document.getElementById('typeFixed').checked = true;
        } else {
            document.getElementById('typePercentage').checked = true;
        }
        toggleMaxDiscount();

        // Periode — convert datetime ke datetime-local format
        if (coupon.starts_at) {
            document.getElementById('couponStartsAt').value = coupon.starts_at.slice(0, 16);
        }
        if (coupon.expires_at) {
            document.getElementById('couponExpiresAt').value = coupon.expires_at.slice(0, 16);
        }
    } else {
        title.textContent = 'Buat Kupon';
    }

    updateCouponPreview();
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeCouponModal() {
    const modal = document.getElementById('couponModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function toggleMaxDiscount() {
    const isPercentage = document.getElementById('typePercentage').checked;
    const maxWrap = document.getElementById('maxDiscountWrap');
    const prefix  = document.getElementById('valuePrefix');
    const valInput = document.getElementById('couponValue');

    if (isPercentage) {
        maxWrap.style.opacity = '1';
        maxWrap.style.pointerEvents = 'auto';
        prefix.textContent = '%';
        valInput.setAttribute('max', '100');
    } else {
        maxWrap.style.opacity = '0.4';
        maxWrap.style.pointerEvents = 'none';
        document.getElementById('couponMaxDiscount').value = '';
        prefix.textContent = 'Rp';
        valInput.removeAttribute('max');
    }
    updateCouponPreview();
}

// ── Preview diskon live: tampilkan potongan & harga akhir sesungguhnya ──
// Menjawab kebingungan umum: "kenapa 100% cuma memotong sebagian?" — admin
// langsung lihat basis harga JUAL produk yang dipakai + hasil GRATIS saat 100%.
function updateCouponPreview() {
    var box  = document.getElementById('couponPreview');
    var body = document.getElementById('couponPreviewBody');
    if (!box || !body) return;

    var isPct = document.getElementById('typePercentage').checked;
    var val   = parseFloat(document.getElementById('couponValue').value) || 0;
    var maxD  = parseFloat(document.getElementById('couponMaxDiscount').value) || 0;
    var sel   = document.getElementById('couponProductId');
    var opt   = sel.options[sel.selectedIndex];
    var price = opt ? (parseFloat(opt.getAttribute('data-price') || '0') || 0) : 0;
    var fmt   = window.fmtMoney || function (n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); };

    if (val <= 0) { box.classList.add('hidden'); return; }

    // "Semua Produk" (tanpa harga spesifik) → catatan generik.
    if (!sel.value || price <= 0) {
        body.innerHTML = isPct
            ? 'Diskon <strong>' + (val % 1 === 0 ? val : val.toFixed(2).replace(/0+$/,'').replace(/\.$/,'')) + '%</strong> dihitung dari <strong>harga jual</strong> tiap produk saat checkout.'
            : 'Potongan tetap <strong>' + fmt(val) + '</strong> dari harga jual (maksimal seharga produk).';
        box.classList.remove('hidden');
        return;
    }

    var disc = isPct ? (price * val / 100) : val;
    if (isPct && maxD > 0) disc = Math.min(disc, maxD);
    disc = Math.min(disc, price);
    var finalP = Math.max(0, price - disc);

    var basis = isPct
        ? ((val % 1 === 0 ? val : val.toFixed(2).replace(/0+$/,'').replace(/\.$/,'')) + '% dari ' + fmt(price))
        : ('potongan ' + fmt(val) + ' dari ' + fmt(price));
    var freeNote = finalP <= 0 ? ' <span style="font-weight:700;color:#059669">— pembeli dapat GRATIS</span>' : '';

    body.innerHTML = 'Untuk <strong>' + (opt.text || '') + '</strong>: ' + basis +
        ' = potong <strong>' + fmt(disc) + '</strong> → pembeli bayar <strong>' + fmt(finalP) + '</strong>' + freeNote;
    box.classList.remove('hidden');
}

['couponValue', 'couponMaxDiscount'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', updateCouponPreview);
});
document.getElementById('couponProductId').addEventListener('change', updateCouponPreview);
document.querySelectorAll('input[name="type"]').forEach(function (r) {
    r.addEventListener('change', updateCouponPreview);
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
