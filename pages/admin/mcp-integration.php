<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Integrasi Claude';
$topbarTitle = 'Integrasi Claude (MCP)';

$mcpEnabled   = (string) getSetting('mcp_enabled', '0') === '1';
$maskPii      = (string) getSetting('mcp_mask_pii', '1') === '1';
$writeEnabled = (string) getSetting('mcp_write_enabled', '0') === '1';

$tokenHash      = trim((string) getSetting('mcp_api_token_hash', ''));
$tokenCreatedAt = (string) getSetting('mcp_api_token_created_at', '');
$hasToken       = $tokenHash !== '';

// Token plaintext hasil generate — tampil SEKALI (dibaca lalu dihapus dari
// session; refresh = hilang, hanya hash yang tersimpan). Pola sama Studio.
$freshToken = (string) ($_SESSION['mcp_token_show'] ?? '');
unset($_SESSION['mcp_token_show']);

$csrf = generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-3xl mx-auto space-y-5">

        <div class="mb-1 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Integrasi Claude</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
              Hubungkan Claude ke data bisnis instalasi ini — omzet, produk terlaris, order menunggu bayar, performa affiliate, dan pencarian member — lewat percakapan biasa.
            </p>
          </div>
          <a href="<?= e(APP_URL) ?>/admin/docs#integrasi-claude" target="_blank" rel="noopener"
             title="Buka panduan Integrasi Claude"
             class="shrink-0 inline-flex items-center gap-1.5 px-2.5 h-8 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-[var(--brand-color)] hover:bg-gray-100 dark:hover:bg-gray-800 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <span class="hidden sm:inline">Panduan</span>
          </a>
        </div>

        <!-- Tentang -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <div class="flex items-start gap-3">
            <span class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent)">
              <img src="<?= e(rtrim(APP_URL, '/')) ?>/assets/img/claude-symbol.webp" alt="Claude" class="w-5 h-5 object-contain">
            </span>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-900 dark:text-white">Apa yang Claude bisa lihat?</p>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Token di bawah membuka akses <span class="font-medium text-gray-700 dark:text-gray-300">baca saja</span> ke ringkasan omzet, penjualan produk, order pending, leaderboard affiliate, dan pencarian member — tidak ada aksi yang mengubah data (hapus, kirim pesan, buat kupon) di tahap ini.
              </p>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">
                Hubungkan lewat fitur <span class="font-medium text-gray-600 dark:text-gray-300">Custom Connector</span> di aplikasi Claude memakai URL &amp; token di bawah. Untuk saat ini semua akses bersifat <span class="font-medium text-gray-600 dark:text-gray-300">baca data</span>; aksi yang mengubah data (kirim pesan, buat kupon) menyusul di fase berikutnya dan akan selalu meminta persetujuanmu.
              </p>
            </div>
          </div>
        </div>

        <!-- Status aktif -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/mcp-save-settings" id="mcp-settings-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">Aktifkan Integrasi Claude</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                  Nonaktif = <span class="font-mono">/api/internal/*</span> menolak semua permintaan meski tokennya benar. Default nonaktif demi keamanan.
                </p>
              </div>
              <label class="relative inline-flex items-center cursor-pointer shrink-0">
                <input type="hidden" name="mcp_enabled" value="0">
                <input type="checkbox" name="mcp_enabled" value="1" <?= $mcpEnabled ? 'checked' : '' ?>
                       onchange="document.getElementById('mcp-settings-form').submit()"
                       class="sr-only peer">
                <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
              </label>
            </div>

            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
              <p class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Privasi Data Member</p>
              <div class="space-y-2">
                <label class="flex items-start gap-2.5 cursor-pointer">
                  <input type="radio" name="mcp_mask_pii" value="1" <?= $maskPii ? 'checked' : '' ?>
                         onchange="document.getElementById('mcp-settings-form').submit()"
                         class="mt-0.5 w-4 h-4 text-[var(--brand-color)] border-gray-300 dark:border-gray-600">
                  <span class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium">Samarkan email &amp; WhatsApp</span> <span class="text-emerald-600 dark:text-emerald-400 text-xs font-semibold">(Direkomendasikan)</span>
                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Mis. b***@gmail.com, 6281****89 — cukup untuk verifikasi tanpa membongkar data penuh.</span>
                  </span>
                </label>
                <label class="flex items-start gap-2.5 cursor-pointer">
                  <input type="radio" name="mcp_mask_pii" value="0" <?= !$maskPii ? 'checked' : '' ?>
                         onchange="document.getElementById('mcp-settings-form').submit()"
                         class="mt-0.5 w-4 h-4 text-[var(--brand-color)] border-gray-300 dark:border-gray-600">
                  <span class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium">Tampilkan data penuh</span>
                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">Email &amp; nomor WhatsApp member terlihat utuh oleh Claude. Pastikan hanya kamu yang pegang token ini.</span>
                  </span>
                </label>
              </div>
            </div>

            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
              <div class="rounded-lg border p-4" style="border-color:color-mix(in srgb,#f59e0b 40%,transparent);background:color-mix(in srgb,#f59e0b 8%,transparent)">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">Izinkan Aksi Tulis</p>
                    <p class="text-xs mt-1 leading-relaxed text-amber-800">
                      Saat menyala, Claude bisa <strong>mengirim pesan (WA/email) ke member</strong> dan <strong>alert ke Telegram admin</strong> — tiap aksi tetap minta persetujuanmu di Claude &amp; tercatat. Default mati demi keamanan. Payout, refund, &amp; ubah saldo tidak pernah tersedia lewat Claude.
                    </p>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="mcp_write_enabled" value="0">
                    <input type="checkbox" name="mcp_write_enabled" value="1" <?= $writeEnabled ? 'checked' : '' ?>
                           onchange="document.getElementById('mcp-settings-form').submit()"
                           class="sr-only peer">
                    <div class="w-11 h-6 rounded-full bg-gray-200 dark:bg-gray-700 peer-checked:bg-[var(--brand-color)] transition-colors"></div>
                    <div class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-5"></div>
                  </label>
                </div>
              </div>
            </div>
          </form>
        </div>

        <!-- Token API -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-900 dark:text-white">Token API</p>
              <?php if ($hasToken): ?>
                <p class="text-sm mt-1 inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                  Aktif sejak <?= e($tokenCreatedAt !== '' ? date('d M Y H:i', (int) $tokenCreatedAt) : '-') ?>
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Token hanya tersimpan sebagai hash — tidak bisa ditampilkan ulang. Lupa token? Generate ulang (token lama otomatis dicabut).</p>
              <?php else: ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Belum ada token. Generate untuk menghubungkan Claude.</p>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/mcp-generate-token" id="mcp-generate-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <?php if ($hasToken): ?>
                <button type="button"
                        onclick="confirmAndRun({title:'Generate Ulang Token?',message:'Token lama langsung dicabut — koneksi Claude yang memakai token lama akan terputus sampai token baru dipasang.',confirmText:'Ya, Generate Ulang',variant:'warning'}, () => { document.getElementById('mcp-generate-form').submit(); })"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                  Generate Ulang
                </button>
                <?php else: ?>
                <button type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                  Generate Token
                </button>
                <?php endif; ?>
              </form>
              <?php if ($hasToken): ?>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/mcp-revoke-token" id="mcp-revoke-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="button"
                        onclick="confirmAndRun({title:'Cabut Token?',message:'Claude tidak akan bisa mengakses data lagi sampai token baru dibuat & dipasang ulang.',confirmText:'Ya, Cabut',variant:'danger'}, () => { document.getElementById('mcp-revoke-form').submit(); })"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-red-600 dark:text-red-400 border border-red-200 dark:border-red-900/50 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                  Cabut Token
                </button>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">URL Konektor</p>
            <div class="flex items-center gap-2 mb-3">
              <input type="text" readonly value="<?= e(rtrim(APP_URL, '/')) ?>/api/mcp"
                     onclick="this.select()"
                     class="flex-1 px-3 py-2 rounded-lg text-xs font-mono bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white select-all">
            </div>
            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1.5">Cara memasang di Claude</p>
            <ol class="text-xs text-gray-500 dark:text-gray-400 space-y-1 list-decimal pl-4">
              <li>Buka aplikasi Claude &rsaquo; <span class="font-medium text-gray-600 dark:text-gray-300">Settings &rsaquo; Connectors &rsaquo; Add custom connector</span>.</li>
              <li>Tempel <span class="font-medium text-gray-600 dark:text-gray-300">URL Konektor</span> di atas.</li>
              <li>Di bagian pengaturan lanjutan (Request headers), tambahkan header <span class="font-mono">Authorization: Bearer &lt;token&gt;</span> memakai token yang kamu Generate di atas.</li>
              <li>Simpan, lalu aktifkan connector. Pastikan tombol <span class="font-medium text-gray-600 dark:text-gray-300">Aktifkan Integrasi Claude</span> di atas menyala.</li>
            </ol>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<?php if ($freshToken !== ''): ?>
<!-- Modal token baru — tampil SEKALI. Setelah halaman ini, token tak bisa dilihat lagi. -->
<div id="mcp-token-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/50"></div>
  <div class="relative bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-xl max-w-lg w-full p-6">
    <div class="flex items-start gap-3 mb-4">
      <span class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent)">
        <svg class="w-5 h-5" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
      </span>
      <div>
        <h3 class="text-base font-bold text-gray-900 dark:text-white">Token Claude Berhasil Dibuat</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Salin & simpan SEKARANG — token tidak akan ditampilkan lagi setelah modal ini ditutup.</p>
      </div>
    </div>
    <div class="flex items-center gap-2 mb-4">
      <input id="mcp-token-value" type="text" readonly value="<?= e($freshToken) ?>"
             class="flex-1 px-3 py-2.5 rounded-lg text-xs font-mono bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white select-all">
      <button type="button" id="mcp-token-copy"
              class="shrink-0 inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
        <span id="mcp-token-copy-label">Salin</span>
      </button>
    </div>
    <div class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-900/40 mb-4">
      <svg class="w-4 h-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
      <p class="text-xs text-amber-700 dark:text-amber-300">Kami hanya menyimpan sidik jari (hash) token ini. Kalau hilang, kamu harus generate ulang dan memasang ulang di Claude.</p>
    </div>
    <button type="button" onclick="document.getElementById('mcp-token-modal').remove()"
            class="w-full px-4 py-2.5 rounded-lg text-sm font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
      Sudah Kusimpan, Tutup
    </button>
  </div>
</div>
<script>
document.getElementById('mcp-token-copy').addEventListener('click', function () {
  var input = document.getElementById('mcp-token-value');
  var label = document.getElementById('mcp-token-copy-label');
  navigator.clipboard.writeText(input.value).then(function () {
    label.textContent = 'Tersalin';
    setTimeout(function () { label.textContent = 'Salin'; }, 2000);
  }).catch(function () {
    input.select(); document.execCommand('copy');
    label.textContent = 'Tersalin';
    setTimeout(function () { label.textContent = 'Salin'; }, 2000);
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
