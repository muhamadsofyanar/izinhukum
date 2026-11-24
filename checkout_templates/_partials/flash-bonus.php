<?php
if (!empty($isVoucher)) return;
// ═══ BONUS KILAT — bonus produk premium GRATIS berbatas waktu ═══
// Variables dari checkout.php:
//   $fbOffers    (array)  daftar bonus aktif (bonus_product_id, headline, description,
//                         bonus_name, bonus_normal_price, bonus_thumbnail)
//   $fbLabel     (string) judul blok
//   $fbMaxSelect (int)    maksimal bonus yang boleh dipilih
//   $fbRemaining (int)    sisa detik masa berlaku (server-anchored via cookie)
//   $fbExpired   (bool)   true jika waktu sudah habis saat halaman dibuka
//
// Warna emerald didefinisikan via <style> lokal (bukan utility Tailwind) agar
// self-contained — tak butuh regenerate tailwind.css & aman ke paket pembeli.
if (empty($fbOffers)) return;
$fbMaxSelect = max(1, (int) ($fbMaxSelect ?? 1));
$fbRemaining = max(0, (int) ($fbRemaining ?? 0));
$fbExpired   = !empty($fbExpired) || $fbRemaining <= 0;
?>
<style>
#flash-bonus-block{border:2px solid #a7f3d0;background:rgba(209,250,229,.4);border-radius:.5rem;padding:1.25rem;box-shadow:0 1px 2px 0 rgba(0,0,0,.05)}
.dark #flash-bonus-block{border-color:rgba(6,95,70,.5);background:rgba(6,78,59,.1)}
#flash-bonus-block.fb-off{opacity:.6}
.fb-title{color:#065f46;font-weight:600;font-size:.875rem;display:flex;align-items:center;gap:.5rem;min-width:0}
.dark .fb-title{color:#6ee7b7}
.fb-title svg{color:#10b981}
.fb-chip{flex-shrink:0;display:inline-flex;align-items:center;gap:.25rem;padding:.125rem .5rem;border-radius:.375rem;font-size:.75rem;font-weight:700;font-variant-numeric:tabular-nums;background:#059669;color:#fff}
.fb-chip.fb-chip-off{background:#e5e7eb;color:#6b7280}
.dark .fb-chip.fb-chip-off{background:#1f2937;color:#9ca3af}
.fb-item{display:flex;align-items:center;gap:.75rem;padding:.75rem;border:1px solid #a7f3d0;background:#fff;border-radius:.5rem;cursor:pointer;transition:border-color .15s,box-shadow .15s}
.dark .fb-item{border-color:rgba(6,95,70,.5);background:#111827}
.fb-item:hover{border-color:#6ee7b7}
.fb-item:has(:checked){border-color:#34d399;box-shadow:0 0 0 1px #34d399}
.fb-item.fb-lock{pointer-events:none}
.fb-cb{width:1rem;height:1rem;flex-shrink:0;accent-color:#059669}
.fb-thumb-ph{width:2.75rem;height:2.75rem;border-radius:.375rem;background:#d1fae5;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dark .fb-thumb-ph{background:rgba(6,78,59,.3)}
.fb-thumb-ph svg{width:1.25rem;height:1.25rem;color:#10b981}
.fb-free{font-weight:700;color:#059669}
.dark .fb-free{color:#34d399}
.fb-strike{color:#9ca3af;text-decoration:line-through}
</style>
<div id="flash-bonus-block"
     class="<?= $fbExpired ? 'fb-off' : '' ?>"
     data-remaining="<?= $fbExpired ? 0 : $fbRemaining ?>"
     data-max="<?= $fbMaxSelect ?>">
  <div class="flex items-center justify-between gap-2 mb-1">
    <h4 class="fb-title">
      <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/>
        <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
      </svg>
      <span class="truncate"><?= e($fbLabel ?: 'Bonus Gratis Hari Ini') ?></span>
    </h4>
    <span id="fb-countdown-chip" class="fb-chip<?= $fbExpired ? ' fb-chip-off' : '' ?>">
      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <span id="fb-countdown"><?= $fbExpired ? 'Habis' : sprintf('%02d:%02d', intdiv($fbRemaining, 60), $fbRemaining % 60) ?></span>
    </span>
  </div>
  <p class="text-xs text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
    <span id="fb-subtext"<?= $fbExpired ? ' class="hidden"' : '' ?>>Pilih <?= $fbMaxSelect > 1 ? 'sampai ' . $fbMaxSelect : '' ?> produk premium ini <span class="fb-free">GRATIS</span> — khusus order sekarang. Selesaikan sebelum waktunya habis.</span>
    <span id="fb-expired-text"<?= $fbExpired ? '' : ' class="hidden"' ?> class="font-medium">Waktu bonus sudah habis.</span>
  </p>

  <div class="space-y-2" id="fb-list">
    <?php foreach ($fbOffers as $fb):
      $fbNormal = (float) $fb['bonus_normal_price'];
    ?>
    <label class="fb-item<?= $fbExpired ? ' fb-lock' : '' ?>">
      <input type="checkbox" name="flash_bonus_ids[]"
             value="<?= (int) $fb['bonus_product_id'] ?>"
             class="fb-cb flash-bonus-checkbox"
             <?= $fbExpired ? 'disabled' : '' ?>>
      <?php if ($fb['bonus_thumbnail']): ?>
      <img src="<?= e($fb['bonus_thumbnail']) ?>" alt="" width="44" height="44" loading="lazy" decoding="async" class="w-11 h-11 rounded-md object-cover shrink-0">
      <?php else: ?>
      <div class="fb-thumb-ph">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/></svg>
      </div>
      <?php endif; ?>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">
          <?= $fb['headline'] ? e($fb['headline']) : e($fb['bonus_name']) ?>
        </p>
        <?php if ($fb['description']): ?>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate"><?= e($fb['description']) ?></p>
        <?php endif; ?>
        <p class="text-xs mt-1 flex items-center gap-1.5 flex-wrap">
          <?php if ($fbNormal > 0): ?>
          <span class="fb-strike">Senilai <?= e(formatRupiah($fbNormal)) ?></span>
          <?php endif; ?>
          <span class="fb-free">GRATIS</span>
          <span class="text-[10px] text-gray-400 dark:text-gray-500">&middot; akses langsung setelah bayar</span>
        </p>
      </div>
    </label>
    <?php endforeach; ?>
  </div>

  <p id="fb-max-msg" class="hidden mt-2 text-xs text-amber-600 dark:text-amber-400 font-medium"></p>
</div>

<script>
(function () {
  var block = document.getElementById('flash-bonus-block');
  if (!block || block.dataset.fbInit) return;
  block.dataset.fbInit = '1';

  var remaining  = parseInt(block.dataset.remaining, 10) || 0;
  var maxSelect  = parseInt(block.dataset.max, 10) || 1;
  var chip       = document.getElementById('fb-countdown');
  var chipWrap   = document.getElementById('fb-countdown-chip');
  var subText    = document.getElementById('fb-subtext');
  var expText    = document.getElementById('fb-expired-text');
  var maxMsg     = document.getElementById('fb-max-msg');
  var boxes      = block.querySelectorAll('.flash-bonus-checkbox');
  var items      = block.querySelectorAll('.fb-item');

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function expire() {
    boxes.forEach(function (b) { b.checked = false; b.disabled = true; });
    items.forEach(function (el) { el.classList.add('fb-lock'); });
    block.classList.add('fb-off');
    if (chip) chip.textContent = 'Habis';
    if (chipWrap) chipWrap.classList.add('fb-chip-off');
    if (subText) subText.classList.add('hidden');
    if (expText) expText.classList.remove('hidden');
    if (maxMsg)  maxMsg.classList.add('hidden');
  }

  // ── Countdown ──
  if (remaining > 0) {
    var timer = setInterval(function () {
      remaining--;
      if (remaining <= 0) { clearInterval(timer); expire(); return; }
      if (chip) chip.textContent = pad(Math.floor(remaining / 60)) + ':' + pad(remaining % 60);
    }, 1000);
  } else {
    expire();
  }

  // ── Batas maksimal pilih ──
  boxes.forEach(function (box) {
    box.addEventListener('change', function () {
      var checked = block.querySelectorAll('.flash-bonus-checkbox:checked');
      if (checked.length > maxSelect) {
        box.checked = false;
        if (maxMsg) {
          maxMsg.textContent = 'Maksimal ' + maxSelect + ' bonus yang bisa dipilih.';
          maxMsg.classList.remove('hidden');
          setTimeout(function () { maxMsg.classList.add('hidden'); }, 2500);
        }
      }
    });
  });
})();
</script>
