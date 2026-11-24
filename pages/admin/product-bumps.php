<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Product Bumps';
$topbarTitle = 'Product Bumps';

$selectedProductId = (int) ($_GET['product_id'] ?? 0);

$allProducts     = [];
$bumps           = [];
$selectedProduct = null;
$bumpProducts    = []; // untuk dropdown modal (exclude main product)
$productsWithBumps = []; // ringkasan: produk utama yang sudah punya bump

try {
    $pdo = getDB();

    $stmt        = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name ASC");
    $allProducts = $stmt->fetchAll();

    // Ringkasan produk yang SUDAH punya bump (biar admin tahu sekilas). JOIN products
    // → hanya produk yang masih ada; orphan bump (produk induk terhapus) tak ditampilkan.
    $stmt = $pdo->query(
        "SELECT p.id, p.name, p.status AS product_status,
                COUNT(pb.id) AS total_bumps,
                COALESCE(SUM(pb.status = 'active'), 0) AS active_bumps
         FROM product_bumps pb
         JOIN products p ON p.id = pb.main_product_id
         GROUP BY p.id, p.name, p.status
         ORDER BY p.name ASC"
    );
    $productsWithBumps = $stmt->fetchAll();

    if ($selectedProductId > 0) {
        $stmt = $pdo->prepare("SELECT id, name FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([$selectedProductId]);
        $selectedProduct = $stmt->fetch();

        if ($selectedProduct) {
            $stmt = $pdo->prepare(
                "SELECT pb.*,
                        p.name AS bump_name,
                        p.price AS bump_normal_price,
                        p.thumbnail AS bump_thumbnail
                 FROM product_bumps pb
                 JOIN products p ON pb.bump_product_id = p.id
                 WHERE pb.main_product_id = ?
                 ORDER BY pb.sort_order ASC, pb.created_at ASC"
            );
            $stmt->execute([$selectedProductId]);
            $bumps = $stmt->fetchAll();
        }

        // Produk yang bisa jadi bump (semua active, exclude main)
        $stmt = $pdo->prepare("SELECT id, name, price FROM products WHERE status = 'active' AND id != ? ORDER BY name ASC");
        $stmt->execute([$selectedProductId]);
        $bumpProducts = $stmt->fetchAll();
    }

} catch (Exception $e) {
    error_log('Product bumps page error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data.', 'error');
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

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Product Bumps</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Penawaran tambahan yang muncul saat checkout produk tertentu</p>
        </div>
        <?php if ($selectedProduct): ?>
        <button onclick="openBumpModal()"
                class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition-opacity hover:opacity-90">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Tambah Bump
        </button>
        <?php endif; ?>
      </div>

      <!-- Pilih Produk Utama -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 mb-5">
        <form method="GET" class="flex items-end gap-3">
          <div class="flex-1 max-w-sm">
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Pilih Produk Utama</label>
            <select name="product_id" onchange="this.form.submit()"
                    class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
              <option value="">-- Pilih produk --</option>
              <?php foreach ($allProducts as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $selectedProductId === (int)$p['id'] ? 'selected' : '' ?>>
                <?= e($p['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($selectedProduct): ?>
          <span class="text-xs text-gray-400 dark:text-gray-500 pb-2">
            Menampilkan bump untuk: <strong class="text-gray-700 dark:text-gray-300"><?= e($selectedProduct['name']) ?></strong>
          </span>
          <?php endif; ?>
        </form>
      </div>

      <?php if (!$selectedProduct && $selectedProductId > 0): ?>
      <div class="bg-yellow-50 dark:bg-yellow-900/10 border border-yellow-200 dark:border-yellow-800/40 rounded-xl p-4 text-sm text-yellow-700 dark:text-yellow-400">
        Produk tidak ditemukan atau tidak aktif.
      </div>

      <?php elseif (!$selectedProduct): ?>
      <?php if (!empty($productsWithBumps)): ?>
      <!-- Ringkasan: produk yang sudah punya bump -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
          <div>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">Produk dengan Bump</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Klik produk untuk melihat atau mengedit bump-nya.</p>
          </div>
          <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?= count($productsWithBumps) ?> produk</span>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($productsWithBumps as $pw): ?>
          <a href="<?= e(APP_URL) ?>/admin/product-bumps?product_id=<?= (int)$pw['id'] ?>"
             class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
            <div class="min-w-0 flex items-center gap-2">
              <span class="text-sm font-medium text-gray-900 dark:text-white truncate"><?= e($pw['name']) ?></span>
              <?php if ($pw['product_status'] !== 'active'): ?>
              <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400 shrink-0">produk nonaktif</span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" title="Bump aktif"><?= (int)$pw['active_bumps'] ?> aktif</span>
              <span class="text-xs text-gray-400 dark:text-gray-500"><?= (int)$pw['total_bumps'] ?> total</span>
              <svg class="w-4 h-4 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="text-xs text-gray-400 dark:text-gray-600 mt-3 text-center">Atau pilih <strong>produk utama</strong> di atas untuk menambah bump pada produk lain.</p>
      <?php else: ?>
      <div class="py-16 text-center bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
        </svg>
        <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada produk yang punya bump. Pilih produk utama di atas untuk menambah.</p>
      </div>
      <?php endif; ?>

      <?php elseif (empty($bumps)): ?>
      <div class="py-16 text-center bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/>
        </svg>
        <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada bump untuk produk ini</p>
        <button onclick="openBumpModal()" class="mt-3 text-sm text-[var(--brand-color)] hover:underline font-medium">Tambah bump pertama →</button>
      </div>

      <?php else: ?>
      <!-- Tabel Bumps -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Produk Bump</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Harga Normal</th>
                <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Harga Bump</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Headline</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Urutan</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($bumps as $bump): ?>
              <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="px-5 py-3.5">
                  <div class="flex items-center gap-3">
                    <?php if ($bump['bump_thumbnail']): ?>
                    <img src="<?= e($bump['bump_thumbnail']) ?>" alt="" class="w-8 h-8 rounded-lg object-cover shrink-0">
                    <?php else: ?>
                    <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
                      <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/></svg>
                    </div>
                    <?php endif; ?>
                    <span class="font-medium text-gray-900 dark:text-white"><?= e($bump['bump_name']) ?></span>
                  </div>
                </td>
                <td class="px-4 py-3.5 text-right text-gray-500 dark:text-gray-400 text-xs">
                  <?= e(formatRupiah((float)$bump['bump_normal_price'])) ?>
                </td>
                <td class="px-4 py-3.5 text-right">
                  <?php if ($bump['bump_price'] !== null): ?>
                  <span class="font-semibold text-[var(--brand-color)]"><?= e(formatRupiah((float)$bump['bump_price'])) ?></span>
                  <?php else: ?>
                  <span class="text-xs text-gray-400 italic">Harga normal</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3.5 text-gray-600 dark:text-gray-400 max-w-[180px] truncate">
                  <?= $bump['headline'] ? e($bump['headline']) : '<span class="text-gray-300 dark:text-gray-700 text-xs italic">—</span>' ?>
                </td>
                <td class="px-4 py-3.5 text-center text-gray-500 dark:text-gray-400"><?= (int)$bump['sort_order'] ?></td>
                <td class="px-4 py-3.5 text-center">
                  <?php if ($bump['status'] === 'active'): ?>
                  <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Aktif</span>
                  <?php else: ?>
                  <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Nonaktif</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button onclick='openBumpModal(<?= htmlspecialchars(json_encode($bump), ENT_QUOTES) ?>)'
                            title="Edit"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                    </button>
                    <form id="del-bump-<?= (int)$bump['id'] ?>" method="POST"
                          action="<?= e(APP_URL) ?>/actions/admin/delete-product-bump" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="bump_id" value="<?= (int)$bump['id'] ?>">
                      <input type="hidden" name="product_id" value="<?= $selectedProductId ?>">
                      <button type="button" title="Hapus"
                              onclick="confirmAndSubmit('del-bump-<?= (int)$bump['id'] ?>',{title:'Hapus Bump?',message:'Bump ini akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})"
                              class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <polyline points="3 6 5 6 21 6"/>
                          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                          <path d="M10 11v6"/><path d="M14 11v6"/>
                          <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                        </svg>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<?php if ($selectedProduct): ?>
<!-- ═══ MODAL BUMP ═══ -->
<div id="bumpModal" class="fixed inset-0 z-50 hidden">
  <div class="flex min-h-screen items-start justify-center px-4 pt-6 pb-10">
    <div onclick="closeBumpModal()" class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg border border-gray-200 dark:border-gray-700 my-4 flex flex-col" style="max-height:90vh">

      <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 shrink-0">
        <h3 id="bumpModalTitle" class="text-sm font-bold text-gray-900 dark:text-white">Tambah Bump</h3>
        <button onclick="closeBumpModal()" class="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <form id="bumpForm" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-product-bump" class="flex flex-col flex-1 min-h-0">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="bump_id" id="bumpId" value="">
        <input type="hidden" name="main_product_id" value="<?= $selectedProductId ?>">
        <input type="hidden" name="redirect_product_id" value="<?= $selectedProductId ?>">

        <div class="overflow-y-auto flex-1 p-5 space-y-4">

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Produk Bump <span class="text-red-500">*</span></label>
            <select name="bump_product_id" id="bumpProductId" required
                    class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
              <option value="">Pilih produk...</option>
              <?php foreach ($bumpProducts as $bp): ?>
              <option value="<?= (int)$bp['id'] ?>" data-price="<?= (float)$bp['price'] ?>">
                <?= e($bp['name']) ?> — <?= e(formatRupiah((float)$bp['price'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
              Harga Bump (<?= e(currencySymbol()) ?>) <span class="font-normal text-gray-400 dark:text-gray-600">— kosongkan untuk pakai harga normal</span>
            </label>
            <input type="number" name="bump_price" id="bumpPrice" min="0" step="1000" placeholder="Kosong = harga normal produk"
                   class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Headline</label>
            <input type="text" name="headline" id="bumpHeadline" maxlength="200"
                   placeholder="Contoh: Tambah hanya Rp 49.000!"
                   class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Deskripsi Singkat</label>
            <textarea name="description" id="bumpDescription" rows="2" maxlength="500"
                      class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition resize-none"
                      placeholder="Deskripsi singkat penawaran bump..."></textarea>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
              <select name="status" id="bumpStatus"
                      class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
                <option value="active">Aktif</option>
                <option value="inactive">Nonaktif</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Urutan Tampil</label>
              <input type="number" name="sort_order" id="bumpSortOrder" min="0" value="0"
                     class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] outline-none transition">
            </div>
          </div>

        </div>

        <div class="flex items-center justify-end gap-2.5 px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 shrink-0 bg-white dark:bg-gray-900 rounded-b-2xl">
          <button type="button" onclick="closeBumpModal()"
                  class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
            Batal
          </button>
          <button type="submit"
                  class="px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
            Simpan
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openBumpModal(bump) {
  document.getElementById('bumpModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  if (bump) {
    document.getElementById('bumpModalTitle').textContent   = 'Edit Bump';
    document.getElementById('bumpId').value                 = bump.id          || '';
    document.getElementById('bumpProductId').value          = bump.bump_product_id || '';
    document.getElementById('bumpPrice').value              = bump.bump_price  !== null ? bump.bump_price : '';
    document.getElementById('bumpHeadline').value           = bump.headline    || '';
    document.getElementById('bumpDescription').value        = bump.description || '';
    document.getElementById('bumpStatus').value             = bump.status      || 'active';
    document.getElementById('bumpSortOrder').value          = bump.sort_order  || 0;
  } else {
    document.getElementById('bumpModalTitle').textContent = 'Tambah Bump';
    document.getElementById('bumpId').value = '';
    document.getElementById('bumpForm').reset();
    document.getElementById('bumpStatus').value     = 'active';
    document.getElementById('bumpSortOrder').value  = 0;
  }
}

function closeBumpModal() {
  document.getElementById('bumpModal').classList.add('hidden');
  document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeBumpModal(); });
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
