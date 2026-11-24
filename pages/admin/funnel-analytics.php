<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

// ─── Filter ──────────────────────────────────────────────────
$period    = $_GET['period'] ?? '7';   // default 7 hari

$allowedPeriods = ['today' => 'Hari Ini', 'yesterday' => 'Kemarin', '7' => '7 Hari', '30' => '30 Hari', '90' => '90 Hari', '365' => '1 Tahun', 'all' => 'Semua Waktu'];
if (!array_key_exists($period, $allowedPeriods)) {
    $period = '7';
}
$periodLabel = $allowedPeriods[$period];   // label periode aktif (penanda UI)

// ─── Helper klausa tanggal (dipusatkan — dulu di-inline di 5 titik) ──
// 'today'/'yesterday' = satu hari PERSIS; angka = rolling window N hari terakhir
// (termasuk hari ini); 'all' = tanpa batas. $period sudah divalidasi ke whitelist
// di atas; nilai angka tetap di-cast (int) sebelum diinterpolasi (anti-injeksi).
$dateClauseFor = function (string $col) use ($period): string {
    if ($period === 'all')       return '';
    if ($period === 'today')     return "AND DATE($col) = CURDATE()";
    if ($period === 'yesterday') return "AND DATE($col) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    $n = (int) $period;
    return "AND DATE($col) >= DATE_SUB(CURDATE(), INTERVAL {$n} DAY)";
};

// ─── Load daftar produk & bundle aktif untuk dropdown ─────────
$products = [];
$bundles  = [];
try {
    // Urut terbaru dibuat di ATAS → produk terbaru otomatis jadi default (auto-pilih [0]).
    $products = getDB()->query(
        "SELECT id, name, landing_page_url FROM products WHERE status = 'active' ORDER BY created_at DESC, id DESC"
    )->fetchAll();
    $bundles = getDB()->query(
        "SELECT id, name FROM product_bundles WHERE status = 'active' ORDER BY created_at DESC, id DESC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Funnel analytics list error: ' . $e->getMessage());
}

// ─── Produk/bundle DEFAULT (pin) — dipilih admin, override "terbaru" ──────
// Disimpan di settings (`funnel_default_target` = p<id>/b<id>). Item yang di-pin
// dipindah ke PALING ATAS dropdown & dipakai sbg default saat tak ada ?target=.
// $csrf utk tombol toggle (generateCSRF idempoten; tak ganggu form lain).
$csrf          = generateCSRF();
$funnelDefault = (string) getSetting('funnel_default_target', '');
if ($funnelDefault !== '' && preg_match('/^([pb])(\d+)$/', $funnelDefault, $fm)) {
    $pinKind = $fm[1];
    $pinId   = (int) $fm[2];
    if ($pinKind === 'p') {
        foreach ($products as $i => $p) {
            if ((int) $p['id'] === $pinId) { array_unshift($products, array_splice($products, $i, 1)[0]); break; }
        }
    } else {
        foreach ($bundles as $i => $b) {
            if ((int) $b['id'] === $pinId) { array_unshift($bundles, array_splice($bundles, $i, 1)[0]); break; }
        }
    }
}

// ─── Target aktif: produk (p<id>) atau bundle (b<id>) ─────────
// Dropdown baru memakai param `target` (p<id>/b<id>). Kompat mundur: param lama
// product_id / bundle_id tetap dihormati (bookmark & link luar tak putus).
$mode      = 'product';
$productId = 0;
$bundleId  = 0;
$target    = (string) ($_GET['target'] ?? '');
if (preg_match('/^b(\d+)$/', $target, $m)) {
    $mode = 'bundle'; $bundleId = (int) $m[1];
} elseif (preg_match('/^p(\d+)$/', $target, $m)) {
    $mode = 'product'; $productId = (int) $m[1];
} elseif ((int) ($_GET['bundle_id'] ?? 0) > 0) {
    $mode = 'bundle'; $bundleId = (int) $_GET['bundle_id'];
} elseif ((int) ($_GET['product_id'] ?? 0) > 0) {
    $mode = 'product'; $productId = (int) $_GET['product_id'];
}

// Auto-pilih target bila tak ada di URL: pakai PRODUK DEFAULT (pin) bila di-set &
// masih aktif; jika tidak, jatuh ke item teratas (produk dulu, lalu bundle).
if ($productId === 0 && $bundleId === 0) {
    if ($funnelDefault !== '' && preg_match('/^([pb])(\d+)$/', $funnelDefault, $dm)) {
        $dKind = $dm[1];
        $dId   = (int) $dm[2];
        if ($dKind === 'p' && array_filter($products, fn($p) => (int) $p['id'] === $dId)) {
            $mode = 'product'; $productId = $dId;
        } elseif ($dKind === 'b' && array_filter($bundles, fn($b) => (int) $b['id'] === $dId)) {
            $mode = 'bundle'; $bundleId = $dId;
        }
    }
    if ($productId === 0 && $bundleId === 0) {
        if (!empty($products))    { $mode = 'product'; $productId = (int) $products[0]['id']; }
        elseif (!empty($bundles)) { $mode = 'bundle';  $bundleId  = (int) $bundles[0]['id']; }
    }
}

// ─── Cari target yang dipilih ────────────────────────────────
$selectedProduct = null;   // hanya terisi di mode produk
$selectedBundle  = null;   // hanya terisi di mode bundle
$selectedName    = '';
if ($mode === 'product') {
    foreach ($products as $p) {
        if ((int) $p['id'] === $productId) { $selectedProduct = $p; $selectedName = $p['name']; break; }
    }
} else {
    foreach ($bundles as $b) {
        if ((int) $b['id'] === $bundleId) { $selectedBundle = $b; $selectedName = $b['name']; break; }
    }
}
$hasSelection = ($selectedProduct !== null) || ($selectedBundle !== null);
// Nilai <select> aktif (utk atribut selected di dropdown).
$targetValue  = $mode === 'bundle' ? 'b' . $bundleId : 'p' . $productId;
// Label tampilan tombol combobox (nama + badge sumber), mencerminkan opsi terpilih.
$targetLabel = '';
if ($selectedProduct) {
    $targetLabel = $selectedProduct['name'] . ' — ' . (!empty($selectedProduct['landing_page_url']) ? 'LP Eksternal' : 'LP Internal');
} elseif ($selectedBundle) {
    $targetLabel = $selectedBundle['name'] . ' — Bundle';
}

// ─── Hitung data funnel ──────────────────────────────────────
// Funnel 5 tahap yang benar-benar berurutan (tiap tahap subset tahap sebelumnya):
//   Lead Magnet (opsional) → Sales Page → Klik Checkout → Bayar → Ambil Bump
$leadOptins     = 0;   // panel kontribusi (bukan tahap funnel — lihat catatan di bawah)
$leadBuyers     = 0;   // opt-in yang berujung beli produk ini
$lpViews        = 0;
$checkoutClicks = 0;
$paidOrders     = 0;   // TAHAP BARU — dulu funnel berhenti di "klik", tak pernah
                       // menunjukkan berapa yang jadi uang.
$paidRevenue    = 0.0;
$bumpTakers     = 0;
$linkedMagnets  = [];

if ($selectedProduct) {
    try {
        $pdo = getDB();

        $dateClause  = $dateClauseFor('created_at');
        $oDateClause = $dateClauseFor('o.created_at');

        // Tahap 0: opt-in lead magnet yang tertaut ke produk ini (v1.1.98).
        // Tanpa relasi lead_magnets.linked_product_id, tahap ini mustahil dihitung.
        $stmt = $pdo->prepare(
            "SELECT id, title FROM lead_magnets WHERE linked_product_id = ? ORDER BY title ASC"
        );
        $stmt->execute([$productId]);
        $linkedMagnets = $stmt->fetchAll();

        if ($linkedMagnets) {
            $lDateClause = $dateClauseFor('l.created_at');
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM leads l
                 JOIN lead_magnets lm ON lm.id = l.lead_magnet_id
                 WHERE lm.linked_product_id = ? $lDateClause"
            );
            $stmt->execute([$productId]);
            $leadOptins = (int) $stmt->fetchColumn();

            // Berapa opt-in yang BERUJUNG beli produk ini — inilah metrik yang
            // sah untuk lead magnet (bukan "opt-in → visitor" yang tak nyambung).
            // Lead ditautkan ke user via promoted_user_id, fallback cocokkan email
            // (lead bisa beli sebelum sempat dipromosikan jadi akun).
            $stmt = $pdo->prepare(
                "SELECT COUNT(DISTINCT l.id)
                 FROM leads l
                 JOIN lead_magnets lm ON lm.id = l.lead_magnet_id AND lm.linked_product_id = ?
                 JOIN users u ON (u.id = l.promoted_user_id OR (l.email IS NOT NULL AND u.email = l.email))
                 JOIN orders o ON o.user_id = u.id AND o.status = 'paid'
                 JOIN order_items oi ON oi.order_id = o.id AND oi.product_id = ? AND oi.is_bump = 0
                 WHERE 1=1 $lDateClause"
            );
            $stmt->execute([$productId, $productId]);
            $leadBuyers = (int) $stmt->fetchColumn();
        }

        // Tahap 1: lp_view (sudah ter-dedup per sesi saat insert)
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM funnel_events
             WHERE product_id = ? AND event_type = 'lp_view' $dateClause"
        );
        $stmt->execute([$productId]);
        $lpViews = (int) $stmt->fetchColumn();

        // Tahap 2: checkout_click
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM funnel_events
             WHERE product_id = ? AND event_type = 'checkout_click' $dateClause"
        );
        $stmt->execute([$productId]);
        $checkoutClicks = (int) $stmt->fetchColumn();

        // Tahap 3: BAYAR — order lunas yang memuat produk ini sebagai item UTAMA
        // (is_bump=0). Inilah ujung funnel yang sesungguhnya.
        // BUG LAMA (jangan diulang): SUM(DISTINCT o.total) dedup PER NILAI, bukan per
        // order — 2 order beda dgn total SAMA (lumrah utk produk harga tetap) akan
        // dihitung SEKALI saja → omzet undercount. Fix: dedup per order.id dulu via
        // subquery, baru SUM tanpa DISTINCT.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*), COALESCE(SUM(total), 0) FROM (
                SELECT DISTINCT o.id, o.total
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE oi.product_id = ? AND oi.is_bump = 0 AND o.status = 'paid'
                $oDateClause
             ) t"
        );
        $stmt->execute([$productId]);
        [$paidOrders, $paidRevenue] = array_values($stmt->fetch(PDO::FETCH_NUM));
        $paidOrders  = (int) $paidOrders;
        $paidRevenue = (float) $paidRevenue;

        // Tahap 4: dari pembeli produk ini, berapa yang SEKALIAN ambil bump.
        // Dulu query-nya menghitung "produk ini terjual sebagai bump di order
        // orang lain" (oi.product_id = X AND is_bump = 1) — itu funnel produk
        // LAIN, bukan subset tahap sebelumnya, sehingga bisa melebihi jumlah
        // checkout & merusak makna funnel.
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT o.id)
             FROM orders o
             JOIN order_items main ON main.order_id = o.id AND main.product_id = ? AND main.is_bump = 0
             JOIN order_items bump ON bump.order_id = o.id AND bump.is_bump = 1
             WHERE o.status = 'paid' $oDateClause"
        );
        $stmt->execute([$productId]);
        $bumpTakers = (int) $stmt->fetchColumn();

    } catch (Exception $e) {
        error_log('Funnel analytics query error: ' . $e->getMessage());
    }
} elseif ($selectedBundle) {
    // ─── Mode BUNDLE — funnel 3 tahap: Visitor → Klik Checkout → Bayar ───
    // Bundle tak punya lead magnet & order bump; event dilacak via funnel_events
    // .bundle_id (product_id=0). Bayar = order lunas yang memuat item bundle ini
    // (order_items.bundle_id = X, is_bump=0). Satu order bundle = banyak item
    // (satu per produk) → dedup per order.id via subquery sebelum SUM (BUKAN
    // SUM(DISTINCT o.total) — itu dedup per NILAI, bikin omzet undercount kalau
    // ada order lain dgn total kebetulan sama).
    try {
        $pdo = getDB();
        ensureFunnelBundleColumn($pdo);   // self-heal kolom bila migrasi belum jalan

        $dateClause  = $dateClauseFor('created_at');
        $oDateClause = $dateClauseFor('o.created_at');

        // Tahap 1: lp_view bundle (dedup per sesi saat insert)
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM funnel_events
             WHERE bundle_id = ? AND event_type = 'lp_view' $dateClause"
        );
        $stmt->execute([$bundleId]);
        $lpViews = (int) $stmt->fetchColumn();

        // Tahap 2: checkout_click bundle
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM funnel_events
             WHERE bundle_id = ? AND event_type = 'checkout_click' $dateClause"
        );
        $stmt->execute([$bundleId]);
        $checkoutClicks = (int) $stmt->fetchColumn();

        // Tahap 3: BAYAR — order lunas yang memuat bundle ini.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*), COALESCE(SUM(total), 0) FROM (
                SELECT DISTINCT o.id, o.total
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE oi.bundle_id = ? AND oi.is_bump = 0 AND o.status = 'paid'
                $oDateClause
             ) t"
        );
        $stmt->execute([$bundleId]);
        [$paidOrders, $paidRevenue] = array_values($stmt->fetch(PDO::FETCH_NUM));
        $paidOrders  = (int) $paidOrders;
        $paidRevenue = (float) $paidRevenue;

    } catch (Exception $e) {
        error_log('Funnel analytics bundle query error: ' . $e->getMessage());
    }
}

// ─── Persentase konversi antar tahap ─────────────────────────
$pct = fn(int $num, int $den): float => $den > 0 ? round($num / $den * 100, 1) : 0.0;
$pctOptinToBuy    = $pct($leadBuyers, $leadOptins);   // efektivitas lead magnet
$pctLpToCheckout  = $pct($checkoutClicks, $lpViews);
$pctCheckoutToPaid = $pct($paidOrders, $checkoutClicks);
$pctPaidToBump    = $pct($bumpTakers, $paidOrders);
$pctLpToPaid      = $pct($paidOrders, $lpViews);   // konversi utama LP → uang


// Sumber LP hanya relevan mode produk. Mode bundle → badge "Bundle" netral-brand.
$isExternalLP = $mode === 'product' && !empty($selectedProduct['landing_page_url']);
$lpSource     = $mode === 'bundle' ? 'Bundle' : ($isExternalLP ? 'LP Eksternal' : 'LP Internal');
// Badge sumber: netral (abu) utk LP eksternal, aksen brand utk internal/bundle —
// dulu ungu/biru hardcode yang tak ikut branding.
$lpBadgeStyle = $isExternalLP
    ? 'background:rgba(107,114,128,.12);color:#6b7280'
    : 'background:color-mix(in srgb, var(--brand-color) 12%, transparent);color:var(--brand-color)';

$pageTitle = 'Funnel Analytics — ' . getSetting('app_name', 'STIFIn Mulia');
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

<div class="lg:pl-60 flex flex-col min-h-screen">
  <?php include __DIR__ . '/../../partials/topbar.php'; ?>

  <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-6xl mx-auto w-full">

    <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

    <!-- ─── Header ──────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Funnel Analytics</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Perjalanan pembeli: Sales Page → Klik Checkout → Bayar → Order Bump</p>
      </div>
    </div>

    <!-- ─── Filter bar ───────────────────────────────────────── -->
    <style>
      .fa-combo-panel{position:absolute;z-index:40;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px -8px rgba(0,0,0,.25);overflow:hidden}
      .fa-combo-search{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid #f1f5f9}
      .fa-combo-search svg{width:16px;height:16px;color:#94a3b8;flex:none}
      .fa-combo-search input{flex:1;border:0;outline:0;background:transparent;font-size:14px;color:#0f172a}
      .fa-combo-list{max-height:320px;overflow-y:auto;padding:4px}
      .fa-grp{padding:8px 10px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8}
      .fa-opt{display:block;width:100%;text-align:left;padding:8px 10px;border-radius:8px;font-size:14px;color:#334155;cursor:pointer;background:transparent;border:0}
      .fa-opt:hover{background:#f1f5f9}
      .fa-opt.is-sel{background:var(--brand-color);color:#fff}
      .fa-opt.is-sel:hover{background:var(--brand-color)}
      .fa-badge{color:#94a3b8}
      .fa-opt.is-sel .fa-badge{color:rgba(255,255,255,.82)}
      .fa-empty{padding:16px;text-align:center;font-size:13px;color:#94a3b8}
      .dark .fa-combo-panel{background:#0f172a;border-color:#1f2937}
      .dark .fa-combo-search{border-color:#1f2937}
      .dark .fa-combo-search input{color:#e5e7eb}
      .dark .fa-grp{color:#64748b}
      .dark .fa-opt{color:#cbd5e1}
      .dark .fa-opt:hover{background:#1f2937}
      /* Dot status "menyala" pada tombol produk default funnel saat aktif. */
      .fd-dot{position:relative;display:inline-flex;align-items:center;justify-content:center;width:9px;height:9px;flex:none}
      .fd-dot::before{content:"";position:absolute;inset:0;border-radius:9999px;background:#10b981;opacity:.55;animation:fd-ping 1.4s cubic-bezier(0,0,.2,1) infinite}
      .fd-dot::after{content:"";position:relative;width:9px;height:9px;border-radius:9999px;background:#10b981;box-shadow:0 0 0 2px rgba(16,185,129,.18),0 0 8px 1px rgba(16,185,129,.65)}
      @keyframes fd-ping{75%,100%{transform:scale(2.1);opacity:0}}
      @media (prefers-reduced-motion:reduce){.fd-dot::before{animation:none}}
    </style>
    <div class="flex flex-wrap items-end gap-3">
    <form method="GET" class="flex flex-1 min-w-0 flex-wrap gap-3 items-end">
      <!-- Pilih Produk / Bundle -->
      <div class="flex-1 min-w-[220px]">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk / Bundle</label>
        <div class="relative" id="faCombo">
          <input type="hidden" name="target" id="faTarget" value="<?= e($targetValue) ?>">
          <button type="button" id="faComboBtn" onclick="faToggle(event)"
                  class="w-full appearance-none text-left rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm px-3.5 py-2.5 pr-8 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
            <span class="block truncate"><?= $hasSelection ? e($targetLabel) : 'Pilih produk / bundle…' ?></span>
          </button>
          <svg class="absolute right-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
               viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 12 15 18 9"/>
          </svg>

          <!-- Panel combobox (searchable) -->
          <div id="faComboPanel" class="fa-combo-panel hidden">
            <div class="fa-combo-search">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="faComboSearch" placeholder="Cari produk / bundle…" autocomplete="off" oninput="faFilter(this.value)">
            </div>
            <div id="faComboList" class="fa-combo-list">
              <?php if ($products): ?>
              <div class="fa-grp" data-grp>Produk</div>
              <?php foreach ($products as $p): ?>
              <?php $pv = 'p' . (int)$p['id']; $pb = !empty($p['landing_page_url']) ? 'LP Eksternal' : 'LP Internal'; ?>
              <button type="button" class="fa-opt<?= $targetValue === $pv ? ' is-sel' : '' ?>"
                      data-value="<?= e($pv) ?>" data-search="<?= e(mb_strtolower($p['name'])) ?>"
                      onclick="faPick('<?= e($pv) ?>')"><?= e($p['name']) ?> <span class="fa-badge">— <?= $pb ?></span></button>
              <?php endforeach; ?>
              <?php endif; ?>
              <?php if ($bundles): ?>
              <div class="fa-grp" data-grp>Bundle</div>
              <?php foreach ($bundles as $b): ?>
              <?php $bv = 'b' . (int)$b['id']; ?>
              <button type="button" class="fa-opt<?= $targetValue === $bv ? ' is-sel' : '' ?>"
                      data-value="<?= e($bv) ?>" data-search="<?= e(mb_strtolower($b['name'])) ?>"
                      onclick="faPick('<?= e($bv) ?>')"><?= e($b['name']) ?> <span class="fa-badge">— Bundle</span></button>
              <?php endforeach; ?>
              <?php endif; ?>
              <p id="faComboEmpty" class="fa-empty hidden">Tidak ada hasil.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Periode -->
      <div class="min-w-0 max-w-full">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Periode</label>
        <div class="flex rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto bg-white dark:bg-gray-900">
          <?php foreach ($allowedPeriods as $val => $label): $active = (string) $val === (string) $period; ?>
          <?php // Penanda warna tab aktif via INLINE style — class arbitrary
                // bg-[var(--brand-color)] tak ter-compile di tailwind.css. ?>
          <button type="submit" name="period" value="<?= e($val) ?>"
                  <?= $active ? 'aria-current="true"' : '' ?>
                  style="<?= $active ? 'background:var(--brand-color);color:#fff' : '' ?>"
                  class="shrink-0 px-3.5 py-2.5 text-xs font-semibold whitespace-nowrap transition <?= $active
                    ? 'shadow-sm'
                    : 'font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
            <?= e($label) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </form>

    <?php // ─── Tombol pin "produk default funnel": pojok kanan-atas toolbar ───
          $isPinned = ($hasSelection && $funnelDefault !== '' && $funnelDefault === $targetValue); ?>
    <?php if ($hasSelection): ?>
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/set-funnel-default" class="ml-auto shrink-0">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="target" value="<?= e($targetValue) ?>">
      <input type="hidden" name="period" value="<?= e($period) ?>">
      <button type="submit"
              title="<?= $isPinned ? 'Lepas sebagai produk default funnel' : 'Jadikan ini default: muncul paling atas & terbuka otomatis saat halaman dibuka' ?>"
              style="<?= $isPinned ? 'border-color:var(--brand-color);color:var(--brand-color);background:color-mix(in srgb,var(--brand-color) 10%,transparent)' : '' ?>"
              class="inline-flex items-center gap-2 rounded-xl border px-3.5 py-2.5 text-xs font-semibold transition <?= $isPinned ? '' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/></svg>
        <?= $isPinned ? 'Produk Default Funnel' : 'Jadikan default funnel' ?>
        <?php if ($isPinned): ?><span class="fd-dot" aria-label="aktif" title="Aktif"></span><?php endif; ?>
      </button>
    </form>
    <?php endif; ?>
    </div>

    <?php if ($hasSelection): ?>

    <!-- ─── Produk / Bundle badge ────────────────────────────── -->
    <div class="flex items-center gap-3 -mt-2">
      <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200"><?= e($selectedName) ?></h2>
      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold" style="<?= $lpBadgeStyle ?>">
        <?php if ($mode === 'bundle'): ?>
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>
        </svg>
        <?php elseif ($isExternalLP): ?>
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
        </svg>
        <?php else: ?>
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <?php endif; ?>
        <?= e($lpSource) ?>
      </span>
      <?php if ($isExternalLP): ?>
      <span class="text-xs text-gray-400 dark:text-gray-600 truncate max-w-xs" title="<?= e($selectedProduct['landing_page_url']) ?>">
        → <?= e(parse_url($selectedProduct['landing_page_url'], PHP_URL_HOST) ?: $selectedProduct['landing_page_url']) ?>
      </span>
      <?php endif; ?>
      <!-- Penanda periode aktif -->
      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ml-auto shrink-0 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <?= e($periodLabel) ?>
      </span>
    </div>

    <!-- ─── Ringkasan tahap (kartu compact, aksen brand) ──────── -->
    <?php
    // Warna funnel diturunkan dari var(--brand-color) — sebelumnya pelangi
    // hardcode (biru/indigo/ungu/emerald/violet/teal) sehingga halaman ini
    // kebal tema & tak ikut branding buyer. Satu-satunya warna non-brand yang
    // dipertahankan: HIJAU utk tahap "Bayar" (uang masuk) — konvensi universal
    // yang membantu baca, bukan dekorasi.
    $brandTint = fn(int $pctMix): string => "color-mix(in srgb, var(--brand-color) {$pctMix}%, transparent)";

    // Tahap funnel: label, jumlah, konversi dari tahap sebelumnya, keterangan.
    // CATATAN PENTING: Opt-in Lead Magnet SENGAJA BUKAN tahap funnel.
    // Opt-in bukan superset visitor sales page — pengunjung bisa datang
    // langsung tanpa pernah lewat lead magnet, sehingga "opt-in → visitor"
    // gampang tembus >100% (mis. 3 opt-in vs 13 visitor = 433%) dan bentuk
    // corongnya jadi terbalik. Lead magnet = JALUR MASUK, ditampilkan sebagai
    // panel kontribusi terpisah di bawah funnel.
    $stages = [];
    $stages[] = [
        'key' => 'lp', 'label' => 'Visitor Sales Page', 'value' => $lpViews,
        'pct' => null,
        'note' => $mode === 'bundle' ? 'Buka halaman bundle' : ($isExternalLP ? 'Klik link affiliate' : 'Buka halaman produk'),
    ];
    $stages[] = [
        'key' => 'checkout', 'label' => 'Klik Checkout', 'value' => $checkoutClicks,
        'pct' => $pctLpToCheckout,
        'note' => 'Menuju form pembayaran',
    ];
    $stages[] = [
        'key' => 'paid', 'label' => 'Bayar', 'value' => $paidOrders,
        'pct' => $pctCheckoutToPaid,
        'note' => formatRupiah($paidRevenue) . ' omzet',
    ];
    // Order Bump hanya relevan untuk produk (bundle tak punya bump).
    if ($mode !== 'bundle')
    $stages[] = [
        'key' => 'bump', 'label' => 'Ambil Order Bump', 'value' => $bumpTakers,
        'pct' => $pctPaidToBump,
        'note' => 'Nilai order bertambah',
    ];

    $stageIcon = [
        'optin'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
        'lp'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
        'checkout' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>',
        'paid'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'bump'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>',
    ];

    // Funnel "terbalik": tahap lanjut > tahap sebelumnya. Terjadi saat order
    // mendahului mulainya pelacakan kunjungan (funnel_events) — umum pada periode
    // panjang / Semua Waktu → konversi bisa tembus 100% & bar melebihi lebar.
    // Kita tandai (badge amber + catatan) dan jaga bar TAK overflow (clamp ≤100%).
    $funnelInverted = false;
    foreach ($stages as $si => $sv) {
        if ($si > 0 && (int) $sv['value'] > (int) $stages[$si - 1]['value']) { $funnelInverted = true; break; }
    }
    // Tampilan konversi: >100% ditandai (amber) alih-alih angka absurd (mis. 12300%).
    $convBadge = function (?float $p): array {
        if ($p === null) return ['text' => '', 'over' => false];
        if ($p > 100)    return ['text' => '>100%', 'over' => true];
        return ['text' => $p . '%', 'over' => false];
    };
    ?>
    <style>
      /* Layout funnel (lokal per-halaman → tak butuh regen Tailwind) */
      .fn-strip{display:flex;flex-wrap:wrap;}
      .fn-seg{flex:1 1 50%;min-width:0;}
      .fn-conn{display:none;}
      @media (min-width:640px){ .fn-strip{flex-wrap:nowrap;} .fn-seg{flex:1 1 0;} .fn-conn{display:flex;} }
      .fn-row{display:grid;grid-template-columns:132px 1fr 140px;align-items:center;gap:14px;}
      @media (max-width:520px){ .fn-row{grid-template-columns:92px 1fr 84px;gap:8px;} }
    </style>

    <!-- ─── Strip tahapan tersambung (kartu tunggal + konektor konversi) ─── -->
    <div class="card-base bg-white dark:bg-gray-900 border border-gray-200/70 dark:border-gray-800 overflow-hidden fn-strip kpi-accent">
      <?php foreach ($stages as $i => $st):
          $isPaid = $st['key'] === 'paid';
          $accent = $isPaid ? '#10b981' : 'var(--brand-color)';
          $tint   = $isPaid ? 'rgba(16,185,129,.12)' : $brandTint(12);
      ?>
      <?php if ($i > 0): ?>
      <?php $cb = $convBadge($st['pct']); ?>
      <div class="fn-conn flex-col items-center justify-center gap-1 shrink-0 border-l border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/20" style="width:46px">
        <svg class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 6 15 12 9 18"/></svg>
        <span class="text-[10px] font-bold tabular-nums" style="color:<?= $cb['over'] ? '#f59e0b' : $accent ?>"><?= e($cb['text']) ?></span>
      </div>
      <?php endif; ?>
      <div class="fn-seg relative p-4">
        <span class="absolute inset-x-0 top-0 h-0.5" style="background:<?= $accent ?>"></span>
        <div class="flex items-center justify-between mb-2.5">
          <span class="w-8 h-8 flex items-center justify-center shrink-0" style="border-radius:var(--control-radius,0.375rem);background:<?= $tint ?>">
            <svg class="w-4 h-4" style="color:<?= $accent ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $stageIcon[$st['key']] ?></svg>
          </span>
          <span class="text-[10px] font-bold uppercase tracking-wider text-gray-300 dark:text-gray-600">Tahap <?= $i + 1 ?></span>
        </div>
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate"><?= e($st['label']) ?></p>
        <p class="text-3xl font-extrabold leading-none tabular-nums mt-1" style="<?= $isPaid ? 'color:#10b981' : '' ?>"><?= number_format($st['value']) ?></p>
        <p class="text-[11px] mt-1.5 truncate <?= $isPaid ? 'font-semibold' : 'text-gray-400 dark:text-gray-500' ?>" style="<?= $isPaid ? 'color:#10b981' : '' ?>" title="<?= e($st['note']) ?>"><?= e($st['note']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ─── Visualisasi Funnel (bar horizontal full-width + drop-off) ─── -->
    <?php $base = max(1, (int) $stages[0]['value']); ?>
    <div class="card-base bg-white dark:bg-gray-900 border border-gray-200/70 dark:border-gray-800 p-5 sm:p-6">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Visualisasi Funnel</h3>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Angka merah = pengunjung yang berhenti di tahap itu &middot; periode <strong><?= e($periodLabel) ?></strong></p>
        </div>
        <?php $cbSum = $convBadge((float) $pctLpToPaid); ?>
        <span class="hidden sm:inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
          Visitor → Bayar
          <span class="px-2 py-0.5 rounded-full font-bold" style="background:<?= $cbSum['over'] ? 'rgba(245,158,11,.12)' : $brandTint(10) ?>;color:<?= $cbSum['over'] ? '#b45309' : 'var(--brand-color)' ?>"><?= e($cbSum['text']) ?></span>
        </span>
      </div>

      <?php if ($funnelInverted): ?>
      <!-- Penanda anomali: funnel terbalik karena data order mendahului pelacakan -->
      <div class="flex items-start gap-2 px-3.5 py-2.5 mb-5 rounded-lg text-xs" style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.28);color:#b45309">
        <svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
        <span class="dark:text-amber-400/90">Beberapa tahap melebihi 100% pada periode <strong><?= e($periodLabel) ?></strong>. Jumlah <em>Bayar</em> mencakup semua transaksi, sedangkan <em>Visitor</em> hanya dihitung sejak Funnel Analytics mulai mencatat kunjungan — jadi ada transaksi yang mendahului pelacakan. Pilih periode lebih pendek untuk konversi yang setara.</span>
      </div>
      <?php endif; ?>

      <div class="space-y-2.5">
        <?php foreach ($stages as $i => $st):
            $isPaid = $st['key'] === 'paid';
            $val    = (int) $st['value'];
            $wPct   = round($val / $base * 100, 1);
            // Clamp ≤100%: tahap yang melebihi tahap pertama (funnel terbalik) TAK
            // boleh membuat bar menembus batas kartu. Lantai kecil agar tetap terlihat.
            $barW   = $val > 0 ? min(100, max(4, $wPct)) : 2;
            $inside = $barW >= 12;                        // nilai di dalam bar bila cukup lebar
            $bg = $isPaid
                ? 'linear-gradient(90deg,#34d399,#10b981)'
                : ($val > 0
                    ? 'linear-gradient(90deg, color-mix(in srgb, var(--brand-color) 55%, #ffffff), var(--brand-color))'
                    : 'color-mix(in srgb, var(--brand-color) 22%, transparent)');
            $lost = $lostPct = 0;
            if ($i > 0) {
                $prev    = $stages[$i - 1];
                $lost    = max(0, (int) $prev['value'] - $val);
                $lostPct = $prev['value'] > 0 ? round($lost / $prev['value'] * 100, 1) : 0;
            }
        ?>
        <div class="fn-row">
          <!-- Label tahap -->
          <div class="flex items-center gap-2 min-w-0">
            <span class="w-6 h-6 flex items-center justify-center shrink-0" style="border-radius:var(--control-radius,0.375rem);background:<?= $isPaid ? 'rgba(16,185,129,.12)' : $brandTint(12) ?>">
              <svg class="w-3.5 h-3.5" style="color:<?= $isPaid ? '#10b981' : 'var(--brand-color)' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $stageIcon[$st['key']] ?></svg>
            </span>
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-200 truncate"><?= e($st['label']) ?></span>
          </div>
          <!-- Bar -->
          <div class="relative h-9" style="border-radius:var(--control-radius,0.375rem);background:color-mix(in srgb, var(--brand-color) 6%, transparent)">
            <div class="h-9 flex items-center justify-end px-3 transition-all" style="width:<?= $barW ?>%;border-radius:var(--control-radius,0.375rem);background:<?= $bg ?>">
              <?php if ($inside): ?><span class="text-xs font-bold text-white tabular-nums"><?= number_format($val) ?></span><?php endif; ?>
            </div>
            <?php if (!$inside): ?>
            <span class="absolute top-1/2 -translate-y-1/2 text-xs font-bold tabular-nums text-gray-500 dark:text-gray-400" style="left:calc(<?= $barW ?>% + 8px)"><?= number_format($val) ?></span>
            <?php endif; ?>
          </div>
          <!-- Drop-off -->
          <div class="text-[11px] text-right tabular-nums text-gray-400 dark:text-gray-500">
            <?php if ($i === 0): ?>
              <span class="font-bold" style="color:var(--brand-color)">100%</span>
            <?php else: $cb = $convBadge($st['pct']); ?>
              <span class="font-bold" style="color:<?= $cb['over'] ? '#f59e0b' : ($isPaid ? '#10b981' : 'var(--brand-color)') ?>"><?= e($cb['text']) ?></span>
              <?php if ($lost > 0): ?> · <span class="text-red-400 font-medium"><?= number_format($lost) ?> berhenti</span><?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="mt-7 pt-5 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <span class="text-sm text-gray-500 dark:text-gray-400">Konversi keseluruhan <span class="text-gray-400 dark:text-gray-600">(Visitor → Bayar)</span></span>
        <div class="flex items-baseline gap-3">
          <span class="text-sm text-gray-400 dark:text-gray-500"><?= e(formatRupiah($paidRevenue)) ?></span>
          <span class="text-lg font-bold tabular-nums <?= $cbSum['over'] ? '' : 'text-gray-900 dark:text-white' ?>" style="<?= $cbSum['over'] ? 'color:#f59e0b' : '' ?>"><?= e($cbSum['text']) ?></span>
        </div>
      </div>
    </div>

    <?php if ($linkedMagnets): ?>
    <!-- ─── Kontribusi Lead Magnet (JALUR MASUK, bukan tahap funnel) ───
         Dipisah karena opt-in bukan superset visitor sales page; yang diukur
         di sini adalah efektivitasnya: dari sekian opt-in, berapa yang
         akhirnya membeli produk ini. -->
    <div class="card-base bg-white dark:bg-gray-900 border border-gray-200/70 dark:border-gray-800 p-6 kpi-accent">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Kontribusi Lead Magnet</h3>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Jalur masuk menuju produk ini — bukan tahap funnel, karena pengunjung bisa datang langsung tanpa opt-in.</p>
        </div>
        <a href="<?= e(APP_URL) ?>/admin/lead-magnets" class="text-xs font-semibold hover:underline" style="color:var(--brand-color)">Kelola →</a>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="p-3.5 border border-gray-200/70 dark:border-gray-800" style="border-radius:var(--card-radius,0.5rem)">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Opt-in</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white leading-none mt-1.5 tabular-nums"><?= number_format($leadOptins) ?></p>
        </div>
        <div class="p-3.5 border border-gray-200/70 dark:border-gray-800" style="border-radius:var(--card-radius,0.5rem)">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Jadi Pembeli</p>
          <p class="text-2xl font-bold leading-none mt-1.5 tabular-nums" style="color:#10b981"><?= number_format($leadBuyers) ?></p>
        </div>
        <div class="p-3.5 border border-gray-200/70 dark:border-gray-800" style="border-radius:var(--card-radius,0.5rem)">
          <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Konversi Opt-in → Beli</p>
          <p class="text-2xl font-bold leading-none mt-1.5 tabular-nums" style="color:var(--brand-color)"><?= $pctOptinToBuy ?>%</p>
        </div>
      </div>

      <div class="flex flex-wrap gap-1.5 mt-3">
        <?php foreach ($linkedMagnets as $lm): ?>
        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium" style="background:<?= $brandTint(10) ?>;color:var(--brand-color)"><?= e($lm['title']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // ─── Pengunjung Aktif (Live) — global lintas halaman, auto-refresh ───
    // "Live" = sesi yang SEDANG membuka halaman (heartbeat ≤ ~1 menit), per tahap.
    $liveInit = funnelLivePages(60);
    $liveTotal = array_sum(array_column($liveInit, 'total'));
    ?>
    <div class="card-base bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 p-6">
      <div class="flex items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-2">
          <span class="relative flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400" style="opacity:.75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
          </span>
          <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Pengunjung Aktif <span id="fl-total" class="text-gray-400 font-normal">(<?= (int) $liveTotal ?>)</span></h3>
        </div>
        <span class="text-[11px] text-gray-400 dark:text-gray-500">sedang online · <span id="fl-updated"><?= date('H:i:s') ?></span></span>
      </div>
      <div id="fl-list" class="space-y-2">
        <?php if (empty($liveInit)): ?>
        <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">Belum ada pengunjung aktif saat ini.</p>
        <?php else: foreach ($liveInit as $lp):
          $sub = $lp['salespage'] . ' di sales page' . ($lp['checkout'] > 0 ? ' · ' . $lp['checkout'] . ' di checkout' : '');
        ?>
        <div class="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/30">
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate"><?= e($lp['name']) ?></p>
            <p class="text-[11px] text-gray-400 mt-0.5"><?= e($sub) ?></p>
          </div>
          <span class="shrink-0 text-sm font-bold text-green-600 dark:text-green-400"><?= (int) $lp['total'] ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <script>
    (function () {
      var BASE = <?= json_encode(rtrim(APP_URL, '/')) ?>;
      var listEl = document.getElementById('fl-list');
      var totalEl = document.getElementById('fl-total');
      var updEl = document.getElementById('fl-updated');
      if (!listEl) return;
      function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
      function row(p) {
        var sub = p.salespage + ' di sales page' + (p.checkout > 0 ? ' · ' + p.checkout + ' di checkout' : '');
        return '<div class="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/30">'
          + '<div class="min-w-0"><p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">' + esc(p.name) + '</p>'
          + '<p class="text-[11px] text-gray-400 mt-0.5">' + esc(sub) + '</p></div>'
          + '<span class="shrink-0 text-sm font-bold text-green-600 dark:text-green-400">' + p.total + '</span></div>';
      }
      function render(d) {
        if (totalEl) totalEl.textContent = '(' + (d.total || 0) + ')';
        if (updEl && d.updated_at) updEl.textContent = d.updated_at;
        if (!d.pages || !d.pages.length) {
          listEl.innerHTML = '<p class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">Belum ada pengunjung aktif saat ini.</p>';
          return;
        }
        listEl.innerHTML = d.pages.map(row).join('');
      }
      function poll() {
        fetch(BASE + '/actions/admin/funnel-live?window=60', { headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.json(); }).then(render).catch(function () {});
      }
      setInterval(function () { if (!document.hidden) poll(); }, 20000);
    })();
    </script>

    <?php else: ?>
    <!-- No product -->
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-12 text-center">
      <svg class="w-12 h-12 text-gray-300 dark:text-gray-700 mx-auto mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
      </svg>
      <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada produk atau bundle aktif untuk ditampilkan.</p>
    </div>
    <?php endif; ?>

  </main>
</div>

<script>
(function () {
  'use strict';
  var combo = document.getElementById('faCombo');
  if (!combo) return;
  var panel = document.getElementById('faComboPanel');
  var search = document.getElementById('faComboSearch');
  var emptyEl = document.getElementById('faComboEmpty');

  window.faToggle = function (e) {
    if (e) e.stopPropagation();
    var opening = panel.classList.contains('hidden');
    panel.classList.toggle('hidden');
    if (opening) {
      search.value = '';
      faFilter('');
      setTimeout(function () { search.focus(); }, 10);
      var sel = panel.querySelector('.fa-opt.is-sel');
      if (sel) sel.scrollIntoView({ block: 'nearest' });
    }
  };

  window.faFilter = function (q) {
    q = (q || '').trim().toLowerCase();
    var any = false;
    panel.querySelectorAll('.fa-opt').forEach(function (o) {
      var hit = q === '' || o.getAttribute('data-search').indexOf(q) !== -1;
      o.style.display = hit ? '' : 'none';
      if (hit) any = true;
    });
    // Sembunyikan header grup yang tak punya opsi terlihat.
    panel.querySelectorAll('[data-grp]').forEach(function (g) {
      var n = g.nextElementSibling, vis = false;
      while (n && !(n.hasAttribute && n.hasAttribute('data-grp'))) {
        if (n.classList && n.classList.contains('fa-opt') && n.style.display !== 'none') { vis = true; break; }
        n = n.nextElementSibling;
      }
      g.style.display = vis ? '' : 'none';
    });
    if (emptyEl) emptyEl.classList.toggle('hidden', any);
  };

  window.faPick = function (v) {
    var t = document.getElementById('faTarget');
    t.value = v;
    t.form.submit();
  };

  document.addEventListener('click', function (e) {
    if (!combo.contains(e.target)) panel.classList.add('hidden');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') panel.classList.add('hidden');
  });
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
