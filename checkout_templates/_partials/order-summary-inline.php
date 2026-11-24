<?php
// Compact inline order summary — used in minimal and two-step templates
// Variables: $product, $price, $origPrice, $discount, $isFree
?>
<div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-700 p-4">
  <div class="flex items-center gap-3 mb-3">
    <?php if ($product['thumbnail']): ?>
    <img src="<?= e($product['thumbnail']) ?>" alt="" class="w-10 h-10 rounded-lg object-cover shrink-0">
    <?php else: ?>
    <div class="w-10 h-10 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center shrink-0">
      <svg class="w-5 h-5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="2" y="3" width="20" height="14" rx="2"/>
      </svg>
    </div>
    <?php endif; ?>
    <div class="flex-1 min-w-0">
      <p class="text-xs text-[var(--brand-color)] font-medium uppercase tracking-wider"><?= e(productCatalogLabel($product)) ?></p>
      <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($product['name']) ?></p>
    </div>
  </div>

  <div class="space-y-1.5 text-sm">
    <?php if ($origPrice > $price): ?>
    <div class="flex justify-between">
      <span class="text-gray-400">Harga normal</span>
      <span class="text-gray-400 line-through"><?= e(formatRupiah($origPrice)) ?></span>
    </div>
    <div class="flex justify-between">
      <span class="text-gray-400">Diskon</span>
      <span class="text-green-600 dark:text-green-400 font-medium">-<?= e(formatRupiah($discount)) ?></span>
    </div>
    <?php endif; ?>
    <div id="coupon-summary-row" class="flex justify-between hidden">
      <span class="text-gray-400">Diskon kupon</span>
      <span id="coupon-discount-display" class="text-green-600 dark:text-green-400 font-medium">-Rp 0</span>
    </div>
    <div id="points-summary-row" class="flex justify-between hidden">
      <span class="text-gray-400">Diskon <?= function_exists('pointsLabel') ? e(strtolower(pointsLabel())) : 'poin' ?> <span id="points-used-display"></span></span>
      <span id="points-discount-display" class="text-green-600 dark:text-green-400 font-medium">-Rp 0</span>
    </div>
    <div id="bump-summary-row" class="flex justify-between hidden">
      <span class="text-gray-400">Tambahan produk</span>
      <span id="bump-total-display" class="text-gray-700 dark:text-gray-300 font-medium">+Rp 0</span>
    </div>
    <?php if (($setupFee ?? 0) > 0): ?>
    <div class="flex justify-between">
      <span class="text-gray-400">Biaya setup awal <span class="text-[11px]">(sekali)</span></span>
      <span class="text-gray-700 dark:text-gray-300 font-medium">+<?= e(formatRupiah($setupFee)) ?></span>
    </div>
    <?php endif; ?>
    <div class="flex justify-between font-bold pt-1.5 border-t border-gray-200 dark:border-gray-700">
      <span class="text-gray-900 dark:text-white">Total</span>
      <?php if ($isFree): ?>
      <span class="text-green-600">GRATIS</span>
      <?php else: ?>
      <span id="order-total-display" class="text-[var(--brand-color)]"><?= !empty($isFlexible) ? 'Pilih nominal' : e(formatRupiah($price + ($setupFee ?? 0))) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($pMyr)): ?>
    <div class="flex justify-end pt-1"><?= ringgitBadge($pMyr) ?></div>
    <?php endif; ?>
    <?php if (!$isFree && (int) ($product['access_duration_days'] ?? 0) > 0): ?>
    <p class="text-[11px] text-gray-400 dark:text-gray-500">Langganan · perpanjang <?= e(formatRupiah($price)) ?> / <?= e(subscriptionPeriodLabel((int) $product['access_duration_days'])) ?></p>
    <?php endif; ?>
    <?php include __DIR__ . '/booking-mode-picker.php'; ?>
  </div>
</div>
