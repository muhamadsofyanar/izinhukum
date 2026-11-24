<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('content')) { redirect('/admin/no-access'); }

$pageTitle   = 'Modul & Materi';
$topbarTitle = 'Modul & Materi';

// ─── Filter owner: produk ATAU lead magnet ────────────────────
$selectedProductId    = (int) ($_GET['product_id'] ?? 0);
$selectedLeadMagnetId = (int) ($_GET['lead_magnet_id'] ?? 0);
$selectedClassId      = (int) ($_GET['class_id']   ?? 0);
// Owner eksklusif (produk diprioritaskan bila keduanya terisi).
$ownerType = $selectedProductId > 0 ? 'product' : ($selectedLeadMagnetId > 0 ? 'lead_magnet' : '');

$products  = [];
$leadMagnets = [];
$classes   = [];
$materials = [];
$selectedProduct    = null;
$selectedLeadMagnet = null;
$selectedClass      = null;
$overviewGroups     = []; // ringkasan semua modul+materi per owner (saat belum pilih owner)

try {
    $pdo = getDB();
    ensureMaterialHtmlContentType($pdo);
    ensureClassLeadMagnetOwner($pdo);

    // Semua produk untuk dropdown
    $products = $pdo->query(
        "SELECT id, name, type FROM products ORDER BY name ASC"
    )->fetchAll();

    // Lead magnet untuk dropdown owner
    $leadMagnets = $pdo->query(
        "SELECT id, title FROM lead_magnets ORDER BY created_at DESC"
    )->fetchAll();

    // Kategori untuk dropdown modul
    $allCategories = $pdo->query(
        "SELECT id, name FROM class_categories ORDER BY sort_order ASC, name ASC"
    )->fetchAll();

    // Query modul (class) untuk owner aktif — sama untuk produk/lead magnet, hanya kolom owner berbeda.
    $classQuery = fn(string $ownerCol): string =>
        "SELECT c.*,
                (SELECT COUNT(*) FROM materials m WHERE m.class_id = c.id) AS material_count,
                cat.name AS category_name
         FROM classes c
         LEFT JOIN class_categories cat ON cat.id = c.category_id
         WHERE c.{$ownerCol} = ?
         ORDER BY c.sort_order ASC, c.id ASC";

    if ($ownerType === 'product') {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$selectedProductId]);
        $selectedProduct = $stmt->fetch() ?: null;
        if ($selectedProduct) {
            $stmt = $pdo->prepare($classQuery('product_id'));
            $stmt->execute([$selectedProductId]);
            $classes = $stmt->fetchAll();

            // Modul berbagi (shared): muat daftar produk tambahan per modul.
            if ($classes) {
                $cids = array_column($classes, 'id');
                $ph   = implode(',', array_fill(0, count($cids), '?'));
                $spStmt = $pdo->prepare("SELECT class_id, product_id FROM class_products WHERE class_id IN ($ph)");
                $spStmt->execute($cids);
                $sharedMap = [];
                foreach ($spStmt->fetchAll() as $row) {
                    $sharedMap[(int) $row['class_id']][] = (int) $row['product_id'];
                }
                foreach ($classes as &$cRef) {
                    $cRef['shared_product_ids'] = $sharedMap[(int) $cRef['id']] ?? [];
                }
                unset($cRef);
            }
        }
    } elseif ($ownerType === 'lead_magnet') {
        $stmt = $pdo->prepare('SELECT * FROM lead_magnets WHERE id = ? LIMIT 1');
        $stmt->execute([$selectedLeadMagnetId]);
        $selectedLeadMagnet = $stmt->fetch() ?: null;
        if ($selectedLeadMagnet) {
            $stmt = $pdo->prepare($classQuery('lead_magnet_id'));
            $stmt->execute([$selectedLeadMagnetId]);
            $classes = $stmt->fetchAll();
        }
    }

    // File Download Manager milik produk ini — pilihan tautan utk materi tipe file.
    $productDownloads = [];
    if ($ownerType === 'product' && $selectedProduct) {
        $stmt = $pdo->prepare(
            "SELECT id, title, filename_orig, file_size FROM product_downloads
             WHERE product_id = ? AND status = 'active'
             ORDER BY sort_order ASC, title ASC"
        );
        $stmt->execute([$selectedProductId]);
        $productDownloads = $stmt->fetchAll();
    }

    if ($selectedClassId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = ? LIMIT 1');
        $stmt->execute([$selectedClassId]);
        $selectedClass = $stmt->fetch() ?: null;

        if ($selectedClass) {
            $stmt = $pdo->prepare(
                'SELECT * FROM materials WHERE class_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute([$selectedClassId]);
            $materials = $stmt->fetchAll();

            // Lampiran per materi (utk prefill checkbox di form edit).
            // Fail-safe bila tabel material_files belum ada.
            if ($materials) {
                try {
                    $mids = array_column($materials, 'id');
                    $ph   = implode(',', array_fill(0, count($mids), '?'));
                    $af   = $pdo->prepare("SELECT material_id, download_id FROM material_files WHERE material_id IN ($ph) ORDER BY sort_order ASC");
                    $af->execute($mids);
                    $attachMap = [];
                    foreach ($af->fetchAll() as $row) {
                        $attachMap[(int) $row['material_id']][] = (int) $row['download_id'];
                    }
                    foreach ($materials as &$mRef) {
                        $mRef['attach_ids'] = $attachMap[(int) $mRef['id']] ?? [];
                    }
                    unset($mRef);
                } catch (Exception $e) { /* lampiran opsional */ }
            }
        }
    }

    // ─── Overview: semua modul & materi yang sudah dibuat, dikelompokkan per owner.
    // Ditampilkan saat belum ada owner dipilih, agar halaman tidak kosong & admin
    // bisa langsung melihat/mengelola konten tanpa harus memilih produk dulu.
    if ($ownerType === '') {
        $ovRows = $pdo->query(
            "SELECT c.id, c.title, c.status, c.sort_order, c.product_id, c.lead_magnet_id,
                    p.name AS product_name, lm.title AS lm_title,
                    (SELECT COUNT(*) FROM materials m WHERE m.class_id = c.id) AS material_count
             FROM classes c
             LEFT JOIN products p      ON p.id  = c.product_id
             LEFT JOIN lead_magnets lm ON lm.id = c.lead_magnet_id
             ORDER BY (c.product_id IS NULL) ASC, COALESCE(p.name, lm.title) ASC, c.sort_order ASC, c.id ASC"
        )->fetchAll();
        foreach ($ovRows as $row) {
            $isProd = !empty($row['product_id']);
            // Lewati modul yatim (tanpa produk & tanpa lead magnet).
            if (!$isProd && empty($row['lead_magnet_id'])) continue;
            $key = $isProd ? 'p' . (int) $row['product_id'] : 'lm' . (int) $row['lead_magnet_id'];
            if (!isset($overviewGroups[$key])) {
                $overviewGroups[$key] = [
                    'type'           => $isProd ? 'product' : 'lead_magnet',
                    'owner_id'       => $isProd ? (int) $row['product_id'] : (int) $row['lead_magnet_id'],
                    'name'           => $isProd ? ($row['product_name'] ?: '(produk terhapus)')
                                                : ($row['lm_title'] ?: '(lead magnet terhapus)'),
                    'modules'        => [],
                    'material_total' => 0,
                ];
            }
            $overviewGroups[$key]['modules'][] = [
                'id'             => (int) $row['id'],
                'title'          => (string) $row['title'],
                'status'         => (string) ($row['status'] ?? 'active'),
                'material_count' => (int) $row['material_count'],
            ];
            $overviewGroups[$key]['material_total'] += (int) $row['material_count'];
        }
    }

} catch (Exception $e) {
    error_log('Admin content error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data konten.', 'error');
}

$statusBadge = fn(string $s): string => match ($s) {
    'active'   => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    'inactive' => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
    default    => 'inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500',
};

$typeIcon = fn(string $t): string => match ($t) {
    'video' => 'M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z',
    'file'  => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    'link'  => 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1',
    default => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
};

// ─── Variabel owner terpadu (dipakai di seluruh UI) ───────────
$owner      = $ownerType === 'product' ? $selectedProduct : ($ownerType === 'lead_magnet' ? $selectedLeadMagnet : null);
$ownerName  = $ownerType === 'product' ? ($selectedProduct['name'] ?? '') : ($selectedLeadMagnet['title'] ?? '');
$ownerCtx   = $ownerType === 'product' ? ['product_id' => $selectedProductId]
            : ($ownerType === 'lead_magnet' ? ['lead_magnet_id' => $selectedLeadMagnetId] : []);
// Base URL preview (tampilan pembeli) untuk owner aktif.
$previewBase = $ownerType === 'product'     ? '/admin/content/preview/' . (int) $selectedProductId
             : ($ownerType === 'lead_magnet' ? '/admin/content/preview/lm/' . (int) $selectedLeadMagnetId : '');
// Hidden input owner untuk semua form (kirim owner yang aktif; satu terisi, satu 0).
$ownerHidden = '<input type="hidden" name="product_id" value="' . (int) $selectedProductId . '">'
             . '<input type="hidden" name="lead_magnet_id" value="' . (int) $selectedLeadMagnetId . '">';

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

      <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Modul &amp; Materi</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Alur konten: <strong class="text-gray-700 dark:text-gray-300">Produk / Lead Magnet</strong> → <strong class="text-gray-700 dark:text-gray-300">Modul</strong> → <strong class="text-gray-700 dark:text-gray-300">Materi</strong> (video, teks, HTML, atau file). Pilih produk atau lead magnet, buat modul, lalu isi materinya.
          </p>
        </div>
        <a href="<?= e(APP_URL) ?>/admin/docs#materi-kelas" target="_blank" rel="noopener"
           class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          Panduan
        </a>
      </div>

      <!-- Owner Selector: Produk atau Lead Magnet -->
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-4 mb-5">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Pilih Produk / Lead Magnet</label>
        <select onchange="if(this.value) location.href='<?= e(APP_URL) ?>/admin/content?' + this.value;"
                class="w-full px-3.5 py-2.5 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
          <option value="">-- Pilih produk / lead magnet --</option>
          <?php if (!empty($products)): ?>
          <optgroup label="Produk">
            <?php foreach ($products as $prod): ?>
            <option value="product_id=<?= (int)$prod['id'] ?>" <?= ($ownerType === 'product' && $selectedProductId === (int)$prod['id']) ? 'selected' : '' ?>>
              <?= e($prod['name']) ?> (<?= e($prod['type']) ?>)
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (!empty($leadMagnets)): ?>
          <optgroup label="Lead Magnet">
            <?php foreach ($leadMagnets as $lm): ?>
            <option value="lead_magnet_id=<?= (int)$lm['id'] ?>" <?= ($ownerType === 'lead_magnet' && $selectedLeadMagnetId === (int)$lm['id']) ? 'selected' : '' ?>>
              <?= e($lm['title']) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <?php if (!$owner): ?>
      <!-- Belum pilih owner: tampilkan ringkasan SEMUA modul & materi yang sudah dibuat -->
      <?php if (empty($overviewGroups)): ?>
      <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm py-14 text-center">
        <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
        </svg>
        <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada modul. Pilih produk atau lead magnet di atas untuk mulai membuat konten.</p>
      </div>
      <?php else: ?>
      <?php
        $ovModuleTotal = 0; $ovMaterialTotal = 0;
        foreach ($overviewGroups as $g) { $ovModuleTotal += count($g['modules']); $ovMaterialTotal += $g['material_total']; }
      ?>
      <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
        <div>
          <h2 class="text-sm font-bold text-gray-900 dark:text-white">Semua Konten</h2>
          <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
            <?= number_format(count($overviewGroups)) ?> owner · <?= number_format($ovModuleTotal) ?> modul · <?= number_format($ovMaterialTotal) ?> materi.
            Klik <strong class="text-gray-600 dark:text-gray-300">Kelola</strong> untuk mengelola modul &amp; materinya.
          </p>
        </div>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <?php foreach ($overviewGroups as $g): ?>
        <?php
          $ovLink = APP_URL . '/admin/content?'
                  . ($g['type'] === 'product' ? 'product_id=' : 'lead_magnet_id=') . (int) $g['owner_id'];
        ?>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 dark:border-gray-800">
            <div class="min-w-0">
              <div class="flex items-center gap-1.5">
                <?php if ($g['type'] === 'lead_magnet'): ?>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Lead Magnet</span>
                <?php else: ?>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">Produk</span>
                <?php endif; ?>
              </div>
              <p class="font-semibold text-gray-900 dark:text-white truncate mt-1"><?= e($g['name']) ?></p>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= number_format(count($g['modules'])) ?> modul · <?= number_format($g['material_total']) ?> materi</p>
            </div>
            <a href="<?= e($ovLink) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 shrink-0">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Kelola
            </a>
          </div>
          <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($g['modules'] as $mod): ?>
            <?php $modLink = $ovLink . '&class_id=' . (int) $mod['id']; ?>
            <li>
              <a href="<?= e($modLink) ?>" class="flex items-center justify-between gap-2 px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <span class="flex items-center gap-2 min-w-0">
                  <span class="font-medium text-sm text-gray-800 dark:text-gray-200 truncate"><?= e($mod['title']) ?></span>
                  <?php if ($mod['status'] !== 'active'): ?>
                  <span class="<?= $statusBadge($mod['status']) ?> shrink-0">Nonaktif</span>
                  <?php endif; ?>
                </span>
                <span class="text-xs text-gray-400 dark:text-gray-600 shrink-0"><?= number_format($mod['material_count']) ?> materi</span>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php else: ?>

      <div class="flex flex-wrap items-center justify-between gap-3 mb-4 flex-wrap">
      <!-- Breadcrumb konteks: perjelas posisi Produk › Modul › Materi -->
      <nav class="flex items-center gap-1.5 text-xs flex-wrap" aria-label="Breadcrumb">
        <?php if ($ownerType === 'lead_magnet'): ?>
        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Lead Magnet</span>
        <?php endif; ?>
        <span class="font-semibold text-gray-700 dark:text-gray-200"><?= e($ownerName) ?></span>
        <svg class="w-3 h-3 text-gray-300 dark:text-gray-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <span class="<?= $selectedClass ? 'text-gray-500 dark:text-gray-400' : 'font-semibold text-[var(--brand-color)]' ?>">Modul</span>
        <?php if ($selectedClass): ?>
        <svg class="w-3 h-3 text-gray-300 dark:text-gray-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <span class="font-semibold text-[var(--brand-color)] truncate max-w-[220px]"><?= e($selectedClass['title']) ?></span>
        <svg class="w-3 h-3 text-gray-300 dark:text-gray-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <span class="text-gray-500 dark:text-gray-400">Materi</span>
        <?php endif; ?>
      </nav>
      <?php if ($previewBase !== ''): ?>
      <a href="<?= e(APP_URL) ?><?= e($previewBase) ?>" target="_blank" rel="noopener"
         class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition shrink-0">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        Lihat sebagai Pembeli
      </a>
      <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

        <!-- ─── Classes (kiri, 2/5) ─────────────────────────── -->
        <div class="lg:col-span-2">
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
              <div>
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Modul</h3>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= count($classes) ?> modul</p>
              </div>
              <button onclick="openClassModal()"
                      class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Tambah Modul
              </button>
            </div>

            <!-- Form inline tambah/edit modul (pengganti popup) -->
            <div id="classForm" class="hidden border-b border-gray-100 dark:border-gray-800 bg-[color-mix(in_srgb,var(--brand-color)_4%,transparent)]">
              <div class="flex items-center justify-between px-5 pt-4 pb-1">
                <h4 id="classModalTitle" class="text-sm font-semibold text-gray-900 dark:text-white">Tambah Modul</h4>
                <button type="button" onclick="closeClassModal()" class="p-1 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-content" class="px-5 pb-5 space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_class">
                <input type="hidden" name="class_id" id="classId" value="">
                <?= $ownerHidden ?>
                <div>
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Judul Modul <span class="text-red-500">*</span></label>
                  <input type="text" name="title" id="classTitle" required maxlength="200"
                         class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                         placeholder="Contoh: Modul 1 — Pengantar">
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Deskripsi</label>
                  <textarea name="description" id="classDesc" rows="2"
                            class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
                            placeholder="Deskripsi singkat modul..."></textarea>
                </div>
                <?php if (!empty($allCategories)): ?>
                <div>
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Kategori</label>
                  <select name="category_id" id="classCategoryId"
                          class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <option value="">— Tanpa Kategori —</option>
                    <?php foreach ($allCategories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                    <select name="status" id="classStatus"
                            class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                      <option value="active">Active</option>
                      <option value="inactive">Inactive</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Urutan</label>
                    <input type="number" name="sort_order" id="classSortOrder" min="0" value="0"
                           class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  </div>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Drip Content — terbuka setelah (hari)</label>
                  <input type="number" name="unlock_after_days" id="classUnlockDays" min="0" max="3650" value="0"
                         class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1 leading-relaxed">0 = langsung terbuka. Isi mis. 7 = modul baru bisa dibuka member 7 hari sejak aksesnya aktif. Hanya berlaku untuk produk (lead magnet selalu terbuka).</p>
                </div>
                <?php
                // Multi-share hanya relevan untuk owner produk (bukan lead magnet).
                $shareTargets = [];
                if ($ownerType === 'product') {
                    foreach ($products as $prod) {
                        if ((int) $prod['id'] !== $selectedProductId) $shareTargets[] = $prod;
                    }
                }
                ?>
                <?php if ($ownerType === 'product' && !empty($shareTargets)): ?>
                <div>
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Bagikan ke produk lain</label>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mb-2 leading-relaxed">Modul ini akan tampil juga di produk yang dicentang (konten yang sama, tak perlu dibuat ulang). Perubahan materi otomatis berlaku di semua produk berbagi.</p>
                  <div class="max-h-40 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                    <?php foreach ($shareTargets as $prod): ?>
                    <label class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                      <input type="checkbox" name="shared_product_ids[]" value="<?= (int)$prod['id'] ?>" data-share-checkbox
                             class="shrink-0 w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
                      <span class="text-sm text-gray-700 dark:text-gray-300 truncate"><?= e($prod['name']) ?> <span class="text-xs text-gray-400">(<?= e($prod['type']) ?>)</span></span>
                    </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endif; ?>
                <div class="flex justify-end gap-2 pt-1">
                  <button type="button" onclick="closeClassModal()" class="px-3.5 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Batal</button>
                  <button type="submit" data-loading data-loading-text="Menyimpan..." class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
                </div>
              </form>
            </div>

            <?php if (empty($classes)): ?>
            <div class="py-10 text-center">
              <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada modul</p>
              <button onclick="openClassModal()" class="mt-2 text-xs text-[var(--brand-color)] hover:underline">Tambah modul pertama</button>
            </div>
            <?php else: ?>
            <div id="classList" class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($classes as $cls): ?>
              <?php
              $isActive = $selectedClassId === (int)$cls['id'];
              $clsUrl   = '?' . http_build_query(array_merge($ownerCtx, ['class_id' => $cls['id']]));
              ?>
              <div data-row-id="<?= (int)$cls['id'] ?>" class="flex items-center gap-3 px-4 py-3 <?= $isActive ? 'bg-[color-mix(in_srgb,var(--brand-color)_6%,transparent)]' : 'hover:bg-gray-50 dark:hover:bg-gray-800/40' ?> transition-colors">
                <span class="drag-handle cursor-grab text-gray-300 dark:text-gray-700 hover:text-gray-500 dark:hover:text-gray-500 shrink-0 -ml-1" title="Geser untuk mengubah urutan">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                </span>
                <a href="<?= e(APP_URL) ?>/admin/content<?= e($clsUrl) ?>" class="flex-1 min-w-0">
                  <p class="font-medium text-sm <?= $isActive ? 'text-[var(--brand-color)]' : 'text-gray-800 dark:text-gray-200' ?> truncate">
                    <?= e($cls['title']) ?>
                  </p>
                  <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                    <span class="text-xs text-gray-400 dark:text-gray-600">
                      <?= (int)$cls['material_count'] ?> materi &middot; urutan <?= (int)$cls['sort_order'] ?>
                    </span>
                    <?php if (!empty($cls['category_name'])): ?>
                    <span class="inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] text-[var(--brand-color)]">
                      <?= e($cls['category_name']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ((int) ($cls['unlock_after_days'] ?? 0) > 0): ?>
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400" title="Drip content — terbuka <?= (int)$cls['unlock_after_days'] ?> hari sejak akses member aktif">
                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                      Drip hari ke-<?= (int)$cls['unlock_after_days'] ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($cls['shared_product_ids'])): ?>
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400" title="Dibagikan ke produk lain">
                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                      Dibagikan ke <?= count($cls['shared_product_ids']) ?> produk
                    </span>
                    <?php endif; ?>
                  </div>
                </a>
                <div class="flex items-center gap-0.5 shrink-0">
                  <?php if ((int) $cls['material_count'] === 0): ?>
                  <a href="<?= e(APP_URL) ?>/admin/content<?= e($clsUrl) ?>&add_material=1"
                     title="Buka form tambah materi untuk modul ini"
                     class="inline-flex items-center gap-1 px-2.5 py-1.5 mr-1 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition whitespace-nowrap">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah Materi
                  </a>
                  <?php else: ?>
                  <a href="<?= e(APP_URL) ?>/admin/content<?= e($clsUrl) ?>"
                     title="Lihat &amp; kelola materi modul ini"
                     class="inline-flex items-center gap-1 px-2.5 py-1.5 mr-1 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition whitespace-nowrap">
                    Kelola Materi
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                  </a>
                  <?php endif; ?>
                  <button onclick='openClassModal(<?= htmlspecialchars(json_encode($cls), ENT_QUOTES) ?>)'
                          class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                  </button>
                  <form id="del-class-<?= (int)$cls['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-content" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="class_id" value="<?= (int)$cls['id'] ?>">
                    <?= $ownerHidden ?>
                    <button type="button"
                            onclick="confirmAndSubmit('del-class-<?= (int)$cls['id'] ?>',{title:'Hapus Modul?',message:'Modul ini beserta semua materinya akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})"
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
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ─── Materials (kanan, 3/5) ─────────────────────── -->
        <div class="lg:col-span-3">
          <?php if (!$selectedClass): ?>
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm py-14 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <p class="text-xs text-gray-400 dark:text-gray-600">Pilih modul untuk mengelola materi</p>
          </div>
          <?php else: ?>
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
              <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-[var(--brand-color)] mb-0.5">Materi dalam modul</p>
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm truncate"><?= e($selectedClass['title']) ?></h3>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5"><?= count($materials) ?> materi</p>
              </div>
              <button onclick="openMaterialModal()"
                      class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                  <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Tambah Materi
              </button>
            </div>

            <!-- Form inline tambah/edit materi (pengganti popup) -->
            <div id="materialForm" class="hidden border-b border-gray-100 dark:border-gray-800 bg-[color-mix(in_srgb,var(--brand-color)_4%,transparent)]">
              <div class="flex items-center justify-between px-5 pt-4 pb-1">
                <h4 id="materialModalTitle" class="text-sm font-semibold text-gray-900 dark:text-white">Tambah Materi</h4>
                <button type="button" onclick="closeMaterialModal()" class="p-1 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-content" class="px-5 pb-5 space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_material">
                <input type="hidden" name="material_id" id="materialId" value="">
                <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                <?= $ownerHidden ?>
                <div>
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Judul Materi <span class="text-red-500">*</span></label>
                  <input type="text" name="title" id="materialTitle" required maxlength="200"
                         class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                         placeholder="Judul materi...">
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Tipe Konten</label>
                    <select name="content_type" id="materialContentType"
                            class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                      <option value="text">Teks</option>
                      <option value="html">HTML (kode HTML kustom)</option>
                      <option value="video">Video (YouTube/Vimeo/URL)</option>
                      <option value="embed">Embed (iframe — Canva, dll)</option>
                      <option value="file">File</option>
                      <option value="link">Link</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Urutan</label>
                    <input type="number" name="sort_order" id="materialSortOrder" min="0" value="0"
                           class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition">
                  </div>
                </div>
                <?php if ($ownerType === 'product'): ?>
                <div id="downloadField" class="hidden">
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">File dari Download Manager</label>
                  <?php if ($productDownloads): ?>
                  <select name="download_id" id="materialDownloadId"
                          class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <option value="">— Tidak, pakai URL manual —</option>
                    <?php foreach ($productDownloads as $pd): ?>
                    <option value="<?= (int) $pd['id'] ?>"><?= e($pd['title']) ?> (<?= e($pd['filename_orig']) ?> · <?= formatFileSize((int) $pd['file_size']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                  <p class="text-[11px] text-gray-400 dark:text-gray-600 mt-1">
                    File terproteksi: diunduh lewat link sekali-pakai + cek akses member. Bila memilih file, URL manual di bawah diabaikan.
                    <a href="<?= e(APP_URL) ?>/admin/downloads" target="_blank" rel="noopener" class="text-[var(--brand-color)] hover:underline">Kelola file</a>
                  </p>
                  <?php else: ?>
                  <p class="text-xs text-gray-400 dark:text-gray-600 px-3 py-2 rounded-lg border border-dashed border-gray-200 dark:border-gray-700">
                    Belum ada file di Download Manager untuk produk ini —
                    <a href="<?= e(APP_URL) ?>/admin/downloads" target="_blank" rel="noopener" class="text-[var(--brand-color)] hover:underline">upload file dulu</a>
                    atau isi URL manual di bawah.
                  </p>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
                <div id="urlField">
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">URL Konten</label>
                  <input type="text" name="content_url" id="materialUrl"
                         class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                         placeholder="https://...">
                </div>
                <div id="contentField">
                  <label id="contentFieldLabel" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Konten Teks</label>
                  <!-- Editor teks kaya (tipe Teks) — hasilnya disimpan sebagai HTML ke kolom content -->
                  <div id="richToolbar" class="hidden flex-wrap items-center gap-0.5 px-2 py-1.5 rounded-t-lg border border-b-0 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                    <button type="button" data-cmd="bold" title="Tebal" class="rt-btn w-7 h-7 rounded text-sm font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">B</button>
                    <button type="button" data-cmd="italic" title="Miring" class="rt-btn w-7 h-7 rounded text-sm italic text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">I</button>
                    <button type="button" data-cmd="underline" title="Garis bawah" class="rt-btn w-7 h-7 rounded text-sm underline text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">U</button>
                    <span class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1"></span>
                    <button type="button" data-block="h2" title="Judul" class="rt-btn px-1.5 h-7 rounded text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">H2</button>
                    <button type="button" data-block="h3" title="Sub judul" class="rt-btn px-1.5 h-7 rounded text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">H3</button>
                    <button type="button" data-block="p" title="Paragraf biasa" class="rt-btn px-1.5 h-7 rounded text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">P</button>
                    <span class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1"></span>
                    <button type="button" data-cmd="insertUnorderedList" title="List poin" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/></svg>
                    </button>
                    <button type="button" data-cmd="insertOrderedList" title="List angka" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
                    </button>
                    <span class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1"></span>
                    <button type="button" id="rtLinkBtn" title="Sisipkan link" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    </button>
                    <button type="button" data-cmd="removeFormat" title="Bersihkan format" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M5 20h6"/><path d="M13 4L8 20"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="21" y1="15" x2="15" y2="21"/></svg>
                    </button>
                    <span id="rtLinkBox" class="hidden items-center gap-1 ml-1">
                      <input type="text" id="rtLinkUrl" placeholder="https://..."
                             class="px-2 py-1 rounded text-xs border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white outline-none w-44">
                      <button type="button" id="rtLinkApply" class="px-2 py-1 rounded text-xs font-semibold text-white bg-brand hover:opacity-90 transition">Pasang</button>
                    </span>
                  </div>
                  <div id="richEditor" contenteditable="true"
                       class="hidden w-full px-3 py-2 rounded-b-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition min-h-[140px] max-h-[380px] overflow-y-auto leading-relaxed [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_h2]:text-lg [&_h2]:font-bold [&_h3]:font-semibold [&_a]:text-[var(--brand-color)] [&_a]:underline [&_p]:mb-2"></div>
                  <textarea name="content" id="materialContent" rows="5"
                            class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-y"
                            placeholder="Isi konten materi..."></textarea>
                </div>

                <!-- Deskripsi kaya (tampil DI BAWAH konten) — untuk video/embed/link/file -->
                <div id="descField" class="hidden">
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Deskripsi (tampil di bawah konten)</label>
                  <p class="text-[11px] text-gray-400 dark:text-gray-600 mb-1.5">Opsional — teks &amp; link yang muncul di bawah video/konten (mis. prompt, tautan tool, catatan).</p>
                  <div id="descToolbar" class="flex flex-wrap items-center gap-0.5 px-2 py-1.5 rounded-t-lg border border-b-0 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                    <button type="button" data-cmd="bold" title="Tebal" class="rt-btn w-7 h-7 rounded text-sm font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">B</button>
                    <button type="button" data-cmd="italic" title="Miring" class="rt-btn w-7 h-7 rounded text-sm italic text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">I</button>
                    <button type="button" data-cmd="underline" title="Garis bawah" class="rt-btn w-7 h-7 rounded text-sm underline text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">U</button>
                    <span class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1"></span>
                    <button type="button" data-block="h2" title="Judul" class="rt-btn px-1.5 h-7 rounded text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">H2</button>
                    <button type="button" data-block="h3" title="Sub judul" class="rt-btn px-1.5 h-7 rounded text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">H3</button>
                    <button type="button" data-block="p" title="Paragraf biasa" class="rt-btn px-1.5 h-7 rounded text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">P</button>
                    <span class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1"></span>
                    <button type="button" data-cmd="insertUnorderedList" title="List poin" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/></svg>
                    </button>
                    <button type="button" data-cmd="insertOrderedList" title="List angka" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
                    </button>
                    <span class="w-px h-4 bg-gray-200 dark:bg-gray-700 mx-1"></span>
                    <button type="button" id="descLinkBtn" title="Sisipkan link" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    </button>
                    <button type="button" data-cmd="removeFormat" title="Bersihkan format" class="rt-btn w-7 h-7 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition inline-flex items-center justify-center">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3"/><path d="M5 20h6"/><path d="M13 4L8 20"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="21" y1="15" x2="15" y2="21"/></svg>
                    </button>
                    <span id="descLinkBox" class="hidden items-center gap-1 ml-1">
                      <input type="text" id="descLinkUrl" placeholder="https://..."
                             class="px-2 py-1 rounded text-xs border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white outline-none w-44">
                      <button type="button" id="descLinkApply" class="px-2 py-1 rounded text-xs font-semibold text-white bg-brand hover:opacity-90 transition">Pasang</button>
                    </span>
                  </div>
                  <div id="descEditor" contenteditable="true"
                       class="w-full px-3 py-2 rounded-b-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition min-h-[120px] max-h-[360px] overflow-y-auto leading-relaxed [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_h2]:text-lg [&_h2]:font-bold [&_h3]:font-semibold [&_a]:text-[var(--brand-color)] [&_a]:underline [&_p]:mb-2"></div>
                  <textarea name="description" id="descContent" class="hidden"></textarea>
                </div>
                <?php if ($ownerType === 'product' && $productDownloads): ?>
                <div id="attachField">
                  <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Lampiran (opsional)</label>
                  <p class="text-[11px] text-gray-400 dark:text-gray-600 mb-1.5">File Download Manager yang ditampilkan di bawah konten materi — boleh lebih dari satu, berlaku untuk semua tipe konten.</p>
                  <div class="max-h-36 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                    <?php foreach ($productDownloads as $pd): ?>
                    <label class="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                      <input type="checkbox" name="attach_download_ids[]" value="<?= (int) $pd['id'] ?>" data-attach-checkbox
                             class="shrink-0 w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
                      <span class="text-sm text-gray-700 dark:text-gray-300 truncate"><?= e($pd['title']) ?> <span class="text-xs text-gray-400">(<?= e($pd['filename_orig']) ?> &middot; <?= formatFileSize((int) $pd['file_size']) ?>)</span></span>
                    </label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endif; ?>
                <div class="flex justify-end gap-2 pt-1">
                  <button type="button" onclick="closeMaterialModal()" class="px-3.5 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">Batal</button>
                  <button type="submit" data-loading data-loading-text="Menyimpan..." class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
                </div>
              </form>
            </div>

            <?php if (empty($materials)): ?>
            <div class="py-10 text-center">
              <p class="text-xs text-gray-400 dark:text-gray-600">Belum ada materi</p>
              <button onclick="openMaterialModal()" class="mt-2 text-xs text-[var(--brand-color)] hover:underline">Tambah materi pertama</button>
            </div>
            <?php else: ?>
            <div id="materialList" class="divide-y divide-gray-100 dark:divide-gray-800">
              <?php foreach ($materials as $mat): ?>
              <div data-row-id="<?= (int)$mat['id'] ?>" class="flex items-center gap-3 px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                <span class="drag-handle cursor-grab text-gray-300 dark:text-gray-700 hover:text-gray-500 dark:hover:text-gray-500 shrink-0 -ml-1" title="Geser untuk mengubah urutan">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                </span>
                <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0 text-gray-500 dark:text-gray-400">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="<?= $typeIcon($mat['content_type']) ?>"/>
                  </svg>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate"><?= e($mat['title']) ?></p>
                  <p class="text-xs text-gray-400 dark:text-gray-600 capitalize"><?= e($mat['content_type']) ?></p>
                </div>
                <div class="flex items-center gap-0.5 shrink-0">
                  <?php if ($previewBase !== ''): ?>
                  <a href="<?= e(APP_URL) ?><?= e($previewBase) ?>/material/<?= (int)$mat['id'] ?>" target="_blank" rel="noopener"
                     title="Preview materi (tampilan pembeli)"
                     class="p-1.5 rounded-lg text-gray-400 hover:text-[var(--brand-color)] hover:bg-[color-mix(in_srgb,var(--brand-color)_10%,transparent)] transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </a>
                  <?php endif; ?>
                  <button onclick='openMaterialModal(<?= htmlspecialchars(json_encode($mat), ENT_QUOTES) ?>)'
                          class="p-1.5 rounded-lg text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                  </button>
                  <form id="del-mat-<?= (int)$mat['id'] ?>" method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-content" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="delete_material">
                    <input type="hidden" name="material_id" value="<?= (int)$mat['id'] ?>">
                    <?= $ownerHidden ?>
                    <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                    <button type="button"
                            onclick="confirmAndSubmit('del-mat-<?= (int)$mat['id'] ?>',{title:'Hapus Materi?',message:'Materi ini akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})"
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
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- ═══ MODAL KELAS ═══ -->
<!-- form modul kini inline di panel kiri (pengganti popup) -->

<!-- ═══ MODAL MATERI ═══ -->
<!-- form materi kini inline di panel kanan (pengganti popup) -->

<script>
// ── Class modal ──────────────────────────────────────────────
function openClassModal(cls) {
  const box = document.getElementById('classForm');
  box.classList.remove('hidden');
  const catSel = document.getElementById('classCategoryId');
  if (cls) {
    document.getElementById('classModalTitle').textContent  = 'Edit Modul';
    document.getElementById('classId').value                = cls.id;
    document.getElementById('classTitle').value             = cls.title;
    document.getElementById('classDesc').value              = cls.description || '';
    document.getElementById('classStatus').value            = cls.status;
    document.getElementById('classSortOrder').value         = cls.sort_order || 0;
    document.getElementById('classUnlockDays').value        = cls.unlock_after_days || 0;
    if (catSel) catSel.value = cls.category_id || '';
    setSharedProducts(cls.shared_product_ids || []);
  } else {
    document.getElementById('classModalTitle').textContent  = 'Tambah Modul';
    document.getElementById('classId').value                = '';
    document.getElementById('classTitle').value             = '';
    document.getElementById('classDesc').value              = '';
    document.getElementById('classStatus').value            = 'active';
    document.getElementById('classSortOrder').value         = <?= count($classes) ?>;
    document.getElementById('classUnlockDays').value        = 0;
    if (catSel) catSel.value = '';
    setSharedProducts([]);
  }
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  document.getElementById('classTitle').focus();
}
function setSharedProducts(ids) {
  const set = new Set((ids || []).map(Number));
  document.querySelectorAll('[data-share-checkbox]').forEach(cb => {
    cb.checked = set.has(Number(cb.value));
  });
}
function closeClassModal() {
  const box = document.getElementById('classForm');
  if (box) box.classList.add('hidden');
}

// ── Material modal ───────────────────────────────────────────
function openMaterialModal(mat) {
  const box = document.getElementById('materialForm');
  box.classList.remove('hidden');
  if (mat) {
    document.getElementById('materialModalTitle').textContent = 'Edit Materi';
    document.getElementById('materialId').value               = mat.id;
    document.getElementById('materialTitle').value            = mat.title;
    document.getElementById('materialContentType').value      = mat.content_type;
    document.getElementById('materialUrl').value              = mat.content_url  || '';
    document.getElementById('materialContent').value          = mat.content      || '';
    document.getElementById('descEditor').innerHTML           = mat.description  || '';
    document.getElementById('materialSortOrder').value        = mat.sort_order   || 0;
    setMaterialDownloadId(mat.download_id || '');
    setAttachIds(mat.attach_ids || []);
  } else {
    document.getElementById('materialModalTitle').textContent = 'Tambah Materi';
    document.getElementById('materialId').value               = '';
    document.getElementById('materialTitle').value            = '';
    document.getElementById('materialContentType').value      = 'text';
    document.getElementById('materialUrl').value              = '';
    document.getElementById('materialContent').value          = '';
    document.getElementById('descEditor').innerHTML           = '';
    document.getElementById('materialSortOrder').value        = <?= count($materials) ?>;
    setMaterialDownloadId('');
    setAttachIds([]);
  }
  updateMaterialFields();
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  document.getElementById('materialTitle').focus();
}
function closeMaterialModal() {
  const box = document.getElementById('materialForm');
  if (box) box.classList.add('hidden');
}

function updateMaterialFields() {
  const sel = document.getElementById('materialContentType');
  if (!sel) return;
  const type = sel.value;
  const urlField      = document.getElementById('urlField');
  const contentField  = document.getElementById('contentField');
  const downloadField = document.getElementById('downloadField');
  const usesContent   = (type === 'text' || type === 'embed' || type === 'html');
  urlField.classList.toggle('hidden', usesContent);
  contentField.classList.toggle('hidden', !usesContent);
  if (downloadField) downloadField.classList.toggle('hidden', type !== 'file');
  // Deskripsi kaya: untuk tipe yang butuh teks/link di bawah konten (video/embed/link/file).
  const descField = document.getElementById('descField');
  if (descField) descField.classList.toggle('hidden', !(type === 'video' || type === 'embed' || type === 'link' || type === 'file'));
  syncMaterialUrlState();
  // Label & placeholder textarea menyesuaikan tipe.
  const lbl = document.getElementById('contentFieldLabel');
  const ta  = document.getElementById('materialContent');
  if (lbl && ta) {
    const isCode = (type === 'embed' || type === 'html');
    ta.classList.toggle('font-mono', isCode);
    ta.classList.toggle('text-xs', isCode);
    if (type === 'embed') {
      lbl.textContent = 'Kode Embed (iframe)';
      ta.placeholder  = 'Tempel kode embed di sini. Contoh: <iframe src="https://www.canva.com/design/.../view?embed" ...></iframe>';
    } else if (type === 'html') {
      lbl.textContent = 'Kode HTML';
      ta.placeholder  = 'Tempel kode HTML kustom di sini. Contoh: <h2>Judul</h2><p>Paragraf...</p><img src="https://...">';
    } else {
      lbl.textContent = 'Konten Teks';
      ta.placeholder  = 'Isi konten materi...';
    }
  }
  syncRichEditor(type);
}

// ── Editor teks kaya (tipe Teks) ─────────────────────────────
// contenteditable + toolbar; hasil HTML disinkronkan ke textarea saat submit.
// Konten teks polos lama dikonversi ke paragraf saat dimuat ke editor.
function syncRichEditor(type) {
  const bar = document.getElementById('richToolbar');
  const ed  = document.getElementById('richEditor');
  const ta  = document.getElementById('materialContent');
  if (!bar || !ed || !ta) return;
  const useRich = (type === 'text');
  bar.classList.toggle('hidden', !useRich);
  bar.classList.toggle('flex', useRich);
  ed.classList.toggle('hidden', !useRich);
  ta.classList.toggle('hidden', useRich);
  if (useRich) {
    const val = ta.value || '';
    if (/<[a-z][^>]*>/i.test(val)) {
      ed.innerHTML = val;
    } else {
      // Teks polos → paragraf (escape dulu supaya karakter < & > aman).
      const esc = val.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      ed.innerHTML = val.trim() === '' ? '' :
        '<p>' + esc.split(/\n{2,}/).map(function (p) { return p.replace(/\n/g, '<br>'); }).join('</p><p>') + '</p>';
    }
  }
}
function syncRichToTextarea() {
  const ed = document.getElementById('richEditor');
  const ta = document.getElementById('materialContent');
  const type = document.getElementById('materialContentType').value;
  if (!ed || !ta || type !== 'text' || ed.classList.contains('hidden')) return;
  const html = ed.innerHTML.trim();
  ta.value = (html === '' || html === '<br>' || html === '<p><br></p>') ? '' : html;
}
(function () {
  const bar = document.getElementById('richToolbar');
  const ed  = document.getElementById('richEditor');
  if (!bar || !ed) return;
  bar.querySelectorAll('[data-cmd]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      ed.focus();
      document.execCommand(btn.getAttribute('data-cmd'), false, null);
    });
  });
  bar.querySelectorAll('[data-block]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      ed.focus();
      document.execCommand('formatBlock', false, '<' + btn.getAttribute('data-block') + '>');
    });
  });
  // Sisipkan link tanpa prompt() bawaan: input kecil inline di toolbar.
  const linkBtn = document.getElementById('rtLinkBtn');
  const linkBox = document.getElementById('rtLinkBox');
  const linkUrl = document.getElementById('rtLinkUrl');
  let savedRange = null;
  linkBtn.addEventListener('click', function () {
    const sel = window.getSelection();
    savedRange = (sel && sel.rangeCount) ? sel.getRangeAt(0) : null;
    linkBox.classList.toggle('hidden');
    linkBox.classList.toggle('inline-flex');
    if (!linkBox.classList.contains('hidden')) linkUrl.focus();
  });
  document.getElementById('rtLinkApply').addEventListener('click', function () {
    const url = (linkUrl.value || '').trim();
    if (!url) return;
    ed.focus();
    if (savedRange) {
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(savedRange);
    }
    document.execCommand('createLink', false, url);
    linkUrl.value = '';
    linkBox.classList.add('hidden');
    linkBox.classList.remove('inline-flex');
  });
  // Sinkron ke textarea tepat sebelum form dikirim.
  const form = ed.closest('form');
  if (form) form.addEventListener('submit', syncRichToTextarea);
})();

// ── Editor kaya Deskripsi (di bawah konten) ──────────────────
(function () {
  const bar = document.getElementById('descToolbar');
  const ed  = document.getElementById('descEditor');
  const ta  = document.getElementById('descContent');
  if (!bar || !ed || !ta) return;
  bar.querySelectorAll('[data-cmd]').forEach(function (btn) {
    btn.addEventListener('click', function () { ed.focus(); document.execCommand(btn.getAttribute('data-cmd'), false, null); });
  });
  bar.querySelectorAll('[data-block]').forEach(function (btn) {
    btn.addEventListener('click', function () { ed.focus(); document.execCommand('formatBlock', false, '<' + btn.getAttribute('data-block') + '>'); });
  });
  const linkBtn = document.getElementById('descLinkBtn');
  const linkBox = document.getElementById('descLinkBox');
  const linkUrl = document.getElementById('descLinkUrl');
  let savedRange = null;
  if (linkBtn) linkBtn.addEventListener('click', function () {
    const sel = window.getSelection();
    savedRange = (sel && sel.rangeCount) ? sel.getRangeAt(0) : null;
    linkBox.classList.toggle('hidden'); linkBox.classList.toggle('inline-flex');
    if (!linkBox.classList.contains('hidden')) linkUrl.focus();
  });
  const linkApply = document.getElementById('descLinkApply');
  if (linkApply) linkApply.addEventListener('click', function () {
    const url = (linkUrl.value || '').trim();
    if (!url) return;
    ed.focus();
    if (savedRange) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(savedRange); }
    document.execCommand('createLink', false, url);
    linkUrl.value = ''; linkBox.classList.add('hidden'); linkBox.classList.remove('inline-flex');
  });
  const form = ed.closest('form');
  if (form) form.addEventListener('submit', function () {
    const html = ed.innerHTML.trim();
    ta.value = (html === '' || html === '<br>' || html === '<p><br></p>') ? '' : html;
  });
})();

// ── Lampiran materi (checkbox Download Manager) ──────────────
function setAttachIds(ids) {
  const set = new Set((ids || []).map(Number));
  document.querySelectorAll('[data-attach-checkbox]').forEach(function (cb) {
    cb.checked = set.has(Number(cb.value));
  });
}

// ── Drag & drop urutan modul / materi ────────────────────────
// Geser lewat pegangan (ikon titik-titik); saat dilepas, urutan baru dikirim
// ke server (indeks baris = sort_order). Tanpa library luar.
function initSortable(listId, action) {
  const list = document.getElementById(listId);
  if (!list) return;
  let dragEl = null, startOrder = '';
  const rowIds = function () {
    return Array.from(list.querySelectorAll('[data-row-id]')).map(function (r) { return r.getAttribute('data-row-id'); });
  };
  list.querySelectorAll('[data-row-id]').forEach(function (row) {
    const handle = row.querySelector('.drag-handle');
    if (!handle) return;
    handle.addEventListener('mousedown', function () { row.draggable = true; });
    row.addEventListener('dragstart', function (e) {
      dragEl = row;
      startOrder = rowIds().join(',');
      row.classList.add('opacity-50');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
    });
    row.addEventListener('dragend', function () {
      row.draggable = false;
      row.classList.remove('opacity-50');
      if (!dragEl) return;
      dragEl = null;
      const now = rowIds();
      if (now.join(',') === startOrder) return;
      const fd = new FormData();
      fd.append('csrf_token', '<?= e($_SESSION['csrf_token']) ?>');
      fd.append('action', action);
      fd.append('product_id', '<?= (int) $selectedProductId ?>');
      fd.append('lead_magnet_id', '<?= (int) $selectedLeadMagnetId ?>');
      fd.append('class_id', '<?= (int) $selectedClassId ?>');
      now.forEach(function (id) { fd.append('ids[]', id); });
      fetch('<?= e(APP_URL) ?>/actions/admin/save-content', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.success) { if (window.showToast) showToast('Urutan tersimpan.', 'success'); }
          else if (window.showToast) showToast(d.message || 'Gagal menyimpan urutan.', 'error');
        })
        .catch(function () { if (window.showToast) showToast('Gagal menyimpan urutan.', 'error'); });
    });
  });
  list.addEventListener('dragover', function (e) {
    if (!dragEl) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const rows = Array.from(list.querySelectorAll('[data-row-id]')).filter(function (r) { return r !== dragEl; });
    let before = null;
    for (let i = 0; i < rows.length; i++) {
      const rc = rows[i].getBoundingClientRect();
      if (e.clientY < rc.top + rc.height / 2) { before = rows[i]; break; }
    }
    if (before) list.insertBefore(dragEl, before);
    else list.appendChild(dragEl);
  });
  list.addEventListener('drop', function (e) { e.preventDefault(); });
}
initSortable('classList', 'reorder_classes');
initSortable('materialList', 'reorder_materials');

// Tautan Download Manager pada materi tipe file: bila sebuah file dipilih,
// URL manual dinonaktifkan (server memang mengabaikannya).
function setMaterialDownloadId(val) {
  const dl = document.getElementById('materialDownloadId');
  if (dl) dl.value = String(val || '');
  syncMaterialUrlState();
}
function syncMaterialUrlState() {
  const dl  = document.getElementById('materialDownloadId');
  const url = document.getElementById('materialUrl');
  if (!url) return;
  // URL manual hanya dinonaktifkan bila tipe = File DAN sebuah file dipilih.
  // (Cegah URL ter-disable gara-gara nilai sisa select saat tipe = link/video.)
  const type = document.getElementById('materialContentType')?.value;
  const linked = (type === 'file') && !!(dl && dl.value);
  url.disabled = linked;
  url.classList.toggle('opacity-50', linked);
  if (linked) url.placeholder = 'Memakai file Download Manager di atas';
  else url.placeholder = 'https://...';
}
const _mdl = document.getElementById('materialDownloadId');
if (_mdl) _mdl.addEventListener('change', syncMaterialUrlState);

const _mct = document.getElementById('materialContentType');
if (_mct) { _mct.addEventListener('change', updateMaterialFields); updateMaterialFields(); }

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeClassModal(); closeMaterialModal(); }
});

<?php if ($selectedClass && !empty($_GET['add_material'])): ?>
// Datang dari tombol "Tambah Materi" di daftar modul — langsung buka formnya
// supaya user tidak bingung mencari langkah berikutnya.
openMaterialModal();
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
