<?php
// Konten tab "Komisi" — dipakai oleh pages/admin/affiliate-finance.php.
// Guard, layout (head/sidebar/topbar/footer) & generateCSRF ditangani parent.
// Logika & endpoint aksi TIDAK berubah (approve-commission dll).
$search    = trim($_GET['search'] ?? '');
$statusF   = $_GET['status'] ?? '';
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 20;

$validStatuses = ['pending', 'approved', 'paid'];
if (!in_array($statusF, $validStatuses, true)) $statusF = '';

$commissions = [];
$totalCount  = 0;
$totalPages  = 1;
$stats       = ['pending' => 0, 'approved' => 0, 'paid' => 0, 'total_amount' => 0.0];

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(u.name LIKE ? OR u.email LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($statusF !== '') {
    $where[]  = 'c.status = ?';
    $params[] = $statusF;
} else {
    // Default: sembunyikan komisi yang SUDAH DIBAYAR dari daftar (fokus ke yang
    // masih perlu tindakan: pending/disetujui). Histori komisi dibayar tetap bisa
    // dilihat via filter Status = "Dibayar" atau Export CSV.
    $where[] = "c.status <> 'paid'";
}
$whereSQL = implode(' AND ', $where);

try {
    $pdo = getDB();

    // Stats
    $sRows = $pdo->query(
        "SELECT c.status, COUNT(*) AS cnt, COALESCE(SUM(c.amount),0) AS total
         FROM commissions c GROUP BY c.status"
    )->fetchAll();
    foreach ($sRows as $r) {
        $stats[$r['status']] = (int) $r['cnt'];
        $stats['total_amount'] += (float) $r['total'];
    }

    $cntStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM commissions c
         JOIN affiliates a ON c.affiliate_id = a.id
         JOIN users u ON a.user_id = u.id
         WHERE $whereSQL"
    );
    $cntStmt->execute($params);
    $totalCount = (int) $cntStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT c.id, c.amount, c.rate, c.status, c.created_at,
                COALESCE(c.approved_at,'') AS approved_at,
                COALESCE(c.paid_at,'') AS paid_at,
                u.name AS affiliate_name, u.email AS affiliate_email,
                a.code AS affiliate_code,
                o.order_number, o.total AS order_total
         FROM commissions c
         JOIN affiliates a ON c.affiliate_id = a.id
         JOIN users u ON a.user_id = u.id
         JOIN orders o ON c.order_id = o.id
         WHERE $whereSQL
         ORDER BY c.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $commissions = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Admin commissions error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data komisi.', 'error');
}
?>
<!-- Toolbar: jumlah + Export -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-5">
  <p class="text-sm text-gray-500 dark:text-gray-400"><?= number_format($totalCount) ?> komisi ditemukan</p>
  <a href="<?= e(APP_URL) ?>/actions/admin/export-data?type=commissions"
     class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition shadow-sm">
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
      <polyline points="7 10 12 15 17 10"/>
      <line x1="12" y1="15" x2="12" y2="3"/>
    </svg>
    Export CSV
  </a>
</div>

<!-- Stat Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Komisi</p>
    <p class="text-xl font-bold text-gray-900 dark:text-white mt-2 truncate"><?= e(formatRupiah($stats['total_amount'])) ?></p>
  </div>
  <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pending</p>
    <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mt-2"><?= number_format($stats['pending']) ?></p>
  </div>
  <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Disetujui</p>
    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-2"><?= number_format($stats['approved']) ?></p>
  </div>
  <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-800 shadow-sm">
    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Dibayar</p>
    <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-2"><?= number_format($stats['paid']) ?></p>
  </div>
</div>

<!-- Filter -->
<form method="GET" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-5 flex flex-wrap gap-3 items-end shadow-sm">
  <input type="hidden" name="tab" value="commissions">
  <div class="flex-1 min-w-[180px]">
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Cari Affiliate</label>
    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Nama atau email..."
           class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
  </div>
  <div class="w-40">
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Status</label>
    <select name="status" class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
      <option value="">Semua (belum dibayar)</option>
      <option value="pending"  <?= $statusF === 'pending'  ? 'selected' : '' ?>>Pending</option>
      <option value="approved" <?= $statusF === 'approved' ? 'selected' : '' ?>>Disetujui</option>
      <option value="paid"     <?= $statusF === 'paid'     ? 'selected' : '' ?>>Dibayar</option>
    </select>
  </div>
  <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-90" style="background-color:var(--brand-color)">Filter</button>
  <?php if ($search || $statusF): ?>
  <a href="<?= e(APP_URL) ?>/admin/affiliate-finance?tab=commissions" class="px-4 py-2.5 rounded-lg text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition">Reset</a>
  <?php endif; ?>
</form>

<!-- Table -->
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
  <?php if (empty($commissions)): ?>
  <div class="py-14 text-center">
    <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
      <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
      <line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>
    </svg>
    <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada data komisi.</p>
  </div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
          <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Affiliate</th>
          <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">No. Order</th>
          <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Komisi</th>
          <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
          <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tanggal</th>
          <th class="text-center px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
        <?php foreach ($commissions as $c): ?>
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
          <td class="px-5 py-3.5">
            <p class="font-semibold text-gray-900 dark:text-white"><?= e($c['affiliate_name']) ?></p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5"><?= e($c['affiliate_email']) ?></p>
            <span class="font-mono text-xs text-gray-400 dark:text-gray-600"><?= e($c['affiliate_code']) ?></span>
          </td>
          <td class="px-4 py-3.5">
            <a href="<?= e(APP_URL) ?>/admin/transactions/<?= e($c['order_number']) ?>"
               class="font-mono text-xs font-bold text-[var(--brand-color)] hover:underline">
              <?= e($c['order_number']) ?>
            </a>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5"><?= e(formatRupiah((float)$c['order_total'])) ?></p>
          </td>
          <td class="px-4 py-3.5 text-right">
            <p class="font-bold text-gray-900 dark:text-white"><?= e(formatRupiah((float)$c['amount'])) ?></p>
            <p class="text-xs text-gray-400 dark:text-gray-500"><?= number_format((float)$c['rate'], 1) ?>% rate</p>
          </td>
          <td class="px-4 py-3.5 text-center">
            <?= renderStatusBadge($c['status'], 'commission') ?>
          </td>
          <td class="px-4 py-3.5 text-right text-xs text-gray-400 dark:text-gray-600">
            <?= e(date('d M Y', strtotime($c['created_at']))) ?>
            <?php if ($c['paid_at']): ?>
            <p class="text-green-500">Dibayar <?= e(date('d M Y', strtotime($c['paid_at']))) ?></p>
            <?php elseif ($c['approved_at']): ?>
            <p class="text-blue-500">Disetujui <?= e(date('d M Y', strtotime($c['approved_at']))) ?></p>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3.5 text-center">
            <?php if ($c['status'] === 'pending'): ?>
            <form id="approve-<?= (int)$c['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/approve-commission" class="inline">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="commission_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button type="button"
                      onclick="confirmAndSubmit('approve-<?= (int)$c['id'] ?>', {title:'Setujui Komisi?', message:'Komisi <?= e(formatRupiah((float)$c['amount'])) ?> akan disetujui.', confirmText:'Ya, Setujui', variant:'success'})"
                      class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 transition">
                Setujui
              </button>
            </form>
            <?php elseif ($c['status'] === 'approved'): ?>
            <form id="paid-<?= (int)$c['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/approve-commission" class="inline">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="commission_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="action" value="mark_paid">
              <button type="button"
                      onclick="confirmAndSubmit('paid-<?= (int)$c['id'] ?>', {title:'Tandai Dibayar?', message:'Komisi <?= e(formatRupiah((float)$c['amount'])) ?> ditandai lunas.', confirmText:'Ya, Dibayar', variant:'success'})"
                      class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-green-600 hover:bg-green-700 transition">
                Tandai Dibayar
              </button>
            </form>
            <?php else: ?>
            <span class="text-xs text-gray-400 dark:text-gray-600">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="flex items-center justify-between px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
    <p class="text-xs text-gray-500 dark:text-gray-400">Halaman <?= $page ?> dari <?= $totalPages ?></p>
    <div class="flex items-center gap-1">
      <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 'commissions', 'page' => $page - 1])) ?>"
         class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">‹</a>
      <?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 'commissions', 'page' => $p])) ?>"
         class="px-3 py-1.5 text-xs rounded-lg border transition <?= $p === $page ? 'border-[var(--brand-color)] text-[var(--brand-color)] font-semibold' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
        <?= $p ?>
      </a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 'commissions', 'page' => $page + 1])) ?>"
         class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">›</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
