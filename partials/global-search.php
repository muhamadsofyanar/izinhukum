<?php
// ─── Global Search Ctrl+K (admin/staff) ────────────────────────
// Modal pencarian: produk, user, transaksi (endpoint global-search) + menu
// navigasi (filter lokal). Kotak-tegas sesuai bahasa desain (control-radius).
//
// DI-INCLUDE DARI partials/topbar.php (bukan sidebar-admin.php) — tombol Cari
// & shortcut Ctrl+K ada di topbar yang tampil di SEMUA area dashboard, termasuk
// area member (/dashboard, /member, dst) yang memakai sidebar-user.php. Dulu
// modal+JS ini menempel di sidebar-admin.php, jadi di area member window
// .openGlobalSearch tak pernah terdefinisi → tombol diam saja saat diklik.
// Penjaga akses: topbar hanya merender tombol+include ini untuk admin/staff,
// dan endpoint /actions/admin/global-search tetap requireAdminOrStaff().
?>
<div id="gs-overlay" class="hidden fixed inset-0 z-[70] bg-black/40" style="backdrop-filter:blur(2px)">
  <div class="mx-auto mt-[10vh] w-full max-w-lg px-4">
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden" style="border-radius:var(--card-radius,0.5rem)">
      <div class="flex items-center gap-3 px-4 border-b border-gray-100 dark:border-gray-800">
        <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="gs-input" type="text" placeholder="Cari produk, user, transaksi, atau menu..." autocomplete="off"
               class="w-full h-12 bg-transparent text-sm text-gray-900 dark:text-white placeholder-gray-400 outline-none">
        <kbd class="px-1.5 py-0.5 rounded border border-gray-200 dark:border-gray-700 text-[10px] font-mono text-gray-400 shrink-0">Esc</kbd>
      </div>
      <div id="gs-results" class="max-h-[50vh] overflow-y-auto p-2">
        <p class="px-3 py-6 text-center text-xs text-gray-400">Ketik minimal 2 huruf untuk mencari.</p>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var overlay = document.getElementById('gs-overlay');
  var input   = document.getElementById('gs-input');
  var results = document.getElementById('gs-results');
  if (!overlay) return;
  var base = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  var timer = null, ctrl = null, items = [], active = -1;

  // Menu navigasi (filter lokal, tanpa request).
  var MENUS = [
    ['Dashboard', '/admin'], ['Users', '/admin/users'], ['Langganan', '/admin/subscriptions'],
    ['Products', '/admin/products'], ['Bundles', '/admin/bundles'], ['Canvas', '/admin/canvas'],
    ['Transactions', '/admin/transactions'], ['Funnel Analytics', '/admin/funnel-analytics'],
    ['Materi & Kelas', '/admin/content'], ['Kategori Kelas', '/admin/class-categories'],
    ['Download Manager', '/admin/downloads'], ['Sertifikat', '/admin/certificates'],
    ['Review Produk', '/admin/reviews'], ['Kupon', '/admin/coupons'], ['Order Bump', '/admin/product-bumps'],
    ['Bonus Kilat', '/admin/flash-bonus'],
    ['Upsells', '/admin/upsells'], ['Komisi', '/admin/commissions'], ['Payout', '/admin/payouts'],
    ['Aturan Komisi', '/admin/commission-rules'], ['Leaderboard', '/admin/leaderboard'],
    ['Broadcast', '/admin/broadcast'], ['Email Sequence', '/admin/email-sequences'],
    ['Abandoned Cart', '/admin/abandoned-carts'], ['Lead Magnet', '/admin/lead-magnets'],
    ['Leads AI Chat', '/admin/chat-leads'], ['Social Proof', '/admin/social-proof'],
    ['Tracking Pixel', '/admin/tracking-pixels'], ['AI Chat', '/admin/ai-chat'],
    ['Notifikasi', '/admin/notifications'], ['Membership Level', '/admin/membership-levels'],
    ['Branding', '/admin/branding'], ['Settings', '/admin/settings'], ['Integrations', '/admin/integrations'],
    ['Rekening Bank', '/admin/bank-accounts'], ['Staff', '/admin/staff'],
    ['Template Checkout', '/admin/checkout-templates'], ['Lisensi Produk', '/admin/licenses'],
    ['Update Sistem', '/admin/update'], ['Backup Database', '/admin/backups'],
    ['Import Data', '/admin/data-import'], ['Migrasi Sejoli', '/admin/data-import'],
    ['Kesehatan Sistem', '/admin/system-health'], ['Log Aktivitas', '/admin/activity-logs'],
    ['Dokumentasi', '/admin/docs'], ['Waitlist', '/admin/waitlist'], ['License', '/admin/license']
  ];

  window.openGlobalSearch = function () {
    overlay.classList.remove('hidden');
    input.value = ''; renderIdle(); active = -1;
    setTimeout(function () { input.focus(); }, 30);
  };
  function close() { overlay.classList.add('hidden'); }
  function renderIdle() {
    results.textContent = '';
    var p = document.createElement('p');
    p.className = 'px-3 py-6 text-center text-xs text-gray-400';
    p.textContent = 'Ketik minimal 2 huruf untuk mencari.';
    results.appendChild(p);
  }

  function addSection(title, list) {
    if (!list.length) return;
    var h = document.createElement('p');
    h.className = 'px-3 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-widest text-gray-400';
    h.textContent = title;
    results.appendChild(h);
    list.forEach(function (it) {
      var a = document.createElement('a');
      a.href = base + it.url;
      a.className = 'gs-item flex items-center justify-between gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors';
      a.style.borderRadius = 'var(--control-radius, 0.375rem)';
      var l = document.createElement('span'); l.textContent = it.label; l.className = 'truncate font-medium';
      a.appendChild(l);
      if (it.sub) { var s = document.createElement('span'); s.textContent = it.sub; s.className = 'text-xs text-gray-400 truncate shrink-0'; a.appendChild(s); }
      results.appendChild(a);
      items.push(a);
    });
  }

  function render(data, q) {
    results.textContent = ''; items = []; active = -1;
    var ql = q.toLowerCase();
    addSection('Menu', MENUS.filter(function (m) { return m[0].toLowerCase().indexOf(ql) !== -1; })
      .slice(0, 5).map(function (m) { return { label: m[0], url: m[1], sub: '' }; }));
    addSection('Produk', data.products || []);
    addSection('User', data.users || []);
    addSection('Transaksi', data.orders || []);
    if (!items.length) {
      var p = document.createElement('p');
      p.className = 'px-3 py-6 text-center text-xs text-gray-400';
      p.textContent = 'Tidak ada hasil untuk "' + q + '".';
      results.appendChild(p);
    }
  }

  input.addEventListener('input', function () {
    var q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { renderIdle(); return; }
    timer = setTimeout(function () {
      if (ctrl) ctrl.abort();
      ctrl = new AbortController();
      fetch(base + '/actions/admin/global-search?q=' + encodeURIComponent(q), { signal: ctrl.signal })
        .then(function (r) { return r.json(); })
        .then(function (d) { render(d, q); })
        .catch(function (e) { if (e.name !== 'AbortError') render({}, q); });
    }, 220);
  });

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openGlobalSearch(); return; }
    if (overlay.classList.contains('hidden')) return;
    if (e.key === 'Escape') { close(); }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!items.length) return;
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach(function (el, i) {
        el.classList.toggle('bg-gray-100', i === active);
        el.classList.toggle('dark:bg-gray-800', i === active);
        if (i === active) el.scrollIntoView({ block: 'nearest' });
      });
    }
    if (e.key === 'Enter' && active >= 0 && items[active]) { items[active].click(); }
  });
  overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
})();
</script>
