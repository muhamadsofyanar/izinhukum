  <?php include __DIR__ . '/confirm-modal.php'; ?>

  <!-- Logout form (POST + CSRF) — disubmit via JS oleh semua tombol logout -->
  <?php if (isLoggedIn()): ?>
  <form id="logout-form" method="POST" action="<?= e(APP_URL) ?>/actions/auth/logout" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
  </form>
  <?php endif; ?>

  <!-- App JS (versi = filemtime → cache browser otomatis ter-bust saat file berubah) -->
  <script src="<?= e(APP_URL) ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: '1' ?>"></script>

  <?php
  // ─── SOCIAL PROOF INJECT (deteksi konteks dari URL) ────────────────────────
  include __DIR__ . '/sp-popup.php';
  ?>

  <?php
  // ─── AI CHAT WIDGET (guard sendiri: enabled + lokasi + non-admin) ──────────
  include_once __DIR__ . '/ai-chat-widget.php';
  ?>

  <?php
  // ─── PRESENCE HEARTBEAT MEMBER (guard sendiri: login + role 'user') ────────
  // Kosong di halaman admin/staff/tamu. Mencatat member yang sedang online →
  // indikator "sedang online" di kartu Active Members dashboard admin.
  echo memberPresenceHeartbeatScript();
  ?>

  <!-- Dark mode init (sebelum paint, hindari flash) -->
  <script>
    // Sudah di-handle oleh initDarkMode() di app.js yang dipanggil saat DOMContentLoaded
    // Tapi kita tambah init cepat di sini untuk menghindari FOUC (flash of unstyled content)
    (function() {
      try {
        var saved = localStorage.getItem('averion_dark');
        if (saved === '1') {
          document.documentElement.classList.add('dark');
        } else if (saved === '0') {
          document.documentElement.classList.remove('dark');
        }
      } catch(e) {}
    })();
  </script>

</body>
</html>
