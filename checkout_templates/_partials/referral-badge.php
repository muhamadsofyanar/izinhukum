<?php
// Attribution afiliasi di form checkout — tampilkan NAMA affiliate (fallback ke
// kode bila nama tak ditemukan) supaya affiliate & pembeli yakin referral tercatat.
// Display-only: tidak memengaruhi proses order. Butuh $refCode (wajib) &
// $refAffiliateName (opsional) di scope. Aman di-include berulang (self-guard).
$rc = isset($refCode) ? trim((string) $refCode) : '';
$rn = isset($refAffiliateName) ? trim((string) $refAffiliateName) : '';
if ($rc === '') return;
?>
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-4 mb-4">
  <div class="flex items-center gap-2.5">
    <svg class="w-5 h-5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
    <div class="min-w-0">
      <?php if ($rn !== ''): ?>
      <p class="text-sm text-gray-700 dark:text-gray-300">Direkomendasikan oleh <span class="font-bold text-[var(--brand-color)]"><?= e($rn) ?></span></p>
      <?php else: ?>
      <p class="text-sm text-gray-700 dark:text-gray-300">Kode referral: <span class="font-mono font-bold text-[var(--brand-color)]"><?= e($rc) ?></span></p>
      <?php endif; ?>
      <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Pembelianmu tercatat untuk afiliasi ini.</p>
    </div>
  </div>
</div>
