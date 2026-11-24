<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Dashboard';
$topbarTitle = 'Dashboard';

$userId = (int) $_SESSION['user_id'];

$totalOrders   = 0;
$totalSpent    = 0.0;
$activeAccess  = 0;
$recentOrders  = [];
$myProducts    = [];
$memberLevel   = null;

try {
    $pdo = getDB();

    $totalOrders = (int) $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?')
        ->execute([$userId]) ? 0 : 0; // placeholder — re-query below

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
    $stmt->execute([$userId]);
    $totalOrders = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE user_id = ? AND status = 'paid'");
    $stmt->execute([$userId]);
    $totalSpent = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM member_access
         WHERE user_id = ? AND status = 'active'
           AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $stmt->execute([$userId]);
    $activeAccess = (int) $stmt->fetchColumn();

    $memberLevel = getMembershipLevel($userId);

} catch (Exception $e) {
    error_log('User dashboard stats error: ' . $e->getMessage());
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT o.order_number, o.total, o.status, o.created_at,
                GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') AS item_names
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.user_id = ?
         GROUP BY o.id
         ORDER BY o.created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$userId]);
    $recentOrders = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('User dashboard orders error: ' . $e->getMessage());
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.slug, p.type, p.catalog_category, p.thumbnail,
                ma.expires_at, ma.started_at
         FROM member_access ma
         JOIN products p ON ma.product_id = p.id
         WHERE ma.user_id = ? AND ma.status = 'active'
           AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
         ORDER BY ma.started_at DESC
         LIMIT 4"
    );
    $stmt->execute([$userId]);
    $myProducts = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('User dashboard products error: ' . $e->getMessage());
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

      <section class="dmi-hero mb-6" aria-labelledby="member-darmutiara-heading">
        <div>
          <h2 id="member-darmutiara-heading">Assalamu’alaikum, <?= e(explode(' ', $_SESSION['name'] ?? 'Anggota')[0]) ?></h2>
          <p>Lihat jadwal, daftar kajian, dan ikuti diskusi bersama anggota.</p>
          <?php if ($memberLevel): ?>
          <p>Keanggotaan <?= e($memberLevel['name']) ?></p>
          <?php endif; ?>
        </div>
        <a class="dmi-cta" href="<?= e(APP_URL) ?>/kajian">Lihat Jadwal Kajian <span aria-hidden="true">→</span></a>
      </section>

      <!-- Stat Cards -->
      <?php
      // UI Theme: kartu statistik mengikuti card-style theme aktif — selaras dashboard admin.
      // Airy (gradient) → kartu berwarna + teks/ikon putih; theme flat lainnya → kartu putih + ikon berwarna.
      $cardStyle = ($themeConfig['--card-style'] ?? (function_exists('getThemeConfig') ? getThemeConfig()['--card-style'] : 'flat'));
      $grad      = ($cardStyle === 'gradient');
      $cardCls   = fn(int $n) => $grad
          ? "card-base stat-card stat-card-$n p-4"
          : "card-base bg-white dark:bg-gray-900 p-4 border border-gray-200 dark:border-gray-800";
      $iconCls   = 'w-10 h-10 rounded-lg flex items-center justify-center shrink-0' . ($grad ? ' stat-icon' : '');
      ?>
      <?php $showPoints = function_exists('pointsEnabled') && pointsEnabled(); ?>
      <div class="kpi-accent grid grid-cols-1 <?= $showPoints ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-3' ?> gap-4 mb-6">

        <!-- Total Order -->
        <div class="<?= $cardCls(1) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Order</p>
              <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($totalOrders) ?></p>
            </div>
            <div class="<?= $iconCls ?> bg-blue-50 dark:bg-blue-900/20">
              <svg class="w-5 h-5 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
              </svg>
            </div>
          </div>
          <a href="<?= e(APP_URL) ?>/transactions" class="mt-3 text-xs font-medium flex items-center gap-1 text-[var(--brand-color)] hover:underline">
            Lihat semua <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>

        <!-- Total Belanja -->
        <div class="<?= $cardCls(2) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Belanja</p>
              <p class="mt-2 text-xl font-bold text-gray-900 dark:text-white truncate"><?= e(formatRupiah($totalSpent)) ?></p>
            </div>
            <div class="<?= $iconCls ?> bg-green-50 dark:bg-green-900/20">
              <svg class="w-5 h-5 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4h-4z"/>
              </svg>
            </div>
          </div>
          <p class="mt-3 text-xs text-gray-400 dark:text-gray-600">Transaksi lunas</p>
        </div>

        <!-- Akses Aktif -->
        <div class="<?= $cardCls(3) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Akses Aktif</p>
              <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($activeAccess) ?></p>
            </div>
            <div class="<?= $iconCls ?> bg-purple-50 dark:bg-purple-900/20">
              <svg class="w-5 h-5 text-purple-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <polyline points="9 12 11 14 15 10"/>
              </svg>
            </div>
          </div>
          <a href="<?= e(APP_URL) ?>/member" class="mt-3 text-xs font-medium flex items-center gap-1 text-[var(--brand-color)] hover:underline">
            Area Member <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>

        <?php if ($showPoints): $_dPts = pointsBalance((int) $_SESSION['user_id']); ?>
        <!-- Poin Reward -->
        <div class="<?= $cardCls(4) ?>">
          <div class="flex items-start justify-between">
            <div class="min-w-0">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Saldo <?= e(pointsLabel()) ?></p>
              <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($_dPts, 0, ',', '.') ?></p>
            </div>
            <div class="<?= $iconCls ?> bg-amber-50 dark:bg-amber-900/20">
              <svg class="w-5 h-5 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>
              </svg>
            </div>
          </div>
          <a href="<?= e(APP_URL) ?>/rewards" class="mt-3 text-xs font-medium flex items-center gap-1 text-[var(--brand-color)] hover:underline">
            Setara <?= e(formatRupiah(pointsToRupiah($_dPts))) ?> <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>
        <?php endif; ?>

      </div>

      <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

        <!-- Transaksi Terbaru (3/5) -->
        <div class="lg:col-span-3 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Transaksi Terbaru</h3>
            <a href="<?= e(APP_URL) ?>/transactions" class="text-xs text-[var(--brand-color)] hover:underline font-medium">Semua →</a>
          </div>
          <?php if (empty($recentOrders)): ?>
          <div class="py-10 text-center">
            <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada transaksi</p>
            <a href="<?= e(APP_URL) ?>/products" class="mt-1 text-xs text-[var(--brand-color)] hover:underline">Mulai belanja →</a>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($recentOrders as $o): ?>
            <a href="<?= e(APP_URL) ?>/transactions/<?= e($o['order_number']) ?>"
               class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
              <div class="flex-1 min-w-0">
                <p class="font-mono text-xs font-bold text-[var(--brand-color)]"><?= e($o['order_number']) ?></p>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5"><?= e($o['item_names'] ?? '-') ?></p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                  <?= (float)$o['total'] > 0 ? e(formatRupiah((float)$o['total'])) : 'Gratis' ?>
                </p>
                <?= renderStatusBadge($o['status'], 'order') ?>
              </div>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Produk Saya (2/5) -->
        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Produk Saya</h3>
            <a href="<?= e(APP_URL) ?>/member" class="text-xs text-[var(--brand-color)] hover:underline font-medium">Semua →</a>
          </div>
          <?php if (empty($myProducts)): ?>
          <div class="py-10 text-center">
            <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada produk</p>
            <a href="<?= e(APP_URL) ?>/products" class="mt-1 text-xs text-[var(--brand-color)] hover:underline">Jelajahi produk →</a>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($myProducts as $prod): ?>
            <a href="<?= e(APP_URL) ?>/member/<?= (int)$prod['id'] ?>"
               class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
              <?php if ($prod['thumbnail']): ?>
              <img src="<?= e($prod['thumbnail']) ?>" alt="" class="w-9 h-9 rounded-lg object-cover shrink-0">
              <?php else: ?>
              <div class="w-9 h-9 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="2" y="3" width="20" height="14" rx="2"/>
                </svg>
              </div>
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-gray-800 dark:text-gray-200 truncate"><?= e($prod['name']) ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 capitalize"><?= e(productCatalogLabel($prod)) ?></p>
              </div>
              <svg class="w-3.5 h-3.5 text-gray-300 dark:text-gray-700 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

      </div>

      <!-- Penawaran Spesial (upsell bertarget) — tampil bila diaktifkan admin & member punya penawaran -->
      <?php
      $memberOffers = (function_exists('memberOffersEnabled') && memberOffersEnabled())
          ? getMemberUpsellOffers($userId, 3) : [];
      if (!empty($memberOffers)):
      ?>
      <div class="mt-5 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/></svg>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white"><?= e(memberOffersTitle()) ?></h3>
          </div>
          <a href="<?= e(APP_URL) ?>/penawaran" class="text-xs font-medium text-[var(--brand-color)] hover:underline">Lihat semua →</a>
        </div>
        <div class="p-5">
          <?php
          $offers = $memberOffers;
          include __DIR__ . '/../../partials/_member-offers.php';
          ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Quick Actions -->
      <div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3">
        <?php
        $quickActions = [
            ['label' => 'Produk',     'href' => '/products',     'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4z'],
            ['label' => 'Transaksi',  'href' => '/transactions',  'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ];
        // Quick action Affiliate hanya saat program afiliasi aktif (pagar runtime).
        if (affiliateProgramEnabled()) {
            $quickActions[] = ['label' => 'Affiliate',  'href' => '/affiliate',     'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'];
        }
        $quickActions[] = ['label' => 'Profil',     'href' => '/profile',       'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'];
        ?>
        <?php foreach ($quickActions as $qa): ?>
        <a href="<?= e(APP_URL) ?><?= e($qa['href']) ?>"
           class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 flex flex-col items-center gap-2 hover:border-[var(--brand-color)] hover:shadow-md transition-all group">
          <div class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 group-hover:bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center transition-colors">
            <svg class="w-5 h-5 text-gray-500 dark:text-gray-400 group-hover:text-[var(--brand-color)] transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="<?= e($qa['icon']) ?>"/>
            </svg>
          </div>
          <span class="text-xs font-medium text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-white transition-colors"><?= e($qa['label']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/_member-offers-floating.php'; ?>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
