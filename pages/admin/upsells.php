<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Upsell';
$topbarTitle = 'Upsell';

$offers      = [];
$allProducts = [];
try {
    $pdo = getDB();
    $offers = $pdo->query(
        "SELECT pu.*,
                pm.name AS main_name,
                px.name AS upsell_name, px.price AS upsell_normal_price, px.thumbnail AS upsell_thumbnail
         FROM product_upsells pu
         JOIN products pm ON pm.id = pu.main_product_id
         JOIN products px ON px.id = pu.upsell_product_id
         ORDER BY pm.name ASC"
    )->fetchAll();
    $allProducts = $pdo->query("SELECT id, name, price FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    error_log('upsells page error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data. Pastikan update database sudah berjalan.', 'error');
}

generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-4 sm:p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="min-w-0">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Upsell</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Penawaran satu produk dengan harga spesial yang tampil <strong>setelah pembayaran lunas</strong>. Satu penawaran per produk pemicu.</p>
        </div>
        <button onclick="openUpsellModal()"
                class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition-opacity hover:opacity-90">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Tambah Upsell
        </button>
      </div>

      <div class="bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800/40 rounded-xl p-4 mb-5 text-sm text-blue-700 dark:text-blue-400">
        Penawaran muncul di halaman sukses pembayaran &amp; detail transaksi buyer, hanya bila buyer <strong>belum memiliki</strong> produk yang ditawarkan.
        Buyer yang menerima akan membuat order baru dan langsung diarahkan ke pembayarannya.
      </div>

      <!-- Pengaturan tampilan penawaran di dashboard member (default MATI, opt-in) -->
      <?php
      $moEnabled  = getSetting('member_offers_enabled', '0') === '1';
      $moFloating = getSetting('member_offers_floating', '0') === '1';
      $moTitle    = getSetting('member_offers_title', '');
      ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 sm:p-5 mb-5">
        <div class="flex items-start gap-2.5 mb-4">
          <svg class="w-4 h-4 mt-0.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          <div class="min-w-0">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tampilkan Penawaran di Dashboard Member</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Selain di invoice, penawaran upsell di atas bisa tampil sebagai kartu di dashboard, menu sidebar "Penawaran", dan halaman <code class="text-[var(--brand-color)]">/penawaran</code>. Hanya penawaran yang relevan untuk tiap member (produk pemicu sudah dibeli, produk tawaran belum dimiliki).</p>
          </div>
        </div>
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-settings" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="group" value="member">
          <input type="hidden" name="return" value="upsells">

          <label class="flex items-start gap-3 cursor-pointer">
            <input type="hidden" name="settings[member_offers_enabled]" value="0">
            <input type="checkbox" name="settings[member_offers_enabled]" value="1" <?= $moEnabled ? 'checked' : '' ?>
                   class="mt-0.5 w-4 h-4 rounded text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
            <span class="text-sm text-gray-700 dark:text-gray-300">Aktifkan tampilan penawaran di dashboard member
              <span class="block text-xs text-gray-400 dark:text-gray-500">Mati = penawaran hanya tampil di invoice/thank-you seperti biasa.</span></span>
          </label>

          <label class="flex items-start gap-3 cursor-pointer">
            <input type="hidden" name="settings[member_offers_floating]" value="0">
            <input type="checkbox" name="settings[member_offers_floating]" value="1" <?= $moFloating ? 'checked' : '' ?>
                   class="mt-0.5 w-4 h-4 rounded text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
            <span class="text-sm text-gray-700 dark:text-gray-300">Tampilkan juga kartu melayang (floating) di pojok dashboard
              <span class="block text-xs text-gray-400 dark:text-gray-500">Opsional. Bisa ditutup member; tak muncul lagi setelah ditutup.</span></span>
          </label>

          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Judul Section Penawaran</label>
            <input type="text" name="settings[member_offers_title]" value="<?= e($moTitle) ?>" maxlength="60"
                   placeholder="Penawaran Spesial Untukmu"
                   class="w-full max-w-md px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
          </div>

          <div class="pt-1">
            <button type="submit" class="whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan Pengaturan</button>
          </div>
        </form>
      </div>

      <?php if (empty($offers)): ?>
      <div class="py-16 text-center bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">
        <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
        <p class="text-sm font-medium text-gray-400 dark:text-gray-600 mb-4">Belum ada penawaran upsell</p>
        <button onclick="openUpsellModal()" class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition-opacity hover:opacity-90">Buat Upsell Pertama</button>
      </div>
      <?php else: ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($offers as $o): ?>
          <div class="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-4">
            <div class="flex-1 min-w-[220px]">
              <p class="text-xs text-gray-400 dark:text-gray-500">Setelah membeli</p>
              <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($o['main_name']) ?></p>
              <div class="flex items-center gap-1.5 mt-1 text-xs text-gray-500 dark:text-gray-400">
                <svg class="w-3.5 h-3.5 shrink-0 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <span class="truncate">tawarkan <strong class="text-gray-700 dark:text-gray-300"><?= e($o['upsell_name']) ?></strong></span>
              </div>
            </div>
            <div class="text-right shrink-0">
              <p class="text-sm font-bold text-[var(--brand-color)]"><?= e(formatRupiah((float) $o['upsell_price'])) ?></p>
              <p class="text-xs text-gray-400 line-through"><?= e(formatRupiah((float) $o['upsell_normal_price'])) ?></p>
            </div>
            <span class="shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $o['status'] === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' ?>"><?= $o['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span>
            <div class="flex items-center gap-1 shrink-0">
              <button onclick='openUpsellModal(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)'
                      class="p-1.5 rounded-lg text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800 transition" title="Edit">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-upsell" class="inline"
                    onsubmit="return confirm('Hapus penawaran upsell untuk <?= e($o['main_name']) ?>?')">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Hapus">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- Modal Tambah/Edit -->
<div id="upsellModal" class="hidden fixed inset-0 z-50 bg-black/50 overflow-y-auto">
  <div class="flex min-h-screen items-start justify-center px-4 pt-6 pb-10">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 id="upsellModalTitle" class="font-bold text-gray-900 dark:text-white text-base">Tambah Upsell</h3>
        <button onclick="closeUpsellModal()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-upsell" class="p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="id" id="up_id" value="">

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk Pemicu <span class="text-red-500">*</span></label>
          <select name="main_product_id" id="up_main" required
                  class="w-full px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
            <option value="">-- Setelah buyer membeli produk ini --</option>
            <?php foreach ($allProducts as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Produk yang Ditawarkan <span class="text-red-500">*</span></label>
          <select name="upsell_product_id" id="up_product" required
                  class="w-full px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
            <option value="">-- Produk penawaran --</option>
            <?php foreach ($allProducts as $p): ?>
            <option value="<?= (int) $p['id'] ?>" data-price="<?= (float) $p['price'] ?>"><?= e($p['name']) ?> — <?= e(formatRupiah((float) $p['price'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Harga Spesial (<?= e(currencySymbol()) ?>) <span class="text-red-500">*</span></label>
          <input type="number" name="upsell_price" id="up_price" required min="1" step="1000"
                 class="w-full px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Harga coret otomatis memakai harga normal produk.</p>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Judul Penawaran</label>
          <input type="text" name="headline" id="up_headline" maxlength="200" placeholder="Contoh: Tunggu! Lengkapi dengan template premium ini"
                 class="w-full px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition">
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Deskripsi Singkat</label>
          <textarea name="description" id="up_desc" rows="2" maxlength="500"
                    class="w-full px-3 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] transition resize-y"></textarea>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
          <input type="checkbox" name="status" id="up_status" value="active" checked class="w-4 h-4 rounded text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
          Aktif
        </label>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeUpsellModal()"
                  class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Batal</button>
          <button type="submit"
                  class="whitespace-nowrap px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openUpsellModal(data) {
  var m = document.getElementById('upsellModal');
  document.getElementById('upsellModalTitle').textContent = data ? 'Edit Upsell' : 'Tambah Upsell';
  document.getElementById('up_id').value       = data ? data.id : '';
  document.getElementById('up_main').value     = data ? data.main_product_id : '';
  document.getElementById('up_product').value  = data ? data.upsell_product_id : '';
  document.getElementById('up_price').value    = data ? parseFloat(data.upsell_price) : '';
  document.getElementById('up_headline').value = data ? (data.headline || '') : '';
  document.getElementById('up_desc').value     = data ? (data.description || '') : '';
  document.getElementById('up_status').checked = data ? data.status === 'active' : true;
  m.classList.remove('hidden');
}
function closeUpsellModal() { document.getElementById('upsellModal').classList.add('hidden'); }
document.getElementById('upsellModal').addEventListener('click', function (e) {
  if (e.target === this) closeUpsellModal();
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
