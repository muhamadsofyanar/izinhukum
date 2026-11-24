<?php
/**
 * Averion — Notifikasi Order Masuk (Admin) — partial reusable (SP-05)
 * ----------------------------------------------------------------------------
 * Popup + suara persisten saat ada order baru. Muncul di SEMUA halaman admin
 * (di-include dari partials/sidebar-admin.php yang dimuat setiap halaman admin),
 * bukan lagi hanya di Riwayat Transaksi.
 *
 * Persisten: popup tidak auto-hilang, MENUMPUK (stack), hanya ditutup manual
 * via tombol ×. Ikut Style Popup / Tema / Posisi dari pengaturan Social Proof.
 *
 * Guard:
 *   - Hanya admin penuh (endpoint latest-order-id butuh requireAdmin(); staff 403).
 *   - Hanya bila sp_notif_order_enabled = 1.
 *   - Idempotent: aman walau ter-include lebih dari sekali dalam satu halaman.
 */
if (!function_exists('isAdmin') || !isAdmin()) return;
if (getSetting('sp_notif_order_enabled', '1') !== '1') return;

$notifSound = getSetting('sp_notif_order_sound', 'cash_register');
include __DIR__ . '/sp-sound-engine.php';
?>
<script>
// Popup notif order admin — UI selaras Style Popup Social Proof (card/toast/minimal
// + tema + posisi + brand border), TAPI PERSISTEN: tidak auto-hilang, ditutup manual
// via tombol ×, dan MENUMPUK (stack) tiap ada order baru. Ikut sp_popup_style/theme/position.
(function () {
  'use strict';
  if (window.__averionOrderNotifInit) return; // idempotent: cegah dobel polling/suara
  window.__averionOrderNotifInit = true;

  var SOUND_TYPE     = <?= json_encode($notifSound) ?>;
  var STYLE          = <?= json_encode(getSetting('sp_popup_style', 'card')) ?>;
  var THEME          = <?= json_encode(getSetting('sp_theme', 'light')) ?>;
  var POS            = <?= json_encode(getSetting('sp_position', 'br')) ?>;
  var ENDPOINT       = <?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/latest-order-id') ?>;
  var CHECK_INTERVAL = 30000; // 30 detik
  var lastKnownOrderId = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }
  function fetchLatest() {
    return fetch(ENDPOINT, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  // Inisialisasi: catat order terakhir saat halaman dibuka.
  fetchLatest().then(function (d) { if (d && d.id) lastKnownOrderId = d.id; }).catch(function () {});

  function checkNewOrders() {
    if (document.hidden) return; // hemat: jangan polling saat tab tidak aktif
    fetchLatest().then(function (d) {
      if (d && d.id && d.id > lastKnownOrderId) {
        lastKnownOrderId = d.id;
        showOrderNotif(d);
      }
    }).catch(function () {});
  }

  // ── Ikon (sama persis dengan preview Social Proof) ──
  function bag(sz, c) { return '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="' + (c || 'currentColor') + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>'; }
  function chk(sz) { return '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'; }
  function seal(sz) { return '<svg width="' + sz + '" height="' + sz + '" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z"/></svg>'; }

  // Container tumpukan popup (persisten). Newest paling dekat sudut.
  function ensureStack() {
    var s = document.getElementById('averion-order-notif-stack');
    if (s) return s;
    var right = (POS === 'br' || POS === 'tr');
    var top   = (POS === 'tl' || POS === 'tr');
    s = document.createElement('div');
    s.id = 'averion-order-notif-stack';
    s.style.cssText = 'position:fixed;z-index:2147483600;display:flex;flex-direction:column;gap:10px;pointer-events:none;' +
      (top ? 'top:18px;' : 'bottom:18px;') + (right ? 'right:18px;' : 'left:18px;') +
      'align-items:' + (right ? 'flex-end' : 'flex-start') + ';max-height:calc(100vh - 36px);';
    document.body.appendChild(s);
    return s;
  }

  function showOrderNotif(data) {
    if (window.spPlaySound) window.spPlaySound(SOUND_TYPE);
    var stack = ensureStack();
    var top   = (POS === 'tl' || POS === 'tr');

    var buyer = esc(data.buyer_name || 'Pembeli');
    var prod  = esc(data.product_name || '');
    var amt   = esc(data.amount_formatted || '');
    var text  = '<strong>' + buyer + '</strong> memesan ' + prod;

    var dark = THEME === 'dark';
    var base = 'position:relative;pointer-events:auto;flex-shrink:0;display:flex;align-items:center;gap:10px;border-radius:8px;font-family:-apple-system,sans-serif;' +
      'opacity:0;transform:translateY(' + (top ? '-10px' : '10px') + ') scale(.98);transition:opacity .3s cubic-bezier(.16,1,.3,1),transform .35s cubic-bezier(.16,1,.3,1);';

    var box, inner;
    if (STYLE === 'toast') {
      box = base + 'padding:7px 30px 7px 11px;' + (dark ? 'background:#1e293b;color:#fff;border:1px solid #334155;' : 'background:#fff;color:#1e293b;border:1px solid #e2e8f0;') + 'box-shadow:0 1px 2px rgba(15,23,42,.06),0 6px 20px -6px rgba(15,23,42,.18);';
      inner = '<span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(22,163,74,.12);color:#16a34a;flex-shrink:0;">' + chk(13) + '</span><span style="font-size:13px;font-weight:500;">' + text + ' · ' + amt + '</span>';
    } else if (STYLE === 'minimal') {
      box = base + 'padding:9px 30px 9px 10px;' + (dark ? 'background:rgba(30,41,59,.72);color:#e2e8f0;border:1px solid rgba(255,255,255,.08);' : 'background:rgba(255,255,255,.78);color:#334155;border:1px solid rgba(255,255,255,.6);') + 'backdrop-filter:blur(10px) saturate(140%);box-shadow:0 1px 2px rgba(15,23,42,.05),0 4px 16px -4px rgba(15,23,42,.14);';
      inner = '<div style="width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color);">' + bag(18, 'var(--brand-color)') + '</div>' +
              '<div><div style="font-size:13px;font-weight:500;line-height:1.35;">' + text + '</div><div style="font-size:11px;color:#94a3b8;margin-top:2px;">Order baru · ' + amt + '</div></div>';
    } else {
      box = base + 'width:290px;padding:10px 26px 10px 10px;border-left:3px solid var(--brand-color);' + (dark ? 'background:#1e293b;color:#fff;border-top:1px solid #334155;border-right:1px solid #334155;border-bottom:1px solid #334155;' : 'background:#fff;color:#1e293b;border-top:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;') + 'box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 30px -8px rgba(15,23,42,.22);';
      inner = '<div style="position:relative;flex-shrink:0;">' +
                '<div style="width:44px;height:44px;border-radius:6px;background:color-mix(in srgb,var(--brand-color) 12%,transparent);color:var(--brand-color);display:flex;align-items:center;justify-content:center;">' + bag(20, 'var(--brand-color)') + '</div>' +
                '<span style="position:absolute;right:-3px;bottom:-3px;width:15px;height:15px;border-radius:50%;background:#16a34a;display:flex;align-items:center;justify-content:center;box-sizing:border-box;border:2px solid ' + (dark ? '#1e293b' : '#fff') + ';"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>' +
              '</div>' +
              '<div><div style="font-size:13px;font-weight:500;line-height:1.35;">' + text + '</div><div style="font-size:11px;color:#94a3b8;margin-top:2px;">Order baru masuk · ' + amt + '</div></div>';
    }

    // Tombol tutup (×) — popup persisten, hanya hilang saat diklik.
    var closeBtn = '<button type="button" data-close aria-label="Tutup" style="position:absolute;top:6px;right:7px;background:none;border:none;cursor:pointer;color:#94a3b8;line-height:0;padding:2px;display:flex;">' +
      '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';

    var el = document.createElement('div');
    el.className = 'averion-order-notif';
    el.setAttribute('style', box);
    el.innerHTML = inner + closeBtn;

    // Newest paling dekat sudut: atas → prepend, bawah → append.
    if (top) stack.insertBefore(el, stack.firstChild); else stack.appendChild(el);

    el.querySelector('[data-close]').addEventListener('click', function () {
      el.style.opacity = '0';
      el.style.transform = 'translateY(' + (top ? '-6px' : '6px') + ') scale(.98)';
      setTimeout(function () { el.remove(); if (stack && !stack.children.length) stack.remove(); }, 260);
    });

    requestAnimationFrame(function () { el.style.opacity = '1'; el.style.transform = 'translateY(0) scale(1)'; });
  }

  setInterval(checkNewOrders, CHECK_INTERVAL);
})();
</script>
