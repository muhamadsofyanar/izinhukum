<?php
if (!empty($isVoucher)) return;
// ─── Tukar Poin Reward jadi potongan (checkout LOGIN saja) ───────────────
// Variabel yang dipakai: $product, $isFree, $isFlexible.
// Angka di sini hanya PRATINJAU — server menghitung ulang dari saldo terkunci
// (pointsPlanRedeem) saat order dibuat, jadi layar tak pernah bisa "menipu".
if (!empty($isFree) || !empty($isFlexible)) return;
if (!function_exists('pointsEnabled') || !pointsEnabled()) return;
if (!isLoggedIn()) return;

$_pUserId  = (int) ($_SESSION['user_id'] ?? 0);
$_pBalance = pointsBalance($_pUserId);
if ($_pBalance <= 0) return;

$_pLabel = pointsLabel();
$_pPrice = (float) ($product['price'] ?? 0);
$_pPlan  = pointsPlanRedeem($_pBalance, $_pPrice, 0.0);
if ($_pPlan['points'] <= 0) {
    // Punya poin tapi belum memenuhi syarat (di bawah minimal) — beri tahu, jangan diam.
    $_pMin = pointsMinRedeem();
    ?>
    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background:rgba(245,158,11,.14)">
          <svg class="w-4 h-4" style="color:#b45309" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/></svg>
        </div>
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-900 dark:text-white">Saldo <?= e($_pLabel) ?>: <?= number_format($_pBalance, 0, ',', '.') ?></p>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Minimal <?= number_format($_pMin, 0, ',', '.') ?> <?= e($_pLabel) ?> untuk bisa dipakai.</p>
        </div>
      </div>
    </div>
    <?php
    return;
}
?>
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
  <div class="flex items-center justify-between gap-3 mb-3">
    <h4 class="font-semibold text-gray-900 dark:text-white text-sm flex items-center gap-2">
      <svg class="w-4 h-4" style="color:#b45309" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.66 3.58 3 8 3s8-1.34 8-3V6"/><path d="M4 12v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/></svg>
      Tukar <?= e($_pLabel) ?> Saya
    </h4>
    <span class="shrink-0 text-[11px] font-semibold px-2 py-1 rounded" style="background:rgba(245,158,11,.14);color:#b45309">
      Saldo: <?= number_format($_pBalance, 0, ',', '.') ?> <?= e($_pLabel) ?>
    </span>
  </div>

  <label for="use-points-toggle" class="flex items-center justify-between gap-3 p-3 rounded-lg cursor-pointer border" style="background:rgba(245,158,11,.07);border-color:rgba(245,158,11,.35)">
    <span class="min-w-0">
      <span class="block text-sm font-semibold text-gray-900 dark:text-white">Gunakan Saldo <?= e($_pLabel) ?></span>
      <span class="block text-xs mt-0.5" style="color:#b45309">
        Memotong <span id="points-use-count"><?= number_format($_pPlan['points'], 0, ',', '.') ?></span> <?= e($_pLabel) ?>
        (&minus; <span id="points-use-rupiah"><?= e(formatRupiah($_pPlan['discount'])) ?></span>)
      </span>
    </span>
    <input type="checkbox" id="use-points-toggle" class="w-5 h-5 rounded shrink-0" style="accent-color:#f59e0b">
  </label>
  <input type="hidden" name="use_points" id="use_points_hidden" value="0">
  <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-2">
    Maksimal <?= (int) pointsMaxDiscountPercent() ?>% dari harga produk. Sisa <?= e(strtolower($_pLabel)) ?> tetap tersimpan.
  </p>
</div>

<script>
(function () {
  var toggle  = document.getElementById('use-points-toggle');
  var hidden  = document.getElementById('use_points_hidden');
  if (!toggle || !hidden) return;

  var balance = <?= (int) $_pBalance ?>;
  var unit    = <?= (float) pointsValue() ?>;
  var maxPct  = <?= (float) pointsMaxDiscountPercent() ?>;
  var minPts  = <?= (int) pointsMinRedeem() ?>;
  var price   = <?= (float) $_pPrice ?>;

  var cntEl = document.getElementById('points-use-count');
  var rpEl  = document.getElementById('points-use-rupiah');
  var rowEl = document.getElementById('points-summary-row');
  var discEl= document.getElementById('points-discount-display');
  var usedEl= document.getElementById('points-used-display');

  function fmt(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

  // Meniru pointsPlanRedeem() di server: saldo -> batas persen -> sisa setelah kupon.
  function plan() {
    if (unit <= 0 || balance < minPts) return { points: 0, disc: 0 };
    var couponDisc = (window._checkoutCouponDisc ? window._checkoutCouponDisc() : 0);
    var cap = Math.min(price * (maxPct / 100), Math.max(0, price - couponDisc));
    if (cap <= 0) return { points: 0, disc: 0 };
    var pts = Math.min(Math.floor(cap / unit), balance);
    if (pts <= 0 || pts < minPts) return { points: 0, disc: 0 };
    return { points: pts, disc: pts * unit };
  }

  // Dipakai oleh recalc total bersama (sumber tunggal perhitungan layar).
  window._checkoutPointsDisc = function () {
    return toggle.checked ? plan().disc : 0;
  };

  function sync() {
    var p = plan();
    if (cntEl) cntEl.textContent = p.points.toLocaleString('id-ID');
    if (rpEl)  rpEl.textContent  = fmt(p.disc);

    // Kupon bisa membuat poin jadi tak terpakai → matikan toggle agar tak menyesatkan.
    if (p.points <= 0 && toggle.checked) toggle.checked = false;
    hidden.value = toggle.checked && p.points > 0 ? '1' : '0';

    if (rowEl) {
      if (toggle.checked && p.disc > 0) {
        rowEl.classList.remove('hidden');
        if (discEl) discEl.textContent = '-' + fmt(p.disc);
        if (usedEl) usedEl.textContent = '(' + p.points.toLocaleString('id-ID') + ')';
      } else {
        rowEl.classList.add('hidden');
      }
    }
    if (window._recalcCheckoutTotal) window._recalcCheckoutTotal();
  }

  toggle.addEventListener('change', sync);
  // Kupon diterapkan/dihapus → batas poin berubah, hitung ulang.
  document.addEventListener('averion:coupon-changed', sync);
  sync();
})();
</script>
