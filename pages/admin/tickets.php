<?php
// Admin — Tiket Support: daftar + filter status. Detail & balas di ticket-detail.php.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Tiket Support';
$topbarTitle = 'Tiket Support';

$filter = in_array($_GET['status'] ?? '', ['open','answered','closed','all'], true) ? $_GET['status'] : 'open';

$stats   = ['open' => 0, 'answered' => 0, 'closed' => 0];
$tickets = [];
try {
    $pdo = getDB();
    foreach (['open','answered','closed'] as $s) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE status = ?");
        $c->execute([$s]);
        $stats[$s] = (int) $c->fetchColumn();
    }

    $where = $filter === 'all' ? '' : 'WHERE t.status = ?';
    $args  = $filter === 'all' ? [] : [$filter];
    $q = $pdo->prepare(
        "SELECT t.*, u.name AS user_name, u.email AS user_email,
                (t.last_reply_by = 'user' AND (t.admin_seen_at IS NULL OR t.admin_seen_at < t.last_reply_at)) AS needs_reply
         FROM support_tickets t
         JOIN users u ON u.id = t.user_id
         $where
         ORDER BY t.last_reply_at DESC, t.created_at DESC
         LIMIT 100"
    );
    $q->execute($args);
    $tickets = $q->fetchAll();
} catch (Throwable $e) { error_log('admin tickets list: ' . $e->getMessage()); }
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Tiket Support</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kelola pertanyaan & bantuan dari pembeli.</p>
      </div>

      <!-- Filter -->
      <div class="flex flex-wrap gap-2 mb-5">
        <?php
        $tabs = ['open' => 'Menunggu Jawaban', 'answered' => 'Dijawab', 'closed' => 'Ditutup', 'all' => 'Semua'];
        foreach ($tabs as $st => $lbl):
          $count = $st === 'all' ? array_sum($stats) : $stats[$st];
          $active = $filter === $st;
        ?>
        <a href="?status=<?= $st ?>"
           class="px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $active
                ? 'text-white' : 'bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' ?>"
           <?= $active ? 'style="background:var(--brand-color)"' : '' ?>>
          <?= $lbl ?> (<?= $count ?>)
        </a>
        <?php endforeach; ?>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($tickets)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Tidak ada tiket pada filter ini.</div>
        <?php else: ?>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($tickets as $t): $m = ticketStatusMeta($t['status']); ?>
          <li>
            <a href="<?= e(APP_URL) ?>/admin/tickets/<?= (int) $t['id'] ?>" class="flex items-center gap-3 px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                  <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($t['subject']) ?></p>
                  <?php if ((int) $t['needs_reply']): ?>
                  <span class="shrink-0 px-1.5 py-0.5 rounded text-[10px] font-bold text-white" style="background:var(--brand-color)">BARU</span>
                  <?php endif; ?>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                  <span class="font-mono"><?= e($t['ticket_number']) ?></span> ·
                  <?= e($t['user_name']) ?> (<?= e($t['user_email']) ?>) ·
                  <?= e(date('d M Y, H:i', strtotime($t['last_reply_at'] ?: $t['created_at']))) ?>
                </p>
              </div>
              <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-semibold <?= $m['cls'] ?>"><?= e($m['label']) ?></span>
              <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
