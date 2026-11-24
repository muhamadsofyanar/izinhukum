<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('users')) { redirect('/admin/no-access'); }
if (function_exists('ensureUserEmailOptOutColumn')) ensureUserEmailOptOutColumn(getDB());

$pageTitle   = 'Manajemen Pengguna';
$topbarTitle = 'Manajemen Pengguna';

// ─── Filter & Pagination ───────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$roleF     = $_GET['role']   ?? '';
$statusF   = $_GET['status'] ?? '';
$sortF     = $_GET['sort']   ?? 'created_desc';
$minSpentF = (float) ($_GET['min_spent'] ?? 0);
$spentF    = $_GET['spent']  ?? '';               // '' | 'zero' (Rp0) | 'noorder' (tak pernah order)
$accessF   = ($_GET['access'] ?? '') === 'active' ? 'active' : '';
$levelF    = (int) ($_GET['level'] ?? 0);         // 0 = semua; selain itu id membership level
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page - 1) * $perPage;

$validRoles    = ['user', 'admin'];
$validStatuses = ['active', 'inactive', 'banned'];
$validSorts    = [
    'created_desc' => 'u.created_at DESC, u.id DESC',
    'spent_desc'   => 'total_spent DESC, u.created_at DESC, u.id DESC',
    'spent_asc'    => 'total_spent ASC, u.created_at DESC, u.id DESC',
    'orders_desc'  => 'order_count DESC, u.created_at DESC, u.id DESC',
    'name_asc'     => 'u.name ASC, u.id DESC',
];

if (!in_array($roleF,   $validRoles,   true)) $roleF   = '';
if (!in_array($statusF, $validStatuses, true)) $statusF = '';
if (!array_key_exists($sortF, $validSorts))   $sortF   = 'created_desc';
if (!in_array($spentF,  ['zero', 'noorder'], true)) $spentF = '';

// Membership levels untuk dropdown filter + validasi $levelF (level = otomatis
// dari total belanja, disinkron tiap load via recalculateAllUserMembershipLevels).
$membershipLevels = [];
try {
    $membershipLevels = getDB()->query(
        "SELECT id, name, color FROM membership_levels ORDER BY sort_order ASC"
    )->fetchAll();
} catch (Exception $e) {
    $membershipLevels = [];
}
$validLevelIds = array_map(static fn($l) => (int) $l['id'], $membershipLevels);
if (!in_array($levelF, $validLevelIds, true)) $levelF = 0;

$orderBySql = $validSorts[$sortF];

// ─── Build query ───────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(u.name LIKE ? OR u.email LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($roleF !== '') {
    $where[]  = 'u.role = ?';
    $params[] = $roleF;
}
if ($statusF !== '') {
    $where[]  = 'u.status = ?';
    $params[] = $statusF;
}
// Filter belanja: Rp0 (belum belanja) / belum pernah order sama sekali.
// Korelasi subquery (tanpa param) → konsisten dipakai di count & list.
if ($spentF === 'zero') {
    $where[] = "COALESCE((SELECT SUM(o2.total) FROM orders o2 WHERE o2.user_id = u.id AND o2.status = 'paid'), 0) = 0";
} elseif ($spentF === 'noorder') {
    $where[] = "NOT EXISTS (SELECT 1 FROM orders o2 WHERE o2.user_id = u.id)";
}
// Filter tepat-level membership (id sudah divalidasi dari daftar level).
if ($levelF > 0) {
    $where[]  = 'u.membership_level_id = ?';
    $params[] = $levelF;
}

if ($accessF === 'active') {
    $where[] = "EXISTS (SELECT 1 FROM member_access ma WHERE ma.user_id = u.id
        AND ma.status = 'active' AND (ma.expires_at IS NULL OR ma.expires_at > NOW()))";
}
$whereClause = implode(' AND ', $where);
$havingClause = $minSpentF > 0 ? 'HAVING total_spent >= ?' : '';
$havingParams = $minSpentF > 0 ? [$minSpentF] : [];

$users    = [];
$total    = 0;
$totalPages = 1;
$stats    = ['total' => 0, 'active' => 0, 'banned' => 0, 'admin' => 0];

try {
    $pdo = getDB();

    // Auto-sync & recalculate level membership seluruh user berbasis total belanja
    recalculateAllUserMembershipLevels($pdo);

    // Stats
    $stmt = $pdo->query("SELECT COUNT(*) as cnt, status, role FROM users GROUP BY status, role");
    foreach ($stmt->fetchAll() as $r) {
        $stats['total'] += (int)$r['cnt'];
        if ($r['status'] === 'active')  $stats['active'] += (int)$r['cnt'];
        if ($r['status'] === 'banned')  $stats['banned'] += (int)$r['cnt'];
        if ($r['role']   === 'admin')   $stats['admin']  += (int)$r['cnt'];
    }

    // Count
    if ($minSpentF > 0) {
        $countSql = "SELECT COUNT(*) FROM (
            SELECT u.id, COALESCE((SELECT SUM(total) FROM orders WHERE user_id = u.id AND status = 'paid'), 0) AS total_spent
            FROM users u
            WHERE $whereClause
            HAVING total_spent >= ?
        ) AS cnt_tbl";
        $stmtC = $pdo->prepare($countSql);
        $stmtC->execute(array_merge($params, [$minSpentF]));
        $total = (int) $stmtC->fetchColumn();
    } else {
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereClause");
        $stmtC->execute($params);
        $total = (int) $stmtC->fetchColumn();
    }

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $sql = "SELECT u.id, u.name, u.email, u.phone, u.avatar, u.role, u.status,
                   u.affiliate_code, u.created_at, u.email_opt_out,
                   COALESCE((SELECT COUNT(*) FROM orders WHERE user_id = u.id AND status = 'paid'), 0) AS order_count,
                   COALESCE((SELECT SUM(total) FROM orders WHERE user_id = u.id AND status = 'paid'), 0) AS total_spent,
                   COALESCE((SELECT COUNT(*) FROM member_access WHERE user_id = u.id AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())), 0) AS access_count,
                   COALESCE(ml.name, ml_calc.name, '') AS level_name,
                   COALESCE(ml.color, ml_calc.color, '') AS level_color
            FROM users u
            LEFT JOIN membership_levels ml ON ml.id = u.membership_level_id
            LEFT JOIN membership_levels ml_calc ON ml_calc.id = (
                SELECT id FROM membership_levels
                WHERE min_spend <= COALESCE((SELECT SUM(total) FROM orders WHERE user_id = u.id AND status = 'paid'), 0)
                ORDER BY min_spend DESC LIMIT 1
            )
            WHERE $whereClause
            $havingClause
            ORDER BY $orderBySql
            LIMIT $perPage OFFSET $offset";

    $stmtU = $pdo->prepare($sql);
    $stmtU->execute(array_merge($params, $havingParams));
    $users = $stmtU->fetchAll();

} catch (Exception $e) {
    error_log('admin/users error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data pengguna.', 'error');
}

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

      <?php if (!empty($_SESSION['bulk_delete_backup'])):
          $_bkp = basename($_SESSION['bulk_delete_backup']); unset($_SESSION['bulk_delete_backup']); ?>
      <div class="mb-4 flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300">
        <p class="text-sm font-medium">Cadangan daftar pengguna terdampak telah dibuat.</p>
        <a href="<?= e(APP_URL) ?>/actions/admin/download-deleted-users?file=<?= urlencode($_bkp) ?>"
           class="inline-flex items-center gap-2 shrink-0 px-3.5 py-1.5 rounded-lg text-sm font-semibold bg-white dark:bg-gray-900 border border-blue-200 dark:border-blue-800 hover:bg-blue-50 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Unduh CSV
        </a>
      </div>
      <?php endif; ?>

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Pengguna</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= number_format($total) ?> pengguna terdaftar</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a href="<?= e(APP_URL) ?>/actions/admin/export-data?type=users"
             class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition shadow-sm">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="7 10 12 15 17 10"/>
              <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Export CSV
          </a>
          <button onclick="openUserModal(null)"
                  class="flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white hover:opacity-90 transition"
                  style="background-color: var(--brand-color);">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Pengguna
          </button>
        </div>
      </div>

      <!-- Stat Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <?php
        $statCards = [
            ['label' => 'Total',  'val' => $stats['total'],  'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'color' => 'brand'],
            ['label' => 'Aktif',  'val' => $stats['active'], 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'color' => 'green'],
            ['label' => 'Admin',  'val' => $stats['admin'],  'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'color' => 'blue'],
            ['label' => 'Banned', 'val' => $stats['banned'], 'icon' => 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636', 'color' => 'red'],
        ];
        foreach ($statCards as $sc):
        $colorMap = ['brand' => 'text-[var(--brand-color)] bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)]', 'green' => 'text-green-600 bg-green-50 dark:text-green-400 dark:bg-green-900/20', 'blue' => 'text-blue-600 bg-blue-50 dark:text-blue-400 dark:bg-blue-900/20', 'red' => 'text-red-600 bg-red-50 dark:text-red-400 dark:bg-red-900/20'];
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg flex items-center justify-center <?= $colorMap[$sc['color']] ?>">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="<?= e($sc['icon']) ?>"/></svg>
          </div>
          <div>
            <p class="text-xl font-bold text-gray-900 dark:text-white"><?= number_format((int)$sc['val']) ?></p>
            <p class="text-xs text-gray-400 dark:text-gray-600"><?= e($sc['label']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Filter Bar -->
      <form method="GET" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-3.5 mb-4 flex flex-col sm:flex-row flex-wrap items-center gap-2.5">
        <div class="flex-1 min-w-[200px] relative">
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" value="<?= e($search) ?>" placeholder="Cari nama atau email..."
                 class="w-full pl-9 pr-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        </div>
        <select name="sort" class="px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none font-medium">
          <option value="created_desc" <?= $sortF === 'created_desc' ? 'selected' : '' ?>>Terbaru Mendaftar</option>
          <option value="spent_desc"   <?= $sortF === 'spent_desc'   ? 'selected' : '' ?>>💎 Total Belanja Tertinggi</option>
          <option value="spent_asc"    <?= $sortF === 'spent_asc'    ? 'selected' : '' ?>>Total Belanja Terendah</option>
          <option value="orders_desc"  <?= $sortF === 'orders_desc'  ? 'selected' : '' ?>>Order Terbanyak</option>
          <option value="name_asc"     <?= $sortF === 'name_asc'     ? 'selected' : '' ?>>Nama (A - Z)</option>
        </select>
        <input type="number" name="min_spent" value="<?= $minSpentF > 0 ? (int) $minSpentF : '' ?>" placeholder="Min. Belanja (<?= e(currencySymbol()) ?>)" min="0" step="50000" class="px-3 py-2 w-40 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
        <select name="spent" class="px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
          <option value="">Semua Belanja</option>
          <option value="zero"    <?= $spentF === 'zero'    ? 'selected' : '' ?>>Belum belanja (Rp0)</option>
          <option value="noorder" <?= $spentF === 'noorder' ? 'selected' : '' ?>>Belum pernah order</option>
        </select>
        <select name="role" class="px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
          <option value="">Semua Role</option>
          <option value="user"  <?= $roleF === 'user'  ? 'selected' : '' ?>>User</option>
          <option value="admin" <?= $roleF === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
        <select name="status" class="px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
          <option value="">Semua Status</option>
          <option value="active"   <?= $statusF === 'active'   ? 'selected' : '' ?>>Aktif</option>
          <option value="inactive" <?= $statusF === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
          <option value="banned"   <?= $statusF === 'banned'   ? 'selected' : '' ?>>Banned</option>
        </select>
        <?php if ($membershipLevels): ?>
        <select name="access" aria-label="Akses produk" class="px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white">
          <option value="">Semua akses produk</option>
          <option value="active" <?= $accessF === 'active' ? 'selected' : '' ?>>Akses produk aktif</option>
        </select>
        <select name="level" class="px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
          <option value="">Semua Level</option>
          <?php foreach ($membershipLevels as $lvl): ?>
          <option value="<?= (int) $lvl['id'] ?>" <?= $levelF === (int) $lvl['id'] ? 'selected' : '' ?>><?= e($lvl['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-900 dark:bg-white text-white dark:text-gray-900 hover:opacity-80 transition">Cari</button>
        <?php if ($search || $roleF || $statusF || $sortF !== 'created_desc' || $minSpentF > 0 || $spentF || $accessF || $levelF): ?>
        <a href="<?= e(APP_URL) ?>/admin/users" class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Reset</a>
        <?php endif; ?>
      </form>

      <!-- Bulk Action Bar -->
      <div id="bulkBar" class="hidden mb-3 flex flex-wrap items-center justify-between gap-3 border rounded-xl px-4 py-2.5"
           style="background:color-mix(in srgb,var(--brand-color) 8%,transparent);border-color:color-mix(in srgb,var(--brand-color) 30%,transparent)">
        <div class="flex items-center gap-2 text-sm">
          <span id="bulkCount" class="font-semibold text-gray-900 dark:text-white">0 terpilih</span>
          <span id="bulkSelectAllWrap" class="hidden">
            <button type="button" id="bulkSelectAllBtn"
                    class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-bold border transition"
                    style="color:var(--brand-color);border-color:color-mix(in srgb,var(--brand-color) 45%,transparent);background:color-mix(in srgb,var(--brand-color) 14%,transparent)">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
              Pilih semua <?= number_format($total) ?> yang cocok filter
            </button>
          </span>
          <button type="button" id="bulkClear" class="text-gray-500 dark:text-gray-400 hover:underline">Batal pilih</button>
        </div>
        <button type="button" id="bulkDeleteBtn"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
          Hapus Terpilih
        </button>
      </div>

      <!-- Hidden form untuk hapus massal -->
      <form id="bulkDeleteForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/bulk-delete-users" class="hidden">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="ids" id="bulkIds" value="">
        <input type="hidden" name="select_all_matching" id="bulkSelectAllMatching" value="0">
        <input type="hidden" name="force_delete_zero_total" id="bulkForceZeroInput" value="0">
        <!-- kriteria filter aktif (dipakai saat pilih-semua-cocok, di-derive ulang di server) -->
        <input type="hidden" name="search"  value="<?= e($search) ?>">
        <input type="hidden" name="role"    value="<?= e($roleF) ?>">
        <input type="hidden" name="status"  value="<?= e($statusF) ?>">
        <input type="hidden" name="spent"   value="<?= e($spentF) ?>">
        <input type="hidden" name="access" value="<?= e($accessF) ?>">
        <input type="hidden" name="level"   value="<?= (int) $levelF ?>">
      </form>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($users)): ?>
        <div class="py-16 text-center text-gray-400 dark:text-gray-600">
          <svg class="w-10 h-10 mx-auto mb-3 opacity-40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          <p class="text-sm">Tidak ada pengguna ditemukan</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <th class="px-4 py-3 text-left w-10">
                  <input type="checkbox" id="bulkMaster" title="Pilih semua di halaman ini"
                         class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 cursor-pointer" style="accent-color:var(--brand-color)">
                </th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pengguna</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden md:table-cell">Role</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Pesanan</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden xl:table-cell">Total Belanja</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden xl:table-cell">Level</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Terdaftar</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($users as $u): ?>
              <?php
              $initials = strtoupper(substr(trim($u['name']), 0, 1));
$roleClass = $u['role'] === 'admin'
                  ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400'
                  : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400';
              ?>
              <?php $isSelf = ((int)$u['id'] === (int)($_SESSION['user_id'] ?? 0)); ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition">
                <td class="px-4 py-3">
                  <input type="checkbox" class="bulk-cb w-4 h-4 rounded border-gray-300 dark:border-gray-600 cursor-pointer"
                         value="<?= (int)$u['id'] ?>" style="accent-color:var(--brand-color)"
                         <?= $isSelf ? 'disabled title="Tidak bisa memilih akun sendiri"' : '' ?>
                         <?= $u['role'] === 'admin' ? 'data-admin="1"' : '' ?>>
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center gap-3">
                    <?php if ($u['avatar']): ?>
                    <img src="<?= e($u['avatar']) ?>" class="w-8 h-8 rounded-full object-cover shrink-0" alt="">
                    <?php else: ?>
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"
                         style="background-color: var(--brand-color);"><?= e($initials) ?></div>
                    <?php endif; ?>
                    <div class="min-w-0">
                      <p class="font-medium text-gray-900 dark:text-white truncate"><?= e($u['name']) ?></p>
                      <p class="text-xs text-gray-400 dark:text-gray-600 truncate"><?= e($u['email']) ?></p>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3 hidden md:table-cell">
                  <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold <?= $roleClass ?>">
                    <?= e(ucfirst($u['role'])) ?>
                  </span>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell text-gray-600 dark:text-gray-400">
                  <?= (int)$u['order_count'] ?> pesanan
                  <?php if ((int)$u['access_count'] > 0): ?>
                  <span class="ml-1 text-xs text-[var(--brand-color)]"><?= (int)$u['access_count'] ?> akses</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 hidden xl:table-cell font-medium text-gray-900 dark:text-white">
                  <?= e(formatRupiah((float)$u['total_spent'])) ?>
                  <?php if ((float)$u['total_spent'] >= 1000000): ?>
                  <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 ml-1" title="Top Spender (≥ Rp 1 Jt)">⭐ VIP</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 hidden xl:table-cell">
                  <?php if ($u['level_name']): ?>
                  <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold"
                        style="background-color:<?= e($u['level_color']) ?>20; color:<?= e($u['level_color']) ?>">
                    <span class="w-1.5 h-1.5 rounded-full inline-block" style="background-color:<?= e($u['level_color']) ?>"></span>
                    <?= e($u['level_name']) ?>
                  </span>
                  <?php else: ?>
                  <span class="text-xs text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 hidden lg:table-cell whitespace-nowrap">
                  <?php $ts = strtotime($u['created_at']); ?>
                  <?php if ($ts): ?>
                  <div class="font-medium text-gray-700 dark:text-gray-300"><?= e(date('d M Y', $ts)) ?></div>
                  <div class="text-xs text-gray-400 dark:text-gray-600 tabular-nums"><?= e(date('H:i', $ts)) ?> WIB</div>
                  <?php else: ?>
                  <span class="text-xs text-gray-400">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                  <?= renderStatusBadge($u['status'], 'user') ?>
                </td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button onclick="openUserModal(<?= htmlspecialchars(json_encode([
                        'id'     => $u['id'],
                        'name'   => $u['name'],
                        'email'  => $u['email'],
                        'phone'  => $u['phone'] ?? '',
                        'role'   => $u['role'],
                        'status' => $u['status'],
                    ]), ENT_QUOTES) ?>)"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800 transition"
                            title="Edit">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <?php if ($u['role'] === 'user' && $u['status'] === 'active'): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/impersonate-start" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition" title="Lihat sebagai user ini">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($u['role'] === 'user'): $uOptOut = (int)($u['email_opt_out'] ?? 0) === 1; ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/toggle-user-optout" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="opt_out" value="<?= $uOptOut ? 0 : 1 ?>">
                      <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/admin/users') ?>">
                      <button type="submit"
                              class="p-1.5 rounded-lg transition <?= $uOptOut ? 'text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/20' : 'text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800' ?>"
                              title="<?= $uOptOut ? 'Berhenti berlangganan AKTIF — klik untuk kirim email promosi lagi' : 'Hentikan email promosi ke user ini (opt-out)' ?>">
                        <?php if ($uOptOut): ?>
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.63 13A17.89 17.89 0 0 1 18 8"/><path d="M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.33-5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        <?php else: ?>
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <?php endif; ?>
                      </button>
                    </form>
                    <?php endif; ?>
                    <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-user" id="del-user-<?= (int)$u['id'] ?>" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <button type="button"
                              onclick="confirmAndSubmit('del-user-<?= (int)$u['id'] ?>',{title:'Hapus Pengguna?',message:'Pengguna dengan riwayat transaksi akan di-banned (bukan dihapus permanen) demi menjaga data keuangan. Tindakan ini tidak dapat dibatalkan.',confirmText:'Ya, Hapus',variant:'danger'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                              title="Hapus">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                      </button>
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

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
        <span>Halaman <?= $page ?> dari <?= $totalPages ?> (<?= number_format($total) ?> pengguna)</span>
        <div class="flex items-center gap-1">
          <?php
          $qs = http_build_query([
              'search'    => $search,
              'role'      => $roleF,
              'status'    => $statusF,
              'sort'      => $sortF,
              'min_spent' => $minSpentF > 0 ? (int) $minSpentF : '',
              'spent'     => $spentF,
              'access'    => $accessF,
              'level'     => $levelF > 0 ? $levelF : '',
          ]);
          $start = max(1, $page - 2);
          $end   = min($totalPages, $page + 2);
          if ($page > 1): ?>
          <a href="?<?= $qs ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">‹</a>
          <?php endif;
          for ($i = $start; $i <= $end; $i++): ?>
          <a href="?<?= $qs ?>&page=<?= $i ?>"
             class="px-3 py-1.5 rounded-lg border transition <?= $i === $page ? 'border-[var(--brand-color)] text-[var(--brand-color)] font-semibold' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
            <?= $i ?>
          </a>
          <?php endfor;
          if ($page < $totalPages): ?>
          <a href="?<?= $qs ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">›</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- ─── User Modal ──────────────────────────────────────────────── -->
<div id="userModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeUserModal()"></div>
  <div class="relative z-10 flex items-center justify-center min-h-full p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
      <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
        <h3 id="modalTitle" class="font-bold text-gray-900 dark:text-white text-base">Tambah Pengguna</h3>
        <button onclick="closeUserModal()" class="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <form id="userForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-user">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="user_id" id="userId" value="0">
        <div class="p-5 space-y-4">

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nama Lengkap <span class="text-red-500">*</span></label>
            <input type="text" name="name" id="uName" required maxlength="100"
                   class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email <span class="text-red-500">*</span></label>
            <input type="email" name="email" id="uEmail" required maxlength="150"
                   class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">No. HP</label>
            <input type="text" name="phone" id="uPhone" maxlength="20"
                   class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Role</label>
              <select name="role" id="uRole"
                      class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
                <option value="user">User</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Status</label>
              <select name="status" id="uStatus"
                      class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none">
                <option value="active">Aktif</option>
                <option value="inactive">Nonaktif</option>
                <option value="banned">Banned</option>
              </select>
            </div>
          </div>

          <div id="pwField">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              Password <span id="pwRequired" class="text-red-500">*</span>
              <span id="pwOptional" class="text-xs text-gray-400 hidden">(kosongkan jika tidak ingin diubah)</span>
            </label>
            <div class="relative">
              <input type="password" name="password" id="uPw" autocomplete="new-password"
                     class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition pr-10">
              <button type="button" onclick="togglePw('uPw', this)"
                      class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
          </div>

        </div>
        <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2.5">
          <button type="button" onclick="closeUserModal()"
                  class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
            Batal
          </button>
          <button type="submit" data-loading data-loading-text="Menyimpan..."
                  class="px-5 py-2 rounded-lg text-sm font-semibold text-white hover:opacity-90 transition"
                  style="background-color: var(--brand-color);">
            Simpan
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openUserModal(data) {
    const modal = document.getElementById('userModal');
    const isNew = !data || !data.id;
    document.getElementById('modalTitle').textContent = isNew ? 'Tambah Pengguna' : 'Edit Pengguna';
    document.getElementById('userId').value   = data?.id    ?? 0;
    document.getElementById('uName').value    = data?.name  ?? '';
    document.getElementById('uEmail').value   = data?.email ?? '';
    document.getElementById('uPhone').value   = data?.phone ?? '';
    document.getElementById('uRole').value    = data?.role  ?? 'user';
    document.getElementById('uStatus').value  = data?.status ?? 'active';
    document.getElementById('uPw').value      = '';
    document.getElementById('uPw').required   = isNew;
    document.getElementById('pwRequired').classList.toggle('hidden', !isNew);
    document.getElementById('pwOptional').classList.toggle('hidden', isNew);
    modal.classList.remove('hidden');
    document.getElementById('uName').focus();
}
function closeUserModal() {
    document.getElementById('userModal').classList.add('hidden');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeUserModal(); });
</script>

<!-- ─── Bulk Delete Modal ───────────────────────────────────────── -->
<div id="bulkDeleteModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeBulkModal()"></div>
  <div class="relative z-10 flex items-center justify-center min-h-full p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md">
      <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
        <div class="w-8 h-8 shrink-0 rounded-lg flex items-center justify-center bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </div>
        <h3 class="font-bold text-gray-900 dark:text-white text-base">Hapus <span id="bulkModalCount">0</span> Pengguna?</h3>
      </div>
      <div class="p-5 space-y-3 text-sm text-gray-600 dark:text-gray-400">
        <p>Tindakan ini <strong class="text-gray-900 dark:text-white">tidak dapat dibatalkan</strong>. Sebelum menghapus, sistem membuat <strong>cadangan CSV</strong> berisi daftar pengguna terdampak.</p>
        <ul class="list-disc pl-5 space-y-1">
          <li>Pengguna dengan <strong>riwayat transaksi berbayar</strong> tidak dihapus — otomatis di-<strong>banned</strong> demi menjaga data keuangan.</li>
          <li>Akun <strong>admin/staff</strong> dan akun Anda sendiri <strong>dilewati</strong>. Affiliate yang punya <strong>data komisi</strong> tidak dihapus (di-<strong>banned</strong>).</li>
        </ul>
        <div class="rounded-lg border pt-3 pb-3 px-4" style="border-color:color-mix(in srgb,#f59e0b 40%,transparent);background:color-mix(in srgb,#f59e0b 8%,transparent)">
          <label class="flex items-start gap-2 cursor-pointer select-none">
            <input type="checkbox" id="bulkForceZero" class="mt-1 w-4 h-4 rounded border-gray-300 dark:border-gray-600" style="accent-color:#f59e0b">
            <span class="text-xs leading-relaxed text-amber-800">
              <strong>Hapus permanen meski punya order Rp0</strong> (freebie / lead magnet / akses gratis /
              artefak migrasi) — akses di dalamnya ikut terhapus. Tetap <strong>tidak akan pernah</strong>
              menyentuh pengguna dengan transaksi bernilai lebih dari Rp0.
            </span>
          </label>
        </div>
        <div id="bulkTypeWrap" class="hidden pt-1">
          <label class="block text-gray-700 dark:text-gray-300 mb-1.5">Ketik <strong>HAPUS</strong> untuk mengonfirmasi:</label>
          <input type="text" id="bulkTypeInput" autocomplete="off" placeholder="HAPUS"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        </div>
      </div>
      <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2.5">
        <button type="button" onclick="closeBulkModal()"
                class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Batal</button>
        <button type="button" id="bulkConfirmBtn" onclick="submitBulkDelete()"
                class="px-5 py-2 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 transition disabled:opacity-50 disabled:cursor-not-allowed">Ya, Hapus</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var totalMatching = <?= (int) $total ?>;
  var TYPE_THRESHOLD = 100; // di atas ini wajib ketik-konfirmasi
  var selectAllMatching = false;

  function boxes()   { return Array.prototype.slice.call(document.querySelectorAll('.bulk-cb')); }
  function active()  { return boxes().filter(function (b) { return !b.disabled; }); }
  function checked() { return active().filter(function (b) { return b.checked; }); }

  var bar        = document.getElementById('bulkBar');
  var countEl    = document.getElementById('bulkCount');
  var master     = document.getElementById('bulkMaster');
  var selAllWrap = document.getElementById('bulkSelectAllWrap');

  function refresh() {
    var c = checked().length;
    if (selectAllMatching) {
      countEl.textContent = totalMatching.toLocaleString('id-ID') + ' pengguna (semua yang cocok filter) terpilih';
    } else {
      countEl.textContent = c.toLocaleString('id-ID') + ' terpilih';
    }
    bar.classList.toggle('hidden', c === 0 && !selectAllMatching);
    // tautan "pilih semua N" muncul saat semua di halaman tercentang & masih ada baris lain di luar halaman
    var showSelAll = !selectAllMatching && c > 0 && c === active().length && totalMatching > active().length;
    selAllWrap.classList.toggle('hidden', !showSelAll);
    if (master) master.checked = c > 0 && c === active().length;
  }

  if (master) master.addEventListener('change', function () {
    selectAllMatching = false;
    active().forEach(function (b) { b.checked = master.checked; });
    refresh();
  });
  boxes().forEach(function (b) {
    b.addEventListener('change', function () { selectAllMatching = false; refresh(); });
  });

  var selAllBtn = document.getElementById('bulkSelectAllBtn');
  if (selAllBtn) selAllBtn.addEventListener('click', function () { selectAllMatching = true; refresh(); });
  var clearBtn = document.getElementById('bulkClear');
  if (clearBtn) clearBtn.addEventListener('click', function () {
    selectAllMatching = false;
    active().forEach(function (b) { b.checked = false; });
    if (master) master.checked = false;
    refresh();
  });

  // ── Modal ──
  var modal      = document.getElementById('bulkDeleteModal');
  var typeWrap   = document.getElementById('bulkTypeWrap');
  var typeInput  = document.getElementById('bulkTypeInput');
  var confirmBtn = document.getElementById('bulkConfirmBtn');
  var needType   = false;

  document.getElementById('bulkDeleteBtn').addEventListener('click', function () {
    var n = selectAllMatching ? totalMatching : checked().length;
    if (n === 0) { showToast('Belum ada pengguna dipilih', 'info'); return; }
    document.getElementById('bulkModalCount').textContent = n.toLocaleString('id-ID');
    needType = n > TYPE_THRESHOLD;
    typeWrap.classList.toggle('hidden', !needType);
    typeInput.value = '';
    var fzReset = document.getElementById('bulkForceZero');
    if (fzReset) fzReset.checked = false; // selalu default OFF tiap modal dibuka
    confirmBtn.disabled = needType;
    modal.classList.remove('hidden');
    if (needType) setTimeout(function () { typeInput.focus(); }, 50);
  });

  if (typeInput) typeInput.addEventListener('input', function () {
    confirmBtn.disabled = needType && typeInput.value.trim().toUpperCase() !== 'HAPUS';
  });

  window.closeBulkModal = function () { modal.classList.add('hidden'); };

  window.submitBulkDelete = function () {
    if (needType && typeInput.value.trim().toUpperCase() !== 'HAPUS') return;
    document.getElementById('bulkSelectAllMatching').value = selectAllMatching ? '1' : '0';
    document.getElementById('bulkIds').value = selectAllMatching ? '' : checked().map(function (b) { return b.value; }).join(',');
    var fz = document.getElementById('bulkForceZero');
    document.getElementById('bulkForceZeroInput').value = (fz && fz.checked) ? '1' : '0';
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Memproses...';
    document.getElementById('bulkDeleteForm').submit();
  };

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') window.closeBulkModal(); });
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
