<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('abandoned_carts')) { redirect('/admin/no-access'); }

$pageTitle   = 'Abandoned Cart';
$topbarTitle = 'Abandoned Cart Recovery';

$processResult = processAbandonedCarts(30);

$delayHours = max(1, (int) getSetting('abandoned_cart_delay_hours', '1'));
$stats = ['pending' => 0, 'unsent' => 0, 'sent' => 0, 'recovered' => 0];
$orders = []; $logs = [];
try {
    $pdo = getDB();
    $stats['pending']   = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
    $u = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status='pending' AND recovery_remind_stage = 0 AND created_at <= NOW() - INTERVAL ? HOUR"); $u->execute([$delayHours]); $stats['unsent'] = (int) $u->fetchColumn();
    $stats['sent']      = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending' AND recovery_remind_stage > 0")->fetchColumn();
    $stats['recovered'] = (int) $pdo->query("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN abandoned_cart_logs acl ON acl.order_id=o.id WHERE o.status='paid' AND acl.status='sent'")->fetchColumn();
    $orders = $pdo->query(
        "SELECT o.id, o.order_number, o.total, o.created_at, o.recovery_sent_at, o.recovery_remind_stage, u.name AS user_name, u.email AS user_email,
                oi.product_name, TIMESTAMPDIFF(HOUR, o.created_at, NOW()) AS hours_ago
         FROM orders o JOIN users u ON u.id=o.user_id JOIN order_items oi ON oi.order_id=o.id AND oi.is_bump=0
         WHERE o.status='pending' GROUP BY o.id ORDER BY o.created_at DESC LIMIT 50"
    )->fetchAll();
    $logs = $pdo->query(
        "SELECT acl.*, o.order_number, u.name AS user_name FROM abandoned_cart_logs acl
         JOIN orders o ON o.id=acl.order_id JOIN users u ON u.id=acl.user_id ORDER BY acl.sent_at DESC LIMIT 20"
    )->fetchAll();
} catch (Exception $e) { error_log('abandoned-carts load error: ' . $e->getMessage()); }

$enabled  = getSetting('abandoned_cart_enabled', '0') === '1';
$channel  = getSetting('abandoned_cart_channel', 'email');
$provider = resolveAbandonedCartProvider($channel);

generateCSRF();
$csrf = $_SESSION['csrf_token'];
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>
      <?php if ($processResult['sent'] > 0 || $processResult['failed'] > 0): ?>
      <div class="mb-4 rounded-xl border border-green-200 dark:border-green-800/40 bg-green-50 dark:bg-green-900/10 px-4 py-3 text-sm text-green-700 dark:text-green-400">Auto-processed: <?= (int)$processResult['sent'] ?> reminder terkirim, <?= (int)$processResult['failed'] ?> gagal, <?= (int)$processResult['skipped'] ?> dilewati.</div>
      <?php endif; ?>

      <div class="mb-5"><h2 class="text-xl font-bold text-gray-900 dark:text-white">Abandoned Cart Recovery</h2><p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kirim reminder ke order pending yang belum dibayar (hingga 3x per order, dengan jeda)</p></div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['pending'] ?></p><p class="text-xs text-gray-400">Total Pending</p></div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['unsent'] ?></p><p class="text-xs text-gray-400">Belum Dapat Reminder</p></div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['sent'] ?></p><p class="text-xs text-gray-400">Sudah Dapat Reminder</p></div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-2xl font-bold text-green-600 dark:text-green-400"><?= $stats['recovered'] ?></p><p class="text-xs text-gray-400">Berhasil Recover</p></div>
      </div>

      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-abandoned-cart-settings" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div class="flex flex-wrap items-end gap-4">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="hidden" name="abandoned_cart_enabled" value="0">
            <input type="checkbox" name="abandoned_cart_enabled" value="1" <?= $enabled ? 'checked' : '' ?> class="rounded">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Aktifkan Recovery</span>
          </label>
          <div><label class="block text-xs text-gray-500 uppercase mb-1">Delay #1 (jam)</label><input type="number" name="abandoned_cart_delay_hours" min="1" max="72" value="<?= e(getSetting('abandoned_cart_delay_hours','1')) ?>" class="w-24 h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs text-gray-500 uppercase mb-1">Jeda #2 (jam)</label><input type="number" name="abandoned_cart_gap2_hours" min="1" max="168" value="<?= e(getSetting('abandoned_cart_gap2_hours','24')) ?>" class="w-24 h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs text-gray-500 uppercase mb-1">Jeda #3 (jam)</label><input type="number" name="abandoned_cart_gap3_hours" min="1" max="168" value="<?= e(getSetting('abandoned_cart_gap3_hours','48')) ?>" class="w-24 h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs text-gray-500 uppercase mb-1">Channel</label><select name="abandoned_cart_channel" class="h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><option value="email" <?= $channel==='email'?'selected':'' ?>>Email</option><option value="whatsapp" <?= $channel==='whatsapp'?'selected':'' ?>>WhatsApp</option></select></div>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan</button>
          <span class="flex-1"></span>
        </div>
        <p class="text-xs text-gray-400 mt-3">Reminder dikirim <strong>hingga 3x</strong> per order: #1 setelah <em>Delay #1</em> sejak order dibuat, #2 setelah <em>Jeda #2</em> dari reminder sebelumnya, #3 setelah <em>Jeda #3</em>. Berhenti otomatis saat order dibayar. Pakai template <strong>abandoned_cart</strong> yang bisa diedit di <a href="<?= e(APP_URL) ?>/admin/notifications" class="text-brand hover:underline">Notifikasi</a>.</p>
      </form>

      <div class="flex justify-end mb-3">
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/process-abandoned-carts"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><button type="submit" class="px-3 py-2 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Proses Sekarang</button></form>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6">
        <?php if (empty($orders)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Tidak ada order pending.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
          <th class="text-left px-5 py-3">Order</th><th class="text-left px-4 py-3">User</th><th class="text-left px-4 py-3">Produk</th><th class="text-right px-4 py-3">Total</th><th class="text-center px-4 py-3">Reminder</th><th class="text-right px-5 py-3">Aksi</th>
        </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($orders as $o):
            $late  = $o['hours_ago'] >= $delayHours;
            $stage = (int) ($o['recovery_remind_stage'] ?? 0);
            if ($stage > 0) { $b='bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'; $t='Pengingat '.$stage.'/3 · '.date('d/m', strtotime($o['recovery_sent_at'])); }
            elseif ($late) { $b='bg-gray-100 text-gray-500 dark:bg-gray-800'; $t='Belum Dikirim'; }
            else { $b='bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'; $t='Menunggu'; }
          ?>
          <tr>
            <td class="px-5 py-3"><a href="<?= e(APP_URL) ?>/admin/transactions/<?= e($o['order_number']) ?>" class="font-mono text-xs text-brand hover:underline"><?= e($o['order_number']) ?></a><p class="text-xs text-gray-400"><?= (int)$o['hours_ago'] ?> jam lalu</p></td>
            <td class="px-4 py-3"><p class="text-gray-900 dark:text-white"><?= e($o['user_name']) ?></p><p class="text-xs text-gray-400"><?= e($o['user_email']) ?></p></td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($o['product_name']) ?></td>
            <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300"><?= e(formatRupiah((float)$o['total'])) ?></td>
            <td class="px-4 py-3 text-center"><span class="text-xs px-2 py-0.5 rounded-full <?= $b ?>"><?= e($t) ?></span></td>
            <td class="px-5 py-3 text-right">
              <?php if ($stage < 3): ?>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/send-cart-recovery" class="inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200"><?= $stage > 0 ? 'Kirim Lagi' : 'Kirim Sekarang' ?></button></form>
              <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>

      <?php if (!empty($logs)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Riwayat Recovery</h3></div>
        <div class="overflow-x-auto"><table class="w-full text-sm"><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($logs as $lg): ?>
          <tr><td class="px-5 py-2.5 text-gray-400 text-xs"><?= date('d M H:i', strtotime($lg['sent_at'])) ?></td><td class="px-4 py-2.5 text-gray-700 dark:text-gray-300"><?= e($lg['user_name']) ?></td><td class="px-4 py-2.5 text-gray-500 font-mono text-xs"><?= e($lg['order_number']) ?></td><td class="px-4 py-2.5"><span class="text-xs px-2 py-0.5 rounded-full <?= $lg['status']==='sent'?'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400':($lg['status']==='failed'?'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400':'bg-gray-100 text-gray-500') ?>"><?= e($lg['status']) ?></span></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
