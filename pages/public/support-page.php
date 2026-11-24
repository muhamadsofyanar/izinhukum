<?php
// Halaman Dukung (Bayar Fleksibel Fase 2) — halaman support turnkey milik admin,
// satu per situs. Slug & aktivasi diatur di /admin/support-page. Router (index.php)
// hanya me-load file ini bila support_page_enabled = '1'. Reuse mesin checkout/order
// Fase 1: kartu tip = produk flexible ber-flag show_on_support; Supporters Wall =
// query order paid dari produk tsb (render server-side, nama dimasking).
require_once __DIR__ . '/../../bootstrap.php';

$pdo = getDB();

// Guard ganda (selain router) — bila diakses langsung.
if (getSetting('support_page_enabled', '0') !== '1') {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}

$appName    = getSetting('app_name', 'STIFIn Mulia');
$appLogo    = getSetting('app_logo', '');
$bio        = trim((string) getSetting('support_bio', ''));
$badgeText  = trim((string) getSetting('support_badge_text', 'Halaman Dukungan'));
$headline   = trim((string) getSetting('support_headline', '')) ?: ('Dukung karya ' . $appName);
$showAmount = getSetting('support_show_amount', '1') === '1';

// Social links (opsional).
$socials = array_filter([
    'instagram' => trim((string) getSetting('support_social_instagram', '')),
    'youtube'   => trim((string) getSetting('support_social_youtube', '')),
    'tiktok'    => trim((string) getSetting('support_social_tiktok', '')),
    'website'   => trim((string) getSetting('support_social_website', '')),
]);

// ─── Kartu tip = produk flexible ber-flag show_on_support ─────
$tipCards = [];
try {
    $st = $pdo->query(
        "SELECT id, name, slug, short_description, thumbnail, checkout_mode,
                flexible_label, flexible_min, flexible_presets
         FROM products
         WHERE pricing_type = 'flexible' AND show_on_support = 1 AND status = 'active'
         ORDER BY id DESC"
    );
    $tipCards = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('support-page tip cards: ' . $e->getMessage());
}

// ─── Supporters Wall + jumlah pendukung ──────────────────────
$wall = [];
$supporterCount = 0;
try {
    $st = $pdo->query(
        "SELECT o.id, o.total, o.created_at, o.supporter_message,
                o.supporter_anonymous, o.supporter_message_hidden, u.name AS buyer_name
         FROM orders o
         JOIN users u        ON u.id = o.user_id
         JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
         JOIN products p     ON p.id = oi.product_id
         WHERE o.status = 'paid' AND p.pricing_type = 'flexible' AND p.show_on_support = 1
         GROUP BY o.id
         ORDER BY o.created_at DESC
         LIMIT 60"
    );
    $wall = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cStmt = $pdo->query(
        "SELECT COUNT(DISTINCT o.id)
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
         JOIN products p     ON p.id = oi.product_id
         WHERE o.status = 'paid' AND p.pricing_type = 'flexible' AND p.show_on_support = 1"
    );
    $supporterCount = (int) $cStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('support-page wall: ' . $e->getMessage());
}

// Pra-hitung nama tampil (masking server-side) untuk wall + tumpukan avatar.
foreach ($wall as &$w) {
    $w['display_name'] = supporterDisplayName($w['buyer_name'] ?? null, (int) $w['supporter_anonymous'] === 1);
}
unset($w);

$pageTitle = 'Dukung ' . $appName;
$svgIcon = [
    'instagram' => '<path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.3 2.2.4.6.2 1 .4 1.4.9.5.4.7.8.9 1.4.1.4.3 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.3 1.8-.4 2.2-.2.6-.4 1-.9 1.4-.4.5-.8.7-1.4.9-.4.1-1 .3-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.3-2.2-.4-.6-.2-1-.4-1.4-.9-.5-.4-.7-.8-.9-1.4-.1-.4-.3-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.3-1.8.4-2.2.2-.6.4-1 .9-1.4.4-.5.8-.7 1.4-.9.4-.1 1-.3 2.2-.4C8.4 2.2 8.8 2.2 12 2.2zm0 3.4a6.4 6.4 0 100 12.8 6.4 6.4 0 000-12.8zm0 10.6a4.2 4.2 0 110-8.4 4.2 4.2 0 010 8.4zm6.6-10.9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>',
    'youtube'   => '<path d="M23 7.5s-.2-1.6-.9-2.3c-.9-.9-1.8-.9-2.3-1C16.5 4 12 4 12 4s-4.5 0-7.8.2c-.5.1-1.4.1-2.3 1-.7.7-.9 2.3-.9 2.3S.8 9.4.8 11.3v1.4c0 1.9.2 3.8.2 3.8s.2 1.6.9 2.3c.9.9 2 .9 2.5 1 1.8.2 7.6.2 7.6.2s4.5 0 7.8-.2c.5-.1 1.4-.1 2.3-1 .7-.7.9-2.3.9-2.3s.2-1.9.2-3.8v-1.4c0-1.9-.2-3.8-.2-3.8zM9.6 15.1V8.9l6 3.1-6 3.1z"/>',
    'tiktok'    => '<path d="M16.5 2h-3v13.3a2.6 2.6 0 11-2.6-2.6c.2 0 .4 0 .6.1V9.7a5.9 5.9 0 00-.6 0 5.7 5.7 0 105.7 5.7V8.4a7 7 0 004.1 1.3V6.6a4.1 4.1 0 01-4.1-4.1 4 4 0 010-.5z"/>',
    'website'   => '<path d="M12 2a10 10 0 100 20 10 10 0 000-20zm6.9 6h-2.9a15.7 15.7 0 00-1.3-3.4A8 8 0 0118.9 8zM12 4c.8 1.1 1.4 2.5 1.8 4h-3.6c.4-1.5 1-2.9 1.8-4zM4.3 14a7.8 7.8 0 010-4h3.3a17 17 0 000 4H4.3zm.8 2h2.9c.3 1.2.8 2.4 1.3 3.4A8 8 0 015.1 16zm2.9-8H5.1a8 8 0 014.2-3.4A15.7 15.7 0 008 8zm4 12c-.8-1.1-1.4-2.5-1.8-4h3.6c-.4 1.5-1 2.9-1.8 4zm2.2-6H9.8a15 15 0 010-4h4.4a15 15 0 010 4zm.5 5.4c.5-1 1-2.2 1.3-3.4h2.9a8 8 0 01-4.2 3.4zM16.4 14a17 17 0 000-4h3.3a7.8 7.8 0 010 4h-3.3z"/>',
];
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="min-h-screen">
  <div class="max-w-2xl mx-auto px-4 py-10 sm:py-14">

    <!-- Header kreator -->
    <div class="mb-9">
      <div class="flex items-center justify-between gap-3 mb-5">
        <?php if ($badgeText !== ''): ?>
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold" style="color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 10%, transparent);">
          <svg viewBox="0 0 24 24" fill="currentColor" class="w-3.5 h-3.5" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
          <?= e($badgeText) ?>
        </span>
        <?php else: ?>
        <span></span>
        <?php endif; ?>
        <?php if ($appLogo !== ''): ?>
          <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>" class="h-11 w-11 rounded-xl object-contain shrink-0">
        <?php else: ?>
          <div class="h-11 w-11 rounded-xl flex items-center justify-center text-sm font-bold shrink-0 ring-1 ring-inset ring-gray-200 dark:ring-gray-800" style="color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 10%, transparent);"><?= e(mb_strtoupper(mb_substr($appName, 0, 2))) ?></div>
        <?php endif; ?>
      </div>

      <h1 class="text-3xl font-bold text-gray-900 dark:text-white leading-tight tracking-tight"><?= e($headline) ?></h1>
      <?php if ($bio !== ''): ?>
      <p class="text-[15px] text-gray-600 dark:text-gray-400 mt-2.5 leading-relaxed whitespace-pre-line max-w-xl"><?= e($bio) ?></p>
      <?php endif; ?>
      <?php if ($socials): ?>
      <div class="flex items-center gap-2 mt-4">
        <?php foreach ($socials as $net => $url): ?>
        <a href="<?= e($url) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= e(ucfirst($net)) ?>"
           class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] hover:border-[var(--brand-color)] transition">
          <svg viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4" aria-hidden="true"><?= $svgIcon[$net] ?? '' ?></svg>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Kartu tip -->
    <?php if ($tipCards): ?>
    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3 uppercase tracking-wide">Pilih dukunganmu</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-10">
      <?php foreach ($tipCards as $c):
        $label   = trim((string) $c['flexible_label']) ?: 'Bayar Seikhlasnya';
        $min     = (float) $c['flexible_min'];
        $presets = array_values(array_filter(array_map('intval', explode(',', (string) $c['flexible_presets'])), fn($n) => $n > 0));
        // Halaman Dukung SELALU arahkan ke form publik (/order/{slug}) agar pendukung bisa
        // bayar TANPA akun. Pengunjung yang sudah login otomatis dialihkan ke checkout
        // login oleh public-checkout.php. Produk Dukung (flexible+show_on_support) kini
        // diizinkan guest-checkout walau checkout_mode='login'.
        $href    = rtrim(APP_URL, '/') . '/order/' . rawurlencode($c['slug']);
      ?>
      <a href="<?= e($href) ?>" class="group block bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 hover:border-[var(--brand-color)] transition">
        <div class="rounded-md p-3 mb-3" style="background: color-mix(in srgb, var(--brand-color) 8%, transparent);">
          <div class="text-[11px] font-medium mb-2" style="color: var(--brand-color);">Pilih nominal<?php if ($min > 0): ?> &middot; min <?= e(formatRupiah($min)) ?><?php endif; ?></div>
          <div class="flex flex-wrap gap-1.5">
            <?php if ($presets): foreach ($presets as $i => $amt): ?>
              <span class="text-[11px] rounded px-2 py-1 <?= $i === count($presets) - 1 ? 'text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700' ?>"<?= $i === count($presets) - 1 ? ' style="background: var(--brand-color);"' : '' ?>>Rp<?= e(formatRupiahShort((float) $amt)) ?></span>
            <?php endforeach; else: ?>
              <span class="text-[11px] rounded px-2 py-1 bg-white dark:bg-gray-800 text-gray-500 dark:text-gray-400 border border-dashed border-gray-300 dark:border-gray-600">Nominal bebas</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-sm font-semibold text-gray-900 dark:text-white group-hover:text-[var(--brand-color)] transition"><?= e($c['name']) ?></span>
        </div>
        <?php if (!empty($c['short_description'])): ?>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2"><?= e($c['short_description']) ?></p>
        <?php endif; ?>
        <div class="text-[11px] font-medium mt-2 inline-flex items-center gap-1" style="color: var(--brand-color);">
          <?= e($label) ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Banner jumlah pendukung -->
    <?php if ($supporterCount > 0): ?>
    <div class="rounded-lg p-5 mb-6 flex items-center justify-between gap-4 flex-wrap" style="background: var(--brand-color);">
      <div class="min-w-0">
        <div class="text-lg font-bold text-white"><?= number_format($supporterCount, 0, ',', '.') ?> orang sudah mendukung</div>
        <div class="text-xs mt-0.5" style="color: rgba(255,255,255,0.85);">Terima kasih atas dukungannya.</div>
      </div>
      <div class="flex -space-x-2 shrink-0">
        <?php foreach (array_slice($wall, 0, 4) as $w): ?>
        <div class="w-9 h-9 rounded-full bg-white flex items-center justify-center text-[11px] font-semibold border-2" style="color: var(--brand-color); border-color: var(--brand-color);"><?= e(supporterInitials($w['display_name'])) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Supporters Wall -->
    <?php if ($wall): ?>
    <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3 uppercase tracking-wide">Supporters Wall</div>
    <div class="space-y-2.5">
      <?php foreach ($wall as $w):
        $name    = $w['display_name'];
        $isAnon  = (int) $w['supporter_anonymous'] === 1;
        $msg     = ((int) $w['supporter_message_hidden'] === 0) ? trim((string) $w['supporter_message']) : '';
      ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-4 flex gap-3">
        <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-semibold shrink-0 <?= $isAnon ? 'bg-gray-100 dark:bg-gray-800 text-gray-400' : '' ?>"<?= $isAnon ? '' : ' style="background: color-mix(in srgb, var(--brand-color) 12%, transparent); color: var(--brand-color);"' ?>>
          <?php if ($isAnon): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4" aria-hidden="true"><path d="M20 21a8 8 0 10-16 0M12 11a4 4 0 100-8 4 4 0 000 8z" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <?php else: ?>
            <?= e(supporterInitials($name)) ?>
          <?php endif; ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-baseline gap-2 flex-wrap">
            <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= e($name) ?></span>
            <?php if ($showAmount): ?>
            <span class="text-xs font-medium" style="color: var(--brand-color);"><?= e(formatRupiah((float) $w['total'])) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($msg !== ''): ?>
          <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 leading-relaxed break-words"><?= e($msg) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php elseif (!$tipCards): ?>
    <div class="text-center py-16">
      <p class="text-sm text-gray-500 dark:text-gray-400">Halaman dukungan belum siap. Kembali lagi nanti.</p>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
