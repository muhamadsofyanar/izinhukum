<?php
// ─── Panel penawaran upsell (post-purchase) ────────────────────
// Di-include dari transaction-detail & public-thank-you saat order LUNAS
// dan getUpsellOffer() mengembalikan penawaran.
// Butuh: $upsellOffer (array), $order (punya order_number), CSRF session.
if (empty($upsellOffer) || empty($order['order_number'])) { return; }
$__uoSave = $upsellOffer['normal_price'] > $upsellOffer['upsell_price']
    ? $upsellOffer['normal_price'] - $upsellOffer['upsell_price'] : 0;
?>
<div id="upsell-offer" class="mt-4 mb-4 rounded-2xl overflow-hidden border-2 border-[var(--brand-color)] shadow-sm">
  <div class="px-4 py-2 text-xs font-bold text-white tracking-wide flex items-center gap-1.5" style="background:var(--brand-color)">
    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
    PENAWARAN KHUSUS — hanya di halaman ini
  </div>
  <div class="p-4 sm:p-5 bg-white dark:bg-gray-900">
    <div class="flex items-start gap-3.5">
      <?php if (!empty($upsellOffer['thumbnail'])): ?>
      <img src="<?= e($upsellOffer['thumbnail']) ?>" alt="" class="w-14 h-14 rounded-xl object-cover shrink-0">
      <?php else: ?>
      <div class="w-14 h-14 rounded-xl bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      </div>
      <?php endif; ?>
      <div class="min-w-0 flex-1">
        <p class="font-bold text-gray-900 dark:text-white text-sm leading-snug">
          <?= e($upsellOffer['headline'] !== '' ? $upsellOffer['headline'] : 'Tambahkan ' . $upsellOffer['product_name'] . '?') ?>
        </p>
        <?php if ($upsellOffer['description'] !== ''): ?>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed"><?= e($upsellOffer['description']) ?></p>
        <?php endif; ?>
        <div class="flex items-baseline gap-2 mt-2 flex-wrap">
          <span class="text-lg font-extrabold text-[var(--brand-color)]"><?= e(formatRupiah($upsellOffer['upsell_price'])) ?></span>
          <?php if ($__uoSave > 0): ?>
          <span class="text-xs text-gray-400 line-through"><?= e(formatRupiah($upsellOffer['normal_price'])) ?></span>
          <span class="text-[11px] font-bold px-1.5 py-0.5 rounded bg-red-100 text-red-600">HEMAT <?= e(formatRupiah($__uoSave)) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <button type="button" id="upsell-accept-btn"
            class="mt-4 w-full py-3 rounded-xl text-sm font-bold text-white transition hover:opacity-90"
            style="background:var(--brand-color)">
      Ya, Tambahkan — <?= e($upsellOffer['product_name']) ?> →
    </button>
    <p id="upsell-error" class="text-xs text-red-500 hidden mt-2 text-center"></p>
    <p class="text-xs text-gray-400 dark:text-gray-600 text-center mt-2">Order terpisah — kamu tinggal menyelesaikan pembayarannya.</p>
  </div>
</div>
<script>
(function () {
  var btn = document.getElementById('upsell-accept-btn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var err = document.getElementById('upsell-error');
    btn.disabled = true; btn.textContent = 'Menyiapkan order…';
    var body = new URLSearchParams();
    body.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '') ?>);
    body.append('source_order', <?= json_encode((string) $order['order_number']) ?>);
    fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/user/accept-upsell') ?>, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.success && d.order_number) {
        // Server menentukan tujuan: member → /transactions/, tamu → /public-thank-you/
        window.location.href = d.redirect
          ? <?= json_encode(rtrim(APP_URL, '/')) ?> + d.redirect
          : <?= json_encode(rtrim(APP_URL, '/') . '/transactions/') ?> + encodeURIComponent(d.order_number);
        return;
      }
      err.textContent = d.message || 'Gagal membuat order. Coba lagi.';
      err.classList.remove('hidden');
      btn.disabled = false; btn.textContent = 'Ya, Tambahkan →';
    })
    .catch(function () {
      err.textContent = 'Gagal menghubungi server. Coba lagi.';
      err.classList.remove('hidden');
      btn.disabled = false; btn.textContent = 'Ya, Tambahkan →';
    });
  });
})();
</script>
