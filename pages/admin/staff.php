<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin(); // HANYA admin utama yang kelola staff

$pageTitle   = 'Staff Management';
$topbarTitle = 'Staff Management';

$availablePermissions = [
    'dashboard'       => 'Dashboard & Statistik',
    'products'        => 'Kelola Produk (+ Bundle & Review)',
    'transactions'    => 'Kelola Transaksi',
    'users'           => 'Kelola Users',
    'content'         => 'Kelola Konten Kelas',
    'coupons'         => 'Kelola Kupon',
    'affiliates'      => 'Affiliate & Komisi',
    'notifications'   => 'Template Notifikasi',
    'activity_logs'   => 'Activity Logs',
    'downloads'       => 'Download Manager',
    'email_sequences' => 'Email Sequence',
    'certificates'    => 'Sertifikat',
    'abandoned_carts' => 'Abandoned Cart',
    'broadcast'       => 'Broadcast',
];

$staffUsers = [];
try {
    $rows = getDB()->query(
        "SELECT u.id, u.name, u.email, u.status, u.created_at,
                (SELECT COUNT(*) FROM staff_permissions WHERE user_id = u.id) AS perm_count
         FROM users u WHERE u.role = 'staff' ORDER BY u.created_at DESC"
    )->fetchAll();
    $permStmt = getDB()->prepare("SELECT permission FROM staff_permissions WHERE user_id = ?");
    foreach ($rows as &$r) {
        $permStmt->execute([$r['id']]);
        $r['permissions'] = $permStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($r);
    $staffUsers = $rows;
} catch (Exception $e) {
    error_log('staff page load error: ' . $e->getMessage());
}

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

      <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Staff Management</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Akun staff dengan akses panel admin terbatas</p>
        </div>
        <button onclick="openCreateStaff()" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">+ Tambah Staff</button>
      </div>

      <div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-900/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
        Staff bisa mengakses panel admin sesuai permission yang diberikan. Staff <strong>tidak bisa</strong> mengubah Settings, Branding, License, Integrasi, dan tidak bisa mengelola staff lain.
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($staffUsers)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Belum ada staff. Klik "Tambah Staff" untuk membuat akun staff pertama.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
          <thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
            <th class="text-left px-5 py-3">Nama / Email</th>
            <th class="text-center px-4 py-3">Status</th>
            <th class="text-center px-4 py-3">Permission</th>
            <th class="text-left px-4 py-3">Dibuat</th>
            <th class="text-right px-5 py-3">Aksi</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($staffUsers as $st): ?>
            <tr>
              <td class="px-5 py-3">
                <p class="font-medium text-gray-900 dark:text-white"><?= e($st['name']) ?></p>
                <p class="text-xs text-gray-400"><?= e($st['email']) ?></p>
              </td>
              <td class="px-4 py-3 text-center">
                <span class="text-xs px-2 py-0.5 rounded-full <?= $st['status'] === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' ?>"><?= e($st['status']) ?></span>
              </td>
              <td class="px-4 py-3 text-center text-gray-600 dark:text-gray-300"><?= (int) $st['perm_count'] ?> akses</td>
              <td class="px-4 py-3 text-gray-400 text-xs"><?= date('d M Y', strtotime($st['created_at'])) ?></td>
              <td class="px-5 py-3 text-right">
                <div class="flex items-center justify-end gap-1.5">
                  <button onclick='openPermStaff(<?= (int) $st['id'] ?>, <?= json_encode($st['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, <?= json_encode($st['permissions'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'
                          class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Kelola Akses</button>
                  <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-staff" id="del-staff-<?= (int) $st['id'] ?>" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="staff_id" value="<?= (int) $st['id'] ?>">
                    <button type="button" onclick="confirmAndSubmit('del-staff-<?= (int) $st['id'] ?>', {title:'Hapus Akun Staff', message:'Akun akan diubah jadi user biasa & dinonaktifkan.', confirmText:'Hapus', variant:'danger'})"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">Hapus</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Modal Tambah Staff -->
<div id="modal-create" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-staff">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-white">Tambah Staff Baru</h3>
        <button type="button" onclick="closeModal('modal-create')" class="text-gray-400 hover:text-gray-600">✕</button>
      </div>
      <div class="p-5 space-y-4">
        <div><label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">Nama</label>
          <input type="text" name="name" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">Email</label>
          <input type="email" name="email" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">Password (min 8 karakter)</label>
          <input type="password" name="password" required minlength="8" autocomplete="new-password" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div>
          <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Permission</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
            <?php foreach ($availablePermissions as $key => $label): ?>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
              <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" class="rounded"> <?= e($label) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2">
        <button type="button" onclick="closeModal('modal-create')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Buat Staff</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Kelola Akses -->
<div id="modal-perm" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-staff-permissions">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="staff_id" id="perm-staff-id">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 class="font-semibold text-gray-900 dark:text-white">Akses untuk <span id="perm-staff-name"></span></h3>
        <button type="button" onclick="closeModal('modal-perm')" class="text-gray-400 hover:text-gray-600">✕</button>
      </div>
      <div class="p-5">
        <div class="flex gap-2 mb-3">
          <button type="button" onclick="permAll(true)" class="text-xs px-2 py-1 rounded border border-gray-200 dark:border-gray-700">Pilih Semua</button>
          <button type="button" onclick="permAll(false)" class="text-xs px-2 py-1 rounded border border-gray-200 dark:border-gray-700">Reset</button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
          <?php foreach ($availablePermissions as $key => $label): ?>
          <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
            <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" data-perm class="rounded perm-check"> <?= e($label) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2">
        <button type="button" onclick="closeModal('modal-perm')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateStaff(){ document.getElementById('modal-create').classList.remove('hidden'); }
function closeModal(id){ document.getElementById(id).classList.add('hidden'); }
function permAll(v){ document.querySelectorAll('#modal-perm .perm-check').forEach(c => c.checked = v); }
function openPermStaff(id, name, perms){
  document.getElementById('perm-staff-id').value = id;
  document.getElementById('perm-staff-name').textContent = name;
  document.querySelectorAll('#modal-perm .perm-check').forEach(c => c.checked = (perms || []).indexOf(c.value) !== -1);
  document.getElementById('modal-perm').classList.remove('hidden');
}
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
