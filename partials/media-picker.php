<?php
// Modal Picker Galeri Media — reusable. Sertakan partial ini SEKALI di halaman,
// lalu panggil window.openMediaPicker(function(insertText, img){ ... }).
// insertText = <img …> atau URL sesuai pilihan; img = {url,alt,width,height}.
// Butuh app.js (showToast) + user admin. Guard anti double-include.
if (!defined('MEDIA_PICKER_INCLUDED')) {
    define('MEDIA_PICKER_INCLUDED', 1);
    $mpCsrf = generateCSRF();
    $mpBase = rtrim(APP_URL, '/');
?>
<style>
  #mediaPicker { position: fixed; inset: 0; z-index: 70; display: none; }
  #mediaPicker.open { display: flex; align-items: center; justify-content: center; padding: 16px; }
  #mediaPicker .mp-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
  #mediaPicker .mp-panel { position: relative; width: 100%; max-width: 780px; max-height: 88vh; display: flex; flex-direction: column; }
  .mp-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; }
  @media (min-width: 640px) { .mp-grid { grid-template-columns: repeat(4, minmax(0,1fr)); } }
  @media (min-width: 900px) { .mp-grid { grid-template-columns: repeat(5, minmax(0,1fr)); } }
  .mp-cell { aspect-ratio: 1/1; border-radius: 10px; overflow: hidden; border: 2px solid transparent; cursor: pointer; position: relative; background: #f1f5f9; }
  .dark .mp-cell { background: #1e293b; }
  .mp-cell:hover { border-color: var(--brand-color); }
  .mp-cell img { width: 100%; height: 100%; object-fit: contain; }
  #mpDrop.dragover { border-color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 8%, transparent); }
</style>
<div id="mediaPicker" aria-hidden="true">
  <div class="mp-backdrop" data-mp-close></div>
  <div class="mp-panel bg-white dark:bg-gray-900 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-800">
    <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
      <h3 class="text-base font-semibold text-gray-900 dark:text-white">Pilih Gambar</h3>
      <button type="button" data-mp-close class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-3">
      <label id="mpDrop" style="min-width:180px" class="flex-1 cursor-pointer rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-700 px-3 py-2 text-center text-xs text-gray-500 dark:text-gray-400 hover:border-gray-300 transition">
        <input type="file" id="mpFile" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="hidden">
        Seret gambar ke sini / klik untuk unggah
      </label>
      <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
        Sisipkan:
        <label class="inline-flex items-center gap-1 cursor-pointer"><input type="radio" name="mpFormat" value="img" checked class="text-brand focus:ring-brand"> &lt;img&gt;</label>
        <label class="inline-flex items-center gap-1 cursor-pointer"><input type="radio" name="mpFormat" value="url" class="text-brand focus:ring-brand"> URL</label>
      </div>
    </div>
    <div class="p-5 overflow-y-auto">
      <div id="mpStatus" class="hidden mb-3 text-xs text-gray-500 dark:text-gray-400"></div>
      <div id="mpEmpty" class="hidden text-center py-10 text-sm text-gray-400">Belum ada gambar. Unggah lewat kotak di atas.</div>
      <div id="mpGrid" class="mp-grid"></div>
    </div>
  </div>
</div>
<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($mpCsrf) ?>;
  var base = <?= json_encode($mpBase) ?>;
  var modal = document.getElementById('mediaPicker');
  var grid = document.getElementById('mpGrid');
  var emptyEl = document.getElementById('mpEmpty');
  var statusEl = document.getElementById('mpStatus');
  var fileInput = document.getElementById('mpFile');
  var drop = document.getElementById('mpDrop');
  var onPick = null;

  function fmt() { var r = modal.querySelector('input[name="mpFormat"]:checked'); return r ? r.value : 'img'; }
  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
  function buildInsert(img) {
    if (fmt() === 'url') return img.url;
    var alt = (img.alt || '').replace(/"/g, '&quot;');
    return '<img src="' + img.url + '" alt="' + alt + '" loading="lazy">';
  }

  function cell(img) {
    var c = document.createElement('button');
    c.type = 'button'; c.className = 'mp-cell'; c.title = 'Klik untuk sisipkan';
    c.innerHTML = '<img src="' + esc(img.url) + '" alt="' + esc(img.alt || '') + '" loading="lazy">';
    c.addEventListener('click', function () {
      if (typeof onPick === 'function') onPick(buildInsert(img), img);
      close();
    });
    return c;
  }

  function render(items) {
    grid.innerHTML = '';
    emptyEl.classList.toggle('hidden', items.length > 0);
    items.forEach(function (img) { grid.appendChild(cell(img)); });
  }

  function load() {
    grid.innerHTML = '<p class="text-xs text-gray-400">Memuat…</p>';
    fetch(base + '/actions/admin/list-media', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) { render((res && res.items) || []); })
      .catch(function () { grid.innerHTML = '<p class="text-xs text-red-500">Gagal memuat gambar.</p>'; });
  }

  function uploadFiles(files) {
    var list = Array.prototype.slice.call(files).filter(function (f) { return /^image\//.test(f.type); });
    if (!list.length) return;
    var done = 0;
    statusEl.classList.remove('hidden'); statusEl.textContent = 'Mengunggah 0/' + list.length + '…';
    var fresh = [];
    list.forEach(function (f) {
      var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('file', f);
      fetch(base + '/actions/admin/upload-media', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res && res.ok && res.media) fresh.push(res.media); else if (window.showToast) showToast((res && res.message) || 'Gagal unggah', 'error'); })
        .catch(function () { if (window.showToast) showToast('Gagal terhubung', 'error'); })
        .finally(function () {
          done++; statusEl.textContent = 'Mengunggah ' + done + '/' + list.length + '…';
          if (done === list.length) {
            fresh.forEach(function (img) { grid.insertBefore(cell(img), grid.firstChild); });
            if (fresh.length) emptyEl.classList.add('hidden');
            statusEl.textContent = fresh.length + ' gambar terunggah.';
            setTimeout(function () { statusEl.classList.add('hidden'); }, 2000);
          }
        });
    });
  }

  fileInput.addEventListener('change', function () { uploadFiles(this.files); this.value = ''; });
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragover'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('dragover'); }); });
  drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) uploadFiles(e.dataTransfer.files); });

  function open(cb) { onPick = cb; modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); load(); }
  function close() { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); onPick = null; }
  modal.querySelectorAll('[data-mp-close]').forEach(function (el) { el.addEventListener('click', close); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) close(); });

  window.openMediaPicker = open;
})();
</script>
<?php } ?>
