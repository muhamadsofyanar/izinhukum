<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('downloads')) { redirect('/admin/no-access'); }

$pageTitle   = 'Download Manager';
$topbarTitle = 'Download Manager';

$downloads = [];
$products  = [];
$logs      = [];
try {
    $pdo = getDB();
    $downloads = $pdo->query(
        "SELECT pd.*, p.name AS product_name FROM product_downloads pd
         JOIN products p ON p.id = pd.product_id
         ORDER BY p.name ASC, pd.sort_order ASC, pd.created_at DESC"
    )->fetchAll();
    $products = $pdo->query("SELECT id, name FROM products WHERE status='active' ORDER BY name ASC")->fetchAll();
    $logs = $pdo->query(
        "SELECT dl.*, pd.title, p.name AS product_name, u.name AS user_name, u.email AS user_email
         FROM download_logs dl
         JOIN product_downloads pd ON pd.id = dl.download_id
         JOIN products p ON p.id = pd.product_id
         JOIN users u ON u.id = dl.user_id
         ORDER BY dl.downloaded_at DESC LIMIT 20"
    )->fetchAll();
} catch (Exception $e) {
    error_log('admin downloads load error: ' . $e->getMessage());
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
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Download Manager</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">File terproteksi yang di-attach ke produk</p>
        </div>
        <button onclick="document.getElementById('modal-upload').classList.remove('hidden')" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">+ Upload File</button>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-6">
        <?php if (empty($downloads)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Belum ada file. Klik "Upload File" untuk menambah.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
          <thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
            <th class="text-left px-5 py-3">File</th><th class="text-left px-4 py-3">Produk</th>
            <th class="text-center px-4 py-3">Ukuran</th><th class="text-center px-4 py-3">Download</th>
            <th class="text-center px-4 py-3">Status</th><th class="text-right px-5 py-3">Aksi</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($downloads as $d): ?>
            <tr>
              <td class="px-5 py-3">
                <p class="font-medium text-gray-900 dark:text-white"><?= e($d['title']) ?><?php if ($d['version']): ?> <span class="text-xs px-1.5 py-0.5 rounded bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-brand"><?= e($d['version']) ?></span><?php endif; ?></p>
                <p class="text-xs text-gray-400"><?= e($d['filename_orig']) ?></p>
              </td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= e($d['product_name']) ?></td>
              <td class="px-4 py-3 text-center text-gray-500"><?= e(formatFileSize((int) $d['file_size'])) ?></td>
              <td class="px-4 py-3 text-center text-gray-500"><?= (int) $d['download_count'] ?></td>
              <td class="px-4 py-3 text-center"><span class="text-xs px-2 py-0.5 rounded-full <?= $d['status'] === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800' ?>"><?= e($d['status']) ?></span></td>
              <td class="px-5 py-3 text-right">
                <button onclick='openEditDl(<?= json_encode(["id"=>$d["id"],"title"=>$d["title"],"description"=>$d["description"],"version"=>$d["version"],"sort_order"=>$d["sort_order"],"status"=>$d["status"]], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)' class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Edit</button>
                <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-download" id="del-dl-<?= (int)$d['id'] ?>" class="inline">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button type="button" onclick="confirmAndSubmit('del-dl-<?= (int)$d['id'] ?>', {title:'Hapus File', message:'Hapus file ini permanen?', confirmText:'Hapus', variant:'danger'})" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600 hover:bg-red-50">Hapus</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>

      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">Download Logs (20 terbaru)</h3></div>
        <?php if (empty($logs)): ?>
        <div class="p-6 text-center text-sm text-gray-400">Belum ada aktivitas download.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($logs as $lg): ?>
          <tr><td class="px-5 py-2.5 text-gray-400 text-xs"><?= date('d M Y H:i', strtotime($lg['downloaded_at'])) ?></td>
            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300"><?= e($lg['title']) ?></td>
            <td class="px-4 py-2.5 text-gray-500"><?= e($lg['user_name']) ?> <span class="text-xs text-gray-400">(<?= e($lg['user_email']) ?>)</span></td>
            <td class="px-5 py-2.5 text-right text-xs text-gray-400 font-mono"><?= e($lg['ip_address'] ?? '') ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Modal Upload -->
<div id="modal-upload" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-download" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">Upload File Baru</h3><button type="button" onclick="document.getElementById('modal-upload').classList.add('hidden')" class="text-gray-400">✕</button></div>
      <div class="p-5 space-y-3">
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Produk</label>
          <select name="product_id" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
            <option value="">— Pilih Produk —</option>
            <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Judul Tampilan</label><input type="text" name="title" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Deskripsi (opsional)</label><input type="text" name="description" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Versi (opsional)</label><input type="text" name="version" placeholder="v1.0" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <?php
          // Batas efektif = terkecil dari batas aplikasi (100MB) dan batas PHP
          // server (post_max_size/upload_max_filesize) — di shared hosting batas
          // server sering jauh lebih kecil dan uploadnya gagal membingungkan.
          $srvLimit = phpUploadLimitBytes();
          $effLimit = $srvLimit > 0 ? min($srvLimit, DOWNLOAD_MAX_SIZE) : DOWNLOAD_MAX_SIZE;
        ?>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">File (maks <?= e(formatFileSize($effLimit)) ?>)</label>
          <input type="file" name="file" required data-max="<?= (int) $effLimit ?>" class="w-full text-sm text-gray-600 dark:text-gray-300"
                 onchange="var w=document.getElementById('dl-size-warn');var f=this.files[0];var over=f&&f.size>parseInt(this.dataset.max,10);w.classList.toggle('hidden',!over);this.form.querySelector('button[type=submit]').disabled=!!over;">
          <p id="dl-size-warn" class="hidden text-xs text-red-500 mt-1.5">File melebihi batas upload (<?= e(formatFileSize($effLimit)) ?>) — perkecil file atau naikkan batas PHP di hosting.</p>
          <?php if ($srvLimit > 0 && $srvLimit < DOWNLOAD_MAX_SIZE): ?>
          <p class="text-xs text-gray-400 mt-1">Batas ini mengikuti pengaturan PHP hosting (post_max_size / upload_max_filesize).</p>
          <?php endif; ?>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-upload').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button><button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Upload</button></div>
    </form>
  </div>
</div>

<!-- Modal Edit -->
<div id="modal-edit" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md">
    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-download">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" id="edit-id">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">Edit File</h3><button type="button" onclick="document.getElementById('modal-edit').classList.add('hidden')" class="text-gray-400">✕</button></div>
      <div class="p-5 space-y-3">
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Judul</label><input type="text" name="title" id="edit-title" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Deskripsi</label><input type="text" name="description" id="edit-desc" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Versi</label><input type="text" name="version" id="edit-version" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Urutan</label><input type="number" name="sort_order" id="edit-sort" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
        </div>
        <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Status</label><select name="status" id="edit-status" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></div>
      </div>
      <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-2"><button type="button" onclick="document.getElementById('modal-edit').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700">Batal</button><button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan</button></div>
    </form>
  </div>
</div>

<script>
function openEditDl(d){
  document.getElementById('edit-id').value=d.id;
  document.getElementById('edit-title').value=d.title||'';
  document.getElementById('edit-desc').value=d.description||'';
  document.getElementById('edit-version').value=d.version||'';
  document.getElementById('edit-sort').value=d.sort_order||0;
  document.getElementById('edit-status').value=d.status||'active';
  document.getElementById('modal-edit').classList.remove('hidden');
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
