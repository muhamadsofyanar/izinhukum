<?php
// Strip "metode diterima" — chip ikon SVG NETRAL (bukan logo merek → aman merek dagang).
// Menyesuaikan metode aktif. Variabel dari scope checkout: $duitkuActive, $xenditActive,
// $mootaActive, $paypalActive, $manualTransferAvailable.
$duitkuActive            = $duitkuActive ?? false;
$xenditActive            = $xenditActive ?? false;
$mootaActive             = $mootaActive ?? false;
$paypalActive            = $paypalActive ?? false;
$manualTransferAvailable = $manualTransferAvailable ?? false;

$hasHosted = $duitkuActive || $xenditActive;          // gateway multi-metode (bank/e-wallet/qris/kartu)
$hasBank   = $hasHosted || $mootaActive || $manualTransferAvailable;
$hasWallet = $hasHosted;
$hasQris   = $hasHosted;
$hasCard   = $hasHosted || $paypalActive;

// Ikon netral (SVG inline, currentColor). Label = judul chip (aksesibilitas).
$icons = [
    'bank'   => ['on' => $hasBank,   'label' => 'Transfer Bank', 'svg' => '<path d="M3 21h18M4 21V10l8-5 8 5v11M9 21v-6h6v6" stroke-linecap="round" stroke-linejoin="round"/>'],
    'wallet' => ['on' => $hasWallet, 'label' => 'E-wallet',      'svg' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M16 12h3M3 9h18" stroke-linecap="round"/>'],
    'qris'   => ['on' => $hasQris,   'label' => 'QRIS',          'svg' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h3v3M20 14v6M17 20h3" stroke-linecap="round" stroke-linejoin="round"/>'],
    'card'   => ['on' => $hasCard,   'label' => 'Kartu',         'svg' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20" stroke-linecap="round"/>'],
];
$active = array_filter($icons, fn($i) => $i['on']);
if (!$active) return;
?>
<div class="flex items-center flex-wrap gap-1.5 mb-3" aria-label="Metode pembayaran yang diterima">
  <?php foreach ($active as $ic): ?>
  <span class="pay-chip" title="<?= e($ic['label']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><?= $ic['svg'] ?></svg>
  </span>
  <?php endforeach; ?>
  <span class="text-[11px] text-gray-400 dark:text-gray-500 ml-1">Pembayaran aman &amp; terenkripsi</span>
</div>
