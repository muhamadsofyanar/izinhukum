<?php
if (!defined('INSTALLER_ENTRY')) { http_response_code(403); exit('Akses tidak diizinkan.'); }

$currentStep = 4;
$stepTitle   = ''; // Tidak ada judul step di halaman finish

// Installer harus sudah selesai (lock file ada)
$lockFile = dirname(__DIR__) . '/install.lock';
if (!file_exists($lockFile)) {
    header('Location: ?step=1');
    exit;
}

include __DIR__ . '/../partials/installer-head.php';
?>

<div class="flex flex-col items-center text-center py-2">

  <!-- Ikon centang sukses -->
  <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mb-5">
    <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
    </svg>
  </div>

  <h2 class="text-xl font-bold text-gray-900 mb-2">Aplikasi berhasil dipasang!</h2>
  <p class="text-sm text-gray-400 mb-6 leading-relaxed">
    Masuk dengan akun yang baru Anda buat untuk mulai menggunakan aplikasi.
  </p>

  <!-- Pesan keamanan -->
  <div class="w-full bg-amber-50 border border-amber-100 rounded-xl px-4 py-3.5 mb-6 text-left">
    <p class="text-xs font-semibold text-amber-700 mb-1">Penting — Langkah Keamanan</p>
    <p class="text-xs text-amber-600 leading-relaxed">
      Hapus folder <span class="font-mono font-semibold">installer/</span> melalui
      <span class="font-semibold">File Manager</span> cPanel setelah selesai.
      Folder ini tidak lagi dibutuhkan dan sebaiknya tidak ada di server produksi.
    </p>
  </div>

  <a href="../login"
     class="block w-full bg-brand text-white text-center font-semibold py-3.5 rounded-xl hover:opacity-90 transition">
    Buka Aplikasi
  </a>
</div>

<?php include __DIR__ . '/../partials/installer-foot.php'; ?>
