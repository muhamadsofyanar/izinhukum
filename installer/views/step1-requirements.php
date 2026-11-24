<?php
if (!defined('INSTALLER_ENTRY')) { http_response_code(403); exit('Akses tidak diizinkan.'); }

$currentStep = 1;
$stepTitle   = 'Cek Kebutuhan';

// ─── Kebutuhan server (definisi bersama dgn guard mark-step.php) ─
require_once dirname(__DIR__) . '/requirements.php';

$checks      = installer_required_checks();       // WAJIB (blocker)
$recommended = installer_recommended_checks();    // gd/zip (opsional)

// Hanya kebutuhan WAJIB yang menentukan boleh-lanjut. gd/zip tidak memblokir.
$allPass = installer_required_all_pass();

// ─── Deteksi mod_rewrite untuk hosting Apache ────────────────
$serverSoftware  = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
$isApache        = str_contains($serverSoftware, 'apache') || str_contains($serverSoftware, 'litespeed');
// PHP-FPM/CGI/LiteSpeed tidak selalu menyediakan daftar modul Apache.
// null = belum dapat diverifikasi, bukan berarti routing pasti aktif/nonaktif.
$modRewriteOk = null;
if ($isApache && function_exists('apache_get_modules')) {
    $modRewriteOk = in_array('mod_rewrite', apache_get_modules(), true);
}

include __DIR__ . '/../partials/installer-head.php';
?>

<p class="text-sm text-gray-400 mb-5">
  Memastikan semua kebutuhan server terpenuhi sebelum melanjutkan.
</p>

<p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Wajib (harus lolos)</p>

<div class="space-y-3 mb-5">
  <?php foreach ($checks as $check): ?>
    <div class="flex items-center gap-3 border border-gray-100 rounded-xl px-4 py-3.5 <?= $check['pass'] ? 'bg-green-50/40' : 'bg-red-50/40' ?>">
      <?php if ($check['pass']): ?>
        <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
        </svg>
      <?php else: ?>
        <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      <?php endif; ?>
      <span class="text-sm text-gray-700">
        <?= e($check['label']) ?>
        <?php if ($check['detail']): ?>
          <span class="text-gray-400 ml-1"><?= e($check['detail']) ?></span>
        <?php endif; ?>
      </span>
    </div>
  <?php endforeach; ?>

  <!-- ── Routing: front controller (non-blocking info) ──────────────────── -->
  <?php if ($modRewriteOk === false): ?>
    <div class="flex items-start gap-3 border border-amber-200 rounded-xl px-4 py-3.5 bg-amber-50/50">
      <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
      </svg>
      <div>
        <p class="text-sm font-semibold text-amber-800">Apache mod_rewrite belum aktif</p>
        <p class="text-xs text-amber-700 mt-1 leading-relaxed">
          Aktifkan <code class="font-mono bg-amber-100 px-1 rounded">mod_rewrite</code> di konfigurasi Apache,
          atau hubungi penyedia hosting untuk mengaktifkannya. Routing URL bersih tidak akan berfungsi tanpa modul ini.
        </p>
      </div>
    </div>
  <?php elseif ($modRewriteOk === true): ?>
    <div class="flex items-center gap-3 border border-gray-100 rounded-xl px-4 py-3.5 bg-green-50/40">
      <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
      </svg>
      <span class="text-sm text-gray-700">
        Routing URL bersih
        <span class="text-gray-400 ml-1">(mod_rewrite terdeteksi pada Apache)</span>
      </span>
    </div>
  <?php else: ?>
    <div class="flex items-start gap-3 border border-amber-200 rounded-xl px-4 py-3.5 bg-amber-50/50">
      <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="9" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M12 11v5m0-9h.01"/>
      </svg>
      <div>
        <p class="text-sm font-semibold text-amber-800">Routing perlu diverifikasi</p>
        <p class="text-xs text-amber-700 mt-1 leading-relaxed">
          Daftar modul web server tidak tersedia untuk pemeriksaan otomatis. Ini tidak menghalangi instalasi.
          Pada Apache/LiteSpeed, pastikan aturan routing <code class="font-mono bg-amber-100 px-1 rounded">.htaccess</code> diizinkan.
          Pada Nginx, pastikan konfigurasi routing mengarah ke <code class="font-mono bg-amber-100 px-1 rounded">index.php</code>.
          Hubungi penyedia hosting jika halaman selain beranda tidak dapat dibuka setelah instalasi.
        </p>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ── Direkomendasikan (opsional — tidak memblokir instalasi) ─────────── -->
<p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Direkomendasikan (opsional)</p>

<div class="space-y-3 mb-5">
  <?php foreach ($recommended as $rec): ?>
    <div class="flex items-center gap-3 border rounded-xl px-4 py-3.5 <?= $rec['pass'] ? 'border-gray-100 bg-green-50/40' : 'border-amber-200 bg-amber-50/50' ?>">
      <?php if ($rec['pass']): ?>
        <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
        </svg>
      <?php else: ?>
        <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
      <?php endif; ?>
      <span class="text-sm text-gray-700 flex-1 min-w-0">
        <?= e($rec['label']) ?>
        <?php if ($rec['detail']): ?>
          <span class="block text-xs text-gray-400 mt-0.5"><?= e($rec['detail']) ?></span>
        <?php endif; ?>
      </span>
      <?php if (!$rec['pass']): ?>
        <span class="text-xs font-semibold text-amber-700 bg-amber-100 rounded-full px-2.5 py-1 flex items-center gap-1 flex-shrink-0">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
          </svg>
          Opsional
        </span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($allPass): ?>
  <!-- Tombol lanjut: submit hidden form dulu untuk set session, lalu redirect via action -->
  <a href="#"
     onclick="document.getElementById('mark-step1').submit(); return false;"
     class="block w-full bg-brand text-white text-center font-semibold py-3.5 rounded-xl hover:opacity-90 transition">
    Lanjut
  </a>

  <form id="mark-step1" method="POST" action="actions/mark-step.php" class="hidden">
    <input type="hidden" name="step" value="1">
    <input type="hidden" name="_csrf" value="<?= e($_SESSION['installer_csrf'] ?? '') ?>">
  </form>
<?php else: ?>
  <!-- Instruksi perbaikan untuk item yang gagal -->
  <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3.5 mb-4 text-left">
    <p class="text-xs font-semibold text-amber-800 mb-1.5">Cara memperbaiki</p>
    <ul class="text-xs text-amber-700 leading-relaxed space-y-1 list-disc pl-4">
      <li>Folder tidak bisa ditulis? Set izin lewat <span class="font-semibold">File Manager</span> hosting:
        <code class="font-mono bg-amber-100 px-1 rounded">chmod 755</code> pada folder tersebut.</li>
      <li>Ekstensi PHP belum aktif? Aktifkan lewat
        <span class="font-semibold">cPanel &rarr; Select PHP Version / PHP Extensions</span>,
        lalu centang ekstensi yang bertanda silang.</li>
      <li>Versi PHP terlalu lama? Ubah lewat
        <span class="font-semibold">cPanel &rarr; Select PHP Version</span> ke PHP 8.0 atau lebih baru.</li>
      <li>File <code class="font-mono bg-amber-100 px-1 rounded">stifin-install.sql</code> tidak ada?
        Pastikan semua file aplikasi ter-upload ke folder yang benar.</li>
    </ul>
    <p class="text-xs text-amber-600 mt-2">Setelah diperbaiki, klik <span class="font-semibold">Cek Ulang</span> di bawah.</p>
  </div>

  <button disabled
          class="block w-full bg-gray-100 text-gray-400 text-center font-semibold py-3.5 rounded-xl cursor-not-allowed select-none">
    Perbaiki Dulu Masalah di Atas
  </button>
  <a href="?step=1"
     class="block text-center text-sm text-brand mt-3 hover:underline">
    Cek Ulang
  </a>
<?php endif; ?>


<?php include __DIR__ . '/../partials/installer-foot.php'; ?>
