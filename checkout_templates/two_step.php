<?php
// TWO-STEP CHECKOUT — wizard with step indicator
// Step 1: Data pembeli + Kupon + Bumps
// Step 2: Metode pembayaran + Ringkasan + Submit
// CSS show/hide, single <form>, no page reload between steps
$_partials = __DIR__ . '/_partials';
?>
<div class="max-w-2xl mx-auto">

  <!-- Step Indicator -->
  <div class="flex items-center justify-center gap-0 mb-6" id="stepIndicator">
    <div class="flex items-center gap-2" id="stepIndicator1">
      <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white" style="background:var(--brand-color)">1</div>
      <span class="text-sm font-semibold text-gray-900 dark:text-white">Info & Produk</span>
    </div>
    <div class="w-12 h-px bg-gray-200 dark:bg-gray-700 mx-3"></div>
    <div class="flex items-center gap-2" id="stepIndicator2">
      <div id="step2Circle" class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 transition-colors duration-200">2</div>
      <span id="step2Label" class="text-sm text-gray-400 dark:text-gray-500 transition-colors duration-200">Pembayaran</span>
    </div>
  </div>

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

    <!-- ══ STEP 1 ══ -->
    <div id="checkoutStep1" class="space-y-4">

      <!-- Buyer info -->
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

        <?php if ($tplShowPhone || $tplShowAddress || $tplShowNotes): ?>
        <div class="mt-4 space-y-3">
          <?php if ($tplShowPhone): ?>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nomor WhatsApp <span class="text-red-500">*</span></label>
            <input type="tel" name="phone" id="buyer-wa" inputmode="numeric" value="<?= e($buyerPhone) ?>" maxlength="20"
                   class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                   placeholder="08xxxxxxxxxx">
            <p id="buyer-wa-error" class="hidden text-xs text-red-500 mt-1">Nomor WhatsApp wajib diisi (10–15 digit).</p>
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
      </div>

      <!-- Coupon -->
      <?php include $_partials . '/coupon-field.php'; ?>
    <?php include $_partials . '/points-redeem.php'; ?>

      <!-- Bumps -->
      <?php include $_partials . '/flash-bonus.php'; ?>
      <?php include $_partials . '/bump-offers.php'; ?>

      <!-- Static price preview (no JS IDs — avoids duplicate with step 2 card) -->
      <?php if (!$isFree): ?>
      <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-700 px-4 py-3 text-sm">
        <span class="text-gray-500 dark:text-gray-400 truncate mr-2"><?= e($product['name']) ?></span>
        <span class="font-bold text-gray-900 dark:text-white shrink-0"><?= e(formatRupiah($price)) ?></span>
      </div>
      <?php endif; ?>

      <!-- Next button -->
      <button type="button" onclick="goToStep2()"
              class="w-full py-3 rounded-lg text-sm font-bold text-white transition hover:opacity-90"
              style="background-color: var(--brand-color);">
        Lanjut ke Pembayaran →
      </button>

    </div><!-- /step1 -->

    <!-- ══ STEP 2 ══ -->
    <div id="checkoutStep2" class="hidden space-y-4">

      <!-- Order summary card (kolom tunggal → JANGAN sticky, agar tak menutupi pembayaran saat scroll) -->
      <?php $summaryStickyDesktop = false; ?>
      <?php include $_partials . '/order-summary-card.php'; ?>

      <!-- Payment method -->
      <?php include $_partials . '/payment-method.php'; ?>

      <!-- Submit -->
      <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
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

      <!-- Back button -->
      <button type="button" onclick="goToStep1()"
              class="w-full py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
        ← Kembali ke Informasi
      </button>

    </div><!-- /step2 -->

  </form>
</div>

<script>
(function () {
  function goToStep2() {
    // Nomor WhatsApp wajib: validasi sebelum lanjut (field ada di Step 1).
    var wa    = document.getElementById('buyer-wa');
    var waErr = document.getElementById('buyer-wa-error');
    if (wa) {
      var digits = (wa.value || '').replace(/[^0-9]/g, '');
      if (!/^[0-9]{10,15}$/.test(digits)) {
        if (waErr) waErr.classList.remove('hidden');
        wa.focus();
        return;
      }
      if (waErr) waErr.classList.add('hidden');
    }
    document.getElementById('checkoutStep1').classList.add('hidden');
    document.getElementById('checkoutStep2').classList.remove('hidden');
    // Update indicator
    var circle = document.getElementById('step2Circle');
    var label  = document.getElementById('step2Label');
    circle.style.background = 'var(--brand-color)';
    circle.style.color      = '#fff';
    label.style.color       = '';
    label.classList.remove('text-gray-400', 'dark:text-gray-500');
    label.classList.add('text-gray-900', 'dark:text-white', 'font-semibold');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function goToStep1() {
    document.getElementById('checkoutStep2').classList.add('hidden');
    document.getElementById('checkoutStep1').classList.remove('hidden');
    var circle = document.getElementById('step2Circle');
    var label  = document.getElementById('step2Label');
    circle.style.background = '';
    circle.style.color      = '';
    label.classList.remove('text-gray-900', 'dark:text-white', 'font-semibold');
    label.classList.add('text-gray-400', 'dark:text-gray-500');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // Expose for onclick handlers
  window.goToStep2 = goToStep2;
  window.goToStep1 = goToStep1;
})();
</script>
