<?php
// Variables expected from checkout.php scope:
// $product, $price, $origPrice, $discount, $isFree
// No direct output buffering here — rendered inline.
?>
<?php
// $summaryStickyDesktop (dari template pemanggil): sticky HANYA di desktop bila kartu
// ini benar-benar di kolom sidebar (standard/sidebar_sticky). Di layout kolom tunggal
// (two_step) atau di mobile, sticky membuat kartu menutupi konten di bawahnya saat
// scroll — jadi jangan sticky. `lg:sticky` + `top-6` (top-6 hanya berlaku saat sticky).
$_summarySticky = !empty($summaryStickyDesktop) ? 'lg:sticky top-6' : '';
?>
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden <?= $_summarySticky ?>">

  <?php if ($product['thumbnail']): ?>
  <img src="<?= e($product['thumbnail']) ?>" alt="<?= e($product['name']) ?>"
       class="w-full h-36 object-cover">
  <?php else: ?>
  <div class="w-full h-36 bg-gradient-to-br from-[var(--brand-color)] to-[var(--brand-color-light)] flex items-center justify-center">
    <svg class="w-12 h-12 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
      <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
    </svg>
  </div>
  <?php endif; ?>

  <div class="p-4">
    <p class="text-xs font-medium text-[var(--brand-color)] uppercase tracking-wider mb-1"><?= e(productCatalogLabel($product)) ?></p>
    <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-tight"><?= e($product['name']) ?></h3>
    <?php if ($product['short_description']): ?>
    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed"><?= e($product['short_description']) ?></p>
    <?php endif; ?>

    <?php if ($product['access_duration_days'] > 0): ?>
    <div class="mt-3 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
      </svg>
      Akses <?= (int)$product['access_duration_days'] ?> hari
    </div>
    <?php else: ?>
    <div class="mt-3 flex items-center gap-1.5 text-xs text-green-600 dark:text-green-400">
      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
      Akses Seumur Hidup
    </div>
    <?php endif; ?>

    <div class="border-t border-gray-100 dark:border-gray-800 mt-4 pt-4 space-y-2">
      <?php if ($origPrice > $price): ?>
      <div class="flex justify-between text-sm">
        <span class="text-gray-500 dark:text-gray-400">Harga normal</span>
        <span class="text-gray-400 line-through"><?= e(formatRupiah($origPrice)) ?></span>
      </div>
      <div class="flex justify-between text-sm">
        <span class="text-gray-500 dark:text-gray-400">Diskon harga</span>
        <span class="text-green-600 dark:text-green-400 font-medium">-<?= e(formatRupiah($discount)) ?></span>
      </div>
      <?php endif; ?>
      <div id="coupon-summary-row" class="flex justify-between text-sm hidden">
        <span class="text-gray-500 dark:text-gray-400">Diskon kupon</span>
        <span id="coupon-discount-display" class="text-green-600 dark:text-green-400 font-medium">-Rp 0</span>
      </div>
      <div id="points-summary-row" class="flex justify-between text-sm hidden">
        <span class="text-gray-500 dark:text-gray-400">Diskon <?= function_exists('pointsLabel') ? e(strtolower(pointsLabel())) : 'poin' ?> <span id="points-used-display" class="text-gray-400"></span></span>
        <span id="points-discount-display" class="text-green-600 dark:text-green-400 font-medium">-Rp 0</span>
      </div>
      <div id="bump-summary-row" class="flex justify-between text-sm hidden">
        <span class="text-gray-500 dark:text-gray-400">Tambahan produk</span>
        <span id="bump-total-display" class="text-gray-700 dark:text-gray-300 font-medium">+Rp 0</span>
      </div>
      <?php if (($setupFee ?? 0) > 0): ?>
      <div class="flex justify-between text-sm">
        <span class="text-gray-500 dark:text-gray-400">Biaya setup awal <span class="text-[11px]">(sekali)</span></span>
        <span class="text-gray-700 dark:text-gray-300 font-medium">+<?= e(formatRupiah($setupFee)) ?></span>
      </div>
      <?php endif; ?>
      <div class="flex justify-between font-bold text-base pt-1 border-t border-gray-100 dark:border-gray-800">
        <span class="text-gray-900 dark:text-white">Total</span>
        <?php if ($isFree): ?>
        <span class="text-green-600 dark:text-green-400">GRATIS</span>
        <?php else: ?>
        <span id="order-total-display" class="text-[var(--brand-color)]"><?= !empty($isFlexible) ? 'Pilih nominal' : e(formatRupiah($price + ($setupFee ?? 0))) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($pMyr)): ?>
      <div class="flex justify-end pt-1"><?= ringgitBadge($pMyr) ?></div>
      <?php endif; ?>
      <?php if (!$isFree && (int) ($product['access_duration_days'] ?? 0) > 0): ?>
      <p class="text-[11px] text-gray-400 dark:text-gray-500 pt-1">Langganan · perpanjang <?= e(formatRupiah($price)) ?> / <?= e(subscriptionPeriodLabel((int) $product['access_duration_days'])) ?></p>
      <?php endif; ?>
      <?php include __DIR__ . '/booking-mode-picker.php'; ?>
    </div>
  </div>
</div>
