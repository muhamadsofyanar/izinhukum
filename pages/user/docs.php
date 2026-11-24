<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

$pageTitle   = 'Panduan';
$topbarTitle = 'Panduan';

$appName = getSetting('app_name', 'STIFIn Mulia');
$uid     = (int) ($_SESSION['user_id'] ?? 0);

// ─── Deteksi fitur aktif → tampilkan hanya section yang relevan (adaptif, white-label) ───
$dPoints  = function_exists('pointsEnabled') && pointsEnabled();
$dAff     = function_exists('affiliateProgramEnabled') && affiliateProgramEnabled();
$dTickets = function_exists('ticketIsBuyer') && ticketIsBuyer($uid);
$dLicense = false;
try {
    $_l = getDB()->prepare("SELECT id FROM product_licenses WHERE user_id = ? LIMIT 1");
    $_l->execute([$uid]);
    $dLicense = (bool) $_l->fetch();
} catch (Throwable $e) { $dLicense = false; }

// ─── Helper: teks dwibahasa inline (Indonesia / Malaysia) ───
$L = function (string $id, string $my): string {
    return '<span data-lang="id">' . $id . '</span>'
         . '<span data-lang="my" class="hidden">' . $my . '</span>';
};

// ─── Helper UI konten ───
$info  = fn(string $h): string => '<div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 mb-4 flex gap-2.5"><svg class="w-4 h-4 shrink-0 mt-0.5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5C17.7 10.2 18 9 18 7.5a6 6 0 0 0-12 0c0 1.5.5 2.7 1.5 4 .8.8 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg><p class="text-sm text-blue-700 dark:text-blue-300">' . $h . '</p></div>';
$warn  = fn(string $h): string => '<div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4 mb-4 flex gap-2.5"><svg class="w-4 h-4 shrink-0 mt-0.5 text-yellow-600 dark:text-yellow-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><p class="text-sm text-yellow-700 dark:text-yellow-300">' . $h . '</p></div>';
$h3    = fn(string $t): string => '<h3 class="font-semibold text-gray-900 dark:text-white mb-2 mt-1">' . $t . '</h3>';
$ul    = fn(array $items): string => '<ul class="space-y-1.5 mb-5 list-disc pl-5">' . implode('', array_map(fn($i) => '<li>' . $i . '</li>', $items)) . '</ul>';
$steps = function (array $items): string {
    $o = '<div class="space-y-1 mb-5">';
    foreach ($items as $i => [$title, $desc]) {
        $o .= '<div class="flex gap-4 py-2">'
            . '<div class="w-8 h-8 rounded-full text-white flex-shrink-0 flex items-center justify-center text-sm font-bold" style="background:var(--brand-color)">' . ($i + 1) . '</div>'
            . '<div class="pt-0.5"><p class="font-medium text-gray-800 dark:text-gray-200">' . $title . '</p>'
            . '<p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">' . $desc . '</p></div></div>';
    }
    return $o . '</div>';
};

$U = fn(string $p): string => e(APP_URL) . $p; // helper URL internal

// ─── Kumpulkan section (nav + body selalu sinkron; hanya yang aktif) ───
$docs = [];
$grp  = function (string $idLabel, string $myLabel) use (&$docs): void { $docs[] = ['group' => [$idLabel, $myLabel]]; };
$doc  = function (string $id, string $tid, string $tmy, string $desc, string $body) use (&$docs): void {
    $docs[] = ['id' => $id, 'tid' => $tid, 'tmy' => $tmy, 'desc' => $desc, 'body' => $body];
};

// ═══ Grup: Mulai ═══
$grp('Mulai', 'Permulaan');

$doc('masuk', 'Masuk ke Akun', 'Log Masuk ke Akaun',
    'Cara masuk ke dashboard member dan menjaga akun tetap aman.',
    $steps([
        ['Buka halaman masuk', 'Klik tombol <strong>Masuk</strong> / <strong>Login</strong> di situs, lalu isi email & kata sandi yang kamu daftarkan saat membeli.'],
        ['Verifikasi (bila diminta)', 'Untuk keamanan, sistem bisa mengirim kode OTP ke email. Masukkan kodenya untuk lanjut.'],
        ['Lupa kata sandi?', 'Klik <strong>Lupa Password</strong> di halaman masuk, lalu ikuti tautan reset yang dikirim ke email kamu.'],
    ])
    . $info('Gunakan email yang <strong>sama</strong> dengan yang kamu pakai saat checkout agar semua produk langsung terhubung ke akunmu.')
);

$doc('akses-produk', 'Mengakses Produk Saya', 'Mengakses Produk Saya',
    'Setelah pembayaran berhasil, produk otomatis masuk ke akunmu.',
    $h3('Di mana produk saya?')
    . '<p class="mb-4">Semua produk yang sudah kamu beli ada di menu <a href="' . $U('/member') . '" class="text-[var(--brand-color)] font-medium hover:underline">Area Member</a>. Klik produknya untuk membuka materi, modul, kelas, atau membership di dalamnya.</p>'
    . $steps([
        ['Buka Area Member', 'Dari dashboard, klik menu <strong>Area Member</strong> di sisi kiri.'],
        ['Pilih produk', 'Klik kartu produk yang ingin kamu akses.'],
        ['Mulai belajar/menggunakan', 'Materi, video, file, atau tautan akses akan terbuka di halaman produk tersebut.'],
    ])
    . $info('Beberapa produk berupa <strong>akses eksternal</strong> (link/aplikasi lain). Tautannya tampil di halaman produk di Area Member setelah pembayaranmu dikonfirmasi.')
);

$doc('belum-muncul', 'Produk Belum Muncul?', 'Produk Belum Muncul?',
    'Langkah cepat jika produk yang dibeli belum terlihat di Area Member.',
    $warn('Penyebab paling umum: <strong>pembayaran belum terkonfirmasi</strong>. Produk baru terbuka otomatis setelah status transaksi menjadi <strong>Lunas</strong>.')
    . $ul([
        'Cek status di menu <a href="' . $U('/transactions') . '" class="text-[var(--brand-color)] font-medium hover:underline">Transaksi</a>. Jika masih <em>Menunggu Pembayaran</em>, selesaikan pembayaran dulu.',
        'Baru saja transfer? Konfirmasi pembayaran bisa butuh beberapa menit. Muat ulang halaman Area Member setelahnya.',
        'Pastikan kamu masuk dengan <strong>email yang sama</strong> seperti saat membeli.',
        'Produk berupa lisensi/akses pihak ketiga kadang "sedang disiapkan" sebentar — tunggu lalu cek kembali.',
    ])
    . '<p>Masih belum muncul setelah pembayaran Lunas? ' . ($dTickets
        ? 'Hubungi kami lewat <a href="' . $U('/bantuan') . '" class="text-[var(--brand-color)] font-medium hover:underline">Tiket Support</a> dengan menyertakan nomor order.'
        : 'Hubungi admin dengan menyertakan nomor order kamu.') . '</p>'
);

// ═══ Grup: Produk & File ═══
$grp('Produk & File', 'Produk & Fail');

$doc('download', 'File & Download', 'Fail & Muat Turun',
    'Mengunduh file yang disertakan dalam produkmu.',
    '<p class="mb-4">File yang bisa diunduh (ebook, template, lampiran materi) tersedia di menu <a href="' . $U('/downloads') . '" class="text-[var(--brand-color)] font-medium hover:underline">File Downloads</a> dan/atau di dalam halaman produk pada Area Member.</p>'
    . $ul([
        'Klik tombol <strong>Download</strong> pada file yang diinginkan.',
        'Jika file besar, tunggu hingga unduhan selesai sebelum menutup halaman.',
        'Simpan file di perangkat agar bisa diakses kapan saja.',
    ])
);

if ($dLicense) {
    $doc('lisensi', 'Lisensi Saya', 'Lesen Saya',
        'Melihat dan menggunakan kode lisensi produkmu.',
        '<p class="mb-4">Produk yang memakai lisensi muncul di menu <a href="' . $U('/member/my-licenses') . '" class="text-[var(--brand-color)] font-medium hover:underline">Lisensi Saya</a>. Di sana kamu bisa melihat kode/kunci lisensi beserta statusnya.</p>'
        . $info('Jika lisensi masih berstatus <strong>sedang disiapkan</strong>, artinya kunci sedang diterbitkan otomatis. Cek kembali beberapa saat lagi.')
    );
}

$doc('sertifikat', 'Sertifikat Saya', 'Sijil Saya',
    'Mengunduh sertifikat setelah menyelesaikan kelas.',
    '<p class="mb-4">Sertifikat yang tersedia untuk kamu ada di menu <a href="' . $U('/certificates') . '" class="text-[var(--brand-color)] font-medium hover:underline">Sertifikat Saya</a>.</p>'
    . $ul([
        'Sertifikat biasanya terbit setelah syarat kelas terpenuhi (mis. materi selesai).',
        'Klik <strong>Unduh</strong> untuk menyimpan sertifikat dalam bentuk file.',
    ])
);

// ═══ Grup: Transaksi ═══
$grp('Transaksi', 'Transaksi');

$doc('transaksi', 'Transaksi & Invoice', 'Transaksi & Invois',
    'Melihat riwayat pembelian, invoice, dan melanjutkan pembayaran.',
    '<p class="mb-4">Semua pembelianmu tercatat di menu <a href="' . $U('/transactions') . '" class="text-[var(--brand-color)] font-medium hover:underline">Transaksi</a>, lengkap dengan status dan invoice.</p>'
    . $steps([
        ['Buka Transaksi', 'Klik menu <strong>Transaksi</strong> di sidebar.'],
        ['Lihat detail/invoice', 'Klik salah satu transaksi untuk melihat rincian dan invoice.'],
        ['Lanjutkan pembayaran', 'Jika status <em>Menunggu Pembayaran</em>, buka transaksinya lalu selesaikan pembayaran.'],
    ])
);

// ═══ Grup: Lainnya ═══
$grp('Lainnya', 'Lain-lain');

if ($dPoints) {
    $doc('poin', 'Poin & Reward', 'Mata & Ganjaran',
        'Mengumpulkan dan menukar poin dari pembelianmu.',
        '<p class="mb-4">Setiap transaksi yang memenuhi syarat bisa memberi poin. Lihat saldo & tukar poin di menu <a href="' . $U('/rewards') . '" class="text-[var(--brand-color)] font-medium hover:underline">' . e(function_exists('pointsMenuLabel') ? pointsMenuLabel() : 'Poin Reward') . '</a>.</p>'
        . $ul([
            'Poin masuk otomatis setelah transaksi berstatus Lunas.',
            'Tukar poin menjadi potongan harga saat checkout (bila tersedia).',
        ])
    );
}

if ($dAff) {
    $doc('afiliasi', 'Program Afiliasi', 'Program Afiliat',
        'Dapatkan komisi dengan merekomendasikan produk.',
        '<p class="mb-4">Ambil link afiliasimu di menu <a href="' . $U('/affiliate') . '" class="text-[var(--brand-color)] font-medium hover:underline">Affiliate</a>, lalu bagikan. Komisi tercatat otomatis saat ada pembelian lewat linkmu.</p>'
        . $ul([
            'Salin link afiliasi (atau link custom) dari halaman Affiliate.',
            'Pantau komisi & status di halaman yang sama.',
            'Ajukan pencairan bila saldo komisi sudah mencapai minimal.',
        ])
    );
}

$doc('akun', 'Akun & Profil', 'Akaun & Profil',
    'Memperbarui data diri, nomor WhatsApp, dan kata sandi.',
    '<p class="mb-4">Kelola data akunmu di menu <a href="' . $U('/profile') . '" class="text-[var(--brand-color)] font-medium hover:underline">Profil</a>.</p>'
    . $ul([
        'Perbarui nama, email, dan nomor WhatsApp agar notifikasi sampai.',
        'Ganti kata sandi secara berkala untuk keamanan.',
    ])
);

$doc('bantuan', 'Butuh Bantuan?', 'Perlukan Bantuan?',
    'Jika jawabannya tidak ada di panduan ini.',
    '<p class="mb-4">Buka menu <strong>Pusat Bantuan</strong> di atas tombol Keluar, lalu pilih <strong>Panduan</strong>' . ($dTickets ? ' atau <strong>Tiket Support</strong>' : '') . '. Masing-masing membuka halaman tersendiri.</p>' .
    ($dTickets
        ? '<p class="mb-4">Kirim pertanyaanmu lewat <a href="' . $U('/bantuan') . '" class="text-[var(--brand-color)] font-medium hover:underline">Tiket Support</a>. Sertakan <strong>nomor order</strong> dan jelaskan kendalamu agar cepat dibantu.</p>'
        : '<p class="mb-4">Hubungi admin melalui kontak resmi yang tersedia. Sertakan <strong>nomor order</strong> dan jelaskan kendalamu agar cepat dibantu.</p>')
    . $info('Sebagian besar kendala akses teratasi dengan memastikan pembayaran sudah <strong>Lunas</strong> dan masuk dengan <strong>email yang benar</strong>.')
);
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<style>
  #docsScroll { scroll-behavior: smooth; }
  section[id] { scroll-margin-top: 1.5rem; }
  .lang-btn.lang-active { background: var(--brand-color); border-color: var(--brand-color); color: #fff; }
  .doc-nav.doc-nav-active { background: color-mix(in srgb, var(--brand-color) 12%, transparent); color: var(--brand-color); font-weight: 600; }
</style>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-user.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main id="docsScroll" class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- Header -->
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-3">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Panduan <?= e($appName) ?></h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            <?= $L('Cara mengakses produk & member area. Baca di sini sebelum bertanya.', 'Cara mengakses produk & kawasan ahli. Baca di sini sebelum bertanya.') ?>
          </p>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center gap-2.5 shrink-0">
          <div class="relative w-full sm:w-64 lg:w-72">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input id="docsSearch" type="search" autocomplete="off" spellcheck="false" placeholder="Cari panduan…"
                   class="w-full pl-9 pr-9 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <button id="docsSearchClear" type="button" aria-label="Bersihkan pencarian"
                    class="hidden absolute right-2 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button onclick="setLang('id')" id="lang-id" class="lang-btn px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 transition">Indonesia</button>
            <button onclick="setLang('my')" id="lang-my" class="lang-btn px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 transition">Malaysia</button>
          </div>
        </div>
      </div>

      <p id="docsSearchCount" class="hidden text-xs text-gray-400 dark:text-gray-500 mb-5"></p>

      <div class="flex gap-8 relative">

        <!-- Nav docs (sticky) -->
        <aside class="hidden lg:block w-64 flex-shrink-0">
          <div class="sticky top-0 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 overflow-y-auto" style="max-height:calc(100vh - 7rem)">
            <?php $grpOpen = false; ?>
            <?php foreach ($docs as $d): ?>
              <?php if (isset($d['group'])): ?>
                <?php if ($grpOpen): ?></nav></div><?php endif; ?>
                <div class="mb-1 mt-4 first:mt-0">
                  <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2 px-2"><?= $L($d['group'][0], $d['group'][1]) ?></p>
                  <nav class="space-y-0.5">
                <?php $grpOpen = true; ?>
              <?php else: ?>
                <a href="#<?= e($d['id']) ?>" class="doc-nav flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800/60 transition-colors"><?= $L($d['tid'], $d['tmy']) ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($grpOpen): ?></nav></div><?php endif; ?>
          </div>
        </aside>

        <!-- Konten -->
        <div class="flex-1 min-w-0 space-y-5">
          <?php foreach ($docs as $d): ?>
            <?php if (isset($d['group'])) continue; ?>
            <section id="<?= e($d['id']) ?>" class="scroll-mt-6">
              <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 lg:p-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2"><?= $L($d['tid'], $d['tmy']) ?></h2>
                <p class="text-gray-500 dark:text-gray-400 mb-6"><?= e($d['desc']) ?></p>
                <hr class="border-gray-100 dark:border-gray-800 mb-6">
                <div class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed"><?= $d['body'] ?></div>
              </div>
            </section>
          <?php endforeach; ?>

          <p id="docsNoResults" class="hidden text-center text-sm text-gray-400 dark:text-gray-500 py-14">
            Tidak ada panduan yang cocok dengan pencarianmu.
          </p>
        </div>

      </div>

    </main>
  </div>
</div>

<script>
let currentDocsLang = localStorage.getItem('docs_lang') || 'id';
if (currentDocsLang !== 'id' && currentDocsLang !== 'my') currentDocsLang = 'id';

function setLang(lang) {
  currentDocsLang = lang;
  document.querySelectorAll('[data-lang]').forEach(el => el.classList.add('hidden'));
  document.querySelectorAll('[data-lang="' + lang + '"]').forEach(el => el.classList.remove('hidden'));
  document.querySelectorAll('.lang-btn').forEach(btn => btn.classList.remove('lang-active'));
  const active = document.getElementById('lang-' + lang);
  if (active) active.classList.add('lang-active');
  localStorage.setItem('docs_lang', lang);
  if (typeof window.__applyDocsSearch === 'function') window.__applyDocsSearch();
}

document.addEventListener('DOMContentLoaded', () => {
  setLang(currentDocsLang);

  const sections = Array.from(document.querySelectorAll('section[id]'));
  const navLinks = Array.from(document.querySelectorAll('.doc-nav'));
  const navGroups = [...new Set(navLinks.map(l => l.closest('nav')).filter(Boolean))];

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        navLinks.forEach(link => {
          link.classList.toggle('doc-nav-active', link.getAttribute('href') === '#' + entry.target.id);
        });
      }
    });
  }, { root: document.getElementById('docsScroll'), rootMargin: '-10% 0px -75% 0px' });
  sections.forEach(s => observer.observe(s));

  navLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      const target = document.getElementById(link.getAttribute('href').slice(1));
      if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
  });

  const input    = document.getElementById('docsSearch');
  const clearBtn = document.getElementById('docsSearchClear');
  const noRes    = document.getElementById('docsNoResults');
  const countEl  = document.getElementById('docsSearchCount');

  function sectionText(sec) {
    let t = '';
    sec.querySelectorAll('[data-lang="' + currentDocsLang + '"]').forEach(el => { t += ' ' + el.textContent; });
    t += ' ' + sec.textContent; // sertakan teks non-dwibahasa (body)
    return t.toLowerCase();
  }
  function navText(link) {
    const span = link.querySelector('[data-lang="' + currentDocsLang + '"]');
    return (span ? span.textContent : link.textContent).toLowerCase();
  }

  function apply() {
    const q = (input.value || '').trim().toLowerCase();
    clearBtn.classList.toggle('hidden', q === '');
    let visible = 0;
    sections.forEach(sec => {
      const link  = document.querySelector('.doc-nav[href="#' + sec.id + '"]');
      const hay   = sectionText(sec) + ' ' + (link ? navText(link) : '');
      const match = q === '' || hay.indexOf(q) !== -1;
      sec.classList.toggle('hidden', !match);
      if (link) link.classList.toggle('hidden', !match);
      if (match) visible++;
    });
    navGroups.forEach(nav => {
      const any = Array.from(nav.querySelectorAll('.doc-nav')).some(a => !a.classList.contains('hidden'));
      if (nav.parentElement) nav.parentElement.classList.toggle('hidden', !any);
    });
    noRes.classList.toggle('hidden', visible !== 0);
    if (q === '') { countEl.classList.add('hidden'); }
    else { countEl.classList.remove('hidden'); countEl.textContent = visible + ' panduan ditemukan untuk "' + input.value.trim() + '"'; }
  }
  window.__applyDocsSearch = apply;

  if (input) {
    input.addEventListener('input', apply);
    input.addEventListener('keydown', (e) => { if (e.key === 'Escape') { input.value = ''; apply(); } });
    clearBtn.addEventListener('click', () => { input.value = ''; apply(); input.focus(); });
  }
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
