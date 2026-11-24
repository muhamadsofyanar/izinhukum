<?php
// ─── Hermes Agent — pusat konfigurasi Ingest API (admin) ──────────────────
// Produk untuk semua pembeli; default MATI. Token hash-only. Extensible:
// kapabilitas ingest berikutnya tinggal muncul dari registry (helpers/hermes.php).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Hermes Agent';
$topbarTitle = 'Hermes Agent';

$enabled   = hermesEnabled();
$manage    = hermesManageEnabled();
$read      = hermesReadEnabled();
$tokenSet  = hermesTokenIsSet();
$endpoint  = hermesEndpointUrl();
$insightsEndpoint = baseUrl() . '/api/hermes/insights';
$registry  = hermesCapabilityRegistry();
$insights  = hermesInsightsRegistry();
$csrf      = generateCSRF();

// Token mentah ditampilkan SEKALI setelah generate/rotate (lalu dibuang).
$tokenOnce = (string) ($_SESSION['hermes_token_once'] ?? '');
unset($_SESSION['hermes_token_once']);

include __DIR__ . '/../../partials/head.php';
?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-5 lg:p-6">
      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-3xl mx-auto">
        <div class="mb-5 flex items-center gap-3">
          <h1 class="text-xl font-bold text-gray-900 dark:text-white">Hermes Agent</h1>
          <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full <?= $enabled ? 'bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' ?>">
            <span class="w-1.5 h-1.5 rounded-full <?= $enabled ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
            <?= $enabled ? 'Aktif' : 'Nonaktif' ?>
          </span>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-3 mb-5">
          Izinkan sistem eksternal (automation/AI) menulis <strong>draft</strong> lewat Ingest API ber-token. Konten masuk selalu sebagai draft/inactive — aktivasi tetap kamu. Hermes <strong>tidak pernah</strong> mengirim email atau memegang kredensial pengirim.
        </p>

        <?php if ($tokenOnce !== ''): ?>
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-5">
          <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-1.5">Token baru — salin sekarang, tidak bisa dilihat ulang</p>
          <div class="flex items-center gap-2">
            <code id="hermesTokenOnce" class="flex-1 min-w-0 px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-amber-200 dark:border-amber-800 text-xs font-mono text-gray-800 dark:text-gray-200 break-all"><?= e($tokenOnce) ?></code>
            <button type="button" onclick="hermesCopy('hermesTokenOnce', this)" class="shrink-0 px-3 py-2 rounded-lg text-xs font-semibold text-white bg-brand hover:opacity-90 transition">Salin</button>
          </div>
        </div>
        <?php endif; ?>

        <!-- Konfigurasi -->
        <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/save-hermes"
              class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 mb-5">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <div class="space-y-4">
            <label class="flex items-start gap-3 cursor-pointer">
              <input type="checkbox" name="hermes_enabled" value="1" <?= $enabled ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 rounded accent-[var(--brand-color)]">
              <span>
                <span class="block text-sm font-medium text-gray-900 dark:text-white">Aktifkan Hermes Agent</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Endpoint menerima permintaan ber-token. Butuh token dibuat dulu.</span>
              </span>
            </label>

            <div class="border-t border-gray-100 dark:border-gray-800"></div>

            <label class="flex items-start gap-3 cursor-pointer">
              <input type="checkbox" name="hermes_read_enabled" value="1" <?= $read ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 rounded accent-[var(--brand-color)]">
              <span>
                <span class="block text-sm font-medium text-gray-900 dark:text-white">Izinkan baca data bisnis</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Membuka endpoint <span class="font-mono">/api/hermes/insights</span> agar Hermes menganalisis bisnis: omzet, produk, traffic, affiliate, email/broadcast. <span class="text-gray-600 dark:text-gray-300 font-medium">Read-only, agregat, tanpa data pribadi pelanggan.</span> Terpisah dari tier manajemen. Butuh Hermes aktif.</span>
              </span>
            </label>

            <div class="border-t border-gray-100 dark:border-gray-800"></div>

            <label class="flex items-start gap-3 cursor-pointer">
              <input type="checkbox" name="hermes_manage_enabled" value="1" <?= $manage ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 rounded accent-[var(--brand-color)]">
              <span>
                <span class="block text-sm font-medium text-gray-900 dark:text-white">Izinkan tier manajemen</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Membuka aksi tulis berdampak (mis. memasukkan follow-up ke antrean kirim). <span class="text-amber-600 dark:text-amber-400 font-medium">Aktifkan hanya bila sistem eksternalmu tepercaya.</span> Butuh Hermes aktif.</span>
              </span>
            </label>

            <div class="flex justify-end pt-1">
              <button type="submit" data-loading data-loading-text="Menyimpan..." class="px-5 py-2 rounded-lg text-sm font-semibold text-white bg-brand hover:opacity-90 transition">Simpan</button>
            </div>
          </div>
        </form>

        <!-- Kapabilitas (registry) -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-5">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Kapabilitas Ingest</h2>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Apa yang bisa diminta Hermes. Bertambah seiring fitur baru.</p>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($registry as $key => $c):
              $on = $c['available'] && (!$c['requires_manage'] || $manage); ?>
            <div class="flex items-start gap-3 px-5 py-3.5">
              <span class="mt-0.5 shrink-0 inline-flex items-center justify-center w-5 h-5 rounded-full <?= $on ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500' ?>">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= e($c['title']) ?>
                  <span class="font-mono text-[11px] text-gray-400">· <?= e($key) ?></span>
                  <?php if (!$c['available']): ?><span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">segera</span><?php endif; ?>
                  <?php if ($c['requires_manage']): ?><span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-600 dark:bg-amber-900/20 dark:text-amber-400">butuh manajemen</span><?php endif; ?>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= e($c['description']) ?></p>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Kapabilitas Baca Data Bisnis (insights) -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden mb-5">
          <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
            <div>
              <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Baca Data Bisnis</h2>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Metrik agregat yang bisa dianalisis Hermes (read-only, tanpa data pribadi).</p>
            </div>
            <span class="shrink-0 inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full <?= $read ? 'bg-green-50 text-green-600 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' ?>">
              <span class="w-1.5 h-1.5 rounded-full <?= $read ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
              <?= $read ? 'Aktif' : 'Nonaktif' ?>
            </span>
          </div>
          <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Endpoint insights (GET, ber-token)</label>
            <div class="flex items-center gap-2">
              <code id="hermesInsights" class="flex-1 min-w-0 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-xs font-mono text-gray-700 dark:text-gray-300 break-all"><?= e($insightsEndpoint) ?>?metric=business_summary&period=30d</code>
              <button type="button" onclick="hermesCopy('hermesInsights', this)" class="shrink-0 px-3 py-2 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Salin</button>
            </div>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($insights as $metric => $def): ?>
            <div class="flex items-start gap-3 px-5 py-3">
              <span class="mt-0.5 shrink-0 inline-flex items-center justify-center w-5 h-5 rounded-full <?= $read ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500' ?>">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
              </span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= e($def['title']) ?>
                  <span class="font-mono text-[11px] text-gray-400">· <?= e($metric) ?></span>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= e($def['description']) ?></p>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="px-5 py-3 bg-gray-50/60 dark:bg-gray-800/30 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
            <span class="font-medium text-gray-600 dark:text-gray-300">Catatan data:</span>
            Omzet &amp; "lunas" = order berstatus <span class="font-mono">paid</span>. Averion tidak melacak refund di status order (omzet tidak dikurangi refund). Averion tidak menyimpan belanja iklan, profit, atau atribusi iklan. Zona waktu &amp; definisi lengkap tersedia di endpoint discovery.
          </div>
        </div>

        <!-- Endpoint + Token -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
          <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Endpoint &amp; Token</h2>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Endpoint discovery (GET, ber-token)</label>
          <div class="flex items-center gap-2 mb-4">
            <code id="hermesEndpoint" class="flex-1 min-w-0 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-xs font-mono text-gray-700 dark:text-gray-300 break-all"><?= e($endpoint) ?></code>
            <button type="button" onclick="hermesCopy('hermesEndpoint', this)" class="shrink-0 px-3 py-2 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Salin</button>
          </div>

          <div class="flex items-center justify-between gap-3 flex-wrap">
            <p class="text-xs text-gray-500 dark:text-gray-400">
              Token akses: <?= $tokenSet ? '<span class="text-green-600 dark:text-green-400 font-medium">tersimpan</span> (hanya hash — nilai asli tak tersimpan).' : '<span class="text-amber-600 dark:text-amber-400 font-medium">belum ada</span> — buat dulu untuk mengaktifkan.' ?>
            </p>
            <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/hermes-token" id="hermesTokenForm">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <button type="button"
                      onclick="confirmAndSubmit('hermesTokenForm',{title:'<?= $tokenSet ? 'Putar Ulang Token?' : 'Buat Token?' ?>',message:'<?= $tokenSet ? 'Token lama langsung tidak berlaku. Sistem eksternal harus memakai token baru.' : 'Token akan dibuat dan ditampilkan sekali.' ?>',confirmText:'<?= $tokenSet ? 'Ya, Putar Ulang' : 'Ya, Buat' ?>',variant:'<?= $tokenSet ? 'danger' : 'primary' ?>'})"
                      class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><polyline points="21 3 21 8 16 8"/></svg>
                <?= $tokenSet ? 'Putar ulang token' : 'Buat token' ?>
              </button>
            </form>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<script>
function hermesCopy(id, btn) {
  var el = document.getElementById(id); if (!el) return;
  var text = el.textContent.trim();
  var done = function () { if (window.showToast) showToast('Disalin', 'success'); else { var o = btn.textContent; btn.textContent = 'Tersalin'; setTimeout(function(){ btn.textContent = o; }, 1500); } };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done).catch(done);
  else { var r = document.createRange(); r.selectNode(el); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); try { document.execCommand('copy'); } catch (e) {} s.removeAllRanges(); done(); }
}
</script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
