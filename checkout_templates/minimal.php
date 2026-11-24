<?php
// MINIMAL CHECKOUT — single column, price big at top, impulse-buy focused
// Variables available from checkout.php (same scope)
$_partials = __DIR__ . '/_partials';
?>
<div class="max-w-lg mx-auto">

  <!-- Product + Price Header -->
  <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-6 mb-4 text-center">
    <?php if ($product['thumbnail']): ?>
    <img src="<?= e($product['thumbnail']) ?>" alt="" class="w-16 h-16 rounded-lg object-cover mx-auto mb-3">
    <?php else: ?>
    <div class="w-16 h-16 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center mx-auto mb-3">
      <svg class="w-7 h-7 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="2" y="3" width="20" height="14" rx="2"/>
      </svg>
    </div>
    <?php endif; ?>
    <h2 class="font-bold text-gray-900 dark:text-white text-base mb-1"><?= e($product['name']) ?></h2>
    <?php if ($product['short_description']): ?>
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3"><?= e($product['short_description']) ?></p>
    <?php endif; ?>

    <?php if ($isFree): ?>
    <div class="text-3xl font-black text-green-600 dark:text-green-400">GRATIS</div>
    <?php else: ?>
    <div class="flex items-baseline justify-center gap-2">
      <div id="order-total-display" class="text-3xl font-black text-[var(--brand-color)]"><?= e(formatRupiah($price + ($setupFee ?? 0))) ?></div>
      <?php if ($origPrice > $price): ?>
      <div class="text-sm text-gray-400 line-through"><?= e(formatRupiah($origPrice)) ?></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($pMyr)): ?>
    <div class="flex justify-center mt-1.5"><?= ringgitBadge($pMyr) ?></div>
    <?php endif; ?>
    <?php if (($setupFee ?? 0) > 0): ?>
    <p class="text-center text-xs text-gray-500 dark:text-gray-400 mt-1.5">Termasuk biaya setup awal <?= e(formatRupiah($setupFee)) ?> (sekali, pembelian pertama)</p>
    <?php endif; ?>
    <?php if ((int) ($product['access_duration_days'] ?? 0) > 0): ?>
    <p class="text-center text-xs text-gray-400 dark:text-gray-500 mt-1">Langganan · perpanjang <?= e(formatRupiah($price)) ?> / <?= e(subscriptionPeriodLabel((int) $product['access_duration_days'])) ?></p>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Hidden coupon/bump rows for JS compat -->
    <div id="coupon-summary-row" class="hidden mt-2 text-sm text-green-600">
      Diskon kupon: <span id="coupon-discount-display">-Rp 0</span>
    </div>
    <div id="bump-summary-row" class="hidden mt-1 text-sm text-gray-600">
      Tambahan: <span id="bump-total-display">+Rp 0</span>
    </div>

    <?php if ($product['access_duration_days'] > 0): ?>
    <p class="text-xs text-gray-400 mt-2">Akses <?= (int)$product['access_duration_days'] ?> hari</p>
    <?php else: ?>
    <p class="text-xs text-green-600 dark:text-green-400 mt-2 flex items-center justify-center gap-1">
      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
      Akses Seumur Hidup
    </p>
    <?php endif; ?>

    <!-- Pilihan Booking Fee / DP (tampil hanya bila produk mengaktifkannya) -->
    <?php include $_partials . '/booking-mode-picker.php'; ?>
  </div>

  <!-- Bump Offers -->
  <?php include $_partials . '/flash-bonus.php'; ?>
  <?php include $_partials . '/bump-offers.php'; ?>

  <!-- Form -->
  <form id="checkout-form" method="POST" action="<?= e(APP_URL) ?>/actions/checkout/process" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
    <input type="hidden" name="coupon_id" id="coupon_id_hidden" value="">
    <input type="hidden" name="discount_amount" id="discount_amount_hidden" value="0">
    <input type="hidden" name="bump_total" id="bump_total_hidden" value="0">
    <?php if ($refCode): ?>
    <input type="hidden" name="referral_code" value="<?= e($refCode) ?>">
    <?php endif; ?>

    <?php include __DIR__ . '/_partials/referral-badge.php'; ?>

    <!-- Buyer compact -->
    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-4">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center text-sm font-bold text-[var(--brand-color)] shrink-0">
          <?= e(strtoupper(substr($_SESSION['name'], 0, 1))) ?>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-gray-900 dark:text-white"><?= e($_SESSION['name']) ?></p>
          <p class="text-xs text-gray-400"><?= e($_SESSION['email']) ?></p>
        </div>
      </div>
    </div>

    <!-- Coupon -->
    <?php include $_partials . '/coupon-field.php'; ?>
    <?php include $_partials . '/points-redeem.php'; ?>

    <!-- Payment method compact -->
    <?php if (!$isFree): ?>
    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
      <?php include $_partials . '/payment-method-compact.php'; ?>
    </div>
    <?php endif; ?>

    <?php if ($tplShowPhone || $tplShowAddress || $tplShowNotes): ?>
    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-3">
      <?php if ($tplShowPhone): ?>
      <div>
        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nomor WhatsApp <span class="text-red-500">*</span></label>
        <input type="tel" name="phone" required inputmode="numeric" value="<?= e($buyerPhone) ?>" maxlength="20"
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
               placeholder="08xxxxxxxxxx">
        <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Info order dikirim ke WhatsApp ini.</p>
      </div>
      <?php endif; ?>
      <?php if ($tplShowAddress): ?>
      <div>
        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Alamat <span class="font-normal text-gray-400">(opsional)</span></label>
        <textarea name="address" rows="2" maxlength="500"
                  class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"></textarea>
      </div>
      <?php endif; ?>
      <?php if ($tplShowNotes): ?>
      <div>
        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Catatan <span class="font-normal text-gray-400">(opsional)</span></label>
        <textarea name="notes" rows="2" maxlength="500"
                  class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
                  placeholder="Catatan untuk admin..."></textarea>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <button type="submit" id="checkout-submit-btn" data-loading data-loading-text="Memproses..."
            class="w-full py-3.5 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
            style="background-color: var(--brand-color);">
      <span class="inline-flex items-center justify-center gap-2">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        <?= $isFree ? 'Dapatkan Gratis Sekarang' : 'Bayar Sekarang' ?>
      </span>
    </button>
    <div id="gateway-error" class="hidden p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-400"></div>
    <div class="flex justify-center mt-1">
      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-50 text-[11px] whitespace-nowrap max-w-full">
        <svg class="w-3 h-3 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        <span class="min-w-0 truncate text-gray-600"><span class="font-semibold text-emerald-600">Akses dikirim otomatis</span> setelah pembayaran berhasil.</span>
      </span>
    </div>
  </form>

</div>
