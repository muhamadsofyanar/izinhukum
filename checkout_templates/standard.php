<?php
// STANDARD CHECKOUT — 2-column layout (form left 3/5, summary right 2/5)
// Variables available from checkout.php:
// $product, $price, $origPrice, $discount, $isFree, $bumpOffers, $refCode,
// $duitkuActive, $xenditActive, $hasGateway, $couponError,
// $tplShowPhone, $tplShowAddress, $tplShowNotes
$_partials = __DIR__ . '/_partials';
?>
<div class="max-w-3xl mx-auto">

  <!-- Breadcrumb -->
  <nav class="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-600 mb-5">
    <a href="<?= e(APP_URL) ?>/products" class="hover:text-[var(--brand-color)] transition">Produk</a>
    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
    <a href="<?= e(APP_URL) ?>/products/<?= e($product['slug']) ?>" class="hover:text-[var(--brand-color)] transition truncate max-w-[160px]"><?= e($product['name']) ?></a>
    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
    <span class="text-gray-600 dark:text-gray-400">Checkout</span>
  </nav>

  <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

    <!-- ─── Order Summary (kanan) ─── -->
    <div class="lg:col-span-2 lg:order-2">
      <?php $summaryStickyDesktop = true; // kolom kanan desktop → boleh sticky ?>
      <?php include $_partials . '/order-summary-card.php'; ?>
    </div>

    <!-- ─── Form (kiri) ─── -->
    <div class="lg:col-span-3 lg:order-1 space-y-4">

      <!-- Info pembeli -->
      <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
        <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Informasi Pembeli</h4>
        <div class="flex items-start gap-3">
          <div class="w-9 h-9 rounded-full bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] flex items-center justify-center text-sm font-bold text-[var(--brand-color)] shrink-0">
            <?= e(strtoupper(substr($_SESSION['name'], 0, 1))) ?>
          </div>
          <div>
            <p class="font-medium text-gray-900 dark:text-white text-sm"><?= e($_SESSION['name']) ?></p>
            <p class="text-xs text-gray-500 dark:text-gray-400"><?= e($_SESSION['email']) ?></p>
          </div>
        </div>
      </div>

      <!-- Metode Pembayaran -->
      <?php include $_partials . '/payment-method.php'; ?>

      <!-- Form -->
      <form id="checkout-form" method="POST" action="<?= e(APP_URL) ?>/actions/checkout/process">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <input type="hidden" name="coupon_id" id="coupon_id_hidden" value="">
        <input type="hidden" name="discount_amount" id="discount_amount_hidden" value="0">
        <input type="hidden" name="bump_total" id="bump_total_hidden" value="0">
        <?php if ($refCode): ?>
        <input type="hidden" name="referral_code" value="<?= e($refCode) ?>">
        <?php endif; ?>

        <?php include __DIR__ . '/_partials/referral-badge.php'; ?>

        <div class="space-y-4">
          <?php include $_partials . '/coupon-field.php'; ?>
    <?php include $_partials . '/points-redeem.php'; ?>
          <?php include $_partials . '/flash-bonus.php'; ?>
          <?php include $_partials . '/bump-offers.php'; ?>

          <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-4">

            <?php if ($tplShowPhone): ?>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Nomor WhatsApp <span class="text-red-500">*</span>
              </label>
              <input type="tel" name="phone" required inputmode="numeric" value="<?= e($buyerPhone) ?>" maxlength="20"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                     placeholder="08xxxxxxxxxx">
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Info order &amp; akses dikirim ke WhatsApp ini.</p>
            </div>
            <?php endif; ?>

            <?php if ($tplShowAddress): ?>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Alamat Pengiriman <span class="text-xs text-gray-400 font-normal">(opsional)</span>
              </label>
              <textarea name="address" rows="2" maxlength="500"
                        class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
                        placeholder="Alamat lengkap pengiriman..."></textarea>
            </div>
            <?php endif; ?>

            <?php if ($tplShowNotes): ?>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Catatan <span class="text-xs text-gray-400 font-normal">(opsional)</span>
              </label>
              <textarea name="notes" rows="2" maxlength="500"
                        class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
                        placeholder="Pesan atau catatan untuk admin..."></textarea>
            </div>
            <?php endif; ?>

            <button type="submit" id="checkout-submit-btn" data-loading data-loading-text="Memproses..."
                    class="w-full py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
                    style="background-color: var(--brand-color);">
              <span class="inline-flex items-center justify-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                <?= $isFree ? 'Dapatkan Gratis Sekarang' : 'Buat Order & Bayar' ?>
              </span>
            </button>
            <div id="gateway-error" class="hidden mt-3 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-sm text-red-700 dark:text-red-400"></div>
            <div class="flex justify-center mt-3">
              <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-50 text-[11px] whitespace-nowrap max-w-full">
                <svg class="w-3 h-3 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                <span class="min-w-0 truncate text-gray-600"><span class="font-semibold text-emerald-600">Akses dikirim otomatis</span> setelah pembayaran berhasil.</span>
              </span>
            </div>
          </div>
        </div>
      </form>

    </div>
  </div>
</div>
