<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { redirect('/admin/no-access'); }

$pageTitle   = 'Product Bundles';
$topbarTitle = 'Product Bundles';

$bundles = [];
try {
    $bundles = getDB()->query(
        "SELECT pb.*, (SELECT COUNT(*) FROM product_bundle_items WHERE bundle_id = pb.id) AS item_count
         FROM product_bundles pb ORDER BY pb.sort_order ASC, pb.created_at DESC"
    )->fetchAll();
} catch (Exception $e) { error_log('admin bundles error: ' . $e->getMessage()); }

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
        <div><h2 class="text-xl font-bold text-gray-900 dark:text-white">Product Bundles</h2><p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Paket beberapa produk dengan satu harga khusus</p></div>
        <div class="flex items-center gap-2">
          <a href="<?= e(APP_URL) ?>/admin/docs#bundling" target="_blank" rel="noopener"
             class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            Panduan
          </a>
          <a href="<?= e(APP_URL) ?>/admin/bundles/edit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">+ Buat Bundle</a>
        </div>
      </div>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($bundles)): ?>
        <div class="p-10 text-center text-sm text-gray-400">Belum ada bundle.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40">
          <th class="text-left px-5 py-3">Nama</th><th class="text-center px-4 py-3">Item</th><th class="text-right px-4 py-3">Harga Bundle</th><th class="text-right px-4 py-3">Normal</th><th class="text-center px-4 py-3">Hemat</th><th class="text-center px-4 py-3">Status</th><th class="text-right px-5 py-3">Aksi</th>
        </tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($bundles as $b):
            $hemat = (float)$b['original_price'] > 0 ? round(((float)$b['original_price'] - (float)$b['bundle_price'])/(float)$b['original_price']*100) : 0;
          ?>
          <tr>
            <td class="px-5 py-3">
              <p class="font-medium text-gray-900 dark:text-white">
                <?= e($b['name']) ?>
                <?php if ((int)($b['hide_from_catalog'] ?? 0) === 1): ?>
                  <span class="inline-flex items-center ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300">Unlisted</span>
                <?php endif; ?>
              </p>
              <p class="text-xs text-gray-400 font-mono"><?= e($b['slug']) ?></p>
            </td>
            <td class="px-4 py-3 text-center text-gray-500"><?= (int)$b['item_count'] ?></td>
            <td class="px-4 py-3 text-right text-gray-900 dark:text-white"><?= e(formatRupiah((float)$b['bundle_price'])) ?></td>
            <td class="px-4 py-3 text-right text-gray-400 line-through"><?= e(formatRupiah((float)$b['original_price'])) ?></td>
            <td class="px-4 py-3 text-center"><?php if ($hemat>0): ?><span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"><?= $hemat ?>%</span><?php else: ?><span class="text-gray-300">—</span><?php endif; ?></td>
            <td class="px-4 py-3 text-center"><span class="text-xs px-2 py-0.5 rounded-full <?= $b['status']==='active'?'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400':($b['status']==='draft'?'bg-gray-100 text-gray-500 dark:bg-gray-800':'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400') ?>"><?= e($b['status']) ?></span></td>
            <td class="px-5 py-3 text-right whitespace-nowrap">
              <a href="<?= e(APP_URL) ?>/admin/bundles/edit?id=<?= (int)$b['id'] ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Edit</a>
              <a href="<?= e(APP_URL) ?>/bundles/<?= e($b['slug']) ?>" target="_blank" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">Lihat</a>
              <button type="button" onclick="copyBundleUrl('<?= e(APP_URL) ?>/bundles/<?= e($b['slug']) ?>')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                Salin URL
              </button>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-bundle" class="inline" id="del-b-<?= (int)$b['id'] ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="bundle_id" value="<?= (int)$b['id'] ?>"><button type="button" onclick="confirmAndSubmit('del-b-<?= (int)$b['id'] ?>', {title:'Hapus Bundle', message:'Hapus bundle ini? Tindakan permanen.', confirmText:'Hapus', variant:'danger'})" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600">Hapus</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<script>
// Salin URL bundle publik ke clipboard (fallback execCommand → prompt).
window.copyBundleUrl = function (url) {
  var ok = function () { if (window.showToast) showToast('URL disalin: ' + url, 'success'); };
  var legacy = function () {
    try {
      var ta = document.createElement('textarea');
      ta.value = url; ta.style.position = 'fixed'; ta.style.top = '0'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.focus(); ta.select();
      var d = document.execCommand('copy'); document.body.removeChild(ta);
      if (d) { ok(); return true; }
    } catch (e) {}
    return false;
  };
  var fail = function () {
    if (legacy()) return;
    if (window.showToast) showToast('Tidak bisa menyalin otomatis. Salin manual dari kotak.', 'error');
    try { window.prompt('Salin URL Bundle:', url); } catch (e) {}
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(ok).catch(fail);
  } else { fail(); }
};
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
