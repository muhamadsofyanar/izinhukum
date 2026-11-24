<?php
// ════════════════════════════════════════════════════════════════
// Galeri Media — hosting gambar internal (landing/konten). Upload, salin URL/
// embed, alt, hapus. Route: /admin/media.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pdo = getDB();
$q   = trim($_GET['q'] ?? '');
$gid = (int) ($_GET['group'] ?? 0); // filter grup; 0 = semua

$items = [];
$groups = [];
$totalBytes = 0; $totalCount = 0; $largeCount = 0;
try {
    $where = []; $params = [];
    if ($q !== '') { $where[] = '(original_name LIKE ? OR alt LIKE ?)'; $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; }
    if ($gid > 0) { $where[] = 'group_id = ?'; $params[] = $gid; }
    $sql = 'SELECT * FROM media_files' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll();
    $agg = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(size),0) b FROM media_files")->fetch();
    $totalCount = (int) ($agg['c'] ?? 0);
    $totalBytes = (int) ($agg['b'] ?? 0);
    // Jumlah gambar yang masih > 400 KB & bisa dikompres (GIF dikecualikan) — utk tombol kompres massal.
    $largeCount = (int) $pdo->query("SELECT COUNT(*) FROM media_files WHERE size > " . (400 * 1024) . " AND mime IN ('image/jpeg','image/png','image/webp')")->fetchColumn();
} catch (Exception $e) {
    error_log('Media list error: ' . $e->getMessage());
}
// Daftar grup + jumlah gambar per grup. Fail-soft bila tabel belum ada (pra-migrasi).
try {
    $groups = $pdo->query(
        "SELECT g.id, g.name, (SELECT COUNT(*) FROM media_files mf WHERE mf.group_id = g.id) AS cnt
         FROM media_groups g ORDER BY g.name ASC"
    )->fetchAll();
} catch (Exception $e) { $groups = []; }
// Nama grup aktif (untuk label tombol salin-semua).
$activeGroupName = '';
foreach ($groups as $g) { if ((int) $g['id'] === $gid) { $activeGroupName = (string) $g['name']; break; } }

$csrf = generateCSRF();
$base = rtrim(APP_URL, '/');
// Batas efektif nyata = lebih kecil antara cap aplikasi (8MB, harus sinkron dgn
// upload-media.php/replace-media.php) dan batas PHP hosting (post_max_size/
// upload_max_filesize) — supaya UI tak menjanjikan ukuran yang server tak sanggup.
$mediaAppCap    = 8 * 1024 * 1024;
$mediaSrvLimit  = phpUploadLimitBytes();
$mediaEffLimit  = $mediaSrvLimit > 0 ? min($mediaSrvLimit, $mediaAppCap) : $mediaAppCap;
$mediaBaseUrl = rtrim(UPLOAD_URL, '/') . '/media/';
$pageTitle   = 'Galeri Media';
$topbarTitle = 'Galeri Media';

function media_human_size(int $b): string {
    if ($b >= 1073741824) return number_format($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return number_format($b / 1024, 0) . ' KB';
    return $b . ' B';
}
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<style>
  .media-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
  @media (min-width: 640px)  { .media-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  @media (min-width: 1024px) { .media-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
  @media (min-width: 1440px) { .media-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
  .media-thumb { aspect-ratio: 1 / 1; background-image:
      linear-gradient(45deg, #f1f5f9 25%, transparent 25%), linear-gradient(-45deg, #f1f5f9 25%, transparent 25%),
      linear-gradient(45deg, transparent 75%, #f1f5f9 75%), linear-gradient(-45deg, transparent 75%, #f1f5f9 75%);
    background-size: 16px 16px; background-position: 0 0, 0 8px, 8px -8px, -8px 0; }
  .dark .media-thumb { background-color: #0f172a; background-image:
      linear-gradient(45deg, #1e293b 25%, transparent 25%), linear-gradient(-45deg, #1e293b 25%, transparent 25%),
      linear-gradient(45deg, transparent 75%, #1e293b 75%), linear-gradient(-45deg, transparent 75%, #1e293b 75%); }
  .dropzone.dragover { border-color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 8%, transparent); }
  .dropzone:focus-visible { outline: 2px solid var(--brand-color); outline-offset: 3px; }
</style>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Header -->
      <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Galeri Media</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">
            Unggah gambar sekali, pakai di mana pun lewat URL di domain Anda sendiri.
            Cocok untuk Canvas landing page — tak perlu lagi upload ke layanan luar.
          </p>
        </div>
        <div class="text-right shrink-0">
          <p class="text-xs text-gray-400 uppercase tracking-wide">Pemakaian</p>
          <p class="text-sm font-semibold text-gray-900 dark:text-white"><span id="mCount"><?= $totalCount ?></span> gambar · <span id="mSize"><?= media_human_size($totalBytes) ?></span></p>
          <button type="button" id="mCompressAll" data-count="<?= (int) $largeCount ?>"
                  class="mt-2 inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-xs font-semibold border transition <?= $largeCount > 0 ? 'text-white bg-brand border-transparent hover:opacity-90' : 'text-gray-400 border-gray-200 dark:border-gray-700 cursor-default' ?>"
                  <?= $largeCount > 0 ? '' : 'disabled' ?>
                  title="Kompres semua gambar yang masih di atas 400KB">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
            <span id="mCompressLabel"><?= $largeCount > 0 ? ('Kompres ' . (int) $largeCount . ' gambar besar') : 'Semua ≤ 400KB' ?></span>
          </button>
        </div>
      </div>

      <!-- Grup Media -->
      <div class="mb-4">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mr-1">Grup</span>
          <a href="<?= e($base) ?>/admin/media<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"
             class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-xs font-medium border transition <?= $gid === 0 ? 'bg-brand text-white border-transparent' : 'text-gray-700 dark:text-gray-200 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
            Semua <span class="opacity-70"><?= (int) $totalCount ?></span>
          </a>
          <?php foreach ($groups as $g): $ga = ((int) $g['id'] === $gid); ?>
          <a href="<?= e($base) ?>/admin/media?group=<?= (int) $g['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
             class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-xs font-medium border transition <?= $ga ? 'bg-brand text-white border-transparent' : 'text-gray-700 dark:text-gray-200 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
            <?= e($g['name']) ?> <span class="opacity-70"><?= (int) $g['cnt'] ?></span>
          </a>
          <?php endforeach; ?>
          <!-- Buat grup -->
          <span class="inline-flex items-center gap-1.5">
            <input type="text" id="mNewGroup" maxlength="100" placeholder="Nama grup baru…"
                   class="h-8 px-2.5 w-40 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs text-gray-900 dark:text-white">
            <button type="button" id="mCreateGroup" class="inline-flex items-center gap-1 h-8 px-2.5 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Buat grup">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Buat
            </button>
          </span>
        </div>
        <?php if ($gid > 0): ?>
        <div class="flex flex-wrap items-center gap-2 mt-2.5">
          <button type="button" id="mCopyAll" data-group="<?= $gid ?>" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            Salin semua URL grup &ldquo;<?= e($activeGroupName) ?>&rdquo;
          </button>
          <button type="button" id="mDeleteGroup" data-group="<?= $gid ?>" class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-xs font-medium text-red-600 border border-gray-200 dark:border-gray-700 hover:bg-red-50 transition" title="Hapus grup (gambar tetap, kembali ke Tanpa grup)">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg>
            Hapus grup
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- Unggah ke grup -->
      <div class="flex items-center gap-2 mb-2">
        <label for="mUploadGroup" class="text-xs font-medium text-gray-500 dark:text-gray-400">Unggah ke grup:</label>
        <select id="mUploadGroup" class="h-8 px-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs text-gray-900 dark:text-white">
          <option value="0">Tanpa grup</option>
          <?php foreach ($groups as $g): ?>
          <option value="<?= (int) $g['id'] ?>" <?= ((int) $g['id'] === $gid) ? 'selected' : '' ?>><?= e($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Dropzone -->
      <label id="dropzone" tabindex="0" role="button" aria-label="Unggah gambar" aria-describedby="mPasteHint" class="dropzone block cursor-pointer rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 p-6 text-center mb-5 transition">
        <input type="file" id="mFile" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="hidden">
        <div class="flex flex-col items-center gap-2 pointer-events-none">
          <span class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent)">
            <svg class="w-6 h-6" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 20h16"/></svg>
          </span>
          <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Seret &amp; lepas gambar ke sini, atau klik untuk pilih</p>
          <p id="mPasteHint" class="text-xs text-gray-400">Atau salin gambar / screenshot, lalu tekan Ctrl+V (Mac: Cmd+V) di halaman ini untuk langsung mengunggah.</p>
          <p class="text-xs text-gray-400">JPG, PNG, WEBP, GIF · maks <?= e(formatFileSize($mediaEffLimit)) ?> · otomatis dikompres</p>
          <?php if ($mediaSrvLimit > 0 && $mediaSrvLimit < $mediaAppCap): ?>
          <p class="text-[11px] text-gray-400">Batas ini mengikuti pengaturan PHP hosting (post_max_size / upload_max_filesize).</p>
          <?php endif; ?>
        </div>
      </label>
      <div id="mUploadStatus" role="status" aria-live="polite" class="hidden mb-4 text-sm text-gray-500 dark:text-gray-400"></div>

      <!-- Cari -->
      <form method="GET" action="<?= e($base) ?>/admin/media" class="flex items-center gap-2 mb-4 max-w-md">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama berkas / alt…"
               class="flex-1 h-9 px-3 border border-gray-200 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-white">
        <button type="submit" class="px-4 h-9 rounded-lg text-sm font-medium text-white bg-brand hover:opacity-90">Cari</button>
        <?php if ($q !== ''): ?><a href="<?= e($base) ?>/admin/media" class="px-3 h-9 inline-flex items-center text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Reset</a><?php endif; ?>
      </form>

      <!-- Empty state -->
      <div id="mEmpty" class="<?= empty($items) ? '' : 'hidden' ?> bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-10 text-center">
        <p class="text-base font-semibold text-gray-900 dark:text-white"><?= $q !== '' ? 'Tak ada gambar cocok.' : 'Belum ada gambar' ?></p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?= $q !== '' ? 'Coba kata kunci lain.' : 'Unggah gambar pertama Anda lewat kotak di atas.' ?></p>
      </div>

      <!-- Grid -->
      <div id="mGrid" class="media-grid">
        <?php foreach ($items as $m):
          $url = $mediaBaseUrl . rawurlencode($m['filename']);
          $dim = ($m['width'] && $m['height']) ? ((int) $m['width'] . '×' . (int) $m['height']) : '';
        ?>
        <div class="media-card group bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden flex flex-col"
             data-id="<?= (int) $m['id'] ?>" data-url="<?= e($url) ?>">
          <div class="media-thumb relative">
            <img src="<?= e($url) ?>" alt="<?= e((string) ($m['alt'] ?? '')) ?>" loading="lazy" class="absolute inset-0 w-full h-full object-contain">
          </div>
          <div class="p-2.5 space-y-2">
            <div class="flex items-center gap-1.5">
              <button type="button" data-act="url" class="flex-1 inline-flex items-center justify-center gap-1.5 h-8 rounded-lg text-xs font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Salin URL">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                URL
              </button>
              <button type="button" data-act="embed" class="flex-1 inline-flex items-center justify-center gap-1.5 h-8 rounded-lg text-xs font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Salin embed &lt;img&gt;">
                &lt;img&gt;
              </button>
              <button type="button" data-act="replace" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Ganti gambar (URL/embed tetap)">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
              </button>
              <input type="file" data-act="replace-input" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
              <button type="button" data-act="del" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-red-600 border border-gray-200 dark:border-gray-700 hover:bg-red-50 transition" title="Hapus">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg>
              </button>
            </div>
            <input type="text" data-act="alt" value="<?= e((string) ($m['alt'] ?? '')) ?>" placeholder="alt text (SEO/aksesibilitas)"
                   class="w-full h-7 px-2 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-xs text-gray-700 dark:text-gray-200">
            <p class="text-xs text-gray-400 truncate"><?= $dim ? e($dim) . ' · ' : '' ?><?= e(media_human_size((int) $m['size'])) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrf) ?>;
  var base = <?= json_encode($base) ?>;
  var EFF_LIMIT = <?= (int) $mediaEffLimit ?>; // batas efektif nyata (app cap vs PHP hosting)
  var CUR_GROUP = <?= (int) $gid ?>; // 0 = tampil Semua
  var grid = document.getElementById('mGrid');
  var empty = document.getElementById('mEmpty');
  var dz = document.getElementById('dropzone');
  var fileInput = document.getElementById('mFile');
  var statusEl = document.getElementById('mUploadStatus');
  var uploadGroupSel = document.getElementById('mUploadGroup');

  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
  function copy(text) { navigator.clipboard.writeText(text).then(function () { showToast('Disalin ke clipboard', 'success'); }, function () { showToast('Gagal menyalin', 'error'); }); }
  function humanSize(b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
  }

  // ── Delegasi aksi kartu ──
  grid.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-act]');
    if (!btn) return;
    var card = btn.closest('.media-card'); if (!card) return;
    var act = btn.getAttribute('data-act');
    var url = card.getAttribute('data-url');
    if (act === 'url') copy(url);
    else if (act === 'embed') { var alt = (card.querySelector('[data-act="alt"]') || {}).value || ''; copy('<img src="' + url + '" alt="' + alt.replace(/"/g, '&quot;') + '" loading="lazy">'); }
    else if (act === 'del') deleteCard(card);
    else if (act === 'replace') { var fi = card.querySelector('input[data-act="replace-input"]'); if (fi) fi.click(); }
  });
  grid.addEventListener('change', function (e) {
    var inp = e.target.closest('input[data-act="alt"]');
    if (inp) { saveAlt(inp.closest('.media-card'), inp.value); return; }
    var rf = e.target.closest('input[data-act="replace-input"]');
    if (rf && rf.files && rf.files[0]) { replaceCard(rf.closest('.media-card'), rf.files[0]); rf.value = ''; }
  });

  function replaceCard(card, file) {
    if (!file) return;
    if (file.size > EFF_LIMIT) { showToast('Ukuran maksimal ' + humanSize(EFF_LIMIT), 'error'); return; }
    var img = card.querySelector('.media-thumb img');
    if (img) img.style.opacity = '0.4';
    var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('id', card.getAttribute('data-id')); fd.append('file', file);
    fetch(base + '/actions/admin/replace-media', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.ok) {
          // URL/embed TETAP sama; refresh preview admin via cache-bust (embed publik tetap URL bersih).
          if (img) { img.src = res.media.url + '?t=' + (res.media.cachebust || Date.now()); img.style.opacity = ''; }
          showToast('Gambar diganti — URL & embed tetap sama', 'success');
        } else { if (img) img.style.opacity = ''; showToast((res && res.message) || 'Gagal mengganti gambar', 'error'); }
      }).catch(function () { if (img) img.style.opacity = ''; showToast('Gagal terhubung', 'error'); });
  }

  function saveAlt(card, alt) {
    var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('id', card.getAttribute('data-id')); fd.append('alt', alt);
    fetch(base + '/actions/admin/update-media-alt', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (res) { if (res && res.ok) showToast('Alt disimpan', 'success'); }).catch(function () {});
  }
  function deleteCard(card) {
    confirmAndRun({ title: 'Hapus gambar?', message: 'Gambar akan dihapus permanen dari server. Tautan yang sudah dipasang akan rusak.', confirmText: 'Ya, Hapus', variant: 'danger' }, function () {
      var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('id', card.getAttribute('data-id'));
      fetch(base + '/actions/admin/delete-media', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (res) {
          if (res && res.ok) { card.remove(); showToast('Gambar dihapus', 'success'); if (!grid.children.length) empty.classList.remove('hidden'); }
          else showToast((res && res.message) || 'Gagal menghapus', 'error');
        }).catch(function () { showToast('Gagal terhubung', 'error'); });
    });
  }

  // ── Kartu baru dari hasil upload ──
  function prependCard(m) {
    empty.classList.add('hidden');
    var el = document.createElement('div');
    el.className = 'media-card group bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden flex flex-col';
    el.setAttribute('data-id', m.id); el.setAttribute('data-url', m.url);
    var dim = (m.width && m.height) ? (m.width + '×' + m.height + ' · ') : '';
    el.innerHTML =
      '<div class="media-thumb relative"><img src="' + esc(m.url) + '" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-contain"></div>'
      + '<div class="p-2.5 space-y-2"><div class="flex items-center gap-1.5">'
      + '<button type="button" data-act="url" class="flex-1 inline-flex items-center justify-center gap-1.5 h-8 rounded-lg text-xs font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">URL</button>'
      + '<button type="button" data-act="embed" class="flex-1 inline-flex items-center justify-center gap-1.5 h-8 rounded-lg text-xs font-medium text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">&lt;img&gt;</button>'
      + '<button type="button" data-act="replace" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition" title="Ganti gambar (URL/embed tetap)"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg></button>'
      + '<input type="file" data-act="replace-input" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">'
      + '<button type="button" data-act="del" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-red-600 border border-gray-200 dark:border-gray-700 hover:bg-red-50 transition"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></button>'
      + '</div><input type="text" data-act="alt" value="" placeholder="alt text (SEO/aksesibilitas)" class="w-full h-7 px-2 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-xs text-gray-700 dark:text-gray-200">'
      + '<p class="text-xs text-gray-400 truncate">' + dim + esc(humanSize(m.size)) + '</p></div>';
    grid.insertBefore(el, grid.firstChild);
    // Update ringkasan
    var c = document.getElementById('mCount'); if (c) c.textContent = (parseInt(c.textContent, 10) || 0) + 1;
  }

  // ── Upload (dropzone + input) ──
  function uploadFiles(files) {
    var all = Array.prototype.slice.call(files).filter(function (f) { return /^image\//.test(f.type); });
    var list = all.filter(function (f) { return f.size <= EFF_LIMIT; });
    var oversize = all.length - list.length;
    if (oversize > 0) showToast(oversize + ' file dilewati (>' + humanSize(EFF_LIMIT) + ')', 'error');
    if (!list.length) return;
    var done = 0, ok = 0;
    statusEl.classList.remove('hidden');
    statusEl.textContent = 'Mengunggah 0/' + list.length + '…';
    var targetGroup = uploadGroupSel ? (uploadGroupSel.value || '0') : '0';
    list.forEach(function (f) {
      var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('file', f); fd.append('group_id', targetGroup);
      fetch(base + '/actions/admin/upload-media', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok && res.media) {
            // Tampilkan kartu baru hanya bila cocok dengan filter grup aktif (atau "Semua"),
            // supaya tak muncul di view grup yang berbeda. Total "Pemakaian" tetap naik.
            if (CUR_GROUP === 0 || String(CUR_GROUP) === String(targetGroup)) { prependCard(res.media); }
            else { var c = document.getElementById('mCount'); if (c) c.textContent = (parseInt(c.textContent, 10) || 0) + 1; }
            ok++;
          } else { showToast((res && res.message) || 'Gagal unggah ' + f.name, 'error'); }
        })
        .catch(function () { showToast('Gagal terhubung saat unggah', 'error'); })
        .finally(function () {
          done++; statusEl.textContent = 'Mengunggah ' + done + '/' + list.length + '…';
          if (done === list.length) { statusEl.textContent = ok + ' gambar terunggah.'; setTimeout(function () { statusEl.classList.add('hidden'); }, 2500); }
        });
    });
  }
  fileInput.addEventListener('change', function () { uploadFiles(this.files); this.value = ''; });
  ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('dragover'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); if (ev !== 'dragleave' || e.target === dz) dz.classList.remove('dragover'); }); });
  dz.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) uploadFiles(e.dataTransfer.files); });

  // Tempel gambar di halaman galeri tanpa perlu hover atau fokus khusus.
  // Kolom teks dan dialog tetap memakai paste biasa.
  dz.addEventListener('click', function () { dz.focus(); });
  dz.addEventListener('keydown', function (e) {
    if (e.target === dz && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      fileInput.click();
    }
  });
  document.addEventListener('paste', function (e) {
    if (e.defaultPrevented || !e.clipboardData) return;
    var protectedSelector = 'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="dialog"], [aria-modal="true"]';
    var target = e.target;
    var active = document.activeElement;
    if ((target && target.closest && target.closest(protectedSelector)) ||
        (active && active.closest && active.closest(protectedSelector))) return;
    var files = [];
    Array.prototype.forEach.call(e.clipboardData.items || [], function (item) {
      if (item.kind !== 'file' || !/^image\//.test(item.type)) return;
      var file = item.getAsFile();
      if (file) files.push(file);
    });
    // Fallback untuk browser yang hanya menyediakan daftar files.
    if (!files.length) {
      files = Array.prototype.slice.call(e.clipboardData.files || []).filter(function (file) { return /^image\//.test(file.type); });
    }
    if (!files.length) return; // Teks, URL, dan HTML bukan gambar clipboard.
    e.preventDefault();
    uploadFiles(files);
  });

  // ── Grup media ──
  function mgPost(fields) {
    var fd = new FormData(); fd.append('csrf_token', CSRF);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(base + '/actions/admin/media-group', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  var createBtn = document.getElementById('mCreateGroup');
  var newGroupInput = document.getElementById('mNewGroup');
  function createGroup() {
    var name = (newGroupInput.value || '').trim();
    if (!name) { showToast('Isi nama grup dulu', 'error'); newGroupInput.focus(); return; }
    mgPost({ action: 'create', name: name }).then(function (res) {
      if (res && res.ok && res.group) { showToast('Grup dibuat', 'success'); window.location.href = base + '/admin/media?group=' + res.group.id; }
      else showToast((res && res.message) || 'Gagal membuat grup', 'error');
    }).catch(function () { showToast('Gagal terhubung', 'error'); });
  }
  if (createBtn && newGroupInput) {
    createBtn.addEventListener('click', createGroup);
    newGroupInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); createGroup(); } });
  }
  var copyAllBtn = document.getElementById('mCopyAll');
  if (copyAllBtn) copyAllBtn.addEventListener('click', function () {
    mgPost({ action: 'urls', group_id: copyAllBtn.getAttribute('data-group') }).then(function (res) {
      if (res && res.ok) {
        if (!res.count) { showToast('Grup ini belum punya gambar', 'error'); return; }
        copy(res.text); showToast(res.count + ' URL disalin (satu per baris)', 'success');
      } else showToast((res && res.message) || 'Gagal menyalin', 'error');
    }).catch(function () { showToast('Gagal terhubung', 'error'); });
  });
  var delGroupBtn = document.getElementById('mDeleteGroup');
  if (delGroupBtn) delGroupBtn.addEventListener('click', function () {
    confirmAndRun({ title: 'Hapus grup?', message: 'Grup akan dihapus. Gambar di dalamnya TIDAK ikut terhapus — hanya kembali ke "Tanpa grup".', confirmText: 'Ya, Hapus Grup', variant: 'danger' }, function () {
      mgPost({ action: 'delete', id: delGroupBtn.getAttribute('data-group') }).then(function (res) {
        if (res && res.ok) { showToast('Grup dihapus', 'success'); window.location.href = base + '/admin/media'; }
        else showToast((res && res.message) || 'Gagal menghapus grup', 'error');
      }).catch(function () { showToast('Gagal terhubung', 'error'); });
    });
  });

  // ── Kompres massal gambar > 400KB (ber-cursor, tahan timeout) ──
  var compBtn = document.getElementById('mCompressAll');
  var compLabel = document.getElementById('mCompressLabel');
  async function runBulkCompress() {
    compBtn.disabled = true;
    var after = 0, tProc = 0, tComp = 0, tSaved = 0, guard = 0;
    statusEl.classList.remove('hidden');
    statusEl.textContent = 'Menyiapkan kompres massal…';
    while (guard++ < 10000) {
      var fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('after_id', after);
      fd.append('limit', '3');
      var d;
      try {
        var r = await fetch(base + '/actions/admin/compress-media-bulk', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        d = await r.json();
      } catch (e) { showToast('Gangguan jaringan saat mengompres.', 'error'); break; }
      if (!d || !d.ok) { showToast((d && d.message) || 'Gagal mengompres.', 'error'); break; }
      tProc += d.processed; tComp += d.compressed; tSaved += d.saved; after = d.last_id;
      if (compLabel) compLabel.textContent = 'Mengompres… ' + tProc;
      statusEl.textContent = 'Kompres massal: ' + tProc + ' diproses, ' + tComp + ' dikompres, hemat ' + humanSize(tSaved) + '…';
      if (d.done) break;
    }
    statusEl.textContent = 'Selesai — ' + tComp + ' gambar dikompres, hemat ' + humanSize(tSaved) + '.';
    showToast(tComp + ' gambar dikompres · hemat ' + humanSize(tSaved), 'success');
    setTimeout(function () { window.location.reload(); }, 1400);
  }
  if (compBtn && !compBtn.disabled) compBtn.addEventListener('click', function () {
    confirmAndRun({
      title: 'Kompres gambar besar?',
      message: 'Semua gambar yang masih di atas 400KB akan dikompres (ukuran turun, URL & embed tetap sama). Berkas lama ditimpa — proses ini tidak bisa dibatalkan. Lanjutkan?',
      confirmText: 'Ya, Kompres',
      variant: 'warning'
    }, runBulkCompress);
  });
})();
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
