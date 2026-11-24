<?php
// Variables: $isFree, $duitkuActive, $xenditActive, $mootaActive, $hasGateway,
//            $manualTransferAvailable, $bankAccounts
if ($isFree) return;
$mootaActive             = $mootaActive ?? false;
$paypalActive            = $paypalActive ?? false;
$midtransActive          = $midtransActive ?? false;
$tripayActive            = $tripayActive ?? false;
$tripayChannels          = $tripayChannels ?? [];
$manualTransferAvailable = $manualTransferAvailable ?? false;
$bankAccounts            = $bankAccounts ?? [];
// Metode terpilih default (biar user "tinggal klik" tanpa harus memilih dulu).
// Prioritas: gateway lokal → PayPal (internasional) → transfer manual → manual generik.
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
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-sm p-5">
  <h4 class="font-semibold text-gray-900 dark:text-white text-sm mb-2.5">Metode Pembayaran</h4>
  <?php include __DIR__ . '/accepted-payments.php'; ?>
  <div class="space-y-2.5">

    <?php if ($duitkuActive): ?>
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="duitku" id="method_duitku"
             <?= $defaultMethod === 'duitku' ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Bayar via Duitku</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Transfer Bank, E-wallet (OVO, Dana, dll), QRIS</p>
      </div>
      <span class="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-md bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Otomatis</span>
    </label>
    <?php endif; ?>

    <?php if ($xenditActive): ?>
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="xendit" id="method_xendit"
             <?= $defaultMethod === 'xendit' ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Bayar via Xendit</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Transfer Bank, E-wallet, Kartu Kredit, QRIS</p>
      </div>
      <span class="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-md bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Otomatis</span>
    </label>
    <?php endif; ?>

    <?php if ($midtransActive): ?>
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="midtrans" id="method_midtrans"
             <?= $defaultMethod === 'midtrans' ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Bayar via Midtrans</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Transfer Bank, GoPay/ShopeePay, QRIS, Kartu Kredit</p>
      </div>
      <span class="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-md bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Otomatis</span>
    </label>
    <?php endif; ?>

    <?php if ($tripayActive): ?>
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="tripay" id="method_tripay"
             <?= $defaultMethod === 'tripay' ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Bayar via Tripay</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">VA Bank, QRIS, E-wallet, Alfamart — pilih di bawah</p>
      </div>
      <span class="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-md bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Otomatis</span>
    </label>
    <!-- Pemilih channel Tripay (muncul saat Tripay dipilih) -->
    <div id="tripay-channels" class="<?= $defaultMethod === 'tripay' ? '' : 'hidden' ?> pl-1 space-y-1.5">
      <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Pilih metode pembayaran:</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <?php foreach ($tripayChannels as $i => $ch): ?>
        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_6%,transparent)] transition">
          <input type="radio" name="tripay_channel" value="<?= e($ch['code']) ?>" <?= $i === 0 ? 'checked' : '' ?> class="checkout-radio shrink-0">
          <?php if (!empty($ch['icon'])): ?><img src="<?= e($ch['icon']) ?>" alt="" class="w-8 h-5 object-contain shrink-0"><?php endif; ?>
          <span class="text-sm text-gray-800 dark:text-gray-200 truncate"><?= e($ch['name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($mootaActive): ?>
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="moota" id="method_moota"
             <?= $defaultMethod === 'moota' ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Transfer Bank</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Transfer ke rekening kami — pembayaran terkonfirmasi otomatis</p>
      </div>
      <span class="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-md bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Otomatis</span>
    </label>
    <?php endif; ?>

    <?php if ($paypalActive): ?>
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="paypal" id="method_paypal"
             <?= $defaultMethod === 'paypal' ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Bayar via PayPal</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Kartu / saldo PayPal — untuk pembeli luar negeri (ditagih dalam USD)</p>
      </div>
      <span class="text-xs font-semibold text-white px-2 py-0.5 rounded-full" style="background:#003087">PayPal</span>
    </label>
    <?php endif; ?>

    <?php if ($manualTransferAvailable): ?>
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="manual_transfer" id="method_manual_transfer"
             <?= $manualTransferDefault ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Transfer Bank Manual</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Transfer ke rekening kami, lalu upload bukti — dikonfirmasi admin</p>
      </div>
    </label>
    <?php endif; ?>

    <?php if (!($hasGateway ?? false) && !$manualTransferAvailable): ?>
    <!-- Transfer Manual generik: MURNI fallback terakhir — hanya tampil bila TIDAK ada
         gateway otomatis aktif DAN belum ada rekening manual. Saat gateway aktif, opsi
         ini disembunyikan agar pembeli tidak bingung memilih jalur yang lebih lambat. -->
    <label class="flex items-center gap-3 p-3.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_5%,transparent)] transition">
      <input type="radio" name="payment_method" value="manual" id="method_manual"
             <?= $plainManualDefault ? 'checked' : '' ?>
             class="checkout-radio">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-900 dark:text-white">Transfer Manual</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Admin akan mengirim instruksi pembayaran setelah order dibuat</p>
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
    <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed"><?= nl2br(e($manualPaymentInstruction)) ?></p>
    <?php endif; ?>

    <?php if ($hasBankList):
        // Checkout = cukup PILIH tujuan (chip ringkas). Nomor rekening, QR,
        // atas nama & tombol salin sengaja TIDAK di sini — belum bisa dipakai
        // bayar (order belum ada) & bikin form panjang; semuanya tampil di
        // halaman order/invoice setelah submit. Urutan bank→e-wallet sudah
        // dari query (ORDER BY channel_type), jadi grouping implisit.
        $firstId = $bankAccounts[array_key_first($bankAccounts)]['id'];
    ?>
    <div>
      <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Pilih tujuan transfer:</p>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($bankAccounts as $bank): $cType = $bank['channel_type'] ?? 'bank'; ?>
        <label class="flex items-center gap-2 pl-2.5 pr-3 py-1.5 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-[var(--brand-color)] has-[:checked]:border-[var(--brand-color)] has-[:checked]:bg-[color-mix(in_srgb,var(--brand-color)_6%,transparent)] focus-within:ring-2 transition"
               style="--tw-ring-color: color-mix(in srgb, var(--brand-color) 30%, transparent);">
          <input type="radio" name="bank_account_id" value="<?= (int) $bank['id'] ?>" <?= (int) $bank['id'] === (int) $firstId ? 'checked' : '' ?> class="sr-only">
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
<?php // Script copy-to-clipboard dihapus bersama tombol Salin — nomor rekening
      // kini hanya tampil (& bisa disalin) di halaman order/invoice. ?>
