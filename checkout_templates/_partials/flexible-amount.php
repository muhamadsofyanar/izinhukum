<?php
// Bayar Fleksibel — pemilih nominal (preset + custom). WAJIB disertakan DI DALAM
// #checkout-form (hidden input flexible_amount ikut terkirim). Var dari checkout.php:
// $flexLabel (string), $flexMin (float), $flexPresets (array int).
// Fase 1: nominal efektif harus > 0 (paid). Menonaktifkan tombol submit sampai valid.
$_flexFloor = max((float) ($flexMin ?? 0), 1);
// Id elemen target (checkout member vs public checkout pakai id berbeda).
$_flexTotalId  = $flexTotalId       ?? 'order-total-display';
$_flexSubmitId = $flexSubmitId      ?? 'checkout-submit-btn';
$_flexLabelId  = $flexSubmitLabelId ?? '';
?>
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
  <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-1"><?= e($flexLabel ?: 'Bayar Seikhlasnya') ?></h4>
  <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Pilih nominal yang ingin kamu bayar<?php if ((float) ($flexMin ?? 0) > 0): ?> &middot; minimal <?= e(formatRupiah((float) $flexMin)) ?><?php endif; ?>.</p>

  <?php if (!empty($flexPresets)): ?>
  <div class="grid grid-cols-3 gap-2 mb-3" id="flexPresetWrap">
    <?php foreach ($flexPresets as $amt): ?>
    <button type="button" class="flex-preset px-2 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-300 transition" data-amount="<?= (int) $amt ?>"><?= e(formatRupiah((float) $amt)) ?></button>
    <?php endforeach; ?>
    <button type="button" class="flex-preset flex-preset-other px-2 py-2.5 rounded-lg border border-dashed border-gray-300 dark:border-gray-600 text-sm font-medium text-gray-600 dark:text-gray-400 transition" data-other>Nominal Lain</button>
  </div>
  <?php endif; ?>

  <div id="flexCustomWrap" class="<?= !empty($flexPresets) ? 'hidden' : '' ?> mb-1">
    <div class="relative">
      <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">Rp</span>
      <input type="number" id="flexCustomInput" inputmode="numeric" min="<?= (int) $_flexFloor ?>" step="1000"
             class="w-full pl-9 pr-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
             placeholder="Masukkan nominal">
    </div>
  </div>

  <input type="hidden" name="flexible_amount" id="flexible_amount" value="">
  <p id="flexError" class="hidden text-xs text-red-500 mt-1.5">Nominal minimal <?= e(formatRupiah($_flexFloor)) ?>.</p>

  <?php if (!empty($flexSupport)): // Fase 2: produk tampil di Halaman Dukung → pesan + anonim ?>
  <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
    <label for="supporter_message" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Pesan dukungan <span class="text-gray-400 font-normal">(opsional, tampil di halaman)</span></label>
    <textarea name="supporter_message" id="supporter_message" rows="2" maxlength="280"
              class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
              placeholder="Tulis pesan singkat (opsional)"></textarea>
    <label class="flex items-center gap-2 mt-2 cursor-pointer select-none">
      <input type="checkbox" name="supporter_anonymous" id="supporter_anonymous" value="1"
             class="rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
      <span class="text-xs text-gray-600 dark:text-gray-400">Sembunyikan nama saya (tampil sebagai "Anonim")</span>
    </label>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function () {
  var floor      = <?= (float) $_flexFloor ?>;
  var customWrap = document.getElementById('flexCustomWrap');
  var customInp  = document.getElementById('flexCustomInput');
  var hidden     = document.getElementById('flexible_amount');
  var errEl      = document.getElementById('flexError');
  var totalEl    = document.getElementById(<?= json_encode($_flexTotalId) ?>);
  var submitBtn  = document.getElementById(<?= json_encode($_flexSubmitId) ?>);
  var labelEl    = <?= $_flexLabelId !== '' ? 'document.getElementById(' . json_encode($_flexLabelId) . ')' : 'null' ?>;
  if (!hidden) return;

  function fmt(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

  function clearPresetHl() {
    document.querySelectorAll('.flex-preset').forEach(function (b) { b.style.borderColor = ''; b.style.color = ''; });
  }

  function apply(amt, fromCustom) {
    var valid = amt >= floor;
    hidden.value = valid ? amt : '';
    if (errEl) errEl.classList.toggle('hidden', valid || !amt);
    if (totalEl) totalEl.textContent = valid ? fmt(amt) : 'Pilih nominal';
    if (labelEl) labelEl.textContent = valid ? ('Bayar ' + fmt(amt)) : 'Pilih nominal dulu';
    if (submitBtn) submitBtn.disabled = !valid;
    if (!fromCustom) {
      clearPresetHl();
      var active = document.querySelector('.flex-preset[data-amount="' + amt + '"]');
      if (active) { active.style.borderColor = 'var(--brand-color)'; active.style.color = 'var(--brand-color)'; }
    }
  }

  document.querySelectorAll('.flex-preset[data-amount]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (customWrap) customWrap.classList.add('hidden');
      if (customInp) customInp.value = '';
      apply(parseInt(btn.getAttribute('data-amount'), 10) || 0, false);
    });
  });
  var other = document.querySelector('.flex-preset[data-other]');
  if (other) other.addEventListener('click', function () {
    clearPresetHl();
    if (customWrap) customWrap.classList.remove('hidden');
    if (customInp) customInp.focus();
    hidden.value = '';
    if (submitBtn) submitBtn.disabled = true;
    if (totalEl) totalEl.textContent = 'Pilih nominal';
  });
  if (customInp) customInp.addEventListener('input', function () {
    var v = parseInt((customInp.value || '').replace(/[^0-9]/g, ''), 10) || 0;
    apply(v, true);
  });

  // Awal: belum ada nominal → submit dinonaktifkan.
  if (submitBtn) submitBtn.disabled = true;
  if (totalEl) totalEl.textContent = 'Pilih nominal';
  if (labelEl) labelEl.textContent = 'Pilih nominal dulu';
  }); // ready
})();
</script>
