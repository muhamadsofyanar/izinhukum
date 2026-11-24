<?php
// Variables: $isFree, $tplShowCoupon (toggle per template — default tampil)
// Produk fleksibel: tampilkan pemilih nominal di slot ini (kupon nonaktif).
if (!empty($isFlexible)) { include __DIR__ . '/flexible-amount.php'; return; }
if ($isFree || !($tplShowCoupon ?? true)) return;
?>
<div id="coupon-field-block" class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
  <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-3">Kode Kupon</h4>
  <div class="flex gap-2">
    <input type="text" id="coupon-code" placeholder="Masukkan kode kupon"
           maxlength="50" autocomplete="off"
           oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9-]/g,'')"
           class="min-w-0 flex-1 px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
    <button type="button" id="apply-coupon-btn"
            class="shrink-0 px-4 py-2.5 rounded-lg text-sm font-semibold border border-[var(--brand-color)] text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_8%,transparent)] transition whitespace-nowrap">
      Terapkan
    </button>
  </div>
  <div id="coupon-result" class="mt-2 text-sm"></div>
</div>
