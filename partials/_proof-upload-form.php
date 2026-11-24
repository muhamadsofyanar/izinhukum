<?php
// Form upload bukti transfer. Butuh $order dalam scope (di-include dari transaction-detail).
?>
<form method="POST" action="<?= e(APP_URL) ?>/actions/user/upload-proof" enctype="multipart/form-data" class="space-y-2 mt-2">
  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
  <input type="file" name="proof_file" accept="image/jpeg,image/png,application/pdf" required
         class="block w-full text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] file:text-[var(--brand-color)] hover:file:opacity-80 cursor-pointer">
  <button type="submit" data-loading data-loading-text="Mengupload..."
          class="w-full py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
    Upload Bukti Transfer
  </button>
  <p class="text-xs text-gray-400 dark:text-gray-600">Format JPG, PNG, atau PDF. Maksimal 3MB.</p>
</form>
