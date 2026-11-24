<?php
if (!empty($isVoucher)) return;
// Variables: $bumpOffers (array)
if (empty($bumpOffers)) return;
?>
<div id="bump-offers-block" class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
  <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-3 flex items-center gap-2">
    <svg class="w-4 h-4 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/>
      <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/>
      <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
    </svg>
    Tingkatkan Order Kamu
  </h4>
  <div class="space-y-3">
    <?php foreach ($bumpOffers as $bump):
      $bumpDisplayPrice = ($bump['bump_price'] !== null)
          ? (float)$bump['bump_price']
          : (float)$bump['bump_normal_price'];
    ?>
    <label class="flex items-start gap-3 p-3.5 border-2 border-dashed border-amber-200 dark:border-amber-800/50 bg-amber-50/50 dark:bg-amber-900/10 rounded-lg cursor-pointer hover:border-amber-300 dark:hover:border-amber-700 transition has-[:checked]:border-amber-400 has-[:checked]:bg-amber-50 dark:has-[:checked]:bg-amber-900/20">
      <input type="checkbox" name="bump_ids[]"
             value="<?= (int)$bump['bump_product_id'] ?>"
             data-price="<?= $bumpDisplayPrice ?>"
             class="bump-checkbox mt-1 w-4 h-4 accent-amber-500 shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">
          <?= $bump['headline'] ? e($bump['headline']) : 'Tambahkan ' . e($bump['bump_name']) ?>
        </p>
        <?php if ($bump['description']): ?>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">
          <?= e($bump['description']) ?>
        </p>
        <?php endif; ?>
        <p class="text-sm font-bold text-gray-900 dark:text-white mt-1.5">
          <?= $bumpDisplayPrice > 0 ? e(formatRupiah($bumpDisplayPrice)) : '<span class="text-green-600">GRATIS</span>' ?>
          <?php if ($bump['bump_price'] !== null && (float)$bump['bump_normal_price'] > $bumpDisplayPrice): ?>
          <span class="ml-1.5 text-xs text-gray-400 line-through font-normal">
            <?= e(formatRupiah((float)$bump['bump_normal_price'])) ?>
          </span>
          <?php endif; ?>
        </p>
      </div>
      <?php if ($bump['bump_thumbnail']): ?>
      <img src="<?= e($bump['bump_thumbnail']) ?>" alt=""
           class="w-14 h-14 rounded-lg object-cover shrink-0">
      <?php endif; ?>
    </label>
    <?php endforeach; ?>
  </div>
</div>
