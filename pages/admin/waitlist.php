<?php
// Admin: daftar pendaftar waitlist produk coming soon (+ export CSV).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { redirect('/admin/no-access'); }

$pageTitle   = 'Waitlist';
$topbarTitle = 'Waitlist Produk';

$productFilter = (int) ($_GET['product_id'] ?? 0);
$statusFilter  = $_GET['status'] ?? '';
$allowedWlStatus = ['waiting', 'notified', 'converted'];
if (!in_array($statusFilter, $allowedWlStatus, true)) $statusFilter = '';

$where  = [];
$params = [];
if ($productFilter > 0)   { $where[] = 'pw.product_id = ?'; $params[] = $productFilter; }
if ($statusFilter !== '') { $where[] = 'pw.status = ?';     $params[] = $statusFilter; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$entries = [];
$productsWithWl = [];
$stat = ['total' => 0, 'waiting' => 0, 'notified' => 0, 'converted' => 0];
try {
    $pdo = getDB();

    // ── Export CSV (hormati filter aktif) ─────────────────────
    if (isset($_GET['export'])) {
        $ex = $pdo->prepare(
            "SELECT pw.name, pw.whatsapp, pw.email, p.name AS product_name, pw.status, pw.created_at
             FROM product_waitlist pw JOIN products p ON p.id = pw.product_id
             $whereSQL ORDER BY pw.created_at DESC"
        );
        $ex->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="waitlist-' . date('Ymd-His') . '.csv"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM agar Excel baca UTF-8
        fputcsv($fp, ['Nama', 'WhatsApp', 'Email', 'Produk', 'Status', 'Terdaftar']);
        while ($row = $ex->fetch()) {
            fputcsv($fp, [$row['name'], $row['whatsapp'], $row['email'], $row['product_name'], $row['status'], $row['created_at']]);
        }
        fclose($fp);
        exit;
    }

    // Dropdown produk yang punya pendaftar.
    $productsWithWl = $pdo->query(
        "SELECT p.id, p.name, p.status, COUNT(pw.id) AS cnt
         FROM products p JOIN product_waitlist pw ON pw.product_id = p.id
         GROUP BY p.id ORDER BY cnt DESC"
    )->fetchAll();

    // Statistik (hormati filter produk, abaikan filter status).
    $stWhere  = $productFilter > 0 ? 'WHERE product_id = ?' : '';
    $stParams = $productFilter > 0 ? [$productFilter] : [];
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'waiting')   AS waiting,
                SUM(status = 'notified')  AS notified,
                SUM(status = 'converted') AS converted
         FROM product_waitlist $stWhere"
    );
    $st->execute($stParams);
    $row = $st->fetch();
    if ($row) $stat = ['total' => (int)$row['total'], 'waiting' => (int)$row['waiting'], 'notified' => (int)$row['notified'], 'converted' => (int)$row['converted']];

    $stmt = $pdo->prepare(
        "SELECT pw.*, p.name AS product_name, p.status AS product_status
         FROM product_waitlist pw JOIN products p ON p.id = pw.product_id
         $whereSQL ORDER BY pw.created_at DESC LIMIT 500"
    );
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Admin waitlist error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data waitlist.', 'error');
}

$wlBadge = fn(string $s): string => match ($s) {
    'waiting'   => '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">Menunggu</span>',
    'notified'  => '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">Dinotifikasi</span>',
    'converted' => '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Jadi Pembeli</span>',
    default     => '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">' . e($s) . '</span>',
};

$qs = fn(array $extra): string => '?' . http_build_query(array_filter(array_merge(
    ['product_id' => $productFilter ?: null, 'status' => $statusFilter ?: null], $extra
), fn($v) => $v !== null && $v !== ''));

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

      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Waitlist Produk</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Pendaftar daftar tunggu produk Coming Soon — otomatis dinotifikasi saat launch.</p>
        </div>
        <?php if ($stat['total'] > 0): ?>
        <a href="<?= e(APP_URL) ?>/admin/waitlist<?= e($qs(['export' => 1])) ?>"
           class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition-opacity hover:opacity-90">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Export CSV
        </a>
        <?php endif; ?>
      </div>

      <!-- Statistik -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
        <?php foreach ([['Total Pendaftar', $stat['total'], 'text-gray-900 dark:text-white'],
                        ['Menunggu', $stat['waiting'], 'text-yellow-600 dark:text-yellow-400'],
                        ['Dinotifikasi', $stat['notified'], 'text-blue-600 dark:text-blue-400'],
                        ['Jadi Pembeli', $stat['converted'], 'text-green-600 dark:text-green-400']] as [$label, $val, $cls]): ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4">
          <p class="text-xs text-gray-400 dark:text-gray-500 mb-1"><?= e($label) ?></p>
          <p class="text-2xl font-bold <?= $cls ?>"><?= number_format((int) $val) ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Filter -->
      <form method="GET" action="" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 mb-5 flex flex-wrap gap-3 items-end shadow-sm">
        <div class="w-64">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk</label>
          <select name="product_id" onchange="this.form.submit()"
                  class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Produk</option>
            <?php foreach ($productsWithWl as $pw): ?>
            <option value="<?= (int) $pw['id'] ?>" <?= $productFilter === (int) $pw['id'] ? 'selected' : '' ?>><?= e($pw['name']) ?> (<?= (int) $pw['cnt'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-44">
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Status</label>
          <select name="status" onchange="this.form.submit()"
                  class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <option value="">Semua Status</option>
            <option value="waiting"   <?= $statusFilter === 'waiting'   ? 'selected' : '' ?>>Menunggu</option>
            <option value="notified"  <?= $statusFilter === 'notified'  ? 'selected' : '' ?>>Dinotifikasi</option>
            <option value="converted" <?= $statusFilter === 'converted' ? 'selected' : '' ?>>Jadi Pembeli</option>
          </select>
        </div>
      </form>

      <!-- Tabel -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pendaftar</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">WhatsApp</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Terdaftar</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php if (empty($entries)): ?>
              <tr><td colspan="6" class="px-5 py-14 text-center text-sm text-gray-400 dark:text-gray-600">Belum ada pendaftar waitlist. Set produk ke status <strong>Coming Soon</strong> agar form waitlist tampil di halaman produk.</td></tr>
              <?php else: foreach ($entries as $en): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-5 py-3.5">
                  <div class="font-medium text-gray-800 dark:text-gray-200"><?= e($en['name']) ?></div>
                  <?php if ($en['email']): ?><div class="text-xs text-gray-400 dark:text-gray-500"><?= e($en['email']) ?></div><?php endif; ?>
                </td>
                <td class="px-4 py-3.5">
                  <a href="https://wa.me/<?= e($en['whatsapp']) ?>" target="_blank" rel="noopener"
                     class="inline-flex items-center gap-1.5 text-xs font-mono text-green-600 dark:text-green-400 hover:underline" title="Chat WhatsApp">
                    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.885-9.885 9.885M20.52 3.449C18.24 1.245 15.24 0 12.045 0 5.463 0 .103 5.359.1 11.945c0 2.096.547 4.142 1.588 5.945L0 24l6.335-1.652a11.882 11.882 0 005.71 1.454h.005c6.582 0 11.946-5.361 11.949-11.949 0-3.19-1.24-6.19-3.495-8.436"/></svg>
                    <?= e($en['whatsapp']) ?>
                  </a>
                </td>
                <td class="px-4 py-3.5">
                  <div class="text-xs text-gray-700 dark:text-gray-300"><?= e($en['product_name']) ?></div>
                  <div class="mt-0.5"><?= renderStatusBadge($en['product_status'], 'product') ?></div>
                </td>
                <td class="px-4 py-3.5"><?= $wlBadge($en['status']) ?></td>
                <td class="px-4 py-3.5 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= e(date('d M Y H:i', strtotime($en['created_at']))) ?></td>
                <td class="px-5 py-3.5 text-right">
                  <form id="del-wl-<?= (int) $en['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-waitlist-entry" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>">
                    <button type="button" title="Hapus"
                            onclick="confirmAndSubmit('del-wl-<?= (int) $en['id'] ?>',{title:'Hapus Pendaftar?',message:'Hapus <?= e(addslashes($en['name'])) ?> dari waitlist? Dia tidak akan menerima notifikasi launch.',confirmText:'Ya, Hapus',variant:'danger'})"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                      </svg>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
