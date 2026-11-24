<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { redirect('/admin/no-access'); }

$pageTitle   = 'Produk';
$topbarTitle = 'Manajemen Produk';

// ─── Filter & Pagination ──────────────────────────────────────
$search       = trim($_GET['q']      ?? '');
$typeFilter   = $_GET['type']        ?? '';
$categoryFilter = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$statusFilter = $_GET['status']      ?? '';
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 15;

$types    = ['class', 'membership', 'ebook', 'software', 'jasa', 'coaching', 'webinar', 'template', 'other'];
$catalogCategories = [];
$statuses = ['active', 'inactive', 'draft', 'coming_soon'];
// Label ramah untuk status (ucfirst saja jelek untuk coming_soon).
$statusLabel = fn(string $s): string => $s === 'coming_soon' ? 'Coming Soon' : ucfirst($s);

// ─── Query produk ─────────────────────────────────────────────
$products   = [];
$totalCount = 0;
$totalPages = 1;

try {
    $pdo    = getDB();
    $catalogCategories = $pdo->query("SELECT DISTINCT catalog_category FROM products
        WHERE catalog_category IS NOT NULL AND catalog_category <> '' ORDER BY catalog_category")
        ->fetchAll(PDO::FETCH_COLUMN);
    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[]  = '(p.name LIKE ? OR p.slug LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($typeFilter !== '') {
        $where[]  = 'p.type = ?';
        $params[] = $typeFilter;
    }
    if ($categoryFilter !== '') {
        $where[] = 'p.catalog_category = ?';
        $params[] = $categoryFilter;
    }
    if ($statusFilter !== '') {
        $where[]  = 'p.status = ?';
        $params[] = $statusFilter;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSQL");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT p.*,
                (SELECT COUNT(*) FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 WHERE oi.product_id = p.id AND o.status = 'paid') AS sold_count,
                (SELECT COALESCE(SUM(oi.price * oi.quantity), 0) FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 WHERE oi.product_id = p.id AND o.status = 'paid') AS omset,
                (SELECT COUNT(*) FROM product_waitlist pw
                 WHERE pw.product_id = p.id) AS waitlist_count,
                (SELECT CONCAT(u.name, ' (', u.email, ')') FROM users u
                 WHERE u.id = p.jv_partner_user_id) AS jv_partner_label
         FROM products p
         $whereSQL
         ORDER BY p.sort_order ASC, p.created_at DESC, p.id ASC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $products = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Admin products list error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data produk.', 'error');
}

// Tombol naik/turun hanya bermakna di tampilan penuh (tanpa cari/filter) —
// urutannya = urutan tampil di homepage (sort_order ASC). Saat difilter,
// hasilnya bukan urutan homepage, jadi kontrol reorder disembunyikan.
$canReorder = ($search === '' && $typeFilter === '' && $categoryFilter === '' && $statusFilter === '');
$lastIdx    = count($products) - 1;

// ─── Helpers tampilan ─────────────────────────────────────────
$typeBadge = function(string $type): string {
    return match ($type) {
        'class'      => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
        'membership' => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
        'ebook'      => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        'software'   => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'jasa'       => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        'coaching'   => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
        'webinar'    => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
        'template'   => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        default      => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    };
};


// ─── Checkout templates untuk dropdown ───────────────────────
$checkoutTemplates = [];
try {
    $checkoutTemplates = getDB()->query(
        "SELECT id, name, layout_type, is_default FROM checkout_templates ORDER BY is_default DESC, id ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Products: load checkout templates error: ' . $e->getMessage());
}

// ─── Canvas tersedia untuk LP selector (standalone + product_landing) ─
$availableCanvases = [];
try {
    // Sertakan homepage Canvas juga — boleh dipakai sbg landing produk. Homepage
    // diurut paling atas + ditandai agar admin tahu itu halaman utama.
    $availableCanvases = getDB()->query(
        "SELECT id, title, slug, page_type, is_homepage FROM canvas_pages
         WHERE is_active = 1 AND page_type IN ('standalone','product_landing','homepage')
         ORDER BY is_homepage DESC, title ASC"
    )->fetchAll();
} catch (Exception $e) {
    // Tabel canvas_pages mungkin belum ada (migration belum jalan) — abaikan.
    error_log('Products: load canvases error: ' . $e->getMessage());
}

// ─── Produk untuk deep-link ?edit=<id> ───────────────────────
// Dibuka lewat URL (bookmark/share) — bisa produk yang tak ada di halaman
// atau filter saat ini, jadi diambil terpisah, bukan dari $products.
$editProduct = null;
$editId      = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    try {
        $stmt = getDB()->prepare("SELECT p.*, (SELECT CONCAT(u.name, ' (', u.email, ')') FROM users u WHERE u.id = p.jv_partner_user_id) AS jv_partner_label FROM products p WHERE p.id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editProduct = $stmt->fetch() ?: null;
    } catch (Exception $e) {
        error_log('Products: load edit product error: ' . $e->getMessage());
    }
}

// Peta produk untuk JS (dipakai tombol Edit di tabel + restore saat tombol Back).
$productMap = [];
foreach ($products as $p) {
    $productMap[(int) $p['id']] = $p;
}
if ($editProduct) {
    $productMap[(int) $editProduct['id']] = $editProduct;
}

// Daftar produk untuk dropdown "Produk Resell" (target tombol Order Resell Rights
// di salespage). Semua produk aktif — pilihan mana yang jadi lisensi Resell (R).
$resellProductOptions = [];
try {
    $resellProductOptions = getDB()->query(
        "SELECT id, name FROM products WHERE status = 'active' ORDER BY name ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('products: resell options load error: ' . $e->getMessage());
}

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
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Produk</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= number_format($totalCount) ?> produk ditemukan</p>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= e(APP_URL) ?>/admin/affiliate-displays"
             title="Tampilkan produk affiliate (milik orang lain) di homepage"
             class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            Tambah Display
          </a>
          <button onclick="openProductModal()"
                  class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition-opacity hover:opacity-90">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Tambah Produk
          </button>
        </div>
      </div>

      <!-- Filter Bar -->
      <form method="GET" action="" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-5 flex flex-wrap gap-3 items-end shadow-sm">
        <div class="flex-1 min-w-[180px]">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Cari Produk</label>
          <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Nama atau slug..."
                   class="w-full pl-9 pr-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>
        </div>
        <div class="w-36">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Tipe</label>
          <select name="type" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Tipe</option>
            <?php foreach ($types as $t): ?>
            <option value="<?= e($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-36">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Kategori</label>
          <select name="category" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Kategori</option>
            <?php foreach ($catalogCategories as $category): ?>
            <option value="<?= e($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= e($category) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-36">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Status</label>
          <select name="status" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Status</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $statusLabel($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90 transition">Filter</button>
          <?php if ($search || $typeFilter || $categoryFilter || $statusFilter): ?>
          <a href="<?= e(APP_URL) ?>/admin/products" class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($products)): ?>
        <div class="py-16 text-center">
          <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
          <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada produk</p>
          <button onclick="openProductModal()" class="mt-3 text-sm text-[var(--brand-color)] hover:underline font-medium">Tambah produk pertama →</button>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tipe / Kategori</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Harga</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Terjual</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Omset</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($products as $i => $product): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-5 py-3.5">
                  <p class="font-semibold text-gray-900 dark:text-white"><?= e($product['name']) ?></p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 font-mono">/<?= e($product['slug']) ?></p>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <span class="<?= $typeBadge($product['type']) ?>"><?= e(ucfirst($product['type'])) ?></span>
                  <?php if (!empty($product['catalog_category'])): ?>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1"><?= e($product['catalog_category']) ?></p>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5 text-right">
                  <?php if (($product['pricing_type'] ?? 'fixed') === 'flexible'): ?>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] text-[var(--brand-color)]">Fleksibel</span>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5 truncate max-w-[120px] ml-auto"><?= e($product['flexible_label'] ?: 'Bayar Seikhlasnya') ?></p>
                  <?php elseif ((float)$product['price'] > 0): ?>
                  <?php $dime = dimeInfo($product); ?>
                  <span class="font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah($dime ? $dime['price'] : (float)$product['price'])) ?></span>
                  <?php if ($dime): ?>
                  <p class="text-[11px] text-[var(--brand-color)] font-medium mt-0.5">Dime · <?= $dime['at_cap'] ? 'harga maksimum' : ($dime['remaining'] . ' lagi naik ' . formatRupiah($dime['increment'])) ?></p>
                  <?php endif; ?>
                  <?php if ($product['price_myr'] !== null && (float)$product['price_myr'] > 0 && strtoupper((string) getSetting('currency', 'IDR')) === 'IDR'): ?>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5"><?= e(formatRinggit((float)$product['price_myr'])) ?></p>
                  <?php endif; ?>
                  <?php if ((int)($product['access_duration_days'] ?? 0) > 0): ?>
                  <p class="text-[11px] text-[var(--brand-color)] font-medium">Langganan · /<?= e(subscriptionPeriodLabel((int)$product['access_duration_days'])) ?></p>
                  <?php endif; ?>
                  <?php if ((float)$product['original_price'] > (float)$product['price']): ?>
                  <p class="text-xs text-gray-400 line-through"><?= e(formatRupiah((float)$product['original_price'])) ?></p>
                  <?php endif; ?>
                  <?php else: ?>
                  <span class="text-green-600 dark:text-green-400 font-medium text-xs">Gratis</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <span class="font-medium text-gray-700 dark:text-gray-300"><?= number_format((int)$product['sold_count']) ?></span>
                </td>
                <td class="px-4 py-3.5 text-right">
                  <span class="font-medium text-gray-700 dark:text-gray-300"><?= e(formatRupiah((float)$product['omset'])) ?></span>
                </td>
                <td class="px-4 py-3.5 text-center">
                  <?= renderStatusBadge($product['status'], 'product') ?>
                  <?php if ((int) ($product['hide_from_catalog'] ?? 0) === 1): ?>
                  <span class="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                        title="Tersembunyi dari katalog publik — tetap bisa dibeli lewat link langsung">
                    <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    Unlisted
                  </span>
                  <?php endif; ?>
                  <?php if ((int) ($product['waitlist_count'] ?? 0) > 0): ?>
                  <a href="<?= e(APP_URL) ?>/admin/waitlist?product_id=<?= (int) $product['id'] ?>"
                     class="block mt-1 text-[11px] font-medium text-indigo-600 dark:text-indigo-400 hover:underline"
                     title="Lihat daftar waitlist produk ini"><?= number_format((int) $product['waitlist_count']) ?> waitlist</a>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <?php if ($canReorder): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/reorder-product" class="reorder-col">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                      <input type="hidden" name="page" value="<?= (int) $page ?>">
                      <button type="submit" name="dir" value="up" class="reorder-btn" title="Naikkan urutan"
                              <?= ($page === 1 && $i === 0) ? 'disabled' : '' ?>>
                        <svg class="w-3.5 h-3.5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
                      </button>
                      <button type="submit" name="dir" value="down" class="reorder-btn" title="Turunkan urutan"
                              <?= ($page === $totalPages && $i === $lastIdx) ? 'disabled' : '' ?>>
                        <svg class="w-3.5 h-3.5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($product['status'] === 'coming_soon'): ?>
                    <form id="launch-prod-<?= (int)$product['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/launch-product" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                      <button type="button"
                              onclick="confirmAndSubmit('launch-prod-<?= (int)$product['id'] ?>',{title:'Launch Produk Sekarang?',message:'Produk <?= e(addslashes($product['name'])) ?> akan diaktifkan dan notifikasi launch (email+WA) MULAI DIKIRIM ke <?= number_format((int)($product['waitlist_count'] ?? 0)) ?> pendaftar waitlist. Lanjutkan?',confirmText:'Ya, Launch',variant:'success'})"
                              class="px-2.5 py-1.5 rounded-lg text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition">
                        Launch
                      </button>
                    </form>
                    <?php endif; ?>
                    <button type="button" title="Salin URL Produk"
                            data-url="<?= e(APP_URL) ?>/products/<?= e($product['slug']) ?>"
                            onclick="copyProductUrl(this)"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] transition">
                      <svg class="w-4 h-4 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                      </svg>
                    </button>
                    <?php if (($product['checkout_mode'] ?? 'login') === 'public'): ?>
                    <button type="button" title="Salin Link Checkout Publik"
                            data-url="<?= e(APP_URL) ?>/order/<?= e($product['slug']) ?>"
                            onclick="copyProductUrl(this)"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-green-600 dark:hover:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition">
                      <svg class="w-4 h-4 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                      </svg>
                    </button>
                    <a href="<?= e(APP_URL) ?>/order/<?= e($product['slug']) ?>?preview=1" target="_blank" title="Buka Form Checkout Publik"
                       class="p-1.5 rounded-lg text-gray-400 hover:text-green-600 dark:hover:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 14h6"/>
                      </svg>
                    </a>
                    <button type="button" title="Kode Embed Form Order"
                            data-slug="<?= e($product['slug']) ?>" data-name="<?= e($product['name']) ?>"
                            onclick="openEmbedModal(this.getAttribute('data-slug'), this.getAttribute('data-name'))"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] transition">
                      <svg class="w-4 h-4 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    </button>
                    <?php endif; ?>
                    <a href="<?= e(APP_URL) ?>/products/<?= e($product['slug']) ?>" target="_blank" title="Preview"
                       class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                      </svg>
                    </a>
                    <button onclick="openProductModal(PRODUCT_MAP[<?= (int) $product['id'] ?>])"
                            title="Edit"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                    </button>
                    <form id="dup-prod-<?= (int)$product['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/duplicate-product" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                      <button type="button" title="Duplikat"
                              onclick="confirmAndSubmit('dup-prod-<?= (int)$product['id'] ?>',{title:'Duplikat Produk?',message:'Salinan dibuat sebagai draft dengan slug terpisah. Kelas & materi (jika ada) ikut disalin.',confirmText:'Ya, Duplikat',variant:'info'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <rect x="8" y="8" width="12" height="12" rx="2"/>
                          <path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>
                        </svg>
                      </button>
                    </form>
                    <form id="del-prod-<?= (int)$product['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-product" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                      <button type="button" title="Hapus"
                              onclick="confirmAndSubmit('del-prod-<?= (int)$product['id'] ?>',{title:'Hapus Produk?',message:'Produk ini akan dihapus permanen beserta semua kontennya.',confirmText:'Ya, Hapus',variant:'danger'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <polyline points="3 6 5 6 21 6"/>
                          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                          <path d="M10 11v6"/><path d="M14 11v6"/>
                          <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
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
        $buildPageUrl = function(int $p) use ($search, $typeFilter, $categoryFilter, $statusFilter): string {
            $q = http_build_query(array_filter([
                'q'      => $search      ?: null,
                'type'   => $typeFilter  ?: null,
                'category' => $categoryFilter ?: null,
                'status' => $statusFilter ?: null,
                'page'   => $p > 1 ? $p : null,
            ]));
            return APP_URL . '/admin/products' . ($q ? '?' . $q : '');
        };
        $rangeStart = max(1, $page - 2);
        $rangeEnd   = min($totalPages, $page + 2);
        ?>
        <div class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
          <p class="text-xs text-gray-500 dark:text-gray-400">
            Halaman <?= $page ?> dari <?= $totalPages ?> &middot; <?= number_format($totalCount) ?> total
          </p>
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

<!-- ═══ MODAL PRODUK ═══ -->
<style>
/* Tab modal produk — dibuat lokal agar tak perlu regenerate tailwind.css */
.product-tab{
  position:relative; padding:.5rem .85rem; font-size:.75rem; font-weight:600; white-space:nowrap;
  color:#6b7280; border-bottom:2px solid transparent; margin-bottom:-1px; border-radius:8px 8px 0 0;
  background:none; cursor:pointer; transition:color .15s ease, border-color .15s ease, background .15s ease;
}
.product-tab:hover{ color:#111827; background:color-mix(in srgb, var(--brand-color) 6%, transparent); }
.product-tab[aria-selected="true"]{ color:var(--brand-color); border-bottom-color:var(--brand-color); background:color-mix(in srgb, var(--brand-color) 8%, transparent); }
.dark .product-tab{ color:#9ca3af; }
.dark .product-tab:hover{ color:#f9fafb; background:color-mix(in srgb, var(--brand-color) 12%, transparent); }
.dark .product-tab[aria-selected="true"]{ color:var(--brand-color); background:color-mix(in srgb, var(--brand-color) 14%, transparent); }
.product-tab-panel[hidden]{ display:none; }
/* Tombol naik/turun urutan produk (homepage) — lokal, tak perlu regen tailwind */
.reorder-col{ display:inline-flex; flex-direction:column; gap:2px; margin-right:2px; }
.reorder-btn{ display:flex; align-items:center; justify-content:center; width:22px; height:17px; border-radius:6px; color:#9ca3af; cursor:pointer; transition:color .15s ease, background .15s ease; }
.reorder-btn:hover{ color:var(--brand-color); background:color-mix(in srgb, var(--brand-color) 10%, transparent); }
.reorder-btn:disabled{ opacity:.3; cursor:not-allowed; }
.reorder-btn:disabled:hover{ color:#9ca3af; background:none; }
.dark .reorder-btn{ color:#6b7280; }
.dark .reorder-btn:disabled:hover{ color:#6b7280; }
</style>
<div id="productModal" class="fixed inset-0 z-50 hidden">
  <div class="flex min-h-screen items-start justify-center px-4 pt-4 pb-10">
    <div onclick="closeProductModal()" class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-4xl border border-gray-200 dark:border-gray-700 my-4 flex flex-col" style="max-height:90vh">

      <!-- Sticky Header -->
      <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 shrink-0">
        <div class="flex items-center gap-3 min-w-0">
          <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0" style="background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color)">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.89 1.45l8 4A2 2 0 0 1 22 7.24v9.53a2 2 0 0 1-1.11 1.79l-8 4a2 2 0 0 1-1.79 0l-8-4a2 2 0 0 1-1.1-1.8V7.24a2 2 0 0 1 1.11-1.79l8-4a2 2 0 0 1 1.78 0z"/><polyline points="2.32 6.16 12 11 21.68 6.16"/><line x1="12" y1="22.76" x2="12" y2="11"/></svg>
          </span>
          <div class="min-w-0">
            <h3 id="modalTitle" class="text-base font-bold text-gray-900 dark:text-white truncate">Tambah Produk</h3>
            <p id="modalSubtitle" class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 truncate">Isi detail produk di bawah</p>
          </div>
        </div>
        <div class="flex items-center gap-1 shrink-0">
          <a href="<?= e(APP_URL) ?>/admin/docs#produk-checkout" target="_blank" rel="noopener"
             title="Buka panduan cara menambah produk"
             class="inline-flex items-center gap-1.5 px-2.5 h-8 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <span class="hidden sm:inline">Panduan</span>
          </a>
          <button onclick="closeProductModal()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Tab -->
      <div class="px-5 border-b border-gray-100 dark:border-gray-800 shrink-0 overflow-x-auto">
        <div class="flex items-center gap-1" role="tablist" aria-label="Seksi produk">
          <button type="button" class="product-tab" role="tab" aria-selected="true"  data-tab="dasar"     onclick="setProductTab('dasar')">Dasar</button>
          <button type="button" class="product-tab" role="tab" aria-selected="false" data-tab="harga"     onclick="setProductTab('harga')">Harga &amp; Akses</button>
          <button type="button" class="product-tab" role="tab" aria-selected="false" data-tab="media"     onclick="setProductTab('media')">Media &amp; Link</button>
          <button type="button" class="product-tab" role="tab" aria-selected="false" data-tab="deskripsi" onclick="setProductTab('deskripsi')">Deskripsi</button>
          <button type="button" class="product-tab" role="tab" aria-selected="false" data-tab="lanjutan"  onclick="setProductTab('lanjutan')">Lanjutan</button>
        </div>
      </div>

      <!-- Form: scrollable body + sticky footer -->
      <form id="productForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-product" enctype="multipart/form-data" class="flex flex-col flex-1 min-h-0">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="product_id" id="productId" value="">

        <div class="overflow-y-auto flex-1 p-5">

          <!-- ── TAB: Dasar ── -->
          <div class="product-tab-panel space-y-3" data-panel="dasar" role="tabpanel">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nama Produk <span class="text-red-500">*</span></label>
              <input type="text" name="name" id="productName" required maxlength="200"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                     placeholder="Nama produk...">
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Slug URL <span class="text-red-500">*</span></label>
              <div class="flex items-center gap-2">
                <span class="text-xs text-gray-400 dark:text-gray-600 shrink-0">/products/</span>
                <input type="text" name="slug" id="productSlug" required maxlength="220"
                       class="flex-1 px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                       placeholder="slug-produk">
                <button type="button" onclick="generateSlug()"
                        class="shrink-0 px-3 h-9 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Auto</button>
              </div>
            </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tipe <span class="text-red-500">*</span></label>
                <select name="type" id="productType" required
                        class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <option value="">Pilih tipe...</option>
                  <?php foreach ($types as $t): ?>
                  <option value="<?= e($t) ?>"><?= ucfirst($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status <span class="text-red-500">*</span></label>
                <select name="status" id="productStatus" required onchange="syncLaunchAtField()"
                        class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <?php foreach ($statuses as $s): ?>
                  <option value="<?= e($s) ?>"><?= $statusLabel($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div>
              <label for="productCatalogCategory" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Kategori Etalase <span class="font-normal">(opsional)</span></label>
              <input type="text" name="catalog_category" id="productCatalogCategory" list="productCatalogCategories" maxlength="80" autocomplete="off" placeholder="Pilih kategori lama atau ketik nama baru"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
              <datalist id="productCatalogCategories">
                <?php foreach ($catalogCategories as $category): ?><option value="<?= e($category) ?>"></option><?php endforeach; ?>
              </datalist>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Nama baru dapat dipilih lagi untuk produk lain. Tipe tetap menentukan isi otomatis halaman produk.</p>
            </div>
            <!-- Jadwal launch (khusus Coming Soon) -->
            <div id="launchAtWrap" class="hidden">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Jadwal Launch <span class="font-normal text-gray-400">(opsional)</span></label>
              <input type="datetime-local" name="launch_at" id="productLaunchAt"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Saat waktu ini tiba, produk otomatis aktif &amp; notifikasi launch dikirim ke pendaftar waitlist. Kosongkan bila mau launch manual (tombol Launch di daftar produk).</p>
            </div>

            <!-- Visibilitas katalog (produk "unlisted") -->
            <div class="pt-1">
              <input type="hidden" name="hide_from_catalog" value="0">
              <label class="flex items-start gap-2.5 cursor-pointer select-none">
                <input type="checkbox" name="hide_from_catalog" id="productHideCatalog" value="1"
                       class="w-4 h-4 mt-0.5 rounded accent-[var(--brand-color)] shrink-0">
                <span class="min-w-0">
                  <span class="block text-xs font-medium text-gray-700 dark:text-gray-300">Sembunyikan dari katalog publik</span>
                  <span class="block text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Produk tidak tampil di halaman depan &amp; daftar produk, tapi <strong>tetap bisa dibeli</strong> lewat link langsung, sales page, atau checkout. Cocok untuk produk khusus/rahasia.</span>
                </span>
              </label>
            </div>
          </div>

          <!-- ── TAB: Harga & Akses ── -->
          <div class="product-tab-panel space-y-3" data-panel="harga" role="tabpanel" hidden>

            <!-- Tipe Harga: Sekali Bayar vs Bayar Fleksibel -->
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tipe Harga</label>
              <select name="pricing_type" id="productPricingType" onchange="syncPricingType()"
                      class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <option value="fixed">Sekali Bayar — harga tetap</option>
                <option value="flexible">Bayar Fleksibel — pembeli tentukan nominal</option>
              </select>
            </div>

            <!-- Teks Tombol Kustom (semua produk) -->
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Teks Tombol Kustom <span class="text-gray-400 font-normal">(opsional)</span></label>
              <input type="text" name="cta_label" id="productCtaLabel" maxlength="60" placeholder="Kosong = otomatis (Beli Sekarang / Ambil Gratis)"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Teks tombol beli di landing &amp; katalog. Contoh: Bayar Sesukamu &middot; Dukung Karya Ini &middot; Ambil Sekarang. Kosong = otomatis.</p>
            </div>

            <!-- Field khusus Bayar Fleksibel -->
            <div id="flexibleFields" class="hidden space-y-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-3">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Label ke Pembeli</label>
                <input type="text" name="flexible_label" id="productFlexLabel" maxlength="60" placeholder="Bayar Seikhlasnya"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Contoh: Bayar Seikhlasnya &middot; Dukung Saya &middot; Traktir Kopi &middot; Support Konten Ini. Kosong = "Bayar Seikhlasnya".</p>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Minimum (<?= e(currencySymbol()) ?>)</label>
                  <input type="number" name="flexible_min" id="productFlexMin" min="0" step="1000" value="0"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">0 = boleh bayar berapa pun (termasuk gratis).</p>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Preset Nominal</label>
                  <input type="text" name="flexible_presets" id="productFlexPresets" placeholder="10000, 25000, 50000"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Pisahkan koma. Kosong = hanya input custom.</p>
                </div>
              </div>
              <p class="text-[11px] text-gray-500 dark:text-gray-400">Untuk produk fleksibel: kupon, order bump, setup fee, &amp; booking DP otomatis nonaktif; akses sekali bayar (bukan langganan). Produk tetap bisa punya konten/akses (kelas, download, dll).</p>
              <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                  <input type="hidden" name="show_on_support" value="0">
                  <input type="checkbox" name="show_on_support" id="productShowOnSupport" value="1"
                         class="mt-0.5 rounded border-gray-300 dark:border-gray-600 accent-[var(--brand-color)]">
                  <span>
                    <span class="block text-xs font-medium text-gray-700 dark:text-gray-300">Tampilkan di Halaman Dukung</span>
                    <span class="block text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Produk ini muncul sebagai kartu tip di halaman /dukung, dan pembeli bisa menulis pesan dukungan.</span>
                  </span>
                </label>
              </div>
            </div>

            <div id="fpPriceGrid" class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Harga (<?= e(currencySymbol()) ?>)</label>
                <input type="number" name="price" id="productPrice" min="0" step="1000" value="0"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Harga Asli <span class="font-normal text-gray-400 dark:text-gray-600">(coret)</span></label>
                <input type="number" name="original_price" id="productOriginalPrice" min="0" step="1000" value="0"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Durasi Akses <span class="font-normal text-gray-400 dark:text-gray-600">(hari; &gt;0 = berlangganan, 0 = sekali bayar)</span></label>
                <input type="number" name="access_duration_days" id="productDuration" min="0" value="0"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Komisi Afiliasi (%)</label>
                <input type="number" name="affiliate_commission" id="productCommission" min="0" max="100" step="0.5" value="0"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">0 = produk tidak diikutkan program afiliasi. Isi &gt; 0 untuk memberi komisi ke afiliasi. Rate ini bisa ditimpa oleh Aturan Komisi khusus produk/level.</p>
              </div>
            </div>

            <!-- Pasar Malaysia (opsional) — harga pendamping RM + bahasa label -->
            <div id="fpMalaysia" class="pt-1 grid grid-cols-1 md:grid-cols-2 gap-3">
              <?php if (strtoupper((string) getSetting('currency', 'IDR')) === 'IDR'): ?>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Harga Alias Ringgit <span class="font-normal text-gray-400 dark:text-gray-600">(RM, opsional)</span></label>
                <div class="relative">
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400 pointer-events-none">RM</span>
                  <input type="number" name="price_myr" id="productPriceMyr" min="0" step="0.01" placeholder="Kosongkan jika tidak jual ke Malaysia"
                         class="w-full pl-10 pr-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Tampil sebagai pendamping harga Rupiah (mis. <strong>Rp 57.000 / RM 13</strong>). Hanya tampilan — pembayaran tetap dalam Rupiah.</p>
              </div>
              <?php else: ?>
              <input type="hidden" name="price_myr" id="productPriceMyr" value="">
              <?php endif; ?>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Bahasa Label</label>
                <select name="language" id="productLanguage"
                        class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <option value="id">Indonesia</option>
                  <option value="ms">Melayu</option>
                </select>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Ubah label tombol jadi Bahasa Melayu untuk pembeli Malaysia.</p>
              </div>
            </div>

            <div id="fpSetup" class="pt-1">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Biaya Awal / Setup Fee (<?= e(currencySymbol()) ?>) <span class="font-normal text-gray-400 dark:text-gray-600">(opsional)</span></label>
              <input type="number" name="setup_fee" id="productSetupFee" min="0" step="1000" value="0"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Ditambahkan ke harga <strong>hanya pada pembelian pertama</strong> (mis. biaya setup langganan). Saat pembeli memperpanjang, biaya ini tak dikenakan lagi. Kosong / 0 = tidak ada.</p>
            </div>

            <!-- Booking Fee (DP) -->
            <div id="fpBooking" class="pt-2 border-t border-gray-100 dark:border-gray-700">
              <div class="flex items-center gap-3">
                <input type="hidden" name="booking_enabled" value="0">
                <input type="checkbox" name="booking_enabled" value="1" id="productBookingEnabled"
                       class="w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]"
                       onchange="document.getElementById('bookingOptions').classList.toggle('hidden', !this.checked); syncBookingDime('booking');">
                <label for="productBookingEnabled" class="text-sm text-gray-700 dark:text-gray-300">Izinkan Booking Fee (bayar DP dulu)</label>
              </div>
              <p id="bookingDimeNote" class="hidden text-[11px] text-amber-600 dark:text-amber-400 mt-1 ml-7">Nonaktif — tidak bisa aktif bersamaan dengan <strong>Dime Sale</strong>. Matikan Dime Sale dulu untuk memakai Booking Fee.</p>
              <div id="bookingOptions" class="hidden grid grid-cols-2 gap-3 mt-2 pl-7">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Booking Fee (<?= e(currencySymbol()) ?>)</label>
                  <input type="number" name="booking_fee" id="productBookingFee" min="0" step="1000" value="0"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
                <p id="bookingFeeError" class="hidden col-span-2 -mt-1 text-[11px] font-medium text-red-600 dark:text-red-400"></p>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Batas Pelunasan (hari)</label>
                  <input type="number" name="booking_deadline_days" id="productBookingDeadline" min="1" value="7"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
                <p class="col-span-2 text-[11px] text-gray-400 dark:text-gray-500">Pembeli bisa bayar DP untuk mengunci slot &amp; harga; sisa dilunasi sebelum batas waktu (lewat = booking hangus). Akses konten terbuka setelah lunas. <strong>Nominal DP wajib lebih dari 0 dan lebih kecil dari harga</strong> — jika tidak, booking otomatis nonaktif.</p>
                <div class="col-span-2 flex items-start gap-2.5 pt-1 border-t border-gray-100 dark:border-gray-700">
                  <input type="hidden" name="booking_instant_access" value="0">
                  <input type="checkbox" name="booking_instant_access" value="1" id="productBookingInstant"
                         class="mt-0.5 w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]">
                  <label for="productBookingInstant" class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
                    <span class="font-medium text-gray-700 dark:text-gray-300">Beri akses area member langsung meski baru DP</span><br>
                    Pembeli bisa masuk area member segera setelah bayar DP. Akses bersifat sementara — <strong>dicabut otomatis bila booking hangus</strong>. Kunci lisensi (produk berlisensi) tetap dikirim saat lunas.
                  </label>
                </div>
              </div>
            </div>

            <!-- Dime Sale (harga naik bertahap) -->
            <div id="fpDime" class="pt-2 border-t border-gray-100 dark:border-gray-700">
              <div class="flex items-center gap-3">
                <input type="hidden" name="dime_enabled" value="0">
                <input type="checkbox" name="dime_enabled" value="1" id="productDimeEnabled"
                       class="w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]"
                       onchange="document.getElementById('dimeOptions').classList.toggle('hidden', !this.checked); syncBookingDime('dime');">
                <label for="productDimeEnabled" class="text-sm text-gray-700 dark:text-gray-300">Aktifkan Dime Sale (harga naik seiring penjualan)</label>
              </div>
              <p id="dimeBookingNote" class="hidden text-[11px] text-amber-600 dark:text-amber-400 mt-1 ml-7">Nonaktif — tidak bisa aktif bersamaan dengan <strong>Booking Fee</strong>. Matikan Booking Fee dulu untuk memakai Dime Sale.</p>
              <div id="dimeOptions" class="hidden grid grid-cols-2 gap-3 mt-2 pl-7">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Naik Tiap (penjualan)</label>
                  <input type="number" name="dime_step_qty" id="productDimeStep" min="1" value="10"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Kenaikan Harga (<?= e(currencySymbol()) ?>)</label>
                  <input type="number" name="dime_increment" id="productDimeIncrement" min="0" step="1000" value="0"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
                <div class="col-span-2">
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Harga Maksimum (<?= e(currencySymbol()) ?>) <span class="font-normal text-gray-400 dark:text-gray-600">(opsional — kosong = tanpa batas)</span></label>
                  <input type="number" name="dime_max_price" id="productDimeMax" min="0" step="1000" placeholder="Kosongkan jika tanpa batas"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                </div>
                <p class="col-span-2 text-[11px] text-gray-400 dark:text-gray-500">Harga mulai dari <strong>Harga (<?= e(currencySymbol()) ?>)</strong> di atas, lalu bertambah tiap kelipatan penjualan LUNAS. Pembeli awal dapat harga termurah. Harga yang dilihat pembeli saat checkout dikunci — dia bayar persis segitu. Tidak bisa barengan Bayar Fleksibel / Booking Fee.</p>
              </div>
            </div>

            <!-- JV Partner (bagi hasil) -->
            <div id="fpJv" class="pt-2 border-t border-gray-100 dark:border-gray-700">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">JV Partner <span class="font-normal text-gray-400 dark:text-gray-600">(bagi hasil, opsional)</span></label>
              <div id="jvSelected" class="hidden items-center gap-2 mb-2">
                <span class="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                  <span id="jvSelectedLabel"></span>
                  <button type="button" onclick="jvClearPartner()" class="w-4 h-4 rounded flex items-center justify-center text-gray-400 hover:text-red-500 transition" aria-label="Hapus partner">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </span>
              </div>
              <input type="hidden" name="jv_partner_user_id" id="productJvPartnerId" value="">
              <div class="grid grid-cols-2 gap-3">
                <div class="relative">
                  <input type="text" id="jvPartnerSearch" autocomplete="off" placeholder="Cari nama/email partner…"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <div id="jvPartnerResults" class="hidden absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 shadow-lg max-h-56 overflow-y-auto" style="z-index: 40;"></div>
                </div>
                <div>
                  <div class="relative">
                    <input type="number" name="jv_partner_percent" id="productJvPercent" min="0" max="100" step="0.5" value="0"
                           class="w-full pl-3 pr-8 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400 pointer-events-none">%</span>
                  </div>
                </div>
              </div>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Jatah partner dihitung otomatis tiap order lunas: <strong>(total − komisi affiliate) × persen</strong>. Pembayaran ke partner dilakukan manual &amp; dicatat di menu <strong>JV Partner</strong>.</p>
            </div>

            <!-- Popup Social Proof (per produk) -->
            <div class="pt-2 border-t border-gray-100 dark:border-gray-700">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Popup Social Proof</label>
              <select name="sp_mode" id="productSpMode"
                      class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                <option value="all">Campur semua produk (default)</option>
                <option value="own">Hanya pembelian produk ini</option>
                <option value="off">Sembunyikan di produk ini</option>
              </select>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Berlaku di halaman produk &amp; checkout produk ini. Master toggle tetap di <a href="<?= e(APP_URL) ?>/admin/social-proof" class="text-[var(--brand-color)] hover:underline">Social Proof</a>.</p>
            </div>
          </div>

          <!-- ── TAB: Lanjutan (Salespage Reseller, Sertifikat, Review, Lisensi) ── -->
          <div class="product-tab-panel space-y-4" data-panel="lanjutan" role="tabpanel" hidden>

            <!-- Salespage Siap Pakai (Reseller) -->
            <div id="fpSalespage" class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
              <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Salespage Siap Pakai (Reseller)</p>
              <div class="flex items-center gap-3">
                <input type="hidden" name="salespage_enabled" value="0">
                <input type="checkbox" name="salespage_enabled" value="1" id="productSalespageEnabled"
                       class="w-4 h-4 rounded accent-[var(--brand-color)]"
                       onchange="document.getElementById('salespageFields').classList.toggle('hidden', !this.checked)">
                <label for="productSalespageEnabled" class="text-sm text-gray-700 dark:text-gray-300">Aktifkan salespage siap pakai untuk produk ini</label>
              </div>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 ml-7">Reseller dapat link <strong>/on/{slug}</strong> untuk promosi: tombol <strong>Order Personal Use</strong> (ke WA reseller, off-platform) + <strong>Order Resell Rights</strong> (checkout dengan ref reseller).</p>

              <div id="salespageFields" class="hidden ml-7 space-y-3">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Produk Resell <span class="font-normal text-gray-400 dark:text-gray-600">(target tombol Order Resell Rights)</span></label>
                  <select name="resell_product_id" id="productResellProductId"
                          class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                    <option value="">— Tidak ada (sembunyikan tombol Order Resell Rights) —</option>
                    <?php foreach ($resellProductOptions as $rp): ?>
                    <option value="<?= (int) $rp['id'] ?>"><?= e($rp['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Produk <strong>lisensi Resell</strong> yang dituju tombol Order Resell Rights. Reseller yang <strong>memiliki produk ini</strong> otomatis mendapat link salespage produk ini di dashboard mereka. Harga yang tampil di salespage = <strong>Harga</strong> produk ini (lisensi personal).</p>
                </div>
                <p class="text-[11px] text-gray-400 dark:text-gray-500">Teks WhatsApp &amp; pilihan menampilkan tombol <strong>Order Resell Rights</strong> diatur masing-masing reseller di dashboard mereka (menu Reseller).</p>
              </div>
            </div>

            <!-- Akses Produk Berpassword (delivery reseller off-platform) -->
            <div id="fpAccessPage" class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
              <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Halaman Akses Produk (Berpassword)</p>
              <div class="flex items-center gap-3">
                <input type="hidden" name="access_page_enabled" value="0">
                <input type="checkbox" name="access_page_enabled" value="1" id="productAccessEnabled"
                       class="w-4 h-4 rounded accent-[var(--brand-color)]">
                <label for="productAccessEnabled" class="text-sm text-gray-700 dark:text-gray-300">Aktifkan halaman akses berpassword untuk produk ini</label>
              </div>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 ml-7">Halaman <strong>/akses/{slug}</strong> menampilkan konten produk (modul &amp; materi) tanpa login, dibuka dengan password. <strong>Password diatur masing-masing reseller</strong> di dashboard mereka (menu Reseller) lalu dibagikan ke pembeli personal-use.</p>
            </div>

            <!-- Mode Akses Konten: Kelas / Embed langsung -->
            <div class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
              <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Mode Akses Konten</p>
              <select name="access_type" id="productAccessType" onchange="syncAccessType()"
                      class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
                <option value="course">Kelas / Modul (default) — konten via Modul &amp; Materi</option>
                <option value="embed">Embed langsung — satu konten HTML/URL, tanpa kelas</option>
              </select>
              <div id="embedFields" class="hidden space-y-3 pt-1">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Kode Embed (HTML)</label>
                  <textarea name="embed_html" id="productEmbedHtml" rows="6" placeholder="&lt;iframe ...&gt;&lt;/iframe&gt; atau HTML aplikasi lengkap"
                            class="w-full px-3 py-2 rounded-lg text-sm font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] resize-y"></textarea>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Dirender di iframe aman (sandbox). <strong>HTML diutamakan</strong> bila diisi.</p>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">atau URL Tool (di-iframe)</label>
                  <input type="url" name="embed_url" id="productEmbedUrl" placeholder="https://tool.contoh.com/app"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Dipakai bila HTML kosong. Pembeli yang punya akses langsung mendarat di sini (tanpa daftar kelas). Kelas/Modul diabaikan untuk mode ini.</p>
                </div>
                <div class="pt-1">
                  <button type="button" onclick="previewEmbed()"
                          class="inline-flex items-center gap-2 px-3 h-9 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    Lihat sebagai pembeli
                  </button>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Pratinjau langsung dari isian di atas (belum perlu disimpan), persis tampilan halaman akses pembeli.</p>
                </div>
              </div>
            </div>

            <!-- Sertifikat & Review -->
            <div class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Sertifikat &amp; Review</p>
            <div class="flex items-center gap-3">
              <input type="hidden" name="certificate_enabled" value="0">
              <input type="checkbox" name="certificate_enabled" value="1" id="productCertEnabled"
                     class="w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]"
                     onchange="document.getElementById('certOptions').classList.toggle('hidden', !this.checked)">
              <label for="productCertEnabled" class="text-sm text-gray-700 dark:text-gray-300">Aktifkan sertifikat untuk produk ini</label>
            </div>
            <div id="certOptions" class="hidden space-y-2 pl-7">
              <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                <input type="hidden" name="certificate_auto_issue" value="0">
                <input type="checkbox" name="certificate_auto_issue" value="1" id="productCertAuto" class="w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600">
                Terbitkan otomatis setelah semua materi selesai
              </label>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Judul Sertifikat (opsional)</label>
                <input type="text" name="certificate_title_template" id="productCertTitle" placeholder="Sertifikat Kelulusan {{product}}"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none">
              </div>
            </div>
            <div class="flex items-center gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
              <input type="hidden" name="reviews_enabled" value="0">
              <input type="checkbox" name="reviews_enabled" value="1" id="productReviewsEnabled"
                     class="w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]">
              <label for="productReviewsEnabled" class="text-sm text-gray-700 dark:text-gray-300">Aktifkan Review &amp; Testimoni</label>
            </div>
          </div>

            <!-- Pengaturan Lisensi -->
            <div class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Pengaturan Lisensi</p>
            <div class="flex items-center gap-3">
              <!-- hidden default 0 agar tetap terkirim saat checkbox tidak dicentang -->
              <input type="hidden" name="license_enabled" value="0">
              <input type="checkbox" name="license_enabled" value="1" id="productLicenseEnabled"
                     class="w-4 h-4 rounded text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]"
                     onchange="syncLicenseOptions()">
              <label for="productLicenseEnabled" class="text-sm text-gray-700 dark:text-gray-300">
                Aktifkan license key untuk produk ini
              </label>
            </div>
            <div id="licenseOptions" class="hidden grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Maks Aktivasi (Seats)</label>
                <input type="number" name="license_seats" id="productLicenseSeats" min="1" max="999" value="1"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Berapa domain/device yang bisa aktifkan 1 license key.</p>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Masa Berlaku (hari)</label>
                <input type="number" name="license_validity_days" id="productLicenseValidity" min="1"
                       placeholder="Kosongkan = Selamanya"
                       class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Dihitung sejak pertama aktivasi, bukan sejak pembelian.</p>
              </div>
            </div>
            </div>

            <!-- Integrasi & Otomasi (opsional, jarang dipakai) -->
            <div class="bg-gray-50 dark:bg-gray-800/40 rounded-xl p-4 space-y-3">
            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Integrasi &amp; Otomasi <span class="normal-case font-normal tracking-normal text-gray-400">(opsional)</span></p>

            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Kode Program Eksternal <span class="text-gray-400 font-normal">(integrasi webhook)</span></label>
              <input type="text" name="external_program_code" id="productExternalCode" maxlength="100"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                     placeholder="mis. PROGRAM_A">
              <p class="text-[11px] text-gray-400 mt-1">Dikirim ke aplikasi eksternal saat pembelian lunas (grant akses). Kosongkan bila produk ini tak terintegrasi. Atur endpoint di <a href="<?= e(APP_URL) ?>/admin/webhooks" class="underline">Integrasi Webhook</a>.</p>
            </div>

            <div class="pt-2 border-t border-gray-100 dark:border-gray-700">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Redirect Setelah Bayar</label>
              <input type="text" name="redirect_after_paid_url" id="productRedirectAfterPaid" maxlength="500"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                     placeholder="https://app-tujuan.com/welcome?email={email}&order={order_id}&sig={sig}">
              <p class="text-[11px] text-gray-400 mt-1">Setelah pembayaran lunas, pembeli otomatis diarahkan ke URL ini. Kosong = tetap di halaman sukses. Placeholder terisi otomatis: <code class="text-[10px]">{order_id}</code> <code class="text-[10px]">{email}</code> <code class="text-[10px]">{name}</code> <code class="text-[10px]">{phone}</code> <code class="text-[10px]">{product_id}</code> <code class="text-[10px]">{product_name}</code> <code class="text-[10px]">{amount}</code> <code class="text-[10px]">{sig}</code> (tanda tangan HMAC untuk verifikasi keaslian) <code class="text-[10px]">{token}</code> (JWT auto-login ber-kedaluwarsa untuk melogin-kan pembeli otomatis di app tujuan).</p>
              <p class="text-[11px] text-gray-400 mt-1">Signing secret <code class="text-[10px]">{sig}</code> = <code class="text-[10px] select-all break-all"><?= e(redirectSigningSecret()) ?></code> — berikan ke app tujuan. Rumus: <code class="text-[10px]">HMAC_SHA256("{order_id}|{email}", secret)</code>.</p>
            </div>
            </div>

          </div>

          <!-- ── TAB: Media & Link ── -->
          <div class="product-tab-panel grid grid-cols-1 md:grid-cols-2 gap-4 items-start" data-panel="media" role="tabpanel" hidden>

            <!-- Thumbnail Upload -->
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">
                Thumbnail <span class="font-normal text-gray-400 dark:text-gray-600">— JPG/PNG/WebP, maks 2MB</span>
              </label>
              <input type="hidden" name="thumbnail" id="productThumbnail" value="">

              <!-- Drop Zone -->
              <div id="thumbDropzone"
                   class="border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-xl p-5 text-center cursor-pointer hover:border-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_3%,transparent)] transition"
                   onclick="document.getElementById('thumbFile').click()"
                   ondragover="event.preventDefault();this.style.borderColor='var(--brand-color)'"
                   ondragleave="this.style.borderColor=''"
                   ondrop="handleThumbDrop(event)">
                <svg class="w-7 h-7 mx-auto text-gray-300 dark:text-gray-600 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                  <polyline points="17 8 12 3 7 8"/>
                  <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Klik atau drag & drop gambar di sini</p>
                <p class="text-xs text-gray-300 dark:text-gray-600 mt-0.5">JPG, PNG, WebP — maks 2MB</p>
                <input type="file" id="thumbFile" name="thumbnail_file" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="handleThumbFile(this)">
              </div>

              <!-- Preview -->
              <div id="thumbPreview" class="hidden relative rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700">
                <div class="aspect-[4/3] bg-gray-100 dark:bg-gray-800">
                  <img id="thumbPreviewImg" src="" alt="" class="w-full h-full object-cover">
                </div>
                <div class="absolute top-2 right-2 flex items-center gap-1.5">
                  <button type="button" onclick="document.getElementById('thumbFile').click()"
                          class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-white/90 dark:bg-gray-900/90 backdrop-blur text-gray-700 dark:text-gray-200 border border-white/50 hover:bg-white dark:hover:bg-gray-900 shadow-sm transition">
                    Ganti
                  </button>
                  <button type="button" onclick="clearThumb()"
                          class="w-7 h-7 rounded-lg flex items-center justify-center text-sm bg-red-500 hover:bg-red-600 text-white shadow-sm transition font-bold leading-none">
                    ×
                  </button>
                </div>
              </div>

              <p id="thumbError" class="hidden text-xs text-red-500 mt-1.5"></p>
            </div>

            <!-- Landing Page selector -->
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">
                Landing Page <span class="font-normal text-gray-400 dark:text-gray-600">(opsional)</span>
              </label>
              <div class="space-y-1.5">

                <!-- none -->
                <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-white dark:hover:bg-gray-800 transition">
                  <input type="radio" name="lp_type" value="none" class="lp-type-radio mt-0.5 w-4 h-4 text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]">
                  <span class="text-sm text-gray-700 dark:text-gray-300">Tidak ada <span class="text-gray-400 dark:text-gray-600">— tampilkan halaman produk Averion</span></span>
                </label>

                <!-- canvas -->
                <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-white dark:hover:bg-gray-800 transition">
                  <input type="radio" name="lp_type" value="canvas" class="lp-type-radio mt-0.5 w-4 h-4 text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]">
                  <span class="text-sm text-gray-700 dark:text-gray-300">Canvas <span class="text-gray-400 dark:text-gray-600">— gunakan halaman Canvas internal</span></span>
                </label>
                <div id="canvas-select-block" class="hidden ml-6">
                  <select name="canvas_id" id="productCanvasId"
                          class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <option value="">— Pilih Canvas —</option>
                    <?php foreach ($availableCanvases as $cv): ?>
                    <option value="<?= (int) $cv['id'] ?>"><?= e($cv['title']) ?> — /<?= e($cv['slug']) ?><?= (int) ($cv['is_homepage'] ?? 0) === 1 ? ' (Homepage)' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Visitor LP dari Canvas ini akan ter-tracking otomatis ke Funnel Analytics.</p>
                </div>

                <!-- external -->
                <label class="flex items-start gap-2.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-white dark:hover:bg-gray-800 transition">
                  <input type="radio" name="lp_type" value="external" class="lp-type-radio mt-0.5 w-4 h-4 text-[var(--brand-color)] border-gray-300 dark:border-gray-600 focus:ring-[var(--brand-color)]">
                  <span class="text-sm text-gray-700 dark:text-gray-300">URL Eksternal <span class="text-gray-400 dark:text-gray-600">— redirect ke domain lain</span></span>
                </label>
                <div id="external-url-block" class="hidden ml-6">
                  <input type="url" name="landing_page_url" id="productLandingPageUrl"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                         placeholder="https://contoh.com/sales-page">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Link afiliasi akan redirect ke URL ini.</p>
                </div>

              </div>
            </div>

            <!-- Checkout Template -->
            <?php if (!empty($checkoutTemplates)): ?>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
                Checkout Template <span class="font-normal text-gray-400 dark:text-gray-600">(opsional)</span>
              </label>
              <select name="checkout_template_id" id="productCheckoutTemplate"
                      class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                <option value="">— Gunakan template default —</option>
                <?php foreach ($checkoutTemplates as $ct): ?>
                <option value="<?= (int)$ct['id'] ?>">
                  <?= e($ct['name']) ?><?= $ct['is_default'] ? ' (default)' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Pilih tampilan form checkout khusus untuk produk ini.</p>
            </div>
            <?php endif; ?>

            <!-- Kode Kupon (per produk) -->
            <label class="flex flex-wrap items-center justify-between gap-3 cursor-pointer rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5">
              <div>
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Tampilkan Kode Kupon</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Tampilkan card input kupon di checkout produk ini — matikan bila tidak menjalankan promo kupon.</p>
              </div>
              <input type="checkbox" name="show_coupon" id="productShowCoupon" value="1" checked
                     class="w-4 h-4 rounded accent-[var(--brand-color)] shrink-0">
            </label>

            <!-- Mode Checkout -->
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Mode Checkout</label>
              <select name="checkout_mode" id="productCheckoutMode" onchange="syncPublicFormBlock()"
                      class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                <option value="login">Login Dulu (default)</option>
                <option value="public">Form Publik — tanpa login</option>
              </select>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Form Publik cocok untuk produk yang ingin konversi tinggi (buyer langsung isi form tanpa login).</p>
            </div>

            <!-- Template Form Publik (muncul hanya jika mode = public) -->
            <div id="publicFormTemplateBlock" class="hidden">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Template Form Publik</label>
              <select name="public_form_template" id="productPublicFormTemplate"
                      class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                <option value="clean">Clean — form simpel terpusat</option>
                <option value="card">Card — dengan langkah bertahap</option>
                <option value="split">Split — form kiri, ringkasan kanan</option>
              </select>
            </div>
          </div>

          <!-- ── TAB: Deskripsi ── -->
          <div class="product-tab-panel space-y-3" data-panel="deskripsi" role="tabpanel" hidden>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Deskripsi Singkat <span class="font-normal text-gray-400 dark:text-gray-600">(tampil di listing)</span></label>
              <textarea name="short_description" id="productShortDesc" rows="2" maxlength="500"
                        class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
                        placeholder="Deskripsi singkat yang muncul di listing produk..."></textarea>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Deskripsi Lengkap</label>
              <textarea name="description" id="productDesc" rows="4"
                        class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-y"
                        placeholder="Deskripsi lengkap produk..."></textarea>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Yang Kamu Dapat <span class="font-normal text-gray-400 dark:text-gray-600">(highlight di landing — 1 poin per baris)</span></label>
              <textarea name="highlights" id="productHighlights" rows="4"
                        class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-y"
                        placeholder="Contoh:&#10;Lisensi penuh (white-label)&#10;File & panduan instalasi&#10;Update seumur hidup"></textarea>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Kosongkan untuk memakai daftar otomatis sesuai kategori produk.</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Isi Paket <span class="font-normal text-gray-400 dark:text-gray-600">(untuk produk non-kelas — 1 item per baris)</span></label>
              <textarea name="includes" id="productIncludes" rows="4"
                        class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-y"
                        placeholder="Contoh:&#10;Averion — Business Operating System (langganan 1 tahun)&#10;FormPay — Premium White Label License (lifetime)&#10;Sellix — Premium White Label License (lifetime)"></textarea>
              <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Tampil sebagai section "Isi Paket" di landing (menggantikan Kurikulum untuk produk non-kelas). Kosongkan untuk menyembunyikan.</p>
            </div>
            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">AI Knowledge <span class="font-normal text-gray-400 dark:text-gray-600">(bekal jualan untuk AI Chat)</span></label>
                <button type="button" id="aiKnowledgeGenBtn" onclick="generateAiKnowledge()"
                        class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-md border border-[var(--brand-color)] text-[var(--brand-color)] hover:bg-[var(--brand-color)] hover:text-white transition">
                  <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.9 5.8a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-1.9a2 2 0 0 0 1.3-1.3L12 3z"/></svg>
                  Generate draft dengan AI
                </button>
              </div>
              <textarea name="ai_knowledge" id="productAiKnowledge" rows="5" maxlength="4000"
                        class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-y"
                        placeholder="Poin manfaat utama, untuk siapa produk ini, dan jawaban atas keberatan umum (mis. 'kemahalan', 'apa bedanya dengan X'). AI Chat memakai catatan ini untuk merekomendasikan & menjual produkmu."></textarea>
              <p id="aiKnowledgeStatus" class="hidden text-xs mt-1"></p>
            </div>
          </div>

        </div>

        <!-- Sticky Footer -->
        <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 shrink-0 bg-white dark:bg-gray-900 rounded-b-2xl">
          <p id="productSaveHint" class="flex items-center gap-1.5 text-[11px] text-gray-400 dark:text-gray-500 min-w-0">
            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span class="truncate">Lengkapi field wajib: <span class="font-medium text-gray-500 dark:text-gray-400">Nama, Slug, Tipe</span></span>
          </p>
          <div class="flex items-center gap-2.5 shrink-0 ml-auto">
            <button type="button" onclick="closeProductModal()"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
              Batal
            </button>
            <button type="submit" id="productSaveBtn" data-loading data-loading-text="Menyimpan..." disabled
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition opacity-50 cursor-not-allowed">
              <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Simpan Produk
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ─── Modal Kode Embed Form Order ─────────────────────────────── -->
<div id="embedModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEmbedModal()"></div>
  <div class="relative z-10 flex items-center justify-center min-h-full p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg">
      <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
        <h3 class="font-bold text-gray-900 dark:text-white text-base">Kode Embed Form Order</h3>
        <button onclick="closeEmbedModal()" class="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="p-5 space-y-3">
        <p class="text-sm text-gray-600 dark:text-gray-400">Tempel kode ini di situs mana pun (WordPress, landing page, link bio). Form order akan tampil sebagai iframe dan <strong>menyesuaikan tinggi otomatis</strong>. Pembayaran gateway membuka di jendela penuh; transfer manual tetap di dalam form. <span id="embedProdName" class="font-semibold text-gray-900 dark:text-white"></span></p>
        <textarea id="embedCode" readonly rows="7" onclick="this.select()"
                  class="w-full px-3 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 font-mono text-xs leading-relaxed outline-none focus:ring-2 focus:ring-[var(--brand-color)]"></textarea>
        <div class="flex items-center gap-2">
          <button type="button" onclick="copyEmbedCode(this)"
                  class="px-4 py-2 rounded-lg text-sm font-semibold text-white hover:opacity-90 transition" style="background-color: var(--brand-color);">Salin Kode</button>
          <a id="embedPreviewLink" href="#" target="_blank"
             class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Buka Pratinjau</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Overlay pratinjau embed "Lihat sebagai pembeli" (full-screen statis, tanpa scroll halaman) -->
<div id="embedPreviewOverlay" class="hidden fixed inset-0 z-[60] flex flex-col" style="background:#030712">
  <div class="flex items-center gap-3 h-12 px-4 shrink-0 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
    <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-900 dark:text-white">
      <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      Pratinjau — tampilan pembeli
    </span>
    <button type="button" onclick="closeEmbedPreview()"
            class="ml-auto inline-flex items-center gap-1.5 px-3 h-8 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      Tutup
    </button>
  </div>
  <iframe id="embedPreviewFrame" class="flex-1 w-full block bg-white" style="border:0" title="Pratinjau embed"></iframe>
</div>

<script>
// ── Kode Embed Form Order (iframe + auto-resize via postMessage) ──
var EMBED_BASE = <?= json_encode(rtrim(APP_URL, '/')) ?>;
function buildEmbedSnippet(slug) {
  var src = EMBED_BASE + '/embed/checkout/' + slug;
  // Bungkus dgn div text-align:center + iframe inline-block → iframe center di
  // container mana pun (Elementor/WordPress/dll), tak menempel ke kiri.
  return '<div style="text-align:center">\n'
       + '  <iframe src="' + src + '" style="width:100%;max-width:520px;border:0;overflow:hidden;display:inline-block" title="Form Order" loading="lazy"></iframe>\n'
       + '</div>\n'
       + '<script>(function(){window.addEventListener("message",function(e){if(!e.data||!e.data.averionEmbed||!e.data.height)return;var f=document.getElementsByTagName("iframe");for(var i=0;i<f.length;i++){if(f[i].contentWindow===e.source){f[i].style.height=e.data.height+"px";}}});})();<\/script>';
}
function openEmbedModal(slug, name) {
  document.getElementById('embedCode').value = buildEmbedSnippet(slug);
  document.getElementById('embedProdName').textContent = name ? '(' + name + ')' : '';
  document.getElementById('embedPreviewLink').href = EMBED_BASE + '/embed/checkout/' + slug;
  document.getElementById('embedModal').classList.remove('hidden');
}
function closeEmbedModal() { document.getElementById('embedModal').classList.add('hidden'); }
function copyEmbedCode(btn) {
  var code = document.getElementById('embedCode').value;
  var done = function () { var o = btn.textContent; btn.textContent = 'Tersalin!'; setTimeout(function () { btn.textContent = o; }, 1500); };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(code).then(done).catch(function () { fallbackCopyUrl(code); done(); });
  } else { fallbackCopyUrl(code); done(); }
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeEmbedModal(); });

// ── Salin URL produk ke clipboard + feedback ikon centang ──
function copyProductUrl(btn) {
  var url = btn.getAttribute('data-url');
  var origTitle = btn.getAttribute('title'); // kembalikan judul asli (reusable utk tombol salin apa pun)
  var done = function () {
    var orig = btn.innerHTML;
    btn.innerHTML = '<svg class="w-4 h-4 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
    btn.classList.add('text-green-500');
    btn.setAttribute('title', 'Tersalin!');
    setTimeout(function () {
      btn.innerHTML = orig;
      btn.classList.remove('text-green-500');
      btn.setAttribute('title', origTitle);
    }, 1500);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(done).catch(function () { fallbackCopyUrl(url); done(); });
  } else {
    fallbackCopyUrl(url); done();
  }
}
function fallbackCopyUrl(text) {
  var ta = document.createElement('textarea');
  ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
}

// ── Data produk untuk tombol Edit & restore saat tombol Back ──
var PRODUCT_MAP  = <?= json_encode($productMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var PRODUCTS_URL = <?= json_encode(APP_URL . '/admin/products') ?>;

// ── Tab modal ─────────────────────────────────────────────────
function setProductTab(name) {
  document.querySelectorAll('#productModal .product-tab').forEach(function (btn) {
    btn.setAttribute('aria-selected', btn.getAttribute('data-tab') === name ? 'true' : 'false');
  });
  document.querySelectorAll('#productModal .product-tab-panel').forEach(function (panel) {
    panel.hidden = (panel.getAttribute('data-panel') !== name);
  });
  var body = document.querySelector('#productForm .overflow-y-auto');
  if (body) body.scrollTop = 0;
}

// Field wajib bisa berada di tab yang sedang tersembunyi — browser tak bisa
// fokus ke sana & submit gagal senyap. Buka tab-nya dulu saat validasi gagal.
document.getElementById('productForm').addEventListener('invalid', function (e) {
  var panel = e.target.closest('.product-tab-panel');
  if (panel && panel.hidden) setProductTab(panel.getAttribute('data-panel'));
}, true);

// ── Gate tombol Simpan: aktif hanya bila semua field wajib terisi ──
// Cek nilai [required] secara manual (JANGAN checkValidity — itu memicu event
// 'invalid' yang melompatkan tab saat pengguna masih mengetik).
function productRequiredFilled() {
  var reqs = document.getElementById('productForm').querySelectorAll('[required]');
  for (var i = 0; i < reqs.length; i++) {
    var el = reqs[i];
    if (el.disabled) continue;
    if (!String(el.value || '').trim()) return false;
  }
  return true;
}
function updateProductSaveBtn() {
  var btn = document.getElementById('productSaveBtn');
  if (!btn) return;
  var ok = productRequiredFilled();
  btn.disabled = !ok;
  btn.classList.toggle('opacity-50', !ok);
  btn.classList.toggle('cursor-not-allowed', !ok);
  btn.classList.toggle('hover:opacity-90', ok);
  var hint = document.getElementById('productSaveHint');
  if (hint) hint.classList.toggle('hidden', ok);
}
(function () {
  var form = document.getElementById('productForm');
  form.addEventListener('input', updateProductSaveBtn);
  form.addEventListener('change', updateProductSaveBtn);
})();

// ── Guard perubahan belum disimpan ────────────────────────────
var productFormBaseline = '';
var productModalPushed  = false;

function productFormSnapshot() {
  var parts = [];
  Array.prototype.forEach.call(document.getElementById('productForm').elements, function (el) {
    if (!el.name || el.name === 'csrf_token') return;
    if (el.type === 'checkbox' || el.type === 'radio') {
      parts.push(el.name + '=' + (el.checked ? '1' : '0'));
    } else if (el.type === 'file') {
      parts.push(el.name + '=' + (el.files && el.files.length ? el.files[0].name + ':' + el.files[0].size : ''));
    } else {
      parts.push(el.name + '=' + el.value);
    }
  });
  return parts.join('|');
}

function isProductFormDirty() {
  return productFormSnapshot() !== productFormBaseline;
}

// Setelah submit, form ditinggalkan — jangan tanya lagi.
// Booking Fee & Dime Sale saling eksklusif (server: dime menang). Kunci yang satu
// saat yang lain aktif + tampilkan catatan, agar tak "aktif tapi diam-diam ditolak".
function syncBookingDime(source) {
  var bk = document.getElementById('productBookingEnabled');
  var dm = document.getElementById('productDimeEnabled');
  if (!bk || !dm) return;
  if (bk.checked && dm.checked) {
    // Keduanya ter-check (mis. data lama) → pemenang = yang barusan diklik; default Dime.
    if (source === 'booking') { dm.checked = false; var d = document.getElementById('dimeOptions'); if (d) d.classList.add('hidden'); }
    else { bk.checked = false; var b = document.getElementById('bookingOptions'); if (b) b.classList.add('hidden'); }
  }
  dm.disabled = bk.checked;
  bk.disabled = dm.checked;
  var bkNote = document.getElementById('bookingDimeNote');
  var dmNote = document.getElementById('dimeBookingNote');
  if (bkNote) bkNote.classList.toggle('hidden', !dm.checked); // booking dikunci krn dime
  if (dmNote) dmNote.classList.toggle('hidden', !bk.checked); // dime dikunci krn booking
}

document.getElementById('productForm').addEventListener('submit', function (e) {
  // Validasi Booking Fee: bila diaktifkan, DP wajib 0 < fee < harga. Cegah simpan
  // "aktif tapi diam-diam mati" — beri umpan balik langsung, bukan nonaktif senyap.
  var bkOn  = document.getElementById('productBookingEnabled');
  var errEl = document.getElementById('bookingFeeError');
  if (errEl) errEl.classList.add('hidden');
  if (bkOn && bkOn.checked) {
    var fee   = parseFloat(document.getElementById('productBookingFee').value) || 0;
    var price = parseFloat(document.getElementById('productPrice').value) || 0;
    if (fee <= 0 || price <= 0 || fee >= price) {
      e.preventDefault();
      if (typeof setProductTab === 'function') setProductTab('harga');
      var opts = document.getElementById('bookingOptions');
      if (opts) opts.classList.remove('hidden');
      if (errEl) {
        errEl.textContent = (price <= 0)
          ? 'Isi harga produk lebih dulu sebelum mengaktifkan Booking Fee.'
          : 'Nominal DP harus lebih dari 0 dan lebih kecil dari harga produk.';
        errEl.classList.remove('hidden');
      }
      var f = document.getElementById('productBookingFee'); if (f) { f.focus(); f.select && f.select(); }
      return false;
    }
  }
  productFormBaseline = productFormSnapshot();
});

// ── URL deep-link: /admin/products?edit=<id> ──────────────────
function productModalUrl(id) {
  return PRODUCTS_URL + (id ? '?edit=' + encodeURIComponent(id) : '?edit=new');
}

function openProductModal(product, opts) {
  opts = opts || {};
  var wasOpen = !document.getElementById('productModal').classList.contains('hidden');
  document.getElementById('productModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  setProductTab('dasar');
  clearThumb();
  clearThumbError();
  if (product) {
    document.getElementById('modalTitle').textContent      = 'Edit Produk';
    document.getElementById('modalSubtitle').textContent   = product.name || '';
    document.getElementById('productId').value             = product.id                    || '';
    document.getElementById('productName').value           = product.name                  || '';
    document.getElementById('productSlug').value           = product.slug                  || '';
    document.getElementById('productType').value           = product.type                  || '';
    document.getElementById('productCatalogCategory').value = product.catalog_category     || '';
    document.getElementById('productStatus').value         = product.status                || 'active';
    document.getElementById('productHideCatalog').checked  = String(product.hide_from_catalog) === '1';
    // launch_at "Y-m-d H:i:s" → format datetime-local "Y-m-dTH:i"
    document.getElementById('productLaunchAt').value       = product.launch_at ? String(product.launch_at).replace(' ', 'T').slice(0, 16) : '';
    syncLaunchAtField();
    document.getElementById('productPrice').value          = product.price                 || 0;
    document.getElementById('productOriginalPrice').value  = product.original_price        || 0;
    document.getElementById('productPriceMyr').value       = (product.price_myr != null ? product.price_myr : '');
    document.getElementById('productLanguage').value       = product.language              || 'id';
    document.getElementById('productDuration').value       = product.access_duration_days  || 0;
    document.getElementById('productCommission').value     = product.affiliate_commission  || 0;
    document.getElementById('productSalespageEnabled').checked = (product.salespage_enabled == 1);
    document.getElementById('productResellProductId').value = product.resell_product_id || '';
    document.getElementById('salespageFields').classList.toggle('hidden', product.salespage_enabled != 1);
    document.getElementById('productAccessEnabled').checked = (product.access_page_enabled == 1);
    document.getElementById('productAccessType').value     = product.access_type || 'course';
    document.getElementById('productEmbedHtml').value      = product.embed_html || '';
    document.getElementById('productEmbedUrl').value       = product.embed_url || '';
    syncAccessType();
    document.getElementById('productSetupFee').value       = product.setup_fee             || 0;
    document.getElementById('productPricingType').value    = product.pricing_type          || 'fixed';
    document.getElementById('productFlexLabel').value      = product.flexible_label        || '';
    document.getElementById('productCtaLabel').value       = product.cta_label             || '';
    document.getElementById('productFlexMin').value        = product.flexible_min          || 0;
    document.getElementById('productFlexPresets').value    = product.flexible_presets      || '';
    document.getElementById('productShowOnSupport').checked = (product.show_on_support == 1);
    document.getElementById('productBookingEnabled').checked = (product.booking_enabled == 1);
    document.getElementById('productBookingFee').value      = product.booking_fee           || 0;
    document.getElementById('productBookingDeadline').value = product.booking_deadline_days || 7;
    document.getElementById('productBookingInstant').checked = (product.booking_instant_access == 1);
    document.getElementById('bookingOptions').classList.toggle('hidden', product.booking_enabled != 1);
    document.getElementById('productDimeEnabled').checked   = (product.dime_enabled == 1);
    document.getElementById('productDimeStep').value        = product.dime_step_qty || 10;
    document.getElementById('productDimeIncrement').value   = product.dime_increment || 0;
    document.getElementById('productDimeMax').value         = (product.dime_max_price != null ? product.dime_max_price : '');
    document.getElementById('dimeOptions').classList.toggle('hidden', product.dime_enabled != 1);
    syncBookingDime();
    document.getElementById('productJvPercent').value       = product.jv_partner_percent || 0;
    if (product.jv_partner_user_id && product.jv_partner_label) {
      jvSetPartner(product.jv_partner_user_id, product.jv_partner_label);
    } else { jvClearPartner(); }
    document.getElementById('productSpMode').value          = product.sp_mode || 'all';
    document.getElementById('productLandingPageUrl').value = product.landing_page_url      || '';
    var ciEl = document.getElementById('productCanvasId');
    if (ciEl) ciEl.value = product.canvas_id || '';
    setLpType(product.lp_type || 'none');
    document.getElementById('productShortDesc').value      = product.short_description     || '';
    document.getElementById('productDesc').value           = product.description           || '';
    document.getElementById('productHighlights').value     = product.highlights            || '';
    document.getElementById('productIncludes').value       = product.includes              || '';
    document.getElementById('productAiKnowledge').value    = product.ai_knowledge          || '';
    var ctEl = document.getElementById('productCheckoutTemplate');
    if (ctEl) ctEl.value = product.checkout_template_id || '';
    document.getElementById('productShowCoupon').checked = (product.show_coupon ?? 1) == 1;
    document.getElementById('productCheckoutMode').value       = product.checkout_mode || 'login';
    document.getElementById('productPublicFormTemplate').value = product.public_form_template || 'clean';
    document.getElementById('productExternalCode').value       = product.external_program_code || '';
    document.getElementById('productRedirectAfterPaid').value   = product.redirect_after_paid_url || '';
    syncPublicFormBlock();
    document.getElementById('productLicenseEnabled').checked = (product.license_enabled == 1);
    document.getElementById('productCertEnabled').checked = (product.certificate_enabled == 1);
    document.getElementById('productCertAuto').checked = (product.certificate_auto_issue == 1);
    document.getElementById('productCertTitle').value = product.certificate_title_template || '';
    document.getElementById('certOptions').classList.toggle('hidden', product.certificate_enabled != 1);
    document.getElementById('productReviewsEnabled').checked = (product.reviews_enabled == 1);
    document.getElementById('productLicenseSeats').value     = product.license_seats || 1;
    document.getElementById('productLicenseValidity').value  = product.license_validity_days || '';
    syncLicenseOptions();
    if (product.thumbnail) {
      showThumbPreview(product.thumbnail);
      document.getElementById('productThumbnail').value = product.thumbnail;
    }
  } else {
    document.getElementById('modalTitle').textContent    = 'Tambah Produk';
    document.getElementById('modalSubtitle').textContent = 'Isi detail produk di bawah';
    document.getElementById('productId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('productStatus').value = 'active';
    document.getElementById('productHideCatalog').checked = false;
    document.getElementById('productLaunchAt').value = '';
    syncLaunchAtField();
    document.getElementById('productBookingEnabled').checked = false;
    document.getElementById('productBookingFee').value = 0;
    document.getElementById('productBookingDeadline').value = 7;
    document.getElementById('productBookingInstant').checked = false;
    document.getElementById('bookingOptions').classList.add('hidden');
    document.getElementById('productDimeEnabled').checked = false;
    document.getElementById('productDimeEnabled').disabled = false;
    document.getElementById('productBookingEnabled').disabled = false;
    document.getElementById('productDimeStep').value = 10;
    document.getElementById('productDimeIncrement').value = 0;
    document.getElementById('productDimeMax').value = '';
    document.getElementById('dimeOptions').classList.add('hidden');
    document.getElementById('productJvPercent').value = 0;
    jvClearPartner();
    document.getElementById('productSpMode').value = 'all';
    document.getElementById('productPrice').value = 0;
    document.getElementById('productOriginalPrice').value = 0;
    document.getElementById('productPriceMyr').value = '';
    document.getElementById('productLanguage').value = 'id';
    document.getElementById('productDuration').value = 0;
    document.getElementById('productCommission').value = 0;
    document.getElementById('productSalespageEnabled').checked = false;
    document.getElementById('productResellProductId').value = '';
    document.getElementById('salespageFields').classList.add('hidden');
    document.getElementById('productAccessEnabled').checked = false;
    document.getElementById('productAccessType').value = 'course';
    document.getElementById('productEmbedHtml').value = '';
    document.getElementById('productEmbedUrl').value = '';
    syncAccessType();
    document.getElementById('productPricingType').value = 'fixed';
    document.getElementById('productFlexLabel').value = '';
    document.getElementById('productCtaLabel').value = '';
    document.getElementById('productFlexMin').value = 0;
    document.getElementById('productFlexPresets').value = '';
    document.getElementById('productShowCoupon').checked = true;
    document.getElementById('productCatalogCategory').value = '';
    document.getElementById('productCheckoutMode').value = 'login';
    document.getElementById('productPublicFormTemplate').value = 'clean';
  document.getElementById('productExternalCode').value = '';
    syncPublicFormBlock();
    document.getElementById('productLicenseEnabled').checked = false;
    document.getElementById('productLicenseSeats').value = 1;
    document.getElementById('productLicenseValidity').value = '';
    document.getElementById('productCertEnabled').checked = false;
    document.getElementById('productCertAuto').checked = false;
    document.getElementById('productCertTitle').value = '';
    document.getElementById('certOptions').classList.add('hidden');
    document.getElementById('productReviewsEnabled').checked = false;
    syncLicenseOptions();
    setLpType('none');
  }
  syncPricingType();
  document.getElementById('productName').focus();
  updateProductSaveBtn(); // sinkronkan state tombol Simpan dgn isi form

  // Baseline dirty-check diambil setelah form terisi penuh.
  productFormBaseline = productFormSnapshot();

  // Simpan state ke URL supaya bisa di-bookmark/share & Back menutup modal.
  if (!opts.fromPopState && !wasOpen) {
    var id = (product && product.id) ? String(product.id) : 'new';
    history.pushState({ averionProductModal: id }, '', productModalUrl(product && product.id ? product.id : ''));
    productModalPushed = true;
  }
}

// ── Mode Akses: Embed langsung → tampilkan field embed ──
function syncAccessType() {
  var v = document.getElementById('productAccessType').value;
  document.getElementById('embedFields').classList.toggle('hidden', v !== 'embed');
}

// ── Pratinjau embed "Lihat sebagai pembeli" (client-side, tak perlu simpan) ──
// Meniru persis iframe sandbox di halaman akses pembeli (embed-access.php):
// HTML → srcdoc TANPA allow-same-origin; URL → src DENGAN allow-same-origin.
function previewEmbed() {
  var html = (document.getElementById('productEmbedHtml').value || '').trim();
  var url  = (document.getElementById('productEmbedUrl').value || '').trim();
  var fr   = document.getElementById('embedPreviewFrame');
  if (!html && !url) {
    if (typeof showToast === 'function') showToast('Isi Kode Embed (HTML) atau URL Tool dulu untuk pratinjau.', 'error');
    return;
  }
  if (html) {
    fr.removeAttribute('src');
    fr.setAttribute('sandbox', 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals allow-downloads');
    fr.srcdoc = html;
  } else {
    fr.removeAttribute('srcdoc');
    fr.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-modals allow-downloads');
    fr.setAttribute('referrerpolicy', 'no-referrer');
    fr.src = url;
  }
  document.getElementById('embedPreviewOverlay').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}
function closeEmbedPreview() {
  var fr = document.getElementById('embedPreviewFrame');
  fr.removeAttribute('srcdoc'); fr.removeAttribute('src'); // hentikan konten (mis. video/audio)
  document.getElementById('embedPreviewOverlay').classList.add('hidden');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && !document.getElementById('embedPreviewOverlay').classList.contains('hidden')) closeEmbedPreview();
});

// ── Tipe Harga: flexible → tampilkan field flexible, sembunyikan harga fix/setup/booking ──
function syncPricingType() {
  var flex = document.getElementById('productPricingType').value === 'flexible';
  document.getElementById('flexibleFields').classList.toggle('hidden', !flex);
  ['fpPriceGrid', 'fpSetup', 'fpBooking', 'fpDime'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.classList.toggle('hidden', flex);
  });
}

// ── JV Partner: picker single user (search endpoint sama dgn Info Member) ──
function jvSetPartner(id, label) {
  document.getElementById('productJvPartnerId').value = id;
  document.getElementById('jvSelectedLabel').textContent = label;
  var sel = document.getElementById('jvSelected');
  sel.classList.remove('hidden'); sel.classList.add('flex');
}
function jvClearPartner() {
  document.getElementById('productJvPartnerId').value = '';
  var sel = document.getElementById('jvSelected');
  sel.classList.add('hidden'); sel.classList.remove('flex');
}
(function () {
  var inp = document.getElementById('jvPartnerSearch');
  if (!inp) return;
  var timer = null;
  inp.addEventListener('input', function () {
    var q = inp.value.trim();
    var box = document.getElementById('jvPartnerResults');
    clearTimeout(timer);
    if (q.length < 2) { box.classList.add('hidden'); return; }
    timer = setTimeout(function () {
      fetch('<?= e(rtrim(APP_URL, '/')) ?>/actions/admin/info-user-search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (list) {
          box.innerHTML = '';
          if (!list.length) {
            var p = document.createElement('p');
            p.className = 'px-3 py-2.5 text-xs text-gray-400';
            p.textContent = 'Tidak ada user yang cocok.';
            box.appendChild(p);
          } else {
            list.forEach(function (u) {
              var b = document.createElement('button');
              b.type = 'button';
              b.className = 'w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800 transition';
              var n = document.createElement('span'); n.className = 'block text-sm text-gray-900 dark:text-white'; n.textContent = u.name;
              var m = document.createElement('span'); m.className = 'block text-xs text-gray-400'; m.textContent = u.email;
              b.appendChild(n); b.appendChild(m);
              b.onclick = function () {
                jvSetPartner(u.id, u.name + ' (' + u.email + ')');
                box.classList.add('hidden');
                inp.value = '';
              };
              box.appendChild(b);
            });
          }
          box.classList.remove('hidden');
        }).catch(function () { box.classList.add('hidden'); });
    }, 250);
  });
  document.addEventListener('click', function (e) {
    var wrap = document.getElementById('fpJv');
    if (wrap && !wrap.contains(e.target)) document.getElementById('jvPartnerResults').classList.add('hidden');
  });
})();

// ── Lisensi: tampilkan opsi seats & validity hanya bila license aktif ──
function syncLicenseOptions() {
  var on    = document.getElementById('productLicenseEnabled').checked;
  var block = document.getElementById('licenseOptions');
  if (block) block.classList.toggle('hidden', !on);
}

// ── Jadwal launch: tampil hanya bila status = coming_soon ─────
function syncLaunchAtField() {
  var st   = document.getElementById('productStatus').value;
  var wrap = document.getElementById('launchAtWrap');
  if (wrap) wrap.classList.toggle('hidden', st !== 'coming_soon');
}

// ── Mode Checkout: tampilkan template form publik hanya bila mode=public ──
function syncPublicFormBlock() {
  var mode  = document.getElementById('productCheckoutMode').value;
  var block = document.getElementById('publicFormTemplateBlock');
  if (block) block.classList.toggle('hidden', mode !== 'public');
}

// ── Landing Page selector ─────────────────────────────────────
function setLpType(type) {
  var radios = document.querySelectorAll('.lp-type-radio');
  radios.forEach(function (r) { r.checked = (r.value === type); });
  syncLpBlocks();
}

function syncLpBlocks() {
  var checked = document.querySelector('.lp-type-radio:checked');
  var val = checked ? checked.value : 'none';
  document.getElementById('canvas-select-block').classList.toggle('hidden', val !== 'canvas');
  document.getElementById('external-url-block').classList.toggle('hidden', val !== 'external');
}

document.querySelectorAll('.lp-type-radio').forEach(function (r) {
  r.addEventListener('change', syncLpBlocks);
});

function closeProductModal(opts) {
  opts = opts || {};
  if (document.getElementById('productModal').classList.contains('hidden')) return;

  // Backdrop / Esc / tombol X: satu klik nyasar tak boleh membuang isian.
  if (!opts.force && isProductFormDirty()) {
    confirmAndRun({
      title:       'Tutup tanpa menyimpan?',
      message:     'Ada perubahan pada produk ini yang belum disimpan. Kalau ditutup sekarang, perubahan itu hilang.',
      confirmText: 'Ya, tutup',
      cancelText:  'Lanjut edit',
      variant:     'warning',
    }, function () {
      closeProductModal({ force: true, fromPopState: opts.fromPopState });
    });
    return;
  }

  document.getElementById('productModal').classList.add('hidden');
  document.body.style.overflow = '';
  productFormBaseline = productFormSnapshot();

  if (!opts.fromPopState) {
    // Kembalikan URL ke daftar produk. history.back() dipakai agar entri
    // modal tak menumpuk di riwayat browser.
    if (productModalPushed) {
      productModalPushed = false;
      history.back();
    } else {
      history.replaceState(null, '', PRODUCTS_URL);
    }
  }
}

// Tombol Back: tutup modal (dengan konfirmasi bila ada perubahan).
window.addEventListener('popstate', function (e) {
  var st     = e.state || {};
  var isOpen = !document.getElementById('productModal').classList.contains('hidden');

  if (st.averionProductModal) {
    productModalPushed = true;
    if (!isOpen) {
      var p = st.averionProductModal === 'new' ? null : PRODUCT_MAP[st.averionProductModal];
      openProductModal(p, { fromPopState: true });
    }
    return;
  }

  productModalPushed = false;
  if (!isOpen) return;

  if (isProductFormDirty()) {
    var id = document.getElementById('productId').value;
    showConfirmModal({
      title:       'Tutup tanpa menyimpan?',
      message:     'Ada perubahan pada produk ini yang belum disimpan. Kalau ditutup sekarang, perubahan itu hilang.',
      confirmText: 'Ya, tutup',
      cancelText:  'Lanjut edit',
      variant:     'warning',
    }).then(function (ok) {
      if (ok) {
        closeProductModal({ force: true, fromPopState: true });
      } else {
        // Batal → kembalikan URL modal supaya tetap sinkron dengan layar.
        history.pushState({ averionProductModal: id || 'new' }, '', productModalUrl(id));
        productModalPushed = true;
      }
    });
    return;
  }
  closeProductModal({ force: true, fromPopState: true });
});

function generateSlug() {
  document.getElementById('productSlug').value = slugify(document.getElementById('productName').value);
}

document.getElementById('productName').addEventListener('input', function() {
  if (!document.getElementById('productId').value) {
    document.getElementById('productSlug').value = slugify(this.value);
  }
});

document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  // Saat modal konfirmasi terbuka, Esc miliknya (batal) yang berlaku.
  var cm = document.getElementById('confirmModal');
  if (cm && !cm.classList.contains('hidden')) return;
  closeProductModal();
});

// Auto-buka modal dari URL ?edit=<id> (bookmark/share). Ditunda sampai DOM siap
// karena app.js (showToast dkk) baru dimuat di footer. Entri riwayat saat ini
// ditandai sebagai state modal — tanpa push baru — supaya Back keluar ke daftar.
<?php if ($editProduct || $editId > 0): ?>
document.addEventListener('DOMContentLoaded', function () {
  <?php if ($editProduct): ?>
  history.replaceState({ averionProductModal: '<?= (int) $editProduct['id'] ?>' }, '', productModalUrl(<?= (int) $editProduct['id'] ?>));
  productModalPushed = false;
  openProductModal(PRODUCT_MAP[<?= (int) $editProduct['id'] ?>], { fromPopState: true });
  <?php else: ?>
  history.replaceState(null, '', PRODUCTS_URL);
  showToast('Produk tidak ditemukan — mungkin sudah dihapus.', 'error');
  <?php endif; ?>
});
<?php endif; ?>

// ── Thumbnail Upload ──────────────────────────────────────────
function handleThumbFile(input) {
  var file = input.files[0];
  if (!file) return;
  var allowed = ['image/jpeg', 'image/png', 'image/webp'];
  if (!allowed.includes(file.type)) {
    showThumbError('Tipe file tidak didukung. Gunakan JPG, PNG, atau WebP.');
    input.value = '';
    return;
  }
  if (file.size > 2 * 1024 * 1024) {
    showThumbError('Ukuran file maksimal 2MB.');
    input.value = '';
    return;
  }
  clearThumbError();

  // ── Cegah ERR_UPLOAD_FILE_CHANGED (umum di Android) ──
  // Di HP, file dari Google Photos/galeri cloud/Drive bisa "berubah atau pindah"
  // antara saat dipilih dan saat form disubmit (apalagi bila jeda-nya lama karena
  // mengisi banyak field). Chrome lalu MEMBATALKAN upload dengan ERR_UPLOAD_FILE_CHANGED
  // — request bahkan tak sampai ke server. Solusinya: salin byte file ke File
  // in-memory SEKARANG (saat dipilih) lalu pasang kembali ke input, jadi submit
  // memakai salinan itu dan tak membaca ulang dari disk. Fallback aman: pakai file asli.
  if (typeof file.arrayBuffer === 'function' && 'DataTransfer' in window) {
    file.arrayBuffer().then(function (buf) {
      try {
        var snap = new File([buf], file.name || 'thumbnail',
          { type: file.type, lastModified: Date.now() });
        var dt = new DataTransfer();
        dt.items.add(snap);
        input.files = dt.files; // mengganti files TIDAK memicu ulang onchange
      } catch (ex) { /* fallback: biarkan file asli */ }
    }).catch(function () { /* fallback: biarkan file asli */ });
  }

  var reader = new FileReader();
  reader.onload = function(e) {
    showThumbPreview(e.target.result);
    document.getElementById('productThumbnail').value = '';
  };
  reader.readAsDataURL(file);
}

function handleThumbDrop(e) {
  e.preventDefault();
  e.currentTarget.style.borderColor = '';
  var file = e.dataTransfer.files[0];
  if (!file) return;
  var input = document.getElementById('thumbFile');
  try { var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; } catch(ex) {}
  handleThumbFile(input);
}

function showThumbPreview(src) {
  document.getElementById('thumbDropzone').classList.add('hidden');
  document.getElementById('thumbPreview').classList.remove('hidden');
  document.getElementById('thumbPreviewImg').src = src;
}

function clearThumb() {
  document.getElementById('thumbDropzone').classList.remove('hidden');
  document.getElementById('thumbPreview').classList.add('hidden');
  document.getElementById('thumbPreviewImg').src = '';
  document.getElementById('thumbFile').value = '';
  document.getElementById('productThumbnail').value = '';
}

function showThumbError(msg) {
  var el = document.getElementById('thumbError');
  el.textContent = msg;
  el.classList.remove('hidden');
}

function clearThumbError() {
  var el = document.getElementById('thumbError');
  if (el) el.classList.add('hidden');
}

// ── Generate draft AI Knowledge dari data produk di form ──
function generateAiKnowledge() {
  var btn    = document.getElementById('aiKnowledgeGenBtn');
  var ta     = document.getElementById('productAiKnowledge');
  var status = document.getElementById('aiKnowledgeStatus');
  var name   = (document.getElementById('productName') || {}).value || '';
  var desc   = (document.getElementById('productDesc') || {}).value || '';
  var sdesc  = (document.getElementById('productShortDesc') || {}).value || '';
  var price  = (document.getElementById('productPrice') || {}).value || '';

  function say(msg, isError) {
    status.textContent = msg;
    status.className = 'text-xs mt-1 ' + (isError ? 'text-red-500' : 'text-gray-400');
  }
  if (name.trim() === '') { say('Isi nama produk dulu sebelum generate.', true); return; }

  btn.disabled = true;
  var oldHtml = btn.innerHTML;
  btn.textContent = 'Menyusun draft...';
  say('AI sedang menyusun draft — beberapa detik...', false);

  var fd = new FormData();
  fd.append('csrf_token', '<?= e($_SESSION['csrf_token']) ?>');
  fd.append('name', name);
  fd.append('description', desc);
  fd.append('short_description', sdesc);
  fd.append('price', price);

  fetch('<?= e(APP_URL) ?>/actions/admin/ai-generate-knowledge', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success && d.draft) {
        ta.value = d.draft;
        say('Draft selesai — silakan periksa & sesuaikan sebelum simpan.', false);
      } else {
        say((d && d.message) || 'Gagal menyusun draft. Cek pengaturan AI Chat (API key).', true);
      }
    })
    .catch(function () { say('Gagal terhubung. Coba lagi.', true); })
    .finally(function () { btn.disabled = false; btn.innerHTML = oldHtml; });
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
