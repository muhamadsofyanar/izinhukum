<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle = $topbarTitle = 'Catatan Rilis';

// Tambahkan rilis baru di atas dan samakan versinya dengan version.php.
$releases = [
    [
        'version' => '1.2.270',
        'title' => 'Operasional portal dan pengelolaan kajian',
        'changes' => [
            'Dasbor menampilkan antrean bukti transfer, anggota yang punya akses aktif, dan kajian mendatang.',
            'Pengurus dapat menyaring kajian menurut jenis, program, status, dan tanggal.',
            'Container memproses pekerjaan otomatis tanpa menunggu kunjungan halaman.',
            'Cadangan otomatis dicatat setelah berkas selesai dan tersimpan.',
            'WhatsApp mulai dikirim saat provider aktif, dan katalog menjelaskan kapan akses dibuka.',
        ],
    ],
    [
        'version' => '1.2.269',
        'title' => 'Jadwal Kajian terhubung dengan e-course',
        'changes' => [
            'Anggota bisa membuka materi e-course dari detail kajian yang terkait dengan produk kelas.',
            'Halaman e-course menampilkan jadwal kajian berikutnya untuk program tersebut.',
            'Jadwal anggota menampilkan kajian mendatang, riwayat, dan status pendaftaran.',
        ],
    ],
    [
        'version' => '1.2.268',
        'title' => 'Pengecekan versi terbaru',
        'changes' => [
            'Cek Update membaca versi rilis dari API GitHub terlebih dahulu agar rilis terbaru lebih cepat terdeteksi.',
        ],
    ],
    [
        'version' => '1.2.267',
        'title' => 'Ruang baca PDF lebih luas di HP',
        'changes' => [
            'PDF memakai hampir seluruh lebar layar HP dan bisa dibuka dalam mode layar penuh.',
            'Kontrol ringkas di bawah PDF mendukung pindah halaman dan lompat ke nomor halaman.',
        ],
    ],
    [
        'version' => '1.2.266',
        'title' => 'Tampilan PDF di layar HP',
        'changes' => [
            'Halaman PDF menyesuaikan lebar area materi agar sisi kanan dan kiri terlihat.',
            'Beralih antarhalaman PDF langsung dari Area Member.',
        ],
    ],
    [
        'version' => '1.2.265',
        'title' => 'Baca PDF langsung di Materi',
        'changes' => [
            'Materi PDF terproteksi bisa dibaca di Area Member atau dibuka di tab baru.',
            'Tombol unduh PDF tetap tersedia pada halaman materi.',
        ],
    ],
    [
        'version' => '1.2.264',
        'title' => 'Catatan rilis di dalam aplikasi',
        'changes' => [
            'Buka Catatan Rilis langsung dari halaman Update Sistem.',
            'Lihat ringkasan perubahan dan penanda versi yang sedang terpasang.',
        ],
    ],
    [
        'version' => '1.2.263',
        'title' => 'Tampilan baru STIFIn Mulia',
        'changes' => [
            'Dashboard pengurus dan anggota memakai identitas STIFIn Mulia.',
            'Akses ke jadwal dan pengelolaan Kajian tampil langsung dari dashboard.',
            'Halaman Kajian menonjolkan judul, pemateri, dan jadwal.',
        ],
    ],
    [
        'version' => '1.2.262',
        'title' => 'Rilis awal STIFIn Mulia',
        'changes' => [
            'Installer memakai nama STIFIn Mulia dan aktivasi memakai lisensi per domain dari penerbit Darmutiara.',
            'Modul Kajian menyediakan jadwal, pendaftaran anggota, pengingat kalender, dan diskusi dengan moderasi.',
            'Menu Update Sistem mendukung pengecekan versi dan pemicu deploy Coolify.',
        ],
    ],
];
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">
  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>
  <div class="flex min-w-0 flex-1 flex-col overflow-hidden lg:ml-60">
    <?php include __DIR__ . '/../../partials/topbar.php'; ?>
    <main class="flex-1 overflow-y-auto p-4 sm:p-5 lg:p-6">
      <div class="mx-auto max-w-3xl">
        <header class="dmi-hero mb-6">
          <div>
            <h2>Catatan Rilis</h2>
            <p>STIFIn Mulia · terpasang v<?= e(APP_VERSION) ?></p>
          </div>
          <a class="dmi-cta" href="<?= e(APP_URL) ?>/admin/update"><span aria-hidden="true">←</span> Update Sistem</a>
        </header>
        <ol class="space-y-4" aria-label="Riwayat versi STIFIn Mulia">
          <?php foreach ($releases as $release): ?>
          <?php if (version_compare($release['version'], APP_VERSION, '>')) { continue; } ?>
          <li class="dmi-kajian-panel rounded-xl border border-gray-200 bg-white p-4 sm:p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-3">
              <span class="rounded-lg bg-gray-100 px-2 py-1 font-mono text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">v<?= e($release['version']) ?></span>
              <?php if ($release['version'] === APP_VERSION): ?>
              <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-200">Versi terpasang</span>
              <?php endif; ?>
            </div>
            <h3 class="mt-3 text-lg font-bold text-gray-900 dark:text-white"><?= e($release['title']) ?></h3>
            <ul class="ml-5 mt-2 list-disc space-y-1 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
              <?php foreach ($release['changes'] as $change): ?>
              <li><?= e($change) ?></li>
              <?php endforeach; ?>
            </ul>
          </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
