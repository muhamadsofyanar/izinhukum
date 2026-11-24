<?php
// Compact payment method — dipakai template "minimal".
// Menawarkan opsi yang SAMA dengan payment-method.php (Duitku/Xendit/Moota/
// Transfer Bank Manual + pilih rekening) namun dengan tampilan ringkas.
// Variables (dari checkout.php, scope sama): $isFree, $duitkuActive,
//   $xenditActive, $mootaActive, $hasGateway, $manualTransferAvailable, $bankAccounts
if ($isFree) return;
$mootaActive             = $mootaActive ?? false;
$paypalActive            = $paypalActive ?? false;
$midtransActive          = $midtransActive ?? false;
$tripayActive            = $tripayActive ?? false;
$tripayChannels          = $tripayChannels ?? [];
$manualTransferAvailable = $manualTransferAvailable ?? false;
$bankAccounts            = $bankAccounts ?? [];
// Metode terpilih default (biar user "tinggal klik"): gateway lokal diprioritaskan,
// lalu PayPal, lalu Transfer Bank Manual (bila ada rekening), terakhir manual generik.
if ($duitkuActive)                 { $defaultMethod = 'duitku'; }
elseif ($xenditActive)             { $defaultMethod = 'xendit'; }
elseif ($midtransActive)           { $defaultMethod = 'midtrans'; }
elseif ($tripayActive)             { $defaultMethod = 'tripay'; }
elseif ($mootaActive)              { $defaultMethod = 'moota'; }
elseif ($paypalActive)             { $defaultMethod = 'paypal'; }
elseif ($manualTransferAvailable)  { $defaultMethod = 'manual_transfer'; }
else                               { $defaultMethod = 'manual'; }
$manualTransferDefault = $defaultMethod === 'manual_transfer';
$plainManualDefault    = $defaultMethod === 'manual';
?>
<div>
  <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-2.5">Metode Pembayaran</p>
  <?php include __DIR__ . '/accepted-payments.php'; ?>
  <div class="space-y-2">

    <?php if ($duitkuActive): ?>
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="duitku" id="method_duitku"
             <?= $defaultMethod === 'duitku' ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Duitku</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Bank / E-wallet / QRIS</p>
      </div>
      <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">AUTO</span>
    </label>
    <?php endif; ?>

    <?php if ($xenditActive): ?>
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="xendit" id="method_xendit"
             <?= $defaultMethod === 'xendit' ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Xendit</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Bank / E-wallet / Kartu Kredit</p>
      </div>
      <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">AUTO</span>
    </label>
    <?php endif; ?>

    <?php if ($midtransActive): ?>
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="midtrans" id="method_midtrans"
             <?= $defaultMethod === 'midtrans' ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Midtrans</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Bank / GoPay / QRIS / Kartu</p>
      </div>
      <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">AUTO</span>
    </label>
    <?php endif; ?>

    <?php if ($tripayActive): ?>
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="tripay" id="method_tripay"
             <?= $defaultMethod === 'tripay' ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Tripay</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">VA / QRIS / E-wallet / Alfamart</p>
      </div>
      <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">AUTO</span>
    </label>
    <div id="tripay-channels" class="<?= $defaultMethod === 'tripay' ? '' : 'hidden' ?> space-y-1.5">
      <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Pilih metode:</p>
      <div class="grid grid-cols-1 gap-1.5">
        <?php foreach ($tripayChannels as $i => $ch): ?>
        <label class="flex items-center gap-2 p-2 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_6%,transparent)] transition">
          <input type="radio" name="tripay_channel" value="<?= e($ch['code']) ?>" <?= $i === 0 ? 'checked' : '' ?> class="checkout-radio shrink-0">
          <?php if (!empty($ch['icon'])): ?><img src="<?= e($ch['icon']) ?>" alt="" class="w-7 h-4 object-contain shrink-0"><?php endif; ?>
          <span class="text-sm text-gray-800 dark:text-gray-200 truncate"><?= e($ch['name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($mootaActive): ?>
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="moota" id="method_moota"
             <?= $defaultMethod === 'moota' ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Transfer Bank</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Terkonfirmasi otomatis</p>
      </div>
      <span class="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">AUTO</span>
    </label>
    <?php endif; ?>

    <?php if ($paypalActive): ?>
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="paypal" id="method_paypal"
             <?= $defaultMethod === 'paypal' ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">PayPal</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Kartu / saldo PayPal · ditagih USD</p>
      </div>
      <span class="text-[10px] font-bold text-white px-1.5 py-0.5 rounded-full shrink-0" style="background:#003087">USD</span>
    </label>
    <?php endif; ?>

    <?php if ($manualTransferAvailable): ?>
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="manual_transfer" id="method_manual_transfer"
             <?= $manualTransferDefault ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Transfer Bank Manual</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Upload bukti — dikonfirmasi admin</p>
      </div>
    </label>
    <?php endif; ?>

    <?php if (!($hasGateway ?? false) && !$manualTransferAvailable): ?>
    <!-- Transfer Manual generik: MURNI fallback terakhir — hanya bila TIDAK ada
         gateway otomatis DAN belum ada rekening manual (selaras payment-method.php). -->
    <label class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="manual" id="method_manual"
             <?= $plainManualDefault ? 'checked' : '' ?>
             class="checkout-radio shrink-0">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Transfer Manual</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Instruksi dikirim setelah order</p>
      </div>
    </label>
    <?php endif; ?>

  </div>

  <?php if ($manualTransferAvailable):
      $manualQrImage            = $manualQrImage ?? null;
      $manualPaymentInstruction = $manualPaymentInstruction ?? '';
      $hasBankList              = count($bankAccounts) > 0;
  ?>
  <!-- Detail pembayaran manual (muncul saat metode Transfer Bank Manual dipilih) -->
  <div id="bank-account-selector" class="<?= $manualTransferDefault ? '' : 'hidden' ?> mt-3 space-y-3">

    <?php if (trim($manualPaymentInstruction) !== ''): ?>
    <p class="text-xs text-gray-600 dark:text-gray-300 leading-relaxed"><?= nl2br(e($manualPaymentInstruction)) ?></p>
    <?php endif; ?>

    <?php if ($hasBankList):
        // Chip ringkas — detail (nomor/QR/atas nama) tampil di halaman order.
        // Lihat payment-method.php untuk alasan lengkap.
        $firstIdC = $bankAccounts[array_key_first($bankAccounts)]['id'];
    ?>
    <div>
      <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Pilih tujuan transfer:</p>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($bankAccounts as $bank): $cType = $bank['channel_type'] ?? 'bank'; ?>
        <label class="flex items-center gap-2 pl-2.5 pr-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_6%,transparent)] focus-within:ring-2 transition"
               style="--tw-ring-color: color-mix(in srgb, var(--brand-color) 30%, transparent);">
          <input type="radio" name="bank_account_id" value="<?= (int) $bank['id'] ?>" <?= (int) $bank['id'] === (int) $firstIdC ? 'checked' : '' ?> class="sr-only">
          <?= paymentChannelIcon($cType, $bank['bank_name'], 'sm') ?>
          <span class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= e($bank['bank_name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Nomor rekening<?= !empty($manualQrImage) ? ' &amp; QR Pay' : '' ?> tampil di halaman order berikutnya.</p>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
