<?php
// Admin — JV Partner: rekap bagi hasil per partner (produk JV), tandai dibayar.
// Uang tetap masuk rekening pemilik instalasi — pembayaran ke partner MANUAL oleh
// admin (transfer sendiri), halaman ini hanya pembukuan. Jatah dicatat otomatis oleh
// jvRecordEarning() (helpers.php) saat order produk JV lunas.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();

// ═══ POST HANDLER (PRG) ═════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        flash('jv', 'Token keamanan tidak valid.', 'error');
        redirect('/admin/jv-partner');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_paid') {
        // Batch: semua baris unpaid milik SATU partner ditandai paid sekaligus.
        $partnerId = (int) ($_POST['partner_id'] ?? 0);
        if ($partnerId > 0) {
            $st = $pdo->prepare("UPDATE jv_earnings SET status = 'paid', paid_at = NOW() WHERE partner_user_id = ? AND status = 'unpaid'");
            $st->execute([$partnerId]);
            flash('jv', $st->rowCount() . ' baris ditandai sudah dibayar.', 'success');
        }
        redirect('/admin/jv-partner');
    }

    if ($action === 'cancel') {
        // Batalkan 1 baris (mis. order di-refund) — TIDAK otomatis, sadar oleh admin.
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE jv_earnings SET status = 'cancelled' WHERE id = ? AND status = 'unpaid'")->execute([$id]);
            flash('jv', 'Baris dibatalkan.', 'success');
        }
        redirect('/admin/jv-partner');
    }

    redirect('/admin/jv-partner');
}

// ═══ GET DISPLAY ════════════════════════════════════════════════
$pageTitle   = 'JV Partner';
$topbarTitle = 'JV Partner';

// Rekap per partner: total unpaid + paid + jumlah order.
$partners = [];
try {
    $partners = $pdo->query(
        "SELECT u.id, u.name, u.email,
                SUM(CASE WHEN je.status = 'unpaid' THEN je.amount ELSE 0 END) AS unpaid_total,
                SUM(CASE WHEN je.status = 'paid'   THEN je.amount ELSE 0 END) AS paid_total,
                COUNT(CASE WHEN je.status = 'unpaid' THEN 1 END) AS unpaid_count,
                COUNT(*) AS total_count
         FROM jv_earnings je
         JOIN users u ON u.id = je.partner_user_id
         GROUP BY u.id, u.name, u.email
         ORDER BY unpaid_total DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('jv-partner recap: ' . $e->getMessage()); }

// Detail per order (utk partner yang sedang dibuka via ?partner=)
$openPartnerId = (int) ($_GET['partner'] ?? 0);
$rows = [];
if ($openPartnerId > 0) {
    try {
        $st = $pdo->prepare(
            "SELECT je.*, o.order_number, p.name AS product_name
             FROM jv_earnings je
             JOIN orders o ON o.id = je.order_id
             JOIN products p ON p.id = je.product_id
             WHERE je.partner_user_id = ?
             ORDER BY je.created_at DESC
             LIMIT 100"
        );
        $st->execute([$openPartnerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { error_log('jv-partner detail: ' . $e->getMessage()); }
}

// Produk ber-JV aktif (ringkasan pengaturan) — sanity check admin.
$jvProducts = [];
try {
    $jvProducts = $pdo->query(
        "SELECT p.id, p.name, p.jv_partner_percent, u.name AS partner_name, u.email AS partner_email
         FROM products p JOIN users u ON u.id = p.jv_partner_user_id
         WHERE p.jv_partner_user_id IS NOT NULL AND p.jv_partner_percent > 0
         ORDER BY p.name"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

generateCSRF();
$cardCls = 'bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php
      if (($f = getFlash('jv')) !== null) {
          $isErr = ($f['type'] ?? '') === 'error';
          $wrap  = $isErr
              ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'
              : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300';
          echo '<div class="mb-4 px-4 py-3 rounded-xl border text-sm font-medium ' . $wrap . '">' . e($f['message']) . '</div>';
      }
      ?>

      <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">JV Partner</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Bagi hasil penjualan dengan partner (Joint Venture). Uang tetap masuk ke rekeningmu — jatah partner dicatat otomatis, pembayaran dilakukan manual olehmu.</p>
      </div>

      <?php if ($jvProducts): ?>
      <div class="<?= $cardCls ?> p-5 mb-6">
        <p class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Produk dengan JV Partner aktif</p>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($jvProducts as $jp): ?>
          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300">
            <strong class="text-gray-800 dark:text-gray-200"><?= e($jp['name']) ?></strong>
            <span>&middot; <?= e($jp['partner_name']) ?> (<?= e((string) round((float) $jp['jv_partner_percent'], 2)) ?>%)</span>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="<?= $cardCls ?> overflow-hidden mb-6">
        <?php if (!$partners): ?>
        <div class="p-10 text-center">
          <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada catatan bagi hasil</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Set partner &amp; persen di halaman <a href="<?= e(APP_URL) ?>/admin/products" class="text-[var(--brand-color)] hover:underline">Produk</a> (tab Harga &amp; Akses) — jatah tercatat otomatis saat ada penjualan lunas.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500 border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-3">Partner</th>
                <th class="px-4 py-3 text-right">Belum Dibayar</th>
                <th class="px-4 py-3 text-right">Sudah Dibayar</th>
                <th class="px-4 py-3 text-center">Order</th>
                <th class="px-4 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($partners as $pt): ?>
              <tr class="border-b border-gray-50 dark:border-gray-800/60 <?= $openPartnerId === (int) $pt['id'] ? 'bg-gray-50 dark:bg-gray-800/40' : '' ?>">
                <td class="px-4 py-3.5">
                  <a href="<?= e(APP_URL) ?>/admin/jv-partner?partner=<?= (int) $pt['id'] ?>" class="font-medium text-gray-900 dark:text-white hover:text-[var(--brand-color)] transition">
                    <?= e($pt['name']) ?>
                  </a>
                  <p class="text-xs text-gray-400 dark:text-gray-500"><?= e($pt['email']) ?></p>
                </td>
                <td class="px-4 py-3.5 text-right font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah((float) $pt['unpaid_total'])) ?></td>
                <td class="px-4 py-3.5 text-right text-gray-500 dark:text-gray-400"><?= e(formatRupiah((float) $pt['paid_total'])) ?></td>
                <td class="px-4 py-3.5 text-center text-gray-600 dark:text-gray-300"><?= (int) $pt['total_count'] ?></td>
                <td class="px-4 py-3.5 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <a href="<?= e(APP_URL) ?>/admin/jv-partner?partner=<?= (int) $pt['id'] ?>" class="text-xs font-medium text-[var(--brand-color)] hover:underline">Detail</a>
                    <?php if ((float) $pt['unpaid_total'] > 0): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/admin/jv-partner" class="inline" id="jvpay-<?= (int) $pt['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="mark_paid">
                      <input type="hidden" name="partner_id" value="<?= (int) $pt['id'] ?>">
                      <button type="button" onclick="confirmAndSubmit('jvpay-<?= (int) $pt['id'] ?>', {title:'Tandai Sudah Dibayar?', message:'Semua baris belum-dibayar milik <?= e(addslashes($pt['name'])) ?> (<?= e(formatRupiah((float) $pt['unpaid_total'])) ?>) ditandai lunas. Pastikan transfer sudah dilakukan.', confirmText:'Ya, Tandai Dibayar'})" class="text-xs font-medium text-green-600 hover:underline">Tandai Dibayar</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($openPartnerId > 0): ?>
      <div class="<?= $cardCls ?> overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
          <p class="text-sm font-semibold text-gray-900 dark:text-white">Rincian order</p>
          <a href="<?= e(APP_URL) ?>/admin/jv-partner" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">Tutup</a>
        </div>
        <?php if (!$rows): ?>
        <p class="px-5 py-8 text-center text-xs text-gray-400">Belum ada order.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500 border-b border-gray-100 dark:border-gray-800">
                <th class="px-4 py-2.5">Order</th>
                <th class="px-4 py-2.5">Produk</th>
                <th class="px-4 py-2.5 text-right">Total</th>
                <th class="px-4 py-2.5 text-right">Komisi Afiliasi</th>
                <th class="px-4 py-2.5 text-right">Jatah Partner</th>
                <th class="px-4 py-2.5">Status</th>
                <th class="px-4 py-2.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr class="border-b border-gray-50 dark:border-gray-800/60">
                <td class="px-4 py-2.5 font-mono text-xs text-gray-600 dark:text-gray-300"><?= e($r['order_number']) ?></td>
                <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300 max-w-[180px] truncate"><?= e($r['product_name']) ?></td>
                <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400"><?= e(formatRupiah((float) $r['order_total'])) ?></td>
                <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400"><?= e(formatRupiah((float) $r['affiliate_commission'])) ?></td>
                <td class="px-4 py-2.5 text-right font-semibold text-gray-900 dark:text-white"><?= e(formatRupiah((float) $r['amount'])) ?> <span class="text-[10px] text-gray-400">(<?= e((string) round((float) $r['percent'], 2)) ?>%)</span></td>
                <td class="px-4 py-2.5">
                  <?php $badge = ['unpaid' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'paid' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'cancelled' => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'][$r['status']] ?? ''; ?>
                  <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $badge ?>"><?= e(ucfirst($r['status'])) ?></span>
                </td>
                <td class="px-4 py-2.5 text-right">
                  <?php if ($r['status'] === 'unpaid'): ?>
                  <form method="POST" action="<?= e(APP_URL) ?>/admin/jv-partner?partner=<?= $openPartnerId ?>" class="inline" id="jvcancel-<?= (int) $r['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="button" onclick="confirmAndSubmit('jvcancel-<?= (int) $r['id'] ?>', {title:'Batalkan Baris?', message:'Dipakai bila order ini di-refund/dibatalkan. Jatah partner untuk order ini tidak akan ditagihkan.', confirmText:'Ya, Batalkan', variant:'danger'})" class="text-xs font-medium text-red-500 hover:underline">Batalkan</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
