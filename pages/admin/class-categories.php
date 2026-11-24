<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('content')) { redirect('/admin/no-access'); }

$pageTitle   = 'Kategori Modul';
$topbarTitle = 'Kategori Modul';

$categories = [];
try {
    $pdo = getDB();
    $categories = $pdo->query(
        "SELECT cc.*,
                (SELECT COUNT(*) FROM classes c WHERE c.category_id = cc.id) AS class_count
         FROM class_categories cc
         ORDER BY cc.sort_order ASC, cc.name ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('Class categories list error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data kategori.', 'error');
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

      <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Kategori Modul</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Kelompokkan modul berdasarkan topik atau level</p>
        </div>
        <button onclick="openModal()"
                class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition shadow-sm">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Tambah Kategori
        </button>
      </div>

      <?php if (empty($categories)): ?>

      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm py-16 text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center mb-4">
          <svg class="w-7 h-7 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
          </svg>
        </div>
        <h3 class="font-bold text-gray-900 dark:text-white mb-1">Belum ada kategori</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5 max-w-xs mx-auto">
          Buat kategori untuk mengelompokkan modul kelas. Misalnya: Pemula, Lanjutan, Bisnis, dll.
        </p>
        <button onclick="openModal()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
          Tambah Kategori Pertama
        </button>
      </div>

      <?php else: ?>

      <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <div class="px-4 sm:px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
          <p class="text-sm font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap"><?= count($categories) ?> kategori terdaftar</p>
          <p class="hidden sm:block text-xs text-gray-400 dark:text-gray-600">Assign kategori ke modul di halaman Konten</p>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($categories as $cat): ?>
          <div class="flex items-center gap-3 sm:gap-4 px-4 sm:px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
            <div class="w-10 h-10 rounded-xl bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] flex items-center justify-center shrink-0">
              <svg class="w-5 h-5 text-[var(--brand-color)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($cat['name']) ?></p>
              <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                <span class="text-xs font-mono text-gray-400 dark:text-gray-600"><?= e($cat['slug']) ?></span>
                <?php if ($cat['description']): ?>
                <span class="text-xs text-gray-400 dark:text-gray-600 truncate"><?= e($cat['description']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <div class="text-right">
                <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= (int)$cat['class_count'] ?></p>
                <p class="text-xs text-gray-400 dark:text-gray-600">modul</p>
              </div>
              <div class="flex items-center gap-0.5">
                <button onclick='openModal(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)'
                        class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                  <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                  </svg>
                </button>
                <form id="del-cat-<?= (int)$cat['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-class-category" class="inline">
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                  <button type="button"
                          onclick="confirmAndSubmit('del-cat-<?= (int)$cat['id'] ?>',{title:'Hapus Kategori?',message:'Modul yang menggunakan kategori ini akan kehilangan kategorinya (tidak dihapus).',confirmText:'Ya, Hapus',variant:'danger'})"
                          class="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="3 6 5 6 21 6"/>
                      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                      <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                  </button>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php endif; ?>

    </main>
  </div>
</div>

<!-- Modal Add/Edit Kategori -->
<div id="catModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
  <div class="flex min-h-screen items-start justify-center px-4 pt-6 pb-10">
    <div onclick="closeModal()" class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md border border-gray-200 dark:border-gray-700 my-4">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800">
        <h3 id="modalTitle" class="font-bold text-gray-900 dark:text-white">Tambah Kategori</h3>
        <button onclick="closeModal()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>
      <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-class-category" class="p-5 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="catId" value="">

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Nama Kategori <span class="text-red-500">*</span>
          </label>
          <input type="text" name="name" id="catName" required maxlength="100"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                 placeholder="Contoh: Pemula, Lanjutan, Bisnis...">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Deskripsi</label>
          <input type="text" name="description" id="catDesc" maxlength="255"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                 placeholder="Deskripsi singkat kategori...">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Urutan Tampil</label>
          <input type="number" name="sort_order" id="catSortOrder" min="0" value="0"
                 class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100 dark:border-gray-800">
          <button type="button" onclick="closeModal()"
                  class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
            Batal
          </button>
          <button type="submit" data-loading data-loading-text="Menyimpan..."
                  class="px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
            Simpan
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(cat) {
  document.getElementById('catModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  if (cat) {
    document.getElementById('modalTitle').textContent   = 'Edit Kategori';
    document.getElementById('catId').value              = cat.id;
    document.getElementById('catName').value            = cat.name;
    document.getElementById('catDesc').value            = cat.description || '';
    document.getElementById('catSortOrder').value       = cat.sort_order || 0;
  } else {
    document.getElementById('modalTitle').textContent   = 'Tambah Kategori';
    document.getElementById('catId').value              = '';
    document.getElementById('catName').value            = '';
    document.getElementById('catDesc').value            = '';
    document.getElementById('catSortOrder').value       = <?= count($categories) ?>;
  }
  document.getElementById('catName').focus();
}

function closeModal() {
  document.getElementById('catModal').classList.add('hidden');
  document.body.style.overflow = '';
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
