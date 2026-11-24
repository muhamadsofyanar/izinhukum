<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

// PERTAHANKAN '-' dan '_': order hasil import/migrasi berformat 'INV-20-...-hex'
// atau 'SEJOLI-123'. Membuang '-' (versi lama) membuat lookup gagal → 404.
// Dipakai di prepared statement, jadi aman. Tak di-uppercase agar cocok apa adanya.
$orderNumber = preg_replace('/[^A-Za-z0-9_-]/', '', trim($_GET['order_number'] ?? ''));
if ($orderNumber === '') {
    redirect('/admin/transactions');
}

$order   = null;
$items   = [];
$payment = null;
$commissions = [];

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
         FROM orders o
         JOIN users u ON o.user_id = u.id
         WHERE o.order_number = ?
         LIMIT 1'
    );
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('error', 'Order tidak ditemukan.', 'error');
        redirect('/admin/transactions');
    }

    $stmt = $pdo->prepare(
        'SELECT oi.*, p.slug AS product_slug, p.type AS product_type, p.thumbnail
         FROM order_items oi
         LEFT JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id = ?
         ORDER BY oi.id'
    );
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll();

    // Nama bundle bila ini order paket (order_items semuanya is_bundle=1) → dipakai
    // sbg header "Paket" di atas daftar produk agar konsisten dgn kolom di /admin/transactions.
    $orderBundleName = '';
    foreach ($items as $it) {
        if ((int) ($it['is_bundle'] ?? 0) === 1 && (int) ($it['bundle_id'] ?? 0) > 0) {
            $bs = $pdo->prepare('SELECT name FROM product_bundles WHERE id = ? LIMIT 1');
            $bs->execute([(int) $it['bundle_id']]);
            $orderBundleName = (string) ($bs->fetchColumn() ?: '');
            break;
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? LIMIT 1');
    $stmt->execute([$order['id']]);
    $payment = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare(
        'SELECT c.*, u.name AS affiliate_name, u.email AS affiliate_email
         FROM commissions c
         JOIN affiliates a ON c.affiliate_id = a.id
         JOIN users u ON a.user_id = u.id
         WHERE c.order_id = ?'
    );
    $stmt->execute([$order['id']]);
    $commissions = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Admin transaction detail error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat memuat detail transaksi.', 'error');
    redirect('/admin/transactions');
}

$pageTitle   = 'Order ' . $orderNumber;
$topbarTitle = 'Detail Transaksi';

$statusConfig = match ($order['status']) {
    'paid'      => ['label' => 'Lunas',      'bg' => 'bg-green-50 dark:bg-green-900/10',   'text' => 'text-green-700 dark:text-green-400',  'border' => 'border-green-200 dark:border-green-800/40'],
    'pending'   => ['label' => 'Menunggu',   'bg' => 'bg-yellow-50 dark:bg-yellow-900/10', 'text' => 'text-yellow-700 dark:text-yellow-400','border' => 'border-yellow-200 dark:border-yellow-800/40'],
    'failed'    => ['label' => 'Gagal',      'bg' => 'bg-red-50 dark:bg-red-900/10',       'text' => 'text-red-700 dark:text-red-400',      'border' => 'border-red-200 dark:border-red-800/40'],
    'cancelled' => ['label' => 'Dibatalkan', 'bg' => 'bg-gray-100 dark:bg-gray-800',       'text' => 'text-gray-600 dark:text-gray-400',    'border' => 'border-gray-200 dark:border-gray-700'],
    'expired'   => ['label' => 'Kedaluwarsa', 'bg' => 'bg-orange-50 dark:bg-orange-900/10',  'text' => 'text-orange-700 dark:text-orange-400','border' => 'border-orange-200 dark:border-orange-800/40'],
    default     => ['label' => ucfirst($order['status']), 'bg' => 'bg-gray-100', 'text' => 'text-gray-600', 'border' => 'border-gray-200'],
};

$statusIcon = match ($order['status']) {
    'paid'    => '<svg class="w-6 h-6 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    'pending' => '<svg class="w-6 h-6 text-yellow-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    default   => '<svg class="w-6 h-6 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
};

generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Back + Edit -->
      <div class="flex items-center justify-between gap-3 mb-5">
        <a href="<?= e(APP_URL) ?>/admin/transactions"
           class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Kembali ke Transaksi
        </a>
        <button type="button" onclick="document.getElementById('editTxnModal').classList.remove('hidden')"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Edit Transaksi
        </button>
      </div>

      <div class="max-w-2xl space-y-4">

        <!-- Status Banner -->
        <div class="<?= $statusConfig['bg'] ?> <?= $statusConfig['border'] ?> border rounded-xl p-5 flex items-center gap-4">
          <div class="w-12 h-12 rounded-full bg-white/60 dark:bg-black/20 flex items-center justify-center shrink-0">
            <?= $statusIcon ?>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="<?= $statusConfig['text'] ?> font-bold text-base"><?= $statusConfig['label'] ?></p>
              <span class="font-mono text-xs text-gray-500 dark:text-gray-400 bg-white/60 dark:bg-black/20 px-2 py-0.5 rounded">
                <?= e($order['order_number']) ?>
              </span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
              <?= e(date('d M Y, H:i', strtotime($order['created_at']))) ?>
              <?php if ($order['paid_at']): ?>
              &middot; Dibayar: <?= e(date('d M Y, H:i', strtotime($order['paid_at']))) ?>
              <?php endif; ?>
            </p>
          </div>
          <!-- Quick Actions -->
          <?php if ($order['status'] === 'pending'): ?>
          <div class="flex items-center gap-2 shrink-0">
            <form id="form-confirm-pay" method="POST" action="<?= e(APP_URL) ?>/actions/admin/update-transaction">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="redirect_to" value="detail">
              <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
              <input type="hidden" name="status" value="paid">
              <button type="button"
                      onclick="confirmAndSubmit('form-confirm-pay',{title:'Konfirmasi Pembayaran?',message:'Order ini akan ditandai sebagai lunas dan akses member akan diaktifkan.',confirmText:'Ya, Konfirmasi',variant:'success'})"
                      class="flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-sm font-semibold text-white bg-green-600 hover:bg-green-700 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Konfirmasi
              </button>
            </form>
            <form id="form-cancel-order" method="POST" action="<?= e(APP_URL) ?>/actions/admin/update-transaction">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="redirect_to" value="detail">
              <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
              <input type="hidden" name="status" value="cancelled">
              <button type="button"
                      onclick="confirmAndSubmit('form-cancel-order',{title:'Batalkan Order?',message:'Order ini akan dibatalkan dan tidak dapat dikembalikan ke status pending.',confirmText:'Ya, Batalkan',variant:'danger'})"
                      class="flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-sm font-semibold border border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Batalkan
              </button>
            </form>
          </div>
          <?php endif; ?>
        </div>

        <?php
        // ─── Konfirmasi Pembayaran Manual (manual_transfer + pending) ───
        if (($payment['method'] ?? '') === 'manual_transfer' && $order['status'] === 'pending'):
            $mtBank = null;
            if (!empty($payment['bank_account_id'])) {
                $mtb = getDB()->prepare("SELECT * FROM bank_accounts WHERE id = ? LIMIT 1");
                $mtb->execute([(int) $payment['bank_account_id']]);
                $mtBank = $mtb->fetch() ?: null;
            }
            $hasProof   = !empty($payment['proof_file']);
            $proofIsPdf = $hasProof && preg_match('/\.pdf$/i', $payment['proof_file']);
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border-2 border-amber-300 dark:border-amber-700/50 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 bg-amber-50 dark:bg-amber-900/10">
            <h3 class="font-semibold text-amber-800 dark:text-amber-300 text-sm">Konfirmasi Pembayaran Manual</h3>
          </div>
          <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <p class="text-xs text-gray-400 dark:text-gray-600 mb-1">Rekening Tujuan (dipilih user)</p>
                <?php if ($mtBank): ?>
                <p class="font-semibold text-gray-900 dark:text-white"><?= e($mtBank['bank_name']) ?></p>
                <p class="font-mono text-gray-700 dark:text-gray-300"><?= e($mtBank['account_number']) ?></p>
                <p class="text-gray-500 dark:text-gray-400"><?= e($mtBank['account_name']) ?></p>
                <?php else: ?>
                <p class="text-gray-400">—</p>
                <?php endif; ?>
              </div>
              <div>
                <p class="text-xs text-gray-400 dark:text-gray-600 mb-1">Waktu Upload Bukti</p>
                <p class="font-medium text-gray-700 dark:text-gray-300"><?= $payment['proof_uploaded_at'] ? e(date('d M Y, H:i', strtotime($payment['proof_uploaded_at']))) : 'Belum diupload' ?></p>
              </div>
            </div>

            <div>
              <p class="text-xs text-gray-400 dark:text-gray-600 mb-1.5">Bukti Transfer</p>
              <?php if (!$hasProof): ?>
              <p class="text-sm text-gray-500 dark:text-gray-400 italic">User belum mengupload bukti transfer.</p>
              <?php elseif ($proofIsPdf): ?>
              <a href="<?= e($payment['proof_file']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--brand-color)] hover:underline">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Lihat Bukti Transfer (PDF)
              </a>
              <?php else: ?>
              <a href="<?= e($payment['proof_file']) ?>" target="_blank" rel="noopener">
                <img src="<?= e($payment['proof_file']) ?>" alt="Bukti transfer" class="max-w-xs rounded-lg border border-gray-200 dark:border-gray-700">
              </a>
              <?php endif; ?>
            </div>

            <?php if ($hasProof): ?>
            <div class="flex flex-wrap items-stretch gap-3 pt-1">
              <form id="form-confirm-payment" method="POST" action="<?= e(APP_URL) ?>/actions/admin/confirm-payment">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button type="button"
                        onclick="confirmAndSubmit('form-confirm-payment',{title:'Konfirmasi Pembayaran?',message:'Order ditandai LUNAS dan akses member diaktifkan. Tindakan ini final.',confirmText:'Ya, Konfirmasi',variant:'success'})"
                        class="px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-green-600 hover:bg-green-700 transition">
                  ✓ Konfirmasi Pembayaran
                </button>
              </form>
            </div>
            <form id="form-reject-proof" method="POST" action="<?= e(APP_URL) ?>/actions/admin/reject-payment" class="space-y-2 pt-1">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Alasan penolakan (akan ditampilkan ke user)</label>
              <textarea name="rejection_reason" rows="2" maxlength="255" placeholder="contoh: Nominal tidak sesuai / bukti tidak terbaca"
                        class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white resize-none"></textarea>
              <button type="button"
                      onclick="confirmAndSubmit('form-reject-proof',{title:'Tolak Bukti Transfer?',message:'Bukti dihapus dan user diminta upload ulang. Order tetap pending.',confirmText:'Ya, Tolak',variant:'danger'})"
                      class="px-4 py-2 rounded-lg text-sm font-semibold text-red-600 border border-red-300 dark:border-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                ✗ Tolak / Minta Ulang
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Pembeli -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Informasi Pembeli</h3>
          </div>
          <div class="p-5 grid grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-xs text-gray-400 dark:text-gray-600 mb-0.5">Nama</p>
              <p class="font-semibold text-gray-900 dark:text-white"><?= e($order['user_name']) ?></p>
            </div>
            <div>
              <p class="text-xs text-gray-400 dark:text-gray-600 mb-0.5">Email</p>
              <a href="mailto:<?= e($order['user_email']) ?>" class="font-medium text-[var(--brand-color)] hover:underline">
                <?= e($order['user_email']) ?>
              </a>
            </div>
            <?php if ($order['user_phone']): ?>
            <div>
              <p class="text-xs text-gray-400 dark:text-gray-600 mb-0.5">Telepon</p>
              <p class="font-medium text-gray-700 dark:text-gray-300"><?= e($order['user_phone']) ?></p>
            </div>
            <?php endif; ?>
            <?php if ($order['referral_code']): ?>
            <div>
              <p class="text-xs text-gray-400 dark:text-gray-600 mb-0.5">Kode Referral</p>
              <p class="font-mono font-bold text-[var(--brand-color)]"><?= e($order['referral_code']) ?></p>
            </div>
            <?php endif; ?>
            <?php if ($order['notes']): ?>
            <div class="col-span-2">
              <p class="text-xs text-gray-400 dark:text-gray-600 mb-0.5">Catatan Pembeli</p>
              <p class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed"><?= e($order['notes']) ?></p>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Produk Dipesan -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Produk Dipesan</h3>
            <?php if ($orderBundleName !== ''): ?>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent);color:var(--brand-color)">
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>
              Paket: <?= e($orderBundleName) ?>
            </span>
            <?php endif; ?>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($items as $item): ?>
            <div class="flex items-center gap-4 p-4">
              <?php if (!empty($item['thumbnail'])): ?>
              <img src="<?= e($item['thumbnail']) ?>" alt="" class="w-12 h-12 rounded-lg object-cover shrink-0">
              <?php else: ?>
              <div class="w-12 h-12 rounded-lg bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
              </div>
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($item['product_name']) ?></p>
                <?php if (!empty($item['product_type'])): ?>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 capitalize"><?= e($item['product_type']) ?></p>
                <?php endif; ?>
              </div>
              <div class="text-right shrink-0">
                <p class="font-semibold text-gray-900 dark:text-white text-sm">
                  <?= (float)$item['price'] > 0 ? e(formatRupiah((float)$item['price'])) : '<span class="text-green-600 dark:text-green-400">Gratis</span>' ?>
                </p>
                <?php if ($item['quantity'] > 1): ?>
                <p class="text-xs text-gray-400">x<?= (int)$item['quantity'] ?></p>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Ringkasan Pembayaran -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <h3 class="font-semibold text-gray-900 dark:text-white text-sm mb-4">Ringkasan Pembayaran</h3>
          <div class="space-y-2.5 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
              <span class="text-gray-800 dark:text-gray-200"><?= e(formatRupiah((float)$order['subtotal'])) ?></span>
            </div>
            <?php if ((float)$order['discount'] > 0): ?>
            <div class="flex justify-between">
              <span class="text-gray-500 dark:text-gray-400">Diskon</span>
              <span class="text-green-600 dark:text-green-400">-<?= e(formatRupiah((float)$order['discount'])) ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between font-bold text-base pt-2.5 border-t border-gray-100 dark:border-gray-800">
              <span class="text-gray-900 dark:text-white">Total</span>
              <span class="text-[var(--brand-color)]">
                <?= (float)$order['total'] > 0 ? e(formatRupiah((float)$order['total'])) : 'GRATIS' ?>
              </span>
            </div>
          </div>

          <?php if ($payment): ?>
          <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 grid grid-cols-2 gap-3 text-xs">
            <div>
              <p class="text-gray-400 dark:text-gray-600 mb-0.5">Metode</p>
              <p class="font-semibold text-gray-700 dark:text-gray-300 capitalize"><?= e($payment['method']) ?></p>
            </div>
            <div>
              <p class="text-gray-400 dark:text-gray-600 mb-0.5">Status Bayar</p>
              <p class="font-semibold text-gray-700 dark:text-gray-300 capitalize"><?= e($payment['status']) ?></p>
            </div>
            <?php if ($payment['gateway_ref']): ?>
            <div class="col-span-2">
              <p class="text-gray-400 dark:text-gray-600 mb-0.5">Referensi Gateway</p>
              <p class="font-mono text-gray-700 dark:text-gray-300"><?= e($payment['gateway_ref']) ?></p>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Komisi Afiliasi -->
        <?php if (!empty($commissions)): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Komisi Afiliasi</h3>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($commissions as $com):
            $comStatusClass = match($com['status']) {
                'approved' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                'paid'     => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                default    => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
            };
            ?>
            <div class="flex items-center justify-between px-5 py-3.5 text-sm">
              <div>
                <p class="font-medium text-gray-900 dark:text-white"><?= e($com['affiliate_name']) ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-600"><?= e($com['affiliate_email']) ?> &middot; Rate <?= number_format((float)$com['rate'], 1) ?>%</p>
              </div>
              <div class="flex items-center gap-3">
                <span class="font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah((float)$com['amount'])) ?></span>
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold <?= $comStatusClass ?>">
                  <?= ucfirst($com['status']) ?>
                </span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Update Status Manual -->
        <?php if ($order['status'] !== 'pending'): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Ubah Status</h3>
          </div>
          <form id="form-update-status" method="POST" action="<?= e(APP_URL) ?>/actions/admin/update-transaction" class="p-5 flex items-center gap-3">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="redirect_to" value="detail">
            <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
            <select name="status"
                    class="flex-1 max-w-xs px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
              <?php foreach (['pending', 'paid', 'failed', 'cancelled'] as $st): ?>
              <option value="<?= $st ?>" <?= $order['status'] === $st ? 'selected' : '' ?>>
                <?= match($st) { 'paid' => 'Lunas', 'pending' => 'Menunggu', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan' } ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="button"
                    onclick="confirmAndSubmit('form-update-status',{title:'Ubah Status Order?',message:'Status order akan diperbarui. Aksi ini memengaruhi akses member.',confirmText:'Ya, Update',variant:'warning'})"
                    class="px-4 py-2.5 rounded-lg text-sm font-semibold text-white hover:opacity-90 transition"
                    style="background-color: var(--brand-color);">
              Update Status
            </button>
          </form>
        </div>
        <?php endif; ?>

        <!-- Kirim Ulang Notifikasi (Lunas → konfirmasi bayar; Menunggu → instruksi/pengingat bayar) -->
        <?php if (in_array($order['status'], ['paid', 'pending'], true)):
          $buyerEmail = trim((string) ($order['user_email'] ?? ''));
          $buyerPhone = trim((string) ($order['user_phone'] ?? ''));
          $btnBase    = 'inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold transition';
          $isPaidOrder   = $order['status'] === 'paid';
          $notifLabel    = $isPaidOrder ? 'pembayaran berhasil' : 'instruksi / pengingat pembayaran';
          $waMsgLabel    = $isPaidOrder ? 'Pesan WhatsApp konfirmasi pembayaran' : 'Pesan WhatsApp pengingat/instruksi pembayaran';
          $emailMsgLabel = $isPaidOrder ? 'Email konfirmasi pembayaran' : 'Email pengingat/instruksi pembayaran';
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Kirim Ulang Notifikasi</h3>
          </div>
          <div class="p-5 space-y-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">Kirim ulang notifikasi <strong><?= e($notifLabel) ?></strong> ke pembeli. Pilih channel.</p>
            <div class="flex flex-wrap gap-2.5">
              <!-- WhatsApp -->
              <form id="form-resend-wa" method="POST" action="<?= e(APP_URL) ?>/actions/admin/resend-order-notification" class="inline">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
                <input type="hidden" name="channel" value="whatsapp">
                <?php if ($buyerPhone !== ''): ?>
                <button type="button"
                        onclick="confirmAndSubmit('form-resend-wa',{title:'Kirim Ulang via WhatsApp?',message:'<?= e($waMsgLabel) ?> akan dikirim ulang ke pembeli (<?= e($buyerPhone) ?>).',confirmText:'Ya, Kirim',variant:'warning'})"
                        class="<?= $btnBase ?> text-white hover:opacity-90" style="background-color:#25D366">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.5 0-9.96 4.46-9.96 9.96 0 1.76.46 3.48 1.34 5L2 22l5.2-1.36c1.46.8 3.1 1.22 4.84 1.22h.01c5.5 0 9.96-4.46 9.96-9.96 0-2.66-1.04-5.16-2.92-7.04A9.9 9.9 0 0 0 12.04 2zm5.84 14.2c-.24.68-1.42 1.32-1.96 1.36-.5.06-1.14.08-1.84-.12-.42-.12-.96-.3-1.66-.6-2.92-1.26-4.82-4.2-4.96-4.4-.14-.2-1.2-1.6-1.2-3.04 0-1.44.76-2.16 1.02-2.44.26-.28.58-.36.78-.36l.56.01c.18 0 .42-.06.66.5.24.58.82 2 .9 2.14.06.14.1.3.02.5-.1.2-.14.32-.28.5-.14.18-.3.4-.42.52-.14.14-.28.3-.12.58.16.28.72 1.18 1.54 1.92 1.06.94 1.94 1.24 2.22 1.38.28.14.44.12.6-.08.16-.2.7-.82.88-1.1.18-.28.36-.24.6-.14.24.1 1.54.72 1.8.86.26.14.44.2.5.32.06.1.06.68-.18 1.36z"/></svg>
                  WhatsApp
                </button>
                <?php else: ?>
                <span class="<?= $btnBase ?> bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed" title="Nomor WhatsApp pembeli kosong">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.5 0-9.96 4.46-9.96 9.96 0 1.76.46 3.48 1.34 5L2 22l5.2-1.36c1.46.8 3.1 1.22 4.84 1.22h.01c5.5 0 9.96-4.46 9.96-9.96 0-2.66-1.04-5.16-2.92-7.04A9.9 9.9 0 0 0 12.04 2z"/></svg>
                  WhatsApp
                </span>
                <?php endif; ?>
              </form>
              <!-- Email -->
              <form id="form-resend-email" method="POST" action="<?= e(APP_URL) ?>/actions/admin/resend-order-notification" class="inline">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">
                <input type="hidden" name="channel" value="email">
                <?php if ($buyerEmail !== ''): ?>
                <button type="button"
                        onclick="confirmAndSubmit('form-resend-email',{title:'Kirim Ulang via Email?',message:'<?= e($emailMsgLabel) ?> akan dikirim ulang ke pembeli (<?= e($buyerEmail) ?>).',confirmText:'Ya, Kirim',variant:'warning'})"
                        class="<?= $btnBase ?> text-white hover:opacity-90" style="background-color:var(--brand-color)">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                  Email
                </button>
                <?php else: ?>
                <span class="<?= $btnBase ?> bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed" title="Email pembeli kosong">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                  Email
                </span>
                <?php endif; ?>
              </form>
            </div>
            <?php if ($buyerPhone === '' || $buyerEmail === ''): ?>
            <p class="text-[11px] text-amber-600 dark:text-amber-400">
              <?= $buyerPhone === '' ? 'Nomor WhatsApp pembeli kosong. ' : '' ?><?= $buyerEmail === '' ? 'Email pembeli kosong. ' : '' ?>Channel tanpa data penerima dinonaktifkan.
            </p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<?php
// Affiliate saat ini (dari komisi pending/approved) untuk prefill.
$curAffId = 0; $curAffLabel = '';
foreach ($commissions as $c) {
    if (in_array($c['status'], ['pending', 'approved'], true)) {
        $curAffId = (int) $c['affiliate_id'];
        $curAffLabel = trim(($c['affiliate_name'] ?? '') . ' — ' . ($order['referral_code'] ?? ''));
        break;
    }
}
$edInp = 'w-full h-10 px-3 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition';
?>
<!-- ─── Modal Edit Transaksi ─── -->
<div id="editTxnModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
      <h3 class="text-base font-bold text-gray-900 dark:text-white">Edit Transaksi</h3>
      <button type="button" onclick="document.getElementById('editTxnModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/edit-transaction" class="p-5 space-y-3.5">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>">

      <div>
        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Nama Pembeli</label>
        <input type="text" name="buyer_name" required maxlength="100" value="<?= e($order['user_name']) ?>" class="<?= $edInp ?>">
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Email</label>
          <input type="email" name="buyer_email" value="<?= e($order['user_email']) ?>" class="<?= $edInp ?>">
          <p class="text-[11px] text-gray-400 mt-1">Ini juga email login pembeli.</p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">No. WhatsApp</label>
          <input type="text" name="buyer_wa" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" value="<?= e($order['user_phone'] ?? '') ?>" class="<?= $edInp ?>">
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Harga (Total)</label>
          <input type="number" name="price" min="0" step="any" value="<?= e((float) $order['total']) ?>" class="<?= $edInp ?> font-mono">
          <p class="text-[11px] text-gray-400 mt-1">Total akhir order. Rincian (subtotal/diskon) disederhanakan ke nilai ini.</p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Status</label>
          <select name="status" class="<?= $edInp ?>">
            <?php foreach (['pending' => 'Menunggu', 'paid' => 'Lunas', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan'] as $sv => $sl): ?>
            <option value="<?= $sv ?>" <?= $order['status'] === $sv ? 'selected' : '' ?>><?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Affiliate (combobox pencarian; kosongkan = hapus atribusi) -->
      <div>
        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Affiliate <span class="text-gray-400 font-normal">(komisi)</span></label>
        <div class="relative">
          <input type="hidden" name="affiliate_id" id="edAff" value="<?= $curAffId ?>">
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input type="text" id="edAffSearch" class="<?= $edInp ?>" style="padding-left:2.25rem" autocomplete="off"
                 value="<?= e($curAffLabel) ?>" <?= $curAffId ? 'readonly' : '' ?> placeholder="Cari nama / email / kode affiliate...">
          <button type="button" id="edAffClear" onclick="edAffSelect(null)"
                  class="<?= $curAffId ? '' : 'hidden' ?> absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
          <div id="edAffDrop" class="hidden absolute z-20 mt-1 w-full max-h-56 overflow-y-auto bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg"></div>
        </div>
        <p class="text-[11px] text-gray-400 mt-1">Ganti/hapus affiliate akan menyesuaikan komisi (yang belum dibayar). Diblokir bila komisi sudah dibayar. Komisi baru berstatus pending.</p>
      </div>

      <div class="flex gap-2 pt-2">
        <button type="button" onclick="document.getElementById('editTxnModal').classList.add('hidden')" class="flex-1 py-2.5 border border-gray-200 dark:border-gray-700 text-sm font-medium rounded-xl text-gray-600 dark:text-gray-300">Batal</button>
        <button type="submit" class="flex-1 py-2.5 text-white text-sm font-semibold rounded-xl hover:opacity-90 transition" style="background-color:var(--brand-color)">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>
<script>
function edEsc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function edAffSelect(a) {
  var input = document.getElementById('edAffSearch');
  document.getElementById('edAff').value = a ? a.id : '';
  document.getElementById('edAffClear').classList.toggle('hidden', !a);
  input.value    = a ? (a.name + ' — ' + a.code) : '';
  input.readOnly = !!a;
  if (!a) input.focus();
}
var edAffCache = {};
function edSearchAff(q) {
  if (edAffCache[q]) return Promise.resolve(edAffCache[q]);
  return fetch('<?= e(APP_URL) ?>/actions/admin/search-affiliates?q=' + encodeURIComponent(q), {credentials:'same-origin'})
    .then(function(r){ return r.json(); }).then(function(d){ edAffCache[q] = d.affiliates || []; return edAffCache[q]; })
    .catch(function(){ return []; });
}
(function () {
  var input = document.getElementById('edAffSearch');
  var drop  = document.getElementById('edAffDrop');
  if (!input || !drop) return;
  var timer = null, reqSeq = 0;
  function render(list) {
    drop.innerHTML = list.length ? list.map(function (a) {
      return '<button type="button" data-id="' + a.id + '" class="ed-drop-item block w-full text-left px-3.5 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800">'
           + '<span class="font-medium text-gray-800 dark:text-gray-200">' + edEsc(a.name) + '</span>'
           + '<span class="block text-xs text-gray-400 dark:text-gray-500">' + edEsc(a.email) + ' · ' + edEsc(a.code) + '</span></button>';
    }).join('') : '<div class="px-3.5 py-3 text-sm text-gray-400 dark:text-gray-500">Tidak ada affiliate.</div>';
    drop.classList.remove('hidden');
    drop.dataset.list = JSON.stringify(list);
  }
  function search() {
    var q = input.value.trim(), seq = ++reqSeq;
    drop.innerHTML = '<div class="px-3.5 py-3 text-sm text-gray-400">Mencari...</div>';
    drop.classList.remove('hidden');
    edSearchAff(q).then(function (list) { if (seq === reqSeq) render(list); });
  }
  input.addEventListener('input', function () {
    document.getElementById('edAff').value = '';
    document.getElementById('edAffClear').classList.add('hidden');
    clearTimeout(timer); timer = setTimeout(search, 250);
  });
  input.addEventListener('focus', function () { if (!input.readOnly) search(); });
  document.addEventListener('mousedown', function (e) { if (!drop.contains(e.target) && e.target !== input) drop.classList.add('hidden'); });
  drop.addEventListener('click', function (e) {
    var btn = e.target.closest('.ed-drop-item'); if (!btn) return;
    var list = JSON.parse(drop.dataset.list || '[]');
    var a = list.find(function (x) { return String(x.id) === btn.dataset.id; });
    if (a) edAffSelect(a);
    drop.classList.add('hidden');
  });
})();
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
