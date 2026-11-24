<?php
// Lonceng Info Member di topbar — badge unread + dropdown daftar + modal detail.
// Tampil untuk semua user login. Fail-safe total: bila tabel belum ada (instalasi
// belum migrasi) atau query gagal, lonceng tidak dirender sama sekali.
if (!isLoggedIn()) return;

$infoBellItems  = [];
$infoBellUnread = 0;
try {
    $ibPdo = getDB();
    if (function_exists('ensureAnnouncementCtaColumns')) ensureAnnouncementCtaColumns($ibPdo);
    $ibUid = (int) ($_SESSION['user_id'] ?? 0);
    $st = $ibPdo->prepare(
        "SELECT a.id, a.title, a.body, a.cta_url, a.cta_text, a.created_at, (ar.id IS NOT NULL) AS is_read
         FROM announcements a
         LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.user_id = ?
         WHERE a.is_active = 1 AND (
               a.target_type = 'global'
            OR (a.target_type = 'users' AND EXISTS (
                   SELECT 1 FROM announcement_users au
                   WHERE au.announcement_id = a.id AND au.user_id = ?))
            OR (a.target_type = 'product' AND a.product_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM member_access ma
                   WHERE ma.product_id = a.product_id AND ma.user_id = ?))
         )
         ORDER BY a.created_at DESC
         LIMIT 15"
    );
    $st->execute([$ibUid, $ibUid, $ibUid]);
    $infoBellItems = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($infoBellItems as $ib) { if (!(int) $ib['is_read']) $infoBellUnread++; }
} catch (Throwable $e) {
    return; // tabel belum ada / error → tanpa lonceng, halaman tetap normal
}

// Khusus admin/staff: item "Update tersedia" di paling atas lonceng (sumber cek
// otomatis 12-jam-an yang sudah ada). Hilang sendiri setelah update terpasang.
$infoBellUpdateVer = '';
$infoBellBroadcast = null;
if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    if (function_exists('updateAvailableVersion')) {
        $infoBellUpdateVer = updateAvailableVersion();
        if ($infoBellUpdateVer !== '') $infoBellUnread++;
    }
    // Broadcast dari vendor (Fase 1) — pengumuman + CTA opsional. Untuk pemilik
    // bisnis (admin/staff) saja, bukan pelanggan-akhir mereka.
    if (function_exists('vendorBroadcast')) {
        $infoBellBroadcast = vendorBroadcast();
        if ($infoBellBroadcast) $infoBellUnread++;
    }
}
?>
<div class="relative" id="infoBellWrap">
  <button type="button" onclick="infoBellToggle()"
          class="relative flex items-center justify-center w-9 h-9 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
          aria-label="Info dari admin">
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/>
    </svg>
    <?php if ($infoBellUnread > 0): ?>
    <span id="infoBellBadge" class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full text-[10px] font-bold text-white flex items-center justify-center" style="background: var(--brand-color);"><?= $infoBellUnread > 9 ? '9+' : $infoBellUnread ?></span>
    <?php endif; ?>
  </button>

  <!-- Dropdown -->
  <div id="infoBellPanel" class="hidden absolute right-0 top-11 w-80 max-w-[90vw] bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-lg overflow-hidden z-50">
    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 text-sm font-semibold text-gray-900 dark:text-white">Info</div>
    <div class="max-h-80 overflow-y-auto">
      <?php if ($infoBellBroadcast): ?>
      <div id="broadcastCard" data-id="<?= (int) $infoBellBroadcast['id'] ?>"
           class="px-4 py-3.5 border-b border-gray-100 dark:border-gray-800"
           style="background: color-mix(in srgb, var(--brand-color) 6%, transparent);">
        <div class="flex items-start gap-2.5">
          <span class="mt-0.5 w-6 h-6 rounded-md flex items-center justify-center shrink-0" style="color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 14%, transparent);">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-gray-900 dark:text-white leading-snug"><?= e($infoBellBroadcast['title']) ?></p>
            <?php if (trim((string) $infoBellBroadcast['body']) !== ''): ?>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed whitespace-pre-line"><?= e($infoBellBroadcast['body']) ?></p>
            <?php endif; ?>
            <?php if (!empty($infoBellBroadcast['cta_url']) && !empty($infoBellBroadcast['cta_label'])): ?>
            <a href="<?= e($infoBellBroadcast['cta_url']) ?>" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-1.5 mt-2.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition hover:opacity-90"
               style="background: var(--brand-color);">
              <?= e($infoBellBroadcast['cta_label']) ?>
              <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M8 7h9v9"/></svg>
            </a>
            <?php endif; ?>
          </div>
          <button type="button" onclick="broadcastDismiss()" title="Tutup pengumuman ini"
                  class="shrink-0 w-6 h-6 rounded-md flex items-center justify-center text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition" aria-label="Tutup">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($infoBellUpdateVer !== ''): ?>
      <a href="<?= e(APP_URL) ?>/admin/update"
         class="block px-4 py-3.5 border-b border-gray-100 dark:border-gray-800 transition hover:bg-gray-50 dark:hover:bg-gray-800/60"
         style="background: color-mix(in srgb, var(--brand-color) 6%, transparent);">
        <span class="flex items-start gap-2.5">
          <span class="mt-0.5 w-6 h-6 rounded-md flex items-center justify-center shrink-0" style="color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 16%, transparent);">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
            </svg>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-gray-900 dark:text-white leading-snug">Update v<?= e($infoBellUpdateVer) ?> tersedia</span>
            <span class="block text-xs text-gray-600 dark:text-gray-300 mt-0.5 leading-relaxed">Klik untuk membuka Update Sistem &amp; pasang versi terbaru.</span>
          </span>
        </span>
      </a>
      <?php endif; ?>
      <?php if (!$infoBellItems && $infoBellUpdateVer === '' && !$infoBellBroadcast): ?>
      <p class="px-4 py-8 text-center text-xs text-gray-400 dark:text-gray-500">Belum ada info dari admin.</p>
      <?php else: foreach ($infoBellItems as $ib): ?>
      <button type="button"
              class="info-bell-item w-full text-left px-4 py-3 border-b border-gray-50 dark:border-gray-800/60 hover:bg-gray-50 dark:hover:bg-gray-800/60 transition"
              data-id="<?= (int) $ib['id'] ?>" data-read="<?= (int) $ib['is_read'] ?>"
              data-title="<?= e($ib['title']) ?>" data-body="<?= e($ib['body']) ?>"
              data-cta-url="<?= e($ib['cta_url'] ?? '') ?>" data-cta-text="<?= e($ib['cta_text'] ?? '') ?>"
              data-date="<?= e(date('d M Y, H:i', strtotime($ib['created_at']))) ?>"
              onclick="infoBellOpen(this)">
        <span class="flex items-start gap-2.5">
          <span class="info-bell-dot mt-1.5 w-2 h-2 rounded-full shrink-0 <?= (int) $ib['is_read'] ? 'bg-transparent' : '' ?>" <?= (int) $ib['is_read'] ? '' : 'style="background: var(--brand-color);"' ?>></span>
          <span class="min-w-0">
            <span class="block text-sm <?= (int) $ib['is_read'] ? 'font-normal text-gray-600 dark:text-gray-400' : 'font-semibold text-gray-900 dark:text-white' ?> truncate"><?= e($ib['title']) ?></span>
            <span class="block text-xs text-gray-400 dark:text-gray-500 truncate"><?= e(mb_substr(trim($ib['body']), 0, 70)) ?><?= mb_strlen(trim($ib['body'])) > 70 ? '…' : '' ?></span>
          </span>
        </span>
      </button>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Modal detail — dipindah ke <body> saat init (topbar punya stacking context
       sendiri sehingga position:fixed di sini tidak mengacu ke viewport) -->
  <div id="infoBellModal" class="hidden fixed inset-0 items-center justify-center p-4" style="z-index: 60;">
    <div class="absolute inset-0 bg-black/50" onclick="infoBellCloseModal()"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-800 shadow-2xl w-full max-w-sm max-h-[80vh] flex flex-col overflow-hidden">
      <div class="h-1 shrink-0" style="background: var(--brand-color);"></div>
      <div class="flex items-start gap-3 px-5 pt-4 pb-3 border-b border-gray-100 dark:border-gray-800">
        <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="color: var(--brand-color); background: color-mix(in srgb, var(--brand-color) 10%, transparent);">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/>
          </svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-[10px] font-semibold uppercase tracking-widest" style="color: var(--brand-color);">Info dari Admin</p>
          <h3 id="infoBellModalTitle" class="text-sm font-bold text-gray-900 dark:text-white leading-snug mt-0.5"></h3>
        </div>
        <button type="button" onclick="infoBellCloseModal()" class="shrink-0 w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition" aria-label="Tutup">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div id="infoBellModalBody" class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300 leading-relaxed whitespace-pre-line overflow-y-auto"></div>
      <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
        <p id="infoBellModalDate" class="text-[11px] text-gray-400 dark:text-gray-500"></p>
        <div class="flex items-center gap-2">
          <a id="infoBellModalCta" href="#" target="_blank" rel="noopener noreferrer"
             class="hidden inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white hover:opacity-90 transition" style="background: var(--brand-color);">
            <span id="infoBellModalCtaText"></span>
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M8 7h9v9"/></svg>
          </a>
          <button type="button" onclick="infoBellCloseModal()" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Mengerti</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Pindahkan modal ke <body> — topbar punya stacking context (transform/z) yang membuat
// position:fixed mengacu ke topbar, bukan viewport → modal tak pernah di tengah layar.
document.addEventListener('DOMContentLoaded', function () {
  var m = document.getElementById('infoBellModal');
  if (m && m.parentElement !== document.body) document.body.appendChild(m);
});
function infoBellToggle() {
  document.getElementById('infoBellPanel').classList.toggle('hidden');
}
document.addEventListener('click', function (e) {
  var wrap = document.getElementById('infoBellWrap');
  if (wrap && !wrap.contains(e.target)) {
    var p = document.getElementById('infoBellPanel');
    if (p) p.classList.add('hidden');
  }
});
function infoBellOpen(btn) {
  document.getElementById('infoBellModalTitle').textContent = btn.dataset.title;
  document.getElementById('infoBellModalDate').textContent  = btn.dataset.date;
  document.getElementById('infoBellModalBody').textContent  = btn.dataset.body;
  // Tombol CTA opsional — hanya tampil bila info punya URL.
  var cta = document.getElementById('infoBellModalCta');
  var ctaUrl = btn.dataset.ctaUrl || '';
  if (cta) {
    if (ctaUrl) {
      cta.href = ctaUrl;
      document.getElementById('infoBellModalCtaText').textContent = btn.dataset.ctaText || 'Selengkapnya';
      cta.classList.remove('hidden');
    } else {
      cta.classList.add('hidden');
      cta.removeAttribute('href');
    }
  }
  var modal = document.getElementById('infoBellModal');
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  document.getElementById('infoBellPanel').classList.add('hidden');
  if (btn.dataset.read !== '1') {
    btn.dataset.read = '1';
    var t = btn.querySelector('.info-bell-item, span span');
    var title = btn.querySelector('span > span > span');
    if (title) { title.classList.remove('font-semibold', 'text-gray-900', 'dark:text-white'); title.classList.add('font-normal', 'text-gray-600', 'dark:text-gray-400'); }
    var dot = btn.querySelector('.info-bell-dot');
    if (dot) { dot.style.background = 'transparent'; }
    var badge = document.getElementById('infoBellBadge');
    if (badge) {
      var n = parseInt(badge.textContent, 10);
      if (!isNaN(n) && n > 1) badge.textContent = (n - 1) > 9 ? '9+' : String(n - 1);
      else badge.remove();
    }
    var fd = new FormData();
    fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '') ?>);
    fd.append('announcement_id', btn.dataset.id);
    fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/user/read-info') ?>, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
  }
}
function infoBellCloseModal() {
  var modal = document.getElementById('infoBellModal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}
// Tutup broadcast vendor (per-id) — hilang dari lonceng, badge berkurang, dan
// dicatat di server agar tak muncul lagi (broadcast baru dgn id lain tetap tampil).
function broadcastDismiss() {
  var card = document.getElementById('broadcastCard');
  if (!card) return;
  var id = card.getAttribute('data-id');
  card.remove();
  var badge = document.getElementById('infoBellBadge');
  if (badge) {
    var n = parseInt(badge.textContent, 10);
    if (!isNaN(n) && n > 1) badge.textContent = (n - 1) > 9 ? '9+' : String(n - 1);
    else badge.remove();
  }
  var fd = new FormData();
  fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '') ?>);
  fd.append('broadcast_id', id);
  fetch(<?= json_encode(rtrim(APP_URL, '/') . '/actions/admin/dismiss-broadcast') ?>, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
}
</script>
