<?php
// Toast "tersimpan" global + interceptor AJAX untuk form ber-class `js-ajax-save`.
// Sertakan sekali di halaman (sebelum footer). Form otomatis submit tanpa reload/
// loncat ke atas; hasilnya tampil sebagai toast warna brand yang lembut & premium.
// Action-nya cukup memakai helper saveResult()/wantsJsonResponse() (balas JSON saat AJAX).
?>
<div id="save-toast" role="status" aria-live="polite" style="display:none;opacity:0;transform:translateY(8px)"
     class="fixed bottom-6 right-6 z-[60] flex items-center gap-3 pl-3 pr-5 py-3 rounded-2xl border shadow-xl text-sm font-medium
            bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-800 text-gray-800 dark:text-gray-100 transition-all duration-300">
  <span id="save-toast-icon" class="w-8 h-8 rounded-full flex items-center justify-center shrink-0"></span>
  <span id="save-toast-msg"></span>
</div>
<script>
(function () {
  var toast = document.getElementById('save-toast');
  if (!toast || toast._wired) return;
  toast._wired = true;
  var iconEl = document.getElementById('save-toast-icon');
  var msgEl  = document.getElementById('save-toast-msg');

  var CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
  var CROSS = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

  function hide() {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(8px)';
    clearTimeout(toast._show);
    toast._show = setTimeout(function () { toast.style.display = 'none'; }, 300);
  }
  function show(type, message) {
    var ok = type === 'success';
    // Sukses = tint brand lembut (bukan hijau pekat); error = merah lembut.
    iconEl.style.background = ok ? 'color-mix(in srgb, var(--brand-color) 14%, transparent)' : 'rgba(239,68,68,.12)';
    iconEl.style.color      = ok ? 'var(--brand-color)' : '#ef4444';
    iconEl.innerHTML        = ok ? CHECK : CROSS;
    msgEl.textContent       = message;
    toast.style.display     = 'flex';
    void toast.offsetHeight;               // reflow → transisi jalan
    toast.style.opacity     = '1';
    toast.style.transform   = 'translateY(0)';
    clearTimeout(toast._hide);
    toast._hide = setTimeout(hide, 3000);
  }
  window.showSaveToast = show;

  document.querySelectorAll('form.js-ajax-save').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      // Simpan posisi scroll agar tak loncat (container <main> + window cadangan).
      var scroller = form.closest('main') || document.querySelector('main');
      var sPos = scroller ? scroller.scrollTop : 0;
      var wPos = window.scrollY;

      var btn = form.querySelector('[type="submit"]');
      var prevHtml = null;
      if (btn) {
        // Spinner + teks (app.js di-skip utk form AJAX ini karena tombol kita
        // disable sendiri di bawah). .btn-spinner ada di app.css (currentColor).
        if (btn.hasAttribute('data-loading') || btn.hasAttribute('data-loading-text')) {
          var loadingText = btn.getAttribute('data-loading-text') || btn.getAttribute('data-loading') || 'Menyimpan...';
          prevHtml = btn.innerHTML;
          var sp = document.createElement('span');
          sp.className = 'btn-spinner';
          sp.setAttribute('aria-hidden', 'true');
          btn.textContent = '';
          btn.appendChild(sp);
          btn.appendChild(document.createTextNode(loadingText));
        }
        btn.disabled = true;
      }

      fetch(form.action, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
        .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Respons tidak valid.' }; }); })
        .then(function (d) {
          show(d && d.success ? 'success' : 'error',
               (d && d.message) || (d && d.success ? 'Berhasil disimpan.' : 'Terjadi kesalahan. Coba lagi.'));
        })
        .catch(function () { show('error', 'Gagal terhubung ke server. Periksa koneksi kamu.'); })
        .finally(function () {
          if (btn) { btn.disabled = false; if (prevHtml !== null) btn.innerHTML = prevHtml; }
          if (scroller) scroller.scrollTop = sPos;
          window.scrollTo({ top: wPos, behavior: 'instant' });
        });
    });
  });
})();
</script>
