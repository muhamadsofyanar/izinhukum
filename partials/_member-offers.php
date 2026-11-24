<?php
// ─── Grid kartu penawaran upsell untuk member (dashboard & halaman /penawaran) ───
// Input: $offers (list dari getMemberUpsellOffers), $csrf (token).
// Tombol "Beli" = one-click harga spesial → reuse actions/user/accept-upsell.php
// (kirim source_order; server re-validasi penawaran). "Lihat" → salespage produk.
// UI kotak-tegas (radius kecil), tanpa pill berlebih. Tanpa emoji (SVG inline).
if (empty($offers)) return;
$csrf = $csrf ?? (function_exists('generateCSRF') ? generateCSRF() : '');
?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($offers as $of): ?>
  <div class="flex flex-col bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
    <div class="aspect-video bg-gray-100 dark:bg-gray-800 shrink-0 relative">
      <?php if (!empty($of['thumbnail'])): ?>
      <img src="<?= e($of['thumbnail']) ?>" alt="" class="w-full h-full object-cover">
      <?php else: ?>
      <div class="w-full h-full flex items-center justify-center">
        <svg class="w-8 h-8 text-gray-300 dark:text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M2 15l5-5 4 4 3-3 8 8"/></svg>
      </div>
      <?php endif; ?>
      <?php if (!empty($of['save_percent'])): ?>
      <span class="absolute top-2 left-2 inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-bold text-white bg-[var(--brand-color)]">Hemat <?= (int) $of['save_percent'] ?>%</span>
      <?php endif; ?>
    </div>
    <div class="flex flex-col flex-1 p-4">
      <?php if (!empty($of['headline'])): ?>
      <p class="text-xs font-semibold text-[var(--brand-color)] mb-1 truncate"><?= e($of['headline']) ?></p>
      <?php endif; ?>
      <h4 class="text-sm font-bold text-gray-900 dark:text-white line-clamp-2"><?= e($of['product_name']) ?></h4>
      <?php if (!empty($of['description'])): ?>
      <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 line-clamp-2"><?= e($of['description']) ?></p>
      <?php endif; ?>

      <div class="mt-3 flex items-baseline gap-2">
        <span class="text-base font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($of['upsell_price'])) ?></span>
        <?php if ($of['save_amount'] > 0): ?>
        <span class="text-xs text-gray-400 dark:text-gray-600 line-through"><?= e(formatRupiah($of['normal_price'])) ?></span>
        <?php endif; ?>
      </div>

      <div class="mt-4 flex items-center gap-2 pt-1">
        <?php if (!empty($of['slug'])): ?>
        <a href="<?= e(APP_URL) ?>/products/<?= e($of['slug']) ?>"
           class="flex-1 text-center px-3 py-2 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Lihat</a>
        <?php endif; ?>
        <form method="POST" action="<?= e(APP_URL) ?>/actions/user/accept-upsell" class="flex-1 js-offer-buy" data-product="<?= e($of['product_name']) ?>">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="source_order" value="<?= e($of['source_order']) ?>">
          <button type="submit" class="w-full px-3 py-2 rounded-lg text-xs font-semibold text-white bg-[var(--brand-color)] hover:opacity-90 transition-opacity">Beli Sekarang</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
// One-click beli penawaran: submit async ke accept-upsell → ikuti redirect dari server.
(function () {
  if (window.__memberOffersBound) return;
  window.__memberOffersBound = true;
  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('.js-offer-buy');
    if (!form) return;
    ev.preventDefault();
    var btn = form.querySelector('button[type=submit]');
    if (btn) { btn.disabled = true; btn.textContent = 'Memproses...'; }
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.success && d.redirect) {
          window.location.href = <?= json_encode(rtrim(APP_URL, '/')) ?> + d.redirect;
        } else {
          if (window.showToast) showToast((d && d.message) || 'Penawaran tidak tersedia.', 'error');
          if (btn) { btn.disabled = false; btn.textContent = 'Beli Sekarang'; }
        }
      })
      .catch(function () {
        if (window.showToast) showToast('Terjadi kesalahan. Coba lagi.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Beli Sekarang'; }
      });
  });
})();
</script>
