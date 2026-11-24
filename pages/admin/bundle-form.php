<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('products')) { redirect('/admin/no-access'); }

$bundleId = (int) ($_GET['id'] ?? 0);
$pdo = getDB();
$bundle = null; $items = [];
if ($bundleId) {
    $bStmt = $pdo->prepare("SELECT * FROM product_bundles WHERE id = ? LIMIT 1");
    $bStmt->execute([$bundleId]);
    $bundle = $bStmt->fetch();
    if (!$bundle) { flash('error', 'Bundle tidak ditemukan.', 'error'); redirect('/admin/bundles'); }
    $iStmt = $pdo->prepare("SELECT pbi.product_id, p.name, p.price, p.type FROM product_bundle_items pbi JOIN products p ON p.id=pbi.product_id WHERE pbi.bundle_id=? ORDER BY pbi.sort_order");
    $iStmt->execute([$bundleId]);
    $items = $iStmt->fetchAll();
}
$existingIds = array_column($items, 'product_id');
$availSql = "SELECT id, name, price FROM products WHERE status='active'" . ($existingIds ? " AND id NOT IN (" . implode(',', array_map('intval', $existingIds)) . ")" : "") . " ORDER BY name ASC";
$availableProducts = $pdo->query($availSql)->fetchAll();

$pageTitle   = $bundle ? 'Edit Bundle' : 'Buat Bundle';
$topbarTitle = $pageTitle;
generateCSRF();
$csrf = $_SESSION['csrf_token'];
$bv = fn(string $k, string $d = '') => e((string) ($bundle[$k] ?? $d));
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>
      <a href="<?= e(APP_URL) ?>/admin/bundles" class="text-sm text-gray-400 hover:text-brand">← Bundles</a>
      <h2 class="text-xl font-bold text-gray-900 dark:text-white mt-2 mb-5"><?= $bundle ? 'Edit Bundle' : 'Buat Bundle Baru' ?></h2>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-bundle" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 space-y-3">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$bundleId ?>">
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Nama Bundle</label><input type="text" name="name" id="b-name" value="<?= $bv('name') ?>" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Slug</label><input type="text" name="slug" id="b-slug" value="<?= $bv('slug') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Tagline</label><input type="text" name="tagline" value="<?= $bv('tagline') ?>" placeholder="Hemat 40% dengan paket lengkap!" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Thumbnail URL</label><input type="text" name="thumbnail" value="<?= $bv('thumbnail') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Deskripsi</label><textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"><?= e((string)($bundle['description'] ?? '')) ?></textarea></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Harga Bundle</label><input type="number" name="bundle_price" value="<?= $bv('bundle_price','0') ?>" min="0" required class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Urutan</label><input type="number" name="sort_order" value="<?= $bv('sort_order','0') ?>" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white"></div>
          </div>
          <!-- Visibilitas katalog (bundle "unlisted") -->
          <div class="pt-1">
            <input type="hidden" name="hide_from_catalog" value="0">
            <label class="flex items-start gap-2.5 cursor-pointer select-none">
              <input type="checkbox" name="hide_from_catalog" id="bundleHideCatalog" value="1" <?= ($bundle['hide_from_catalog'] ?? 0) ? 'checked' : '' ?>
                     class="w-4 h-4 mt-0.5 rounded accent-[var(--brand-color)] shrink-0">
              <span class="min-w-0">
                <span class="block text-xs font-medium text-gray-700 dark:text-gray-300">Sembunyikan dari katalog publik</span>
                <span class="block text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Bundle tidak tampil di halaman depan &amp; daftar produk, tapi <strong>tetap bisa dibeli</strong> lewat link langsung. Cocok untuk paket khusus/rahasia.</span>
              </span>
            </label>
          </div>

          <div><label class="block text-xs font-medium text-gray-500 uppercase mb-1.5">Status</label>
            <select name="status" class="w-full h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
              <?php foreach (['draft'=>'Draft','active'=>'Aktif','inactive'=>'Nonaktif'] as $k=>$v): ?><option value="<?= $k ?>" <?= ($bundle['status'] ?? 'draft')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
            </select></div>
          <?php if ($bundle): ?><p class="text-xs text-gray-400">Harga normal (auto): <strong><?= e(formatRupiah((float)$bundle['original_price'])) ?></strong> — dihitung dari produk dalam bundle.</p><?php endif; ?>
          <button type="submit" class="w-full py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Simpan Bundle</button>
        </form>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Produk dalam Bundle</h3>
          <?php if (!$bundle): ?>
          <p class="text-sm text-gray-400">Simpan bundle terlebih dahulu untuk menambahkan produk.</p>
          <?php else: ?>
          <div class="space-y-2 mb-4">
            <?php if (empty($items)): ?><p class="text-sm text-gray-400">Belum ada produk.</p><?php endif; ?>
            <?php foreach ($items as $it): ?>
            <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 dark:border-gray-800">
              <div><p class="text-sm text-gray-900 dark:text-white"><?= e($it['name']) ?></p><p class="text-xs text-gray-400"><?= e(formatRupiah((float)$it['price'])) ?></p></div>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/remove-bundle-item"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="bundle_id" value="<?= (int)$bundleId ?>"><input type="hidden" name="product_id" value="<?= (int)$it['product_id'] ?>"><button class="text-xs text-red-600 hover:underline">Hapus</button></form>
            </div>
            <?php endforeach; ?>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/add-bundle-item" class="flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="bundle_id" value="<?= (int)$bundleId ?>">
            <select name="product_id" required class="flex-1 h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
              <option value="">— Pilih Produk —</option><?php foreach ($availableProducts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e(formatRupiah((float)$p['price'])) ?>)</option><?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 shrink-0">+ Tambah</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>
<script>
(function(){var n=document.getElementById('b-name'),s=document.getElementById('b-slug');if(n&&s){n.addEventListener('input',function(){if(!s.dataset.touched)s.value=this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');});s.addEventListener('input',function(){s.dataset.touched='1';});}})();
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
