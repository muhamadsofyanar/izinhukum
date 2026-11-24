<?php
// ─── Kartu penawaran MELAYANG di pojok dashboard (opsional) ───
// Tampil hanya bila admin mengaktifkan floating & member punya penawaran.
// Bisa ditutup member (disimpan di localStorage per-penawaran → tak muncul lagi).
if (!function_exists('memberOffersFloatingEnabled') || !memberOffersFloatingEnabled()) return;

$_floatUser  = isset($userId) ? (int) $userId : (int) ($_SESSION['user_id'] ?? 0);
$_floatList  = getMemberUpsellOffers($_floatUser, 1);
if (empty($_floatList)) return;
$of   = $_floatList[0];
$csrf = function_exists('generateCSRF') ? generateCSRF() : ($_SESSION['csrf_token'] ?? '');
$key  = 'mo_float_dismiss_' . (int) $of['upsell_id'];
?>
<div id="moFloat" data-key="<?= e($key) ?>"
     style="position:fixed;bottom:16px;right:16px;width:300px;max-width:calc(100vw - 2rem)"
     class="hidden z-40 bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-xl overflow-hidden">
  <button type="button" onclick="document.getElementById('moFloat').remove(); try{localStorage.setItem(<?= json_encode($key) ?>,'1');}catch(e){}"
          class="absolute top-2 right-2 z-10 w-6 h-6 flex items-center justify-center rounded-md bg-black/40 text-white hover:bg-black/60 transition-colors" aria-label="Tutup">
    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
  </button>

  <?php if (!empty($of['thumbnail'])): ?>
  <div class="aspect-video bg-gray-100 dark:bg-gray-800"><img src="<?= e($of['thumbnail']) ?>" alt="" class="w-full h-full object-cover"></div>
  <?php endif; ?>

  <div class="p-4">
    <?php if (!empty($of['save_percent'])): ?>
    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-bold text-white bg-[var(--brand-color)] mb-2">Hemat <?= (int) $of['save_percent'] ?>%</span>
    <?php endif; ?>
    <h4 class="text-sm font-bold text-gray-900 dark:text-white line-clamp-2"><?= e($of['product_name']) ?></h4>
    <div class="mt-2 flex items-baseline gap-2">
      <span class="text-base font-bold text-gray-900 dark:text-white"><?= e(formatRupiah($of['upsell_price'])) ?></span>
      <?php if ($of['save_amount'] > 0): ?>
      <span class="text-xs text-gray-400 dark:text-gray-600 line-through"><?= e(formatRupiah($of['normal_price'])) ?></span>
      <?php endif; ?>
    </div>
    <div class="mt-3 flex items-center gap-2">
      <?php if (!empty($of['slug'])): ?>
      <a href="<?= e(APP_URL) ?>/products/<?= e($of['slug']) ?>" class="flex-1 text-center px-3 py-2 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Lihat</a>
      <?php endif; ?>
      <form method="POST" action="<?= e(APP_URL) ?>/actions/user/accept-upsell" class="flex-1 js-offer-buy">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="source_order" value="<?= e($of['source_order']) ?>">
        <button type="submit" class="w-full px-3 py-2 rounded-lg text-xs font-semibold text-white bg-[var(--brand-color)] hover:opacity-90 transition-opacity">Beli</button>
      </form>
    </div>
  </div>
</div>

<script>
// Tampilkan hanya bila belum pernah ditutup (per-penawaran).
(function () {
  var el = document.getElementById('moFloat');
  if (!el) return;
  var k = el.getAttribute('data-key');
  var dismissed = false;
  try { dismissed = localStorage.getItem(k) === '1'; } catch (e) {}
  if (!dismissed) el.classList.remove('hidden');
})();
// Submit one-click (guard sama dengan _member-offers.php agar tak dobel-bind).
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
          if (btn) { btn.disabled = false; btn.textContent = 'Beli'; }
        }
      })
      .catch(function () {
        if (window.showToast) showToast('Terjadi kesalahan. Coba lagi.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Beli'; }
      });
  });
})();
</script>
