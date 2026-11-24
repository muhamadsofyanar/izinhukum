<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('users')) { redirect('/admin/no-access'); }

$pageTitle   = 'Langganan';
$topbarTitle = 'Langganan Member';

$search       = trim($_GET['q']      ?? '');
$statusFilter = $_GET['status']      ?? '';
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 20;

$allowedFilters = ['active', 'expiring', 'expired', 'lifetime', 'revoked'];

$subs       = [];
$totalCount = 0;
$totalPages = 1;
$stat = ['active' => 0, 'expiring' => 0, 'expired' => 0];

try {
    $pdo = getDB();

    // ── Stat cards ──
    $stat['active'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM member_access WHERE status='active' AND expires_at IS NOT NULL AND expires_at > NOW()"
    )->fetchColumn();
    $stat['expiring'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM member_access WHERE status='active' AND expires_at IS NOT NULL AND expires_at > NOW() AND DATEDIFF(expires_at, NOW()) <= 7"
    )->fetchColumn();
    $stat['expired'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM member_access WHERE status='active' AND expires_at IS NOT NULL AND expires_at <= NOW()"
    )->fetchColumn();

    // ── WHERE ──
    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[]  = '(u.name LIKE ? OR u.email LIKE ? OR p.name LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    switch ($statusFilter) {
        case 'active':   $where[] = "ma.status='active' AND ma.expires_at IS NOT NULL AND ma.expires_at > NOW()"; break;
        case 'expiring': $where[] = "ma.status='active' AND ma.expires_at IS NOT NULL AND ma.expires_at > NOW() AND DATEDIFF(ma.expires_at, NOW()) <= 7"; break;
        case 'expired':  $where[] = "ma.status='active' AND ma.expires_at IS NOT NULL AND ma.expires_at <= NOW()"; break;
        case 'lifetime': $where[] = "ma.status='active' AND ma.expires_at IS NULL"; break;
        case 'revoked':  $where[] = "ma.status='revoked'"; break;
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM member_access ma JOIN users u ON u.id=ma.user_id JOIN products p ON p.id=ma.product_id $whereSQL");
    $cnt->execute($params);
    $totalCount = (int) $cnt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT ma.id, ma.user_id, ma.product_id, ma.status, ma.started_at, ma.expires_at,
                DATEDIFF(ma.expires_at, NOW()) AS days_left,
                u.name AS user_name, u.email AS user_email,
                p.name AS product_name, p.access_duration_days
         FROM member_access ma
         JOIN users u    ON u.id = ma.user_id
         JOIN products p ON p.id = ma.product_id
         $whereSQL
         ORDER BY (ma.expires_at IS NULL) ASC, ma.expires_at ASC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $subs = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('subscriptions page error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data langganan.', 'error');
}

// Badge status per baris.
$subBadge = function (array $s): array {
    if ($s['status'] === 'revoked')  return ['Dicabut',    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'];
    if ($s['expires_at'] === null)   return ['Selamanya',  'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'];
    $dl = (int) $s['days_left'];
    if ($dl < 0)  return ['Expired',    'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'];
    if ($dl <= 7) return ['Akan habis', 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'];
    return ['Aktif', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'];
};

$filterOpts = [
    'active'   => 'Aktif (berjangka)',
    'expiring' => 'Akan habis (≤7 hari)',
    'expired'  => 'Expired',
    'lifetime' => 'Selamanya',
    'revoked'  => 'Dicabut',
];

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

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Langganan Member</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Akses berjangka member — pantau yang akan habis &amp; perpanjang manual bila perlu.</p>
      </div>

      <!-- Stat cards -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 shadow-sm">
          <p class="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wider">Aktif (berjangka)</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= number_format($stat['active']) ?></p>
        </div>
        <a href="?status=expiring" class="bg-white dark:bg-gray-900 rounded-xl border border-yellow-200 dark:border-yellow-800/60 p-4 shadow-sm hover:border-yellow-400 transition-colors">
          <p class="text-xs text-yellow-600 dark:text-yellow-500 uppercase tracking-wider">Akan habis (≤7 hari)</p>
          <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mt-1"><?= number_format($stat['expiring']) ?></p>
        </a>
        <a href="?status=expired" class="bg-white dark:bg-gray-900 rounded-xl border border-red-200 dark:border-red-800/60 p-4 shadow-sm hover:border-red-400 transition-colors">
          <p class="text-xs text-red-600 dark:text-red-500 uppercase tracking-wider">Expired</p>
          <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1"><?= number_format($stat['expired']) ?></p>
        </a>
      </div>

      <!-- Filter -->
      <form method="GET" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-5 flex flex-wrap gap-3 items-end shadow-sm">
        <div class="flex-1 min-w-[200px]">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Cari</label>
          <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Nama, email, atau produk..."
                   class="w-full pl-9 pr-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>
        </div>
        <div class="w-52">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Status</label>
          <select name="status" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Status</option>
            <?php foreach ($filterOpts as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90 transition">Filter</button>
          <?php if ($search || $statusFilter): ?>
          <a href="<?= e(APP_URL) ?>/admin/subscriptions" class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Tabel -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800 text-left">
                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Member</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Mulai</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Berakhir</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php if (empty($subs)): ?>
              <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400 dark:text-gray-600">
                <svg class="w-10 h-10 mx-auto mb-3 text-gray-300 dark:text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Belum ada data langganan.
              </td></tr>
              <?php else: foreach ($subs as $s):
                [$badgeLabel, $badgeCls] = $subBadge($s);
                $dl = $s['expires_at'] !== null ? (int) $s['days_left'] : null;
              ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-4 py-3">
                  <p class="font-medium text-gray-900 dark:text-white truncate max-w-[200px]"><?= e($s['user_name'] ?: '—') ?></p>
                  <p class="text-xs text-gray-400 dark:text-gray-500 truncate max-w-[200px]"><?= e($s['user_email']) ?></p>
                </td>
                <td class="px-4 py-3 text-gray-700 dark:text-gray-300"><?= e($s['product_name']) ?></td>
                <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= e(date('d M Y', strtotime($s['started_at']))) ?></td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <?php if ($s['expires_at'] === null): ?>
                    <span class="text-gray-400 dark:text-gray-500">—</span>
                  <?php else: ?>
                    <span class="text-gray-700 dark:text-gray-300"><?= e(date('d M Y', strtotime($s['expires_at']))) ?></span>
                    <?php if ($dl !== null && $dl >= 0 && $s['status'] === 'active'): ?>
                      <span class="block text-xs <?= $dl <= 7 ? 'text-yellow-600 dark:text-yellow-500' : 'text-gray-400 dark:text-gray-600' ?>"><?= $dl ?> hari lagi</span>
                    <?php elseif ($dl !== null && $dl < 0 && $s['status'] === 'active'): ?>
                      <span class="block text-xs text-red-500">lewat <?= abs($dl) ?> hari</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3"><span class="inline-flex px-2.5 py-1 rounded-full text-xs font-medium <?= $badgeCls ?>"><?= $badgeLabel ?></span></td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                  <button type="button"
                          onclick="openExtend(<?= (int) $s['id'] ?>, '<?= e(addslashes($s['user_name'] ?: $s['user_email'])) ?>', '<?= e(addslashes($s['product_name'])) ?>', <?= (int) ($s['access_duration_days'] ?: 30) ?>)"
                          class="text-xs font-semibold px-2.5 py-1.5 rounded-lg text-white bg-brand hover:opacity-90 transition">Perpanjang</button>
                  <?php if ($s['status'] !== 'revoked'): ?>
                  <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-subscription" id="revoke-<?= (int) $s['id'] ?>" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="access_id" value="<?= (int) $s['id'] ?>">
                    <button type="button"
                            onclick="confirmAndSubmit('revoke-<?= (int) $s['id'] ?>', {title:'Cabut Akses?', message:'Cabut akses <?= e(addslashes($s['product_name'])) ?> milik <?= e(addslashes($s['user_name'] ?: $s['user_email'])) ?>? Member langsung kehilangan akses.', confirmText:'Ya, Cabut', variant:'danger'})"
                            class="text-xs font-semibold px-2.5 py-1.5 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">Cabut</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 dark:border-gray-800">
          <p class="text-xs text-gray-500 dark:text-gray-400">Halaman <?= $page ?> dari <?= $totalPages ?> · <?= number_format($totalCount) ?> total</p>
          <div class="flex gap-2">
            <?php $qs = fn($p) => '?' . http_build_query(array_filter(['q' => $search, 'status' => $statusFilter, 'page' => $p])); ?>
            <?php if ($page > 1): ?><a href="<?= e($qs($page - 1)) ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Sebelumnya</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a href="<?= e($qs($page + 1)) ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Berikutnya</a><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<!-- Modal Perpanjang -->
<div id="extend-modal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/40 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-sm p-5">
    <h3 class="text-base font-bold text-gray-900 dark:text-white">Perpanjang Akses</h3>
    <p id="extend-desc" class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-4"></p>
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/manage-subscription">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="extend">
      <input type="hidden" name="access_id" id="extend-access-id" value="">
      <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Tambah berapa hari?</label>
      <input type="number" name="days" id="extend-days" min="1" max="3650" value="30" required
             class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
      <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1.5">Ditambahkan dari tanggal berakhir saat ini (atau dari hari ini bila sudah lewat). Akses diaktifkan &amp; siklus reminder di-reset.</p>
      <div class="flex justify-end gap-2 mt-5">
        <button type="button" onclick="closeExtend()" class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Batal</button>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Perpanjang</button>
      </div>
    </form>
  </div>
</div>

<script>
function openExtend(id, member, product, defaultDays) {
  document.getElementById('extend-access-id').value = id;
  document.getElementById('extend-days').value = defaultDays || 30;
  document.getElementById('extend-desc').textContent = product + ' — ' + member;
  var m = document.getElementById('extend-modal');
  m.classList.remove('hidden'); m.classList.add('flex');
}
function closeExtend() {
  var m = document.getElementById('extend-modal');
  m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('extend-modal').addEventListener('click', function (e) {
  if (e.target === this) closeExtend();
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
</body>
</html>
