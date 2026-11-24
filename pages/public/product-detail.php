<?php
require_once __DIR__ . '/../../bootstrap.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['slug'] ?? '')));
if ($slug === '') {
    redirect('/products');
}

// Simpan referral code dari ?ref= query param
$ref = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_GET['ref'] ?? '')));
if ($ref !== '' && isLoggedIn() === false) {
    $_SESSION['ref_code'] = $ref;
}

$product  = null;
$classes  = [];
$classCnt = 0;
$matCnt   = 0;

try {
    $pdo  = getDB();
    // coming_soon ikut tampil (CTA berganti form waitlist, checkout tetap tertolak guard).
    $stmt = $pdo->prepare("SELECT * FROM products WHERE slug = ? AND status IN ('active','coming_soon') LIMIT 1");
    $stmt->execute([$slug]);
    $product = $stmt->fetch();

    if (!$product) {
        redirect('/products');
    }

    // Modul count + material count untuk display
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM classes WHERE product_id = ? AND status = 'active'"
    );
    $stmt->execute([$product['id']]);
    $classCnt = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM materials m
         JOIN classes c ON m.class_id = c.id
         WHERE c.product_id = ? AND c.status = 'active'"
    );
    $stmt->execute([$product['id']]);
    $matCnt = (int) $stmt->fetchColumn();

    // Load kelas untuk syllabus preview (max 5 tampil)
    $stmt = $pdo->prepare(
        "SELECT c.id, c.title, c.description,
                COUNT(m.id) AS material_count
         FROM classes c
         LEFT JOIN materials m ON m.class_id = c.id
         WHERE c.product_id = ? AND c.status = 'active'
         GROUP BY c.id, c.title, c.description, c.sort_order
         ORDER BY c.sort_order ASC, c.id ASC
         LIMIT 10"
    );
    $stmt->execute([$product['id']]);
    $classes = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Product detail error: ' . $e->getMessage());
    redirect('/products');
}

// ─── Funnel tracking: lp_view (hanya untuk LP internal) ──────
// Produk dengan landing_page_url akan di-track di affiliate-landing-redirect.php
// supaya tidak dobel-hitung antara jalur internal dan eksternal.
// Admin/staff & bot di-exclude — kunjungan sendiri/non-manusia jangan menggelembungkan funnel.
if (empty($product['landing_page_url']) && !isAdminOrStaff()
    && !isBotUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    try {
        $pdo = getDB();
        $sid = session_id();
        $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $checkStmt = $pdo->prepare(
            "SELECT 1 FROM funnel_events
             WHERE product_id = ? AND event_type = 'lp_view' AND session_id = ?
             AND DATE(created_at) = CURDATE() LIMIT 1"
        );
        $checkStmt->execute([(int)$product['id'], $sid]);
        if (!$checkStmt->fetch()) {
            $pdo->prepare(
                "INSERT INTO funnel_events (product_id, event_type, session_id, user_agent) VALUES (?, 'lp_view', ?, ?)"
            )->execute([(int)$product['id'], $sid, $ua]);
        }
    } catch (Exception $ignored) {}
}

$price       = (float) $product['price'];
$origPrice   = (float) $product['original_price'];
// Bayar Fleksibel: harga dasar 0 tapi BUKAN gratis (pembeli tentukan nominal >= flexible_min).
$isFlexible    = ($product['pricing_type'] ?? 'fixed') === 'flexible';
$hasDisc     = $origPrice > $price && !$isFlexible;
$flexMinDisp   = (float) ($product['flexible_min'] ?? 0);
$flexLabelDisp = trim((string) ($product['flexible_label'] ?? '')) ?: 'Bayar Seikhlasnya';
$ctaCustom     = trim((string) ($product['cta_label'] ?? ''));
$isFree      = $price <= 0 && !$isFlexible;
$discPct     = $hasDisc ? round(100 - ($price / $origPrice * 100)) : 0;

// Meta CAPI corong: ViewContent (server-side, sinkron dgn lp_view di atas).
// Di-antre → dikirim asinkron via auto-cron (nol dampak page-load). Opt-in.
if (empty($product['landing_page_url']) && !isAdminOrStaff()) {
    require_once __DIR__ . '/../../tracking/PixelHelper.php';
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off');
    PixelHelper::trackFunnelServerSide('view', (int) $product['id'], [
        'value'      => $price,
        'source_url' => ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
    ]);
}
// Dime Sale: harga efektif saat ini (tanpa harga coret) + info urgensi.
$isDime      = !empty($product['dime_enabled']) && ($product['pricing_type'] ?? 'fixed') !== 'flexible';
$dimeInfo    = null;
if ($isDime) {
    $price = dimeEffectivePrice($product); $origPrice = $price; $hasDisc = false; $discPct = 0;
    $isFree = $price <= 0;
    $dimeInfo = dimeInfo($product);
}
// Pasar Malaysia (display-only): alias RM opsional + label bahasa produk.
$pMyr        = ($product['price_myr'] !== null && (float) $product['price_myr'] > 0) ? (float) $product['price_myr'] : null;
$plabels     = getProductLabels($product['language'] ?? 'id');
$userHasAccess = isLoggedIn() && hasAccess((int)($_SESSION['user_id'] ?? 0), (int)$product['id']);

$pageTitle = $product['name'] . ' — ' . getSetting('app_name', 'STIFIn Mulia');
$appName   = getSetting('app_name', 'STIFIn Mulia');

$typeBadge = match($product['type']) {
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

// Konten sadar-kategori (label badge, "Yang kamu dapat", "Isi Paket").
$pres = productPresentation($product, $classCnt, $matCnt);

// Tujuan checkout produk ini (dipakai tombol auth + sticky bar mobile).
$ppBasePath   = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/');
$checkoutPath = $ppBasePath . '/checkout?product_id=' . (int) $product['id'];
$authRedirect = rawurlencode($checkoutPath);

// Coming soon: CTA berganti ajakan daftar waitlist (form di kartu kanan, #waitlist).
$isComingSoon = ($product['status'] === 'coming_soon');
$waitlisted   = isset($_GET['waitlisted']);
$wlFlash      = getFlash('waitlist_form');

// CTA utama tunggal (untuk sticky bar mobile) — cermin dari cabang kartu kanan.
if ($isComingSoon) {
    $ctaHref = '#waitlist';                                       $ctaLabel = 'Daftar Waitlist';
} elseif ($userHasAccess) {
    $ctaHref = APP_URL . '/member/' . (int) $product['id'];       $ctaLabel = 'Buka Konten';
} elseif (isLoggedIn()) {
    $ctaHref = APP_URL . '/checkout?product_id=' . (int) $product['id']; $ctaLabel = $ctaCustom !== '' ? $ctaCustom : ($isFree ? 'Ambil Gratis' : $plabels['buy_now']);
} elseif (($product['checkout_mode'] ?? 'login') === 'public') {
    $ctaHref = APP_URL . '/order/' . $product['slug'];  $ctaLabel = $ctaCustom !== '' ? $ctaCustom : ($isFree ? 'Ambil Gratis' : $plabels['buy_now']);
} else {
    $ctaHref = APP_URL . '/register?redirect=' . $authRedirect;   $ctaLabel = $isFree ? 'Daftar & Ambil' : 'Daftar & Beli';
}
?>
<?php $muliaPageStyle = true; include __DIR__ . '/../../partials/head.php'; ?>
<body class="mulia mulia-detail min-h-screen flex flex-col bg-gray-50 dark:bg-gray-950 antialiased">

<!-- ─── Navbar ────────────────────────────────────────────────── -->
<nav class="bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 sticky top-0 z-40">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between gap-4">
    <a href="<?= e(APP_URL) ?>/products" class="flex items-center gap-2">
      <?php $logo = getSetting('app_logo'); ?>
      <?php if ($logo): ?>
      <img src="<?= e($logo) ?>" alt="<?= e($appName) ?>" class="h-7 object-contain">
      <?php else: ?>
      <div class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold"
           style="background-color: var(--brand-color);">
        <?= e(strtoupper(substr($appName, 0, 1))) ?>
      </div>
      <?php endif; ?>
      <span class="font-bold text-gray-900 dark:text-white text-sm hidden sm:block"><?= e($appName) ?></span>
    </a>
    <div class="flex items-center gap-2">
      <?php include __DIR__ . '/../../partials/dark-toggle.php'; ?>
      <?php if (isLoggedIn()): ?>
      <a href="<?= e(APP_URL) ?>/dashboard" class="px-3 py-1.5 rounded-lg text-sm font-medium text-white hover:opacity-90 transition" style="background-color:var(--brand-color);">Dashboard</a>
      <?php else: ?>
      <a href="<?= e(APP_URL) ?>/login" class="px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Masuk</a>
      <a href="<?= e(APP_URL) ?>/register" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-white hover:opacity-90 transition" style="background-color:var(--brand-color);">Daftar</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ─── Breadcrumb ────────────────────────────────────────────── -->
<div class="border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 py-2.5 flex items-center gap-2 text-xs text-gray-400 dark:text-gray-600">
    <a href="<?= e(APP_URL) ?>/products" class="hover:text-[var(--brand-color)] transition">Produk</a>
    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
    <span class="text-gray-600 dark:text-gray-400 truncate"><?= e($product['name']) ?></span>
  </div>
</div>

<!-- ─── Main Content ──────────────────────────────────────────── -->
<div class="flex-1 w-full max-w-5xl mx-auto px-4 sm:px-6 py-6 sm:py-8 pb-24 lg:pb-8">
  <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 lg:gap-8">

    <!-- ─── Detail (kiri 3/5) ─────────────────────────────────── -->
    <div class="lg:col-span-3 space-y-5">

      <!-- Thumbnail -->
      <?php if ($product['thumbnail']): ?>
      <div class="rounded-lg overflow-hidden ring-1 ring-black/5 dark:ring-white/10 shadow-md">
        <div class="aspect-video">
          <img src="<?= e($product['thumbnail']) ?>" alt="<?= e($product['name']) ?>"
               class="w-full h-full object-cover">
        </div>
      </div>
      <?php else: ?>
      <div class="rounded-lg overflow-hidden border border-gray-200 dark:border-gray-800 shadow-sm aspect-video flex items-center justify-center"
           style="background: linear-gradient(135deg, var(--brand-color) 0%, color-mix(in srgb, var(--brand-color) 60%, black) 100%)">
        <svg class="w-16 h-16 text-white/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
      </div>
      <?php endif; ?>

      <!-- Type + Title -->
      <div>
        <div class="flex items-center gap-2 mb-2">
          <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold <?= $typeBadge ?>"><?= e($pres['type_label']) ?></span>
          <?php if ($hasDisc && $discPct >= 5): ?>
          <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold bg-red-500 text-white">Diskon <?= $discPct ?>%</span>
          <?php endif; ?>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white leading-tight"><?= e($product['name']) ?></h1>
        <?php if ($product['short_description']): ?>
        <p class="text-gray-500 dark:text-gray-400 mt-2 leading-relaxed"><?= e($product['short_description']) ?></p>
        <?php endif; ?>
      </div>

      <!-- Stats chips -->
      <?php $chip = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-600 dark:text-gray-300'; ?>
      <div class="flex flex-wrap gap-2">
        <?php if ($classCnt > 0): ?>
        <span class="<?= $chip ?>">
          <svg class="w-3.5 h-3.5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
          </svg>
          <?= $classCnt ?> modul
        </span>
        <?php endif; ?>
        <?php if ($matCnt > 0): ?>
        <span class="<?= $chip ?>">
          <svg class="w-3.5 h-3.5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
          </svg>
          <?= $matCnt ?> materi
        </span>
        <?php endif; ?>
        <?php if ($product['access_duration_days']): ?>
        <span class="<?= $chip ?>">
          <svg class="w-3.5 h-3.5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
          Akses <?= (int)$product['access_duration_days'] ?> hari
        </span>
        <?php else: ?>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800/50 text-green-700 dark:text-green-400">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          Akses seumur hidup
        </span>
        <?php endif; ?>
        <?php if ($product['affiliate_commission'] > 0): ?>
        <span class="<?= $chip ?>">
          <svg class="w-3.5 h-3.5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
          </svg>
          Afiliasi <?= number_format((float)$product['affiliate_commission'], 1) ?>%
        </span>
        <?php endif; ?>
      </div>

      <!-- Deskripsi Lengkap -->
      <?php if ($product['description']): ?>
      <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6">
        <h2 class="flex items-center gap-2 font-bold text-gray-900 dark:text-white text-base mb-3">
          <span class="w-1 h-4 rounded-full" style="background-color:var(--brand-color);"></span>
          Tentang Produk
        </h2>
        <div class="prose prose-sm dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 leading-relaxed whitespace-pre-line">
          <?= e($product['description']) ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Kurikulum / Syllabus -->
      <?php if (!empty($classes)): ?>
      <div>
        <div class="flex items-center justify-between mb-3">
          <h2 class="flex items-center gap-2 font-bold text-gray-900 dark:text-white text-base">
            <span class="w-1 h-4 rounded-full" style="background-color:var(--brand-color);"></span>
            Kurikulum
          </h2>
          <span class="text-xs text-gray-400 dark:text-gray-500"><?= count($classes) ?> modul<?= $matCnt > 0 ? ' · ' . $matCnt . ' materi' : '' ?></span>
        </div>
        <div class="space-y-2">
          <?php foreach ($classes as $i => $cls): ?>
          <div class="group bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 p-3.5 flex items-start gap-3 hover:border-[color-mix(in_srgb,var(--brand-color)_45%,transparent)] hover:shadow-sm transition">
            <div class="w-7 h-7 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center text-xs font-bold text-[var(--brand-color)] shrink-0 mt-0.5">
              <?= $i + 1 ?>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($cls['title']) ?></p>
              <?php if ($cls['description']): ?>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 line-clamp-2"><?= e($cls['description']) ?></p>
              <?php endif; ?>
            </div>
            <?php if ((int)$cls['material_count'] > 0): ?>
            <span class="inline-flex items-center gap-1 text-xs text-gray-400 dark:text-gray-500 shrink-0 mt-0.5">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
              <?= (int)$cls['material_count'] ?>
            </span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Isi Paket (non-course, bila diisi & tak ada kurikulum) -->
      <?php if (empty($classes) && !empty($pres['includes'])): ?>
      <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5 sm:p-6">
        <h2 class="flex items-center gap-2 font-bold text-gray-900 dark:text-white text-base mb-3">
          <span class="w-1 h-4 rounded-full" style="background-color:var(--brand-color);"></span>
          Isi Paket
        </h2>
        <div class="space-y-2">
          <?php foreach ($pres['includes'] as $inc): ?>
          <div class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-300">
            <svg class="w-4 h-4 text-[var(--brand-color)] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <path d="M20 6 9 17l-5-5"/>
            </svg>
            <span><?= e($inc) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- ─── Purchase Card (kanan 2/5) ─────────────────────────── -->
    <div class="lg:col-span-2">
      <div class="sticky top-20">
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-lg overflow-hidden">

          <!-- Harga -->
          <div class="p-5 border-b border-gray-100 dark:border-gray-800">
            <?php if ($isFree): ?>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400">GRATIS</p>
            <?php elseif ($isFlexible): ?>
            <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= e($flexLabelDisp) ?></p>
            <?php if ($flexMinDisp > 0): ?><p class="text-sm text-gray-500 dark:text-gray-400 mt-1">mulai <?= e(formatRupiah($flexMinDisp)) ?></p><?php endif; ?>
            <?php else: ?>
            <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($price)) ?></p>
            <?php if ($pMyr !== null): ?><div class="mt-1.5"><?= ringgitBadge($pMyr) ?></div><?php endif; ?>
            <?php if ($dimeInfo && !$dimeInfo['at_cap']): ?>
            <p class="text-xs font-medium mt-2 inline-flex items-center gap-1.5" style="color: var(--brand-color);">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5" aria-hidden="true"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z" stroke-linejoin="round"/></svg>
              Harga naik <?= e(formatRupiah($dimeInfo['increment'])) ?> setelah <?= (int) $dimeInfo['remaining'] ?> pembelian lagi
            </p>
            <?php endif; ?>
            <?php if ($hasDisc): ?>
            <div class="flex items-center gap-2 mt-1">
              <p class="text-sm text-gray-400 line-through"><?= e(formatRupiah($origPrice)) ?></p>
              <span class="text-xs font-bold text-red-500">Hemat <?= $discPct ?>%</span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
          </div>

          <!-- CTA -->
          <?php
            // Tujuan checkout produk ini. Disematkan sbg ?redirect= pada tombol
            // Masuk/Daftar agar user yg belum login diantar balik ke checkout
            // setelah autentikasi (format path + base-path, konsisten REQUEST_URI).
            $ppBasePath   = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/');
            $checkoutPath = $ppBasePath . '/checkout?product_id=' . (int) $product['id'];
            $authRedirect = rawurlencode($checkoutPath);
          ?>
          <div class="p-5 space-y-3" id="waitlist">
            <?php if ($isComingSoon): ?>
            <!-- Coming Soon: form daftar waitlist (pengganti tombol beli) -->
            <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800/50">
              <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300">
                Segera dibuka<?= !empty($product['launch_at']) ? ' — ' . e(date('d M Y', strtotime($product['launch_at']))) : '' ?>
              </p>
            </div>

            <?php if ($waitlisted): ?>
            <div class="text-center py-4">
              <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              </div>
              <p class="text-sm font-bold text-gray-900 dark:text-white mb-1">Kamu sudah terdaftar!</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">Kami akan mengabari lewat email &amp; WhatsApp begitu produk ini dibuka.</p>
            </div>
            <?php else: ?>
            <?php if ($wlFlash && ($wlFlash['type'] ?? '') === 'error'): ?>
            <div class="px-3 py-2 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800/50 text-xs text-red-600 dark:text-red-400"><?= e($wlFlash['message']) ?></div>
            <?php endif; ?>
            <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">Daftar waitlist — jadi yang pertama tahu (plus penawaran terbaik) saat produk ini dibuka.</p>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/public/submit-waitlist" class="space-y-2.5">
              <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
              <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
              <input type="hidden" name="slug" value="<?= e($product['slug']) ?>">
              <?php if (!isLoggedIn()): ?>
              <input type="text" name="name" required maxlength="100" placeholder="Nama lengkap"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
              <input type="tel" name="whatsapp" required placeholder="Nomor WhatsApp (08xxx)" inputmode="numeric"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
              <input type="email" name="email" placeholder="Email (opsional)"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
              <?php else: ?>
              <p class="text-xs text-gray-400 dark:text-gray-500">Daftar memakai data akunmu yang sedang login.</p>
              <?php endif; ?>
              <button type="submit"
                      class="flex items-center justify-center gap-2 w-full py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
                      style="background-color: var(--brand-color);">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Daftar Waitlist
              </button>
            </form>
            <?php endif; ?>

            <?php elseif ($userHasAccess): ?>
            <a href="<?= e(APP_URL) ?>/member/<?= (int)$product['id'] ?>"
               class="flex items-center justify-center gap-2 w-full py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
               style="background-color: var(--brand-color);">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
              Buka Konten
            </a>
            <p class="text-center text-xs text-green-600 dark:text-green-400 font-medium">✓ Kamu sudah memiliki akses</p>

            <?php elseif (isLoggedIn()): ?>
            <a href="<?= e(APP_URL) ?>/checkout?product_id=<?= (int)$product['id'] ?>"
               class="flex items-center justify-center gap-2 w-full py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
               style="background-color: var(--brand-color);">
              <?= $ctaCustom !== '' ? e($ctaCustom) : ($isFree ? 'Ambil Gratis Sekarang' : 'Beli Sekarang') ?>
            </a>

            <?php elseif (($product['checkout_mode'] ?? 'login') === 'public'): ?>
            <!-- Mode publik: buyer langsung ke form order tanpa login -->
            <a href="<?= e(APP_URL) ?>/order/<?= e($product['slug']) ?>"
               class="flex items-center justify-center gap-2 w-full py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
               style="background-color: var(--brand-color);">
              <?= $ctaCustom !== '' ? e($ctaCustom) : ($isFree ? 'Ambil Gratis Sekarang' : 'Beli Sekarang') ?>
            </a>
            <a href="<?= e(APP_URL) ?>/login?redirect=<?= $authRedirect ?>"
               class="flex items-center justify-center w-full py-2.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
              Sudah punya akun? Masuk
            </a>

            <?php else: ?>
            <a href="<?= e(APP_URL) ?>/register?redirect=<?= $authRedirect ?>"
               class="flex items-center justify-center gap-2 w-full py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
               style="background-color: var(--brand-color);">
              Daftar untuk <?= $isFree ? 'Ambil Gratis' : 'Beli' ?>
            </a>
            <a href="<?= e(APP_URL) ?>/login?redirect=<?= $authRedirect ?>"
               class="flex items-center justify-center w-full py-2.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
              Sudah punya akun? Masuk
            </a>
            <?php endif; ?>

            <!-- Yang kamu dapat -->
            <div class="pt-4 border-t border-gray-100 dark:border-gray-800">
              <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2.5">Yang kamu dapat</p>
              <div class="space-y-2">
                <?php foreach ($pres['highlights'] as $g): ?>
                <div class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-300">
                  <svg class="w-4 h-4 text-[var(--brand-color)] shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M20 6 9 17l-5-5"/>
                  </svg>
                  <span><?= e($g) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Trust strip -->
            <div class="pt-4 border-t border-gray-100 dark:border-gray-800 grid grid-cols-3 gap-2">
              <?php
              $trust = [
                ['d' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',                    'l' => 'Transaksi aman'],
                ['d' => 'M13 2 3 14h9l-1 8 10-12h-9l1-8z',                                 'l' => 'Akses instan'],
                ['d' => 'M2 5h20v14H2z M2 10h20',                                          'l' => 'Bayar mudah'],
              ];
              foreach ($trust as $t):
              ?>
              <div class="flex flex-col items-center text-center gap-1">
                <svg class="w-4 h-4 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="<?= $t['d'] ?>"/>
                </svg>
                <span class="text-[10px] leading-tight text-gray-400 dark:text-gray-500"><?= $t['l'] ?></span>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Bagikan (compact) -->
            <?php $shareUrl = APP_URL . '/products/' . $product['slug']; ?>
            <div class="pt-4 border-t border-gray-100 dark:border-gray-800">
              <div class="flex items-center gap-2">
                <input type="text" value="<?= e($shareUrl) ?>" readonly aria-label="Link produk"
                       class="flex-1 min-w-0 px-2.5 py-1.5 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-mono truncate">
                <button onclick="copyToClipboard('<?= e($shareUrl) ?>', this, 'Tersalin!')"
                        class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                  Salin link
                </button>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>

<!-- ─── Sticky CTA bar (mobile) ───────────────────────────────── -->
<?php if (!$userHasAccess): ?>
<div class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 dark:bg-gray-900/95 backdrop-blur border-t border-gray-200 dark:border-gray-800 px-4 py-2.5 pb-[calc(0.625rem_+_env(safe-area-inset-bottom))] flex items-center gap-3 shadow-[0_-4px_16px_rgba(0,0,0,0.06)]">
  <div class="min-w-0">
    <?php if ($isFree): ?>
    <p class="text-base font-bold text-green-600 dark:text-green-400 leading-none">GRATIS</p>
    <?php elseif ($isFlexible): ?>
    <p class="text-sm font-bold text-gray-900 dark:text-white leading-tight"><?= e($flexLabelDisp) ?><?php if ($flexMinDisp > 0): ?> <span class="text-gray-500 dark:text-gray-400 font-normal">· mulai <?= e(formatRupiah($flexMinDisp)) ?></span><?php endif; ?></p>
    <?php else: ?>
    <p class="text-base font-bold text-gray-900 dark:text-white leading-none"><?= e(formatRupiah($price)) ?></p>
    <?php if ($hasDisc): ?><p class="text-[11px] text-gray-400 line-through leading-none mt-0.5"><?= e(formatRupiah($origPrice)) ?></p><?php endif; ?>
    <?php if ($pMyr !== null): ?><div class="mt-1"><?= ringgitBadge($pMyr) ?></div><?php endif; ?>
    <?php endif; ?>
  </div>
  <a href="<?= e($ctaHref) ?>"
     class="flex-1 text-center py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
     style="background-color: var(--brand-color);"><?= e($ctaLabel) ?></a>
</div>
<?php endif; ?>

<?php
// ─── Section Review ──────────────────────────────────────────────
$showReviews = getSetting('reviews_enabled', '1') === '1' && (int) ($product['reviews_enabled'] ?? 0) === 1;
if ($showReviews):
    $ratingSummary = getProductRatingSummary((int) $product['id']);
    $rppp = (int) getSetting('reviews_per_page', '10'); if ($rppp < 1) $rppp = 10;
    $approvedReviews = []; $userExistingReview = null;
    try {
        $rv = getDB()->prepare("SELECT r.rating, r.review_text, r.approved_at, CONCAT(SUBSTRING_INDEX(u.name,' ',1),' ',LEFT(SUBSTRING_INDEX(u.name,' ',-1),1),'.') AS display_name FROM product_reviews r JOIN users u ON u.id=r.user_id WHERE r.product_id=? AND r.status='approved' ORDER BY r.approved_at DESC LIMIT ?");
        $rv->bindValue(1, (int) $product['id'], PDO::PARAM_INT); $rv->bindValue(2, $rppp, PDO::PARAM_INT); $rv->execute();
        $approvedReviews = $rv->fetchAll();
    } catch (Exception $e) {}
    if (isLoggedIn()) {
        try { $ur = getDB()->prepare("SELECT rating, review_text, status FROM product_reviews WHERE user_id=? AND product_id=?"); $ur->execute([(int) $_SESSION['user_id'], (int) $product['id']]); $userExistingReview = $ur->fetch() ?: null; } catch (Exception $e) {}
    }
?>
<section id="ulasan" class="max-w-5xl mx-auto px-4 sm:px-6 mt-10 scroll-mt-6">
  <div class="border-t border-gray-200 dark:border-gray-800 pt-8">
    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-5">Ulasan Produk</h2>
    <div class="flex flex-col sm:flex-row gap-8 mb-8">
      <div class="shrink-0 text-center">
        <div class="text-5xl font-bold text-gray-900 dark:text-white"><?= $ratingSummary['total'] > 0 ? number_format($ratingSummary['average'], 1) : '—' ?></div>
        <div class="flex justify-center gap-0.5 my-2 text-yellow-400 text-lg"><?php for($i=1;$i<=5;$i++) echo $i<=round($ratingSummary['average'])?'★':'<span class="text-gray-300 dark:text-gray-600">★</span>'; ?></div>
        <div class="text-sm text-gray-400"><?= (int)$ratingSummary['total'] ?> ulasan</div>
      </div>
      <?php if ($ratingSummary['total'] > 0): ?>
      <div class="flex-1 space-y-1.5">
        <?php foreach ([5=>'five_star',4=>'four_star',3=>'three_star',2=>'two_star',1=>'one_star'] as $star=>$key): $cnt=(int)$ratingSummary[$key]; $pct=$ratingSummary['total']>0?round($cnt/$ratingSummary['total']*100):0; ?>
        <div class="flex items-center gap-2 text-sm"><span class="w-3 text-right text-gray-500"><?= $star ?></span><span class="text-yellow-400">★</span><div class="flex-1 bg-gray-200 dark:bg-gray-700 rounded-full h-2"><div class="bg-yellow-400 h-2 rounded-full" style="width:<?= $pct ?>%"></div></div><span class="w-8 text-xs text-gray-400"><?= $cnt ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if (isLoggedIn() && hasAccess((int)$_SESSION['user_id'], (int)$product['id'])): ?>
      <?php if ($userExistingReview && $userExistingReview['status'] === 'approved'): ?>
      <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg text-sm text-green-700 dark:text-green-400">Ulasanmu sudah tampil. Terima kasih!</div>
      <?php elseif ($userExistingReview && $userExistingReview['status'] === 'pending'): ?>
      <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg text-sm text-amber-700 dark:text-amber-400">Ulasanmu sedang menunggu persetujuan admin.</div>
      <?php else: ?>
      <div class="mb-8 p-5 bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-lg">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3"><?= $userExistingReview ? 'Kirim Ulang Ulasanmu' : 'Tulis Ulasanmu' ?></h3>
        <form method="POST" action="<?= e(APP_URL) ?>/actions/user/submit-review">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <div class="flex gap-1 mb-4" id="star-picker">
            <?php for($i=1;$i<=5;$i++): ?><button type="button" data-star="<?= $i ?>" class="star-btn text-gray-300 hover:text-yellow-400 text-3xl leading-none">★</button><?php endfor; ?>
            <input type="hidden" name="rating" id="rating-input" value="<?= (int)($userExistingReview['rating'] ?? 5) ?>">
          </div>
          <textarea name="review_text" rows="3" maxlength="1000" placeholder="Ceritakan pengalamanmu (opsional)..." class="w-full px-3 py-2.5 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white resize-none"><?= e($userExistingReview['review_text'] ?? '') ?></textarea>
          <button type="submit" class="mt-3 px-5 py-2 bg-brand text-white text-sm font-medium rounded-lg hover:opacity-90">Kirim Ulasan</button>
        </form>
        <script>(function(){var b=document.querySelectorAll('#star-picker .star-btn'),i=document.getElementById('rating-input'),c=parseInt(i.value)||5;function u(r){b.forEach(function(x){var s=parseInt(x.dataset.star);x.classList.toggle('text-yellow-400',s<=r);x.classList.toggle('text-gray-300',s>r);});}b.forEach(function(x){x.addEventListener('click',function(){c=parseInt(this.dataset.star);i.value=c;u(c);});x.addEventListener('mouseover',function(){u(parseInt(this.dataset.star));});x.addEventListener('mouseout',function(){u(c);});});u(c);})();</script>
      </div>
      <?php endif; ?>
    <?php elseif (isLoggedIn()): ?>
    <p class="text-sm text-gray-400 italic mb-6">Beli produk ini untuk bisa memberikan ulasan.</p>
    <?php else: ?>
    <p class="text-sm text-gray-400 italic mb-6"><a href="<?= e(APP_URL) ?>/login" class="text-[var(--brand-color)] hover:underline">Login</a> untuk memberikan ulasan.</p>
    <?php endif; ?>

    <?php if (!empty($approvedReviews)): ?>
    <div class="space-y-5">
      <?php foreach ($approvedReviews as $rvw): ?>
      <div class="flex gap-4">
        <div class="shrink-0 w-9 h-9 rounded-full bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-brand flex items-center justify-center font-semibold text-sm"><?= e(strtoupper(substr($rvw['display_name'], 0, 1))) ?></div>
        <div class="flex-1">
          <div class="flex items-center gap-2 mb-1">
            <span class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= e($rvw['display_name']) ?></span>
            <span class="text-yellow-400 text-xs"><?php for($i=1;$i<=5;$i++) echo $i<=(int)$rvw['rating']?'★':'<span class="text-gray-300 dark:text-gray-600">★</span>'; ?></span>
            <span class="text-xs text-gray-400"><?= date('d M Y', strtotime($rvw['approved_at'])) ?></span>
          </div>
          <?php if ($rvw['review_text']): ?><p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed"><?= e($rvw['review_text']) ?></p><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-sm text-gray-400 italic">Belum ada ulasan untuk produk ini.</p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- Footer -->
<footer class="border-t border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 mt-12">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 py-6 flex flex-col sm:flex-row items-center justify-between gap-3">
    <?php $footerText = getSetting('footer_text', ''); ?>
    <p class="text-xs text-gray-400 dark:text-gray-600"><?= e($footerText !== '' ? str_replace('{year}', date('Y'), $footerText) : '© ' . date('Y') . ' ' . $appName . '. All rights reserved.') ?></p>
    <a href="<?= e(APP_URL) ?>/products" class="text-xs text-gray-400 hover:text-[var(--brand-color)] transition">← Kembali ke Produk</a>
  </div>
</footer>

<?php
// Social Proof per produk: footer meng-include sp-popup.php yang membaca ini.
$spProductId = (int) $product['id'];
$spMode      = in_array($product['sp_mode'] ?? 'all', ['all', 'own', 'off'], true) ? $product['sp_mode'] : 'all';
// Heartbeat presence: pengunjung "live" di sales page produk ini.
echo funnelHeartbeatScript('product', (int) $product['id'], 'salespage');
include __DIR__ . '/../../partials/footer.php';

// Meta browser: ViewContent (eventID stabil → dedup dgn CAPI di atas). Opt-in.
if (empty($product['landing_page_url']) && !isAdminOrStaff()) {
    echo PixelHelper::renderFunnelBrowser('view', (int) $product['id'], ['value' => $price]);
}
?>
