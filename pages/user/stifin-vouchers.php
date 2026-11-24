<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();
require_once __DIR__ . '/../../helpers/stifin-promoters.php';
header('Cache-Control: no-store');

$productId = stifinVoucherProductId();
$promoterCode = stifinLinkedPromoterCode(getDB(), (int) $_SESSION['user_id']);
if ($productId === 0 && $promoterCode === null) {
    redirect('/dashboard');
}
$pageTitle = $topbarTitle = 'Voucher Tes';
$orders = [];
$unavailable = false;
$balance = null;
$balanceError = false;
$canCheckout = false;
$pendingOrders = [];
if ($promoterCode !== null && ($_GET['cek_saldo'] ?? '') === '1') {
    try {
        $balance = stifinPromoterBalance($promoterCode);
    } catch (RuntimeException $error) {
        $balanceError = true;
    }
}
try {
    if ($productId > 0) {
        $checkout = getDB()->prepare("SELECT 1 FROM products WHERE id = ? AND slug = 'voucher-tes-stifin' AND status = 'active'");
        $checkout->execute([$productId]);
        $canCheckout = (bool) $checkout->fetchColumn();
        $pending = getDB()->prepare("SELECT o.order_number, SUM(i.quantity) AS quantity
            FROM orders o JOIN order_items i ON i.order_id = o.id
            WHERE o.user_id = ? AND o.status = 'pending' AND i.product_id = ?
            GROUP BY o.id, o.order_number ORDER BY o.id DESC LIMIT 25");
        $pending->execute([(int) $_SESSION['user_id'], $productId]);
        $pendingOrders = $pending->fetchAll(PDO::FETCH_ASSOC);
        $transferStatus = stifinVoucherTransferSchema(getDB()) ? 'q.transfer_status' : 'NULL AS transfer_status';
        $stmt = getDB()->prepare("SELECT o.order_number, q.quantity, q.fulfilled_at, s.code, {$transferStatus}
            FROM stifin_voucher_queue q
            JOIN orders o ON o.id = q.order_id AND o.user_id = q.user_id
            LEFT JOIN stifin_voucher_stock s ON s.queue_id = q.id
            WHERE q.user_id = ? AND o.status = 'paid' ORDER BY q.id DESC, s.id");
        $stmt->execute([(int) $_SESSION['user_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $number = $row['order_number'];
            $orders[$number]['quantity'] = $row['quantity'];
            $orders[$number]['transfer_status'] = $row['transfer_status'];
            $orders[$number]['codes'] ??= [];
            if ($row['fulfilled_at'] !== null && $row['code'] !== null) {
                $orders[$number]['codes'][] = $row['code'];
            }
        }
    }
} catch (Throwable $error) {
    $unavailable = true;
    error_log('[stifin-voucher] Riwayat member tidak tersedia.');
}
include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <div class="max-w-4xl space-y-4">
        <div class="p-5 rounded-xl bg-gray-900 text-white">
          <h1 class="text-2xl font-bold">Voucher Tes STIFIn</h1>
          <p class="mt-2">Cek saldo STIFIn dan status voucher dari pesanan yang sudah lunas.</p>
        </div>
        <?php if ($promoterCode !== null): ?>
          <section class="p-5 rounded-xl bg-white dark:bg-gray-900 border">
            <h2 class="font-semibold">Saldo STIFIn pusat</h2>
            <p>Kode promotor <?= e($promoterCode) ?></p>
            <?php if ($balance !== null): ?>
              <p class="mt-2 font-semibold">Voucher <?= (int) $balance['voucher'] ?> · Free <?= e((string) ($balance['free'] ?? '—')) ?></p>
            <?php elseif ($balanceError): ?>
              <p role="alert">Saldo belum bisa dimuat. Silakan coba lagi.</p>
            <?php endif; ?>
            <a class="inline-block mt-3 underline" href="<?= e(APP_URL) ?>/member/voucher-tes?cek_saldo=1">Cek saldo</a>
          </section>
        <?php endif; ?>
        <?php if ($canCheckout && (!stifinVoucherTransferEnabled() || $promoterCode !== null)): ?>
          <section class="rounded-xl border bg-white p-5 dark:bg-gray-900 space-y-3">
            <h2 class="text-lg font-semibold">Beli voucher</h2>
            <?php if (stifinVoucherTransferEnabled()): ?><p>Voucher masuk ke saldo STIFIn setelah pembayaran terverifikasi.</p><?php endif; ?>
            <form method="get" action="<?= e(APP_URL) ?>/checkout" class="flex flex-wrap items-end gap-3">
              <input type="hidden" name="product_id" value="<?= $productId ?>">
              <label>Jumlah voucher
                <input class="block w-32 rounded-lg border p-2 dark:bg-gray-950" type="number" name="jumlah" min="1" max="1000" value="1" required>
              </label>
              <button class="rounded-lg bg-gray-900 px-4 py-2 text-white dark:bg-gray-700">Lanjut ke pembayaran</button>
            </form>
          </section>
        <?php elseif ($canCheckout && $promoterCode === null): ?>
          <p>Akun promotor perlu ditautkan oleh admin cabang sebelum Anda bisa membeli voucher.</p>
        <?php endif; ?>
        <?php if ($pendingOrders): ?>
          <section class="rounded-xl border bg-white p-5 dark:bg-gray-900 space-y-3">
            <h2 class="font-semibold">Menunggu konfirmasi pembayaran</h2>
            <ul class="space-y-2">
              <?php foreach ($pendingOrders as $invoice): ?>
                <li>
                  <a class="underline" href="<?= e(APP_URL . '/transactions/' . rawurlencode($invoice['order_number'])) ?>"><?= e($invoice['order_number']) ?></a>
                  · <?= (int) $invoice['quantity'] ?> voucher
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>
        <?php if ($unavailable): ?>
          <p role="alert">Data voucher sementara tidak tersedia. Silakan coba lagi.</p>
        <?php elseif ($productId > 0 && !$orders): ?>
          <p>Belum ada pesanan voucher yang lunas.</p>
          <a class="underline" href="<?= e(APP_URL) ?>/katalog">Buka katalog</a>
        <?php endif; ?>
        <?php foreach ($orders as $number => $order): ?>
          <section class="p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white">
            <h2 class="font-semibold">Pesanan <?= e((string) $number) ?></h2>
            <p class="mt-1"><?= (int) $order['quantity'] ?> voucher</p>
            <?php if ($order['transfer_status'] === 'sent'): ?>
              <p class="mt-2">Voucher sudah masuk ke saldo STIFIn.</p>
            <?php elseif ($order['transfer_status'] === 'review'): ?>
              <p class="mt-2">Lunas · Transfer voucher sedang diperiksa.</p>
            <?php elseif ($order['transfer_status'] === 'pending'): ?>
              <p class="mt-2">Lunas · Pengisian saldo dijadwalkan otomatis.</p>
            <?php elseif (!$order['codes']): ?>
              <p class="mt-2">Lunas · Menunggu alokasi voucher.</p>
            <?php else: ?>
              <ul class="mt-3 space-y-2">
                <?php foreach ($order['codes'] as $code): ?>
                  <li class="p-2 rounded-lg bg-gray-100 dark:bg-gray-800"><code class="break-all"><?= e($code) ?></code></li>
                <?php endforeach; ?>
              </ul>
              <p class="mt-3 text-sm">Gunakan voucher sesuai prosedur tes resmi cabang.</p>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
