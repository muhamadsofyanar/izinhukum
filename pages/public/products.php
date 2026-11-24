<?php
require_once __DIR__ . '/../../bootstrap.php';

$pageTitle = getSetting('app_name', 'STIFIn Mulia') . ' — Produk';

// ─── Filter & Pagination ──────────────────────────────────────
$search     = trim($_GET['q']    ?? '');
$typeFilter = $_GET['type']      ?? '';
$categoryFilter = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 12;

$types = ['class', 'membership', 'ebook', 'software', 'jasa', 'coaching', 'webinar', 'template', 'other'];

$products   = [];
$catalogCategories = [];
$presentTypes = [];
$totalCount = 0;
$totalPages = 1;

try {
    $pdo    = getDB();
    $catalogCategories = $pdo->query("SELECT DISTINCT catalog_category FROM products
        WHERE status IN ('active','coming_soon') AND hide_from_catalog = 0
          AND catalog_category IS NOT NULL AND catalog_category <> '' ORDER BY catalog_category")
        ->fetchAll(PDO::FETCH_COLUMN);
    // Tipe yang BENAR-BENAR punya produk tampil (katalog + coming_soon, tidak di-hide)
    // → chip filter tipe yang kosong tak usah ditampilkan.
    $presentTypes = $pdo->query("SELECT DISTINCT type FROM products
        WHERE status IN ('active','coming_soon') AND hide_from_catalog = 0
          AND type IS NOT NULL AND type <> ''")
        ->fetchAll(PDO::FETCH_COLUMN);
    // Sertakan produk coming_soon: tampil di katalog dgn badge + CTA waitlist
    // (checkout tetap tertolak oleh guard status='active' di action checkout).
    // hide_from_catalog = produk "unlisted": disembunyikan dari etalase ini
    // (yang juga jadi homepage '/'), tapi TETAP bisa dibeli via link langsung
    // /products/{slug}, checkout, atau sales page Canvas.
    $where  = ["p.status IN ('active','coming_soon')", 'p.hide_from_catalog = 0'];
    $params = [];

    if ($search !== '') {
        $where[]  = '(p.name LIKE ? OR p.short_description LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($typeFilter !== '' && in_array($typeFilter, $types, true)) {
        $where[]  = 'p.type = ?';
        $params[] = $typeFilter;
    }
    if ($categoryFilter !== '') {
        $where[] = 'p.catalog_category = ?';
        $params[] = $categoryFilter;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSQL");
    $cntStmt->execute($params);
    $totalCount = (int) $cntStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT p.*, cp.slug AS canvas_slug,
                (SELECT COUNT(*) FROM order_items oi
                 JOIN orders o ON oi.order_id = o.id
                 WHERE oi.product_id = p.id AND o.status = 'paid') AS sold_count
         FROM products p
         LEFT JOIN canvas_pages cp ON cp.id = p.canvas_id AND cp.is_active = 1
         $whereSQL
         ORDER BY p.sort_order ASC, p.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $products = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Public products page error: ' . $e->getMessage());
}

// Tampilkan elemen landing (hero penuh, spotlight) hanya di halaman utama murni.
$showLanding = ($search === '' && $typeFilter === '' && $categoryFilter === '' && $page === 1);

// ─── Pengaturan homepage (dapat diedit di admin Branding) ─────
$heroBadge     = getSetting('homepage_hero_badge', 'Platform produk & kelas digital');
$heroCta       = getSetting('homepage_hero_cta', 'Jelajahi Produk');
$footerTagline = getSetting('footer_tagline', 'Produk & kelas digital premium. Akses aktif setelah pembayaran dikonfirmasi.');
$showSpotlight = getSetting('homepage_show_spotlight', '1') === '1';
$showNewRelease = getSetting('homepage_show_new_release', '1') === '1';

// ─── Display Produk Affiliate (kartu rekomendasi, tautan keluar) ──
$showAffiliate  = getSetting('homepage_show_affiliate', '1') === '1';
$affiliateTitle = getSetting('homepage_affiliate_title', 'Rekomendasi Pilihan');
$affiliateBadge = getSetting('homepage_affiliate_badge_text', 'Afiliasi');
$affiliateItems = [];
if ($showLanding && $showAffiliate && isset($pdo)) {
    try {
        // Tabel bisa belum ada di instalasi yang migrasinya belum jalan → fail-safe
        // (try/catch): section cukup disembunyikan, tidak menggagalkan homepage.
        $affiliateItems = $pdo->query(
            "SELECT * FROM affiliate_displays WHERE status = 'active' ORDER BY sort_order ASC, id DESC"
        )->fetchAll();
    } catch (Exception $e) {
        error_log('Public affiliate displays error: ' . $e->getMessage());
    }
}

// ─── Lead Magnet di homepage (section "Gratis", opt-in per magnet) ──
$showLeadMagnet  = getSetting('homepage_show_leadmagnet', '1') === '1';
$leadMagnetTitle = getSetting('homepage_leadmagnet_title', 'Gratis Untukmu');
$leadMagnetItems = [];
if ($showLanding && $showLeadMagnet && isset($pdo)) {
    try {
        // Hanya lead magnet PUBLISHED + dicentang tampil. Fail-safe bila kolom/tabel
        // belum ada (instalasi belum migrasi) → section cukup disembunyikan.
        $leadMagnetItems = $pdo->query(
            "SELECT id, title, slug, headline, subheadline, thumbnail, badge_text, cta_text
             FROM lead_magnets
             WHERE status = 'published' AND show_on_homepage = 1
             ORDER BY created_at DESC"
        )->fetchAll();
    } catch (Exception $e) {
        error_log('Public lead magnet displays error: ' . $e->getMessage());
    }
}

// ─── Statistik hero (data real) ───────────────────────────────
$stat = ['members' => 0, 'products' => 0, 'sold' => 0];
if ($showLanding && isset($pdo)) {
    try {
        $stat['members']  = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
        // Ikut menyembunyikan produk unlisted agar angka statistik cocok dengan
        // jumlah produk yang benar-benar terlihat di katalog di bawahnya.
        $stat['products'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND hide_from_catalog = 0")->fetchColumn();
        $stat['sold']     = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();
    } catch (Exception $e) {
        error_log('Public products stats error: ' . $e->getMessage());
    }
}

// ─── Produk unggulan (terlaris) untuk spotlight ───────────────
$featured = null;
if ($showLanding && $showSpotlight && isset($pdo)) {
    try {
        $fst = $pdo->query(
            "SELECT p.*, cp.slug AS canvas_slug,
                    (SELECT COUNT(*) FROM order_items oi JOIN orders o ON oi.order_id = o.id
                     WHERE oi.product_id = p.id AND o.status = 'paid') AS sold_count
             FROM products p LEFT JOIN canvas_pages cp ON cp.id = p.canvas_id AND cp.is_active = 1
             WHERE p.status = 'active' AND p.hide_from_catalog = 0
             ORDER BY sold_count DESC, p.sort_order ASC, p.created_at DESC LIMIT 1"
        );
        $featured = $fst->fetch() ?: null;
    } catch (Exception $e) {
        error_log('Public products featured error: ' . $e->getMessage());
    }
}

// Produk aktif terbaru menurut tanggal dibuat. Coming Soon belum rilis.
$newRelease = null;
if ($showLanding && $showNewRelease && isset($pdo)) {
    try {
        $nst = $pdo->query(
            "SELECT p.*, cp.slug AS canvas_slug FROM products p
             LEFT JOIN canvas_pages cp ON cp.id = p.canvas_id AND cp.is_active = 1
             WHERE p.status = 'active' AND p.hide_from_catalog = 0
             ORDER BY p.created_at DESC, p.id DESC LIMIT 1"
        );
        $newRelease = $nst->fetch() ?: null;
    } catch (Exception $e) {
        error_log('Public products new release error: ' . $e->getMessage());
    }
}

$typeBadge = function(string $t): string {
    return match ($t) {
        'class'      => 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
        'membership' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
        'ebook'      => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        'software'   => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'jasa'       => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        'coaching'   => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
        'webinar'    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
        'template'   => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        default      => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
    };
};

$appName = getSetting('app_name', 'STIFIn Mulia');
$appLogo = getSetting('app_logo', '');
// Warna solid brand (tanpa gradien) — dipakai tombol, badge, & placeholder.
$brandGrad = 'background: var(--brand-color);';
?>
<?php $muliaPageStyle = true; include __DIR__ . '/../../partials/head.php'; ?>
<body class="mulia mulia-catalog min-h-screen flex flex-col bg-gray-50 dark:bg-gray-950 antialiased">
<style>
  /* Tombol CTA — solid brand + shadow brand + hover-lift, konsisten dengan tombol login. */
  .hp-cta{box-shadow:0 6px 16px -6px color-mix(in srgb,var(--brand-color) 60%,transparent);transition:transform .15s ease,box-shadow .15s ease,opacity .15s ease;}
  .hp-cta:hover{transform:translateY(-1px);box-shadow:0 10px 22px -8px color-mix(in srgb,var(--brand-color) 70%,transparent);}
  .hp-cta:active{transform:translateY(0);}
</style>

<!-- ─── Navbar (frosted, sticky) ──────────────────────────────── -->
<nav class="sticky top-0 z-40 bg-white/80 dark:bg-gray-900/80 backdrop-blur-lg border-b border-gray-100 dark:border-gray-800">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
    <a href="<?= e(APP_URL) ?>/" class="flex items-center gap-2 shrink-0">
      <?php if ($appLogo): ?>
      <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-8 object-contain">
      <?php else: ?>
      <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-sm font-bold shadow-sm" style="<?= $brandGrad ?>"><?= e(strtoupper(substr($appName, 0, 1))) ?></div>
      <?php endif; ?>
      <span class="font-bold text-gray-900 dark:text-white text-sm hidden sm:block"><?= e($appName) ?></span>
    </a>

    <form method="GET" action="<?= e(APP_URL) ?>/products" class="hidden md:flex flex-1 max-w-md mx-4">
      <div class="relative w-full">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari produk..."
               class="w-full pl-9 pr-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        <?php if ($typeFilter): ?><input type="hidden" name="type" value="<?= e($typeFilter) ?>"><?php endif; ?>
        <?php if ($categoryFilter): ?><input type="hidden" name="category" value="<?= e($categoryFilter) ?>"><?php endif; ?>
      </div>
    </form>

    <div class="flex items-center gap-2 shrink-0">
      <?php include __DIR__ . '/../../partials/dark-toggle.php'; ?>
      <?php if (isLoggedIn()): ?>
      <a href="<?= e(APP_URL) ?>/dashboard" class="hp-cta px-4 py-2 rounded-lg text-sm font-semibold text-white hover:opacity-95" style="<?= $brandGrad ?>">Dashboard</a>
      <?php else: ?>
      <a href="<?= e(APP_URL) ?>/login" class="px-3 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Masuk</a>
      <a href="<?= e(APP_URL) ?>/register" class="hp-cta px-4 py-2 rounded-lg text-sm font-semibold text-white hover:opacity-95" style="<?= $brandGrad ?>">Daftar</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<?php if ($showLanding): ?>
<!-- ─── Hero (soft) ───────────────────────────────────────────── -->
<section class="max-w-6xl mx-auto w-full px-4 sm:px-6 pt-8 sm:pt-10">
  <div class="hero-soft relative overflow-hidden rounded-2xl ring-1 ring-gray-200/70 dark:ring-gray-800 px-6 sm:px-12 py-12 sm:py-16 text-center">
    <div class="relative">
      <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-semibold mb-4 text-[var(--brand-color)]"
            style="background: color-mix(in srgb, var(--brand-color) 11%, transparent);">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.9 5.8H20l-4.9 3.6 1.9 5.8L12 14.6 7 18.2l1.9-5.8L4 8.8h6.1z"/></svg>
        <?= e($heroBadge) ?>
      </span>
      <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight mb-4 leading-tight text-gray-900 dark:text-white">
        <?= e(getSetting('homepage_headline', 'Belajar & bertumbuh, semua di satu tempat')) ?>
      </h1>
      <p class="text-sm sm:text-lg text-gray-500 dark:text-gray-300 max-w-xl mx-auto mb-7 leading-relaxed">
        <?= e(getSetting('homepage_subheadline', 'Akses kelas, produk digital, dan komunitas premium — checkout cepat tanpa ribet.')) ?>
      </p>
      <div class="flex flex-wrap items-center justify-center gap-3 mb-9">
        <a href="#produk" class="hp-cta inline-flex items-center gap-2 px-6 py-3 rounded-lg text-sm font-bold text-white hover:opacity-95" style="<?= $brandGrad ?>">
          <?= e($heroCta) ?>
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
        <a href="<?= e(APP_URL) ?>/<?= isLoggedIn() ? 'dashboard' : 'register' ?>" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
          <?= isLoggedIn() ? 'Ke Dashboard' : 'Buat Akun Gratis' ?>
        </a>
      </div>
      <div class="grid grid-cols-3 gap-3 max-w-md mx-auto">
        <?php
        $heroStats = [
            ['n' => $stat['members'],  'l' => 'Member'],
            ['n' => $stat['products'], 'l' => 'Produk'],
            ['n' => $stat['sold'],     'l' => 'Transaksi'],
        ];
        foreach ($heroStats as $hs): ?>
        <div class="rounded-xl bg-white/70 dark:bg-white/5 ring-1 ring-gray-200 dark:ring-gray-800 backdrop-blur px-3 py-3">
          <div class="text-xl sm:text-2xl font-extrabold text-gray-900 dark:text-white"><?= number_format((int) $hs['n']) ?><?= $hs['n'] >= 1 ? '+' : '' ?></div>
          <div class="text-[11px] sm:text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= e($hs['l']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="flex flex-wrap items-center justify-center gap-x-7 gap-y-2 mt-5 text-xs text-gray-500 dark:text-gray-400">
    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg> Pembayaran aman</span>
    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h7l-1 8 10-12h-7z"/></svg> Akses setelah konfirmasi</span>
    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Support responsif</span>
    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.5 12.5 17 22l-5-3-5 3 1.5-9.5"/></svg> Garansi akses</span>
  </div>
</section>

<?php
// Dua seksi memakai satu markup agar harga, CTA, dan tampilan selalu konsisten.
$examLabels = ['wsl-1' => 'WSL 1', 'wsl-2' => 'WSL 2', 'wst-health' => 'WST Health',
    'wst-learning' => 'WST Learning', 'wst-tahfizh' => 'WST Tahfizh'];
$spotlights = [];
if ($featured) $spotlights[] = ['product' => $featured, 'title' => 'Produk Unggulan', 'badge' => (int) $featured['sold_count'] > 0 ? 'Terlaris' : '', 'is_new' => false];
if ($newRelease) $spotlights[] = ['product' => $newRelease, 'title' => 'Produk Baru Rilis', 'badge' => 'Baru Rilis', 'is_new' => true];
foreach ($spotlights as $spotlight):
  $spotlightProduct = $spotlight['product'];
  $spotlightNew = $spotlight['is_new'];
  $examLabel = $examLabels[$spotlightProduct['slug']] ?? '';
  $spotlightPath = '/products/' . rawurlencode($spotlightProduct['slug']);
  if ($examLabel !== '' && ($spotlightProduct['lp_type'] ?? '') === 'canvas' && !empty($spotlightProduct['canvas_slug'])) {
      $spotlightPath = '/' . rawurlencode($spotlightProduct['canvas_slug']) . '/';
  }
  $sPrice = (float) $spotlightProduct['price']; $sOrig = (float) $spotlightProduct['original_price'];
  if (!empty($spotlightProduct['dime_enabled']) && ($spotlightProduct['pricing_type'] ?? 'fixed') !== 'flexible') { $sPrice = dimeEffectivePrice($spotlightProduct); $sOrig = $sPrice; }
  $sFlex = ($spotlightProduct['pricing_type'] ?? 'fixed') === 'flexible';
  $sFlexMin = (float) ($spotlightProduct['flexible_min'] ?? 0);
  $sFlexLabel = trim((string) ($spotlightProduct['flexible_label'] ?? '')) ?: 'Bayar Seikhlasnya';
  $sCta = trim((string) ($spotlightProduct['cta_label'] ?? ''));
  $sHasDisc = ($sOrig > $sPrice) && !$sFlex; $sIsFree = $sPrice <= 0 && !$sFlex;
  $sMyr = ($spotlightProduct['price_myr'] !== null && (float) $spotlightProduct['price_myr'] > 0) ? (float) $spotlightProduct['price_myr'] : null;
  $sDisc = $sHasDisc ? round(100 - ($sPrice / $sOrig * 100)) : 0;
?>
<section class="max-w-5xl mx-auto w-full px-4 sm:px-6 pt-10">
  <div class="flex items-center gap-2 mb-4">
    <?php if ($spotlightNew): ?>
    <svg class="w-5 h-5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4m0 12v4M2 12h4m12 0h4M5 5l3 3m8 8 3 3M19 5l-3 3M8 16l-3 3"/></svg>
    <?php else: ?>
    <svg class="w-5 h-5 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l1.9 5.8H20l-4.9 3.6 1.9 5.8L12 14.6 7 18.2l1.9-5.8L4 8.8h6.1z"/></svg>
    <?php endif; ?>
    <h2 class="text-lg font-bold text-gray-900 dark:text-white"><?= e($spotlight['title']) ?></h2>
  </div>
  <a href="<?= e(rtrim(APP_URL, '/') . $spotlightPath) ?>"
     class="group grid md:grid-cols-2 gap-0 rounded-2xl overflow-hidden bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm hover:shadow-xl transition-all md:min-h-[300px] md:max-h-[340px]">
    <div class="relative aspect-[16/10] md:aspect-auto overflow-hidden bg-gray-100 dark:bg-gray-800">
      <?php if ($spotlightProduct['thumbnail']): ?>
      <img src="<?= e($spotlightProduct['thumbnail']) ?>" alt="<?= e($spotlightProduct['name']) ?>" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
      <?php elseif ($examLabel !== ''): ?>
      <div class="absolute inset-0 flex flex-col items-center justify-center text-white" style="background:linear-gradient(135deg,#123d30,#225e49)">
        <span class="text-xs font-semibold uppercase tracking-widest" style="color:#ddc89a">Biaya Ujian</span>
        <span class="mt-2 text-3xl font-bold"><?= e($examLabel) ?></span>
      </div>
      <?php else: ?>
      <div class="absolute inset-0 w-full h-full flex items-center justify-center" style="<?= $brandGrad ?>"><svg class="w-14 h-14 text-white/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/></svg></div>
      <?php endif; ?>
      <?php if ($spotlight['badge'] !== ''): ?>
      <span class="absolute top-3 left-3 inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold shadow <?= $spotlightNew ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400' : 'bg-amber-400 text-amber-900' ?>">
        <?php if ($spotlightNew): ?>
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4m0 12v4M2 12h4m12 0h4M5 5l3 3m8 8 3 3M19 5l-3 3M8 16l-3 3"/></svg>
        <?php else: ?>
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.4 7.4H22l-6 4.4 2.3 7.2L12 16.8 5.7 21l2.3-7.2-6-4.4h7.6z"/></svg>
        <?php endif; ?>
        <?= e($spotlight['badge']) ?>
      </span>
      <?php endif; ?>
    </div>
    <div class="p-6 sm:p-8 flex flex-col justify-center">
      <span class="inline-flex w-fit px-2 py-0.5 rounded-md text-xs font-medium mb-3 <?= $typeBadge($spotlightProduct['type']) ?>"><?= e(productCatalogLabel($spotlightProduct)) ?></span>
      <h3 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white mb-2 leading-snug"><?= e($spotlightProduct['name']) ?></h3>
      <?php if ($spotlightProduct['short_description']): ?>
      <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-5 line-clamp-3"><?= e($spotlightProduct['short_description']) ?></p>
      <?php endif; ?>
      <div class="flex items-center justify-between gap-4 mt-auto">
        <div>
          <?php if ($sIsFree): ?>
          <p class="text-2xl font-extrabold text-green-600 dark:text-green-400">Gratis</p>
          <?php elseif ($sFlex): ?>
          <p class="text-xl font-extrabold text-gray-900 dark:text-white"><?= e($sFlexLabel) ?></p>
          <?php if ($sFlexMin > 0): ?><p class="text-sm text-gray-500 dark:text-gray-400">mulai <?= e(formatRupiah($sFlexMin)) ?></p><?php endif; ?>
          <?php else: ?>
          <div class="flex items-baseline gap-2">
            <p class="text-2xl font-extrabold text-gray-900 dark:text-white"><?= e(formatRupiah($sPrice)) ?></p>
            <?php if ($sHasDisc): ?><span class="text-sm text-gray-400 line-through"><?= e(formatRupiah($sOrig)) ?></span><?php endif; ?>
            <?php if ($sMyr !== null): ?><div class="mt-1.5"><?= ringgitBadge($sMyr) ?></div><?php endif; ?>
          </div>
          <?php if ($sHasDisc && $sDisc >= 5): ?><span class="text-xs font-bold text-red-500">Hemat <?= $sDisc ?>%</span><?php endif; ?>
          <?php endif; ?>
        </div>
        <span class="hp-cta inline-flex items-center gap-2 px-6 py-3 rounded-lg text-sm font-bold text-white group-hover:opacity-95 shrink-0" style="<?= $brandGrad ?>">
          <?= $sCta !== '' ? e($sCta) : ($sIsFree ? 'Ambil Gratis' : 'Beli Sekarang') ?>
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </span>
      </div>
    </div>
  </a>
</section>
<?php endforeach; ?>
<?php endif; ?>

<!-- ─── Paket Bundle ──────────────────────────────────────────── -->
<?php
$activeBundles = [];
try {
    $activeBundles = getDB()->query(
        "SELECT pb.*, (SELECT COUNT(*) FROM product_bundle_items WHERE bundle_id = pb.id) AS item_count
         FROM product_bundles pb WHERE pb.status = 'active' AND pb.hide_from_catalog = 0 ORDER BY pb.sort_order ASC, pb.created_at DESC"
    )->fetchAll();
} catch (Exception $e) { $activeBundles = []; }
if (!empty($activeBundles)):
?>
<section class="max-w-6xl mx-auto w-full px-4 sm:px-6 pt-6">
  <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">🎁 Paket Bundle — Lebih Hemat</h2>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($activeBundles as $bnd):
      $hemat = (float)$bnd['original_price'] > 0 ? round(((float)$bnd['original_price'] - (float)$bnd['bundle_price'])/(float)$bnd['original_price']*100) : 0;
    ?>
    <a href="<?= e(APP_URL) ?>/bundles/<?= e($bnd['slug']) ?>" class="group block bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 hover:shadow-md transition overflow-hidden">
      <?php if ($bnd['thumbnail']): ?>
      <img src="<?= e($bnd['thumbnail']) ?>" class="w-full h-36 object-cover" alt="<?= e($bnd['name']) ?>">
      <?php else: ?>
      <div class="w-full h-36 bg-gradient-to-br from-[var(--brand-color)]/20 to-[var(--brand-color)]/5 flex items-center justify-center"><svg class="w-10 h-10 text-[var(--brand-color)]/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg></div>
      <?php endif; ?>
      <div class="p-4">
        <?php if ($hemat > 0): ?><span class="inline-block mb-2 text-xs font-bold px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-full">Hemat <?= $hemat ?>%</span><?php endif; ?>
        <h3 class="font-semibold text-gray-900 dark:text-white group-hover:text-[var(--brand-color)] transition"><?= e($bnd['name']) ?></h3>
        <?php if ($bnd['tagline']): ?><p class="text-xs text-gray-400 mt-0.5 truncate"><?= e($bnd['tagline']) ?></p><?php endif; ?>
        <p class="text-xs text-gray-400 mt-1"><?= (int)$bnd['item_count'] ?> produk</p>
        <div class="mt-3 flex items-baseline gap-2">
          <span class="font-bold text-[var(--brand-color)]"><?= e(formatRupiah((float)$bnd['bundle_price'])) ?></span>
          <?php if ((float)$bnd['original_price'] > (float)$bnd['bundle_price']): ?><span class="text-xs text-gray-400 line-through"><?= e(formatRupiah((float)$bnd['original_price'])) ?></span><?php endif; ?>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ─── Katalog Produk ────────────────────────────────────────── -->
<div id="produk" class="flex-1 w-full max-w-6xl mx-auto px-4 sm:px-6 py-10 scroll-mt-20">

  <?php if ($showLanding): ?>
  <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Semua Produk</h2>
  <?php endif; ?>

  <!-- Filter produk dalam satu baris -->
  <div class="flex items-center gap-2 overflow-x-auto pb-1 mb-6 scrollbar-hide">
    <?php
    // Hanya chip tipe yang punya produk (jangan tampilkan kategori kosong).
    // array_intersect mempertahankan urutan kanonik $types. "Semua" selalu ada.
    $baseTypes   = $catalogCategories ? array_diff($types, ['other']) : $types;
    $filterTypes = array_values(array_intersect($baseTypes, $presentTypes));
    $allTypes = ['' => 'Semua'] + array_combine($filterTypes, array_map('ucfirst', $filterTypes));
    foreach ($allTypes as $val => $label):
      $active = $categoryFilter === '' && $typeFilter === $val;
      $href   = APP_URL . '/products?' . http_build_query(array_filter(['q' => $search, 'type' => $val]));
    ?>
    <a href="<?= e($href) ?>"
       class="shrink-0 px-4 py-1.5 rounded-lg text-sm font-medium transition-all <?= $active
         ? 'text-white shadow-sm'
         : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)]' ?>"
       <?= $active ? 'style="' . $brandGrad . '"' : '' ?>>
      <?= e($label) ?>
    </a>
    <?php endforeach; ?>
    <?php foreach ($catalogCategories as $category):
      $active = $categoryFilter === $category;
      $href = APP_URL . '/products?' . http_build_query(array_filter(['q' => $search, 'category' => $category]));
    ?>
    <a href="<?= e($href) ?>" class="shrink-0 px-4 py-1.5 rounded-lg text-sm font-medium transition-all <?= $active
      ? 'text-white shadow-sm'
      : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)]' ?>"
      <?= $active ? 'style="' . $brandGrad . '"' : '' ?>><?= e($category) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Results Meta -->
  <div class="flex items-center justify-between mb-5">
    <p class="text-sm text-gray-500 dark:text-gray-400">
      <?php if ($search || $typeFilter || $categoryFilter): ?>
      <?= number_format($totalCount) ?> hasil ditemukan<?php if ($search): ?> untuk "<strong class="text-gray-800 dark:text-gray-200"><?= e($search) ?></strong>"<?php endif; ?>
      <?php else: ?>
      <?= number_format($totalCount) ?> produk tersedia
      <?php endif; ?>
    </p>
    <?php if ($search || $typeFilter || $categoryFilter): ?>
    <a href="<?= e(APP_URL) ?>/products" class="text-xs text-gray-400 hover:text-[var(--brand-color)] transition">Reset filter</a>
    <?php endif; ?>
  </div>

  <!-- Product Grid -->
  <?php if (empty($products)): ?>
  <div class="py-20 text-center">
    <div class="w-16 h-16 mx-auto rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4">
      <svg class="w-8 h-8 text-gray-400 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    </div>
    <h3 class="font-semibold text-gray-700 dark:text-gray-300">Produk tidak ditemukan</h3>
    <p class="text-sm text-gray-400 dark:text-gray-600 mt-1">Coba kata kunci atau filter yang berbeda.</p>
    <a href="<?= e(APP_URL) ?>/products" class="mt-4 inline-block text-sm text-[var(--brand-color)] hover:underline">Lihat semua produk →</a>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
    <?php foreach ($products as $product):
      if ($featured && (int) $product['id'] === (int) $featured['id']) continue;
      $price     = (float) $product['price'];
      $origPrice = (float) $product['original_price'];
      if (!empty($product['dime_enabled']) && ($product['pricing_type'] ?? 'fixed') !== 'flexible') {
          $price = dimeEffectivePrice($product); $origPrice = $price; // dime: harga efektif, tanpa coret
      }
      $gFlex     = ($product['pricing_type'] ?? 'fixed') === 'flexible';
      $hasDisc   = $origPrice > $price && !$gFlex;
      $gFlexMin  = (float) ($product['flexible_min'] ?? 0);
      $gFlexLabel = trim((string) ($product['flexible_label'] ?? '')) ?: 'Bayar Seikhlasnya';
      $gCta      = trim((string) ($product['cta_label'] ?? ''));
      $isFree    = $price <= 0 && !$gFlex;
      $discPct   = $hasDisc ? round(100 - ($price / $origPrice * 100)) : 0;
      $userHasAccess = isLoggedIn() && hasAccess((int)($_SESSION['user_id'] ?? 0), (int)$product['id']);

      // ── Sales page opsional (Pola C) ──────────────────────────────
      // Tombol sekunder "Sales Page" hanya muncul bila produk memang
      // punya landing khusus: lp_type='canvas' (halaman Canvas /{slug}/)
      // atau 'external' (URL luar). lp_type='none' → tombol disembunyikan.
      $salesUrl      = '';
      $salesExternal = false;
      $lpType        = $product['lp_type'] ?? 'none';
      if ($lpType === 'canvas' && !empty($product['canvas_slug'])) {
          $salesUrl = rtrim(APP_URL, '/') . '/' . rawurlencode($product['canvas_slug']) . '/';
      } elseif ($lpType === 'external' && !empty($product['landing_page_url'])) {
          $salesUrl      = $product['landing_page_url'];
          $salesExternal = true;
      }
    ?>
    <div class="group relative bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-[color-mix(in_srgb,var(--brand-color)_45%,transparent)] transition-all duration-300 overflow-hidden flex flex-col">

      <div class="relative overflow-hidden aspect-[4/3]">
        <?php if ($product['thumbnail']): ?>
        <img src="<?= e($product['thumbnail']) ?>" alt="<?= e($product['name']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
        <?php elseif (isset($examLabels[$product['slug']])): ?>
        <div class="w-full h-full flex items-center justify-center text-white text-2xl font-bold" style="background:linear-gradient(135deg,#123d30,#225e49)">
          <?= e($examLabels[$product['slug']]) ?>
        </div>
        <?php else: ?>
        <div class="w-full h-full flex items-center justify-center" style="<?= $brandGrad ?>"><svg class="w-10 h-10 text-white/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/></svg></div>
        <?php endif; ?>

        <div class="absolute top-2 left-2 flex flex-col gap-1">
          <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium <?= $typeBadge($product['type']) ?>"><?= e(productCatalogLabel($product)) ?></span>
          <?php if ($product['status'] === 'coming_soon'): ?>
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold bg-indigo-600 text-white">
            <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Coming Soon
          </span>
          <?php elseif ($hasDisc && $discPct >= 5): ?>
          <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-bold bg-red-500 text-white">-<?= $discPct ?>%</span>
          <?php endif; ?>
        </div>

        <?php if ($userHasAccess): ?>
        <div class="absolute top-2 right-2">
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium bg-green-500 text-white">
            <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Dimiliki
          </span>
        </div>
        <?php endif; ?>
      </div>

      <div class="p-4 flex-1 flex flex-col">
        <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-snug mb-1 line-clamp-2"><?= e($product['name']) ?></h3>
        <?php if ($product['short_description']): ?>
        <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-2 mb-3 flex-1"><?= e($product['short_description']) ?></p>
        <?php else: ?>
        <div class="flex-1"></div>
        <?php endif; ?>

        <div class="flex items-end justify-between mt-2">
          <div>
            <?php if ($isFree): ?>
            <p class="text-base font-bold text-green-600 dark:text-green-400">Gratis</p>
            <?php elseif ($gFlex): ?>
            <p class="text-sm font-bold text-gray-900 dark:text-white leading-tight"><?= e($gFlexLabel) ?><?php if ($gFlexMin > 0): ?> <span class="text-gray-500 dark:text-gray-400 font-normal">· mulai <?= e(formatRupiah($gFlexMin)) ?></span><?php endif; ?></p>
            <?php else: ?>
            <p class="text-base font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($price)) ?></p>
            <?php if ($hasDisc): ?><p class="text-xs text-gray-400 line-through"><?= e(formatRupiah($origPrice)) ?></p><?php endif; ?>
            <?php $gMyr = ($product['price_myr'] !== null && (float)$product['price_myr'] > 0) ? (float)$product['price_myr'] : null; ?>
            <?php if ($gMyr !== null): ?><div class="mt-1"><?= ringgitBadge($gMyr) ?></div><?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ((int)$product['sold_count'] > 0 && getSetting('show_sold_count', '1') === '1'): ?>
          <p class="text-xs text-gray-400 dark:text-gray-600"><?= number_format((int)$product['sold_count']) ?> terjual</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="px-4 pb-4">
        <?php if ($salesUrl !== ''): ?>
        <?php /* Tombol Sales Page (outline + ikon) DI ATAS tombol beli; z-20 agar bisa diklik di atas link kartu (stretched z-10). Selalu buka tab baru. */ ?>
        <a href="<?= e($salesUrl) ?>" target="_blank" rel="noopener<?= $salesExternal ? ' nofollow' : '' ?>"
           class="relative z-20 w-full mb-2 inline-flex items-center justify-center gap-1 py-2 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition"
           aria-label="Lihat sales page <?= e($product['name']) ?>">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>
          Sales Page
        </a>
        <?php endif; ?>
        <?php if ($userHasAccess): ?>
        <div class="w-full py-2 rounded-lg text-xs font-semibold text-center bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800/40">Akses Produk →</div>
        <?php else: ?>
        <div class="hp-cta w-full py-2 rounded-lg text-xs font-semibold text-center text-white group-hover:opacity-95" style="<?= $brandGrad ?>"><?= $gCta !== '' ? e($gCta) : ($isFree ? 'Ambil Gratis' : 'Beli Sekarang') ?></div>
        <?php endif; ?>
      </div>

      <?php /* Link utama kartu (stretched): menutupi seluruh kartu. Tombol Sales Page di footer memakai z-20 agar tetap bisa diklik di atas link ini. */ ?>
      <a href="<?= e(APP_URL) ?>/products/<?= e($product['slug']) ?>" class="absolute inset-0 z-10" aria-label="Lihat <?= e($product['name']) ?>"></a>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <?php
  $buildUrl = function(int $p) use ($search, $typeFilter, $categoryFilter): string {
      $q = http_build_query(array_filter(['q' => $search ?: null, 'type' => $typeFilter ?: null, 'category' => $categoryFilter ?: null, 'page' => $p > 1 ? $p : null]));
      return APP_URL . '/products' . ($q ? '?' . $q : '');
  };
  ?>
  <div class="flex items-center justify-center gap-2 mt-10">
    <?php if ($page > 1): ?>
    <a href="<?= e($buildUrl($page - 1)) ?>" class="px-4 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-900 transition">← Prev</a>
    <?php endif; ?>
    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
    <a href="<?= e($buildUrl($p)) ?>"
       class="px-4 py-2 text-sm rounded-lg border transition font-medium <?= $p === $page ? 'border-[var(--brand-color)] text-white' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-900' ?>"
       <?= $p === $page ? 'style="' . $brandGrad . '"' : '' ?>>
      <?= $p ?>
    </a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a href="<?= e($buildUrl($page + 1)) ?>" class="px-4 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-900 transition">Next →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php /* ─── Section: Lead Magnet Gratis (opt-in, tautan internal /lp/{slug}) ─── */ ?>
  <?php if ($showLanding && !empty($leadMagnetItems)): ?>
  <section class="mt-10">
    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-5"><?= e($leadMagnetTitle) ?></h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
      <?php foreach ($leadMagnetItems as $lm):
        $lmUrl   = rtrim(APP_URL, '/') . '/lp/' . rawurlencode((string) $lm['slug']);
        $lmThumb = trim((string) ($lm['thumbnail'] ?? ''));
        if ($lmThumb !== '' && !preg_match('#^https?://#i', $lmThumb)) {
            $lmThumb = rtrim(APP_URL, '/') . '/' . ltrim($lmThumb, '/');
        }
      ?>
      <div class="group relative bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-[color-mix(in_srgb,var(--brand-color)_45%,transparent)] transition-all duration-300 overflow-hidden flex flex-col">
        <div class="relative overflow-hidden aspect-[4/3]">
          <?php if ($lmThumb !== ''): ?>
          <img src="<?= e($lmThumb) ?>" alt="<?= e($lm['title']) ?>" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
          <?php else: ?>
          <div class="w-full h-full flex items-center justify-center" style="<?= $brandGrad ?>"><svg class="w-10 h-10 text-white/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></div>
          <?php endif; ?>
          <div class="absolute top-2 left-2 flex flex-col gap-1 items-start">
            <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-bold text-white" style="background:#16a34a">Gratis</span>
            <?php if (!empty($lm['badge_text'])): ?>
            <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-bold text-white" style="background:var(--brand-color)"><?= e($lm['badge_text']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="p-4 flex-1 flex flex-col">
          <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-snug mb-1 line-clamp-2"><?= e($lm['headline'] ?: $lm['title']) ?></h3>
          <?php if (!empty($lm['subheadline'])): ?>
          <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-2 mb-3 flex-1"><?= e($lm['subheadline']) ?></p>
          <?php else: ?>
          <div class="flex-1"></div>
          <?php endif; ?>
        </div>
        <div class="px-4 pb-4">
          <div class="hp-cta w-full py-2 rounded-lg text-xs font-semibold text-center text-white group-hover:opacity-95" style="<?= $brandGrad ?>"><?= e($lm['cta_text'] ?: 'Dapatkan Gratis') ?></div>
        </div>
        <a href="<?= e($lmUrl) ?>" class="absolute inset-0 z-10" aria-label="<?= e($lm['title']) ?>"></a>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php /* ─── Section: Display Produk Affiliate (rekomendasi, tautan keluar) ─── */ ?>
  <?php if ($showLanding && !empty($affiliateItems)): ?>
  <section class="mt-10">
    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-5"><?= e($affiliateTitle) ?></h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
      <?php foreach ($affiliateItems as $aff): ?>
      <div class="group relative bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-[color-mix(in_srgb,var(--brand-color)_45%,transparent)] transition-all duration-300 overflow-hidden flex flex-col">
        <div class="relative overflow-hidden aspect-[4/3]">
          <?php if (!empty($aff['image'])): ?>
          <img src="<?= e($aff['image']) ?>" alt="<?= e($aff['name']) ?>" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
          <?php else: ?>
          <div class="w-full h-full flex items-center justify-center" style="<?= $brandGrad ?>"><svg class="w-10 h-10 text-white/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/></svg></div>
          <?php endif; ?>
          <div class="absolute top-2 left-2 flex flex-col gap-1 items-start">
            <?php if (trim((string) $affiliateBadge) !== ''): ?>
            <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-semibold text-white backdrop-blur" style="background:rgba(0,0,0,0.6)"><?= e($affiliateBadge) ?></span>
            <?php endif; ?>
            <?php if (!empty($aff['badge'])): ?>
            <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-bold text-white" style="background:var(--brand-color)"><?= e($aff['badge']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="p-4 flex-1 flex flex-col">
          <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-snug mb-1 line-clamp-2"><?= e($aff['name']) ?></h3>
          <?php if (!empty($aff['short_desc'])): ?>
          <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-2 mb-3 flex-1"><?= e($aff['short_desc']) ?></p>
          <?php else: ?>
          <div class="flex-1"></div>
          <?php endif; ?>
          <?php if (!empty($aff['price_text'])): ?>
          <p class="text-base font-bold text-gray-900 dark:text-white mt-2"><?= e($aff['price_text']) ?></p>
          <?php endif; ?>
        </div>
        <div class="px-4 pb-4 flex gap-2">
          <a href="<?= e($aff['salespage_url']) ?>" target="_blank" rel="sponsored noopener nofollow"
             class="hp-cta flex-1 py-2 rounded-lg text-xs font-semibold text-center text-white group-hover:opacity-95" style="<?= $brandGrad ?>">Lihat Produk</a>
          <?php if (!empty($aff['checkout_url'])): ?>
          <a href="<?= e($aff['checkout_url']) ?>" target="_blank" rel="sponsored noopener nofollow"
             class="py-2 px-3 rounded-lg text-xs font-semibold text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition">Beli</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</div>

<!-- ─── Footer ────────────────────────────────────────────────── -->
<footer class="border-t border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 mt-8">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6">
      <div class="max-w-xs">
        <div class="flex items-center gap-2 mb-2">
          <?php if ($appLogo): ?>
          <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-7 object-contain">
          <?php else: ?>
          <div class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold" style="<?= $brandGrad ?>"><?= e(strtoupper(substr($appName, 0, 1))) ?></div>
          <?php endif; ?>
          <span class="font-bold text-gray-900 dark:text-white text-sm"><?= e($appName) ?></span>
        </div>
        <?php if (trim($footerTagline) !== ''): ?>
        <p class="text-xs text-gray-400 dark:text-gray-500 leading-relaxed"><?= e($footerTagline) ?></p>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-6 text-xs text-gray-500 dark:text-gray-400">
        <a href="<?= e(APP_URL) ?>/products" class="hover:text-[var(--brand-color)] transition">Produk</a>
        <?php if (isLoggedIn()): ?>
        <a href="<?= e(APP_URL) ?>/dashboard" class="hover:text-[var(--brand-color)] transition">Dashboard</a>
        <button type="button" onclick="document.getElementById('logout-form').submit()" class="hover:text-[var(--brand-color)] transition">Logout</button>
        <?php else: ?>
        <a href="<?= e(APP_URL) ?>/login" class="hover:text-[var(--brand-color)] transition">Masuk</a>
        <a href="<?= e(APP_URL) ?>/register" class="hover:text-[var(--brand-color)] transition">Daftar</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="mt-8 pt-5 border-t border-gray-100 dark:border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-2">
      <p class="text-xs text-gray-400 dark:text-gray-600">
        <?php $footerText = getSetting('footer_text', ''); ?>
        <?= e($footerText !== '' ? str_replace('{year}', date('Y'), $footerText) : '© ' . date('Y') . ' ' . $appName . '. All rights reserved.') ?>
      </p>
      <span class="inline-flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-600">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Aman &amp; terenkripsi
      </span>
    </div>
  </div>
</footer>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
