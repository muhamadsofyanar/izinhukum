<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Display Affiliate';
$topbarTitle = 'Display Produk Affiliate';

$displays = [];
try {
    $displays = getDB()->query(
        "SELECT * FROM affiliate_displays ORDER BY sort_order ASC, id DESC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('affiliate-displays load error: ' . $e->getMessage());
}

// Map untuk modal edit (di-escape aman untuk konteks <script>).
$displayMap = [];
foreach ($displays as $d) {
    $displayMap[(int) $d['id']] = [
        'id'            => (int) $d['id'],
        'name'          => (string) $d['name'],
        'image'         => (string) ($d['image'] ?? ''),
        'salespage_url' => (string) $d['salespage_url'],
        'checkout_url'  => (string) ($d['checkout_url'] ?? ''),
        'price_text'    => (string) ($d['price_text'] ?? ''),
        'badge'         => (string) ($d['badge'] ?? ''),
        'short_desc'    => (string) ($d['short_desc'] ?? ''),
        'sort_order'    => (int) $d['sort_order'],
        'status'        => (string) $d['status'],
    ];
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

      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Display Produk Affiliate</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?= count($displays) ?> display &middot; tampil di homepage sebagai kartu rekomendasi</p>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= e(APP_URL) ?>/admin/products"
             class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
            Produk
          </a>
          <button onclick="openDisplayModal()"
                  class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand transition-opacity hover:opacity-90">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Display
          </button>
        </div>
      </div>

      <p class="text-xs text-gray-400 dark:text-gray-500 mb-5">
        Display affiliate hanya menampilkan kartu + tautan keluar ke situs produk orang lain — tidak ada checkout/akses di sistem ini.
        Atur judul section &amp; label di <a href="<?= e(APP_URL) ?>/admin/branding" class="underline text-[var(--brand-color)]">Branding</a>.
        Baru pertama kali? <a href="<?= e(APP_URL) ?>/admin/docs#display-affiliate" class="underline text-[var(--brand-color)]">Lihat panduan Display Affiliate</a>.
      </p>

      <!-- Table -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <?php if (empty($displays)): ?>
        <div class="py-16 text-center">
          <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada display affiliate</p>
          <button onclick="openDisplayModal()" class="mt-3 text-sm text-[var(--brand-color)] hover:underline font-medium">Tambah display pertama →</button>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Display</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Harga</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Urutan</th>
                <th class="text-center px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($displays as $d): ?>
              <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-800/30 transition-colors">
                <td class="px-5 py-3">
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="w-11 h-11 rounded-lg overflow-hidden shrink-0 bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                      <?php if (!empty($d['image'])): ?>
                      <img src="<?= e($d['image']) ?>" alt="" class="w-full h-full object-cover">
                      <?php else: ?>
                      <svg class="w-5 h-5 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                      <?php endif; ?>
                    </div>
                    <div class="min-w-0">
                      <div class="flex items-center gap-1.5">
                        <p class="font-medium text-gray-900 dark:text-white truncate"><?= e($d['name']) ?></p>
                        <?php if (!empty($d['badge'])): ?>
                        <span class="inline-flex px-1.5 py-0.5 rounded text-xs font-semibold bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 shrink-0"><?= e($d['badge']) ?></span>
                        <?php endif; ?>
                      </div>
                      <a href="<?= e($d['salespage_url']) ?>" target="_blank" rel="noopener nofollow" class="text-xs text-gray-400 dark:text-gray-500 hover:text-[var(--brand-color)] hover:underline truncate block" style="max-width:280px"><?= e($d['salespage_url']) ?></a>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?= $d['price_text'] !== null && $d['price_text'] !== '' ? e($d['price_text']) : '<span class="text-gray-300 dark:text-gray-600">—</span>' ?></td>
                <td class="px-4 py-3 text-center text-gray-500 dark:text-gray-400"><?= (int) $d['sort_order'] ?></td>
                <td class="px-4 py-3 text-center">
                  <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/toggle-affiliate-display" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button type="submit" title="Klik untuk ubah status"
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= $d['status'] === 'active' ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' ?>">
                      <span class="w-1.5 h-1.5 rounded-full <?= $d['status'] === 'active' ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
                      <?= $d['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?>
                    </button>
                  </form>
                </td>
                <td class="px-5 py-3">
                  <div class="flex items-center justify-end gap-1">
                    <button onclick="openDisplayModal(DISPLAY_MAP[<?= (int) $d['id'] ?>])" title="Edit"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button onclick="confirmAndSubmit('del-disp-<?= (int) $d['id'] ?>', {title:'Hapus Display?', message:'Display &quot;<?= e(addslashes($d['name'])) ?>&quot; akan dihapus dari homepage. Tindakan ini tidak bisa dibatalkan.', confirmText:'Ya, Hapus', variant:'danger'})"
                            title="Hapus" class="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                    <form id="del-disp-<?= (int) $d['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/delete-affiliate-display" class="hidden">
                      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                      <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<!-- ═══ Modal Tambah/Edit Display ═══ -->
<div id="displayModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background:rgba(0,0,0,.5)">
  <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-2xl w-full max-w-lg overflow-y-auto" style="max-height:92vh">
    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
      <h3 id="displayModalTitle" class="text-base font-bold text-gray-900 dark:text-white">Tambah Display</h3>
      <button type="button" onclick="closeDisplayModal()" class="p-1 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-affiliate-display" class="p-5 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="id" id="disp_id" value="0">

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Nama Produk <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="disp_name" maxlength="200" required
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Gambar</label>
        <div class="flex items-start gap-3">
          <div id="disp_img_preview" class="w-16 h-16 rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-700 flex items-center justify-center overflow-hidden shrink-0 bg-gray-50 dark:bg-gray-800">
            <svg class="w-6 h-6 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          </div>
          <div class="flex-1 min-w-0 space-y-2">
            <button type="button" onclick="pickDisplayImage()"
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm text-gray-600 dark:text-gray-400 hover:border-[var(--brand-color)] transition">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              Pilih dari Media
            </button>
            <input type="text" name="image" id="disp_image" placeholder="atau tempel URL gambar…" oninput="updateDispImgPreview()"
                   class="w-full px-3 py-2 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          </div>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Link Sales Page (Affiliate) <span class="text-red-500">*</span></label>
        <input type="url" name="salespage_url" id="disp_salespage" placeholder="https://..." required
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Tombol utama kartu mengarah ke sini.</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Link Checkout (Affiliate) <span class="text-gray-400 font-normal">— opsional</span></label>
        <input type="url" name="checkout_url" id="disp_checkout" placeholder="https://..."
               class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Bila diisi, muncul tombol kedua "Beli" langsung ke checkout.</p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Teks Harga <span class="text-gray-400 font-normal">— opsional</span></label>
          <input type="text" name="price_text" id="disp_price" maxlength="60" placeholder="mis. Rp199.000"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Badge <span class="text-gray-400 font-normal">— opsional</span></label>
          <input type="text" name="badge" id="disp_badge" maxlength="40" placeholder="mis. Diskon 50%"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Deskripsi Singkat <span class="text-gray-400 font-normal">— opsional</span></label>
        <textarea name="short_desc" id="disp_desc" rows="2" maxlength="300"
                  class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"></textarea>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Urutan</label>
          <input type="number" name="sort_order" id="disp_sort" value="0"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
          <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Kecil = tampil lebih dulu.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Status</label>
          <select name="status" id="disp_status"
                  class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
            <option value="active">Aktif</option>
            <option value="inactive">Nonaktif</option>
          </select>
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" onclick="closeDisplayModal()" class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Batal</button>
        <button type="submit" data-loading data-loading-text="Menyimpan..." class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
var DISPLAY_MAP = <?= json_encode($displayMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function updateDispImgPreview() {
  var url = document.getElementById('disp_image').value.trim();
  var box = document.getElementById('disp_img_preview');
  box.innerHTML = url
    ? '<img src="' + url.replace(/"/g, '&quot;') + '" alt="" class="w-full h-full object-cover">'
    : '<svg class="w-6 h-6 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
}

function pickDisplayImage() {
  if (typeof window.openMediaPicker !== 'function') { if (window.showToast) showToast('Media Library belum siap', 'error'); return; }
  window.openMediaPicker(function (insertText, img) {
    var url = (img && img.url) ? img.url : insertText;
    document.getElementById('disp_image').value = url;
    updateDispImgPreview();
  });
}

function openDisplayModal(data) {
  document.getElementById('displayModalTitle').textContent = data ? 'Edit Display' : 'Tambah Display';
  document.getElementById('disp_id').value        = data ? data.id : 0;
  document.getElementById('disp_name').value      = data ? data.name : '';
  document.getElementById('disp_image').value     = data ? (data.image || '') : '';
  document.getElementById('disp_salespage').value = data ? data.salespage_url : '';
  document.getElementById('disp_checkout').value  = data ? (data.checkout_url || '') : '';
  document.getElementById('disp_price').value     = data ? (data.price_text || '') : '';
  document.getElementById('disp_badge').value     = data ? (data.badge || '') : '';
  document.getElementById('disp_desc').value      = data ? (data.short_desc || '') : '';
  document.getElementById('disp_sort').value      = data ? data.sort_order : 0;
  document.getElementById('disp_status').value    = data ? data.status : 'active';
  updateDispImgPreview();
  var m = document.getElementById('displayModal');
  m.classList.remove('hidden'); m.classList.add('flex');
}
function closeDisplayModal() {
  var m = document.getElementById('displayModal');
  m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('displayModal').addEventListener('click', function (e) { if (e.target === this) closeDisplayModal(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDisplayModal(); });
</script>

<?php include __DIR__ . '/../../partials/media-picker.php'; ?>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
