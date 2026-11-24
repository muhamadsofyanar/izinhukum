<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('certificates')) { redirect('/admin/no-access'); }

$pageTitle   = 'Certificate Manager';
$topbarTitle = 'Certificate Manager';

$stats = ['total' => 0, 'revoked' => 0, 'today' => 0];
$certs = []; $certProducts = []; $usersList = [];
try {
    $pdo = getDB();
    $stats['total']   = (int) $pdo->query("SELECT COUNT(*) FROM certificates WHERE status='active'")->fetchColumn();
    $stats['revoked'] = (int) $pdo->query("SELECT COUNT(*) FROM certificates WHERE status='revoked'")->fetchColumn();
    $stats['today']   = (int) $pdo->query("SELECT COUNT(*) FROM certificates WHERE status='active' AND DATE(issued_at)=CURDATE()")->fetchColumn();
    $certs = $pdo->query(
        "SELECT c.*, u.name AS user_name, u.email AS user_email, issuer.name AS issued_by_name
         FROM certificates c JOIN users u ON u.id = c.user_id LEFT JOIN users issuer ON issuer.id = c.issued_by
         ORDER BY c.issued_at DESC LIMIT 50"
    )->fetchAll();
    $certProducts = $pdo->query("SELECT id, name FROM products WHERE certificate_enabled = 1 ORDER BY name ASC")->fetchAll();
    $usersList = $pdo->query("SELECT id, name, email FROM users WHERE status='active' ORDER BY name ASC LIMIT 2000")->fetchAll();
} catch (Exception $e) { error_log('admin certs error: ' . $e->getMessage()); }

generateCSRF();
$csrf = $_SESSION['csrf_token'];
$cs = fn(string $k, string $d = '') => e(getSetting($k, $d));
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
        <div><h2 class="text-xl font-bold text-gray-900 dark:text-white">Certificate Manager</h2><p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Terbitkan & kelola sertifikat digital</p></div>
        <button onclick="document.getElementById('modal-issue').classList.remove('hidden')" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">+ Issue Sertifikat</button>
      </div>

      <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['total'] ?></p><p class="text-xs text-gray-400">Total Terbit</p></div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['today'] ?></p><p class="text-xs text-gray-400">Hari Ini</p></div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4"><p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['revoked'] ?></p><p class="text-xs text-gray-400">Dicabut</p></div>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6">
        <?php if (empty($certs)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Belum ada sertifikat.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
          <th class="text-left px-5 py-3">No</th><th class="text-left px-4 py-3">Penerima</th><th class="text-left px-4 py-3">Produk</th><th class="text-left px-4 py-3">Tanggal</th><th class="text-center px-4 py-3">Status</th><th class="text-right px-5 py-3">Aksi</th>
        </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($certs as $c): ?>
          <tr>
            <td class="px-5 py-3 font-mono text-xs text-gray-600 dark:text-gray-300"><?= e($c['certificate_no']) ?></td>
            <td class="px-4 py-3"><p class="text-gray-900 dark:text-white"><?= e($c['user_name']) ?></p><p class="text-xs text-gray-400"><?= e($c['user_email']) ?></p></td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($c['product_name']) ?></td>
            <td class="px-4 py-3 text-gray-400 text-xs"><?= date('d M Y', strtotime($c['issued_at'])) ?></td>
            <td class="px-4 py-3 text-center"><span class="text-xs px-2 py-0.5 rounded-full <?= $c['status']==='active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' ?>"><?= $c['status']==='active'?'Aktif':'Dicabut' ?></span></td>
            <td class="px-5 py-3 text-right">
              <a href="<?= e(APP_URL) ?>/certificate/<?= e($c['uuid']) ?>" target="_blank" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Lihat</a>
              <?php if ($c['status']==='active'): ?>
              <button onclick="revokeCert(<?= (int)$c['id'] ?>)" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600 hover:bg-red-50">Cabut</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>

      <!-- Settings -->
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-cert-settings" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pengaturan Sertifikat</h3></div>
        <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Nama Penandatangan</label><input type="text" name="certificate_signatory_name" value="<?= $cs('certificate_signatory_name') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Jabatan Penandatangan</label><input type="text" name="certificate_signatory_title" value="<?= $cs('certificate_signatory_title') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div class="md:col-span-2"><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Teks Footer</label><input type="text" name="certificate_footer_text" value="<?= $cs('certificate_footer_text') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Warna Background</label><input type="color" name="certificate_bg_color" value="<?= $cs('certificate_bg_color', '#1e1b4b') ?>" class="w-16 h-9 rounded border border-gray-200 dark:border-gray-700"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Warna Accent</label><input type="color" name="certificate_accent_color" value="<?= $cs('certificate_accent_color', '#6366f1') ?>" class="w-16 h-9 rounded border border-gray-200 dark:border-gray-700"></div>
        </div>
        <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-between items-center">
          <a href="<?= e(APP_URL) ?>/admin/products" class="text-xs text-gray-400 hover:text-brand">Aktifkan sertifikat per produk di menu Produk →</a>
          <button type="submit" class="px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan Pengaturan</button>
        </div>
      </form>
    </main>
  </div>
</div>

<!-- Modal Issue -->
<div id="modal-issue" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/issue-certificate">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">Issue Sertifikat Baru</h3><button type="button" onclick="document.getElementById('modal-issue').classList.add('hidden')" class="text-gray-400">✕</button></div>
      <div class="p-5 space-y-3">
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">User</label>
          <select name="user_id" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            <option value="">— Pilih User —</option><?php foreach ($usersList as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option><?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Produk (yang sertifikatnya aktif)</label>
          <select name="product_id" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            <option value="">— Pilih Produk —</option><?php foreach ($certProducts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
          <?php if (empty($certProducts)): ?><p class="text-xs text-amber-500 mt-1">Belum ada produk dengan sertifikat aktif. Aktifkan di menu Produk.</p><?php endif; ?>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-issue').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button><button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Terbitkan</button></div>
    </form>
  </div>
</div>

<form method="POST" action="<?= e(APP_URL) ?>/actions/admin/revoke-certificate" id="revoke-form" class="hidden">
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="cert_id" id="revoke-cert-id"><input type="hidden" name="reason" id="revoke-reason">
</form>
<script>
function revokeCert(id){ var r = prompt('Alasan pencabutan (opsional):'); if(r===null) return; document.getElementById('revoke-cert-id').value=id; document.getElementById('revoke-reason').value=r; document.getElementById('revoke-form').submit(); }
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
