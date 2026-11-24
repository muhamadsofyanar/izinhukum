<?php
// ─── Katalog produk untuk member (etalase di dalam dashboard) ────────────
// Menampilkan produk aktif + estimasi potongan poin yang bisa dipakai member ini.
// Read-only: tombol Beli mengarah ke checkout login yang sudah ada.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$userId   = (int) $_SESSION['user_id'];
$usePoint = function_exists('pointsEnabled') && pointsEnabled();
$balance  = $usePoint ? pointsBalance($userId) : 0;
$label    = $usePoint ? pointsLabel() : 'Poin';

$products = [];
try {
    // Produk yang layak dipajang: aktif, tidak disembunyikan, sudah rilis.
    // Sertakan info salespage (lp_type/canvas/external) agar tombol Salespage
    // mengarah ke landing yang benar — termasuk Canvas (/{canvas_slug}/).
    $st = getDB()->query(
        "SELECT p.id, p.name, p.slug, p.short_description, p.thumbnail, p.price, p.original_price,
                p.pricing_type, p.checkout_mode, p.lp_type, p.landing_page_url, p.canvas_id,
                cp.slug AS canvas_slug
         FROM products p
         LEFT JOIN canvas_pages cp ON cp.id = p.canvas_id AND cp.is_active = 1
         WHERE p.status = 'active'
           AND COALESCE(p.hide_from_catalog, 0) = 0
           AND (p.launch_at IS NULL OR p.launch_at <= NOW())
         ORDER BY p.sort_order ASC, p.created_at DESC"
    );
    $products = $st->fetchAll();
} catch (Throwable $e) {
    // Fallback skema lama (tanpa kolom LP/Canvas atau tabel canvas_pages) —
    // katalog tetap tampil, tombol Salespage memakai halaman produk bawaan.
    error_log('catalog page (rich): ' . $e->getMessage());
    try {
        $st = getDB()->query(
            "SELECT id, name, slug, short_description, thumbnail, price, original_price,
                    pricing_type, checkout_mode
             FROM products
             WHERE status = 'active'
               AND COALESCE(hide_from_catalog, 0) = 0
               AND (launch_at IS NULL OR launch_at <= NOW())
             ORDER BY sort_order ASC, created_at DESC"
        );
        $products = $st->fetchAll();
    } catch (Throwable $e2) {
        error_log('catalog page (fallback): ' . $e2->getMessage());
    }
}

$pageTitle   = 'Katalog';
$topbarTitle = 'Katalog';

include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-6xl mx-auto">
        <div class="flex items-start justify-between gap-4 flex-wrap mb-5">
          <div>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Katalog</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Semua produk yang tersedia untukmu.</p>
          </div>
          <?php if ($usePoint && $balance > 0): ?>
          <a href="<?= e(APP_URL) ?>/rewards" class="shrink-0 inline-flex items-center gap-2 px-4 h-11 rounded-xl text-white" style="background:linear-gradient(135deg,var(--brand-color),color-mix(in srgb,var(--brand-color) 55%,#000))">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/></svg>
            <span class="text-left leading-tight">
              <span class="block text-[10px] uppercase tracking-widest" style="opacity:.85">Saldo <?= e($label) ?></span>
              <span class="block text-sm font-bold"><?= number_format($balance, 0, ',', '.') ?> <?= e($label) ?></span>
            </span>
          </a>
          <?php endif; ?>
        </div>

        <?php if (empty($products)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-12 text-center">
          <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-3">
            <svg class="w-6 h-6 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 9h18M3 9l1.5-4.5A2 2 0 0 1 6.4 3h11.2a2 2 0 0 1 1.9 1.5L21 9M3 9v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9"/></svg>
          </div>
          <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Belum ada produk</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <?php foreach ($products as $p):
            $pid    = (int) $p['id'];
            $slug   = trim((string) ($p['slug'] ?? ''));
            $price  = (float) $p['price'];
            $orig   = (float) ($p['original_price'] ?? 0);
            $owned  = hasAccess($userId, $pid);
            // URL salespage: ikut lp_type produk (Canvas > URL eksternal > halaman produk).
            // Logika sama dengan pages/public/products.php & affiliate-landing-redirect.php.
            $lpType     = $p['lp_type'] ?? 'none';
            $spExternal = false;
            $spUrl      = '';
            if ($lpType === 'canvas' && !empty($p['canvas_slug'])) {
                $spUrl = rtrim(APP_URL, '/') . '/' . rawurlencode($p['canvas_slug']) . '/';
            } elseif ($lpType === 'external' && !empty($p['landing_page_url'])) {
                $spUrl = (string) $p['landing_page_url'];
                $spExternal = true;
            } elseif ($slug !== '') {
                $spUrl = rtrim(APP_URL, '/') . '/products/' . rawurlencode($slug);
            }
            // Estimasi potongan poin untuk produk ini (pakai aturan yang SAMA
            // dengan checkout agar angka di layar tak pernah beda dgn yang dipotong).
            $plan   = ($usePoint && !$owned && $price > 0) ? pointsPlanRedeem($balance, $price, 0.0) : ['points' => 0, 'discount' => 0.0];
          ?>
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden flex flex-col">
            <div class="relative bg-gray-100 dark:bg-gray-800" style="aspect-ratio:16/10">
              <?php if (!empty($p['thumbnail'])): ?>
              <img src="<?= e($p['thumbnail']) ?>" alt="<?= e($p['name']) ?>" loading="lazy" decoding="async"
                   class="w-full h-full object-cover" style="display:block">
              <?php else: ?>
              <div class="w-full h-full flex items-center justify-center">
                <svg class="w-8 h-8 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
              </div>
              <?php endif; ?>
              <?php if ($owned): ?>
              <span class="absolute top-2 left-2 inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold text-white" style="background:#059669">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                Sudah Dimiliki
              </span>
              <?php endif; ?>
            </div>

            <div class="p-4 flex flex-col flex-1">
              <p class="text-sm font-semibold text-gray-900 dark:text-white line-clamp-2"><?= e($p['name']) ?></p>
              <?php if (!empty($p['short_description'])): ?>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2"><?= e($p['short_description']) ?></p>
              <?php endif; ?>

              <div class="mt-3 flex items-end justify-between gap-2">
                <div class="min-w-0">
                  <?php if ($orig > $price): ?>
                  <p class="text-[11px] text-gray-400 line-through"><?= e(formatRupiah($orig)) ?></p>
                  <?php endif; ?>
                  <p class="text-base font-bold text-gray-900 dark:text-white"><?= $price > 0 ? e(formatRupiah($price)) : 'Gratis' ?></p>
                </div>
              </div>

              <?php if ($plan['discount'] > 0): ?>
              <p class="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded"
                 style="background:rgba(16,185,129,.12);color:#047857">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/></svg>
                Hemat <?= e(formatRupiah($plan['discount'])) ?> pakai <?= number_format($plan['points'], 0, ',', '.') ?> <?= e($label) ?>
              </p>
              <?php endif; ?>

              <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800">
                <?php if ($owned): ?>
                <a href="<?= e(APP_URL) ?>/member" class="w-full inline-flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                  Buka Konten
                </a>
                <?php else: ?>
                <a href="<?= e(APP_URL) ?>/checkout?product_id=<?= $pid ?>" class="w-full inline-flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                  Beli Sekarang
                </a>
                <?php endif; ?>
                <?php if ($spUrl !== ''): ?>
                <a href="<?= e($spUrl) ?>" target="_blank" rel="<?= $spExternal ? 'noopener nofollow' : 'noopener' ?>"
                   class="mt-2 w-full inline-flex items-center justify-center gap-2 h-10 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                  Lihat Salespage
                </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
