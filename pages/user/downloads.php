<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

// Menu/halaman ini bisa dimatikan admin (Pengaturan → Menu Dashboard Member).
if (getSetting('member_menu_downloads', '1') === '0') {
    redirect('/dashboard');
}

$pageTitle   = 'File Downloads';
$topbarTitle = 'File Downloads';

$grouped = [];
try {
    $stmt = getDB()->prepare(
        "SELECT pd.*, p.name AS product_name, p.thumbnail AS product_thumbnail
         FROM product_downloads pd
         JOIN products p ON p.id = pd.product_id
         JOIN member_access ma ON ma.product_id = p.id AND ma.user_id = ?
         WHERE ma.status = 'active'
           AND (ma.expires_at IS NULL OR ma.expires_at > NOW())
           AND pd.status = 'active'
         ORDER BY p.name ASC, pd.sort_order ASC"
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $item) {
        $pid = $item['product_id'];
        if (!isset($grouped[$pid])) {
            $grouped[$pid] = ['product' => ['name' => $item['product_name'], 'thumbnail' => $item['product_thumbnail']], 'files' => []];
        }
        $grouped[$pid]['files'][] = $item;
    }
} catch (Exception $e) {
    error_log('user downloads error: ' . $e->getMessage());
}

generateCSRF();
$csrf = $_SESSION['csrf_token'];
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>
      <div class="mb-5"><h2 class="text-xl font-bold text-gray-900 dark:text-white">File Downloads</h2><p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">File dari produk yang kamu miliki</p></div>

      <?php if (empty($grouped)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-10 text-center">
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Kamu belum memiliki produk dengan file download.</p>
        <a href="<?= e(APP_URL) ?>/products" class="inline-block px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90">Lihat Produk</a>
      </div>
      <?php else: ?>
      <?php foreach ($grouped as $g): ?>
      <div class="mb-6">
        <div class="flex items-center gap-3 mb-3">
          <?php if (!empty($g['product']['thumbnail'])): ?><img src="<?= e($g['product']['thumbnail']) ?>" class="w-9 h-9 rounded-lg object-cover"><?php endif; ?>
          <h3 class="font-semibold text-gray-900 dark:text-white"><?= e($g['product']['name']) ?></h3>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <?php foreach ($g['files'] as $dl): ?>
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <p class="font-medium text-sm text-gray-900 dark:text-white mb-0.5"><?= e($dl['title']) ?><?php if ($dl['version']): ?> <span class="text-xs px-1.5 py-0.5 rounded bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-brand"><?= e($dl['version']) ?></span><?php endif; ?></p>
            <?php if ($dl['description']): ?><p class="text-xs text-gray-400 mb-2"><?= e($dl['description']) ?></p><?php endif; ?>
            <p class="text-xs text-gray-400 mb-3"><?= e(formatFileSize((int) $dl['file_size'])) ?></p>
            <form onsubmit="handleDownload(this);return false;">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="download_id" value="<?= (int) $dl['id'] ?>">
              <button type="submit" class="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 bg-brand text-white rounded-lg hover:opacity-90 transition disabled:opacity-50">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                Download
              </button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </main>
  </div>
</div>
<script>
function handleDownload(form){
  var btn = form.querySelector('button'); btn.disabled = true;
  fetch('<?= e(APP_URL) ?>/actions/user/request-download', { method:'POST', body:new FormData(form), headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(function(r){return r.json();})
    .then(function(d){ if(d.success){ window.location.href='<?= e(APP_URL) ?>/download/'+d.token; } else { showToast(d.message||'Gagal membuat link download','error'); btn.disabled=false; } })
    .catch(function(){ showToast('Terjadi kesalahan','error'); btn.disabled=false; });
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
