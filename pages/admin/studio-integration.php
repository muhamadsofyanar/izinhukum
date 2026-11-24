<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Integrasi Studio';
$topbarTitle = 'Integrasi Averion Studio';

$tokenHash      = trim((string) getSetting('studio_api_token_hash', ''));
$tokenCreatedAt = (string) getSetting('studio_api_token_created_at', '');
$hasToken       = $tokenHash !== '';

// Token plaintext hasil generate — tampil SEKALI (dibaca lalu dihapus dari
// session; refresh = hilang, hanya hash yang tersimpan).
$freshToken = (string) ($_SESSION['studio_token_show'] ?? '');
unset($_SESSION['studio_token_show']);

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

      <div class="max-w-3xl mx-auto">

        <div class="mb-5">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Integrasi Averion Studio</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Hubungkan instalasi Averion ini dengan Averion Studio — asisten promosi berbasis prompt, bonus gratis untuk member aktif.
          </p>
        </div>

        <!-- Tentang Studio -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <div class="flex items-start gap-3">
            <span class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent)">
              <svg class="w-5 h-5" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/>
              </svg>
            </span>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-900 dark:text-white">Apa itu Averion Studio?</p>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Studio membantumu membuat materi promosi (broadcast WA, kalender konten, script VSL, ad copy, prompt desain visual) dari data produk di instalasi ini.
                Buka <a href="https://studio.averion.id" target="_blank" rel="noopener" class="font-medium underline" style="color:var(--brand-color)">studio.averion.id</a>,
                login pakai email + license key Averion kamu.
              </p>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Token di bawah dipakai Studio untuk <span class="font-medium text-gray-700 dark:text-gray-300">membaca daftar produk aktif</span> kamu
                (nama, harga, deskripsi singkat, benefit, link checkout) — tidak ada data pelanggan, order, atau omzet yang dibagikan.
              </p>
            </div>
          </div>
        </div>

        <!-- Token API -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-900 dark:text-white">Token API Studio</p>
              <?php if ($hasToken): ?>
                <p class="text-sm mt-1 inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                  Aktif sejak <?= e($tokenCreatedAt !== '' ? date('d M Y H:i', (int) $tokenCreatedAt) : '-') ?>
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Token hanya tersimpan sebagai hash — tidak bisa ditampilkan ulang. Lupa token? Generate ulang (token lama otomatis dicabut).</p>
              <?php else: ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Belum ada token. Generate untuk menghubungkan Studio.</p>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/studio-generate-token" id="studio-generate-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <?php if ($hasToken): ?>
                <button type="button"
                        onclick="confirmAndRun({title:'Generate Ulang Token?',message:'Token lama langsung dicabut — koneksi Studio yang memakai token lama akan terputus sampai token baru dipasang.',confirmText:'Ya, Generate Ulang',variant:'warning'}, () => { document.getElementById('studio-generate-form').submit(); })"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                  Generate Ulang
                </button>
                <?php else: ?>
                <button type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                  Generate Token
                </button>
                <?php endif; ?>
              </form>
              <?php if ($hasToken): ?>
              <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/studio-revoke-token" id="studio-revoke-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="button"
                        onclick="confirmAndRun({title:'Cabut Token?',message:'Studio tidak akan bisa membaca produk lagi sampai token baru dibuat & dipasang ulang.',confirmText:'Ya, Cabut',variant:'danger'}, () => { document.getElementById('studio-revoke-form').submit(); })"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-red-600 dark:text-red-400 border border-red-200 dark:border-red-900/50 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                  Cabut Token
                </button>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
            <p class="text-xs text-gray-400 dark:text-gray-500">
              Cara pakai: Generate Token → salin → buka Studio &rsaquo; Settings &rsaquo; Koneksi Averion → isi domain
              <span class="font-mono text-gray-500 dark:text-gray-400"><?= e(parse_url(APP_URL, PHP_URL_HOST) ?: APP_URL) ?></span> + token → Uji Koneksi.
            </p>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<?php if ($freshToken !== ''): ?>
<!-- Modal token baru — tampil SEKALI. Setelah halaman ini, token tak bisa dilihat lagi. -->
<div id="studio-token-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/50"></div>
  <div class="relative bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-xl max-w-lg w-full p-6">
    <div class="flex items-start gap-3 mb-4">
      <span class="shrink-0 w-10 h-10 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, var(--brand-color) 12%, transparent)">
        <svg class="w-5 h-5" style="color:var(--brand-color)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
      </span>
      <div>
        <h3 class="text-base font-bold text-gray-900 dark:text-white">Token Studio Berhasil Dibuat</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Salin & simpan SEKARANG — token tidak akan ditampilkan lagi setelah modal ini ditutup.</p>
      </div>
    </div>
    <div class="flex items-center gap-2 mb-4">
      <input id="studio-token-value" type="text" readonly value="<?= e($freshToken) ?>"
             class="flex-1 px-3 py-2.5 rounded-lg text-xs font-mono bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white select-all">
      <button type="button" id="studio-token-copy"
              class="shrink-0 inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
        <span id="studio-token-copy-label">Salin</span>
      </button>
    </div>
    <div class="flex items-start gap-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-900/40 mb-4">
      <svg class="w-4 h-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
      <p class="text-xs text-amber-700 dark:text-amber-300">Kami hanya menyimpan sidik jari (hash) token ini. Kalau hilang, kamu harus generate ulang dan memasang ulang di Studio.</p>
    </div>
    <button type="button" onclick="document.getElementById('studio-token-modal').remove()"
            class="w-full px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
      Sudah Kusimpan, Tutup
    </button>
  </div>
</div>
<script>
document.getElementById('studio-token-copy').addEventListener('click', function () {
  var input = document.getElementById('studio-token-value');
  var label = document.getElementById('studio-token-copy-label');
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
