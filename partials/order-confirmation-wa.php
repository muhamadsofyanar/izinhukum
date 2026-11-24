<?php
$adminNumber = (string) getSetting('order_confirmation_whatsapp', '');
if (!preg_match('/^[1-9][0-9]{7,14}$/D', $adminNumber)
    || !in_array($order['status'], ['pending', 'awaiting_manual', 'paid'], true)) {
    return;
}
$message = 'Halo Admin STIFIn Mulia, saya ingin mengonfirmasi pesanan ' . $order['order_number'] . '. Mohon informasi langkah berikutnya.';
?>
<div class="my-4">
  <a class="inline-block rounded-lg bg-emerald-700 text-white px-4 py-2 font-semibold" target="_blank" rel="noopener noreferrer" href="<?= e('https://wa.me/' . $adminNumber . '?text=' . rawurlencode($message)) ?>">Konfirmasi pesanan via WhatsApp</a>
  <p class="text-sm mt-2">Tekan Kirim di WhatsApp agar admin menerima nomor pesanan Anda.</p>
</div>
