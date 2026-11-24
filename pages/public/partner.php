<?php
require_once __DIR__ . '/../../bootstrap.php';

// ─── Averion Partner — storefront per-member ──────────────────────────────
// Satu link unik per member: /partner/{custom_slug|kode}. Menampilkan etalase
// produk Partner (komisi afiliat 100%). Setiap kartu menuju /go/{kode}/{slug}
// (landing afiliasi: set cookie member + arahkan ke salespage/checkout produk)
// sehingga pembelian teratribusi ke member tsb (komisi 100% otomatis).

// Program afiliasi mati → storefront tak berlaku.
if (!affiliateProgramEnabled()) {
    redirect('/products');
}

$key  = (string) ($_GET['code'] ?? '');
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($key));
$code = preg_replace('/[^A-Z0-9]/', '', strtoupper($key));

$affiliate = null;
$products  = [];
try {
    $pdo = getDB();
    // Terima slug (cantik) maupun kode afiliat. Hanya afiliat AKTIF.
    $st = $pdo->prepare(
        "SELECT a.id, a.code, a.user_id, u.name AS member_name
         FROM affiliates a JOIN users u ON u.id = a.user_id
         WHERE a.status = 'active' AND (a.custom_slug = ? OR a.code = ?) LIMIT 1"
    );
    $st->execute([$slug, $code]);
    $affiliate = $st->fetch() ?: null;

    if ($affiliate) {
        // Kunci atribusi ke member untuk seluruh kunjungan (selain tiap CTA /go).
        setAffiliateCookie($affiliate['code']);

        // Produk Partner = produk yang memberi KOMISI EFEKTIF 100% ke member ini
        // (resolveCommissionRate — mencakup kolom produk, commission_rule, level, resell).
        $pst = $pdo->prepare(
            "SELECT p.*, cp.slug AS canvas_slug,
                    (SELECT COUNT(*) FROM order_items oi JOIN orders o ON oi.order_id = o.id
                     WHERE oi.product_id = p.id AND o.status = 'paid') AS sold_count
             FROM products p
             LEFT JOIN canvas_pages cp ON cp.id = p.canvas_id AND cp.is_active = 1
             WHERE p.status = 'active'
               AND (p.affiliate_commission > 0 OR EXISTS (
                     SELECT 1 FROM commission_rules cr WHERE cr.status = 'active' AND cr.product_id = p.id))
             ORDER BY p.sort_order ASC, p.created_at DESC"
        );
        $pst->execute();
        $affUserId = (int) ($affiliate['user_id'] ?? 0);
        foreach ($pst->fetchAll() as $prod) {
            if (resolveCommissionRate($affUserId, (int) $prod['id']) >= 100) { $products[] = $prod; }
        }
    }
} catch (Throwable $e) {
    error_log('Partner storefront error: ' . $e->getMessage());
}

// Kode tidak valid / member tidak aktif → ke katalog umum.
if (!$affiliate) {
    redirect('/products');
}

$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
$brandGrad  = 'background: var(--brand-color);';
$memberName = trim((string) ($affiliate['member_name'] ?? ''));
$affCode    = (string) $affiliate['code'];
$pageTitle  = 'Katalog ' . $appName;

$typeBadge = function (string $t): string {
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
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="partner-bg min-h-screen flex flex-col bg-gray-50 dark:bg-gray-950 antialiased">
<style>
  /* Dot grid tipis sadar-tema — HANYA background halaman, TIDAK di kartu */
  :root{ --pt-dot: rgba(15,23,42,.055); }
  .dark{ --pt-dot: rgba(255,255,255,.05); }
  .partner-bg{ background-image: radial-gradient(var(--pt-dot) 1px, transparent 1px); background-size: 22px 22px; background-position:-1px -1px; }
  /* Pagar: kartu WAJIB opak supaya dot halaman mustahil tembus ke kartu */
  .pgrid > div{ background-color:#ffffff; background-image:none; }
  .dark .pgrid > div{ background-color:#111827; }
  /* Ilustrasi hero (area kanan) — CSS lokal, tak bergantung kelas Tailwind */
  .hero-art{ display:none; position:absolute; right:2rem; top:50%; transform:translateY(-50%);
             width:19rem; height:12rem; pointer-events:none; }
  @media (min-width:860px){ .hero-art{ display:block; } }
  .hp-cta{box-shadow:0 6px 16px -6px color-mix(in srgb,var(--brand-color) 60%,transparent);transition:transform .15s ease,box-shadow .15s ease,opacity .15s ease;}
  .hp-cta:hover{transform:translateY(-1px);box-shadow:0 10px 22px -8px color-mix(in srgb,var(--brand-color) 70%,transparent);}
</style>

<!-- Navbar -->
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
    <div class="flex items-center gap-2 shrink-0">
      <?php include __DIR__ . '/../../partials/dark-toggle.php'; ?>
      <?php if (isLoggedIn()): ?>
      <a href="<?= e(APP_URL) ?>/dashboard" class="hp-cta px-4 py-2 rounded-lg text-sm font-semibold text-white hover:opacity-95" style="<?= $brandGrad ?>">Dashboard</a>
      <?php else: ?>
      <a href="<?= e(APP_URL) ?>/login" class="px-3 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Masuk</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<main class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 py-8">

  <!-- Hero -->
  <div class="rounded-xl p-6 sm:p-8 mb-8 text-white overflow-hidden relative" style="<?= $brandGrad ?>box-shadow:inset 0 0 0 1px rgba(255,255,255,.10);">
    <!-- Glow halus (tanpa dot) -->
    <div class="pointer-events-none" style="position:absolute;top:-5rem;right:-3rem;width:20rem;height:20rem;border-radius:9999px;background:radial-gradient(circle, rgba(255,255,255,.28), transparent 70%)"></div>
    <div class="pointer-events-none" style="position:absolute;bottom:-6rem;left:-2.5rem;width:18rem;height:18rem;border-radius:9999px;background:radial-gradient(circle, rgba(0,0,0,.12), transparent 70%)"></div>
    <!-- Dekorasi SVG transparan: tumpukan kartu produk digital (area kanan hero) -->
    <svg class="hero-art" viewBox="0 0 300 180" fill="none" aria-hidden="true">
      <g stroke="#ffffff" stroke-linecap="round" stroke-linejoin="round" fill="none">
        <rect x="104" y="30" width="132" height="92" rx="12" stroke-width="1.5" opacity=".16"/>
        <rect x="86" y="42" width="132" height="92" rx="12" stroke-width="1.5" opacity=".26"/>
        <rect x="68" y="54" width="132" height="92" rx="12" stroke-width="1.8" opacity=".42"/>
        <rect x="80" y="66" width="48" height="34" rx="6" stroke-width="1.5" opacity=".34"/>
        <path d="M80 92l12-10 9 8 7-6 10 10" stroke-width="1.5" opacity=".3"/>
        <line x1="138" y1="72" x2="188" y2="72" stroke-width="1.5" opacity=".32"/>
        <line x1="138" y1="86" x2="172" y2="86" stroke-width="1.5" opacity=".24"/>
        <rect x="80" y="112" width="56" height="18" rx="5" stroke-width="1.6" opacity=".38"/>
        <line x1="150" y1="121" x2="188" y2="121" stroke-width="1.5" opacity=".22"/>
        <path d="M252 52v16M244 60h16" stroke-width="1.6" opacity=".28"/>
        <path d="M268 100v10M263 105h10" stroke-width="1.5" opacity=".2"/>
        <circle cx="246" cy="128" r="4" stroke-width="1.5" opacity=".22"/>
      </g>
    </svg>
    <div class="relative z-10">
      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold bg-white/20 backdrop-blur mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9h18M3 9l1.5-4.5A2 2 0 0 1 6.4 3h11.2a2 2 0 0 1 1.9 1.5L21 9M3 9v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9"/></svg>
        Katalog Partner
      </span>
      <h1 class="text-2xl sm:text-3xl font-bold leading-tight">Produk Digital <?= e($appName) ?></h1>
      <?php if ($memberName !== ''): ?>
      <p class="text-sm mt-2" style="color:rgba(255,255,255,.85)">Direkomendasikan oleh <strong><?= e($memberName) ?></strong></p>
      <?php endif; ?>
      <p class="text-sm mt-1" style="color:rgba(255,255,255,.80)"><?= count($products) ?> produk tersedia</p>
    </div>
  </div>

  <!-- Grid -->
  <?php if (empty($products)): ?>
  <div class="py-20 text-center">
    <div class="w-16 h-16 mx-auto rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4">
      <svg class="w-8 h-8 text-gray-400 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/></svg>
    </div>
    <h3 class="font-semibold text-gray-700 dark:text-gray-300">Belum ada produk</h3>
    <p class="text-sm text-gray-400 dark:text-gray-600 mt-1">Katalog partner sedang disiapkan. Cek lagi nanti.</p>
    <a href="<?= e(APP_URL) ?>/products" class="mt-4 inline-block text-sm text-[var(--brand-color)] hover:underline">Lihat katalog umum →</a>
  </div>
  <?php else: ?>
  <div class="pgrid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
    <?php foreach ($products as $product):
      $price     = (float) $product['price'];
      $origPrice = (float) $product['original_price'];
      if (!empty($product['dime_enabled']) && ($product['pricing_type'] ?? 'fixed') !== 'flexible') {
          $price = dimeEffectivePrice($product); $origPrice = $price;
      }
      $gFlex     = ($product['pricing_type'] ?? 'fixed') === 'flexible';
      $hasDisc   = $origPrice > $price && !$gFlex;
      $gFlexMin  = (float) ($product['flexible_min'] ?? 0);
      $gFlexLabel = trim((string) ($product['flexible_label'] ?? '')) ?: 'Bayar Seikhlasnya';
      $gCta      = trim((string) ($product['cta_label'] ?? ''));
      $isFree    = $price <= 0 && !$gFlex;
      $discPct   = $hasDisc ? round(100 - ($price / $origPrice * 100)) : 0;
      $gMyr      = ($product['price_myr'] !== null && (float) $product['price_myr'] > 0) ? (float) $product['price_myr'] : null;
      $userHasAccess = isLoggedIn() && hasAccess((int) ($_SESSION['user_id'] ?? 0), (int) $product['id']);
      // CTA: landing afiliasi member → salespage/checkout produk (atribusi terkunci).
      $goUrl     = rtrim(APP_URL, '/') . '/go/' . rawurlencode($affCode) . '/' . rawurlencode($product['slug']);
    ?>
    <div class="group relative bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm hover:shadow-xl hover:-translate-y-1 hover:border-[color-mix(in_srgb,var(--brand-color)_45%,transparent)] transition-all duration-300 overflow-hidden flex flex-col">

      <div class="relative overflow-hidden aspect-[4/3]">
        <?php if ($product['thumbnail']): ?>
        <img src="<?= e($product['thumbnail']) ?>" alt="<?= e($product['name']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
        <?php else: ?>
        <div class="w-full h-full flex items-center justify-center" style="<?= $brandGrad ?>"><svg class="w-10 h-10 text-white/30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/></svg></div>
        <?php endif; ?>
        <div class="absolute top-2 left-2 flex flex-col gap-1">
          <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium <?= $typeBadge($product['type']) ?>"><?= e(productCatalogLabel($product)) ?></span>
          <?php if ($hasDisc && $discPct >= 5): ?>
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
            <?php if ($gMyr !== null): ?><div class="mt-1"><?= ringgitBadge($gMyr) ?></div><?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ((int) $product['sold_count'] > 0 && getSetting('show_sold_count', '1') === '1'): ?>
          <p class="text-xs text-gray-400 dark:text-gray-600"><?= number_format((int) $product['sold_count']) ?> terjual</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="px-4 pb-4">
        <?php if ($userHasAccess): ?>
        <div class="w-full py-2 rounded-lg text-xs font-semibold text-center bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800/40">Akses Produk →</div>
        <?php else: ?>
        <div class="hp-cta w-full py-2 rounded-lg text-xs font-semibold text-center text-white group-hover:opacity-95" style="<?= $brandGrad ?>"><?= $gCta !== '' ? e($gCta) : ($isFree ? 'Ambil Gratis' : 'Beli Sekarang') ?></div>
        <?php endif; ?>
      </div>

      <a href="<?= e($goUrl) ?>" class="absolute inset-0 z-10" aria-label="<?= e($product['name']) ?>"></a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</main>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
