<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Canvas';
$topbarTitle = 'Canvas';

// Hasil aksi terakhir (dipakai JS utk kelola draf Builder) — di-PEEK sebelum
// partials/flash-message.php mengonsumsi $_SESSION['flash']. 'success' = publish
// berhasil → draf aman dibuang; 'error' = gagal → tawarkan pulihkan.
$canvasFlashResult = isset($_SESSION['flash']['success']) ? 'success'
    : (isset($_SESSION['flash']['error']) ? 'error' : '');

// ─── Query data awal ──────────────────────────────────────────
$canvases       = [];
$products       = [];
$magnets        = []; // lead magnet published — untuk penaut Canvas ↔ lead magnet
$activeHomepage = null;

try {
    $pdo = getDB();
    if (function_exists('ensureCanvasRedirectColumns')) ensureCanvasRedirectColumns($pdo);

    $canvases = $pdo->query(
        "SELECT * FROM canvas_pages ORDER BY created_at DESC LIMIT 100"
    )->fetchAll();

    // Lampirkan daftar produk tertaut (junction) ke tiap canvas utk form edit.
    // Fallback ke kolom lama linked_product_id bila junction kosong (data lama).
    try {
        $cppRows = $pdo->query("SELECT canvas_page_id, product_id FROM canvas_page_products")->fetchAll();
        $cppMap  = [];
        foreach ($cppRows as $r) { $cppMap[(int) $r['canvas_page_id']][] = (int) $r['product_id']; }
        foreach ($canvases as &$cv) {
            $cid = (int) $cv['id'];
            $cv['linked_product_ids'] = $cppMap[$cid]
                ?? (!empty($cv['linked_product_id']) ? [(int) $cv['linked_product_id']] : []);
        }
        unset($cv);
    } catch (Throwable $e) { /* tabel junction belum ada (pra-migrasi) */ }

    // Lampirkan varian Rotator (Split Test) ke tiap canvas untuk form edit.
    try {
        $rvRows = $pdo->query(
            "SELECT rotator_id, variant_canvas_id, label, weight, is_active
             FROM canvas_rotator_variants ORDER BY sort_order ASC, id ASC"
        )->fetchAll();
        $rvMap = [];
        foreach ($rvRows as $r) {
            $rvMap[(int) $r['rotator_id']][] = [
                'canvasId' => (int) $r['variant_canvas_id'],
                'label'    => (string) $r['label'],
                'weight'   => (int) $r['weight'],
                'active'   => (int) $r['is_active'],
            ];
        }
        foreach ($canvases as &$cv2) { $cv2['rotator_variants'] = $rvMap[(int) $cv2['id']] ?? []; }
        unset($cv2);
    } catch (Throwable $e) { /* tabel rotator belum ada (pra-migrasi) */ }

    // Kandidat varian = canvas non-rotator (untuk dropdown builder rotator).
    $variantCandidates = array_values(array_filter($canvases, fn($c) => ($c['page_type'] ?? '') !== 'rotator'));

    $products = $pdo->query(
        "SELECT id, name, slug FROM products WHERE status = 'active' ORDER BY name ASC"
    )->fetchAll();

    // Lead magnet published — untuk tipe halaman "Landing Page Lead Magnet".
    try {
        $magnets = $pdo->query(
            "SELECT id, title, slug FROM lead_magnets WHERE status = 'published' ORDER BY title ASC"
        )->fetchAll();
    } catch (Exception $e) { $magnets = []; } // pra-migrasi lead_magnets → lewati

    $hpStmt = $pdo->query(
        "SELECT * FROM canvas_pages WHERE is_homepage = 1 AND is_active = 1 LIMIT 1"
    );
    $activeHomepage = $hpStmt->fetch() ?: null;

} catch (Exception $e) {
    error_log('Admin canvas list error: ' . $e->getMessage());
    flash('error', 'Gagal memuat data Canvas.', 'error');
}

$appBase  = rtrim(APP_URL, '/');
$hostOnly = preg_replace('#^https?://#', '', $appBase);

// ─── Badge helpers ────────────────────────────────────────────
$typeBadge = function (string $type): string {
    [$cls, $label] = match ($type) {
        'homepage'        => ['bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400', 'Homepage'],
        'product_landing' => ['bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400', 'Landing Produk'],
        'rotator'         => ['bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400', 'Split Test'],
        'redirect'        => ['cv-redir-badge', 'Redirect'],
        default           => ['bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'Mandiri'],
    };
    return '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium ' . $cls . '">' . $label . '</span>';
};

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
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Canvas</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Deploy halaman HTML kustom dengan pixel tracking per-halaman</p>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" id="toggleCanvasForm" onclick="toggleCanvasForm()"
                  class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Buat Canvas
          </button>
          <a href="<?= e(APP_URL) ?>/admin/docs#canvas" target="_blank" rel="noopener"
             class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            Panduan
          </a>
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] text-[var(--brand-color)]">
            <?= number_format(count($canvases)) ?> Canvas
          </span>
        </div>
      </div>

      <div class="space-y-5">

        <!-- ═══ FORM CREATE / EDIT (collapsible) ═══ -->
        <div id="canvasFormWrap" class="hidden">
          <div>
            <form id="canvasForm" method="POST" action="<?= e($appBase) ?>/actions/admin/save-canvas"
                  enctype="multipart/form-data"
                  class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">

              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" id="canvasAction" value="publish">
              <input type="hidden" name="id" id="canvasId" value="">

              <!-- Card header -->
              <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
                <h3 id="canvasFormTitle" class="text-sm font-bold text-gray-900 dark:text-white">Canvas Baru</h3>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400"><?= number_format(count($canvases)) ?></span>
              </div>

              <div class="p-5 space-y-4">

                <!-- Kotak error validasi (pra-submit) — tampil di editor tanpa
                     terlempar ke daftar & kehilangan isian. -->
                <div id="canvasFormError" class="hidden rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 text-xs leading-relaxed px-3 py-2.5"></div>

                <!-- Title -->
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Nama Canvas <span class="text-red-500">*</span></label>
                  <input type="text" name="title" id="cTitle" required maxlength="255"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                         placeholder="Misal: Sales Page Webinar">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Label internal, tidak tampil ke pengunjung.</p>
                </div>

                <!-- Slug -->
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Slug / URL <span class="text-red-500">*</span></label>
                  <input type="text" name="slug" id="cSlug" required maxlength="80"
                         class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                         placeholder="sales-page-webinar">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">
                    <span id="slugPreview" class="font-mono text-[var(--brand-color)]"><?= e($hostOnly) ?>/slug</span>
                  </p>
                  <p id="cSlugLocked" class="hidden text-xs text-amber-500 mt-1">Slug tidak dapat diubah setelah Canvas dipublish.</p>
                </div>

                <!-- Page type -->
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tipe Halaman</label>
                  <select name="page_type" id="cPageType"
                          class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <option value="standalone">Halaman Mandiri (/slug)</option>
                    <option value="homepage">Homepage Override (/)</option>
                    <option value="product_landing">Landing Page Produk</option>
                    <option value="lead_magnet_landing">Landing Page Lead Magnet</option>
                    <option value="rotator">Rotator / Split Test</option>
                    <option value="redirect">Redirect (teruskan ke URL lain)</option>
                  </select>
                </div>

                <!-- Redirect: URL tujuan (short-link / cloaking affiliate) -->
                <div id="cRedirectBlock" class="hidden space-y-2">
                  <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">URL Tujuan <span class="text-red-500">*</span></label>
                    <input type="url" name="redirect_url" id="cRedirectUrl" placeholder="https://mitra.contoh.com/aff?id=123"
                           class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">Pengunjung <strong>/<span id="cRedirectSlugPreview">slug</span></strong> diteruskan ke URL ini (redirect 302). Hanya <strong>http(s)</strong>.</p>
                  </div>
                  <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="hidden" name="redirect_keep_query" value="0">
                    <input type="checkbox" name="redirect_keep_query" id="cRedirectKeepQuery" value="1" checked class="w-4 h-4 rounded accent-[var(--brand-color)]">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Teruskan query string (mis. <code class="text-xs">?sub=abc</code>) ke URL tujuan</span>
                  </label>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500">Isi <strong>Tracking Pixel</strong> di bawah bila ingin merekam klik (Meta/TikTok) sebelum redirect.</p>
                </div>

                <!-- Linked products (multi) -->
                <div id="cProductBlock" class="hidden">
                  <div class="flex items-center justify-between mb-1">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Produk yang Ditautkan</label>
                    <span id="cLinkedCount" class="text-[11px] font-medium text-gray-400 dark:text-gray-500 tabular-nums"></span>
                  </div>
                  <?php if (empty($products)): ?>
                  <p class="text-xs text-gray-400 dark:text-gray-600 px-3 py-2 rounded-lg border border-dashed border-gray-200 dark:border-gray-700">Belum ada produk aktif untuk ditautkan.</p>
                  <?php else: ?>
                  <?php if (count($products) > 6): ?>
                  <input type="text" id="cProductSearch" autocomplete="off" placeholder="Cari produk..."
                         class="w-full px-3 h-9 mb-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                  <?php endif; ?>
                  <div id="cLinkedProducts" class="max-h-40 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-800">
                    <?php foreach ($products as $p): ?>
                    <label class="cLinkedRow flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors" data-pname="<?= e(mb_strtolower((string) $p['name'])) ?>">
                      <input type="checkbox" name="linked_product_ids[]" value="<?= (int) $p['id'] ?>"
                             class="cLinkedProduct shrink-0 w-4 h-4" style="accent-color:var(--brand-color)">
                      <span class="text-sm text-gray-700 dark:text-gray-200 truncate"><?= e($p['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                  </div>
                  <p id="cProductNoResult" class="hidden text-xs text-gray-400 dark:text-gray-600 px-3 py-2 mt-1 rounded-lg border border-dashed border-gray-200 dark:border-gray-700">Tidak ada produk yang cocok dengan pencarian.</p>
                  <?php endif; ?>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Bisa pilih <strong>lebih dari satu</strong>. Tiap kunjungan Canvas dihitung sebagai visitor sales page untuk setiap produk terpilih di Funnel Analytics.</p>
                </div>

                <!-- Linked lead magnet (single) -->
                <div id="cLeadMagnetBlock" class="hidden">
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Lead Magnet yang Ditautkan</label>
                  <?php if (empty($magnets)): ?>
                  <p class="text-xs text-gray-400 dark:text-gray-600 px-3 py-2 rounded-lg border border-dashed border-gray-200 dark:border-gray-700">Belum ada lead magnet berstatus <strong>published</strong> untuk ditautkan.</p>
                  <?php else: ?>
                  <select name="linked_lead_magnet_id" id="cLeadMagnetId"
                          class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <option value="">— Pilih lead magnet —</option>
                    <?php foreach ($magnets as $m): ?>
                    <option value="<?= (int) $m['id'] ?>"><?= e($m['title']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php endif; ?>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Canvas ini jadi landing page kaya untuk lead magnet tersebut. Affiliate bisa membagikan link Canvas ini (<code>?ref=</code>) dari dashboard mereka — atribusi tetap terkunci.</p>
                </div>

                <!-- Rotator / Split Test: daftar varian -->
                <div id="cRotatorBlock" class="hidden">
                  <input type="hidden" name="rotator_variants_json" id="cRotatorJson" value="">
                  <div class="flex items-center justify-between mb-1">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Varian (Angle) Sales Page</label>
                    <button type="button" id="cRotatorAdd"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-semibold text-[var(--brand-color)] border hover:bg-gray-50 dark:hover:bg-gray-800 transition" style="border-color:color-mix(in srgb,var(--brand-color) 35%,transparent)">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                      Tambah Varian
                    </button>
                  </div>
                  <?php if (empty($variantCandidates)): ?>
                  <p class="text-xs text-amber-600 dark:text-amber-400 px-3 py-2 rounded-lg border border-dashed border-amber-300 dark:border-amber-800">Belum ada Canvas lain untuk dijadikan varian. Buat dulu beberapa Canvas angle (Halaman Mandiri / Landing Produk), lalu rujuk di sini.</p>
                  <?php endif; ?>
                  <div id="cRotatorRows" class="space-y-2"></div>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5 leading-relaxed">
                    Tiap pengunjung diarahkan sticky ke salah satu varian sesuai <strong>bobot</strong> (traffic split). Butuh minimal 1 varian; idealnya 2+ untuk membandingkan angle. Konversi diukur di panel <strong>Hasil Split Test</strong>.
                  </p>
                </div>

                <!-- Meta description -->
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Meta Description</label>
                  <textarea name="meta_desc" id="cMetaDesc" rows="2" maxlength="160"
                            class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-none"
                            placeholder="Deskripsi singkat untuk SEO (maks 160 karakter)"></textarea>
                </div>

                <!-- Mode -->
                <div id="cModeBlock">
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Mode Konten</label>
                  <select name="mode" id="cMode"
                          class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
                    <option value="builder">Visual Builder (no-code)</option>
                    <option value="upload">Upload ZIP / HTML</option>
                    <option value="embed">Embed HTML Langsung</option>
                  </select>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1"><strong>Visual Builder</strong>: susun halaman dari blok siap pakai (mobile-first) tanpa ngoding.</p>
                </div>

                <!-- Upload block -->
                <?php
                // Batas efektif nyata = lebih kecil antara cap aplikasi (50MB, harus
                // sinkron dgn save-canvas.php) dan batas PHP hosting (post_max_size/
                // upload_max_filesize) — di shared hosting batas server sering jauh
                // lebih kecil & bikin upload gagal membingungkan (lihat save-canvas.php).
                $canvasAppCap = 50 * 1024 * 1024;
                $canvasSrvLimit = phpUploadLimitBytes();
                $canvasEffLimit = $canvasSrvLimit > 0 ? min($canvasSrvLimit, $canvasAppCap) : $canvasAppCap;
                ?>
                <div id="cUploadBlock">
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">File (maks <?= e(formatFileSize($canvasEffLimit)) ?>)</label>
                  <input type="file" name="html_zip" accept=".zip,.html,.htm" id="cHtmlZip" data-max="<?= (int) $canvasEffLimit ?>"
                         onchange="var w=document.getElementById('cUploadSizeWarn');var f=this.files[0];var over=f&&f.size>parseInt(this.dataset.max,10);w.classList.toggle('hidden',!over);document.getElementById('canvasSubmit').disabled=!!over;"
                         class="block w-full text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-[color-mix(in_srgb,var(--brand-color)_12%,transparent)] file:text-[var(--brand-color)] hover:file:opacity-80 cursor-pointer">
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">ZIP wajib memuat <span class="font-mono">index.html</span> di root. Boleh sertakan CSS, JS, gambar, font, video/audio — path relatif otomatis jalan. Untuk video besar, embed dari YouTube/CDN (jangan bundel di ZIP).</p>
                  <p id="cUploadSizeWarn" class="hidden text-xs text-red-500 mt-1">File melebihi batas upload (<?= e(formatFileSize($canvasEffLimit)) ?>) — perkecil file atau naikkan batas PHP di hosting.</p>
                  <?php if ($canvasSrvLimit > 0 && $canvasSrvLimit < $canvasAppCap): ?>
                  <p class="text-xs text-gray-400 mt-1">Batas ini mengikuti pengaturan PHP hosting (post_max_size / upload_max_filesize).</p>
                  <?php endif; ?>
                  <p id="cUploadEditNote" class="hidden text-xs text-amber-500 mt-1">File sudah ada — upload baru untuk mengganti.</p>
                </div>

                <!-- Embed block -->
                <div id="cEmbedBlock" class="hidden">
                  <div class="flex items-center justify-between gap-2 mb-1">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Embed HTML</label>
                    <button type="button" id="cInsertImage"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 15l-5-5L5 21"/></svg>
                      Sisipkan Gambar
                    </button>
                  </div>
                  <textarea name="html_embed" id="cHtmlEmbed" rows="12"
                            class="w-full px-3 py-2 rounded-lg text-xs font-mono border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition resize-y"
                            placeholder="<!DOCTYPE html>&#10;<html>...</html>"></textarea>
                  <button type="button" id="muliaPreset" class="mt-2 text-sm font-semibold underline">Gunakan templat STIFIn Mulia</button>
                  <template id="muliaSource"><textarea><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pilihan STIFIn Mulia</title><style>
*{box-sizing:border-box}body{margin:0;background:#f6f4ed;color:#19382f;font:1rem/1.65 system-ui,sans-serif}main{max-width:64rem;margin:auto;padding:clamp(1.3rem,6vw,5rem)}header{padding-bottom:1rem;border-bottom:1px solid #a7b6aa;font-weight:700}h1,h2{font-family:Georgia,serif;font-weight:500;line-height:1.06}h1{font-size:clamp(2.8rem,7vw,5.5rem);max-width:16ch;margin:.8em 0 .35em}p{max-width:48ch}a{display:inline-block;padding:.75rem 1.25rem;background:#184b3b;color:white;border-radius:.5rem;text-decoration:none;font-weight:650}a:focus-visible{outline:3px solid #a96317;outline-offset:4px}section{margin-top:3rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,14rem),1fr));gap:1rem}article{background:#fffdf8;border:1px solid #d5d9ce;padding:1.4rem;border-radius:.9rem}h2{font-size:1.65rem;margin:0}
</style></head><body><main><header>STIFIn Mulia</header><h1>Kenali diri lebih dekat melalui STIFIn.</h1><p>Temukan tes personal atau keluarga, buku pendamping, dan workshop dalam katalog STIFIn Mulia.</p><a href="<?= e(APP_URL) ?>/products">Lihat katalog produk →</a><section aria-label="Pilihan produk"><article><h2>Tes STIFIn</h2><p>Lihat pilihan tes untuk diri sendiri atau keluarga.</p></article><article><h2>Buku</h2><p>Temukan bacaan untuk mempelajari hasil tes.</p></article><article><h2>Workshop</h2><p>Jelajahi kegiatan untuk mendalami konsep STIFIn.</p></article></section></main></body></html></textarea></template>
                  <script>
                    document.getElementById('muliaPreset').addEventListener('click', function () {
                      const editor = document.getElementById('cHtmlEmbed');
                      if (editor.value.trim() && !window.confirm('Ganti isi editor dengan templat STIFIn Mulia?')) return;
                      editor.value = document.getElementById('muliaSource').content.querySelector('textarea').value;
                      editor.dispatchEvent(new Event('input', {bubbles:true}));
                      editor.focus();
                    });
                  </script>
                </div>

                <!-- Visual Builder block -->
                <div id="cBuilderBlock" class="hidden">
                  <input type="hidden" name="builder_json" id="cBuilderJson" value="">
                  <style>
                    .lb-wrap{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fafafa}
                    .dark .lb-wrap{border-color:#374151;background:#0b1220}
                    .lb-theme{display:flex;flex-wrap:wrap;gap:12px;padding:12px 14px;border-bottom:1px solid #eef1f6;background:#fff}
                    .dark .lb-theme{background:#111827;border-color:#1f2937}
                    .lb-theme label{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:500;color:#6b7280}
                    .dark .lb-theme label{color:#9ca3af}
                    .lb-theme input[type=color]{width:30px;height:30px;padding:0;border:1px solid #e5e7eb;border-radius:6px;background:none;cursor:pointer}
                    .lb-theme select{height:30px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;padding:0 6px;background:#fff;color:#111827}
                    .dark .lb-theme select{background:#1f2937;color:#f3f4f6;border-color:#374151}
                    .lb-palette{display:flex;flex-wrap:wrap;gap:6px;padding:10px 14px;border-bottom:1px solid #eef1f6}
                    .dark .lb-palette{border-color:#1f2937}
                    .lb-addbtn{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:6px 10px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;color:#374151;cursor:pointer;transition:border-color .15s,color .15s}
                    .dark .lb-addbtn{background:#1f2937;color:#e5e7eb;border-color:#374151}
                    .lb-addbtn:hover{border-color:var(--brand-color);color:var(--brand-color)}
                    .lb-addbtn svg{width:13px;height:13px}
                    .lb-list{padding:12px 14px;display:flex;flex-direction:column;gap:10px;min-height:40px}
                    .lb-empty{font-size:13px;color:#9ca3af;text-align:center;padding:18px;border:1px dashed #d1d5db;border-radius:10px}
                    .dark .lb-empty{border-color:#374151}
                    .lb-block{border:1px solid #e5e7eb;border-radius:10px;background:#fff;overflow:hidden}
                    .dark .lb-block{background:#111827;border-color:#1f2937}
                    .lb-bhead{display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f9fafb;border-bottom:1px solid #eef1f6;cursor:pointer}
                    .dark .lb-bhead{background:#0f172a;border-color:#1f2937}
                    .lb-bhead svg.lb-ico{width:15px;height:15px;color:var(--brand-color);flex:none}
                    .lb-bhead .lb-btitle{font-size:13px;font-weight:600;color:#111827;flex:1}
                    .dark .lb-bhead .lb-btitle{color:#f3f4f6}
                    .lb-bact{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:6px;border:0;background:none;color:#9ca3af;cursor:pointer}
                    .lb-bact:hover{background:#f3f4f6;color:#111827}
                    .dark .lb-bact:hover{background:#1f2937;color:#f3f4f6}
                    .lb-bact.lb-del:hover{color:#dc2626;background:#fef2f2}
                    .dark .lb-bact.lb-del:hover{background:rgba(220,38,38,.15)}
                    .lb-bact svg{width:15px;height:15px}
                    .lb-bfields{padding:10px 12px;display:flex;flex-direction:column;gap:9px}
                    .lb-block.lb-collapsed .lb-bfields{display:none}
                    .lb-f label{display:block;font-size:11px;font-weight:500;color:#6b7280;margin-bottom:3px}
                    .dark .lb-f label{color:#9ca3af}
                    .lb-f input[type=text],.lb-f input[type=url],.lb-f input[type=number],.lb-f textarea,.lb-f select{width:100%;font-size:13px;border:1px solid #e5e7eb;border-radius:7px;padding:6px 8px;background:#fff;color:#111827;outline:none}
                    .dark .lb-f input,.dark .lb-f textarea,.dark .lb-f select{background:#1f2937;color:#f3f4f6;border-color:#374151}
                    .lb-f input:focus,.lb-f textarea:focus,.lb-f select:focus{border-color:var(--brand-color);box-shadow:0 0 0 2px color-mix(in srgb,var(--brand-color) 20%,transparent)}
                    .lb-f textarea{resize:vertical;min-height:58px;font-family:inherit}
                    .lb-f .lb-help{font-size:11px;color:#9ca3af;margin-top:3px}
                    .lb-img-row{display:flex;gap:6px;align-items:center}
                    .lb-img-row input{flex:1}
                    .lb-img-pick{font-size:12px;font-weight:600;padding:6px 10px;border-radius:7px;border:1px solid #e5e7eb;background:#fff;color:var(--brand-color);cursor:pointer;white-space:nowrap}
                    .dark .lb-img-pick{background:#1f2937;border-color:#374151}
                    .lb-img-thumb{width:34px;height:34px;border-radius:6px;object-fit:cover;border:1px solid #e5e7eb;display:none}
                  </style>
                  <div class="lb-wrap">
                    <div class="lb-theme">
                      <label>Warna Brand <input type="color" id="lbTheme_brand" value="#6366f1"></label>
                      <label>Latar <input type="color" id="lbTheme_bg" value="#ffffff"></label>
                      <label>Teks <input type="color" id="lbTheme_text" value="#0f172a"></label>
                      <label>Font
                        <select id="lbTheme_font">
                          <option value="system">Sistem (modern)</option>
                          <option value="serif">Serif (elegan)</option>
                          <option value="rounded">Rounded (ramah)</option>
                        </select>
                      </label>
                    </div>
                    <div class="lb-palette" id="lbPalette"></div>
                    <div class="lb-list" id="lbList"></div>
                  </div>
                  <div class="flex items-center gap-2 mt-2">
                    <button type="button" id="lbPreviewBtn"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:border-[var(--brand-color)] hover:text-[var(--brand-color)] transition">
                      <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      Pratinjau (HP)
                    </button>
                    <span class="text-xs text-gray-400 dark:text-gray-600">Blok disusun dari atas ke bawah. Klik judul blok untuk lipat.</span>
                  </div>
                </div>

                <!-- Tracking Pixel (collapsible) -->
                <div id="cPixelCard" class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
                  <button type="button" id="pixelToggle"
                          class="w-full flex items-center justify-between px-3.5 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <div>
                      <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">Tracking Pixel</p>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Pixel khusus halaman ini — beda dari pixel global.</p>
                    </div>
                    <svg id="pixelChevron" class="w-4 h-4 text-gray-400 transition-transform shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                  </button>
                  <div id="pixelBody" class="hidden px-3.5 pb-3.5 pt-1 space-y-3 border-t border-gray-100 dark:border-gray-800">
                    <div class="grid grid-cols-1 gap-3">
                      <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Meta Pixel ID</label>
                        <input type="text" name="meta_pixel_id" id="cMetaPixel" maxlength="255"
                               class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                               placeholder="1234567890, 0987654321">
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">TikTok Pixel ID</label>
                        <input type="text" name="tiktok_pixel_id" id="cTiktokPixel" maxlength="255"
                               class="w-full px-3 h-9 rounded-lg text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                               placeholder="ABCDE1234, FGHIJ5678">
                      </div>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-600">Kosongkan jika tidak diperlukan. Pixel hanya aktif saat Canvas ini dibuka. <strong>Bisa lebih dari 1 ID — pisahkan dengan koma.</strong></p>
                  </div>
                </div>

                <!-- Active -->
                <label class="flex items-center gap-2.5 cursor-pointer">
                  <input type="checkbox" name="is_active" id="cActive" checked
                         class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
                  <span class="text-sm text-gray-700 dark:text-gray-300">Aktifkan Canvas</span>
                </label>

                <!-- AI Chat per-halaman -->
                <?php $aiChatMaster = getSetting('ai_chat_enabled') === '1'; ?>
                <div>
                  <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" name="ai_chat_enabled" id="cAiChat"
                           class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-[var(--brand-color)] focus:ring-[var(--brand-color)]">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Tampilkan AI Chat di halaman ini</span>
                  </label>
                  <p class="text-xs text-gray-400 dark:text-gray-600 mt-1" style="margin-left:1.625rem">
                    <?php if ($aiChatMaster): ?>
                      Widget AI Chat muncul di Canvas ini. Bekal jawaban AI diambil dari produk yang ditaut halaman.
                    <?php else: ?>
                      AI Chat global sedang MATI — widget tetap tak muncul walau opsi ini menyala. Aktifkan dulu di <strong>Pengaturan &rsaquo; AI Chat</strong>.
                    <?php endif; ?>
                  </p>
                </div>

              </div>

              <!-- Card footer -->
              <div class="flex items-center gap-2.5 px-5 py-3.5 border-t border-gray-100 dark:border-gray-800">
                <button type="submit" id="canvasSubmit" data-loading data-loading-text="Menyimpan..."
                        class="flex-1 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                  Publish Canvas
                </button>
                <button type="button" id="canvasCancel" onclick="cancelEdit()"
                        class="hidden px-4 py-2 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                  Batal
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- ═══ HOMEPAGE STATUS + TABEL (full width) ═══ -->
        <div class="space-y-5">

          <!-- Homepage status -->
          <?php if ($activeHomepage): ?>
          <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800/50 rounded-xl p-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
              <span class="w-9 h-9 rounded-lg bg-green-100 dark:bg-green-900/40 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
              </span>
              <div class="min-w-0">
                <p class="text-sm font-semibold text-green-800 dark:text-green-300 truncate">Homepage aktif: <?= e($activeHomepage['title']) ?></p>
                <p class="text-xs text-green-600 dark:text-green-500 font-mono truncate">→ <?= e($appBase) ?>/<?= e($activeHomepage['slug']) ?></p>
              </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <a href="<?= e($appBase) ?>/<?= e($activeHomepage['slug']) ?>/" target="_blank"
                 class="px-3 py-1.5 rounded-lg text-xs font-medium text-green-700 dark:text-green-400 bg-white/70 dark:bg-gray-900/40 hover:bg-white dark:hover:bg-gray-900 transition">Preview</a>
              <form method="POST" action="<?= e($appBase) ?>/actions/admin/save-canvas" class="inline">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="unset_homepage">
                <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-600 dark:text-red-400 bg-white/70 dark:bg-gray-900/40 hover:bg-white dark:hover:bg-gray-900 transition">Nonaktifkan</button>
              </form>
            </div>
          </div>
          <?php else: ?>
          <div class="bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-800 rounded-xl p-4 flex items-center gap-3">
            <span class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
              <svg class="w-5 h-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
              </svg>
            </span>
            <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada Canvas homepage — menampilkan halaman produk default Averion.</p>
          </div>
          <?php endif; ?>

          <!-- Tabel -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
            <?php if (empty($canvases)): ?>
            <div class="py-16 text-center">
              <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/>
              </svg>
              <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Belum ada Canvas</p>
              <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Buat Canvas pertama lewat tombol <strong>Buat Canvas</strong> di atas.</p>
            </div>
            <?php else: ?>
            <style>
              .cv-act{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:.5rem;color:#6b7280;background:transparent;border:0;cursor:pointer;transition:background .15s,color .15s}
              .cv-act:hover{background:#f3f4f6;color:#111827}
              .cv-act svg{width:1rem;height:1rem}
              .cv-act-blue:hover{color:#2563eb;background:#eff6ff}
              .cv-act-purple:hover{color:#7c3aed;background:#faf5ff}
              .cv-act-amber:hover{color:#d97706;background:#fffbeb}
              .cv-act-red:hover{color:#dc2626;background:#fef2f2}
              .cv-sep{display:inline-block;width:1px;height:1.25rem;background:#e5e7eb;margin:0 .25rem;vertical-align:middle}
              .cv-redir-badge{background:#ccfbf1;color:#0f766e}
              .dark .cv-redir-badge{background:rgba(19,78,74,.4);color:#5eead4}
              .cv-redir-url{color:#0d9488}
              .dark .cv-redir-url{color:#5eead4}
              .dark .cv-act{color:#9ca3af}
              .dark .cv-act:hover{background:#1f2937;color:#f3f4f6}
              .dark .cv-act-blue:hover{background:rgba(37,99,235,.15)}
              .dark .cv-act-purple:hover{background:rgba(124,58,237,.15)}
              .dark .cv-act-amber:hover{background:rgba(217,119,6,.15)}
              .dark .cv-act-red:hover{background:rgba(220,38,38,.15)}
              .dark .cv-sep{background:#374151}
              /* Pencarian canvas (filter client-side atas baris yang tampil) */
              .cv-search-wrap{position:relative;display:flex;align-items:center;max-width:26rem}
              .cv-search-wrap > svg{position:absolute;left:.7rem;width:1rem;height:1rem;color:#9ca3af;pointer-events:none}
              .cv-search-input{width:100%;padding:.5rem .5rem .5rem 2.1rem;font-size:.875rem;color:#111827;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;outline:none;transition:border-color .15s,box-shadow .15s}
              .cv-search-input::placeholder{color:#9ca3af}
              .cv-search-input:focus{border-color:var(--brand-color);box-shadow:0 0 0 3px color-mix(in srgb,var(--brand-color) 15%,transparent)}
              .cv-search-clear{position:absolute;right:.5rem;display:none;align-items:center;justify-content:center;width:1.4rem;height:1.4rem;border:0;border-radius:.375rem;background:transparent;color:#9ca3af;cursor:pointer;font-size:1rem;line-height:1}
              .cv-search-clear:hover{background:#f3f4f6;color:#111827}
              .cv-search-count{margin-top:.4rem;font-size:.75rem;color:#6b7280}
              .dark .cv-search-input{color:#f3f4f6;background:#111827;border-color:#374151}
              .dark .cv-search-input::placeholder{color:#6b7280}
              .dark .cv-search-clear:hover{background:#1f2937;color:#f3f4f6}
              .dark .cv-search-count{color:#9ca3af}
            </style>
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800">
              <div class="cv-search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="cvSearch" class="cv-search-input" autocomplete="off" spellcheck="false"
                       placeholder="Cari canvas: judul, slug, atau tipe…" oninput="cvFilter()" onkeydown="if(event.key==='Escape')cvClearSearch()">
                <button type="button" id="cvSearchClear" class="cv-search-clear" aria-label="Bersihkan pencarian" onclick="cvClearSearch()">&times;</button>
              </div>
              <p id="cvSearchCount" class="cv-search-count"></p>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Canvas</th>
                    <th class="text-center px-3 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tipe</th>
                    <th class="text-center px-3 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="text-center px-3 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pixel</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                  <?php foreach ($canvases as $c): ?>
                  <?php
                    $canvasUrl = $appBase . '/' . $c['slug'] . '/';
                    // Basis pencarian client-side: judul + slug + tipe + URL redirect (lowercase).
                    $cvSearchStr = strtolower(trim(
                        ($c['title'] ?? '') . ' ' . ($c['slug'] ?? '') . ' '
                        . ($c['page_type'] ?? '') . ' ' . ($c['redirect_url'] ?? '')
                    ));
                  ?>
                  <tr data-cv-search="<?= e($cvSearchStr) ?>" class="cv-row hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors align-middle">
                    <td class="px-4 py-3.5">
                      <p class="font-semibold text-gray-900 dark:text-white flex items-center gap-1.5">
                        <?= e($c['title']) ?>
                        <?php if ((int) ($c['is_homepage'] ?? 0) === 1): ?>
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" title="Canvas ini dipakai sebagai Homepage">
                          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                          Homepage
                        </span>
                        <?php endif; ?>
                      </p>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 font-mono">/<?= e($c['slug']) ?></p>
                      <?php if (($c['page_type'] ?? '') === 'redirect' && !empty($c['redirect_url'])): ?>
                      <p class="cv-redir-url text-[11px] mt-0.5 truncate max-w-xs inline-flex items-center gap-1" title="<?= e($c['redirect_url']) ?>">
                        <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        <span class="truncate"><?= e($c['redirect_url']) ?></span>
                      </p>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-3.5 text-center"><?= $typeBadge($c['page_type']) ?></td>
                    <td class="px-3 py-3.5 text-center">
                      <?php if ((int) $c['is_active'] === 1): ?>
                      <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Aktif</span>
                      <?php else: ?>
                      <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Nonaktif</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-3.5 text-center">
                      <div class="inline-flex flex-wrap gap-1 justify-center">
                        <?php if (!empty($c['meta_pixel_id'])): ?>
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">Meta</span>
                        <?php endif; ?>
                        <?php if (!empty($c['tiktok_pixel_id'])): ?>
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-900 text-white dark:bg-gray-200 dark:text-gray-900">TikTok</span>
                        <?php endif; ?>
                        <?php if (empty($c['meta_pixel_id']) && empty($c['tiktok_pixel_id'])): ?>
                        <span class="text-xs text-gray-300 dark:text-gray-600">—</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="px-4 py-3.5 text-right whitespace-nowrap">
                      <div class="inline-flex items-center gap-0.5">
                        <!-- Buka -->
                        <a href="<?= e($canvasUrl) ?>" target="_blank" rel="noopener" title="Buka halaman" class="cv-act">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </a>
                        <!-- Salin URL -->
                        <button type="button" onclick="copyCanvasUrl('<?= e($canvasUrl) ?>')" title="Salin URL" class="cv-act">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                        <!-- Edit -->
                        <button type="button" onclick='openEditCanvas(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)' title="Edit" class="cv-act cv-act-blue">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <?php if (($c['page_type'] ?? '') === 'rotator'): ?>
                        <!-- Hasil A/B -->
                        <a href="<?= e($appBase) ?>/admin/canvas/stats?id=<?= (int) $c['id'] ?>" title="Hasil A/B test" class="cv-act cv-act-amber">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        </a>
                        <?php endif; ?>

                        <span class="cv-sep"></span>

                        <?php if ((int) $c['is_homepage'] === 0): ?>
                        <!-- Set Homepage -->
                        <form method="POST" action="<?= e($appBase) ?>/actions/admin/save-canvas" class="inline">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="set_homepage">
                          <input type="hidden" name="slug" value="<?= e($c['slug']) ?>">
                          <button type="submit" title="Jadikan Homepage" class="cv-act cv-act-purple">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                          </button>
                        </form>
                        <?php endif; ?>
                        <!-- Clear Cache (tak relevan untuk redirect) -->
                        <?php if (($c['page_type'] ?? '') !== 'redirect'): ?>
                        <form method="POST" action="<?= e($appBase) ?>/actions/admin/save-canvas" class="inline">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="clear_cache">
                          <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                          <button type="submit" title="Bersihkan cache &amp; refresh asset" class="cv-act cv-act-amber">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                          </button>
                        </form>
                        <?php endif; ?>
                        <!-- Aktif / Nonaktif -->
                        <form method="POST" action="<?= e($appBase) ?>/actions/admin/save-canvas" class="inline">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="toggle_active">
                          <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                          <button type="submit" title="<?= (int) $c['is_active'] === 1 ? 'Nonaktifkan canvas' : 'Aktifkan canvas' ?>" class="cv-act">
                            <?php if ((int) $c['is_active'] === 1): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                            <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php endif; ?>
                          </button>
                        </form>

                        <span class="cv-sep"></span>

                        <!-- Hapus -->
                        <form id="del-canvas-<?= (int) $c['id'] ?>" method="POST" action="<?= e($appBase) ?>/actions/admin/save-canvas" class="inline">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                          <button type="button" title="Hapus canvas"
                                  onclick="confirmAndSubmit('del-canvas-<?= (int) $c['id'] ?>',{title:'Hapus Canvas?',message:'Canvas ini beserta file-nya akan dihapus permanen.',confirmText:'Ya, Hapus',variant:'danger'})"
                                  class="cv-act cv-act-red">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  <tr id="cvNoResult" style="display:none">
                    <td colspan="5" class="py-12 text-center">
                      <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                      <p class="text-sm font-medium text-gray-400 dark:text-gray-600">Tidak ada canvas yang cocok</p>
                      <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Coba kata kunci lain, atau <button type="button" onclick="cvClearSearch()" class="underline hover:text-gray-600 dark:hover:text-gray-400">bersihkan pencarian</button>.</p>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <script>
            // Pencarian canvas: filter baris tabel yang sudah dirender (client-side,
            // tanpa reload). Cocokkan tiap kata kunci (dipisah spasi) ke data-cv-search
            // baris (judul+slug+tipe+redirect). Fungsi global (dipanggil via oninput).
            function cvFilter() {
              var input = document.getElementById('cvSearch');
              if (!input) return;
              var q = input.value.trim().toLowerCase();
              var terms = q.split(/\s+/).filter(Boolean);
              var rows = document.querySelectorAll('tr.cv-row');
              var shown = 0;
              rows.forEach(function (row) {
                var hay = row.getAttribute('data-cv-search') || '';
                var match = terms.every(function (t) { return hay.indexOf(t) !== -1; });
                row.style.display = match ? '' : 'none';
                if (match) shown++;
              });
              var noRes = document.getElementById('cvNoResult');
              if (noRes) noRes.style.display = (shown === 0) ? '' : 'none';
              var clearBtn = document.getElementById('cvSearchClear');
              if (clearBtn) clearBtn.style.display = q !== '' ? 'inline-flex' : 'none';
              var count = document.getElementById('cvSearchCount');
              if (count) count.textContent = q === '' ? '' : (shown + ' canvas cocok dari ' + rows.length);
            }
            function cvClearSearch() {
              var input = document.getElementById('cvSearch');
              if (!input) return;
              input.value = '';
              cvFilter();
              input.focus();
            }
            </script>
            <?php endif; ?>
          </div>

        </div>
      </div>

    </main>
  </div>
</div>

<!-- Pratinjau Landing Builder (frame HP) -->
<div id="lbPreviewModal" class="hidden fixed inset-0 z-[70] items-center justify-center bg-black/60 p-4" style="display:none">
  <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden flex flex-col" style="max-height:92vh">
    <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100 dark:border-gray-800">
      <p class="text-sm font-semibold text-gray-900 dark:text-white">Pratinjau (tampilan HP)</p>
      <button type="button" id="lbPreviewClose" class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
      </button>
    </div>
    <iframe id="lbPreviewFrame" class="w-full flex-1" style="min-height:70vh;border:0;background:#eef1f6"></iframe>
  </div>
</div>

<script>
(function () {
  var APP_HOST = <?= json_encode($hostOnly) ?>;

  var elTitle    = document.getElementById('cTitle');
  var elSlug     = document.getElementById('cSlug');
  var elPageType = document.getElementById('cPageType');
  var elMode     = document.getElementById('cMode');
  var elId       = document.getElementById('canvasId');
  var elAction   = document.getElementById('canvasAction');

  // ── Slug preview + sanitasi ──────────────────────────────────
  function updateSlugPreview() {
    var s = elSlug.value || 'slug';
    document.getElementById('slugPreview').textContent = APP_HOST + '/' + s;
    var rp = document.getElementById('cRedirectSlugPreview');
    if (rp) rp.textContent = s;
  }
  function sanitizeSlug(v) {
    return v.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9_-]/g, '');
  }
  elSlug.addEventListener('input', function () {
    var c = this.selectionStart;
    this.value = sanitizeSlug(this.value);
    try { this.setSelectionRange(c, c); } catch (e) {}
    updateSlugPreview();
  });

  // Auto-slug dari title HANYA saat create (id kosong).
  elTitle.addEventListener('input', function () {
    if (!elId.value) {
      elSlug.value = sanitizeSlug(this.value);
      updateSlugPreview();
    }
  });

  // ── Toggle product / lead magnet block ───────────────────────
  function toggleProductBlock() {
    document.getElementById('cProductBlock')
      .classList.toggle('hidden', elPageType.value !== 'product_landing');
    var lm = document.getElementById('cLeadMagnetBlock');
    if (lm) lm.classList.toggle('hidden', elPageType.value !== 'lead_magnet_landing');
  }
  elPageType.addEventListener('change', toggleProductBlock);

  // ── Hitung produk tertaut terpilih ───────────────────────────
  function updateLinkedCount() {
    var boxes = document.querySelectorAll('.cLinkedProduct');
    var n = 0;
    boxes.forEach(function (cb) { if (cb.checked) n++; });
    var el = document.getElementById('cLinkedCount');
    if (el) el.textContent = n > 0 ? (n + ' dipilih') : '';
  }
  document.querySelectorAll('.cLinkedProduct').forEach(function (cb) {
    cb.addEventListener('change', updateLinkedCount);
  });

  // ── Cari/filter produk tertaut ───────────────────────────────
  (function () {
    var inp = document.getElementById('cProductSearch');
    if (!inp) return;
    var rows  = document.querySelectorAll('#cLinkedProducts .cLinkedRow');
    var noRes = document.getElementById('cProductNoResult');
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase();
      var shown = 0;
      rows.forEach(function (r) {
        var match = q === '' || (r.getAttribute('data-pname') || '').indexOf(q) !== -1;
        r.classList.toggle('hidden', !match);
        if (match) shown++;
      });
      if (noRes) noRes.classList.toggle('hidden', shown !== 0);
    });
  })();

  // ── Toggle upload / embed ────────────────────────────────────
  function toggleModeBlocks() {
    var m = elMode.value;
    document.getElementById('cEmbedBlock').classList.toggle('hidden', m !== 'embed');
    document.getElementById('cUploadBlock').classList.toggle('hidden', m !== 'upload');
    document.getElementById('cBuilderBlock').classList.toggle('hidden', m !== 'builder');
  }
  elMode.addEventListener('change', toggleModeBlocks);

  // ── Rotator / Split Test ─────────────────────────────────────
  var ROT_CANDIDATES = <?= json_encode(array_map(fn($c) => ['id' => (int) $c['id'], 'title' => $c['title'], 'slug' => $c['slug']], $variantCandidates), JSON_UNESCAPED_UNICODE) ?>;
  function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }

  // Sembunyikan konten-sendiri (mode/upload/embed) saat rotator ATAU redirect;
  // rotator → tampilkan blok varian; redirect → tampilkan blok URL tujuan.
  // Kartu Pixel TETAP tampil (rotator: gabung varian; redirect: fire sebelum lompat).
  function toggleRotatorUI() {
    var isRot      = elPageType.value === 'rotator';
    var isRedirect = elPageType.value === 'redirect';
    document.getElementById('cRotatorBlock').classList.toggle('hidden', !isRot);
    var rb = document.getElementById('cRedirectBlock');
    if (rb) rb.classList.toggle('hidden', !isRedirect);
    document.getElementById('cModeBlock').classList.toggle('hidden', isRot || isRedirect);
    if (isRot || isRedirect) {
      document.getElementById('cUploadBlock').classList.add('hidden');
      document.getElementById('cEmbedBlock').classList.add('hidden');
      document.getElementById('cBuilderBlock').classList.add('hidden');
    } else {
      toggleModeBlocks();
    }
  }
  elPageType.addEventListener('change', toggleRotatorUI);

  function rotOptions(selId) {
    var h = '<option value="">— pilih canvas —</option>';
    ROT_CANDIDATES.forEach(function (c) {
      h += '<option value="' + c.id + '"' + (String(c.id) === String(selId) ? ' selected' : '') +
           '>' + escHtml(c.title) + ' (/' + escHtml(c.slug) + ')</option>';
    });
    return h;
  }
  var inpCls = 'px-2 h-8 rounded-lg text-xs border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent';
  function addRotatorRow(data) {
    data = data || {};
    var row = document.createElement('div');
    row.className = 'cRotRow flex items-center gap-1.5 p-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40';
    row.innerHTML =
      '<select class="cRotCanvas flex-1 min-w-0 ' + inpCls + '">' + rotOptions(data.canvasId) + '</select>' +
      '<input type="text" class="cRotLabel w-20 ' + inpCls + '" placeholder="Label" value="' + escHtml(data.label || '') + '">' +
      '<input type="number" min="1" max="1000" class="cRotWeight w-12 ' + inpCls + '" value="' + (data.weight ? parseInt(data.weight, 10) : 1) + '" title="Bobot traffic">' +
      '<label class="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300 shrink-0 cursor-pointer"><input type="checkbox" class="cRotActive w-3.5 h-3.5" ' + (data.active === 0 ? '' : 'checked') + '>Aktif</label>' +
      '<button type="button" class="cRotRemove shrink-0 w-6 h-6 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Hapus varian"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg></button>';
    document.getElementById('cRotatorRows').appendChild(row);
    row.querySelector('.cRotRemove').addEventListener('click', function () { row.remove(); });
  }
  function clearRotatorRows() { document.getElementById('cRotatorRows').innerHTML = ''; }
  function serializeRotatorRows() {
    var out = [];
    document.querySelectorAll('#cRotatorRows .cRotRow').forEach(function (r) {
      var cid = parseInt(r.querySelector('.cRotCanvas').value, 10);
      if (!cid) return;
      out.push({
        canvasId: cid,
        label: r.querySelector('.cRotLabel').value || '',
        weight: parseInt(r.querySelector('.cRotWeight').value, 10) || 1,
        active: r.querySelector('.cRotActive').checked ? 1 : 0
      });
    });
    return out;
  }
  var rotAddBtn = document.getElementById('cRotatorAdd');
  if (rotAddBtn) rotAddBtn.addEventListener('click', function () { addRotatorRow({}); });

  // ── Collapsible pixel section ────────────────────────────────
  document.getElementById('pixelToggle').addEventListener('click', function () {
    var body = document.getElementById('pixelBody');
    var open = body.classList.toggle('hidden');
    document.getElementById('pixelChevron').style.transform = open ? '' : 'rotate(180deg)';
  });

  // ── Edit / Cancel ────────────────────────────────────────────
  window.openEditCanvas = function (c) {
    elAction.value = 'update';
    elId.value     = c.id || '';
    elTitle.value  = c.title || '';
    elSlug.value   = c.slug || '';
    elSlug.readOnly = true;
    elSlug.classList.add('opacity-70', 'cursor-not-allowed');
    document.getElementById('cSlugLocked').classList.remove('hidden');
    updateSlugPreview();

    elPageType.value = c.page_type || 'standalone';
    toggleProductBlock();
    var linkedIds = (c.linked_product_ids && c.linked_product_ids.length
        ? c.linked_product_ids
        : (c.linked_product_id ? [c.linked_product_id] : [])).map(String);
    document.querySelectorAll('.cLinkedProduct').forEach(function (cb) {
      cb.checked = linkedIds.indexOf(String(cb.value)) !== -1;
    });
    updateLinkedCount();
    var elLm = document.getElementById('cLeadMagnetId');
    if (elLm) elLm.value = c.linked_lead_magnet_id || '';

    // Redirect: isi URL tujuan + toggle passthrough.
    document.getElementById('cRedirectUrl').value = c.redirect_url || '';
    document.getElementById('cRedirectKeepQuery').checked = (c.redirect_keep_query === undefined || String(c.redirect_keep_query) === '1');

    // Rotator: isi baris varian dari data.
    clearRotatorRows();
    if (c.page_type === 'rotator' && c.rotator_variants && c.rotator_variants.length) {
      c.rotator_variants.forEach(function (rv) { addRotatorRow(rv); });
    }
    toggleRotatorUI();

    document.getElementById('cMetaDesc').value   = c.meta_desc || '';
    elMode.value = (c.mode === 'embed' || c.mode === 'builder') ? c.mode : 'upload';
    toggleModeBlocks();
    if (c.mode === 'builder') {
      var lbEnv = null;
      try { lbEnv = JSON.parse(c.content || '{}'); } catch (e) { lbEnv = null; }
      if (window.lbLoadEnvelope) window.lbLoadEnvelope(lbEnv || {});
      document.getElementById('cHtmlEmbed').value = '';
    } else {
      document.getElementById('cHtmlEmbed').value = c.content || '';
    }

    document.getElementById('cMetaPixel').value   = c.meta_pixel_id || '';
    document.getElementById('cTiktokPixel').value = c.tiktok_pixel_id || '';
    if (c.meta_pixel_id || c.tiktok_pixel_id) {
      document.getElementById('pixelBody').classList.remove('hidden');
      document.getElementById('pixelChevron').style.transform = 'rotate(180deg)';
    }

    document.getElementById('cActive').checked = String(c.is_active) === '1';
    document.getElementById('cAiChat').checked = String(c.ai_chat_enabled) === '1';

    // Catatan upload saat edit.
    document.getElementById('cUploadEditNote').classList.toggle('hidden', c.mode !== 'upload');

    document.getElementById('canvasFormTitle').textContent = 'Edit Canvas';
    document.getElementById('canvasSubmit').textContent    = 'Simpan Perubahan';
    document.getElementById('canvasCancel').classList.remove('hidden');

    document.getElementById('canvasFormWrap').classList.remove('hidden');
    document.getElementById('canvasForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    elTitle.focus();
  };

  // Buka/tutup form collapsible (mode create).
  window.toggleCanvasForm = function () {
    var wrap = document.getElementById('canvasFormWrap');
    if (wrap.classList.contains('hidden')) {
      cancelEdit();                 // reset ke mode "Canvas Baru"
      wrap.classList.remove('hidden');
      document.getElementById('canvasCancel').classList.remove('hidden'); // tombol Batal utk menutup
      wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
      elTitle.focus();
    } else {
      cancelEdit();                 // cancelEdit menyembunyikan wrap
    }
  };

  window.cancelEdit = function () {
    var f = document.getElementById('canvasForm');
    f.reset();
    document.getElementById('canvasFormWrap').classList.add('hidden');
    elAction.value = 'publish';
    elId.value = '';
    elSlug.readOnly = false;
    elSlug.classList.remove('opacity-70', 'cursor-not-allowed');
    document.getElementById('cSlugLocked').classList.add('hidden');
    document.getElementById('cUploadEditNote').classList.add('hidden');
    clearRotatorRows();
    if (window.lbReset) window.lbReset();
    toggleProductBlock();
    toggleRotatorUI();
    updateLinkedCount();
    toggleModeBlocks();
    updateSlugPreview();
    document.getElementById('canvasFormTitle').textContent = 'Canvas Baru';
    document.getElementById('canvasSubmit').textContent    = 'Publish Canvas';
    document.getElementById('canvasCancel').classList.add('hidden');
  };

  // ── Salin URL Canvas ke clipboard ────────────────────────────
  window.copyCanvasUrl = function (url) {
    var ok = function () { if (window.showToast) showToast('URL disalin: ' + url, 'success'); };
    // Fallback lawas (http non-localhost / clipboard API ditolak): textarea+execCommand.
    var legacyCopy = function () {
      try {
        var ta = document.createElement('textarea');
        ta.value = url; ta.style.position = 'fixed'; ta.style.top = '0'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        var done = document.execCommand('copy'); document.body.removeChild(ta);
        if (done) { ok(); return true; }
      } catch (e) {}
      return false;
    };
    var fail = function () {
      if (legacyCopy()) return;                     // coba cara lawas dulu
      if (window.showToast) showToast('Tidak bisa menyalin otomatis. Salin manual dari kotak.', 'error');
      try { window.prompt('Salin URL Canvas:', url); } catch (e) {}
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(ok).catch(fail);
    } else {
      fail();
    }
  };

  // Anti-WAF (mod_security/LiteSpeed) di hosting pembeli: mode embed mengirim
  // dokumen HTML penuh (<!DOCTYPE>, <script>, <style>) yang sering diblokir 403
  // di lapisan web server SEBELUM PHP jalan. Solusi portabel tanpa sentuh server:
  // kirim HTML dalam base64 (server men-decode-nya). Plaintext textarea TIDAK
  // ikut dikirim agar tak ada token yang memicu WAF.
  // ══ VALIDASI PRA-SUBMIT + DRAF BUILDER ══════════════════════
  // Tujuan: kalau ada kesalahan setelah klik Publish, admin TETAP di editor
  // (tak terlempar ke daftar & kehilangan isian). Validasi klien menangkap
  // kasus "lupa isi field"; draf Builder di localStorage jadi jaring pengaman
  // kalau server menolak (slug bentrok/upload gagal) atau tab tertutup.
  var CANVAS_EXISTING_SLUGS = <?= json_encode(array_values(array_map('strval', array_column($canvases, 'slug')))) ?>;
  var CANVAS_RESERVED_SLUGS = ['admin','dashboard','login','register','logout','checkout','products','member','affiliate','api','assets','actions','pages','uploads','webhook','cron','canvas','go'];
  var CANVAS_FLASH_RESULT   = <?= json_encode($canvasFlashResult) ?>;
  var CANVAS_DRAFT_KEY      = 'av_canvas_builder_draft_v1';

  function cShowFormError(msgs) {
    var box = document.getElementById('canvasFormError');
    if (!box) return;
    if (!msgs || !msgs.length) { box.classList.add('hidden'); box.innerHTML = ''; return; }
    box.innerHTML = '<strong>Periksa lagi sebelum publish:</strong>'
      + '<ul style="margin:.35rem 0 0;padding-left:1.1rem;list-style:disc">'
      + msgs.map(function (m) { return '<li>' + escHtml(m) + '</li>'; }).join('') + '</ul>';
    box.classList.remove('hidden');
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function cValidateForm() {
    var errs = [];
    var isCreate = (elAction.value !== 'update');
    var pageType = elPageType.value;
    var mode     = elMode.value;

    if (!elTitle.value.trim()) errs.push('Nama Canvas wajib diisi.');
    else if (elTitle.value.trim().length > 255) errs.push('Nama Canvas maksimal 255 karakter.');

    if (isCreate) {
      var slug = (elSlug.value || '').trim().toLowerCase();
      if (!/^[a-z0-9][a-z0-9_-]{1,79}$/.test(slug)) {
        errs.push('Slug tidak valid: gunakan a-z, 0-9, dash, underscore (2–80 karakter).');
      } else if (CANVAS_RESERVED_SLUGS.indexOf(slug) !== -1) {
        errs.push('Slug "' + slug + '" sudah dipakai sistem. Pilih slug lain.');
      } else if (CANVAS_EXISTING_SLUGS.indexOf(slug) !== -1) {
        errs.push('Slug "' + slug + '" sudah dipakai Canvas lain. Pilih slug lain.');
      }
    }

    if (pageType === 'redirect') {
      var url = document.getElementById('cRedirectUrl').value.trim();
      if (!url) errs.push('URL Tujuan wajib diisi untuk tipe Redirect.');
      else if (!/^https?:\/\//i.test(url)) errs.push('URL Tujuan harus diawali http:// atau https://.');
    } else if (pageType === 'rotator') {
      if (serializeRotatorRows().length < 1) errs.push('Rotator wajib punya minimal 1 varian canvas.');
    } else {
      if (pageType === 'product_landing' && document.querySelectorAll('.cLinkedProduct:checked').length === 0) {
        errs.push('Pilih produk yang ditautkan untuk Landing Page Produk.');
      }
      if (pageType === 'lead_magnet_landing') {
        var lm = document.getElementById('cLeadMagnetId');
        if (lm && !lm.value) errs.push('Pilih lead magnet untuk Landing Page Lead Magnet.');
      }
      if (mode === 'builder') {
        var env = lbSerialize();
        if (!env.blocks || !env.blocks.length) errs.push('Visual Builder: tambahkan minimal satu blok sebelum publish.');
      } else if (mode === 'embed') {
        var ta = document.getElementById('cHtmlEmbed');
        if (ta && !ta.value.trim()) errs.push('Konten embed HTML tidak boleh kosong.');
      } else if (isCreate) { // upload, create
        var fileInp = document.querySelector('input[name="html_zip"]');
        if (fileInp && (!fileInp.files || !fileInp.files.length)) errs.push('Mode upload memerlukan file ZIP atau HTML.');
      }
    }
    return errs;
  }

  // ── Draf Builder (localStorage) ──
  function cBuilderDraftSave() {
    try {
      if (elMode.value !== 'builder' || elPageType.value === 'rotator' || elPageType.value === 'redirect') return;
      var env = lbSerialize();
      if (!env.blocks || !env.blocks.length) return;
      localStorage.setItem(CANVAS_DRAFT_KEY, JSON.stringify({
        ts: Date.now(), title: elTitle.value || '', slug: elSlug.value || '',
        action: elAction.value, id: elId.value || '', env: env
      }));
    } catch (e) {}
  }
  function cBuilderDraftClear() { try { localStorage.removeItem(CANVAS_DRAFT_KEY); } catch (e) {} }
  function cBuilderDraftGet()   { try { return JSON.parse(localStorage.getItem(CANVAS_DRAFT_KEY) || 'null'); } catch (e) { return null; } }

  var cBuilderBlockEl = document.getElementById('cBuilderBlock');
  var cDraftBar = null;
  function cEnsureDraftBar() {
    if (cDraftBar || !cBuilderBlockEl) return;
    cDraftBar = document.createElement('div');
    cDraftBar.className = 'hidden mb-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200 text-xs px-3 py-2 flex items-center justify-between gap-2';
    cDraftBar.innerHTML = '<span id="cDraftBarText" class="min-w-0"></span>'
      + '<span class="shrink-0 flex items-center gap-2">'
      + '<button type="button" id="cDraftRestore" class="font-semibold underline">Pulihkan</button>'
      + '<button type="button" id="cDraftDiscard" class="opacity-70 hover:opacity-100">Buang</button></span>';
    cBuilderBlockEl.insertBefore(cDraftBar, cBuilderBlockEl.firstChild);
    cDraftBar.querySelector('#cDraftRestore').addEventListener('click', function () {
      var d = cBuilderDraftGet();
      if (!d || !d.env) { cDraftBar.classList.add('hidden'); return; }
      if (!elTitle.value && d.title) elTitle.value = d.title;
      if (!elSlug.readOnly && !elSlug.value && d.slug) { elSlug.value = d.slug; updateSlugPreview(); }
      if (window.lbLoadEnvelope) window.lbLoadEnvelope(d.env);
      cDraftBar.classList.add('hidden');
      if (window.showToast) showToast('Desain Builder terakhir dipulihkan.', 'success');
    });
    cDraftBar.querySelector('#cDraftDiscard').addEventListener('click', function () {
      cBuilderDraftClear(); cDraftBar.classList.add('hidden');
    });
  }
  function cBuilderDraftSyncPrompt() {
    if (!cBuilderBlockEl) return;
    if (elMode.value !== 'builder' || elPageType.value === 'rotator' || elPageType.value === 'redirect') {
      if (cDraftBar) cDraftBar.classList.add('hidden'); return;
    }
    var d = cBuilderDraftGet();
    var cur = lbSerialize();
    // Jangan tawarkan bila tak ada draf, draf kosong, atau builder sedang berisi
    // (hindari menimpa kerja aktif / edit).
    if (!d || !d.env || !(d.env.blocks || []).length || (cur.blocks || []).length) {
      if (cDraftBar) cDraftBar.classList.add('hidden'); return;
    }
    cEnsureDraftBar();
    document.getElementById('cDraftBarText').textContent =
      'Ada desain Builder yang belum tersimpan' + (d.title ? ' ("' + d.title + '")' : '') + '. Pulihkan?';
    cDraftBar.classList.remove('hidden');
  }

  document.getElementById('canvasForm').addEventListener('submit', function (e) {
    // Validasi klien dulu — bila gagal, JANGAN submit (tetap di editor).
    var errs = cValidateForm();
    if (errs.length) {
      e.preventDefault();
      cShowFormError(errs);
      return;
    }
    cShowFormError([]);          // bersihkan error lama
    cBuilderDraftSave();         // simpan draf terbaru sebelum kirim (jaring pengaman)
    // Rotator: serialisasi baris varian ke hidden JSON.
    if (elPageType.value === 'rotator') {
      document.getElementById('cRotatorJson').value = JSON.stringify(serializeRotatorRows());
    }
    // Builder: serialisasi envelope → base64 (anti-WAF, konsisten dengan embed).
    if (elMode.value === 'builder' && elPageType.value !== 'rotator' && elPageType.value !== 'redirect') {
      try {
        var env = JSON.stringify(lbSerialize());
        var hJ = document.getElementById('cBuilderJson');
        hJ.value = btoa(unescape(encodeURIComponent(env)));
        var hEncB = document.createElement('input');
        hEncB.type = 'hidden'; hEncB.name = 'builder_json_encoding'; hEncB.value = 'base64';
        this.appendChild(hEncB);
      } catch (e) {
        document.getElementById('cBuilderJson').value = JSON.stringify(lbSerialize());
      }
    }
    if (document.getElementById('cMode').value !== 'embed') return;
    if (elPageType.value === 'rotator') return; // rotator tak punya konten embed
    var ta = document.getElementById('cHtmlEmbed');
    if (!ta) return;
    var enc;
    try {
      // encodeURIComponent → UTF-8 aman (em dash, dll) sebelum btoa.
      enc = btoa(unescape(encodeURIComponent(ta.value || '')));
    } catch (e) {
      return; // gagal encode → biarkan plaintext (fallback), jangan blokir submit
    }
    ta.removeAttribute('name'); // jangan kirim versi plaintext
    var f = this;
    var hEnc = document.createElement('input');
    hEnc.type = 'hidden'; hEnc.name = 'html_embed_encoding'; hEnc.value = 'base64';
    var hVal = document.createElement('input');
    hVal.type = 'hidden'; hVal.name = 'html_embed'; hVal.value = enc;
    f.appendChild(hEnc); f.appendChild(hVal);
  });

  // ══ VISUAL BUILDER ══════════════════════════════════════════
  var LB_REG      = <?= json_encode(avLbRegistry(), JSON_UNESCAPED_UNICODE) ?>;
  var LB_PRODUCTS = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $products), JSON_UNESCAPED_UNICODE) ?>;
  var lbList    = document.getElementById('lbList');
  var lbPalette = document.getElementById('lbPalette');

  function lbSvg(d) { return '<svg class="lb-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>'; }

  // Palet "tambah blok".
  if (lbPalette) {
    Object.keys(LB_REG).forEach(function (type) {
      var def = LB_REG[type];
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'lb-addbtn';
      b.innerHTML = lbSvg(def.icon) + '<span>' + escHtml(def.label) + '</span>';
      b.addEventListener('click', function () { lbAddBlock(type, null); });
      lbPalette.appendChild(b);
    });
  }

  function lbProductOptions(sel) {
    var h = '<option value="">— pilih produk —</option>';
    LB_PRODUCTS.forEach(function (p) {
      h += '<option value="' + p.id + '"' + (String(p.id) === String(sel) ? ' selected' : '') + '>' + escHtml(p.name) + '</option>';
    });
    return h;
  }

  // Bangun satu field dari schema.
  function lbBuildField(type, f, val) {
    var wrap = document.createElement('div');
    wrap.className = 'lb-f'; wrap.setAttribute('data-fkey', f.key);
    var id = 'lbf_' + Math.random().toString(36).slice(2);
    var html = '<label for="' + id + '">' + escHtml(f.label) + '</label>';
    var v = val != null ? val : '';
    if (f.type === 'textarea' || f.type === 'lines' || f.type === 'pairs') {
      html += '<textarea id="' + id + '" rows="3" placeholder="' + escHtml(f.placeholder || '') + '">' + escHtml(v) + '</textarea>';
    } else if (f.type === 'select') {
      var opts = '';
      Object.keys(f.options || {}).forEach(function (ov) {
        opts += '<option value="' + escHtml(ov) + '"' + (String(ov) === String(v) ? ' selected' : '') + '>' + escHtml(f.options[ov]) + '</option>';
      });
      html += '<select id="' + id + '">' + opts + '</select>';
    } else if (f.type === 'product') {
      html += '<select id="' + id + '">' + lbProductOptions(v) + '</select>';
    } else if (f.type === 'number') {
      html += '<input type="number" id="' + id + '" value="' + escHtml(v) + '">';
    } else if (f.type === 'image') {
      html += '<div class="lb-img-row"><input type="text" id="' + id + '" value="' + escHtml(v) + '" placeholder="URL gambar">'
            + '<button type="button" class="lb-img-pick">Pilih</button>'
            + '<img class="lb-img-thumb" alt=""></div>';
    } else { // text / url
      html += '<input type="' + (f.type === 'url' ? 'url' : 'text') + '" id="' + id + '" value="' + escHtml(v) + '" placeholder="' + escHtml(f.placeholder || '') + '">';
    }
    if (f.help) html += '<p class="lb-help">' + escHtml(f.help) + '</p>';
    wrap.innerHTML = html;

    // Media picker untuk field gambar.
    if (f.type === 'image') {
      var inp = wrap.querySelector('input'); var thumb = wrap.querySelector('.lb-img-thumb');
      var syncThumb = function () { if (inp.value) { thumb.src = inp.value; thumb.style.display = 'block'; } else { thumb.style.display = 'none'; } };
      syncThumb(); inp.addEventListener('input', syncThumb);
      wrap.querySelector('.lb-img-pick').addEventListener('click', function () {
        if (typeof window.openMediaPicker !== 'function') { if (window.showToast) showToast('Galeri media tidak tersedia.', 'error'); return; }
        window.openMediaPicker(function (insertText) {
          var m = String(insertText).match(/src=["']([^"']+)["']/i) || String(insertText).match(/https?:\/\/\S+/i);
          inp.value = m ? (m[1] || m[0]) : insertText;
          syncThumb();
        });
      });
    }
    return wrap;
  }

  // CTA: tampilkan field produk/url sesuai cta_mode.
  function lbRefreshCta(card) {
    var modeSel = card.querySelector('[data-fkey="cta_mode"] select');
    if (!modeSel) return;
    var m = modeSel.value;
    var pf = card.querySelector('[data-fkey="cta_product"]');
    var uf = card.querySelector('[data-fkey="cta_url"]');
    if (pf) pf.style.display = (m === 'product') ? '' : 'none';
    if (uf) uf.style.display = (m === 'url') ? '' : 'none';
  }

  function lbAddBlock(type, cfg) {
    var def = LB_REG[type]; if (!def) return;
    cfg = cfg || {};
    var card = document.createElement('div');
    card.className = 'lb-block'; card.setAttribute('data-type', type);
    card.innerHTML =
      '<div class="lb-bhead">' + lbSvg(def.icon) + '<span class="lb-btitle">' + escHtml(def.label) + '</span>'
      + '<button type="button" class="lb-bact lb-up" title="Naik"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg></button>'
      + '<button type="button" class="lb-bact lb-down" title="Turun"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></button>'
      + '<button type="button" class="lb-bact lb-del" title="Hapus"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2m-1 0v14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V6"/></svg></button>'
      + '</div><div class="lb-bfields"></div>';
    var fieldsWrap = card.querySelector('.lb-bfields');
    def.fields.forEach(function (f) {
      var val = (cfg[f.key] != null) ? cfg[f.key] : (def.defaults[f.key] != null ? def.defaults[f.key] : '');
      fieldsWrap.appendChild(lbBuildField(type, f, val));
    });
    // Lipat/buka via klik header (abaikan klik tombol aksi).
    card.querySelector('.lb-bhead').addEventListener('click', function (e) {
      if (e.target.closest('.lb-bact')) return;
      card.classList.toggle('lb-collapsed');
    });
    card.querySelector('.lb-del').addEventListener('click', function () { card.remove(); lbSyncEmpty(); });
    card.querySelector('.lb-up').addEventListener('click', function () {
      var prev = card.previousElementSibling; if (prev && prev.classList.contains('lb-block')) lbList.insertBefore(card, prev);
    });
    card.querySelector('.lb-down').addEventListener('click', function () {
      var next = card.nextElementSibling; if (next && next.classList.contains('lb-block')) lbList.insertBefore(next, card);
    });
    var modeSel = card.querySelector('[data-fkey="cta_mode"] select');
    if (modeSel) modeSel.addEventListener('change', function () { lbRefreshCta(card); });
    lbList.appendChild(card);
    lbRefreshCta(card);
    lbSyncEmpty();
    return card;
  }

  function lbSyncEmpty() {
    var has = lbList.querySelector('.lb-block');
    var ph = lbList.querySelector('.lb-empty');
    if (!has && !ph) { var d = document.createElement('div'); d.className = 'lb-empty'; d.textContent = 'Belum ada blok. Tambahkan dari palet di atas.'; lbList.appendChild(d); }
    else if (has && ph) ph.remove();
  }

  function lbSerialize() {
    var blocks = [];
    lbList.querySelectorAll('.lb-block').forEach(function (card) {
      var type = card.getAttribute('data-type'); var def = LB_REG[type]; if (!def) return;
      var cfg = {};
      def.fields.forEach(function (f) {
        var fw = card.querySelector('[data-fkey="' + f.key + '"]'); if (!fw) return;
        var ctrl = fw.querySelector('input,textarea,select');
        cfg[f.key] = ctrl ? ctrl.value : '';
      });
      blocks.push({ type: type, cfg: cfg });
    });
    return {
      v: 1, layout: 'mobile_first',
      theme: {
        brand: document.getElementById('lbTheme_brand').value,
        bg:    document.getElementById('lbTheme_bg').value,
        text:  document.getElementById('lbTheme_text').value,
        font:  document.getElementById('lbTheme_font').value
      },
      blocks: blocks
    };
  }

  window.lbLoadEnvelope = function (env) {
    lbList.innerHTML = '';
    env = env || {};
    var th = env.theme || {};
    if (th.brand) document.getElementById('lbTheme_brand').value = th.brand;
    document.getElementById('lbTheme_bg').value   = th.bg || '#ffffff';
    document.getElementById('lbTheme_text').value = th.text || '#0f172a';
    document.getElementById('lbTheme_font').value = th.font || 'system';
    (env.blocks || []).forEach(function (b) { if (b && b.type) lbAddBlock(b.type, b.cfg || {}); });
    lbSyncEmpty();
  };

  window.lbReset = function () {
    lbList.innerHTML = '';
    document.getElementById('lbTheme_brand').value = (<?= json_encode((function(){ $t = avLbThemeDefaults(); return $t['brand']; })()) ?> || '#6366f1');
    document.getElementById('lbTheme_bg').value   = '#ffffff';
    document.getElementById('lbTheme_text').value = '#0f172a';
    document.getElementById('lbTheme_font').value = 'system';
    lbSyncEmpty();
  };

  // Pratinjau: POST envelope ke endpoint admin → render ke iframe.
  var lbPreviewModal = document.getElementById('lbPreviewModal');
  function lbClosePreview() { lbPreviewModal.style.display = 'none'; lbPreviewModal.classList.add('hidden'); }
  document.getElementById('lbPreviewClose').addEventListener('click', lbClosePreview);
  lbPreviewModal.addEventListener('click', function (e) { if (e.target === lbPreviewModal) lbClosePreview(); });
  document.getElementById('lbPreviewBtn').addEventListener('click', function () {
    var env = lbSerialize();
    var fd = new FormData();
    fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token']) ?>);
    fd.append('title', (elTitle.value || 'Pratinjau'));
    fd.append('builder_json', JSON.stringify(env));
    fetch(<?= json_encode($appBase . '/actions/admin/canvas-builder-preview') ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        document.getElementById('lbPreviewFrame').srcdoc = html;
        lbPreviewModal.classList.remove('hidden'); lbPreviewModal.style.display = 'flex';
      })
      .catch(function () { if (window.showToast) showToast('Gagal memuat pratinjau.', 'error'); });
  });

  // Init.
  lbSyncEmpty();
  toggleProductBlock();
  toggleRotatorUI();
  toggleModeBlocks();
  updateSlugPreview();

  // ── Draf Builder: bersihkan bila publish terakhir SUKSES; autosave saat edit;
  //    tawarkan pulihkan saat masuk mode builder dgn kanvas kosong. ──
  if (CANVAS_FLASH_RESULT === 'success') cBuilderDraftClear();
  var cDraftTimer = null;
  function cDraftAutosave() { clearTimeout(cDraftTimer); cDraftTimer = setTimeout(cBuilderDraftSave, 600); }
  if (lbList) {
    lbList.addEventListener('input', cDraftAutosave);
    lbList.addEventListener('change', cDraftAutosave);
    // Tambah/hapus/urut blok → simpan juga.
    try { new MutationObserver(cDraftAutosave).observe(lbList, { childList: true }); } catch (e) {}
  }
  ['lbTheme_brand', 'lbTheme_bg', 'lbTheme_text', 'lbTheme_font'].forEach(function (id) {
    var el = document.getElementById(id); if (el) el.addEventListener('change', cDraftAutosave);
  });
  elMode.addEventListener('change', cBuilderDraftSyncPrompt);
  elPageType.addEventListener('change', cBuilderDraftSyncPrompt);
  cBuilderDraftSyncPrompt();
})();
</script>

<?php include __DIR__ . '/../../partials/media-picker.php'; ?>
<script>
// Sisipkan gambar dari Galeri Media ke textarea Embed HTML (di posisi kursor).
(function () {
  var btn = document.getElementById('cInsertImage');
  var ta  = document.getElementById('cHtmlEmbed');
  if (!btn || !ta || typeof window.openMediaPicker !== 'function') return;
  btn.addEventListener('click', function () {
    window.openMediaPicker(function (insertText) {
      var start = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
      var end   = ta.selectionEnd != null ? ta.selectionEnd : ta.value.length;
      ta.value = ta.value.slice(0, start) + insertText + ta.value.slice(end);
      var pos = start + insertText.length;
      ta.focus();
      try { ta.setSelectionRange(pos, pos); } catch (e) {}
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
})();
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
