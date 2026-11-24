<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('activity_logs')) { redirect('/admin/no-access'); }

$pageTitle   = 'Activity Logs';
$topbarTitle = 'Activity Logs';

$pdo = getDB();

// ─── Filter params ────────────────────────────────────────────
$filterRole    = $_GET['actor_role'] ?? '';
$filterAction  = trim($_GET['action']    ?? '');
$filterUserId  = (int) ($_GET['user_id']   ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to']   ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

// ─── Build WHERE ──────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if (in_array($filterRole, ['admin', 'user', 'system'], true)) {
    $where[]  = 'al.actor_role = ?';
    $params[] = $filterRole;
}
if ($filterAction !== '') {
    $where[]  = 'al.action LIKE ?';
    $params[] = '%' . $filterAction . '%';
}
if ($filterUserId > 0) {
    $where[]  = 'al.user_id = ?';
    $params[] = $filterUserId;
}
if ($filterDateFrom !== '') {
    $where[]  = 'al.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[]  = 'al.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}

$whereStr = implode(' AND ', $where);

// ─── Query ────────────────────────────────────────────────────
$logs  = [];
$total = 0;
$pages = 1;

try {
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al WHERE $whereStr");
    $cntStmt->execute($params);
    $total = (int) $cntStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));

    $stmt = $pdo->prepare(
        "SELECT al.*, u.name AS user_name, u.email AS user_email
         FROM activity_logs al
         LEFT JOIN users u ON al.user_id = u.id
         WHERE $whereStr
         ORDER BY al.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Activity logs query error: ' . $e->getMessage());
    flash('error', 'Gagal memuat log aktivitas.', 'error');
}

// ─── Helpers ──────────────────────────────────────────────────
$roleBadge = function(string $role): string {
    return match ($role) {
        'admin'  => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">Admin</span>',
        'user'   => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">User</span>',
        default  => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">System</span>',
    };
};

$buildPageUrl = function(int $p) use ($filterRole, $filterAction, $filterUserId, $filterDateFrom, $filterDateTo): string {
    $q = array_filter([
        'actor_role' => $filterRole,
        'action'     => $filterAction,
        'user_id'    => $filterUserId ?: '',
        'date_from'  => $filterDateFrom,
        'date_to'    => $filterDateTo,
        'page'       => $p > 1 ? $p : '',
    ], fn($v) => $v !== '' && $v !== 0);
    return '?' . http_build_query($q);
};

$hasFilter = ($filterRole || $filterAction || $filterUserId || $filterDateFrom || $filterDateTo);

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

      <div class="mb-5 flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Activity Logs</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Audit trail semua aksi admin, user, dan sistem<?php if ($total > 0): ?> — <span class="font-medium"><?= number_format($total) ?></span> entri<?php endif; ?></p>
        </div>
        <button type="button" onclick="openClearLogsModal()"
          class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800/60 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/10 transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Clear Logs
        </button>
      </div>

      <!-- ─── Filter ──────────────────────────────────────────── -->
      <form method="GET" action="" class="mb-5 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1">Role</label>
            <select name="actor_role"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
              <option value="">Semua Role</option>
              <option value="admin"  <?= $filterRole === 'admin'  ? 'selected' : '' ?>>Admin</option>
              <option value="user"   <?= $filterRole === 'user'   ? 'selected' : '' ?>>User</option>
              <option value="system" <?= $filterRole === 'system' ? 'selected' : '' ?>>System</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1">Aksi</label>
            <input type="text" name="action" value="<?= e($filterAction) ?>" placeholder="Cari aksi..."
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1">Dari Tanggal</label>
            <input type="date" name="date_from" value="<?= e($filterDateFrom) ?>"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1">Sampai Tanggal</label>
            <input type="date" name="date_to" value="<?= e($filterDateTo) ?>"
              class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]">
          </div>

          <div class="flex items-end gap-2">
            <button type="submit"
              class="flex-1 h-9 px-4 rounded-lg text-sm font-semibold text-white"
              style="background:var(--brand-color)">Filter</button>
            <?php if ($hasFilter): ?>
            <a href="?" class="h-9 px-3 inline-flex items-center rounded-lg text-sm border border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800">×</a>
            <?php endif; ?>
          </div>

        </div>
      </form>

      <!-- ─── Table ────────────────────────────────────────────── -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($logs)): ?>
        <div class="px-5 py-16 text-center">
          <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
          </svg>
          <p class="text-sm text-gray-400 dark:text-gray-500">
            <?= $hasFilter ? 'Tidak ada log yang cocok dengan filter.' : 'Belum ada aktivitas yang tercatat.' ?>
          </p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap">Waktu</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Entity</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Deskripsi</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap">IP Address</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($logs as $log): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">

                <!-- Waktu -->
                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                  <?= e(date('d/m/y', strtotime($log['created_at']))) ?><br>
                  <span class="text-gray-400 dark:text-gray-600"><?= e(date('H:i:s', strtotime($log['created_at']))) ?></span>
                </td>

                <!-- User -->
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2">
                    <?= $roleBadge($log['actor_role']) ?>
                    <?php if ($log['user_name']): ?>
                    <div>
                      <p class="text-xs font-medium text-gray-900 dark:text-white leading-tight"><?= e($log['user_name']) ?></p>
                      <p class="text-xs text-gray-400 leading-tight"><?= e($log['user_email'] ?? '') ?></p>
                    </div>
                    <?php else: ?>
                    <span class="text-xs text-gray-400 italic">—</span>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- Aksi -->
                <td class="px-4 py-3">
                  <code class="text-xs px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded font-mono"><?= e($log['action']) ?></code>
                </td>

                <!-- Entity -->
                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                  <?php if ($log['entity_type']): ?>
                    <span class="font-medium"><?= e($log['entity_type']) ?></span>
                    <?php if ($log['entity_id']): ?><span class="text-gray-400"> #<?= (int)$log['entity_id'] ?></span><?php endif; ?>
                  <?php else: ?>
                    <span class="text-gray-300 dark:text-gray-700">—</span>
                  <?php endif; ?>
                </td>

                <!-- Deskripsi -->
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 max-w-xs">
                  <?= e($log['description'] ?? '—') ?>
                </td>

                <!-- IP -->
                <td class="px-4 py-3 text-xs font-mono text-gray-400 dark:text-gray-500 whitespace-nowrap">
                  <?= e($log['ip_address'] ?? '—') ?>
                </td>

              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 gap-4">
          <span>Halaman <?= $page ?> dari <?= $pages ?></span>
          <div class="flex gap-1 flex-wrap justify-end">
            <?php if ($page > 1): ?>
            <a href="<?= e($buildPageUrl($page - 1)) ?>"
               class="px-2.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">‹</a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $pages; $p++): ?>
              <?php if ($p === $page): ?>
                <span class="px-2.5 py-1 rounded-lg text-xs font-semibold text-white" style="background:var(--brand-color)"><?= $p ?></span>
              <?php elseif ($p === 1 || $p === $pages || abs($p - $page) <= 2): ?>
                <a href="<?= e($buildPageUrl($p)) ?>"
                   class="px-2.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800"><?= $p ?></a>
              <?php elseif (abs($p - $page) === 3): ?>
                <span class="px-1 py-1">…</span>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $pages): ?>
            <a href="<?= e($buildPageUrl($page + 1)) ?>"
               class="px-2.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">›</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<!-- ─── Modal Clear Activity Logs ─────────────────────────────── -->
<div id="clear-logs-modal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeClearLogsModal()"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-800 w-full max-w-md">

      <!-- Header -->
      <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 dark:border-gray-800">
        <div class="w-9 h-9 bg-red-50 dark:bg-red-900/20 rounded-xl flex items-center justify-center shrink-0">
          <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
          </svg>
        </div>
        <div>
          <h3 class="text-base font-bold text-gray-900 dark:text-white">Hapus Activity Logs</h3>
          <p class="text-xs text-gray-400">Tindakan ini tidak bisa dibatalkan</p>
        </div>
        <button type="button" onclick="closeClearLogsModal()" class="ml-auto text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <!-- Form -->
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/clear-activity-logs" class="p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <!-- Mode selection -->
        <div class="space-y-2">
          <label id="opt-older"
            class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition-colors border-gray-200 dark:border-gray-700">
            <input type="radio" name="clear_mode" value="older_than" checked
              onchange="onClearModeChange()"
              class="mt-0.5 accent-[var(--brand-color)]">
            <div class="flex-1 min-w-0">
              <span class="text-sm font-medium text-gray-900 dark:text-white">Hapus log lama saja</span>
              <div class="flex items-center gap-2 mt-1.5">
                <span class="text-xs text-gray-500">Lebih dari</span>
                <input type="number" name="days" id="clearDays" value="90" min="1" max="3650"
                  onclick="event.stopPropagation()"
                  class="w-16 px-2 py-1 border border-gray-200 dark:border-gray-700 rounded text-xs text-center bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-1 focus:ring-[var(--brand-color)]">
                <span class="text-xs text-gray-500">hari yang lalu</span>
              </div>
            </div>
          </label>

          <label id="opt-all"
            class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition-colors border-gray-200 dark:border-gray-700">
            <input type="radio" name="clear_mode" value="all"
              onchange="onClearModeChange()"
              class="mt-0.5 accent-red-500">
            <div class="flex-1">
              <span class="text-sm font-medium text-gray-900 dark:text-white">Hapus SEMUA log</span>
              <p class="text-xs text-red-500 dark:text-red-400 mt-0.5">Seluruh riwayat audit trail akan hilang permanen</p>
            </div>
          </label>
        </div>

        <!-- Konfirmasi ketik ulang -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Ketik <strong class="text-red-600 dark:text-red-400 font-mono tracking-wider">HAPUS</strong> untuk konfirmasi
          </label>
          <input type="text" name="confirm_text" id="clearConfirmText" required autocomplete="off"
            placeholder="HAPUS"
            class="w-full h-10 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-red-400 dark:focus:ring-red-700 placeholder:text-gray-300 dark:placeholder:text-gray-600">
        </div>

        <!-- Actions -->
        <div class="flex gap-3 pt-1">
          <button type="button" onclick="closeClearLogsModal()"
            class="flex-1 py-2.5 border border-gray-200 dark:border-gray-700 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
            Batal
          </button>
          <button type="submit" data-loading="Menghapus..."
            class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-semibold transition-colors">
            Hapus Logs
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<script>
function openClearLogsModal() {
  document.getElementById('clear-logs-modal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  // Reset ke mode default setiap kali dibuka
  var form = document.querySelector('#clear-logs-modal form');
  form.reset();
  onClearModeChange();
  setTimeout(function() {
    document.getElementById('clearConfirmText').focus();
  }, 50);
}

function closeClearLogsModal() {
  document.getElementById('clear-logs-modal').classList.add('hidden');
  document.body.style.overflow = '';
  document.querySelector('#clear-logs-modal form').reset();
}

function onClearModeChange() {
  var radios  = document.querySelectorAll('input[name="clear_mode"]');
  var optOlder = document.getElementById('opt-older');
  var optAll   = document.getElementById('opt-all');
  var selected = '';
  radios.forEach(function(r) { if (r.checked) selected = r.value; });

  // Highlight mode yang dipilih
  optOlder.className = optOlder.className.replace(/border-(gray-200|gray-700|red-\d+|\[var\(--brand-color\)\])/g, '');
  optAll.className   = optAll.className.replace(/border-(gray-200|gray-700|red-\d+|\[var\(--brand-color\)\])/g, '');

  if (selected === 'older_than') {
    optOlder.style.borderColor = 'var(--brand-color)';
    optOlder.style.background  = 'color-mix(in srgb, var(--brand-color) 5%, transparent)';
    optAll.style.borderColor   = '';
    optAll.style.background    = '';
  } else {
    optAll.style.borderColor   = '#ef4444';
    optAll.style.background    = 'rgba(239,68,68,0.05)';
    optOlder.style.borderColor = '';
    optOlder.style.background  = '';
  }
}

// Loading state pada tombol submit
document.querySelector('#clear-logs-modal form').addEventListener('submit', function(e) {
  var btn = this.querySelector('[data-loading]');
  if (!btn) return;
  // Validasi confirm_text sisi client sebelum submit
  var confirmVal = document.getElementById('clearConfirmText').value.trim();
  if (confirmVal !== 'HAPUS') {
    e.preventDefault();
    document.getElementById('clearConfirmText').focus();
    document.getElementById('clearConfirmText').style.borderColor = '#ef4444';
    return;
  }
  btn.disabled = true;
  btn.textContent = btn.dataset.loading;
});

// Highlight confirm text saat valid
document.getElementById('clearConfirmText').addEventListener('input', function() {
  this.style.borderColor = this.value.trim() === 'HAPUS' ? '#22c55e' : '';
});

// Init highlight mode default
onClearModeChange();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
