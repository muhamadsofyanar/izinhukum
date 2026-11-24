<?php
// Pilihan cara bayar: Bayar Penuh vs Booking Fee (DP).
// Variables dari scope checkout: $product, $price, $setupFee, $isFree.
// Radio memakai atribut form="checkout-form" agar tetap terkirim walau
// dirender di luar <form> (kartu ringkasan). Validasi final di server.
$bkFee    = (float) ($product['booking_fee'] ?? 0);
$bkFull   = (float) $price + (float) ($setupFee ?? 0);
$bkActive = !($isFree ?? false)
    && (int) ($product['booking_enabled'] ?? 0) === 1
    && $bkFee > 0 && $bkFee < $bkFull;
if (!$bkActive) return;
$bkDays = max(1, (int) ($product['booking_deadline_days'] ?? 7));
?>
<div class="pt-3 mt-1 border-t border-gray-100 dark:border-gray-800 space-y-2" id="payment-mode-picker">
  <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">Cara Bayar</p>

  <label class="flex items-start gap-2.5 p-3 rounded-lg border-2 cursor-pointer transition border-gray-200 dark:border-gray-700 has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)]">
    <input type="radio" name="payment_mode" value="full" form="checkout-form" checked
           class="checkout-radio mt-0.5">
    <span class="min-w-0">
      <span class="block text-sm font-semibold text-gray-900 dark:text-white">Bayar Penuh</span>
      <span class="block text-xs text-gray-500 dark:text-gray-400"><?= e(formatRupiah($bkFull)) ?> — akses langsung setelah pembayaran</span>
    </span>
  </label>

  <label class="flex items-start gap-2.5 p-3 rounded-lg border-2 cursor-pointer transition border-gray-200 dark:border-gray-700 has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)]">
    <input type="radio" name="payment_mode" value="booking" form="checkout-form"
           class="checkout-radio mt-0.5">
    <span class="min-w-0">
      <span class="block text-sm font-semibold text-gray-900 dark:text-white">Booking Fee <?= e(formatRupiah($bkFee)) ?></span>
      <span class="block text-xs text-gray-500 dark:text-gray-400">Kunci slot &amp; harga sekarang — sisa <?= e(formatRupiah($bkFull - $bkFee)) ?> dilunasi dalam <?= $bkDays ?> hari</span>
    </span>
  </label>

  <script>
  (function () {
    if (window._bookingPickerInit) return;
    window._bookingPickerInit = true;
    window._bookingMode = false;
    var fmtFull    = <?= json_encode(formatRupiah($bkFull)) ?>;
    var fmtBooking = <?= json_encode(formatRupiah($bkFee)) ?>;

    function apply() {
      var sel = document.querySelector('input[name="payment_mode"]:checked');
      window._bookingMode = !!(sel && sel.value === 'booking');
      // Kupon & bump tidak berlaku pada mode booking — sembunyikan bloknya.
      ['coupon-field-block', 'bump-offers-block'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', window._bookingMode);
      });
      if (typeof window._recalcCheckoutTotal === 'function') {
        window._recalcCheckoutTotal();
      } else {
        var t = document.getElementById('order-total-display');
        if (t) t.textContent = window._bookingMode ? fmtBooking : fmtFull;
      }
    }
    document.querySelectorAll('input[name="payment_mode"]').forEach(function (r) {
      r.addEventListener('change', apply);
    });
    apply();
  })();
  </script>
</div>
