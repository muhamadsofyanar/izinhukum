<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Dokumentasi';
$topbarTitle = 'Dokumentasi Averion';

$appName = getSetting('app_name', 'STIFIn Mulia');

// Nilai untuk section "Integrasi App Eksternal" (admin-only page).
$intgRedirectSecret = redirectSigningSecret();
$intgWhSecret = '';
try { $intgWhSecret = (string) (getDB()->query("SELECT secret FROM outgoing_webhooks ORDER BY id DESC LIMIT 1")->fetchColumn() ?: ''); } catch (Throwable $e) {}

// ─── Helper: teks tiga bahasa inline (nav, judul, deskripsi) ───
// Dua bahasa: Indonesia (id) + Malaysia (my). Parameter $en diabaikan (legacy).
$L = function (string $id, string $my, string $en = ''): string {
    return '<span data-lang="id">' . $id . '</span>'
         . '<span data-lang="my" class="hidden">' . $my . '</span>';
};

// Terjemahan body Bahasa Malaysia per section (id => HTML). Diisi setelah semua
// helper siap (sebelum blok echo). Bila sebuah id belum ada di sini, $section
// otomatis menampilkan konten Indonesia sebagai fallback (tidak pernah kosong).
$myBodies = [];

// ─── Helper UI: komponen konten ───
$mono  = fn(string $c): string => '<code class="px-1 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono">' . $c . '</code>';
$info  = fn(string $h): string => '<div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 mb-4 flex gap-2.5"><svg class="w-4 h-4 shrink-0 mt-0.5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5C17.7 10.2 18 9 18 7.5a6 6 0 0 0-12 0c0 1.5.5 2.7 1.5 4 .8.8 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg><p class="text-sm text-blue-700 dark:text-blue-300">' . $h . '</p></div>';
$warn  = fn(string $h): string => '<div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4 mb-4 flex gap-2.5"><svg class="w-4 h-4 shrink-0 mt-0.5 text-yellow-600 dark:text-yellow-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><p class="text-sm text-yellow-700 dark:text-yellow-300">' . $h . '</p></div>';
$chk   = '<svg class="w-4 h-4 shrink-0 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
$code  = fn(string $c): string => '<div class="bg-gray-900 rounded-xl p-4 mb-5 overflow-x-auto"><code class="text-green-400 text-sm font-mono">' . $c . '</code></div>';
$h3    = fn(string $t): string => '<h3 class="font-semibold text-gray-900 dark:text-white mb-2 mt-1">' . $t . '</h3>';
$ul    = function (array $items): string {
    return '<ul class="space-y-1.5 mb-5 list-disc pl-5">' . implode('', array_map(fn($i) => '<li>' . $i . '</li>', $items)) . '</ul>';
};
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
$badges = function (array $items): string {
    $o = '<div class="flex flex-wrap gap-2 mb-5">';
    foreach ($items as $b) {
        $o .= '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">' . $b . '</span>';
    }
    return $o . '</div>';
};

// ─── Helper: bungkus section ───
$section = function (string $id, string $title, string $desc, string $body) use (&$myBodies): string {
    $bodyMy = $myBodies[$id] ?? $body; // fallback ke konten Indonesia bila terjemahan MY belum ada
    return '<section id="' . $id . '" class="scroll-mt-6">'
         . '<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-6 lg:p-8">'
         . '<h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">' . $title . '</h2>'
         . '<p class="text-gray-500 dark:text-gray-400 mb-6">' . $desc . '</p>'
         . '<hr class="border-gray-100 dark:border-gray-800 mb-6">'
         . '<div data-lang="id" class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">' . $body . '</div>'
         . '<div data-lang="my" class="hidden text-gray-600 dark:text-gray-300 text-sm leading-relaxed">' . $bodyMy . '</div>'
         . '</div></section>';
};

// ─── Helper: nav link sidebar docs ───
$nav = function (string $anchor, string $id, string $my, string $en) use ($L): string {
    return '<a href="#' . $anchor . '" class="doc-nav flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800/60 transition-colors">'
         . $L($id, $my, $en) . '</a>';
};
$navGroup = fn(string $label): string => '<p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2 px-2">' . $label . '</p>';
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<style>
  html { scroll-behavior: smooth; }
  #docsScroll { scroll-behavior: smooth; }
  section[id] { scroll-margin-top: 1.5rem; }
  .lang-btn.lang-active { background: var(--brand-color); border-color: var(--brand-color); color: #fff; }
  .doc-nav.doc-nav-active { background: color-mix(in srgb, var(--brand-color) 12%, transparent); color: var(--brand-color); font-weight: 600; }
</style>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main id="docsScroll" class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <!-- ═══ Header: Judul + Search + Toggle Bahasa ═══ -->
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-3">
        <div>
          <h2 class="text-xl font-bold text-gray-900 dark:text-white">Dokumentasi <?= e($appName) ?></h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            <?= $L('Panduan lengkap setup dan penggunaan semua fitur.', 'Panduan lengkap persediaan dan penggunaan semua ciri.', 'Complete guide to set up and use every feature.') ?>
          </p>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center gap-2.5 shrink-0">
          <!-- Search -->
          <div class="relative w-full sm:w-64 lg:w-72">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input id="docsSearch" type="search" autocomplete="off" spellcheck="false"
                   placeholder="Cari panduan…"
                   class="w-full pl-9 pr-9 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent transition">
            <button id="docsSearchClear" type="button" aria-label="Bersihkan pencarian"
                    class="hidden absolute right-2 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <!-- Toggle bahasa -->
          <div class="flex items-center gap-2 shrink-0">
            <button onclick="setLang('id')" id="lang-id" class="lang-btn px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 transition">Indonesia</button>
            <button onclick="setLang('my')" id="lang-my" class="lang-btn px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 transition">Malaysia</button>
          </div>
        </div>
      </div>

      <p id="docsSearchCount" class="hidden text-xs text-gray-400 dark:text-gray-500 mb-5"></p>

      <div class="flex gap-8 relative">

        <!-- ─── Sidebar navigasi docs (sticky) ─── -->
        <aside class="hidden lg:block w-64 flex-shrink-0">
          <div class="sticky top-0 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4 max-h-[calc(100vh-7rem)] overflow-y-auto">

            <div class="mb-4">
              <?= $navGroup($L('Mulai', 'Permulaan', 'Getting Started')) ?>
              <nav class="space-y-0.5">
                <?= $nav('instalasi',        'Instalasi',        'Pemasangan',        'Installation') ?>
                <?= $nav('aktivasi-lisensi', 'Aktivasi Lisensi', 'Pengaktifan Lesen', 'License Activation') ?>
                <?= $nav('setup-awal',       'Setup Pertama',    'Persediaan Awal',   'Initial Setup') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Produk &amp; Penjualan', 'Produk &amp; Jualan', 'Products &amp; Selling')) ?>
              <nav class="space-y-0.5">
                <?= $nav('produk-checkout',   'Produk &amp; Checkout', 'Produk &amp; Checkout', 'Products &amp; Checkout') ?>
                <?= $nav('landing-produk',    'Konten Landing Produk', 'Kandungan Landing Produk', 'Product Landing Content') ?>
                <?= $nav('order-bump',        'Order Bump',            'Order Bump',            'Order Bump') ?>
                <?= $nav('bonus-kilat',       'Bonus Kilat',           'Bonus Kilat',           'Flash Bonus') ?>
                <?= $nav('poin-reward',       'Poin Reward',           'Poin Reward',           'Reward Points') ?>
                <?= $nav('upsell',            'Upsell Post-Purchase',  'Upsell Selepas Belian', 'Post-Purchase Upsell') ?>
                <?= $nav('bundling',          'Bundling Produk',       'Bundling Produk',       'Product Bundles') ?>
                <?= $nav('kupon',             'Kupon Diskon',          'Kupon Diskaun',         'Discount Coupons') ?>
                <?= $nav('checkout-templates','Template Checkout',     'Templat Checkout',      'Checkout Templates') ?>
                <?= $nav('rekening-bank',     'Rekening Bank',         'Akaun Bank',            'Bank Accounts') ?>
                <?= $nav('waitlist',          'Waitlist / Coming Soon','Waitlist / Coming Soon','Waitlist / Coming Soon') ?>
                <?= $nav('booking-fee',       'Booking Fee (DP)',      'Booking Fee (DP)',      'Booking Fee (Deposit)') ?>
                <?= $nav('bayar-fleksibel',   'Bayar Fleksibel &amp; Dukung', 'Bayar Fleksibel &amp; Sokongan', 'Flexible Pricing &amp; Support') ?>
                <?= $nav('dime-sale',         'Dime Sale',             'Dime Sale',             'Dime Sale') ?>
                <?= $nav('harga-ringgit',     'Mata Uang &amp; Ringgit', 'Mata Wang &amp; Ringgit', 'Currency &amp; Ringgit') ?>
                <?= $nav('display-affiliate', 'Display Affiliate',     'Display Affiliate',     'Affiliate Displays') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Member &amp; Konten', 'Ahli &amp; Kandungan', 'Members &amp; Content')) ?>
              <nav class="space-y-0.5">
                <?= $nav('katalog-member',  'Katalog Member',     'Katalog Ahli',        'Member Catalog') ?>
                <?= $nav('materi-kelas',    'Modul &amp; Materi', 'Modul &amp; Bahan',   'Modules &amp; Materials') ?>
                <?= $nav('akses-embed',     'Mode Akses: Embed',  'Mod Akses: Embed',    'Access Mode: Embed') ?>
                <?= $nav('drip-content',    'Drip Content',       'Drip Content',        'Drip Content') ?>
                <?= $nav('download-manager','Download Manager',   'Pengurus Muat Turun', 'Download Manager') ?>
                <?= $nav('sertifikat',      'Sertifikat',         'Sijil',               'Certificates') ?>
                <?= $nav('review',          'Review Produk',      'Ulasan Produk',       'Product Reviews') ?>
                <?= $nav('langganan',       'Langganan',          'Langganan',           'Subscriptions') ?>
                <?= $nav('info-member',     'Info Member',        'Info Member',         'Member Announcements') ?>
                <?= $nav('tiket-support',   'Tiket Support',      'Tiket Sokongan',      'Support Tickets') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Affiliate &amp; Komisi', 'Affiliate &amp; Komisen', 'Affiliate &amp; Commission')) ?>
              <nav class="space-y-0.5">
                <?= $nav('affiliate',         'Sistem Affiliate', 'Sistem Affiliate',     'Affiliate System') ?>
                <?= $nav('commission-rules',  'Aturan Komisi',    'Peraturan Komisen',    'Commission Rules') ?>
                <?= $nav('membership-levels', 'Level Membership', 'Tahap Keahlian',       'Membership Levels') ?>
                <?= $nav('payout',            'Payout Affiliate', 'Pembayaran Affiliate', 'Affiliate Payout') ?>
                <?= $nav('leaderboard',       'Leaderboard',      'Papan Pendahulu',      'Leaderboard') ?>
                <?= $nav('partner-storefront','Partner Storefront','Partner Storefront',  'Partner Storefront') ?>
                <?= $nav('jv-partner',        'JV Partner (Bagi Hasil)', 'JV Partner (Kongsi Hasil)', 'JV Partner (Revenue Share)') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Marketing &amp; Growth', 'Pemasaran &amp; Pertumbuhan', 'Marketing &amp; Growth')) ?>
              <nav class="space-y-0.5">
                <?= $nav('email-sequence',  'Email Sequence',  'Urutan Emel',      'Email Sequence') ?>
                <?= $nav('abandoned-cart',  'Abandoned Cart',  'Troli Terbiar',    'Abandoned Cart') ?>
                <?= $nav('broadcast',       'Broadcast',       'Siaran',           'Broadcast') ?>
                <?= $nav('berhenti-langganan', 'Berhenti Langganan Email', 'Berhenti Melanggan Emel', 'Email Unsubscribe') ?>
                <?= $nav('lead-magnet',     'Lead Magnet',     'Lead Magnet',      'Lead Magnet') ?>
                <?= $nav('social-proof',    'Social Proof',    'Social Proof',     'Social Proof') ?>
                <?= $nav('canvas',          'Canvas',          'Canvas',           'Canvas') ?>
                <?= $nav('galeri-media',    'Galeri Media',    'Galeri Media',     'Media Library') ?>
                <?= $nav('tracking-pixels', 'Tracking Pixel',  'Piksel Penjejak',  'Tracking Pixels') ?>
                <?= $nav('funnel',          'Funnel Analytics','Analitik Funnel',  'Funnel Analytics') ?>
                <?= $nav('funnel-gate',     'Funnel Gate',     'Funnel Gate',      'Funnel Gate') ?>
                <?= $nav('ai-chat',         'AI Chat Widget',  'Widget AI Chat',   'AI Chat Widget') ?>
                <?= $nav('chat-leads',      'Leads AI Chat',   'Leads AI Chat',    'AI Chat Leads') ?>
                <?= $nav('averion-studio',  'Integrasi Studio', 'Integrasi Studio', 'Studio Integration') ?>
                <?= $nav('integrasi-claude', 'Integrasi Claude', 'Integrasi Claude', 'Claude Integration') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Pembayaran', 'Pembayaran', 'Payments')) ?>
              <nav class="space-y-0.5">
                <?= $nav('payment-gateway', 'Payment Gateway', 'Get Bayaran', 'Payment Gateway') ?>
                <?= $nav('xendit',          'Xendit',          'Xendit',      'Xendit') ?>
                <?= $nav('moota',           'Moota',           'Moota',       'Moota') ?>
                <?= $nav('duitku',          'Duitku',          'Duitku',      'Duitku') ?>
                <?= $nav('midtrans',        'Midtrans',        'Midtrans',    'Midtrans') ?>
                <?= $nav('tripay',          'Tripay',          'Tripay',      'Tripay') ?>
                <?= $nav('paypal',          'PayPal',          'PayPal',      'PayPal') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Notifikasi', 'Notifikasi', 'Notifications')) ?>
              <nav class="space-y-0.5">
                <?= $nav('notifikasi',  'Template Notifikasi', 'Templat Notifikasi', 'Notification Templates') ?>
                <?= $nav('whatsapp',    'WhatsApp',            'WhatsApp',           'WhatsApp') ?>
                <?= $nav('telegram',    'Telegram (Alert Admin)', 'Telegram (Alert Admin)', 'Telegram (Admin Alerts)') ?>
                <?= $nav('email-setup', 'Email (Mailketing)',  'Emel (Mailketing)',  'Email (Mailketing)') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Lisensi Produk', 'Lesen Produk', 'Product License')) ?>
              <nav class="space-y-0.5">
                <?= $nav('lisensi-produk',    'Lisensi Produk',    'Lesen Produk',      'Product Licenses') ?>
                <?= $nav('api-keys-overview', 'Status Integrasi',  'Status Integrasi',  'Integration Status') ?>
              </nav>
            </div>

            <div class="mb-4">
              <?= $navGroup($L('Sistem &amp; Tim', 'Sistem &amp; Pasukan', 'System &amp; Team')) ?>
              <nav class="space-y-0.5">
                <?= $nav('staff',         'Staff &amp; Akses',        'Staf &amp; Akses',           'Staff &amp; Access') ?>
                <?= $nav('users',         'Users &amp; Impersonate',  'Pengguna &amp; Impersonate', 'Users &amp; Impersonate') ?>
                <?= $nav('transaksi',     'Transaksi &amp; Invoice',  'Transaksi &amp; Invois',     'Transactions &amp; Invoice') ?>
                <?= $nav('google-oauth',  'Google OAuth',             'Google OAuth',               'Google OAuth') ?>
                <?= $nav('pwa',           'PWA (Install Aplikasi)',   'PWA (Pasang Aplikasi)',      'PWA (Install App)') ?>
                <?= $nav('member-online', 'Member Sedang Online',     'Ahli Sedang Online',         'Members Online Now') ?>
                <?= $nav('update-sistem', 'Update Sistem',            'Kemas Kini Sistem',          'System Update') ?>
                <?= $nav('backup-db',     'Backup Database',          'Sandaran Pangkalan Data',    'Database Backup') ?>
                <?= $nav('data-import',   'Import Data',              'Import Data',                'Data Import') ?>
                <?= $nav('kesehatan-sistem', 'Kesehatan Sistem',      'Kesihatan Sistem',           'System Health') ?>
                <?= $nav('keamanan-2fa',  'Keamanan Login (2FA)',     'Keselamatan Log Masuk (2FA)','Login Security (2FA)') ?>
                <?= $nav('activity-logs', 'Log Aktivitas',            'Log Aktiviti',               'Activity Logs') ?>
              </nav>
            </div>

            <div>
              <?= $navGroup($L('Developer / API', 'Pembangun / API', 'Developer / API')) ?>
              <nav class="space-y-0.5">
                <?= $nav('api-developer',         'API License',            'API Lesen',              'License API') ?>
                <?= $nav('integrasi-eksternal',   'Integrasi App Eksternal', 'Integrasi Apl Luaran',  'External App Integration') ?>
                <?= $nav('hermes-agent',          'Hermes Agent (Ingest API)','Hermes Agent (Ingest API)','Hermes Agent (Ingest API)') ?>
              </nav>
            </div>

          </div>
        </aside>

        <!-- ─── Konten utama ─── -->
        <div class="flex-1 min-w-0 space-y-12">

          <!-- ═══ Video Tutorial ═══ -->
          <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-3"><?= $L('Video Tutorial', 'Video Tutorial') ?></h2>
            <div style="position:relative;padding-top:56.25%;border-radius:0.75rem;overflow:hidden;background:#000">
              <iframe src="https://player.mediadelivery.net/embed/580459/ab970ca7-ff8e-47ea-8687-a14c1f36ab83?autoplay=false&loop=false&muted=false&preload=true&responsive=true" loading="lazy" style="border:0;position:absolute;top:0;height:100%;width:100%;" allow="accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;fullscreen;" allowfullscreen="true"></iframe>
            </div>
          </div>

          <!-- Hasil kosong (tampil saat pencarian tidak cocok) -->
          <div id="docsNoResults" class="hidden bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-10 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Tidak ada panduan yang cocok</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Coba kata kunci lain, mis. <span class="font-mono">webhook</span>, <span class="font-mono">komisi</span>, atau <span class="font-mono">lisensi</span>.</p>
          </div>
<?php

// ═══════════════════════ TERJEMAHAN BAHASA MALAYSIA (body per section) ═══════════════════════
$myBodies = [

  'instalasi' =>
    $h3('Keperluan Hosting')
    . $ul([
        'PHP versi 8.0 atau lebih baharu',
        'MySQL 5.7+ atau MariaDB 10.3+',
        'Modul ' . $mono('mod_rewrite') . ' aktif (Apache) atau setara di Nginx',
        'Sambungan PHP: ' . $mono('pdo_mysql') . ' ' . $mono('curl') . ' ' . $mono('mbstring') . ' ' . $mono('openssl') . ' ' . $mono('gd'),
    ])
    . $h3('Langkah Pemasangan')
    . $steps([
        ['Muat turun fail Averion', 'Ambil fail pemasangan dari panel lesen selepas pembelian.'],
        ['Muat naik ke hosting', 'Muat naik melalui FileManager / FTP ke folder ' . $mono('public_html') . ', kemudian ekstrak.'],
        ['Buka installer', 'Akses ' . $mono('https://domain-anda.com/installer') . ' di pelayar.'],
        ['Isi borang installer', 'Masukkan nama pangkalan data, pengguna, kata laluan, dan host pangkalan data.'],
        ['Klik Install', 'Tunggu sehingga proses selesai — jangan tutup tab.'],
        ['Selesai', 'Folder installer dikunci automatik selepas pemasangan berjaya.'],
    ])
    . $code('https://domain-anda.com/installer')
    . $info('<strong>Petua:</strong> Jika portal dipasang dalam subfolder, laraskan ' . $mono('APP_URL') . ' di ' . $mono('config.php') . '.')
    . $warn('<strong>Perhatian:</strong> Sentiasa buat sandaran pangkalan data sebelum pasang semula.'),

  'aktivasi-lisensi' =>
    $steps([
        ['Buka halaman Lesen', 'Akses ' . $mono('/admin/license') . ' selepas pemasangan selesai.'],
        ['Masukkan license key', 'Guna license key yang dihantar ke emel semasa pembelian.'],
        ['Pengesahan', 'Klik <strong>Aktivasi Lesen</strong> — sistem menghubungi pelayan lesen.'],
        ['Status aktif', 'Jika berjaya, status bertukar kepada <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Active</span>.'],
    ])
    . '<p class="mb-2">Format license key:</p>'
    . $code('AVRN-XXXX-XXXX-XXXX-XXXX')
    . $info('<strong>Info:</strong> Domain anda terikat kepada lesen ini secara automatik pada pengesahan pertama.')
    . $warn('<strong>Perhatian:</strong> Satu lesen hanya sah untuk satu domain.')
    . $info('<strong>Petua:</strong> Lesen boleh dipindahkan ke domain lain dengan menghubungi sokongan.'),

  'setup-awal' =>
    $steps([
        ['Penjenamaan', 'Buka ' . $mono('/admin/branding') . ' → isi nama kedai, muat naik logo & favicon, tetapkan warna jenama.'],
        ['Tetapan umum', 'Buka ' . $mono('/admin/settings') . ' → isi emel & URL sokongan, mata wang, dll.'],
        ['Kaedah pembayaran', 'Aktifkan sekurang-kurangnya satu: payment gateway (' . $mono('/admin/integrations') . ') atau Manual Transfer (' . $mono('/admin/bank-accounts') . ').'],
        ['Notifikasi', 'Sediakan penghantar emel/WhatsApp di ' . $mono('/admin/integrations') . ', kemudian aktifkan templat di ' . $mono('/admin/notifications') . '.'],
        ['Produk pertama', 'Buka ' . $mono('/admin/products') . ' → tambah produk pertama.'],
        ['Uji checkout', 'Lakukan ujian checkout dengan Manual Transfer / mod simulasi dahulu.'],
    ])
    . $h3('Senarai Semak Persediaan Awal')
    . '<ul class="space-y-2 mb-5 text-sm text-gray-600 dark:text-gray-300">'
      . '<li class="flex items-center gap-2">' . $chk . 'Penjenamaan (nama, logo, warna) sudah diisi</li>'
      . '<li class="flex items-center gap-2">' . $chk . 'Emel &amp; URL sokongan sudah diisi</li>'
      . '<li class="flex items-center gap-2">' . $chk . 'Sekurang-kurangnya satu kaedah pembayaran aktif</li>'
      . '<li class="flex items-center gap-2">' . $chk . 'Penghantar notifikasi (emel/WA) tersambung</li>'
      . '<li class="flex items-center gap-2">' . $chk . 'Sekurang-kurangnya satu produk dibuat</li>'
      . '<li class="flex items-center gap-2">' . $chk . 'Ujian checkout berjaya</li>'
    . '</ul>'
    . $info('<strong>Petua:</strong> Mulakan dengan Manual Transfer (Akaun Bank) — paling cepat aktif tanpa pendaftaran gateway.'),

  'produk-checkout' =>
    $h3('Jenis Produk')
    . $badges(['Class', 'Membership', 'Ebook', 'Software', 'Jasa', 'Coaching', 'Webinar', 'Template', 'Other'])
    . $h3('Menambah Produk')
    . '<p class="mb-3">Buka ' . $mono('/admin/products') . ' → <strong>Tambah Produk</strong>, isi: nama, harga (+ harga coret pilihan), penerangan, thumbnail, dan status.</p>'
    . $ul([
        '<strong>Landing Page:</strong> pilih None, Canvas (HTML tersuai), atau URL luaran.',
        '<strong>Templat Checkout:</strong> tetapkan templat borang setiap produk.',
        '<strong>Sijil:</strong> aktifkan ' . $mono('certificate_enabled') . ' jika produk memberi sijil.',
        '<strong>Ulasan:</strong> aktifkan ulasan produk (global ditetapkan di Tetapan).',
        '<strong>Muat Turun &amp; Bahan:</strong> lampirkan fail/bahan selepas produk dibuat.',
    ])
    . $info('<strong>Petua:</strong> Guna thumbnail nisbah 1:1 untuk paparan terbaik di halaman produk & checkout.')
    . $h3('Peta Borang Produk — 5 Tab')
    . $ul([
        '<strong>Dasar</strong> — Nama, Slug, Jenis, Status, Kategori, Jadual Launch (Coming Soon), sembunyi dari katalog.',
        '<strong>Harga &amp; Akses</strong> — Jenis Harga (termasuk Bayar Fleksibel), Harga + harga coret, Tempoh Akses (&gt;0 = langganan), Komisi Afiliasi, Setup Fee, Harga RM, Booking Fee, Dime Sale, JV Partner.',
        '<strong>Media &amp; Pautan</strong> — Thumbnail (1:1), Landing Page, Mod Checkout, Templat Borang.',
        '<strong>Penerangan</strong> — Ringkas/Penuh, "Yang Anda Dapat", Isi Pakej, AI Knowledge.',
        '<strong>Lanjutan</strong> — Sijil, Ulasan, Redirect Selepas Bayar, Kod Embed, Lesen (Seats/Tempoh), Kod Program Luaran, Salespage Reseller.',
    ]),

  'order-bump' =>
    '<p class="mb-3"><strong>Order Bump</strong> = tawaran produk pelengkap (kotak semak) di checkout <strong>Produk Utama</strong>; ditanda pembeli → produk bump masuk order &amp; aktif automatik selepas bayar.</p>'
    . $steps([
        ['Buka Product Bumps', 'Akses ' . $mono('/admin/product-bumps') . '.'],
        ['Pilih Produk Utama', 'Produk yang checkout-nya memaparkan bump ini.'],
        ['Pilih Produk Bump + Harga', 'Tetapkan produk bump + Harga Bump (kosong = harga normal, isi = harga istimewa).'],
        ['Headline, Deskripsi, Status, Urutan', 'Tulis ayat tawaran; aktifkan; atur urutan bila &gt;1 bump.'],
    ])
    . $info('<strong>Petua:</strong> Tawarkan produk pelengkap yang murah & relevan supaya penukaran bump tinggi. Satu produk boleh ada beberapa bump.'),

  'bundling' =>
    $steps([
        ['Buka Bundles', 'Akses ' . $mono('/admin/bundles') . ' → <strong>Buat Bundle</strong>.'],
        ['Isi butiran', 'Nama, slug, penerangan, dan thumbnail bundle.'],
        ['Pilih item', 'Tambah beberapa produk ke dalam bundle.'],
        ['Tetapkan harga', 'Tentukan <strong>Harga Bundle</strong>; harga normal dikira dari jumlah item (lencana jimat % automatik).'],
        ['Terbitkan', 'Tetapkan status aktif — halaman awam tersedia di ' . $mono('/bundles/{slug}') . '.'],
    ])
    . $info('<strong>Info:</strong> Apabila dibeli, SEMUA produk dalam bundle diaktifkan automatik untuk pembeli selepas pembayaran disahkan.'),

  'kupon' =>
    $steps([
        ['Buka Coupons', 'Akses ' . $mono('/admin/coupons') . ' → <strong>Tambah Kupon</strong>.'],
        ['Kod & jenis', 'Tentukan kod unik + jenis potongan: nilai tetap (' . e(currencySymbol()) . ') atau peratusan (%).'],
        ['Had penggunaan', 'Tetapkan kuota maksimum penggunaan dan tarikh tamat tempoh.'],
        ['Skop', 'Sah untuk semua produk atau produk tertentu.'],
    ])
    . $info('<strong>Petua:</strong> Buat kupon bertempoh singkat (cth. 48 jam) untuk mendorong urgensi pembelian.'),

  'checkout-templates' =>
    $h3('Varian Tersedia')
    . $badges(['Standard', 'Minimal', 'Two-Step', 'Sidebar Sticky'])
    . $steps([
        ['Buka Checkout Templates', 'Akses ' . $mono('/admin/checkout-templates') . '.'],
        ['Tetapkan lalai', 'Tandakan satu templat sebagai lalai untuk semua produk.'],
        ['Tetapkan per produk', 'Di borang produk, pilih templat khas jika mahu berbeza daripada lalai.'],
    ])
    . $info('<strong>Petua:</strong> Templat <strong>Two-Step</strong> mengurangkan friksi; <strong>Sidebar Sticky</strong> memastikan ringkasan pesanan kekal kelihatan.'),

  'rekening-bank' =>
    $steps([
        ['Buka Akaun Bank', 'Akses ' . $mono('/admin/bank-accounts') . ' → <strong>Tambah Akaun</strong>.'],
        ['Isi data', 'Pilih bank, nombor akaun, dan nama pemilik.'],
        ['Susun', 'Tetapkan susunan paparan; akaun aktif muncul di checkout Manual Transfer.'],
    ])
    . $info('<strong>Info:</strong> Manual Transfer tidak memerlukan gateway. Pembeli memindahkan secara manual, kemudian admin mengesahkan di Transaksi (atau automatik melalui Moota).'),

  'materi-kelas' =>
    $steps([
        ['Buat kategori (pilihan)', 'Buka ' . $mono('/admin/class-categories') . ' untuk mengumpulkan bahan.'],
        ['Tambah bahan', 'Buka ' . $mono('/admin/content') . ' → pilih produk Class → tambah bahan.'],
        ['Pilih jenis bahan', 'Video (embed YouTube/Vimeo), Fail, Pautan, atau Teks.'],
        ['Susun urutan', 'Susun bahan dan kumpulkan ke kategori modul.'],
    ])
    . $info('<strong>Info:</strong> Pembeli mengakses bahan di ' . $mono('/member') . ' selepas pembayaran disahkan. Navigasi bahan (prev/next) tersedia automatik.'),

  'download-manager' =>
    $steps([
        ['Buka Download Manager', 'Akses ' . $mono('/admin/downloads') . ' → <strong>Muat Naik Fail</strong>.'],
        ['Pilih produk', 'Tentukan produk pemilik fail.'],
        ['Isi butiran', 'Tajuk, penerangan, versi (pilihan), dan susunan.'],
        ['Simpan', 'Fail hanya boleh dimuat turun oleh pembeli yang telah membeli produk tersebut.'],
    ])
    . $info('<strong>Info:</strong> Setiap muat turun direkodkan di <strong>Download Logs</strong> (siapa & bila). Kemas kini versi cukup muat naik fail baharu.')
    . $warn('<strong>Perhatian:</strong> Fail disimpan terlindung — pautan terus tidak boleh diakses tanpa log masuk & pemilikan.'),

  'sertifikat' =>
    $steps([
        ['Aktifkan per produk', 'Di borang produk, aktifkan ' . $mono('certificate_enabled') . '.'],
        ['Tetapkan paparan', 'Buka ' . $mono('/admin/certificates') . ' → isi nama & jawatan penandatangan, teks footer, warna latar & aksen.'],
        ['Terbitkan', 'Klik <strong>Issue Sijil</strong> (pilih penerima + produk), atau biarkan terbit automatik mengikut peraturan produk.'],
        ['Pengesahan', 'Setiap sijil mempunyai nombor & halaman pengesahan awam ' . $mono('/certificate/{uuid}') . '.'],
    ])
    . $info('<strong>Info:</strong> Sijil boleh <strong>ditarik balik</strong> (revoke) bila-bila masa — status bertukar kepada "Ditarik balik" dan halaman pengesahan menandakannya.'),

  'review' =>
    $steps([
        ['Aktifkan global', 'Di ' . $mono('/admin/settings') . ' aktifkan ' . $mono('reviews_enabled') . '.'],
        ['Aktifkan per produk', 'Aktifkan ulasan pada produk yang dikehendaki.'],
        ['Moderasi', 'Buka ' . $mono('/admin/reviews') . ' → tab <strong>Menunggu</strong> → Lulus / Tolak / Padam.'],
        ['Paparan', 'Ulasan yang diluluskan dipaparkan di halaman produk.'],
    ])
    . $info('<strong>Info:</strong> Ulasan baharu berstatus <strong>Menunggu</strong> (pending) dan tidak dipaparkan sebelum diluluskan admin/staf.'),

  'affiliate' =>
    $h3('Cara Berfungsi')
    . '<p class="mb-5">Pengguna daftar → dapat pautan unik → berkongsi pautan → memperoleh komisen dari setiap transaksi yang masuk melalui pautan tersebut.</p>'
    . $ul([
        'Pengguna melihat papan pemuka affiliate dengan log masuk → ' . $mono('/affiliate') . '.',
        'Pautan rujukan berbentuk ' . $mono('/r/{kod}') . ' dan landing ' . $mono('/go/{kod}/{slug}') . '.',
        'Admin/staf memantau komisen & pencairan di ' . $mono('/admin/affiliate-finance') . ' (tab Komisi & Pencairan).',
    ])
    . $info('<strong>Petua:</strong> Tetapkan kadar komisen global di Tetapan, kemudian ganti per produk/tahap di <strong>Peraturan Komisen</strong>.'),

  'commission-rules' =>
    $steps([
        ['Komisen global', 'Tetapkan peratusan lalai di ' . $mono('/admin/settings') . '.'],
        ['Peraturan khusus', 'Buka ' . $mono('/admin/commission-rules') . ' → tambah peraturan per produk / per tahap.'],
        ['Keutamaan', 'Peraturan khusus mengatasi komisen global untuk produk/tahap berkaitan.'],
    ])
    . $info('<strong>Petua:</strong> Beri komisen lebih besar untuk produk margin tinggi atau tahap keahlian tertinggi.'),

  'membership-levels' =>
    $badges(['Bronze', 'Silver', 'Gold', 'Platinum'])
    . $steps([
        ['Buka Membership Levels', 'Akses ' . $mono('/admin/membership-levels') . '.'],
        ['Tetapkan tahap', 'Tambah/edit tahap, susun, dan tentukan komisen per tahap.'],
        ['Kaitkan komisen', 'Guna bersama <strong>Peraturan Komisen</strong> untuk komisen per tahap.'],
    ])
    . $info('<strong>Petua:</strong> Tahap boleh ditukar nama & ditambah mengikut strategi — tidak terhad kepada 4 lalai.'),

  'payout' =>
    $h3('Aliran Status Komisen')
    . $badges(['Pending', 'Approved (sedia cair)', 'Paid'])
    . $steps([
        ['Luluskan komisen', 'Buka ' . $mono('/admin/affiliate-finance') . ' → tab <strong>Pencairan</strong> → tandakan komisen pending → <strong>Approve Terpilih</strong> (batch).'],
        ['Permintaan pencairan', 'Affiliate memohon pencairan; muncul dalam senarai Payout Request.'],
        ['Proses payout', 'Tukar status: Pending → <strong>Processing</strong> → <strong>Paid</strong> (atau Rejected).'],
    ])
    . $info('<strong>Pengaktifan:</strong> Supaya affiliate mendapat notifikasi, aktifkan templat ' . $mono('commission_approved') . ', ' . $mono('payout_processing') . ', ' . $mono('payout_paid') . ', ' . $mono('payout_rejected') . ' di ' . $mono('/admin/notifications') . '.')
    . $info('<strong>Petua:</strong> Tetapkan minimum payout di Tetapan supaya pencairan lebih cekap.'),

  'leaderboard' =>
    '<p class="mb-4">Buka ' . $mono('/admin/leaderboard') . ' untuk melihat kedudukan affiliate berdasarkan jumlah jualan/komisen. Berguna untuk pertandingan & gamifikasi pasukan affiliate.</p>'
    . $info('<strong>Petua:</strong> Umumkan leaderboard secara berkala untuk memacu semangat affiliate teratas.'),

  'email-sequence' =>
    $h3('Pencetus Tersedia')
    . $ul(['Selepas Pembelian Berjaya (' . $mono('order_paid') . ')', 'Selepas Akses Diaktifkan (' . $mono('member_access_activated') . ')', 'Selepas Pengguna Mendaftar (' . $mono('user_register') . ')'])
    . $steps([
        ['Buat sequence', 'Buka ' . $mono('/admin/email-sequences') . ' → <strong>Buat Sequence</strong>, pilih pencetus & produk (atau semua produk).'],
        ['Tambah langkah', 'Tambah emel setiap langkah dengan lengah: D+0, D+1, D+3, dst.'],
        ['Aktifkan', 'Tetapkan sequence & langkah ke status aktif.'],
    ])
    . $info('<strong>Automasi (Auto-Cron):</strong> Penghantaran berjalan <strong>automatik</strong> mengikut lawatan ke laman — tanpa perlu tetapan. Untuk kebolehpercayaan tambahan ketika trafik rendah, pasang cron manual ke ' . $mono('/cron/process') . ' (lihat panduan pemasangan). Selamat berjalan serentak (tiada hantar berganda).')
    . $info('<strong>Prasyarat:</strong> Penghantar emel (Mailketing) mesti aktif di Integrasi.'),

  'abandoned-cart' =>
    $steps([
        ['Buka Abandoned Cart', 'Akses ' . $mono('/admin/abandoned-carts') . '.'],
        ['Aktifkan & tetapkan', 'Tandakan <strong>Aktifkan Recovery</strong>, tetapkan <strong>Lengah (jam)</strong> dan <strong>Saluran</strong> (Emel / WhatsApp).'],
        ['Aktifkan templat', 'Aktifkan templat ' . $mono('abandoned_cart') . ' di ' . $mono('/admin/notifications') . '.'],
    ])
    . $info('<strong>Info:</strong> Peringatan dihantar <strong>sekali setiap pesanan</strong>. Statistik recovery dipaparkan di halaman ini.')
    . $info('<strong>Automasi (Auto-Cron):</strong> Seperti Email Sequence — peringatan diproses <strong>automatik</strong> mengikut trafik laman. Cron manual pilihan untuk kebolehpercayaan tambahan ketika sepi pengunjung.'),

  'broadcast' =>
    $steps([
        ['Buka Broadcast', 'Akses ' . $mono('/admin/broadcast') . '.'],
        ['Tentukan sasaran', 'Pilih segmen penerima (cth. semua pengguna / pembeli produk tertentu).'],
        ['Tulis mesej', 'Susun isi mesej, pilih saluran (emel / WhatsApp), kemudian hantar.'],
    ])
    . $warn('<strong>Perhatian:</strong> Hormati had & polisi provider. Hantar secara berperingkat untuk mengelakkan penyekatan nombor/akaun.'),

  'lead-magnet' =>
    $steps([
        ['Buat magnet', 'Buka ' . $mono('/admin/lead-magnets') . ' (tab <strong>Magnets</strong>) → buat magnet: tajuk, fail/bonus, dan bonus tahap keahlian (pilihan).'],
        ['Kongsi borang', 'Borang menangkap data lead: nama, WhatsApp, emel.'],
        ['Urus leads', 'Tab <strong>Leads</strong> → cari/tapis per magnet, kemudian susuli (follow-up / broadcast).'],
    ])
    . $info('<strong>Petua:</strong> Sambungkan lead ke Email Sequence atau Broadcast untuk nurturing automatik ke arah pembelian.'),

  'social-proof' =>
    $steps([
        ['Buka Social Proof', 'Akses ' . $mono('/admin/social-proof') . '.'],
        ['Tetapkan templat', 'Contoh: ' . $mono('{buyer_name} dari {city} baru saja membeli {product_name}') . '.'],
        ['Pilih bunyi & paparan', 'Pilih kesan bunyi (Crystal Bell, Cash Register, dll) dan kedudukan popup.'],
        ['Pasang embed', 'Salin skrip embed (mengandungi <strong>Integration ID</strong>) ke halaman yang dikehendaki.'],
    ])
    . $info('<strong>Info:</strong> Jika embed bocor/disalahguna, klik <strong>Reset Integration ID</strong> kemudian kemas kini semua embed lama.'),

  'canvas' =>
    $h3('Jenis Halaman')
    . $badges(['Homepage', 'Landing Produk', 'Berdiri Sendiri'])
    . $steps([
        ['Buka Canvas', 'Akses ' . $mono('/admin/canvas') . '.'],
        ['Buat / muat naik', 'Tampal HTML atau muat naik fail/ZIP halaman.'],
        ['Tetapkan jenis', 'Jadikan Homepage, kaitkan sebagai Landing Produk, atau biarkan Berdiri Sendiri (mempunyai slug sendiri).'],
        ['Aktifkan', 'Toggle aktif; halaman terus tayang.'],
    ])
    . $warn('<strong>Perhatian:</strong> Folder muat naik Canvas dikunci (deny-all). Buat kandungan <strong>self-contained / inline</strong> (CSS & imej di-embed) kerana aset relatif tidak ter-serve.')
    . $info('<strong>Penjejakan pixel:</strong> <strong>PageView</strong> menembak di semua Canvas yang diisi Pixel ID; <strong>ViewContent</strong> hanya bila jenis = <strong>Landing Produk</strong> &amp; terpaut produk. Lawatan admin/staf TIDAK menembak ViewContent &amp; funnel — uji di tetingkap <strong>Inkognito</strong>. Split Test tetap dijejak (pixel rotator berjalan di semua varian).')
    . $warn('<strong>Tiada data di Meta Ads?</strong> Lazimnya: (1) <strong>delay</strong> laporan — guna <strong>Uji Peristiwa</strong> untuk real-time; (2) Pixel ID berbeza/kosong berbanding akaun iklan; (3) salah metrik (&ldquo;Paparan Halaman Landing&rdquo; &ne; &ldquo;Klik Pautan&rdquo;).'),

  'tracking-pixels' =>
    $steps([
        ['Buka Tracking Pixels', 'Akses ' . $mono('/admin/tracking-pixels') . '.'],
        ['Tambah piksel', 'Masukkan ID piksel (cth. Meta/Facebook Pixel, Google Ads, TikTok, GA4, GTM).'],
        ['Tetapkan event', 'Piksel ter-inject automatik di halaman awam untuk menjejak event seperti view & purchase.'],
    ])
    . $info('<strong>Petua:</strong> Gabungkan dengan <strong>Funnel Analytics</strong> untuk membandingkan data dalaman vs platform iklan.'),

  'funnel' =>
    '<p class="mb-4">Buka ' . $mono('/admin/funnel-analytics') . ' untuk melihat peringkat <strong>Lawatan → Checkout → Pembayaran</strong>, berserta nisbah penukaran setiap peringkat.</p>'
    . $info('<strong>Petua:</strong> Jika penurunan besar pada peringkat checkout, cuba tukar Templat Checkout atau kurangkan friksi (Two-Step).')
    . $info('<strong>Produk default:</strong> klik butang bintang <strong>Jadikan default funnel</strong> di bawah dropdown → produk/bundle itu muncul paling atas &amp; terbuka automatik setiap kali halaman dibuka.'),

  'payment-gateway' =>
    $h3('Pilihan Pembayaran')
    . $badges(['Xendit', 'Duitku', 'Midtrans', 'Tripay', 'Moota', 'PayPal', 'Manual Transfer'])
    . $ul([
        '<strong>Xendit</strong> — automatik: Virtual Account, QRIS, e-wallet.',
        '<strong>Duitku</strong> — automatik: VA Bank, e-wallet, QRIS.',
        '<strong>Midtrans</strong> — automatik (Snap): VA Bank, GoPay/ShopeePay, QRIS, Kad Kredit.',
        '<strong>Tripay</strong> — automatik: pembeli pilih channel (VA, QRIS, e-wallet, retail Alfamart).',
        '<strong>Moota</strong> — pengesahan automatik pindahan bank melalui kod unik.',
        '<strong>PayPal</strong> — pembeli antarabangsa (dicaj USD daripada jumlah IDR ikut kadar).',
        '<strong>Manual Transfer</strong> — pindahan ke Akaun Bank, disahkan manual oleh admin.',
    ])
    . '<p class="mb-3">Setiap gateway ada panduan tersendiri di menu kiri. Aktifkan di ' . $mono('/admin/integrations') . '. Daftarkan webhook setiap gateway supaya status pembayaran dikemas kini automatik.</p>'
    . $info('<strong>Info:</strong> Hanya satu gateway automatik aktif pada satu masa, namun Manual Transfer boleh kekal seiring.')
    . $warn('<strong>Perhatian:</strong> HTTPS wajib untuk webhook di production.'),

  'xendit' =>
    $steps([
        ['Daftar akaun', 'Buat akaun di ' . $mono('xendit.co') . '.'],
        ['Ambil API Key', 'Dashboard → Settings → API Keys → <strong>Secret Key</strong>.'],
        ['Ambil Webhook Token', 'Dashboard → Settings → Callbacks → <strong>Webhook Verification / Callback token</strong>.'],
        ['Isi di Averion', $mono('/admin/integrations') . ' → Xendit → isi API Key + Webhook Verification Token.'],
        ['Daftarkan webhook', 'Masukkan URL webhook (di bawah) ke dashboard Xendit.'],
        ['Uji sandbox', 'Uji dengan mod sandbox sebelum production.'],
    ])
    . $code('https://domain-anda.com/webhooks/xendit-callback')
    . '<p class="mb-3">Kaedah disokong: <strong>Virtual Account</strong>, <strong>QRIS</strong>, <strong>e-wallet</strong>.</p>'
    . $warn('<strong>Perhatian:</strong> HTTPS wajib — Xendit tidak menghantar webhook ke URL HTTP.'),

  'moota' =>
    $steps([
        ['Daftar akaun', 'Buat akaun di ' . $mono('moota.co') . ' dan tambah akaun bank.'],
        ['Ambil Server Key API V2', 'Dashboard Moota → Tetapan API → <strong>Server Key (API V2)</strong>.'],
        ['Isi di Averion', $mono('/admin/integrations') . ' → Moota → isi API Key (Server Key V2) + Secret Token.'],
        ['Uji sambungan', 'Simpan dahulu, kemudian klik <strong>Test Koneksi</strong> untuk pengesahan.'],
        ['Daftarkan webhook', 'Salin URL webhook (di bawah) ke Moota → Webhook, dengan Secret Token yang sama.'],
        ['Info akaun', 'Isi <strong>Bank Info</strong> (cth. "Maybank 1234567890 a/n Nama Cawangan") yang dipaparkan kepada pembeli.'],
    ])
    . $code('https://domain-anda.com/webhooks/moota-callback')
    . $h3('Cara Kod Unik Berfungsi')
    . '<p class="mb-4">Nilai pindahan = harga + kod unik. Moota menghantar semua mutasi akaun, dan Averion memadankan automatik dengan pesanan yang menunggu pembayaran.</p>'
    . $info('<strong>Nota:</strong> Moota tiada mod sandbox — menggunakan akaun bank sebenar.'),

  'duitku' =>
    $steps([
        ['Daftar akaun', 'Buat akaun merchant di ' . $mono('duitku.com') . '.'],
        ['Ambil kredensial', 'Dashboard Duitku → ambil <strong>Merchant Code</strong> + <strong>API Key</strong>.'],
        ['Isi di Averion', $mono('/admin/integrations') . ' → Duitku → isi Merchant Code + API Key.'],
        ['Mod', 'Pilih Sandbox untuk ujian, kemudian Production apabila sedia.'],
        ['Daftarkan callback', 'Masukkan URL webhook (di bawah) di konfigurasi Duitku.'],
    ])
    . $code('https://domain-anda.com/webhooks/duitku-callback')
    . '<p class="mb-3">Kaedah disokong: <strong>VA Bank</strong>, <strong>e-wallet</strong>, <strong>QRIS</strong>.</p>'
    . $info('<strong>Dokumentasi rasmi:</strong> ' . $mono('https://docs.duitku.com') . '.'),

  'midtrans' =>
    $steps([
        ['Daftar akaun', 'Buat akaun merchant di ' . $mono('midtrans.com') . '.'],
        ['Ambil kredensial', 'Dashboard Midtrans → <strong>Settings → Access Keys</strong> → salin <strong>Server Key</strong> (wajib) &amp; <strong>Client Key</strong> (pilihan). Guna kredensial <strong>Sandbox</strong> semasa mod Sandbox.'],
        ['Isi di Averion', $mono('/admin/integrations') . ' → Midtrans → isi Server Key, Client Key (pilihan), Merchant ID, kemudian pilih mod.'],
        ['Salin URL Webhook', 'Di halaman config Midtrans, salin <strong>Payment Notification URL</strong> (butang Copy).'],
        ['Daftarkan di Midtrans', 'Tampal URL itu di Dashboard Midtrans → <strong>Settings → Configuration</strong> → <strong>Payment Notification URL</strong>.'],
        ['Uji sandbox', 'Uji dengan mod Sandbox sebelum production.'],
    ])
    . $code('https://domain-anda.com/webhooks/midtrans-callback.php')
    . '<p class="mb-3">Kaedah disokong: <strong>VA Bank</strong>, <strong>e-wallet</strong> (GoPay/ShopeePay), <strong>QRIS</strong>, <strong>Kad Kredit</strong> — melalui halaman <strong>Snap</strong>.</p>'
    . $warn('<strong>Perhatian:</strong> HTTPS wajib. Pastikan Server Key &amp; mod padan (Sandbox vs Production).'),

  'tripay' =>
    $steps([
        ['Daftar akaun', 'Buat akaun merchant di ' . $mono('tripay.co.id') . ' dan lengkapkan pengesahan merchant.'],
        ['Ambil kredensial', 'Dashboard Tripay → <strong>Merchant → API</strong> → salin <strong>Kod Merchant</strong>, <strong>API Key</strong>, &amp; <strong>Private Key</strong>. Guna kredensial <strong>Sandbox</strong> semasa mod Sandbox.'],
        ['Isi di Averion', $mono('/admin/integrations') . ' → Tripay → isi Kod Merchant + API Key + Private Key, kemudian pilih mod.'],
        ['Salin URL Callback', 'Di halaman config Tripay, salin <strong>Callback URL</strong> (butang Copy).'],
        ['Daftarkan di Tripay', 'Tampal URL itu di Dashboard Tripay → <strong>Merchant → Callback URL</strong>.'],
        ['Uji sandbox', 'Uji dengan mod Sandbox sebelum production.'],
    ])
    . $code('https://domain-anda.com/webhooks/tripay-callback.php')
    . '<p class="mb-3">Pembeli memilih <strong>channel</strong> (VA Bank, QRIS, e-wallet, retail spt Alfamart) semasa checkout, kemudian diarah ke halaman pembayaran Tripay. Senarai channel diambil automatik daripada akaun anda.</p>'
    . $info('Signature dibuat &amp; disahkan automatik (HMAC-SHA256 dengan Private Key) — cukup isi kredensial dengan betul.')
    . $warn('<strong>Perhatian:</strong> HTTPS wajib untuk callback. Jika channel tak muncul, semak kredensial &amp; mod lalu simpan semula.'),

  'notifikasi' =>
    '<p class="mb-3">Buka ' . $mono('/admin/notifications') . '. Setiap templat terikat kepada <strong>event</strong> (cth. pembayaran berjaya, troli terbiar) dan <strong>saluran</strong> (Emel / WhatsApp).</p>'
    . $h3('Event Penting')
    . $ul([
        $mono('abandoned_cart') . ' — troli terbiar',
        $mono('commission_approved') . ' — komisen affiliate diluluskan',
        $mono('payout_processing') . ' / ' . $mono('payout_paid') . ' / ' . $mono('payout_rejected') . ' — status pencairan',
        $mono('public_checkout_welcome') . ' — sambutan checkout',
    ])
    . $h3('Placeholder')
    . $code('{name} {email} {product_name} {total} {order_number} {checkout_url} {amount} {bank_name} {app_name}')
    . $warn('<strong>Pengaktifan:</strong> Sesetengah templat di-seed dalam keadaan <strong>tidak aktif</strong>. Aktifkan templat yang diperlukan (terutama abandoned cart, komisen, dan payout) supaya mesej benar-benar dihantar.'),

  'whatsapp' =>
    $h3('Provider Disokong')
    . $badges(['Kirimdev (Cloud API rasmi)', 'OneSender', 'Fonte / Fonnte', 'StarSender', 'DripSender'])
    . $steps([
        ['Buka Integrasi', 'Akses ' . $mono('/admin/integrations') . ' → bahagian WhatsApp.'],
        ['Pilih & isi provider', 'Aktifkan toggle provider kemudian isi kredensialnya.'],
        ['Uji hantar', 'Simpan dahulu, kemudian guna butang <strong>Test Kirim</strong> ke nombor sendiri.'],
    ])
    . $h3('Nota Setiap Provider')
    . $ul([
        '<strong>Kirimdev</strong> — WhatsApp Cloud API rasmi. Perlukan <strong>API Key</strong> + <strong>Phone Number ID</strong> (dari dashboard Kirimdev → GET /accounts).',
        '<strong>OneSender</strong> — provider tidak rasmi, isi <strong>Bearer token</strong>. Tidak terikat 24-hour window.',
        '<strong>Fonte (Fonnte)</strong> — isi Authorization token; sesuai untuk OTP ke nombor mana-mana.',
        '<strong>StarSender / DripSender</strong> — provider tidak rasmi, isi API key.',
    ])
    . $info('<strong>Info:</strong> Provider WhatsApp turut digunakan untuk <strong>OTP log masuk</strong> dan saluran WhatsApp pada Abandoned Cart/Broadcast.'),

  'email-setup' =>
    $steps([
        ['Daftar Mailketing', 'Buat akaun di Mailketing dan ambil <strong>API Key</strong> dari dashboard.'],
        ['Isi di Averion', $mono('/admin/integrations') . ' → Mailketing → isi API Key, <strong>Sender Email</strong>, dan <strong>Sender Name</strong>.'],
        ['Guna', 'Emel digunakan automatik untuk notifikasi emel, Email Sequence, dan Abandoned Cart (saluran emel).'],
    ])
    . $info('<strong>Petua:</strong> Guna sender emel berdomain sendiri (cth. ' . $mono('noreply@domainanda.com') . ') supaya kebolehhantaran lebih baik.'),

  'lisensi-produk' =>
    $h3('Cara Berfungsi')
    . '<p class="mb-5">Untuk produk yang dijual dengan perlindungan lesen (cth. perisian/plugin), Averion menjana <strong>license key</strong> setiap pembelian. Perisian milik pembeli kemudian mengesahkan kunci tersebut ke API Averion (lihat <strong>API Developer</strong>).</p>'
    . $steps([
        ['Aktifkan lesen pada produk', 'Di borang produk, aktifkan pilihan lesen dan tentukan bilangan <strong>seat</strong> (maks pengaktifan) serta tempoh sah (pilihan).'],
        ['Kunci terbit automatik', 'Apabila pesanan dibayar, license key dijana automatik dan dihantar kepada pembeli.'],
        ['Urus lesen', 'Buka ' . $mono('/admin/licenses') . ' → tapis status, cari berdasarkan kunci / emel / nama.'],
        ['Butiran & tindakan', 'Buka butiran (' . $mono('/admin/licenses/{id}') . ') untuk melihat pengaktifan per domain/peranti, log API, kemudian <strong>Suspend</strong> / <strong>Revoke</strong> jika perlu.'],
    ])
    . $h3('Status Lesen')
    . $badges(['Active', 'Suspended', 'Revoked', 'Expired'])
    . $info('<strong>Info:</strong> <strong>Seat</strong> = bilangan domain/peranti yang boleh mengaktifkan satu kunci. Pembeli boleh melihat lesen mereka di halaman akaun mereka.'),

  'api-keys-overview' =>
    '<p class="mb-4">Buka ' . $mono('/admin/api-keys-overview') . ' untuk melihat ringkasan integrasi yang <strong>Aktif / Tidak Aktif</strong>, dikumpulkan mengikut jenis:</p>'
    . $badges(['Payment (Duitku, Xendit, Moota)', 'Email (Mailketing)', 'WhatsApp (5 provider)', 'Tracking (Pixel/CAPI)'])
    . '<p class="mb-4">Halaman ini turut memaparkan <strong>log terkini</strong> dari integrasi & transaksi pembayaran — berguna untuk cepat mendiagnosis integrasi mana yang belum tersambung.</p>'
    . $info('<strong>Petua:</strong> Jadikan halaman ini semakan rutin selepas menukar kredensial gateway/provider untuk memastikan semuanya hijau.'),

  'staff' =>
    $steps([
        ['Buka Staff', 'Akses ' . $mono('/admin/staff') . ' (khusus admin utama) → <strong>Tambah Staf</strong>.'],
        ['Buat akaun', 'Isi nama, emel, kata laluan staf.'],
        ['Pilih kebenaran', 'Tandakan akses: Produk, Transaksi, Pengguna, Kandungan, Kupon, Affiliate, Muat Turun, Email Sequence, Sijil, Abandoned Cart, Broadcast, dll.'],
    ])
    . $warn('<strong>Had:</strong> Staf <strong>tidak boleh</strong> mengubah Tetapan, Penjenamaan, Lesen, Integrasi, dan tidak boleh mengurus staf lain. Membuka menu tanpa kebenaran akan dialihkan ke halaman No Access.'),

  'users' =>
    $steps([
        ['Buka Users', 'Akses ' . $mono('/admin/users') . '.'],
        ['Urus akaun', 'Lihat butiran, ubah status (aktif/tidak aktif), dan atur akses produk pengguna.'],
        ['Impersonate', 'Guna ciri <strong>Impersonate</strong> untuk log masuk sebagai pengguna tertentu — berguna semasa membantu/menyelesaikan aduan akses.'],
    ])
    . $warn('<strong>Perhatian:</strong> Tamatkan sesi impersonate selepas selesai. Guna hanya untuk sokongan, bukan bertindak bagi pihak pengguna tanpa kebenaran.'),

  'transaksi' =>
    $steps([
        ['Buka Transaksi', 'Akses ' . $mono('/admin/transactions') . ' untuk senarai semua pesanan.'],
        ['Butiran & pengesahan', 'Buka butiran pesanan untuk mengesahkan pembayaran manual (Manual Transfer) dan mengaktifkan akses pembeli.'],
        ['Invois PDF', 'Muat turun/lihat invois dalam format PDF dari halaman butiran transaksi.'],
    ])
    . $h3('Order Expiry (Tempoh Sah Pesanan)')
    . '<p class="mb-3">Tetapkan ' . $mono('order_expiry_hours') . ' di ' . $mono('/admin/settings') . '. Pesanan pending yang melepasi had akan tamat tempoh automatik.</p>'
    . $info('<strong>Info:</strong> Tetapkan kepada <strong>0</strong> untuk menyahaktifkan tamat tempoh automatik (pesanan pending tidak akan dibatalkan sistem).'),

  'google-oauth' =>
    $info('<strong>Info:</strong> Ciri ini <strong>OFF</strong> secara lalai — aktifkan di ' . $mono('/admin/settings') . ' (' . $mono('google_oauth_enabled') . ').')
    . $steps([
        ['Buat projek', 'Buka ' . $mono('console.cloud.google.com') . ' → buat projek baharu.'],
        ['Buat credentials', 'APIs &amp; Services → Credentials → <strong>Create OAuth 2.0 Client ID</strong>.'],
        ['Application type', 'Pilih <strong>Web application</strong>.'],
        ['Redirect URI', 'Tambah Authorized redirect URI (di bawah).'],
        ['Isi di Averion', $mono('/admin/settings') . ' → isi Client ID + Client Secret → aktifkan toggle.'],
    ])
    . $code('https://domain-anda.com/auth/google/callback')
    . $warn('<strong>Nota:</strong> Admin tidak boleh log masuk via Google (by design) — guna emel &amp; kata laluan.')
    . $info('<strong>Info:</strong> Pengguna dengan emel yang sama akan di-merge automatik.'),

  'update-sistem' =>
    $steps([
        ['Semak versi', 'Buka ' . $mono('/admin/update') . ' → <strong>Cek Update</strong> (menghubungi pelayan lesen).'],
        ['Lihat changelog', 'Jika ada kemas kini, info versi & changelog dipaparkan.'],
        ['Kemas kini sekarang', 'Klik <strong>Update Sekarang</strong> → sahkan → proses automatik.'],
        ['Proses', 'Muat turun → pengesahan → sandaran → apply → selesai.'],
    ])
    . '<p class="mb-3">Sandaran automatik disimpan di folder ' . $mono('backups/') . ' di luar webroot. Jika gagal, pulihkan dari folder tersebut.</p>'
    . $warn('<strong>Perhatian:</strong> Pastikan tiada transaksi berjalan semasa kemas kini.')
    . $info('<strong>Info:</strong> ' . $mono('config.php') . ' dan folder ' . $mono('uploads/') . ' tidak ditimpa semasa kemas kini.'),

];

// ═══════════════════════ MULAI ═══════════════════════

echo $section('instalasi',
  $L('Instalasi', 'Pemasangan', 'Installation'),
  $L('Panduan lengkap memasang ' . e($appName) . ' di hosting kamu.', 'Panduan lengkap memasang ' . e($appName) . ' di hosting anda.', 'Complete guide to install ' . e($appName) . '.'),
  $h3('Persyaratan Hosting')
  . $ul([
      'PHP versi 8.0 atau lebih baru',
      'MySQL 5.7+ atau MariaDB 10.3+',
      'Modul ' . $mono('mod_rewrite') . ' aktif (Apache) atau setara di Nginx',
      'Ekstensi PHP: ' . $mono('pdo_mysql') . ' ' . $mono('curl') . ' ' . $mono('mbstring') . ' ' . $mono('openssl') . ' ' . $mono('gd'),
  ])
  . $h3('Langkah Instalasi')
  . $steps([
      ['Download file Averion', 'Ambil file instalasi dari panel lisensi setelah pembelian.'],
      ['Upload ke hosting', 'Upload via FileManager / FTP ke folder ' . $mono('public_html') . ', lalu ekstrak.'],
      ['Buka installer', 'Akses ' . $mono('https://domain-kamu.com/installer') . ' di browser.'],
      ['Isi form installer', 'Masukkan nama database, user database, password database, dan host database.'],
      ['Klik Install', 'Tunggu hingga proses selesai — jangan tutup tab.'],
      ['Selesai', 'Folder installer otomatis dikunci setelah instalasi berhasil.'],
  ])
  . $code('https://domain-kamu.com/installer')
  . $info('<strong>Tips:</strong> Jika portal dipasang di subfolder, sesuaikan ' . $mono('APP_URL') . ' di ' . $mono('config.php') . '.')
  . $warn('<strong>Perhatian:</strong> Selalu backup database sebelum install ulang.')
);

echo $section('aktivasi-lisensi',
  $L('Aktivasi Lisensi', 'Pengaktifan Lesen', 'License Activation'),
  $L('Cara mengaktifkan lisensi Averion setelah instalasi.', 'Cara mengaktifkan lesen selepas pemasangan.', 'Activate your license after installation.'),
  $steps([
      ['Buka halaman Lisensi', 'Akses ' . $mono('/admin/license') . ' setelah instalasi selesai.'],
      ['Masukkan license key', 'Gunakan license key yang dikirim ke email saat pembelian.'],
      ['Verifikasi', 'Klik <strong>Aktivasi Lisensi</strong> — sistem menghubungi server lisensi.'],
      ['Status aktif', 'Jika berhasil, status berubah menjadi <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">Active</span>.'],
  ])
  . '<p class="mb-2">Format license key:</p>'
  . $code('AVRN-XXXX-XXXX-XXXX-XXXX')
  . $info('<strong>Info:</strong> Domain kamu terikat ke lisensi ini secara otomatis saat verifikasi pertama.')
  . $warn('<strong>Perhatian:</strong> Satu lisensi hanya berlaku untuk satu domain.')
  . $info('<strong>Tips:</strong> Lisensi bisa dipindah domain dengan menghubungi support.')
);

echo $section('setup-awal',
  $L('Setup Pertama', 'Persediaan Awal', 'Initial Setup'),
  $L('Konfigurasi dasar setelah Averion berhasil diinstall.', 'Konfigurasi asas selepas Averion dipasang.', 'Basic configuration after install.'),
  $steps([
      ['Branding', 'Buka ' . $mono('/admin/branding') . ' → isi nama toko, upload logo & favicon, atur warna brand.'],
      ['Pengaturan umum', 'Buka ' . $mono('/admin/settings') . ' → isi email & URL support, mata uang, dll.'],
      ['Metode pembayaran', 'Aktifkan minimal satu: payment gateway (' . $mono('/admin/integrations') . ') atau Manual Transfer (' . $mono('/admin/bank-accounts') . ').'],
      ['Notifikasi', 'Setup pengirim email/WhatsApp di ' . $mono('/admin/integrations') . ', lalu aktifkan template di ' . $mono('/admin/notifications') . '.'],
      ['Produk pertama', 'Buka ' . $mono('/admin/products') . ' → tambah produk pertama.'],
      ['Test checkout', 'Lakukan test checkout dengan Manual Transfer / mode simulasi dulu.'],
  ])
  . $h3('Checklist Setup Awal')
  . '<ul class="space-y-2 mb-5 text-sm text-gray-600 dark:text-gray-300">'
    . '<li class="flex items-center gap-2">' . $chk . 'Branding (nama, logo, warna) sudah diisi</li>'
    . '<li class="flex items-center gap-2">' . $chk . 'Email &amp; URL support sudah diisi</li>'
    . '<li class="flex items-center gap-2">' . $chk . 'Minimal satu metode pembayaran aktif</li>'
    . '<li class="flex items-center gap-2">' . $chk . 'Pengirim notifikasi (email/WA) tersambung</li>'
    . '<li class="flex items-center gap-2">' . $chk . 'Minimal satu produk dibuat</li>'
    . '<li class="flex items-center gap-2">' . $chk . 'Test checkout berhasil</li>'
  . '</ul>'
  . $info('<strong>Tips:</strong> Mulai dengan Manual Transfer (Rekening Bank) — paling cepat aktif tanpa pendaftaran gateway.')
);

// ═══════════════════════ PRODUK & PENJUALAN ═══════════════════════

echo $section('produk-checkout',
  $L('Produk &amp; Checkout', 'Produk &amp; Checkout', 'Products &amp; Checkout'),
  $L('Kelola produk digital dan pengaturan halaman checkout.', 'Urus produk digital dan tetapan checkout.', 'Manage digital products and checkout.'),
  $h3('Tipe Produk')
  . $badges(['Class', 'Membership', 'Ebook', 'Software', 'Jasa', 'Coaching', 'Webinar', 'Template', 'Other'])
  . $h3('1. Membuat Produk')
  . $steps([
      ['Buka Produk', 'Menu ' . $mono('/admin/products') . ' → <strong>Tambah Produk</strong>.'],
      ['Tipe dan Kategori Etalase', 'Pilih <strong>Tipe</strong> untuk isi otomatis produk. <strong>Kategori Etalase</strong> opsional: pilih nama yang sudah ada atau ketik nama baru (maksimal 80 karakter). Nama ini tampil pada produk dan dapat difilter di katalog.'],
      ['Isi data dasar', 'Nama, harga (+ harga coret opsional), deskripsi, thumbnail (rasio 1:1), status <strong>Aktif</strong>.'],
      ['Pilih Landing Page', '<strong>None</strong> (pakai halaman produk bawaan), <strong>Canvas</strong> (HTML kustommu), atau <strong>URL eksternal</strong>.'],
      ['Pilih Template Checkout', 'Form checkout per produk — kelola di ' . $mono('/admin/checkout-templates') . '.'],
      ['Simpan', 'Produk langsung punya halaman jual sendiri di ' . $mono('/products/{slug}') . '.'],
  ])
  . $info('Setelah tersimpan, dari halaman edit produk kamu bisa attach <strong>Download &amp; Materi</strong>, aktifkan <strong>Sertifikat</strong> &amp; <strong>Review</strong>.')
  . $info('Di homepage, <strong>Produk Baru Rilis</strong> menampilkan satu produk aktif terbaru berdasarkan tanggal dibuat. Produk draft, Coming Soon, dan yang disembunyikan dari katalog tidak ditampilkan. Seksi ini bisa dinyalakan atau dimatikan di ' . $mono('/admin/branding') . ' → <strong>Tampilkan Produk Baru Rilis</strong>.')

  . $h3('Peta Form Produk — 5 Tab')
  . '<p class="mb-3">Form Tambah/Edit Produk terbagi jadi <strong>5 tab</strong>. Yang <strong>wajib</strong> hanya Nama, Slug, Tipe, Status, dan Harga; sisanya opsional — isi sesuai kebutuhan.</p>'
  . $ul([
      '<strong>Dasar</strong> — Nama, <strong>Slug URL</strong> (jadi alamat ' . $mono('/products/{slug}') . '), Tipe, Status (Aktif / Draft), <strong>Kategori Etalase</strong>, <strong>Jadwal Launch</strong> (set tanggal → produk tampil <em>Coming Soon</em> sampai waktunya, bisa dipasangkan waitlist), dan <strong>Sembunyikan dari katalog</strong> (produk hanya diakses lewat link langsung, tak muncul di etalase).',
      '<strong>Harga &amp; Akses</strong> — <strong>Tipe Harga</strong> (tetap / gratis / <em>Bayar Fleksibel</em>), Harga, <strong>Harga Asli</strong> (coret), <strong>Teks Tombol Kustom</strong>, <strong>Durasi Akses</strong> (hari; &gt;0 = langganan, 0 = sekali bayar / selamanya), <strong>Komisi Afiliasi %</strong> per produk, <strong>Setup Fee</strong> (biaya awal), <strong>Harga Alias Ringgit (RM)</strong>, plus toggle fitur jual: <em>Booking Fee (DP), Dime Sale, JV Partner</em>.',
      '<strong>Media &amp; Link</strong> — <strong>Thumbnail</strong> (rasio 1:1), <strong>Landing Page</strong> (None / Canvas / URL eksternal), <strong>Mode Checkout</strong> (Login / Public), dan <strong>Template Form Publik</strong>.',
      '<strong>Deskripsi</strong> — Deskripsi Singkat (tampil di listing) &amp; Lengkap, <strong>Yang Kamu Dapat</strong> &amp; <strong>Isi Paket</strong> (highlight di landing, 1 poin per baris), serta <strong>AI Knowledge</strong> (bekal jualan untuk AI Chat Widget).',
      '<strong>Lanjutan</strong> — <strong>Sertifikat</strong>, <strong>Review &amp; Testimoni</strong>, <strong>Redirect Setelah Bayar</strong>, <strong>Kode Embed (HTML)</strong> form order, pengiriman <strong>lisensi</strong> (Maks Aktivasi/Seats + Masa Berlaku) untuk produk Software, <strong>Kode Program Eksternal</strong> (integrasi webhook), dan <strong>Salespage Reseller</strong> (lihat poin 5 di bawah).',
  ])
  . $info('Fitur dengan setelan mendalam punya topik sendiri di sidebar: <strong>Bayar Fleksibel</strong>, <strong>Langganan</strong>, <strong>Booking Fee</strong>, <strong>Dime Sale</strong>, <strong>JV Partner</strong>, <strong>Redirect Setelah Bayar</strong>, <strong>Sertifikat</strong>, <strong>Review</strong>, <strong>Download &amp; Materi</strong>, dan <strong>AI Chat Widget</strong> — buka topik terkait untuk detailnya.')
  . $warn('<strong>Slug URL</strong> membentuk alamat publik produk — hindari menggantinya setelah produk tersebar (link lama bisa mati). <strong>Durasi Akses &gt; 0</strong> mengubah produk jadi <strong>langganan</strong> (akses kedaluwarsa setelah N hari); biarkan <strong>0</strong> untuk akses selamanya.')

  . $h3('2. Login Checkout vs Public Checkout')
  . '<p class="mb-3">Averion punya dua mode order. Atur per produk (kolom <strong>Mode Checkout</strong>):</p>'
  . $ul([
      '<strong>Login Checkout:</strong> pembeli login / daftar akun dulu. Cocok untuk <strong>membership &amp; kelas</strong> (butuh akun untuk mengakses isinya).',
      '<strong>Public Checkout:</strong> pembeli cukup isi nama + email + WA, <strong>tanpa akun</strong>. Friksi rendah — cocok untuk produk sekali-beli & jualan lewat landing page.',
  ])
  . $warn('Kalau kamu menjual lewat <strong>landing page</strong> (Canvas / eksternal), sebaiknya set <strong>Mode Checkout = Public</strong> supaya pembeli bisa langsung order tanpa harus punya akun.')

  . $h3('3. Link Produk untuk Dibagikan / Dipasang di Mana Saja')
  . '<p class="mb-3">Tiap produk otomatis punya link. Salin dari daftar produk (ikon <strong>Salin URL Produk</strong>). Ada dua link penting:</p>'
  . $code('Halaman jual (sales page)&nbsp;: https://domainmu.com/products/nama-produk')
  . $code('Form order langsung (tamu)&nbsp;: https://domainmu.com/order/nama-produk')
  . $info('<strong>Penting:</strong> Portal memakai tautan checkout sebagai pengganti "embed / tempel kode form". Untuk memasang order di landing page mana pun, cukup buat <strong>tombol Beli</strong> yang mengarah ke salah satu link di atas.')

  . $h3('4. Memasang Produk di Landing Page — 3 Cara')
  . $steps([
      ['Cara A — Halaman produk bawaan (paling cepat)', 'Tak perlu landing sendiri. Bagikan link ' . $mono('/products/nama-produk') . ' — sudah ada tombol beli &amp; checkout bawaan.'],
      ['Cara B — Landing sendiri via Canvas', 'Buat HTML landing-mu, pasang di <strong>Canvas</strong> (lihat topik Canvas), lalu tautkan ke produk ini. Di HTML-mu, arahkan <strong>tombol Beli</strong> ke link checkout di atas.'],
      ['Cara C — Landing di luar Averion', 'Punya landing di situs lain? Di produk, isi <strong>Landing Page = URL eksternal</strong>. Di halaman landing itu, buat tombol yang menuju ' . $mono('/order/nama-produk') . '.'],
  ])
  . $info('<strong>Tips:</strong> Gunakan thumbnail rasio 1:1, dan pakai <strong>Public Checkout</strong> untuk jualan lewat landing agar konversi lebih mulus.')

  . $h3('5. Reseller — Salespage Siap Pakai (jual ulang produk)')
  . '<p class="mb-3">Untuk model <strong>lisensi resell</strong>: pembeli hak resell bisa menjual ulang lisensi <strong>Personal</strong> tanpa perlu punya website sendiri. Dua produk saling ditautkan:</p>'
  . $ul([
      '<strong>Produk Personal</strong> — lisensi yang dijual reseller ke pembeli akhir (untung 100%, lewat WhatsApp / off-platform).',
      '<strong>Produk Resell</strong> — dibeli seseorang untuk <strong>menjadi reseller</strong> (hak jual ulang + komisi merekrut reseller baru).',
  ])
  . '<p class="mt-4 mb-2 text-sm font-semibold text-gray-800 dark:text-gray-200">Yang diatur admin (edit produk <strong>Personal</strong> → tab Lanjutan):</p>'
  . $steps([
      ['Aktifkan Salespage Siap Pakai', 'Centang di tab <strong>Lanjutan</strong> pada produk Personal.'],
      ['Pilih Produk Resell', 'Tentukan produk lisensi <strong>Resell</strong> yang dituju tombol <strong>Order Resell Rights</strong>. Reseller yang <strong>memiliki produk Resell ini</strong> otomatis mendapat link salespage produk Personal di dashboard mereka. Harga yang tampil = <strong>Harga</strong> produk Personal.'],
      ['Aktifkan Halaman Akses Produk', 'Untuk pengiriman akses: pembeli personal-use membuka konten (modul &amp; materi) lewat halaman berpassword tanpa login. <strong>Password diatur masing-masing reseller</strong>, bukan admin.'],
  ])
  . '<p class="mt-4 mb-2 text-sm font-semibold text-gray-800 dark:text-gray-200">Yang dilakukan reseller (menu <strong>Reseller</strong> di dashboard):</p>'
  . $ul([
      'Salin <strong>link salespage siap pakai</strong> ' . $mono('/on/nama-produk?ref=KODE') . ' lalu sebarkan untuk promosi.',
      'Atur <strong>teks WhatsApp</strong> tombol Order Personal Use + pilih menampilkan/tidak tombol <strong>Order Resell Rights</strong>.',
      'Set <strong>password akses</strong> sendiri, lalu kirim <strong>link akses</strong> ' . $mono('/akses/nama-produk') . ' + password ke pembeli via WhatsApp (setelah pembeli bayar).',
  ])
  . $info('<strong>Alur order:</strong> tombol <strong>Order Personal Use</strong> → WhatsApp reseller (transaksi &amp; kirim akses manual oleh reseller, untung 100%). Tombol <strong>Order Resell Rights</strong> → checkout produk Resell dengan ref reseller (komisi otomatis via afiliasi). Halaman ' . $mono('/akses/{slug}') . ' menampilkan konten produk setelah pembeli memasukkan password dari reseller.')
  . $warn('Agar tombol <strong>Order Resell Rights</strong> mulus untuk pengunjung tanpa akun, set <strong>Mode Checkout = Public</strong> pada <strong>produk Resell</strong>-nya.')
);

$myBodies['landing-produk'] =
  $h3('Automatik ikut kategori')
  . '<p class="mb-4">Medan <strong>Tipe</strong> menentukan kandungan automatik seperti <strong>&ldquo;Yang kamu dapat&rdquo;</strong>. <strong>Kategori Etalase</strong> boleh diberi nama sendiri untuk label yang dilihat pembeli dan penapis katalog; jika kosong, label Tipe asal digunakan.</p>'
  . $ul([
      '<strong>Software</strong> — lesen, fail &amp; panduan pemasangan, kemas kini.',
      '<strong>E-book / Template</strong> — fail sedia muat turun / sedia guna.',
      '<strong>Jasa / Coaching / Webinar</strong> — sesi, bimbingan, rakaman.',
      '<strong>Kelas</strong> — bilangan modul &amp; bahan (seperti biasa).',
  ])
  . '<p class="mb-4">Produk bukan-kelas juga boleh papar bahagian <strong>&ldquo;Isi Pakej&rdquo;</strong> (pengganti Kurikulum).</p>'
  . $h3('Tulis sendiri (pilihan)')
  . $steps([
      ['Buka tab Deskripsi', 'Edit produk di ' . $mono('/admin/products') . ' → tab <strong>Deskripsi</strong>.'],
      ['Isi medan', '<strong>Yang Kamu Dapat</strong> &amp; <strong>Isi Pakej</strong> — satu poin setiap baris.'],
      ['Kosong = automatik', 'Biarkan kosong untuk guna senarai automatik ikut kategori.'],
  ])
  . $info('<strong>Nota:</strong> Bila diisi, senarai anda menggantikan yang automatik. Produk kelas kekal papar modul &amp; bahan.');

echo $section('landing-produk',
  $L('Konten Landing Produk', 'Kandungan Landing Produk', 'Product Landing Content'),
  $L('Halaman produk otomatis menyesuaikan &ldquo;Yang kamu dapat&rdquo; &amp; &ldquo;Isi Paket&rdquo; dengan kategori — bisa ditimpa manual.', 'Halaman produk menyesuaikan kandungan ikut kategori — boleh ditulis sendiri.', 'The product page adapts its content to the category.'),
  $h3('Otomatis per kategori')
  . '<p class="mb-4">Field <strong>Tipe</strong> menentukan isi otomatis seperti <strong>&ldquo;Yang kamu dapat&rdquo;</strong>. <strong>Kategori Etalase</strong> bisa diberi nama sendiri untuk label yang dilihat pembeli dan filter katalog; jika kosong, label Tipe lama tetap dipakai.</p>'
  . $ul([
      '<strong>Software</strong> — lisensi, file &amp; panduan instalasi, update.',
      '<strong>E-book / Template</strong> — file siap unduh / siap pakai.',
      '<strong>Jasa / Coaching / Webinar</strong> — sesi, bimbingan, rekaman.',
      '<strong>Kelas</strong> — jumlah modul &amp; materi (seperti sebelumnya).',
  ])
  . '<p class="mb-4">Produk non-kelas juga bisa menampilkan bagian <strong>&ldquo;Isi Paket&rdquo;</strong> (pengganti Kurikulum).</p>'
  . $h3('Tulis sendiri (opsional)')
  . $steps([
      ['Buka tab Deskripsi', 'Edit produk di ' . $mono('/admin/products') . ' → tab <strong>Deskripsi</strong>.'],
      ['Isi field', '<strong>Yang Kamu Dapat</strong> &amp; <strong>Isi Paket</strong> — satu poin per baris.'],
      ['Kosong = otomatis', 'Biarkan kosong untuk memakai daftar otomatis sesuai kategori.'],
  ])
  . $info('<strong>Catatan:</strong> Bila diisi, daftarmu menggantikan yang otomatis. Produk kelas tetap menampilkan modul &amp; materi.')
);

echo $section('order-bump',
  $L('Order Bump', 'Order Bump', 'Order Bump'),
  $L('Penawaran tambahan satu-klik saat checkout untuk menaikkan nilai order.', 'Tawaran tambahan semasa checkout.', 'One-click upsell at checkout.'),
  '<p class="mb-4"><strong>Order Bump</strong> adalah penawaran produk pelengkap yang muncul sebagai <strong>checkbox</strong> di halaman checkout <strong>Produk Utama</strong>. Pembeli cukup mencentangnya — produk bump ikut masuk order dan <strong>aktif otomatis</strong> untuk pembeli setelah pembayaran lunas (tanpa checkout terpisah).</p>'
  . $h3('Cara membuat')
  . $steps([
      ['Buka Product Bumps', 'Akses ' . $mono('/admin/product-bumps') . '.'],
      ['Pilih Produk Utama', 'Pilih produk yang <strong>checkout-nya</strong> akan menampilkan bump ini — tiap produk punya daftar bump sendiri.'],
      ['Pilih Produk Bump &amp; Harga', 'Tentukan <strong>Produk Bump</strong> yang ditawarkan + <strong>Harga Bump</strong> (kosongkan = pakai harga normal produk; isi angka untuk harga spesial khusus bump).'],
      ['Tulis Headline &amp; Deskripsi', 'Kalimat penawaran singkat di kartu bump (mis. &ldquo;Tambah hanya Rp 49.000!&rdquo;).'],
      ['Atur Status &amp; Urutan Tampil', 'Set <strong>Aktif</strong>; bila satu produk punya &gt;1 bump, <strong>Urutan Tampil</strong> menentukan susunannya di checkout.'],
  ])
  . $info('<strong>Satu produk boleh punya beberapa bump.</strong> Semua yang aktif tampil (sesuai urutan) di checkout produk utama — pembeli bisa mencentang lebih dari satu.')
  . $info('<strong>Tips:</strong> Tawarkan produk pelengkap yang murah &amp; relevan agar konversi bump tinggi.')
  . $warn('Order Bump berbeda dari <strong>Upsell</strong> (tampil <em>setelah</em> bayar) &amp; <strong>Bonus Kilat</strong> (produk <em>gratis</em> berbatas waktu). Lihat topik masing-masing.')
);

echo $section('bonus-kilat',
  $L('Bonus Kilat', 'Bonus Kilat', 'Flash Bonus'),
  $L('Bonus gratis berbatas waktu untuk mendorong pembeli segera bayar (urgensi/scarcity).', 'Bonus percuma berhad masa untuk mendesak pembayaran.', 'Time-limited free bonus to drive urgency.'),
  '<p class="mb-4">Bonus Kilat menambahkan <strong>produk gratis</strong> ke order pembeli SELAMA mereka menyelesaikan pembayaran sebelum <strong>hitung mundur</strong> habis. Dipakai untuk menciptakan urgensi (&ldquo;selesaikan sekarang atau bonus hangus&rdquo;).</p>'
  . $h3('Cara mengaktifkan')
  . $steps([
      ['Buka pengaturan Bonus Kilat', 'Akses ' . $mono('/admin/flash-bonus') . ' lalu pilih <strong>Produk Utama</strong> — tiap produk punya konfigurasi bonusnya sendiri.'],
      ['Atur blok (per produk)', 'Nyalakan <strong>Tampilkan di Checkout</strong>, set <strong>Masa Berlaku</strong> hitung mundur (menit, s/d 1440 = 24 jam), <strong>Maks. Bonus Dipilih</strong> (berapa bonus boleh diambil pembeli), dan <strong>Judul Blok</strong> yang tampil ke pembeli.'],
      ['Tambah penawaran bonus', 'Tiap bonus: pilih <strong>Produk Bonus</strong> (diberikan gratis), <strong>Headline</strong>, <strong>Deskripsi</strong>, status, &amp; <strong>Urutan Tampil</strong>. Boleh &gt;1 bonus per produk.'],
  ])
  . $h3('Di mana muncul (anti-nunda)')
  . $ul([
      '<strong>Halaman checkout</strong> — kartu bonus + hitung mundur; bonus ikut masuk order bila dibayar sebelum waktu habis.',
      '<strong>Halaman pending / menunggu pembayaran</strong> — bonus yang terkunci ditampilkan lagi dengan hitung mundur yang <strong>meneruskan</strong> timer dari checkout (berbasis cookie), agar pembeli terdorong segera menuntaskan.',
      '<strong>Reminder abandoned cart</strong> — bila order punya Bonus Kilat, urgensi bonus di-<em>append</em> ke pesan reminder. Teksnya bisa diedit lewat template ' . $mono('abandoned_cart_bonus') . ' (email/WA, placeholder ' . $mono('{{flash_bonus_list}}') . ') di ' . $mono('/admin/notifications') . '.',
  ])
  . $info('<strong>Catatan:</strong> Bonus Kilat berbeda dari Order Bump. Bump = penawaran <em>berbayar</em> opsional yang dicentang pembeli; Bonus Kilat = produk <strong>gratis</strong> otomatis yang menciptakan urgensi waktu.')
);

echo $section('poin-reward',
  $L('Poin Reward', 'Poin Reward', 'Reward Points'),
  $L('Poin loyalitas dari tiap transaksi, bisa ditukar jadi potongan harga di checkout.', 'Poin kesetiaan yang boleh ditebus sebagai diskaun.', 'Loyalty points redeemable as checkout discount.'),
  '<p class="mb-4">Member mendapat poin setiap transaksinya <strong>lunas</strong>, lalu bisa menukarkannya jadi <strong>potongan harga</strong> saat checkout. Tujuannya mendorong pembelian ulang.</p>'
  . $warn('Fitur ini <strong>MATI</strong> secara bawaan. Nyalakan di ' . $mono('/admin/settings') . ' &rarr; <strong>Poin Reward</strong>.')

  . $h3('Dua angka yang menentukan besar cashback')
  . $ul([
      '<strong>Dapat 1 poin setiap belanja (Rp)</strong> — bawaan 1.000. Belanja Rp 249.000 &rarr; 249 poin.',
      '<strong>Nilai 1 poin saat ditukar (Rp)</strong> — bawaan 50. Jadi 249 poin = Rp 12.450.',
  ])
  . '<p class="mb-3">Kombinasi bawaan itu setara <strong>cashback 5%</strong>. Menaikkan nilai tukar jadi Rp 100 membuatnya 10% &mdash; makin menarik bagi pembeli, tapi makin menggerus marginmu.</p>'

  . $h3('Pagar pengaman (penting)')
  . $ul([
      '<strong>Maksimal potongan per transaksi</strong> (bawaan 50%) menjaga total belanja <strong>tidak pernah jatuh ke Rp 0</strong>. Biarkan di bawah 100% &mdash; ini mencegah order jadi &ldquo;lunas otomatis&rdquo; tanpa pembayaran.',
      'Poin dihitung dari uang yang <strong>benar-benar dibayar</strong>, jadi belanja yang sudah dipotong poin tidak menghasilkan poin penuh lagi.',
      'Order yang <strong>dibatalkan</strong>: poin hadiahnya ditarik, dan poin yang sudah dipakai <strong>dikembalikan</strong> ke member.',
      'Poin hanya berlaku di <strong>checkout member yang login</strong> (checkout tamu tidak punya akun/saldo).',
  ])

  . $h3('Pembeli lama: mulai dari nol')
  . '<p class="mb-3">Saat fitur dinyalakan, <strong>tidak ada poin otomatis dari belanja masa lalu</strong>. Semua member mulai dari <strong>0 poin</strong> dan baru mengumpulkan dari transaksi <strong>berikutnya</strong>.</p>'
  . $info('Ini disengaja. Memberi poin surut atas seluruh riwayat penjualan akan menciptakan <strong>utang diskon seketika</strong> &mdash; mis. omzet historis Rp 100 juta pada setelan 5% langsung menjadi potongan senilai Rp 5 juta yang bisa ditukar hari itu juga.')

  . $h3('Yang dilihat member')
  . $ul([
      'Menu <strong>Reward Saya</strong> (nama bisa diganti) &mdash; saldo, nilai rupiahnya, dan riwayat lengkap bertaut nomor invoice.',
      'Menu <strong>Katalog</strong> &mdash; etalase produk dengan estimasi <em>&ldquo;Hemat Rp X pakai N poin&rdquo;</em> per produk.',
      'Kartu saldo poin di <strong>Dashboard</strong>.',
      'Di checkout: tombol <strong>Gunakan Saldo Poin</strong> beserta baris <em>Diskon poin</em> pada ringkasan pembayaran.',
  ])
  . $info('<strong>Bisa di-rebrand:</strong> sebutan poin (Poin / Koin / Reward / Cashback) dan nama menunya diatur di Pengaturan &mdash; menyesuaikan gaya brand-mu.')
);

echo $section('upsell',
  $L('Upsell Post-Purchase', 'Upsell Selepas Belian', 'Post-Purchase Upsell'),
  $L('Penawaran satu produk berharga spesial yang tampil SETELAH pembeli lunas — momen paling mudah untuk penjualan kedua.', 'Tawaran produk berharga istimewa selepas pembayaran selesai.', 'Special-priced offer shown right after payment.'),
  $h3('Bedanya dengan Order Bump')
  . '<p class="mb-4"><strong>Order bump</strong> tampil <em>sebelum</em> bayar (checkbox di checkout); <strong>upsell</strong> tampil <em>setelah</em> pembayaran lunas — di halaman sukses dan detail transaksi. Pembeli yang baru saja mengeluarkan uang jauh lebih mudah menerima penawaran kedua.</p>'
  . $steps([
      ['Buka Upsells', 'Akses ' . $mono('/admin/upsells') . ' → <strong>Tambah Penawaran</strong>.'],
      ['Pilih produk pemicu', 'Produk yang bila dibeli akan memunculkan penawaran (1 penawaran per produk pemicu).'],
      ['Pilih produk upsell + harga spesial', 'Produk yang ditawarkan beserta harga khususnya (biasanya lebih murah dari harga normal).'],
      ['Tulis headline &amp; deskripsi', 'Kalimat penawaran singkat yang muncul di kartu upsell.'],
      ['Aktifkan', 'Set status aktif — penawaran tampil otomatis setelah pembeli produk pemicu lunas.'],
  ])
  . $info('<strong>Judul Section Penawaran</strong> (setelan di atas form, berlaku global) adalah heading yang tampil di atas daftar upsell pada halaman sukses &amp; detail transaksi — ubah agar sesuai gaya brand.')
  . $info('<strong>Tips:</strong> Upsell terbaik adalah "langkah berikutnya" yang logis — pembeli kelas dasar ditawari kelas lanjutan, pembeli e-book ditawari template siap pakai.')
);

echo $section('bundling',
  $L('Bundling Produk', 'Bundling Produk', 'Product Bundles'),
  $L('Gabungkan beberapa produk dalam satu paket berharga khusus.', 'Gabungkan beberapa produk dalam satu pakej.', 'Combine products into one priced package.'),
  $steps([
      ['Buka Bundles', 'Akses ' . $mono('/admin/bundles') . ' → <strong>Buat Bundle</strong>.'],
      ['Isi detail', 'Nama, slug, deskripsi, dan thumbnail bundle.'],
      ['Pilih item', 'Tambahkan beberapa produk ke dalam bundle.'],
      ['Set harga', 'Tentukan <strong>Harga Bundle</strong>; harga normal dihitung dari total item (badge hemat % otomatis).'],
      ['Publish', 'Set status aktif — halaman publik tersedia di ' . $mono('/bundles/{slug}') . '.'],
  ])
  . $info('<strong>Info:</strong> Saat dibeli, SEMUA produk dalam bundle otomatis diaktifkan untuk buyer setelah pembayaran dikonfirmasi.')
);

echo $section('kupon',
  $L('Kupon Diskon', 'Kupon Diskaun', 'Discount Coupons'),
  $L('Buat kode kupon untuk potongan harga di checkout.', 'Cipta kod kupon untuk potongan harga.', 'Create coupon codes for checkout discounts.'),
  $steps([
      ['Buka Coupons', 'Akses ' . $mono('/admin/coupons') . ' → <strong>Tambah Kupon</strong>.'],
      ['Kode & tipe', 'Tentukan kode unik + tipe potongan: nominal tetap (' . e(currencySymbol()) . ') atau persentase (%).'],
      ['Batas penggunaan', 'Atur kuota maksimal penggunaan dan tanggal kadaluarsa.'],
      ['Cakupan', 'Berlaku untuk semua produk atau produk tertentu.'],
  ])
  . $info('<strong>Tips:</strong> Buat kupon kadaluarsa singkat (mis. 48 jam) untuk mendorong urgensi pembelian.')
);

echo $section('checkout-templates',
  $L('Template Checkout', 'Templat Checkout', 'Checkout Templates'),
  $L('Pilih tampilan halaman form checkout per produk.', 'Pilih paparan checkout per produk.', 'Choose the checkout form layout per product.'),
  $h3('Varian Tersedia')
  . $badges(['Standard', 'Minimal', 'Two-Step', 'Sidebar Sticky'])
  . $steps([
      ['Buka Checkout Templates', 'Akses ' . $mono('/admin/checkout-templates') . '.'],
      ['Atur default', 'Tandai satu template sebagai <strong>default</strong> untuk semua produk.'],
      ['Assign per produk', 'Di form produk (bagian checkout), pilih template khusus jika ingin berbeda dari default.'],
  ])
  . $h3('Pilih Varian untuk Kurangi Friksi')
  . $ul([
      '<strong>Standard</strong> — form lengkap satu halaman. Aman untuk mayoritas produk.',
      '<strong>Minimal</strong> — field paling sedikit; cocok produk murah / impulse buy.',
      '<strong>Two-Step</strong> — pisah jadi 2 langkah (isi data dulu → baru pilih pembayaran). Menurunkan rasa "form panjang" &amp; menaikkan penyelesaian checkout.',
      '<strong>Sidebar Sticky</strong> — ringkasan order menempel di samping agar pembeli selalu lihat apa yang dibeli + totalnya.',
  ])
  . $info('<strong>Tips:</strong> Kalau <strong>Funnel Analytics</strong> menunjukkan banyak yang berhenti di tahap checkout, coba ganti ke <strong>Two-Step</strong> atau <strong>Minimal</strong> lalu bandingkan konversinya.')
);

echo $section('rekening-bank',
  $L('Rekening Bank', 'Akaun Bank', 'Bank Accounts'),
  $L('Rekening tujuan untuk pembayaran Manual Transfer.', 'Akaun tujuan untuk Manual Transfer.', 'Destination accounts for manual transfer.'),
  $steps([
      ['Buka Rekening Bank', 'Akses ' . $mono('/admin/bank-accounts') . ' → <strong>Tambah Rekening</strong>.'],
      ['Isi data', 'Pilih bank (BCA, Mandiri, BNI, BRI, dll), nomor rekening, dan nama pemilik.'],
      ['Urutkan', 'Atur urutan tampil; rekening aktif muncul di checkout Manual Transfer.'],
  ])
  . $info('<strong>Info:</strong> Manual Transfer tidak butuh gateway. Buyer transfer manual lalu admin konfirmasi di Transaksi (atau otomatis via Moota).')
);

echo $section('waitlist',
  $L('Waitlist / Coming Soon', 'Waitlist / Coming Soon', 'Waitlist / Coming Soon'),
  $L('Kumpulkan calon pembeli SEBELUM produk dirilis — notifikasi otomatis saat launch.', 'Kumpul bakal pembeli SEBELUM produk dilancarkan.', 'Collect buyers before launch.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Produk berstatus <strong>Coming Soon</strong> tetap tampil di katalog &amp; landing dengan badge, tapi tombol beli berganti <strong>form Daftar Waitlist</strong> (nama + WhatsApp + email; user login cukup 1 klik). Saat produk launch, semua pendaftar dinotifikasi otomatis via email + WhatsApp berisi link produk.</p>'
  . $steps([
      ['Set produk Coming Soon', 'Di form produk, ubah status ke <strong>Coming Soon</strong>. Opsional: isi <strong>Jadwal Launch</strong> agar produk aktif otomatis pada tanggalnya.'],
      ['Pendaftar masuk', 'Pengunjung mendaftar lewat form di halaman produk — langsung dapat konfirmasi email + WA (template ' . $mono('waitlist_joined') . ').'],
      ['Launch', 'Klik tombol <strong>Launch</strong> di daftar produk, ATAU biarkan aktif otomatis saat jadwal tiba. Notifikasi launch terkirim bertahap &amp; anti dobel-kirim (tanpa perlu setup cron).'],
      ['Pantau konversi', 'Buka ' . $mono('/admin/waitlist') . ': daftar pendaftar per produk, statistik, export CSV. Pendaftar yang akhirnya membeli otomatis ditandai <strong>Jadi Pembeli</strong>.'],
  ])
  . $info('<strong>Tips:</strong> Buat <strong>Email Sequence</strong> dengan trigger <strong>Setelah Daftar Waitlist</strong> untuk nurture pendaftar sampai launch (butuh pendaftar punya akun / email cocok dengan user terdaftar).')
);

echo $section('booking-fee',
  $L('Booking Fee (DP)', 'Booking Fee (DP)', 'Booking Fee (Deposit)'),
  $L('Buyer bayar DP dulu untuk mengunci slot &amp; harga — pelunasan menyusul.', 'Pembeli bayar deposit dahulu untuk kunci slot &amp; harga.', 'Buyers pay a deposit first to lock the slot & price.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Cocok untuk produk mahal (mis. Rp 3.500.000): buyer bisa bayar <strong>booking fee</strong> dulu (mis. Rp 500.000) untuk mengunci slot &amp; harga, lalu melunasi sisanya sebelum batas waktu. <strong>Akses konten baru terbuka setelah lunas.</strong></p>'
  . $steps([
      ['Aktifkan per produk', 'Di form produk aktifkan <strong>Booking Fee</strong>, isi nominal DP dan <strong>batas pelunasan</strong> (default 7 hari).'],
      ['Buyer memilih', 'Checkout menampilkan pilihan <strong>Bayar Penuh</strong> vs <strong>Booking Fee</strong>.'],
      ['DP dibayar', 'Slot &amp; harga TERKUNCI. Order pelunasan terbit otomatis + email/WA berisi link bayar &amp; batas waktu (template ' . $mono('booking_confirmed') . ').'],
      ['Reminder pelunasan', 'Otomatis terkirim <strong>H-2</strong> &amp; <strong>H-1</strong> sebelum batas waktu (template ' . $mono('settlement_reminder') . ') — anti dobel-kirim, tanpa setup cron.'],
      ['Lunas / hangus', 'Pelunasan dibayar → akses terbuka otomatis. Lewat batas waktu → booking <strong>hangus otomatis</strong> + notifikasi (template ' . $mono('booking_expired') . '); DP tidak dikembalikan otomatis.'],
  ])
  . $h3('Yang perlu diketahui')
  . $ul([
      'Harga terkunci saat booking — kenaikan harga produk tidak memengaruhi sisa pelunasan.',
      'Komisi affiliate tercatat dua tahap: atas DP saat booking, atas sisa saat pelunasan (total = rate &times; harga penuh).',
      'Konfirmasi pembayaran manual oleh admin pada order booking JUGA tidak membuka akses — akses tetap menunggu pelunasan.',
      'Email Sequence dengan trigger <strong>Setelah Booking Fee (DP) Dibayar</strong> bisa dipakai untuk follow-up menuju pelunasan.',
  ])
  . $warn('<strong>Perhatian:</strong> Booking yang hangus TIDAK dibuat ulang otomatis. Buyer yang masih berminat harus memesan lagi dari halaman produk.')
);

echo $section('bayar-fleksibel',
  $L('Bayar Fleksibel &amp; Halaman Dukung', 'Bayar Fleksibel &amp; Halaman Sokongan', 'Flexible Pricing &amp; Support Page'),
  $L('Produk "bayar seikhlasnya" (pembeli tentukan nominal) + halaman /dukung dengan Supporters Wall.', 'Produk "bayar seikhlasnya" + halaman /dukung dengan Supporters Wall.', 'Pay-what-you-want products + a /dukung support page with a Supporters Wall.'),
  $h3('Apa ini')
  . '<p class="mb-4">Fitur ini punya dua lapis. <strong>Bayar Fleksibel</strong> membuat produk yang nominalnya diisi sendiri oleh pembeli — cocok untuk donasi, "traktir kopi", atau bayar seikhlasnya. <strong>Halaman Dukung</strong> (' . $mono('/dukung') . ') adalah satu halaman publik turnkey milikmu yang memajang produk-produk fleksibel itu sebagai kartu tip, lengkap dengan <strong>Supporters Wall</strong> (dinding pendukung).</p>'
  . $info('<strong>Penting:</strong> Produk fleksibel tetap bisa punya konten (kelas, e-book, download, membership). Akses &amp; materi tetap terkirim otomatis setelah bayar — persis produk biasa. Yang berbeda hanya cara nominal ditentukan di checkout.')

  . $h3('1. Bikin produk Bayar Fleksibel')
  . $steps([
      ['Tambah / edit produk', 'Buka ' . $mono('/admin/products') . ' → buka tab <strong>Harga &amp; Akses</strong>.'],
      ['Pilih Tipe Harga', 'Ubah <strong>Tipe Harga</strong> menjadi <strong>Bayar Fleksibel</strong>. Muncul kolom-kolom khusus di bawahnya.'],
      ['Label ke Pembeli', 'Teks bebas yang dilihat pembeli. Kosong = "Bayar Seikhlasnya".'],
      ['Minimum (' . e(currencySymbol()) . ')', 'Nominal minimal yang harus dibayar. 0 = tanpa minimal (nominal efektif tetap di atas 0).'],
      ['Preset Nominal', 'Tombol nominal cepat, mis. ' . $mono('10000, 25000, 50000') . '. Kosong = pembeli isi bebas.'],
      ['Simpan', 'Produk kini punya link checkout sendiri — bisa langsung dibagikan sebagai link donasi/tip.'],
  ])
  . $badges(['Traktir Kopi', 'Bayar Seikhlasnya', 'Dukung Saya', 'Support Konten Ini'])
  . $info('<strong>Otomatis nonaktif untuk produk fleksibel:</strong> kupon, order bump, setup fee, dan booking DP. Ini disengaja agar alur "bayar seikhlasnya" tetap bersih.')

  . $h3('2. Aktifkan Halaman Dukung (/dukung)')
  . $steps([
      ['Buka Halaman Dukung', 'Akses ' . $mono('/admin/support-page') . ' (menu <strong>Halaman Dukung</strong> di sidebar, di bawah Social Proof).'],
      ['Nyalakan halaman', 'Aktifkan <strong>Aktifkan Halaman Dukung</strong> — halaman jadi bisa diakses publik.'],
      ['Atur slug &amp; profil', 'Tentukan slug (default ' . $mono('dukung') . ' → ' . $mono('/dukung') . '), isi <strong>Bio</strong> dan <strong>tautan sosial</strong> (opsional).'],
      ['Tampilkan nominal (opsional)', 'Toggle <strong>Tampilkan nominal di Supporters Wall</strong> — matikan untuk menyembunyikan rupiah tiap pendukung.'],
      ['Pilih produk yang tampil', 'Balik ke ' . $mono('/admin/products') . ', buka produk fleksibel → centang <strong>Tampilkan di Halaman Dukung</strong>. Produk itu jadi kartu tip di ' . $mono('/dukung') . '.'],
  ])
  . $info('<strong>Info:</strong> Halaman Dukung adalah SATU halaman per situs (milik kamu sebagai kreator) — bukan halaman per-produk. Semua produk fleksibel yang dicentang tampil sebagai kartu di sana.')

  . $h3('3. Saat ada yang mendukung')
  . '<p class="mb-4">Di checkout, pembeli memilih nominal, boleh menulis <strong>pesan dukungan</strong>, dan boleh mencentang <strong>tampil anonim</strong>. Setelah pembayaran <strong>lunas</strong>, mereka muncul di <strong>Supporters Wall</strong> — nama ditampilkan sebagai "Budi S." (nama depan + inisial), atau <strong>"Anonim"</strong> bila memilih.</p>'
  . $steps([
      ['Moderasi pesan', 'Ada pesan spam / tidak pantas? Buka ' . $mono('/admin/support-page') . ' → panel <strong>Moderasi pesan</strong> → <strong>Sembunyikan</strong>.'],
      ['Tampilkan lagi', 'Pesan yang disembunyikan bisa ditampilkan kembali kapan saja (reversibel).'],
  ])

  . $h3('Yang perlu diketahui')
  . $ul([
      'Produk fleksibel = <strong>sekali bayar</strong> (bukan langganan).',
      'Komisi affiliate <strong>tidak berlaku</strong> untuk produk fleksibel.',
      'Nominal yang dibayar tampil rapi di invoice, halaman terima kasih, dan notifikasi email/WA.',
      'Supporters Wall hanya menampilkan order berstatus <strong>lunas</strong> dari produk yang dicentang "Tampilkan di Halaman Dukung".',
      'Nama pendukung selalu disamarkan (nama depan + inisial) — nama penuh tidak pernah ditampilkan publik.',
  ])
  . $info('<strong>Tips:</strong> Untuk tip jar murni (tanpa konten), cukup buat produk fleksibel tanpa materi. Untuk pay-what-you-want berkonten, set produk kelas/e-book jadi fleksibel — akses tetap terkirim otomatis.')
);

echo $section('dime-sale',
  $L('Dime Sale', 'Dime Sale', 'Dime Sale'),
  $L('Harga naik otomatis seiring penjualan — pembeli awal dapat harga termurah.', 'Harga naik automatik mengikut jualan.', 'Price rises automatically as sales come in.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Tiap kelipatan sekian penjualan <strong>lunas</strong>, harga produk bertambah otomatis. Contoh: harga mulai Rp 100.000, naik Rp 10.000 tiap 10 penjualan → pembeli ke-1 s.d. 10 bayar Rp 100.000, pembeli ke-11 s.d. 20 bayar Rp 110.000, dst. Menciptakan urgensi: makin cepat beli, makin murah.</p>'
  . $steps([
      ['Aktifkan di produk', 'Buka ' . $mono('/admin/products') . ' → edit produk → tab <strong>Harga &amp; Akses</strong> → centang <strong>Aktifkan Dime Sale</strong>.'],
      ['Atur tangga harga', 'Isi <strong>Naik Tiap (penjualan)</strong> dan <strong>Kenaikan Harga (' . e(currencySymbol()) . ')</strong>. Harga awal = kolom Harga (' . e(currencySymbol()) . ') di atasnya.'],
      ['Harga maksimum (opsional)', 'Isi batas atas agar harga berhenti naik di angka tertentu. Kosong = tanpa batas.'],
      ['Pantau', 'Tabel produk menampilkan harga efektif saat ini + "Dime · N lagi naik" — tanpa perlu halaman lain.'],
  ])
  . $h3('Yang perlu diketahui')
  . $ul([
      'Halaman produk otomatis menampilkan urgensi: <em>"Harga naik Rp X setelah N pembelian lagi"</em>.',
      '<strong>Harga dikunci saat checkout dibuka</strong> — pembeli membayar persis harga yang dia lihat, walau ada penjualan lain saat dia mengisi form.',
      'Hanya penjualan <strong>lunas</strong> yang dihitung; perpanjangan/beli-ulang tidak dihitung, dan harga <strong>tidak pernah turun</strong> (refund tak mengurangi hitungan).',
      'Tidak bisa digabung dengan Bayar Fleksibel atau Booking Fee. Kupon &amp; order bump tetap bisa jalan.',
      'Harga coret (harga asli) disembunyikan selama Dime Sale aktif.',
  ])
  . $info('<strong>Tips:</strong> Umumkan tangga harganya di sales page ("naik tiap 10 pembeli!") — urgensi bekerja paling kuat saat calon pembeli tahu aturannya.')
);

$myBodies['harga-ringgit'] =
  $h3('Tukar mata wang seluruh aplikasi')
  . $steps([
      ['Tetapkan di Settings', 'Buka ' . $mono('/admin/settings') . ' → bahagian <strong>Pembayaran</strong> → isi <strong>Kod Mata Wang</strong> (cth. MYR) &amp; <strong>Simbol Mata Wang</strong> (cth. RM), kemudian simpan.'],
      ['Seluruh app ikut', 'Label harga, dashboard, invois, checkout &amp; emel automatik guna simbol tersebut.'],
      ['Masukkan harga dalam mata wang itu', 'Cth. taip 197 untuk ' . e(currencySymbol()) . ' 197. Nombor <strong>tidak</strong> ditukar automatik.'],
  ])
  . $warn('<strong>Penting:</strong> Ini menukar <strong>simbol &amp; format nombor</strong>, <strong>bukan tukaran kadar</strong>. Angka lama tidak dikonversi. Gateway automatik (cth. Duitku) masih rel Indonesia — untuk mata wang lain guna pembayaran manual.')
  . $h3('Harga Ringgit pendamping (khusus asas Rupiah)')
  . '<p class="mb-3">Jika anda tetap berjualan dalam Rupiah tetapi mahu papar anggaran RM di sebelahnya, isi <strong>Harga Alias Ringgit (RM)</strong> pada produk (tab Harga &amp; Akses). RM muncul sebagai badge kecil di bawah harga Rupiah.</p>'
  . $info('<strong>Nota:</strong> Badge RM pendamping ini <strong>automatik dimatikan</strong> apabila Kod Mata Wang bukan IDR (kerana app sudah guna mata wang global — elak dua mata wang).');

echo $section('harga-ringgit',
  $L('Mata Uang &amp; Harga Ringgit', 'Mata Wang &amp; Harga Ringgit', 'Currency &amp; Ringgit Price'),
  $L('Ganti mata uang seluruh aplikasi, atau tampilkan harga RM pendamping untuk pembeli Malaysia.', 'Tukar mata wang seluruh aplikasi, atau papar harga RM pendamping.', 'Switch the whole-app currency, or show a companion RM price.'),
  $h3('Ganti mata uang seluruh aplikasi')
  . $steps([
      ['Atur di Settings', 'Buka ' . $mono('/admin/settings') . ' → bagian <strong>Pembayaran</strong> → isi <strong>Kode Mata Uang</strong> (mis. MYR) &amp; <strong>Simbol Mata Uang</strong> (mis. RM), lalu simpan.'],
      ['Seluruh aplikasi ikut', 'Label harga, dashboard, invoice, checkout &amp; email otomatis memakai simbol itu.'],
      ['Masukkan harga dalam mata uang tsb.', 'Mis. ketik 197 untuk ' . e(currencySymbol()) . ' 197. Angka <strong>tidak</strong> dikonversi otomatis.'],
  ])
  . $warn('<strong>Penting:</strong> Ini menukar <strong>simbol &amp; format angka</strong>, <strong>bukan konversi kurs</strong>. Angka lama tidak dikonversi — masukkan harga dalam nilai mata uang aktif. Gateway otomatis (mis. Duitku) tetap rail Indonesia — untuk mata uang lain gunakan pembayaran manual.')
  . $h3('Harga Ringgit pendamping (khusus basis Rupiah)')
  . '<p class="mb-3">Kalau kamu tetap berjualan dalam Rupiah tapi ingin menampilkan perkiraan RM di sampingnya, isi <strong>Harga Alias Ringgit (RM)</strong> di produk (tab Harga &amp; Akses). RM muncul sebagai badge kecil di bawah harga Rupiah — di katalog, halaman produk, dan checkout.</p>'
  . $info('<strong>Catatan:</strong> Badge RM pendamping ini <strong>otomatis nonaktif</strong> saat Kode Mata Uang bukan IDR (karena aplikasi sudah memakai mata uang global — hindari dobel mata uang).')
);

// ═══════════════════════ MEMBER & KONTEN ═══════════════════════

echo $section('katalog-member',
  $L('Katalog Member', 'Katalog Ahli', 'Member Catalog'),
  $L('Etalase produk di dalam dashboard member — tempat member membeli produk lain.', 'Etalase produk dalam dashboard ahli.', 'In-dashboard product storefront for members.'),
  $h3('Apa itu')
  . '<p class="mb-4">Member yang login punya halaman <strong>Katalog</strong> (' . $mono('/katalog') . ') di dashboard-nya: etalase semua produk <strong>aktif</strong> yang tidak disembunyikan. Dari sini member bisa membeli produk lain (cross-sell) tanpa keluar dari area member.</p>'
  . $h3('Yang tampil di tiap kartu')
  . $ul([
      'Thumbnail, nama, harga (dengan harga coret bila ada diskon), serta badge <strong>Sudah Dimiliki</strong> untuk produk yang sudah dibeli.',
      'Bila <strong>Poin Reward</strong> aktif &amp; member punya saldo, muncul estimasi <strong>hemat pakai poin</strong> — angkanya sama persis dengan yang dipotong saat checkout.',
      'Tombol utama: <strong>Beli Sekarang</strong> (belum dimiliki) atau <strong>Buka Konten</strong> (sudah dimiliki).',
      'Tombol <strong>Lihat Salespage</strong> — membuka halaman jualan produk di tab baru, otomatis mengikuti jenis landing produk: <strong>Canvas</strong> (' . $mono('/{slug-canvas}/') . '), <strong>URL eksternal</strong>, atau halaman produk bawaan (' . $mono('/products/{slug}') . ').',
  ])
  . $info('Agar sebuah produk tidak muncul di katalog member, aktifkan opsi <strong>sembunyikan dari katalog</strong> pada form produk.')
);

echo $section('materi-kelas',
  $L('Modul &amp; Materi', 'Modul &amp; Bahan', 'Modules &amp; Materials'),
  $L('Kelola konten kelas online untuk produk tipe Class — kelompokkan pelajaran ke dalam Modul, isi tiap Materi dengan video, file, link, atau teks.', 'Urus kandungan kelas dalam talian.', 'Manage online class content.'),

  $h3('Konsep dasar')
  . '<p class="mb-4">Produk bertipe <strong>Class</strong> memuat <strong>Materi</strong> (satu pelajaran = satu video/file/link/teks). Beberapa materi dikelompokkan ke dalam <strong>Modul</strong> (mis. &ldquo;Bab 1: Dasar&rdquo;, &ldquo;Bab 2: Praktik&rdquo;) agar rapi dan mudah dinavigasi pembeli. Modul juga jadi unit untuk fitur <strong>Drip Content</strong> (buka bertahap).</p>'

  . $h3('Langkah membuat kelas')
  . $steps([
      ['1 · Buat Modul (opsional tapi disarankan)', 'Buka ' . $mono('/admin/class-categories') . ' untuk membuat pengelompokan/modul. Tanpa modul, semua materi tampil dalam satu daftar rata.'],
      ['2 · Tambah materi', 'Buka ' . $mono('/admin/content') . ' → pilih produk <strong>Class</strong> → <strong>Tambah Materi</strong>. Isi judul, pilih modul, dan tipe kontennya.'],
      ['3 · Pilih tipe materi', 'Tersedia 4 tipe: <strong>Video</strong> (URL YouTube/Vimeo, atau file video langsung MP4/WebM), <strong>File</strong> (unduhan terproteksi), <strong>Link</strong> (tautan eksternal), atau <strong>Teks</strong> (editor kaya).'],
      ['4 · Lengkapi deskripsi &amp; lampiran', 'Di bawah video/embed kamu bisa menambahkan <strong>Deskripsi kaya</strong> (teks + link, mis. materi pendukung) dan <strong>lampiran file</strong> lebih dari satu — semuanya tampil di bawah pemutar untuk pembeli.'],
      ['5 · Atur urutan', 'Susun urutan modul &amp; materi dengan <strong>drag &amp; drop</strong>. Urutan ini persis yang dilihat pembeli.'],
  ])

  . $h3('Tipe materi &amp; kapan dipakai')
  . $ul([
      '<strong>Video</strong> — tempel URL YouTube/Vimeo (atau file MP4/WebM); sistem meng-embed pemutar otomatis dengan antarmuka YouTube disembunyikan. Cocok untuk pelajaran utama.',
      '<strong>File</strong> — unggah PDF/zip/dll. File tersimpan <em>terproteksi</em> (hanya pembeli yang bisa mengunduh). Lihat juga ' . $mono('Download Manager') . '.',
      '<strong>Link</strong> — arahkan ke sumber eksternal (Google Drive, Notion, dll). Otomatis dibuka di tab baru.',
      '<strong>Teks</strong> — tulis langsung dengan editor kaya (heading, list, gambar, link) untuk catatan atau transkrip.',
  ])

  . $h3('Pengalaman belajar pembeli (otomatis)')
  . $ul([
      'Video <strong>otomatis ditandai selesai</strong> saat ditonton tuntas, dan <strong>posisi tontonan tersimpan</strong> — pembeli melanjutkan dari menit terakhir.',
      'Tombol <strong>&ldquo;Lanjutkan Belajar&rdquo;</strong> membawa pembeli langsung ke materi berikutnya yang belum selesai.',
      '<strong>Bar progres</strong> per produk menunjukkan persentase penyelesaian; navigasi <strong>prev/next</strong> otomatis tersedia.',
      'Bila <strong>Sertifikat</strong> aktif, sertifikat <strong>terbit otomatis</strong> begitu semua materi selesai (lihat bagian ' . $mono('Sertifikat') . ').',
  ])

  . $info('<strong>Cek dulu sebelum publish:</strong> klik <strong>&ldquo;Lihat sebagai Pembeli&rdquo;</strong> di halaman produk untuk melihat persis tampilan kelas dari sisi pembeli (semua modul terbuka dalam mode preview).')
  . $info('<strong>Akses pembeli:</strong> materi terbuka di ' . $mono('/member') . ' setelah pembayaran <strong>terkonfirmasi</strong>. Untuk membuka modul secara bertahap, gunakan ' . $mono('Drip Content') . ' di bawah.')
);

echo $section('akses-embed',
  $L('Mode Akses: Embed Langsung', 'Mod Akses: Embed Terus', 'Access Mode: Direct Embed'),
  $L('Kirim produk atau lead magnet yang isinya satu tool/aplikasi (embed HTML atau URL) tanpa harus dibungkus kelas/modul.', 'Hantar produk berupa satu tool embed tanpa kelas.', 'Deliver a product whose content is a single embedded tool.'),

  $h3('Kapan dipakai')
  . '<p class="mb-4">Untuk produk yang <strong>bukan kelas</strong> — mis. aplikasi/tool web, dashboard, generator, atau halaman interaktif. Pembeli yang punya akses <strong>langsung mendarat di tool-nya</strong>, tanpa perlu membuat kelas/modul/materi.</p>'

  . $h3('Cara mengaktifkan')
  . $steps([
      ['1 · Buka editor', 'Produk: ' . $mono('/admin/products') . ' → edit produk → tab <strong>Lanjutan</strong> → bagian <strong>Mode Akses Konten</strong>. Lead magnet: ' . $mono('/admin/lead-magnets') . ' → edit → bagian <strong>Mode Akses Konten</strong>.'],
      ['2 · Pilih "Embed langsung"', 'Ganti Mode Akses dari <strong>Kelas / Modul</strong> ke <strong>Embed langsung</strong> — dua field akan muncul.'],
      ['3 · Isi konten', 'Tempel <strong>Kode Embed (HTML)</strong> (mis. ' . $mono('&lt;iframe&gt;&lt;/iframe&gt;') . ' atau HTML aplikasi lengkap), ATAU isi <strong>URL Tool</strong> (ditampilkan via iframe). Simpan.'],
      ['4 · Pratinjau (opsional)', 'Klik tombol <strong>&ldquo;Lihat sebagai pembeli&rdquo;</strong> di bawah field embed → membuka pratinjau layar penuh yang merender isi HTML/URL <strong>persis</strong> seperti yang dilihat pembeli. Bekerja langsung dari isian di form — <strong>tak perlu disimpan dulu</strong>. Tekan Esc / Tutup untuk keluar.'],
  ])

  . $h3('HTML vs URL')
  . $ul([
      '<strong>HTML diutamakan</strong> — bila kedua field diisi, yang dipakai HTML; URL diabaikan.',
      '<strong>URL</strong> dipakai hanya bila HTML kosong (tool eksternal ditampilkan dalam iframe).',
      'Konten dirender dalam <strong>iframe sandbox</strong>, bukan disisipkan ke dokumen halaman member. HTML yang ditempel tetap memiliki origin terpisah dari sesi member.',
      'Tautan di dalam konten yang memakai ' . $mono('target="_blank"') . ' dapat membuka situs tujuan di tab baru tanpa mewarisi batasan sandbox. Situs yang melarang iframe, seperti halaman tertentu di Google Drive, tetap harus dibuka sebagai tab baru dan bukan ditampilkan di dalam iframe.',
  ])

  . $h3('Tampilan untuk pembeli')
  . $ul([
      'Muncul di ' . $mono('/member') . ' sebagai kartu produk dengan tombol <strong>Buka Konten</strong> → langsung ke tool.',
      'Halaman akses tampil <strong>penuh (statis)</strong> — embed mengisi seluruh area layar tanpa scroll halaman yang mengganggu; scroll hanya terjadi di dalam konten bila memang panjang.',
      'Tanpa label &ldquo;modul&rdquo; &amp; tanpa bar progres (karena bukan kelas).',
      'Guard akses &amp; masa berlaku tetap berlaku sama seperti produk lain.',
  ])
  . $info('<strong>Produk kelas tidak berubah:</strong> mode default tetap <strong>Kelas / Modul</strong>. Mode embed hanya aktif bila kamu memilihnya secara sengaja.')
);

echo $section('drip-content',
  $L('Drip Content', 'Drip Content', 'Drip Content'),
  $L('Modul terbuka bertahap — mis. Modul 3 baru bisa dibuka 7 hari setelah member join.', 'Modul dibuka berperingkat mengikut hari keahlian.', 'Modules unlock gradually over membership days.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Tiap modul bisa diset <strong>terbuka setelah N hari</strong> dihitung sejak akses member aktif. Member melihat modul terkunci dengan ikon gembok + tanggal terbuka &amp; sisa hari — materi di dalamnya tidak bisa diakses (termasuk lewat URL langsung) sampai waktunya tiba.</p>'
  . $steps([
      ['Atur per modul', 'Buka ' . $mono('/admin/content') . ' → edit modul → isi field <strong>Drip Content — terbuka setelah (hari)</strong>. Nilai <strong>0</strong> = langsung terbuka (default semua modul lama).'],
      ['Contoh pola', 'Modul 1 = 0 (langsung), Modul 2 = 7 (minggu ke-2), Modul 3 = 14 (minggu ke-3), dst.'],
      ['Cek tampilan buyer', 'Klik <strong>Lihat sebagai Pembeli</strong> — mode preview membuka semua modul sambil menampilkan badge <strong>Drip hari ke-N</strong>.'],
  ])
  . $h3('Yang perlu diketahui')
  . $ul([
      'Timer dihitung per member per produk sejak aksesnya aktif — member lama &amp; baru punya jadwal masing-masing.',
      'Materi di modul terkunci tidak bisa ditandai selesai → sertifikat tidak mungkin terbit sebelum semua drip terbuka.',
      'Navigasi prev/next dan tombol &ldquo;Lanjut&rdquo; otomatis melewati modul yang masih terkunci.',
      'Modul lead magnet tidak terkena drip (selalu terbuka).',
  ])
  . $warn('<strong>Perhatian:</strong> Pembelian ulang / perpanjangan me-reset titik mulai akses — timer drip ikut dihitung ulang dari tanggal terbaru.')
);

echo $section('download-manager',
  $L('Download Manager', 'Pengurus Muat Turun', 'Download Manager'),
  $L('File terproteksi yang di-attach ke produk digital.', 'Fail terlindung yang dilampirkan ke produk.', 'Protected files attached to products.'),
  $steps([
      ['Buka Download Manager', 'Akses ' . $mono('/admin/downloads') . ' → <strong>Upload File</strong>.'],
      ['Pilih produk', 'Tentukan produk pemilik file.'],
      ['Isi detail', 'Judul, deskripsi, versi (opsional), dan urutan.'],
      ['Simpan', 'File hanya bisa diunduh buyer yang sudah membeli produk tersebut.'],
  ])
  . $info('<strong>Info:</strong> Setiap unduhan tercatat di <strong>Download Logs</strong> (siapa & kapan). Update versi cukup upload file baru.')
  . $warn('<strong>Perhatian:</strong> File disimpan terproteksi — tautan langsung tidak bisa diakses tanpa login & kepemilikan.')
  . $info('<strong>Batas ukuran upload</strong> mengikuti yang terkecil antara batas aplikasi (100MB) dan pengaturan PHP hosting (' . $mono('post_max_size') . ' / ' . $mono('upload_max_filesize') . ') — angkanya tampil di form upload. File yang melebihi batas ditolak dengan pesan jelas <em>sebelum</em> upload dimulai; naikkan batas PHP di panel hosting bila perlu file lebih besar.')
);

echo $section('sertifikat',
  $L('Sertifikat', 'Sijil', 'Certificates'),
  $L('Terbitkan sertifikat digital untuk penyelesaian produk/kelas.', 'Terbitkan sijil digital.', 'Issue digital certificates.'),
  $steps([
      ['Aktifkan per produk', 'Di form produk, aktifkan ' . $mono('certificate_enabled') . '.'],
      ['Atur tampilan', 'Buka ' . $mono('/admin/certificates') . ' → isi nama & jabatan penandatangan, teks footer, warna background & accent.'],
      ['Terbitkan', 'Klik <strong>Issue Sertifikat</strong> (pilih penerima + produk), atau biarkan terbit otomatis sesuai aturan produk.'],
      ['Verifikasi', 'Setiap sertifikat punya nomor & halaman verifikasi publik ' . $mono('/certificate/{uuid}') . '.'],
  ])
  . $info('<strong>Info:</strong> Sertifikat bisa <strong>dicabut</strong> (revoke) kapan saja — status berubah jadi "Dicabut" dan halaman verifikasi menandainya.')
);

echo $section('review',
  $L('Review Produk', 'Ulasan Produk', 'Product Reviews'),
  $L('Kumpulkan & moderasi ulasan bintang dari buyer.', 'Kumpul & moderasi ulasan pembeli.', 'Collect & moderate buyer reviews.'),
  $steps([
      ['Aktifkan global', 'Di ' . $mono('/admin/settings') . ' aktifkan ' . $mono('reviews_enabled') . '.'],
      ['Aktifkan per produk', 'Aktifkan review pada produk yang diinginkan.'],
      ['Moderasi', 'Buka ' . $mono('/admin/reviews') . ' → tab <strong>Menunggu</strong> → Setujui / Tolak / Hapus.'],
      ['Tampil', 'Review yang disetujui tampil di halaman produk.'],
  ])
  . $info('<strong>Info:</strong> Review baru berstatus <strong>Menunggu</strong> (pending) dan tidak tampil sebelum disetujui admin/staff.')
);

echo $section('langganan',
  $L('Langganan Member', 'Langganan Ahli', 'Member Subscriptions'),
  $L('Kelola akses berjangka member (langganan) + reminder perpanjangan otomatis.', 'Urus akses berjangka ahli.', 'Manage members\' time-limited access.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Produk dengan <strong>Durasi Akses</strong> (mis. 30 hari) otomatis jadi "langganan": akses habis pada tanggalnya, lalu member memperpanjang dengan membeli ulang. <strong>Averion mengingatkan otomatis</strong> sebelum habis (model manual-renewal — tanpa auto-charge).</p>'
  . $h3('Reminder otomatis')
  . $ul([
      'Reminder email &amp; WhatsApp dikirim otomatis <strong>H-3</strong> &amp; <strong>H-1</strong> sebelum akses habis, berisi link perpanjang.',
      'Berjalan lewat cron/Auto-Cron — tak perlu setup. Template bisa diedit di ' . $mono('/admin/notifications') . ' (event <strong>renewal_reminder</strong>).',
      'Matikan via setting ' . $mono('renewal_reminder_enabled') . ' bila tak diinginkan.',
  ])
  . $h3('Halaman Langganan')
  . $steps([
      ['Buka Langganan', 'Akses ' . $mono('/admin/subscriptions') . '.'],
      ['Pantau', 'Lihat semua akses berjangka + status (Aktif / Akan habis ≤7 hari / Expired). Filter &amp; cari member/produk.'],
      ['Perpanjang manual', 'Tombol <strong>Perpanjang</strong> menambah hari akses (mis. bonus/kompensasi) &amp; me-reset siklus reminder.'],
      ['Cabut', 'Tombol <strong>Cabut</strong> menghentikan akses member seketika.'],
  ])
  . $info('<strong>Tips:</strong> Kartu <strong>Akan habis</strong> di halaman ini = daftar prospek perpanjangan untuk follow-up manual.')
);

echo $section('info-member',
  $L('Info Member', 'Info Member', 'Member announcements'),
  $L('Pengumuman dari admin yang tampil di lonceng dashboard member — tanpa popup mengganggu.', 'Pengumuman admin pada loceng dashboard ahli.', 'Admin announcements in the member bell.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Info tampil di ikon <strong>lonceng</strong> kanan atas dashboard member, dengan penanda jumlah info baru. Member mengeklik lonceng → daftar info → klik untuk membaca detail (otomatis tertandai dibaca). Tidak ada popup paksa saat login.</p>'
  . $steps([
      ['Buat info', 'Buka ' . $mono('/admin/info-member') . ' → <strong>Buat Info</strong> → isi judul &amp; isi.'],
      ['Pilih target', '<strong>Semua user</strong>, <strong>User tertentu</strong> (ketik nama/email lalu pilih dari hasil pencarian — bisa lebih dari satu), atau <strong>Pembeli produk</strong> (semua pemilik akses produk yang dipilih).'],
      ['Tambah tombol (opsional)', 'Isi <strong>Tombol Aksi (CTA)</strong>: teks tombol + URL tujuan (mis. link grup WhatsApp, halaman promo). Tombol muncul di detail info &amp; membuka tautan di tab baru. Kosongkan bila tak perlu.'],
      ['Tayangkan', 'Simpan → info langsung tampil di lonceng member yang dituju.'],
      ['Kelola', 'Edit, <strong>Nonaktifkan</strong> (sembunyikan sementara), atau <strong>Hapus</strong>. Kolom <strong>Dibaca</strong> menunjukkan berapa member yang sudah membuka.'],
  ])
  . $ul([
      'Target "Pembeli produk" mengikuti kepemilikan akses — pembeli baru produk itu otomatis ikut melihat info tersebut.',
      'Info nonaktif hilang dari lonceng semua member, tapi datanya tetap ada dan bisa ditayangkan lagi.',
  ])
  . $info('<strong>Tips:</strong> Untuk pengumuman yang juga perlu sampai keluar aplikasi (email/WA), gabungkan dengan <strong>Broadcast</strong> — Info Member untuk yang login, Broadcast untuk menjangkau semua kontak.')
);

$myBodies['tiket-support'] =
  $h3('Untuk pembeli')
  . '<p class="mb-4">Menu <strong>Tiket Sokongan</strong> muncul di dashboard ahli (hanya untuk pembeli). Mereka boleh buka tiket (tajuk, mesej, lampiran JPG/PNG/PDF maks 2MB), berbalas mesej dalam satu perbualan, dan nampak penanda balasan baharu.</p>'
  . $h3('Untuk admin')
  . $steps([
      ['Buka kotak masuk', 'Akses ' . $mono('/admin/tickets') . ' — senarai tiket + tapis status (menunggu jawapan / dijawab / ditutup). Penanda bilangan tiket menunggu di sidebar.'],
      ['Balas', 'Buka tiket → tulis balasan (boleh lampiran) → hantar. Ada pilihan <strong>tutup selepas balas</strong>.'],
      ['Tutup / buka semula', 'Tandai selesai bila selesai; boleh dibuka semula bila ahli balas lagi.'],
  ])
  . $h3('Notifikasi (automatik)')
  . $ul([
      'Tiket / balasan baharu dari ahli → <strong>Telegram &amp; WhatsApp admin</strong>.',
      'Admin balas → <strong>emel &amp; WhatsApp ahli</strong>.',
      'Semua teks boleh diubah di ' . $mono('/admin/notifications') . '.',
  ])
  . $info('<strong>Nota:</strong> Khusus pembeli (ada akses produk / pernah order lunas). Status tiket: menunggu jawapan, dijawab, ditutup.');

echo $section('tiket-support',
  $L('Tiket Support', 'Tiket Sokongan', 'Support Tickets'),
  $L('Pusat bantuan pembeli↔admin — pembeli buka tiket dari dashboard, kamu balas dari admin.', 'Pusat bantuan ahli↔admin.', 'Buyer↔admin help desk.'),
  $h3('Untuk pembeli')
  . '<p class="mb-4">Menu <strong>Tiket Support</strong> muncul di dashboard member (hanya untuk pembeli). Mereka bisa membuka tiket (subjek, pesan, lampiran JPG/PNG/PDF maks 2MB), berbalas pesan dalam satu percakapan berulir, dan melihat penanda balasan baru.</p>'
  . $h3('Untuk admin')
  . $steps([
      ['Buka kotak masuk', 'Akses ' . $mono('/admin/tickets') . ' — daftar tiket + filter status (menunggu jawaban / dijawab / ditutup). Penanda jumlah tiket menunggu di sidebar.'],
      ['Balas', 'Buka tiket → tulis balasan (bisa lampiran) → kirim. Ada opsi <strong>tutup setelah balas</strong>.'],
      ['Tutup / buka lagi', 'Tandai selesai bila beres; bisa dibuka kembali saat member membalas lagi.'],
  ])
  . $h3('Notifikasi (otomatis)')
  . $ul([
      'Tiket / balasan baru dari member → <strong>Telegram &amp; WhatsApp admin</strong>.',
      'Admin membalas → <strong>email &amp; WhatsApp member</strong>.',
      'Semua teks bisa diubah di ' . $mono('/admin/notifications') . '.',
  ])
  . $info('<strong>Catatan:</strong> Khusus pembeli (punya akses produk / pernah order lunas). Status tiket: menunggu jawaban, dijawab, ditutup.')
);

// ═══════════════════════ AFFILIATE & KOMISI ═══════════════════════

echo $section('affiliate',
  $L('Sistem Affiliate', 'Sistem Affiliate', 'Affiliate System'),
  $L('Program referral untuk meningkatkan penjualan.', 'Program rujukan untuk meningkatkan jualan.', 'Referral program to boost sales.'),
  $h3('Cara Kerja')
  . '<p class="mb-5">User daftar → dapat link unik → membagikan link → mendapat komisi dari setiap transaksi yang masuk lewat link tersebut.</p>'
  . $ul([
      'User melihat dashboard affiliate dengan login → ' . $mono('/affiliate') . '.',
      'Link referral berbentuk ' . $mono('/r/{kode}') . ' dan landing ' . $mono('/go/{kode}/{slug}') . '.',
      'Admin/staff memantau komisi & pencairan di ' . $mono('/admin/affiliate-finance') . ' (tab Komisi & Pencairan).',
  ])
  . $h3('Siapa yang bisa jadi affiliate')
  . '<p class="mb-4">Atur di <strong>Pengaturan → Afiliasi → Siapa yang Bisa Jadi Affiliate</strong>: <strong>Semua member</strong> (bawaan — semua akun langsung dapat link) atau <strong>Hanya member yang sudah membeli produk</strong>. Pada mode kedua, member tanpa pembelian lunas melihat penjelasan syarat di halaman Afiliasi, dan link mereka belum menghasilkan komisi sampai pembelian pertamanya lunas.</p>'
  . $h3('Yang dilihat affiliate di /affiliate')
  . $ul([
      'Daftar <strong>Link Affiliasi per Produk</strong> — <strong>hanya produk yang komisinya di-set</strong> (kolom Komisi Afiliasi produk &gt; 0 <em>atau</em> ada Aturan Komisi khusus produk itu) yang ditampilkan; produk tanpa komisi tak ikut dipromosikan.',
      'Tiap produk menampilkan <strong>nilai komisi</strong> (Rupiah + persen) yang akan didapat affiliate itu — <strong>otomatis mengikuti level/role</strong> akunnya (Aturan Komisi per level menang atas kolom produk). Ada juga keterangan &ldquo;Komisi mengikuti level akunmu&rdquo;.',
      'Tab <strong>Riwayat Komisi, Daftar Referral,</strong> dan <strong>Leads dari Kamu</strong> (dengan tombol Chat WA untuk follow-up lead).',
  ])
  . $h3('Notifikasi ke affiliate')
  . '<p class="mb-3">Saat ada order via link affiliate yang <strong>lunas &amp; menghasilkan komisi</strong>, affiliate otomatis diberi tahu lewat event ' . $mono('affiliate_new_order') . ' (email/WA). Status pencairan &amp; persetujuan komisi juga bernotifikasi (' . $mono('commission_approved') . ', ' . $mono('payout_paid') . ', dst). Aktifkan template terkait di ' . $mono('/admin/notifications') . '.</p>'
  . $h3('Pengaturan Afiliasi')
  . '<p class="mb-3">Semua diatur di <strong>Pengaturan → Afiliasi</strong>:</p>'
  . $ul([
      '<strong>Komisi Default (%)</strong> — rate dasar untuk semua produk; bisa di-override per produk/level via <strong>Aturan Komisi</strong>. Isi <strong>0</strong> bila ingin <em>tanpa</em> komisi default — nilai 0% dihormati (tidak diam-diam memakai rate lama).',
      '<strong>Minimum Pencairan</strong> — jumlah terkecil yang boleh diajukan affiliate untuk payout.',
      '<strong>General Cookie</strong> — bila aktif, pembeli otomatis diatribusikan ke affiliate yang link/kodenya terakhir ia pakai untuk <em>semua</em> produk (atribusi ambient). Bila nonaktif, atribusi hanya saat link/kode dipakai eksplisit.',
      '<strong>Umur Cookie (Hari)</strong> — berapa lama atribusi ambient bertahan. Isi <strong>0</strong> = selamanya (lifetime).',
      '<strong>Kaitkan Afiliasi (Lock Customer)</strong> — bila aktif, pembeli yang sudah pernah teratribusi ke satu affiliate akan <strong>selalu</strong> dihitung ke affiliate itu untuk pembelian berikutnya; link/kupon afiliasi lain tak berpengaruh lagi.',
  ])
  . $h3('Visibilitas menu &ldquo;Link Afiliasi Saya&rdquo; (dashboard member)')
  . '<p class="mb-3">Secara bawaan, menu afiliasi di sidebar member <strong>hanya tampil</strong> untuk member yang relevan: yang sudah punya <strong>aktivitas afiliasi</strong> (pernah ada referral/komisi) <em>atau</em> yang <strong>berhak komisi</strong> lewat <strong>Aturan Komisi</strong> (dikunci ke level membership atau kepemilikan produk tertentu). Admin/staff selalu melihat menu ini.</p>'
  . $info('Ingin menu afiliasi tampil ke <strong>semua</strong> member? Aktifkan <strong>Pengaturan → Afiliasi → Tampilkan Menu Afiliasi ke Semua Member</strong>. Saat aktif, menu muncul untuk semua member yang memenuhi mode &ldquo;Siapa yang Bisa Jadi Affiliate&rdquo; di atas — termasuk yang belum punya transaksi/komisi.')
  . $info('<strong>Tips:</strong> Atur besaran komisi global di Settings, lalu override per produk/level di <strong>Aturan Komisi</strong>.')
);

echo $section('commission-rules',
  $L('Aturan Komisi', 'Peraturan Komisen', 'Commission Rules'),
  $L('Atur persentase komisi per produk dan per level membership.', 'Tetapkan komisen per produk & tahap.', 'Set commission per product & level.'),
  $steps([
      ['Komisi global', 'Atur persentase default di ' . $mono('/admin/settings') . ' → Afiliasi. Boleh <strong>0%</strong> (tanpa komisi default).'],
      ['Aturan spesifik', 'Buka ' . $mono('/admin/commission-rules') . ' → tambah aturan per produk / per level.'],
      ['Prioritas', 'Aturan spesifik menimpa komisi global untuk produk/level terkait.'],
  ])
  . $info('<strong>Tips:</strong> Beri komisi lebih besar untuk produk margin tinggi atau level membership atas.')
);

echo $section('membership-levels',
  $L('Level Membership', 'Tahap Keahlian', 'Membership Levels'),
  $L('Tingkatan affiliate dengan komisi & benefit berbeda.', 'Tahap affiliate dengan komisen berbeza.', 'Affiliate tiers with different commission.'),
  $badges(['Bronze', 'Silver', 'Gold', 'Platinum'])
  . $steps([
      ['Buka Membership Levels', 'Akses ' . $mono('/admin/membership-levels') . '.'],
      ['Atur level', 'Tambah/edit level, urutkan, dan tentukan komisi per level.'],
      ['Kaitkan komisi', 'Gunakan bersama <strong>Aturan Komisi</strong> untuk komisi per level.'],
  ])
  . $info('<strong>Tips:</strong> Level bisa diganti namanya & ditambah sesuai strategi — tidak terbatas pada 4 default.')
);

echo $section('payout',
  $L('Payout Affiliate', 'Pembayaran Affiliate', 'Affiliate Payout'),
  $L('Approve komisi dan proses pencairan ke affiliate.', 'Lulus komisen & proses pencairan.', 'Approve commission & process payouts.'),
  $h3('Alur Status Komisi')
  . $badges(['Pending', 'Approved (siap cair)', 'Paid'])
  . $steps([
      ['Approve komisi', 'Buka ' . $mono('/admin/affiliate-finance') . ' → tab <strong>Pencairan</strong> → centang komisi pending → <strong>Approve Terpilih</strong> (batch).'],
      ['Permintaan pencairan', 'Affiliate mengajukan pencairan; muncul di daftar Payout Request.'],
      ['Proses payout', 'Ubah status: Pending → <strong>Processing</strong> → <strong>Paid</strong> (atau Rejected).'],
  ])
  . $info('<strong>Aktivasi:</strong> Agar affiliate dapat notifikasi, aktifkan template ' . $mono('commission_approved') . ', ' . $mono('payout_processing') . ', ' . $mono('payout_paid') . ', ' . $mono('payout_rejected') . ' di ' . $mono('/admin/notifications') . '.')
  . $info('<strong>Tips:</strong> Set minimum payout di Settings agar pencairan efisien.')
);

echo $section('leaderboard',
  $L('Leaderboard', 'Papan Pendahulu', 'Leaderboard'),
  $L('Peringkat affiliate berdasarkan performa.', 'Kedudukan affiliate mengikut prestasi.', 'Affiliate ranking by performance.'),
  '<p class="mb-4">Buka ' . $mono('/admin/leaderboard') . ' untuk melihat peringkat affiliate berdasarkan jumlah penjualan/komisi. Berguna untuk kompetisi & gamifikasi tim affiliate.</p>'
  . $info('<strong>Tips:</strong> Umumkan leaderboard berkala untuk memacu semangat affiliate teratas.')
);

echo $section('partner-storefront',
  $L('Partner Storefront', 'Partner Storefront', 'Partner Storefront'),
  $L('Etalase produk komisi 100% per member — satu link unik untuk reseller/partner.', 'Etalase produk komisen 100% setiap ahli.', 'Per-member storefront of 100%-commission products.'),
  $h3('Apa itu')
  . '<p class="mb-4">Tiap member affiliate punya <strong>satu link storefront unik</strong>: ' . $mono('/partner/{kode}') . ' (atau slug cantik bila diisi). Halaman ini memajang <strong>produk Partner</strong> — yakni produk yang memberi <strong>komisi efektif 100%</strong> ke member tersebut. Cocok untuk model reseller: member cukup membagikan satu link, dan semua pembelian lewat link itu teratribusi &amp; berkomisi penuh ke dia.</p>'
  . $h3('Cara kerja')
  . $ul([
      'Produk yang tampil = produk <strong>aktif</strong> dengan <strong>komisi efektif 100%</strong> untuk member itu (dihitung dari aturan komisi/level/resell, bukan sekadar kolom komisi produk).',
      'Tiap kartu mengarah ke ' . $mono('/go/{kode}/{slug}') . ' — landing afiliasi yang <strong>mengunci atribusi</strong> ke member lalu meneruskan ke salespage/checkout produk (termasuk <strong>Canvas</strong> bila produk memakainya).',
      'Membuka ' . $mono('/partner/{kode}') . ' juga memasang cookie atribusi member untuk seluruh sesi kunjungan.',
  ])
  . $info('Member menemukan link storefront-nya di dashboard Afiliasi (' . $mono('/affiliate') . '). Program Afiliasi harus <strong>aktif</strong>; bila dimatikan, link storefront dialihkan ke katalog produk biasa.')
  . $warn('<strong>Beda dengan Display Produk Affiliate:</strong> Partner Storefront = etalase <em>pribadi</em> satu member berisi produk komisi 100%-nya. Display Affiliate = kartu rekomendasi produk di homepage publik.')
);

echo $section('jv-partner',
  $L('JV Partner (Bagi Hasil)', 'JV Partner (Kongsi Hasil)', 'JV Partner (Revenue Share)'),
  $L('Pembukuan bagi hasil dengan rekan joint-venture per produk — jatah tercatat otomatis dari tiap penjualan lunas.', 'Perakaunan kongsi hasil dengan rakan JV bagi setiap produk.', 'Bookkeeping for revenue-share with JV partners per product.'),
  $h3('Apa itu')
  . '<p class="mb-4">Kalau sebuah produk dibuat/dimodali bersama rekan, kamu bisa menetapkan <strong>satu partner + persen bagiannya</strong> pada produk itu. Setiap order produk tersebut yang <strong>lunas</strong> (termasuk DP &amp; pelunasan booking) otomatis mencatat jatah partner sebagai catatan pembukuan.</p>'
  . $steps([
      ['Set partner di produk', 'Buka edit produk → tab <strong>Harga &amp; Akses</strong> → pilih <strong>JV Partner</strong> (dari daftar user) + isi <strong>persen bagi hasil</strong>. Satu partner per produk.'],
      ['Jatah tercatat otomatis', 'Tiap penjualan lunas produk itu menambah "jatah partner" sesuai persen — tanpa input manual.'],
      ['Pantau &amp; tandai lunas', 'Buka ' . $mono('/admin/jv-partner') . ' → lihat total <strong>Belum Dibayar</strong> per partner → setelah kamu transfer bagiannya, klik <strong>Tandai Dibayar</strong>.'],
  ])
  . $info('Ini alat <strong>pencatatan (pembukuan)</strong>, bukan pembayaran otomatis. Transfer ke partner tetap manual, lalu tandai lunas untuk merapikan catatan.')
  . $warn('<strong>Beda dengan Komisi Affiliate:</strong> affiliate = orang yang <em>mereferralkan</em> pembeli; JV Partner = <em>rekan bisnis</em> yang bagi hasil atas produk tertentu, dihitung dari <strong>setiap</strong> penjualan produk itu (bukan hanya yang direferralkan).')
);

// ═══════════════════════ MARKETING & GROWTH ═══════════════════════

echo $section('email-sequence',
  $L('Email Sequence', 'Urutan Emel', 'Email Sequence'),
  $L('Email terjadwal otomatis (drip) berdasarkan trigger.', 'Emel berjadual automatik berdasarkan pencetus.', 'Automated drip emails by trigger.'),
  $h3('Trigger Tersedia')
  . $ul(['Setelah Pembelian Berhasil (' . $mono('order_paid') . ')', 'Setelah Akses Diaktifkan (' . $mono('member_access_activated') . ')', 'Setelah User Registrasi (' . $mono('user_register') . ')'])
  . $steps([
      ['Buat sequence', 'Buka ' . $mono('/admin/email-sequences') . ' → <strong>Buat Sequence</strong>, pilih trigger & produk (atau semua produk).'],
      ['Tambah step', 'Tambahkan email per langkah dengan delay: D+0, D+1, D+3, dst.'],
      ['Aktifkan', 'Set sequence & step ke status aktif.'],
  ])
  . $info('<strong>Otomasi (Auto-Cron):</strong> Pengiriman berjalan <strong>otomatis</strong> mengikuti kunjungan ke situs — tak perlu setup apa pun. Untuk keandalan ekstra saat traffic rendah, pasang cron manual ke ' . $mono('/cron/process') . ' (lihat panduan instalasi). Aman jalan berdampingan (tak dobel-kirim).')
  . $info('<strong>Prasyarat:</strong> Pengirim email (Mailketing) harus aktif di Integrasi.')
);

echo $section('abandoned-cart',
  $L('Abandoned Cart', 'Troli Terbiar', 'Abandoned Cart Recovery'),
  $L('Reminder otomatis untuk order pending yang belum dibayar.', 'Peringatan untuk order belum dibayar.', 'Reminders for unpaid pending orders.'),
  $steps([
      ['Buka Abandoned Cart', 'Akses ' . $mono('/admin/abandoned-carts') . '.'],
      ['Aktifkan & atur', 'Centang <strong>Aktifkan Recovery</strong>, set <strong>Delay (jam)</strong> dan <strong>Channel</strong> (Email / WhatsApp).'],
      ['Aktifkan template', 'Aktifkan template ' . $mono('abandoned_cart') . ' di ' . $mono('/admin/notifications') . '.'],
  ])
  . $info('<strong>Info:</strong> Reminder dikirim <strong>satu kali per order</strong>. Statistik recover ditampilkan di halaman ini.')
  . $info('<strong>Otomasi (Auto-Cron):</strong> Seperti Email Sequence — reminder diproses <strong>otomatis</strong> mengikuti traffic situs. Cron manual opsional untuk keandalan ekstra saat pengunjung sepi.')
);

echo $section('broadcast',
  $L('Broadcast', 'Siaran', 'Broadcast'),
  $L('Kirim pesan massal ke user via email atau WhatsApp.', 'Hantar mesej pukal kepada pengguna.', 'Send bulk messages to users.'),
  $steps([
      ['Buka Broadcast', 'Akses ' . $mono('/admin/broadcast') . '.'],
      ['Tentukan target', 'Pilih segmen penerima (mis. semua user / pembeli produk tertentu).'],
      ['Tulis pesan', 'Susun isi pesan, pilih channel (email / WhatsApp), lalu kirim.'],
  ])
  . $h3('Segmen penerima')
  . $ul([
      '<strong>Semua User / Member Aktif / Level tertentu</strong> — berdasarkan status akun.',
      '<strong>Pembeli Produk</strong> — hanya yang pernah membeli produk terpilih; bisa juga <strong>kecualikan pembeli</strong> produk tertentu. Pemilih produk kini punya <strong>pencarian, multi-kolom, tombol Semua/Kosongkan &amp; penghitung</strong>.',
      '<strong>Leads</strong> — semua leads atau per lead magnet.',
      '<strong>Pernah buka email</strong> / <strong>Pernah klik link email</strong> (khusus channel Email) — kirim hanya ke kontak responsif, bisa dibatasi <strong>30/60/90 hari</strong> terakhir. Bagus untuk menjaga reputasi pengirim.',
  ])
  . $warn('<strong>Catatan akurasi:</strong> angka &ldquo;buka&rdquo; bisa menggelembung karena proteksi privasi (mis. Apple Mail Privacy Protection yang auto-load gambar). Segmen <strong>&ldquo;pernah klik&rdquo;</strong> lebih akurat sebagai sinyal minat.')

  . $h3('Estimasi &amp; Draft')
  . $ul([
      'Tombol <strong>Cek estimasi penerima</strong> menghitung perkiraan jumlah penerima valid sebelum kirim (mengikuti segmen + pengecualian + lewati email gagal).',
      'Campaign berstatus <strong>Draft</strong> bisa <strong>diedit</strong> dan <strong>dipratinjau</strong> (ikon mata) sebelum dikirim. Campaign yang sudah/sedang terkirim terkunci (read-only).',
      'Setelah konfirmasi kirim, tombol terkunci selama antrean disiapkan. Halaman kemudian memperbarui status dan aksi campaign secara otomatis. Jika respons terputus, klik <strong>Periksa Status</strong> untuk melihat keadaan terbaru sebelum mencoba lagi.',
  ])

  . $warn('<strong>Perhatian:</strong> Hormati batas &amp; kebijakan provider. Kirim bertahap untuk menghindari pemblokiran nomor/akun.')
  . $info('<strong>Berhenti berlangganan:</strong> setiap broadcast email otomatis menyertakan tautan berhenti berlangganan di footer — lihat bagian ' . $mono('Berhenti Langganan Email') . '.')
);

echo $section('berhenti-langganan',
  $L('Berhenti Langganan Email', 'Berhenti Melanggan Emel', 'Email Unsubscribe'),
  $L('Pembeli bisa berhenti menerima email promosi; kamu juga bisa mengaturnya per-pengguna. Email penting (konfirmasi order, akses) tetap terkirim.', 'Pembeli boleh berhenti menerima emel promosi.', 'Buyers can opt out of promotional email.'),

  $h3('Cara kerja')
  . $ul([
      'Setiap <strong>broadcast email</strong> otomatis memuat tautan <strong>&ldquo;Berhenti berlangganan&rdquo;</strong> di footer.',
      'Pembeli klik tautan → halaman konfirmasi (tanpa perlu login) → memilih berhenti. Sejak itu ia <strong>tidak menerima broadcast email</strong> lagi.',
      'Hanya memengaruhi <strong>email promosi (broadcast)</strong>. Email transaksional (konfirmasi pesanan, akses produk) &amp; notifikasi <strong>WhatsApp</strong> tetap terkirim.',
  ])

  . $h3('Atur manual per-pengguna')
  . $steps([
      ['Buka daftar pengguna', 'Buka ' . $mono('/admin/users') . '.'],
      ['Toggle status promosi', 'Pada baris pengguna, klik ikon lonceng: <strong>lonceng dicoret (oranye)</strong> = sedang berhenti berlangganan (klik untuk mengaktifkan lagi); lonceng biasa = masih menerima (klik untuk menghentikan).'],
  ])
  . $info('<strong>Etika &amp; reputasi:</strong> menghormati permintaan berhenti berlangganan menjaga reputasi pengirim dan menurunkan risiko email masuk folder spam.')
);

echo $section('lead-magnet',
  $L('Lead Magnet', 'Lead Magnet', 'Lead Magnet'),
  $L('Tangkap calon pembeli (lead) dengan penawaran gratis.', 'Tangkap bakal pembeli dengan tawaran percuma.', 'Capture leads with a free offer.'),
  $steps([
      ['Buat magnet', 'Buka ' . $mono('/admin/lead-magnets') . ' (tab <strong>Magnets</strong>) → klik <strong>Tambah Lead Magnet</strong>. Isi judul, gambar, headline/subheadline, badge, teks tombol (CTA), dan (opsional) bonus level membership. Setiap magnet punya halaman opt-in sendiri di ' . $mono('/lp/{slug}') . '.'],
      ['Bagikan form', 'Sebarkan tautan ' . $mono('/lp/{slug}') . ' (di bio, iklan, atau tombol di halaman lain). Form menangkap: nama, WhatsApp, email.'],
      ['Kelola leads', 'Tab <strong>Leads</strong> → cari/filter per magnet, lihat funnel (Lead → User → Customer), Export CSV, lalu tindak lanjuti (Follow-up / Broadcast / Email Sequence).'],
      ['Pajang di homepage (opsional)', 'Di form magnet, centang <strong>Tampilkan di homepage</strong> — khusus magnet <strong>Published</strong>. Magnet muncul sebagai kartu di section <strong>Gratis</strong> homepage → ' . $mono('/lp/{slug}') . '. Nyala/mati &amp; judul section diatur di ' . $mono('/admin/branding') . ' → Konten Homepage.'],
  ])

  . $h3('Penawaran Tripwire (Produk Berbayar di Halaman Terima Kasih)')
  . '<p class="mb-3">Ubah lead magnet gratis jadi <strong>funnel tripwire</strong>: setelah pengunjung mendaftar, tampilkan satu produk berbayar sebagai penawaran di halaman terima kasih. Lead tetap tertangkap apa pun pilihannya.</p>'
  . $steps([
      ['Pilih produk tripwire', 'Di form magnet, isi <strong>Produk Tripwire</strong> (pilih 1 produk aktif) + <strong>Headline Penawaran</strong> (opsional, mis. "Diskon khusus hanya di halaman ini").'],
      ['Otomatis tampil', 'Di halaman terima kasih, gambar &amp; judul lead magnet disembunyikan agar fokus — muncul <strong>kartu penawaran</strong> (gambar, nama, harga produk) + tombol <strong>Ambil Penawaran</strong>, plus tautan halus "Nanti saja".'],
      ['Menuju produk', 'Tombol mengarah ke <strong>halaman produk</strong> (' . $mono('/products/{slug}') . '), bukan langsung checkout — pengunjung melihat detail dulu. <strong>Order Bump</strong> produk itu otomatis ikut tampil saat checkout.'],
  ])
  . $info('<strong>Tips:</strong> Order Bump penawaran tripwire diatur di <strong>form produk</strong>-nya (bukan di sini). Pilih produk yang punya gambar agar kartu penawaran lebih menarik.')

  . $h3('Verifikasi WhatsApp (OTP) — Opsional')
  . '<p class="mb-3">Untuk magnet <strong>ber-bonus atau ber-konten</strong>, pendaftar butuh akun agar bisa masuk dashboard. Secara default, pendaftar baru diminta <strong>verifikasi OTP WhatsApp</strong> dulu. Ini bisa dimatikan per magnet.</p>'
  . $ul([
      '<strong>OTP aktif (default):</strong> pendaftar memasukkan kode dari WhatsApp → akun dibuat &amp; nomor terverifikasi → masuk dashboard. Kualitas lead lebih tinggi.',
      '<strong>OTP nonaktif</strong> (hilangkan centang <strong>Wajibkan verifikasi WhatsApp (OTP)</strong>): pendaftar baru <strong>langsung masuk dashboard</strong> tanpa kode — akun dibuat otomatis (nomor belum terverifikasi). Lebih mulus, konversi lebih tinggi.',
  ])
  . $warn('<strong>Keamanan:</strong> saat OTP nonaktif, nomor/email yang <strong>sudah terdaftar</strong> tetap diarahkan untuk <strong>login</strong> (tidak otomatis masuk) demi mencegah pengambilalihan akun. Matikan OTP hanya bila kamu memprioritaskan kemulusan di atas verifikasi nomor.')

  . $info('<strong>Nurturing:</strong> sambungkan lead ke Email Sequence atau Broadcast untuk follow-up otomatis menuju pembelian.')
);

echo $section('display-affiliate',
  $L('Display Produk Affiliate', 'Display Produk Affiliate', 'Affiliate Displays'),
  $L('Pajang produk pihak lain (affiliate) sebagai kartu rekomendasi di homepage.', 'Pamerkan produk affiliate sebagai kad cadangan di laman utama.', 'Showcase affiliate products as recommendation cards on your homepage.'),
  $steps([
      ['Buka Display Affiliate', 'Akses ' . $mono('/admin/affiliate-displays') . ' — atau klik tombol <strong>Tambah Display</strong> di ' . $mono('/admin/products') . '.'],
      ['Tambah display', 'Isi <strong>nama produk</strong>, <strong>gambar</strong> (bisa dari Media Library), <strong>link sales page affiliate</strong> (wajib), dan <strong>link checkout affiliate</strong> (opsional). Tambahan opsional: teks harga, badge, deskripsi singkat.'],
      ['Atur tampil', 'Set status <strong>Aktif</strong> dan atur <strong>urutan</strong>. Kartu otomatis muncul di section <strong>Rekomendasi</strong> homepage.'],
      ['Atur section', 'Nyala/mati section, <strong>judul</strong>, dan <strong>label kartu</strong> (mis. "Afiliasi", bisa dikosongkan) diatur di ' . $mono('/admin/branding') . ' → Konten Homepage.'],
  ])
  . $info('<strong>Catatan:</strong> Display affiliate HANYA menampilkan kartu + tautan keluar ke situs produk orang lain — tidak ada checkout/akses di sistem ini. Tautan otomatis memakai <code>rel="sponsored nofollow"</code> (praktik jujur untuk SEO).')
  . $warn('Isi link dengan URL <strong>http/https</strong> yang valid. Link tidak valid (mis. <code>javascript:</code>) ditolak demi keamanan.')
);

echo $section('social-proof',
  $L('Social Proof', 'Social Proof', 'Social Proof'),
  $L('Popup notifikasi pembelian real-time untuk membangun kepercayaan.', 'Popup notifikasi pembelian masa nyata.', 'Real-time purchase popups.'),
  $steps([
      ['Buka Social Proof', 'Akses ' . $mono('/admin/social-proof') . '.'],
      ['Atur template', 'Contoh: ' . $mono('{buyer_name} dari {city} baru saja membeli {product_name}') . '.'],
      ['Pilih suara & tampilan', 'Pilih efek suara (Crystal Bell, Cash Register, dll) dan posisi popup.'],
      ['Pasang embed', 'Salin embed script (berisi <strong>Integration ID</strong>) ke halaman yang diinginkan.'],
      ['Atur per produk (opsional)', 'Di form produk, field <strong>Popup Social Proof</strong>: campur semua produk (default), <strong>hanya pembelian produk ini</strong>, atau <strong>sembunyikan</strong> di halaman/checkout produk tsb.'],
  ])
  . $info('<strong>Info:</strong> Jika embed bocor/disalahgunakan, klik <strong>Reset Integration ID</strong> lalu perbarui semua embed lama.')
  . $h3('Notifikasi Order Masuk (Admin)')
  . '<p class="mb-3">Di halaman ' . $mono('/admin/social-proof') . ' bagian <strong>Notifikasi Order Masuk</strong>, aktifkan agar kamu mendapat <strong>popup + suara</strong> setiap ada order baru. Berbeda dari popup Social Proof (untuk pengunjung), notifikasi ini khusus <strong>untuk kamu sebagai admin</strong>.</p>'
  . $ul([
      'Muncul di <strong>semua halaman admin</strong> (Dashboard, Transaksi, dll) — tak perlu membuka halaman tertentu.',
      'Popup <strong>menumpuk</strong> bila beberapa order masuk dan <strong>tetap tampil sampai kamu tutup</strong> (tombol ×) — tidak hilang sendiri.',
      'Pilih <strong>suara</strong> tersendiri (mis. Cash Register) lewat dropdown <strong>Suara Notifikasi Order</strong>.',
  ])
  . $warn('<strong>Soal suara:</strong> browser baru mengizinkan audio setelah ada interaksi (klik/ketik) di halaman — hal biasa saat kamu aktif bekerja. Bila tab dibiarkan tanpa disentuh, bunyi pertama bisa senyap meski popup tetap muncul.')
);

echo $section('canvas',
  $L('Canvas', 'Canvas', 'Canvas'),
  $L('Bangun landing/homepage tanpa ngoding (Visual Builder) atau pasang HTML kustommu.', 'Bina landing tanpa kod atau pasang HTML tersuai.', 'Build no-code landings or deploy custom HTML.'),
  $h3('Tipe Halaman')
  . $badges(['Homepage', 'Landing Produk', 'Mandiri', 'Rotator / Split Test', 'Redirect'])
  . '<p class="mb-4">Canvas punya <strong>tiga mode konten</strong>: <strong>Visual Builder</strong> (no-code — susun halaman dari blok siap pakai, mobile-first, <em>direkomendasikan</em>), <strong>Embed HTML</strong> (tempel HTML landing yang sudah kamu buat), dan <strong>Upload ZIP/HTML</strong> (unggah file). Dengan Visual Builder, Canvas membangun halamannya untukmu; dengan Embed/Upload, kamu membuat HTML-nya dulu di editor favoritmu.</p>'

  . $h3('Visual Builder (no-code) — bikin landing tanpa ngoding')
  . '<p class="mb-3">Mode <strong>Visual Builder</strong> membangun halaman <strong>untukmu</strong>: susun landing/sales page dari <strong>blok siap pakai</strong>, tanpa sentuh HTML. Hasilnya otomatis <strong>mobile-first</strong> (rapi di HP). Pilih ini untuk kebanyakan kebutuhan — kecuali kamu memang sudah punya HTML sendiri.</p>'
  . $steps([
      ['1. Buat Canvas → Mode = Visual Builder', 'Di ' . $mono('/admin/canvas') . ' → <strong>Buat Canvas</strong> → <strong>Mode Konten = Visual Builder (no-code)</strong>.'],
      ['2. Atur tema', 'Pilih <strong>warna brand</strong>, warna latar, warna teks, dan <strong>font</strong> di bar atas builder.'],
      ['3. Tambah blok dari palet', 'Klik blok yang diinginkan — tiap blok jadi kartu yang bisa kamu isi, <strong>urutkan</strong> (naik/turun), lipat, atau hapus.'],
      ['4. Arahkan tombol ke produk', 'Pada blok Hero / Harga / Ajakan, set <strong>Tujuan Tombol = Checkout produk</strong> lalu pilih produknya. Tombol otomatis mengarah ke checkout.'],
      ['5. Pratinjau (HP)', 'Klik <strong>Pratinjau (HP)</strong> untuk melihat tampilan ponsel sebelum publish.'],
      ['6. Publish', 'Toggle aktif → halaman tayang di ' . $mono('/{slug}') . '. Slug tak bisa diubah setelah publish.'],
  ])
  . '<p class="mb-2 font-semibold text-gray-700 dark:text-gray-300">Blok yang tersedia:</p>'
  . $badges(['Hero', 'Teks', 'Gambar', 'Video', 'Manfaat', 'Ajakan (CTA)', 'Harga', 'Testimoni', 'FAQ', 'Hitung Mundur', 'Form Lead'])
  . $info('<strong>Atribusi afiliasi otomatis:</strong> saat landing dibuka dengan <code class="text-xs">?ref=KODE</code>, kode afiliasi itu ikut terbawa ke tombol checkout tanpa setelan tambahan.')
  . $info('<strong>Blok Form Lead</strong> aktif bila tipe halaman di-set <strong>Landing Page Lead Magnet</strong> dan ditautkan ke sebuah lead magnet — pendaftar masuk ke sistem lead yang sama (bukan transaksi baru).')

  . $h3('Cara lanjutan: bawa HTML sendiri (Embed / Upload)')
  . $steps([
      ['1. Rancang HTML landing', 'Buat halaman di tools favoritmu, lalu ekspor menjadi <strong>satu file HTML</strong>.'],
      ['2. Jadikan self-contained', 'CSS taruh di dalam <code class="text-xs">&lt;style&gt;</code>; gambar di-<strong>embed</strong> (data URI) atau pakai URL <strong>absolut</strong> (https://...). Aset relatif (mis. <code class="text-xs">img/foto.jpg</code>) TIDAK akan tampil.'],
      ['3. Buka Canvas', 'Menu ' . $mono('/admin/canvas') . ' → buat baru.'],
      ['4. Pilih mode konten', '<strong>Embed HTML Langsung</strong> (tempel kode HTML) ATAU <strong>Upload ZIP / HTML</strong> (unggah file).'],
      ['5. Tipe = Landing Page Produk', 'Pilih tipe halaman <strong>Landing Page Produk</strong>, lalu <strong>tautkan ke produk</strong> (boleh lebih dari satu).'],
      ['6. Publish', 'Toggle aktif → halaman tayang di ' . $mono('/{slug}') . '. Slug tak bisa diubah setelah publish.'],
  ])
  . $h3('Tombol "Beli" (mode Embed / Upload)')
  . '<p class="mb-3">Pada mode <strong>Embed / Upload</strong>, Canvas tidak memasang tombol beli otomatis — di dalam HTML landing-mu, buat tombol/link yang mengarah ke link checkout produk (lihat topik <strong>Produk &amp; Checkout</strong>). <em>Di mode <strong>Visual Builder</strong>, ini otomatis lewat setelan <strong>Tujuan Tombol = Checkout produk</strong> pada blok.</em></p>'
  . $code('&lt;a href="https://domainmu.com/order/nama-produk"&gt;Beli Sekarang&lt;/a&gt;')
  . $info('Kunjungan landing Canvas otomatis tercatat di <strong>Funnel Analytics</strong> untuk setiap produk yang ditautkan.')
  . $warn('<strong>Aset:</strong> folder upload Canvas dikunci (deny-all) — WAJIB inline/absolut. <strong>Error 403 saat simpan HTML penuh?</strong> Sudah ditangani otomatis (dikirim base64). Bila mode Embed tetap gagal di hosting-mu, pakai mode <strong>Upload ZIP</strong>.')

  . $h3('Rotator / Split Test (uji A/B/n sales page)')
  . '<p class="mb-3">Tipe halaman <strong>Rotator / Split Test</strong> membuat <strong>SATU URL</strong> yang bergiliran menampilkan beberapa versi (angle) sales page berbeda — untuk menemukan angle mana yang paling banyak menghasilkan penjualan. Cocok saat kamu ragu headline/penawaran mana yang paling nge-<em>convert</em>.</p>'
  . $steps([
      ['1. Buat tiap angle sebagai Canvas biasa', 'Mis. <code class="text-xs">/promo-a</code> (angle urgensi), <code class="text-xs">/promo-b</code> (angle transformasi), <code class="text-xs">/promo-c</code> (angle bukti sosial). Tipe Mandiri / Landing Produk.'],
      ['2. Buat Canvas baru → Tipe = Rotator / Split Test', 'Beri satu slug utama, mis. <code class="text-xs">/promo</code>. Ini satu-satunya URL yang kamu sebar/iklankan.'],
      ['3. Tambah varian', 'Rujuk canvas angle yang tadi + atur <strong>bobot</strong> (pembagian traffic) &amp; label tiap varian.'],
      ['4. Publish &amp; sebar 1 URL', 'Cukup bagikan <code class="text-xs">/promo</code>. Alamat di browser pengunjung tetap <code class="text-xs">/promo</code> — mereka tak pernah lihat slug angle.'],
      ['5. Pantau di tombol "Hasil"', 'Buka panel <strong>Hasil Split Test</strong> untuk melihat Impresi, Klik Checkout, &amp; Conversion Rate per angle + penanda <strong>Unggulan</strong>.'],
  ])
  . $info('<strong>Sticky per pengunjung:</strong> begitu seseorang dapat angle B, ia akan <strong>selalu</strong> lihat angle B (konsisten, atribusi bersih). Untuk melihat angle lain saat menguji, buka di <strong>jendela Incognito/Samaran</strong> atau perangkat lain — tiap pengunjung baru diundi ulang sesuai bobot.')
  . $warn('Badge "Unggulan" baru muncul setelah minimal 20 impresi per angle, supaya keputusan tidak diambil dari data terlalu sedikit. Kunjungan langsung ke slug angle (mis. <code class="text-xs">/promo-a</code>) TIDAK dihitung dalam statistik split-test — hanya trafik lewat URL rotator.')

  . $h3('Redirect (short-link / cloaking link afiliasi)')
  . '<p class="mb-3">Tipe halaman <strong>Redirect</strong> mengubah slug Canvas menjadi <strong>short-link</strong>: pengunjung yang membuka <code class="text-xs">/{slug}</code> langsung diteruskan ke URL tujuan (redirect <strong>302</strong>). Cocok untuk <strong>meng-cloak link afiliasi</strong> yang panjang/jelek jadi rapi di domainmu sendiri, atau membuat link pendek yang bisa kamu ganti tujuannya kapan saja tanpa mengubah link yang sudah tersebar.</p>'
  . $steps([
      ['1. Buat Canvas baru → Tipe = Redirect', 'Pilih <strong>Redirect (teruskan ke URL lain)</strong> pada dropdown tipe halaman. Kolom konten HTML otomatis disembunyikan — tipe ini tidak butuh HTML.'],
      ['2. Isi URL tujuan', 'Tempel alamat lengkap tujuan, mis. <code class="text-xs">https://mitra.contoh.com/aff?id=123</code>. Wajib diawali <strong>http://</strong> atau <strong>https://</strong>.'],
      ['3. (Opsional) Teruskan query string', 'Centang <strong>Teruskan query string</strong> bila ingin parameter yang menempel di link pendekmu (mis. <code class="text-xs">?sub=abc</code>) ikut dibawa ke URL tujuan — berguna untuk sub-ID pelacakan afiliasi.'],
      ['4. Publish &amp; sebar slug', 'Toggle aktif → link pendek tayang di <code class="text-xs">/{slug}</code>. Sebar link ini di bio, iklan, atau WhatsApp.'],
  ])
  . $h3('Teruskan query string (passthrough sub-ID)')
  . '<p class="mb-3">Bila dicentang, parameter di link pendek digabung ke URL tujuan secara cerdas (menyesuaikan pemakaian <code class="text-xs">?</code> atau <code class="text-xs">&amp;</code>):</p>'
  . $code('/promo?sub=fb01 &nbsp;→&nbsp; https://mitra.contoh.com/aff?id=123&amp;sub=fb01')
  . $h3('Rekam klik sebelum meloncat (Tracking Pixel)')
  . '<p class="mb-3">Isi kolom <strong>Meta Pixel ID</strong> / <strong>TikTok Pixel ID</strong> (lihat bagian berikut) bila ingin merekam <strong>klik/kunjungan</strong> ke link afiliasimu. Bila pixel diisi, Canvas menampilkan halaman antara sekejap untuk menembakkan pixel, lalu otomatis meneruskan ke tujuan. Bila pixel dikosongkan, redirect terjadi <strong>instan</strong> (302 murni tanpa halaman antara).</p>'
  . $info('Bisa dipakai untuk membangun <strong>audiens retargeting</strong> dari orang yang mengklik penawaran afiliasi — walau penjualannya terjadi di situs mitra.')
  . $warn('Hanya URL <strong>http(s)</strong> yang diterima (skema seperti <code class="text-xs">javascript:</code>, <code class="text-xs">data:</code>, atau path relatif ditolak demi keamanan). Bila URL tujuan belum diisi, halaman menampilkan pesan "belum dikonfigurasi" — bukan meneruskan ke mana-mana.')

  . $h3('Tracking Pixel per-halaman (bisa &gt;1 ID)')
  . '<p class="mb-3">Tiap Canvas (termasuk Rotator &amp; Redirect) punya kolom <strong>Meta Pixel ID</strong> &amp; <strong>TikTok Pixel ID</strong> sendiri — terpisah dari pixel global. Boleh isi <strong>lebih dari satu ID sekaligus, pisahkan dengan koma</strong>:</p>'
  . $code('1234567890, 0987654321')
  . $ul([
      'Semua ID otomatis ter-<em>init</em> &amp; menembakkan <strong>PageView</strong> saat halaman dibuka.',
      'Pada <strong>Rotator</strong>: pixel yang diisi di rotator berlaku untuk <strong>semua</strong> varian (digabung dengan pixel khusus tiap angle) — praktis untuk pixel bersama.',
  ])

  . $h3('Pelacakan &amp; Pixel di Canvas — yang sering ditanya')
  . $ul([
      '<strong>PageView</strong> menembak di <strong>semua</strong> Canvas yang diisi Pixel ID (termasuk Rotator &amp; Redirect).',
      '<strong>ViewContent</strong> hanya menembak bila tipe halaman = <strong>Landing Produk</strong> DAN tertaut ke produk. Canvas <strong>Mandiri</strong> / Homepage non-produk → hanya PageView (wajar: ViewContent butuh ID produk).',
  ])
  . $warn('<strong>Tes sambil login admin hanya memunculkan PageView.</strong> Kunjungan admin/staff sengaja TIDAK menembakkan ViewContent &amp; funnel (supaya data tidak kotor oleh kunjunganmu sendiri). Untuk memverifikasi ViewContent, buka halaman di <strong>jendela Incognito/Samaran</strong> atau logout dulu.')
  . $info('<strong>Split Test tetap ter-track.</strong> Pixel yang diisi di halaman Rotator ikut jalan di <strong>semua</strong> varian (digabung dengan pixel tiap angle) — split test tidak membuat pelacakan hilang.')
  . $h3('Kenapa tracking belum muncul di Meta Ads?')
  . $steps([
      ['1. Ada delay', 'Laporan Ads Manager / Events Manager punya jeda beberapa jam (atribusi penuh bisa 1-3 hari). Untuk cek <strong>real-time</strong>, pakai tab <strong>Uji Peristiwa</strong> di Events Manager — event muncul seketika.'],
      ['2. Pixel ID beda / kosong', 'Pixel ID di Canvas harus <strong>sama</strong> dengan pixel yang dipakai akun iklan. Kalau beda atau kosong → Meta tidak mencatat. Paling aman: isi Pixel ID di halaman <strong>Rotator</strong>-nya (otomatis berlaku untuk semua varian).'],
      ['3. Salah baca metrik', '<strong>&ldquo;Tampilan Halaman Landing&rdquo;</strong> (butuh PageView pixel berhasil fire) berbeda dari <strong>&ldquo;Klik Tautan&rdquo;</strong>. Pastikan melihat kolom yang benar.'],
      ['4. Varian bukan Landing Produk', 'Kalau varian split test hanya tipe <strong>Mandiri</strong> → hanya PageView, tidak ada ViewContent.'],
  ])
  . $info('<strong>Verifikasi tercepat:</strong> Events Manager → pilih pixel → <strong>Uji Peristiwa</strong>, lalu buka URL-nya; atau pasang extension <strong>Meta Pixel Helper</strong> lalu buka halaman Canvas untuk melihat event yang menembak secara langsung.')

  . $h3('Salin URL cepat')
  . '<p class="mb-2">Di daftar Canvas, tiap baris punya tombol <strong>Salin URL</strong> (di samping "Buka") untuk menyalin alamat publik halaman ke clipboard — tinggal tempel ke iklan, bio, atau WhatsApp.</p>'
);

echo $section('galeri-media',
  $L('Galeri Media', 'Galeri Media', 'Media Library'),
  $L('Hosting gambar internal — upload sekali, dapat URL di domainmu sendiri.', 'Hosting imej dalaman.', 'Internal image hosting.'),
  '<p class="mb-4"><strong>Galeri Media</strong> adalah tempat menyimpan gambar di domainmu sendiri, tanpa layanan pihak ketiga (imgbb dsb). Upload sekali → dapat URL permanen yang bisa dipakai di mana saja (Canvas, deskripsi produk, email).</p>'
  . $steps([
      ['Buka Galeri Media', 'Menu ' . $mono('/admin/media') . '.'],
      ['Upload gambar', 'Seret / pilih file (JPG, PNG, WebP, GIF), atau salin gambar / screenshot lalu tekan <strong>Ctrl+V</strong> (Mac: <strong>Cmd+V</strong>) di halaman Galeri Media. Tidak perlu mengklik atau mengarahkan kursor ke area unggah. Jika sedang mengetik di kolom pencarian / alt, klik bagian kosong halaman dulu. Gambar langsung diunggah ke grup pilihan dan otomatis dikompres. Batas ukuran tetap mengikuti batas yang tampil di area unggah. Paste di kolom teks tetap normal. Menyalin URL gambar saja tidak mengunggah gambar.'],
      ['Ambil URL', 'Klik gambar → salin URL-nya untuk dipakai di HTML/landing/email.'],
  ])
  . $info('<strong>Terintegrasi ke Canvas:</strong> di editor Canvas (mode Embed), tombol <strong>Sisipkan Gambar</strong> membuka galeri ini — pilih gambar, URL-nya langsung disuntik ke posisi kursor.')
  . $warn('Demi keamanan, format <strong>SVG tidak diizinkan</strong> (bisa memuat skrip). Pakai PNG/JPG/WebP.')
);

echo $section('tracking-pixels',
  $L('Tracking Pixel', 'Piksel Penjejak', 'Tracking Pixels'),
  $L('Pasang pixel iklan & analitik untuk mengukur konversi.', 'Pasang piksel iklan & analitik.', 'Install ad & analytics pixels.'),
  $steps([
      ['Buka Tracking Pixels', 'Akses ' . $mono('/admin/tracking-pixels') . '.'],
      ['Tambah pixel', 'Masukkan ID pixel (mis. Meta/Facebook Pixel, Google Ads, TikTok, GA4, GTM).'],
      ['Atur event', 'Pixel otomatis ter-inject di halaman publik untuk melacak event seperti view & purchase.'],
  ])
  . $info('<strong>Tips:</strong> Padukan dengan <strong>Funnel Analytics</strong> untuk membandingkan data internal vs platform iklan.')
);

echo $section('funnel',
  $L('Funnel Analytics', 'Analitik Funnel', 'Funnel Analytics'),
  $L('Pantau corong konversi dari kunjungan sampai pembayaran — per produk maupun per bundle.', 'Pantau corong penukaran.', 'Track the conversion funnel.'),
  '<p class="mb-4">Buka ' . $mono('/admin/funnel-analytics') . ' untuk melihat tahapan <strong>Visitor Sales Page → Klik Checkout → Bayar → Ambil Order Bump</strong>, beserta rasio konversi tiap tahap, omzet, dan tren harian.</p>'
  . $h3('Cara memakai')
  . $steps([
      ['Pilih target', 'Dropdown <strong>Produk / Bundle</strong> — grup <em>Produk</em> menampilkan funnel 4 tahap; grup <em>Bundle</em> menampilkan funnel 3 tahap (Visitor → Klik → Bayar, tanpa order bump).'],
      ['Pilih periode', 'Default <strong>7 Hari</strong>. Tersedia 7/30/90 hari, 1 tahun, atau Semua Waktu — tab periode yang aktif ditandai warna brand.'],
      ['Baca corong', 'Angka merah = pengunjung yang berhenti di tahap itu. Kartu bawah merangkum konversi keseluruhan Visitor → Bayar beserta omzetnya.'],
      ['Set produk default (opsional)', 'Klik tombol bintang <strong>Jadikan default funnel</strong> di bawah dropdown → produk/bundle itu muncul <strong>paling atas</strong> &amp; <strong>terbuka otomatis</strong> tiap halaman dibuka (mengganti perilaku lama yang selalu memilih produk terbaru). Klik lagi untuk melepas; jika produk default dihapus/nonaktif, otomatis kembali ke produk terbaru.'],
  ])
  . $info('<strong>Bundle:</strong> statistik Visitor &amp; Klik Checkout untuk bundle mulai terisi <strong>sejak fitur ini terpasang</strong> (kunjungan halaman bundle &amp; klik tombol bayar dilacak sejak update). Data <em>Bayar</em> tetap mencakup seluruh transaksi bundle yang lunas.')
  . $warn('<strong>Konversi bisa &gt;100%</strong> pada periode panjang / Semua Waktu — karena jumlah <em>Bayar</em> mencakup transaksi lama, sedangkan <em>Visitor</em> baru dihitung sejak Funnel Analytics mulai mencatat kunjungan. Pilih periode lebih pendek untuk perbandingan yang setara; halaman otomatis menandai anomali ini.')
  . $h3('Apa yang dihitung tiap tahap')
  . $ul([
      '<strong>Klik Checkout</strong> dihitung dari kunjungan ke halaman checkout — mencakup checkout login, <strong>checkout tamu/publik</strong> (' . $mono('/order/{slug}') . '), dan form order yang di-<strong>embed</strong>. Dedup 1&times; per sesi/produk/hari.',
      '<strong>Bayar</strong> mencakup <em>semua</em> order lunas produk itu, termasuk <strong>transaksi manual admin</strong> &amp; order sebelum pelacakan dimulai — keduanya tak punya jejak Klik Checkout (wajar). Karena itu Bayar kadang tampak &ge; Klik Checkout, terutama pada data lama.',
  ])
  . $info('<strong>Tips:</strong> Jika drop besar di tahap checkout, coba ganti Template Checkout atau kurangi friksi (Two-Step).')
  . $h3('Pengunjung Aktif (realtime)')
  . '<p class="mb-3">Kartu <strong>Pengunjung Aktif</strong> menampilkan siapa yang <em>sedang</em> membuka halaman sales/checkout produk saat ini, dipecah per tahap. Diperbarui otomatis tiap ~20 detik; dihitung &ldquo;aktif&rdquo; bila pengunjung terpantau dalam <strong>60 detik</strong> terakhir. Kunjungan admin/staff sendiri tidak dihitung.</p>'
);

echo $section('funnel-gate',
  $L('Funnel Gate', 'Funnel Gate', 'Funnel Gate'),
  $L('Form kualifikasi prospek ber-skor — saring calon yang layak sebelum masuk penawaran.', 'Borang kelayakan prospek berskor.', 'Scored lead-qualification form.'),
  '<p class="mb-4"><strong>Funnel Gate</strong> bukan sekadar form — ini <strong>mesin penyaring prospek</strong>. Pengunjung mengisi beberapa pertanyaan, tiap jawaban diberi <strong>skor</strong>, lalu berdasarkan total skor mereka diarahkan ke tujuan berbeda (mis. skor tinggi → checkout premium, skor menengah → sesi konsultasi, skor rendah → materi gratis). Cocok untuk <strong>high-ticket, mentoring, coaching, agency,</strong> atau membership eksklusif.</p>'
  . $badges(['Kontak di awal', 'Skoring otomatis', 'Autosave draft', 'Redirect by-skor', 'Single / Multi-step'])
  . $h3('Cara membuat')
  . $steps([
      ['1. Buka Funnel Gate', 'Menu ' . $mono('/admin/funnel-gate') . ' → <strong>Buat Form</strong>.'],
      ['2. Atur kontak (di awal)', 'Nama &amp; WhatsApp wajib, Email opsional. Diletakkan di awal supaya prospek yang belum selesai mengisi tetap bisa di-follow-up.'],
      ['3. Susun pertanyaan + skor', 'Tambah pertanyaan (pilihan, rating, angka, teks). Untuk pilihan, beri <strong>skor per opsi</strong>; untuk angka/rating, atur <strong>bobot</strong>.'],
      ['4. Mode tampilan', 'Pilih <strong>Single</strong> (satu halaman) atau <strong>Multi-step</strong> (wizard bertahap dengan progress bar).'],
      ['5. Aturan redirect by-skor', 'Tentukan rentang skor → URL tujuan (mis. skor ≥80 → <code class="text-xs">/checkout/kelas-premium</code>). Bila tak ada yang cocok, tampil pesan terima kasih.'],
      ['6. Publish', 'Form tayang di ' . $mono('/apply/{slug}') . '. Sebar link ini, atau taruh tombolnya di landing Canvas.'],
  ])
  . $h3('Melihat & mengelola submission')
  . $ul([
      'Buka ' . $mono('/admin/funnel-gate/submissions') . ' — daftar semua prospek beserta <strong>skor</strong> &amp; jawaban, bisa difilter status/skor.',
      'Ubah status: <strong>Submitted → Approved / Rejected / Pending</strong>, tambah catatan internal.',
      'Draft yang belum di-submit pun tersimpan (kontak sudah ada) → bisa di-follow-up manual.',
  ])
  . $info('<strong>Tie-in Mailketing:</strong> saat sebuah submission kamu ubah ke <strong>Approved</strong>, prospek otomatis dimasukkan ke <strong>list Mailketing</strong> yang kamu pilih di form (untuk email follow-up otomatis). Lihat topik <strong>Email (Mailketing)</strong>.')
  . $warn('Form publik butuh JavaScript aktif untuk submit &amp; autosave. Skoring dihitung di server saat submit (tak bisa dimanipulasi dari browser).')
);

echo $section('ai-chat',
  $L('AI Chat Widget', 'Widget AI Chat', 'AI Chat Widget'),
  $L('Chatbot AI mengambang untuk halaman publik &amp; member — melayani calon pembeli otomatis 24/7.', 'Chatbot AI terapung untuk laman awam &amp; ahli.', 'Floating AI chatbot for public &amp; member pages.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Widget chat tampil mengambang di pojok halaman. Setiap pesan pengunjung dikirim lewat <strong>proxy server</strong> ke provider AI — jadi <strong>API key tidak pernah terekspos ke browser pengunjung</strong>.</p>'
  . $h3('Langkah setup')
  . $steps([
      ['Buka AI Chat', 'Akses ' . $mono('/admin/ai-chat') . ' lalu aktifkan widget.'],
      ['Pilih provider &amp; API key', 'Pilih <strong>Gemini</strong> (Google) atau <strong>Groq</strong>, tempel API key, klik <strong>Test Koneksi</strong> untuk memastikan valid.'],
      ['Atur kepribadian', 'Isi nama bot, pesan sambutan, dan <strong>System Prompt</strong> (instruksi perilaku, gaya bicara, &amp; batasan AI).'],
      ['Tentukan lokasi tampil', 'Aktifkan untuk halaman publik dan/atau member area sesuai kebutuhan.'],
      ['Personalisasi tampilan', 'Pilih tema &amp; warna (ikut brand atau kustom), aktifkan gradient, tambah quick-replies.'],
  ])
  . $h3('Mode Sales Assistant (menjual, bukan sekadar menjawab)')
  . $steps([
      ['Pakai template persona', 'Di ' . $mono('/admin/ai-chat') . ' klik <strong>Pakai Template Sales Assistant</strong> — system prompt siap pakai: jawab → gali kebutuhan → rekomendasikan satu produk → arahkan checkout, dengan aturan anti-mengarang.'],
      ['Nyalakan konteks katalog', 'Toggle <strong>Sertakan katalog produk sebagai konteks AI</strong> — AI mengenal harga, diskon, link beli, penawaran upsell, dan status tiap produk (maks 30).'],
      ['Isi FAQ &amp; Kebijakan Toko', 'Cara bayar, refund, cara akses, garansi — tanpa ini AI menolak menjawab pertanyaan kebijakan (dia dilarang mengarang).'],
      ['Isi AI Knowledge per produk', 'Di form edit produk ada field <strong>AI Knowledge</strong> (manfaat, target pembeli, jawaban keberatan) + tombol <strong>Generate draft dengan AI</strong> — sekali klik, draft tersusun, tinggal disesuaikan.'],
  ])
  . $h3('Fitur pintar')
  . $ul([
      '<strong>Tombol beli di chat</strong> — link checkout dari AI otomatis tampil sebagai tombol <strong>Beli Sekarang</strong> yang bisa diklik pengunjung.',
      '<strong>Lead capture</strong> — setelah pesan ke-2, pengunjung ditawari (bisa dilewati) meninggalkan nama &amp; WhatsApp → masuk ke <strong>Leads AI Chat</strong> lengkap dengan isi percakapannya.',
      '<strong>Fallback WhatsApp</strong> — bila AI tak sanggup menjawab, pengunjung ditawari lanjut ke admin lewat WhatsApp.',
      '<strong>Rate limit</strong> — maksimum 10 pesan / 60 detik per IP untuk mencegah penyalahgunaan kuota API.',
  ])
  . $info('<strong>Ambil API key gratis:</strong> Gemini di ' . $mono('aistudio.google.com') . ', Groq di ' . $mono('console.groq.com') . '.')
  . $warn('<strong>Biaya:</strong> pemakaian API ditagih oleh provider (Google/Groq) sesuai kuota mereka, di luar biaya portal. Pantau penggunaan di dashboard provider.')
);

echo $section('chat-leads',
  $L('Leads AI Chat', 'Leads AI Chat', 'AI Chat Leads'),
  $L('Kontak pengunjung yang tertangkap dari widget chat — lengkap dengan isi percakapannya untuk follow-up personal.', 'Kenalan pengunjung dari widget chat beserta perbualannya.', 'Visitor contacts captured from the chat widget with full transcript.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Setelah pengunjung mengirim <strong>2 pesan</strong> di widget chat, muncul form sopan menawarkan meninggalkan <strong>nama + nomor WhatsApp</strong> (bisa dilewati, hanya muncul sekali). Bila diisi, seluruh percakapan — termasuk pesan-pesan berikutnya — tersimpan sebagai transcript.</p>'
  . $steps([
      ['Buka Leads AI Chat', 'Akses ' . $mono('/admin/chat-leads') . ' (grup Marketing &amp; Growth).'],
      ['Baca transcript-nya', 'Klik baris lead untuk melihat persis apa yang dia tanyakan — bekal follow-up yang sangat personal.'],
      ['Follow-up via WhatsApp', 'Tombol hijau membuka chat WA dengan pesan pembuka bernama, tinggal lanjutkan dari konteks percakapannya.'],
  ])
  . $info('<strong>Tips closing:</strong> sebut apa yang dia tanyakan di chat ("kemarin tanya soal kelas untuk pemula ya kak…") — tingkat respons follow-up seperti ini jauh di atas broadcast biasa.')
  . $warn('<strong>Anti-spam:</strong> maksimum 3 lead per IP per jam; nomor WA divalidasi &amp; dinormalisasi otomatis ke format 62.')
);

echo $section('averion-studio',
  $L('Integrasi Averion Studio', 'Integrasi Averion Studio', 'Averion Studio Integration'),
  $L('Hubungkan (integrasi) instalasimu ke Averion Studio untuk membuat materi promosi (broadcast WA, kalender konten, script VSL, ad copy) dari data produkmu.', 'Hubungkan pemasangan anda ke Averion Studio.', 'Connect your install to Averion Studio.'),
  $h3('Apa itu')
  . '<p class="mb-4"><strong>Averion Studio</strong> adalah aplikasi pendamping di ' . $mono('studio.averion.id') . ' yang membantu membuat materi promosi — broadcast WhatsApp, kalender konten, script VSL, ad copy, sampai prompt desain visual — berbasis data produk di instalasimu.</p>'
  . $steps([
      ['Buat token', 'Buka ' . $mono('/admin/studio-integration') . ' → <strong>Generate Token</strong>. Token tampil sekali — salin &amp; simpan.'],
      ['Login Studio', 'Buka ' . $mono('studio.averion.id') . ' → login dengan email + license key Averion kamu.'],
      ['Hubungkan', 'Tempel token di Studio → Studio bisa membaca daftar produk aktifmu dan mulai membuatkan materi.'],
  ])
  . $h3('Data yang dibagikan')
  . $ul([
      'Hanya <strong>daftar produk aktif</strong>: nama, harga, deskripsi singkat, benefit, link checkout.',
      'TIDAK ada data pelanggan, order, atau omzet yang dibagikan ke Studio.',
      'Token bisa <strong>dicabut (revoke)</strong> kapan saja dari halaman yang sama — akses Studio langsung terputus.',
  ])
  . $info('<strong>Tips:</strong> Setelah mengubah nama/harga/benefit produk, materi baru yang dibuat Studio otomatis memakai data terkini — tak perlu menghubungkan ulang.')
);

echo $section('integrasi-claude',
  $L('Integrasi Claude (MCP)', 'Integrasi Claude (MCP)', 'Claude Integration (MCP)'),
  $L('Hubungkan instalasimu ke aplikasi Claude sebagai Custom Connector — lalu tanya data bisnismu (omzet, produk, traffic, funnel, order pending, affiliate, member, tiket) lewat percakapan biasa. Claude juga bisa menyusun draft & mengirim pesan (ber-izin).', 'Hubungkan pemasangan anda ke aplikasi Claude sebagai Custom Connector.', 'Connect your install to the Claude app as a Custom Connector.'),
  $h3('Apa itu')
  . '<p class="mb-4"><strong>Integrasi Claude</strong> memakai protokol <strong>MCP</strong> (Model Context Protocol). Instalasimu menyediakan satu alamat konektor (' . $mono(rtrim(APP_URL, '/') . '/api/mcp') . ') yang bisa kamu daftarkan di aplikasi Claude. Setelah tersambung, kamu tinggal bertanya dengan bahasa sehari-hari — Claude memanggil data langsung dari instalasimu (bukan tebakan), lalu menjawab, menganalisis, atau menyusun draft.</p>'
  . $info('<strong>Baca saja secara default.</strong> 13 dari 15 kemampuan hanya <em>membaca</em> data. Dua kemampuan yang bisa <em>mengirim pesan</em> mati secara default dan harus kamu nyalakan manual (lihat Keamanan). Payout, refund, dan perubahan saldo <strong>tidak pernah</strong> tersedia lewat Claude.')

  . $h3('Cara Memasang (sekali saja)')
  . $steps([
      ['Buka pengaturan', 'Masuk ke ' . $mono('/admin/mcp-integration') . ' (menu <strong>Integrasi Claude</strong> di grup Konfigurasi).'],
      ['Generate Token', 'Klik <strong>Generate Token</strong>. Token tampil <strong>sekali</strong> — salin &amp; simpan sekarang (kami hanya menyimpan sidik jari/hash-nya).'],
      ['Nyalakan integrasi', 'Aktifkan toggle <strong>Aktifkan Integrasi Claude</strong>. Tanpa ini, konektor akan ditolak walau token benar.'],
      ['Tambah Custom Connector', 'Di aplikasi Claude: <strong>Settings &rsaquo; Connectors &rsaquo; Add custom connector</strong>. Beri nama bebas, tempel <strong>URL Konektor</strong>.'],
      ['Atur autentikasi', 'Pilih Autentikasi = <strong>"Tidak ada"</strong> (bukan OAuth). Ini server yang memakai API key sebagai pengganti OAuth.'],
      ['Tambah header token', 'Di <strong>Request headers</strong>, tambahkan header ' . $mono('Authorization') . ' bernilai ' . $mono('Bearer &lt;token&gt;') . ' (memakai token dari langkah 2). Centang "Wajib".'],
      ['Simpan & coba', 'Simpan, aktifkan connector, lalu tanya mis. "Berapa omzet 30 hari terakhir?" untuk memastikan tersambung.'],
  ])
  . $code(rtrim(APP_URL, '/') . '/api/mcp')
  . $info('<strong>Alternatif nama header:</strong> selain ' . $mono('Authorization: Bearer &lt;token&gt;') . ', server juga menerima header ' . $mono('x-api-key') . ' atau ' . $mono('api-token') . ' berisi token mentah. Header standar ' . $mono('authorization') . '/' . $mono('x-api-key') . ' paling mulus.')
  . $warn('<strong>Bila gagal connect:</strong> pastikan Autentikasi diset <strong>"Tidak ada"</strong> (bukan "Selalu diperlukan"/OAuth), URL berakhiran ' . $mono('/api/mcp') . ', dan situs sudah HTTPS. Bila firewall hosting agresif, izinkan (whitelist) rentang IP Anthropic ' . $mono('160.79.104.0/21') . '.')

  . $h3('Keamanan &amp; Kontrol')
  . $ul([
      '<strong>Toggle Aktifkan Integrasi Claude</strong> — sakelar utama. Mati = semua permintaan ditolak walau token benar. Default <strong>mati</strong>.',
      '<strong>Privasi Data Member (mask)</strong> — default <strong>menyamarkan</strong> email &amp; WhatsApp (mis. ' . $mono('b***@gmail.com') . ', ' . $mono('6281****89') . '). Ubah ke "Tampilkan data penuh" hanya bila kamu sendiri pemegang token.',
      '<strong>Izinkan Aksi Tulis</strong> — default <strong>mati</strong>. Saat nyala, Claude bisa mengirim pesan ke member &amp; alert Telegram admin. Tiap aksi tetap minta persetujuanmu di Claude &amp; tercatat di log &amp; ' . $mono('notification_logs') . '.',
      '<strong>Token</strong> — hanya tersimpan sebagai hash, tak bisa dilihat ulang. Lupa/bocor? Klik <strong>Generate Ulang</strong> atau <strong>Cabut Token</strong> — koneksi lama langsung putus.',
      '<strong>Batas tergembok</strong> — payout, refund, perubahan saldo, dan penghapusan data <strong>tidak pernah</strong> tersedia lewat Claude, di kondisi apa pun.',
  ])
  . $info('<strong>Audit:</strong> setiap panggilan Claude tercatat di <strong>Log Aktivitas</strong> (' . $mono('/admin/activity-logs') . ') — kamu selalu bisa melihat apa yang diakses dan kapan.')

  . $h3('15 Kemampuan (Tool) yang Tersedia')
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Laporan &amp; omzet</span></p>'
  . $ul([
      $mono('get_business_dashboard') . ' — ringkasan KPI (omzet, order lunas &amp; pending, pengguna baru, member aktif) per periode.',
      $mono('get_revenue_summary') . ' — tren omzet per hari/bulan + perbandingan persen vs periode sebelumnya.',
      $mono('get_product_sales') . ' — produk terlaris: jumlah terjual &amp; omzet per produk.',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Traffic &amp; funnel</span></p>'
  . $ul([
      $mono('get_traffic_summary') . ' — kunjungan, klik checkout, pembelian &amp; conversion rate per produk (untuk cari produk "ramai dilihat tapi sepi beli").',
      $mono('get_funnel_analytics') . ' — funnel satu produk: kunjungan → klik checkout → bayar, plus rate tiap tahap &amp; omzet.',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Analitik lanjutan (A/B, broadcast, pixel)</span></p>'
  . $ul([
      $mono('get_canvas_ab_test') . ' — hasil split test Canvas Rotator: impresi, klik, konversi &amp; CR per varian + penanda varian unggul (isi nama rotator untuk rincian, kosongkan untuk ringkasan semua).',
      $mono('get_broadcast_engagement') . ' — performa broadcast email/WA: penerima, terkirim, gagal, open rate &amp; click rate (agregat per campaign, tanpa data penerima).',
      $mono('get_pixel_delivery_health') . ' — kesehatan pengiriman event server-side (antrian Meta CAPI keluar): jumlah pending/terkirim/gagal, failed rate, umur pending tertua, per platform.',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Order, affiliate &amp; member</span></p>'
  . $ul([
      $mono('get_pending_transactions') . ' — order menunggu bayar, diurut tertua dulu + umur order (jam) &amp; gateway.',
      $mono('get_top_affiliates') . ' — peringkat affiliate: konversi, komisi disetujui &amp; pending.',
      $mono('search_members') . ' — cari member (nama/email/WA): total belanja, jumlah order lunas, level, akses aktif. Email/WA ikut aturan mask.',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Tiket support</span></p>'
  . $ul([
      $mono('get_support_tickets') . ' — daftar tiket (default "open") + tanda <em>needs_reply</em> untuk yang belum dibalas.',
      $mono('get_ticket') . ' — detail satu tiket + seluruh utas percakapan, untuk Claude menyusun DRAFT balasan (tidak dikirim otomatis).',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Aksi tulis (perlu "Izinkan Aksi Tulis" menyala)</span></p>'
  . $ul([
      $mono('send_member_message') . ' — kirim WhatsApp/email ke satu member (memakai provider notifikasi instalasi ini).',
      $mono('send_admin_telegram') . ' — kirim alert ke Telegram admin (internal, bukan ke pelanggan).',
  ])

  . $h3('Contoh Pertanyaan yang Bisa Dijawab Claude')
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Traffic &amp; funnel</span></p>'
  . $ul([
      '"Produk mana yang <strong>trafficnya tinggi tapi konversinya rendah</strong> bulan ini?"',
      '"Di <strong>tahap mana</strong> pengunjung produk <em>Solo Founder</em> paling banyak berhenti?"',
      '"<strong>Bandingkan conversion rate</strong> semua produk bulan ini, urutkan dari yang terburuk."',
      '"Berapa persen pengunjung yang klik checkout tapi tidak jadi bayar di produk X?"',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Split test, broadcast &amp; pixel</span></p>'
  . $ul([
      '"<strong>Varian canvas mana yang menang</strong> di produk X — dan berapa selisih conversion rate-nya?"',
      '"Broadcast terakhir <strong>open &amp; click rate</strong>-nya berapa?"',
      '"Ada <strong>event Meta CAPI yang gagal</strong> terkirim? Sudah berapa lama yang pending menumpuk?"',
      '"Bandingkan <strong>open rate</strong> semua broadcast 30 hari terakhir."',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Omzet &amp; produk</span></p>'
  . $ul([
      '"Berapa <strong>omzet 30 hari</strong> terakhir, naik atau turun dibanding bulan sebelumnya?"',
      '"Apa <strong>5 produk terlaris</strong> tahun ini berdasarkan omzet?"',
      '"Tunjukkan tren omzet <strong>per bulan</strong> selama 90 hari."',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Order, affiliate &amp; member</span></p>'
  . $ul([
      '"Ada berapa <strong>order menunggu bayar</strong> yang sudah lebih dari 24 jam?"',
      '"Siapa <strong>affiliate teratas</strong> bulan ini dan berapa komisi yang belum disetujui?"',
      '"Cari member dengan email budi — berapa <strong>total belanjanya</strong>?"',
  ])
  . '<p class="mb-3"><span class="font-medium text-gray-700 dark:text-gray-300">Tiket &amp; aksi (draft/kirim)</span></p>'
  . $ul([
      '"Tiket support mana yang <strong>belum dibalas</strong>? Bacakan yang paling lama menunggu."',
      '"Baca tiket #TKT-xxxx lalu <strong>buatkan draft balasan</strong> yang sopan." <span class="text-gray-400">(draft — kamu yang mengirim)</span>',
      '"<strong>Kirim WhatsApp</strong> ke member itu berisi info berikut…" <span class="text-gray-400">(butuh Aksi Tulis menyala + persetujuanmu)</span>',
  ])
  . $info('<strong>Tips:</strong> Claude paling kuat saat menggabungkan beberapa kemampuan. Contoh: "Cari produk yang trafficnya tinggi tapi konversinya rendah, lalu usulkan 3 perbaikan checkout" — Claude membaca traffic + funnel lalu menganalisis sendiri.')

  . $h3('Yang Belum Termasuk')
  . $ul([
      'Data <strong>Meta Pixel / TikTok</strong> hidup di dashboard platform iklan mereka (portal mengirim event) — butuh konektor Meta Marketing API terpisah, di luar integrasi ini.',
      'Aksi berisiko tinggi (payout, refund, ubah saldo, hapus data) <strong>tidak pernah</strong> tersedia lewat Claude.',
  ])
  . $warn('<strong>Catatan data traffic:</strong> angka traffic/funnel berasal dari pelacakan funnel portal, jadi hanya tersedia untuk produk yang halaman-nya memasang pelacakan dan sejak fitur Funnel aktif (histori terbatas).')
);

// ═══════════════════════ PEMBAYARAN ═══════════════════════

echo $section('payment-gateway',
  $L('Payment Gateway', 'Get Bayaran', 'Payment Gateway'),
  $L('Pilih cara menerima pembayaran transaksi.', 'Pilih cara menerima bayaran.', 'Choose how to receive payments.'),
  $h3('Pilihan Pembayaran')
  . $badges(['Xendit', 'Duitku', 'Midtrans', 'Tripay', 'Moota', 'PayPal', 'Manual Transfer'])
  . $ul([
      '<strong>Xendit</strong> — otomatis: Virtual Account, QRIS, e-wallet.',
      '<strong>Duitku</strong> — otomatis: VA Bank, e-wallet, QRIS.',
      '<strong>Midtrans</strong> — otomatis (Snap): VA Bank, GoPay/ShopeePay, QRIS, Kartu Kredit.',
      '<strong>Tripay</strong> — otomatis: pembeli pilih channel (VA, QRIS, e-wallet, retail Alfamart).',
      '<strong>Moota</strong> — konfirmasi otomatis transfer bank via kode unik.',
      '<strong>PayPal</strong> — pembeli luar negeri (ditagih USD dari total IDR memakai kurs).',
      '<strong>Manual Transfer</strong> — transfer ke Rekening Bank, konfirmasi manual oleh admin.',
  ])
  . '<p class="mb-3">Setiap gateway punya panduan setup tersendiri di menu kiri (Xendit, Duitku, Midtrans, Tripay, Moota, PayPal). Aktifkan di ' . $mono('/admin/integrations') . ' → tab <strong>Payment Gateway</strong>. Isi kredensial, lalu <strong>daftarkan webhook</strong> gateway agar status pembayaran ter-update otomatis saat pembeli bayar.</p>'
  . $h3('Tentang Webhook (wajib untuk gateway otomatis)')
  . '<p class="mb-3"><strong>Webhook</strong> = URL yang dipanggil gateway untuk memberi tahu Averion bahwa pembayaran sudah masuk. Tanpa mendaftarkan webhook, status order <strong>tidak akan berubah menjadi Lunas</strong> otomatis.</p>'
  . $info('<strong>Tak perlu ketik manual:</strong> URL webhook tiap gateway sudah disediakan di halaman config (kolom <em>URL Webhook</em> + tombol <strong>Copy</strong>). Salin dari sana, lalu tempel di dashboard gateway sesuai lokasi di topik masing-masing di bawah.')
  . $info('<strong>Info:</strong> Hanya satu gateway otomatis aktif dalam satu waktu; Manual Transfer bisa tetap berdampingan.')
  . $warn('<strong>Perhatian:</strong> HTTPS wajib untuk webhook di production — gateway tidak mengirim ke URL HTTP.')
);

echo $section('xendit',
  $L('Integrasi Xendit', 'Integrasi Xendit', 'Xendit Integration'),
  $L('Setup Xendit untuk pembayaran otomatis.', 'Persediaan Xendit untuk bayaran automatik.', 'Set up Xendit for automatic payments.'),
  $steps([
      ['Daftar akun', 'Buat akun di ' . $mono('xendit.co') . '.'],
      ['Ambil API Key', 'Dashboard → Settings → API Keys → <strong>Secret Key</strong>.'],
      ['Ambil Webhook Token', 'Dashboard → Settings → Callbacks → <strong>Webhook Verification / Callback token</strong>.'],
      ['Isi di Averion', $mono('/admin/integrations') . ' → Xendit → isi API Key + Webhook Verification Token.'],
      ['Salin URL Webhook', 'Di halaman config Xendit, salin <strong>URL Webhook</strong> (tombol Copy).'],
      ['Daftarkan di Xendit', 'Tempel URL itu di Dashboard Xendit → <strong>Settings → Callbacks</strong> → bagian <strong>Invoices</strong> → <strong>Invoices paid</strong>. Tanpa ini, status pembayaran tak ter-update otomatis.'],
      ['Test sandbox', 'Uji dengan mode sandbox sebelum production.'],
  ])
  . $code('Contoh URL: https://domain-kamu.com/webhooks/xendit-callback.php')
  . '<p class="mb-3">Metode didukung: <strong>Virtual Account</strong>, <strong>QRIS</strong>, <strong>e-wallet</strong>.</p>'
  . $warn('<strong>Perhatian:</strong> HTTPS wajib — Xendit tidak mengirim webhook ke URL HTTP.')
);

echo $section('moota',
  $L('Integrasi Moota', 'Integrasi Moota', 'Moota Integration'),
  $L('Konfirmasi otomatis transfer bank lewat kode unik.', 'Pengesahan automatik pindahan bank.', 'Auto-confirm bank transfers via unique code.'),
  $steps([
      ['Daftar akun', 'Buat akun di ' . $mono('moota.co') . ' dan tambahkan rekening bank.'],
      ['Ambil Server Key API V2', 'Dashboard Moota → Pengaturan API → <strong>Server Key (API V2)</strong>.'],
      ['Isi di Averion', $mono('/admin/integrations') . ' → Moota → isi API Key (Server Key V2) + Secret Token.'],
      ['Test koneksi', 'Simpan dulu, lalu klik <strong>Test Koneksi</strong> untuk verifikasi.'],
      ['Daftarkan webhook', 'Salin URL webhook (di bawah) ke Moota → Webhook, dengan Secret Token yang sama.'],
      ['Info rekening', 'Isi <strong>Bank Info</strong> (mis. "BCA 1234567890 a/n Nama Cabang") yang tampil ke pembeli.'],
  ])
  . $code('https://domain-kamu.com/webhooks/moota-callback')
  . $h3('Cara Kerja Kode Unik')
  . '<p class="mb-4">Nominal transfer = harga + kode unik. Moota mengirim semua mutasi rekening, dan Averion otomatis mencocokkan dengan order yang menunggu pembayaran.</p>'
  . $info('<strong>Catatan:</strong> Moota tidak punya mode sandbox — menggunakan rekening bank asli.')
);

echo $section('duitku',
  $L('Integrasi Duitku', 'Integrasi Duitku', 'Duitku Integration'),
  $L('Setup Duitku untuk VA Bank, e-wallet, dan QRIS.', 'Persediaan Duitku untuk pelbagai kaedah.', 'Set up Duitku for VA, e-wallet, QRIS.'),
  $steps([
      ['Daftar akun', 'Buat akun merchant di ' . $mono('duitku.com') . '.'],
      ['Ambil kredensial', 'Dashboard Duitku → ambil <strong>Merchant Code</strong> + <strong>API Key</strong>.'],
      ['Isi di Averion', $mono('/admin/integrations') . ' → Duitku → isi Merchant Code + API Key.'],
      ['Mode', 'Pilih <strong>Sandbox</strong> untuk uji coba, lalu <strong>Production</strong> saat siap.'],
      ['Salin URL Webhook', 'Di halaman config Duitku, salin <strong>URL Webhook</strong> (tombol Copy).'],
      ['Daftarkan di Duitku', 'Tempel URL itu di Dashboard Duitku → <strong>Proyek</strong> → <strong>Callback URL</strong> (URL notifikasi). Tanpa ini, status pembayaran tak ter-update otomatis.'],
  ])
  . $code('Contoh URL: https://domain-kamu.com/webhooks/duitku-callback.php')
  . '<p class="mb-3">Metode didukung: <strong>VA Bank</strong>, <strong>e-wallet</strong>, <strong>QRIS</strong>.</p>'
  . $info('<strong>Dokumentasi resmi:</strong> ' . $mono('https://docs.duitku.com') . '.')
);

echo $section('midtrans',
  $L('Integrasi Midtrans', 'Integrasi Midtrans', 'Midtrans Integration'),
  $L('Setup Midtrans (Snap) untuk VA Bank, e-wallet, QRIS, dan kartu.', 'Persediaan Midtrans (Snap) untuk pelbagai kaedah.', 'Set up Midtrans (Snap).'),
  $steps([
      ['Daftar akun', 'Buat akun merchant di ' . $mono('midtrans.com') . '.'],
      ['Ambil kredensial', 'Dashboard Midtrans → <strong>Settings → Access Keys</strong> → salin <strong>Server Key</strong> (wajib) &amp; <strong>Client Key</strong> (opsional). Pakai kredensial <strong>Sandbox</strong> saat mode Sandbox, <strong>Production</strong> saat live.'],
      ['Isi di Averion', $mono('/admin/integrations') . ' → Midtrans → isi <strong>Server Key</strong>, <strong>Client Key</strong> (opsional), <strong>Merchant ID</strong> (informatif), lalu pilih <strong>mode</strong> (Sandbox/Production).'],
      ['Salin URL Webhook', 'Di halaman config Midtrans, salin <strong>Payment Notification URL</strong> (tombol Copy).'],
      ['Daftarkan di Midtrans', 'Tempel URL itu di Dashboard Midtrans → <strong>Settings → Configuration</strong> → <strong>Payment Notification URL</strong>. Tanpa ini, status pembayaran tak ter-update otomatis.'],
      ['Test sandbox', 'Uji dengan mode Sandbox sebelum production.'],
  ])
  . $code('Contoh URL: https://domain-kamu.com/webhooks/midtrans-callback.php')
  . '<p class="mb-3">Metode didukung: <strong>VA Bank</strong>, <strong>e-wallet</strong> (GoPay/ShopeePay), <strong>QRIS</strong>, <strong>Kartu Kredit</strong> — lewat halaman <strong>Snap</strong> (hosted).</p>'
  . $info('Alur: pembeli pilih Midtrans → order dibuat → diarahkan ke halaman Snap Midtrans → bayar → status ter-update otomatis lewat Payment Notification (webhook), lalu akses produk aktif.')
  . $warn('<strong>Perhatian:</strong> HTTPS wajib — Midtrans tidak mengirim notifikasi ke URL HTTP. Pastikan <strong>Server Key</strong> &amp; <strong>mode</strong> cocok (kredensial Sandbox ≠ Production).')
);

echo $section('tripay',
  $L('Integrasi Tripay', 'Integrasi Tripay', 'Tripay Integration'),
  $L('Setup Tripay (Closed Payment) — pembeli pilih channel VA/QRIS/e-wallet/retail.', 'Persediaan Tripay (Closed Payment).', 'Set up Tripay (Closed Payment).'),
  $steps([
      ['Daftar akun', 'Buat akun merchant di ' . $mono('tripay.co.id') . ' dan selesaikan verifikasi merchant.'],
      ['Ambil kredensial', 'Dashboard Tripay → <strong>Merchant → API</strong> → salin <strong>Kode Merchant</strong>, <strong>API Key</strong>, &amp; <strong>Private Key</strong>. Pakai kredensial <strong>Sandbox</strong> saat mode Sandbox.'],
      ['Isi di Averion', $mono('/admin/integrations') . ' → Tripay → isi <strong>Kode Merchant</strong> + <strong>API Key</strong> + <strong>Private Key</strong>, lalu pilih <strong>mode</strong>.'],
      ['Salin URL Callback', 'Di halaman config Tripay, salin <strong>Callback URL</strong> (tombol Copy).'],
      ['Daftarkan di Tripay', 'Tempel URL itu di Dashboard Tripay → <strong>Merchant → Callback URL</strong>. Tanpa ini, status pembayaran tak ter-update otomatis.'],
      ['Test sandbox', 'Uji dengan mode Sandbox sebelum production.'],
  ])
  . $code('Contoh URL: https://domain-kamu.com/webhooks/tripay-callback.php')
  . '<p class="mb-3">Pembeli memilih <strong>channel</strong> (VA Bank, QRIS, e-wallet, retail seperti Alfamart) saat checkout, lalu diarahkan ke halaman pembayaran Tripay (hosted). Daftar channel diambil otomatis dari akunmu (di-cache 1 jam).</p>'
  . $info('Signature dibuat &amp; diverifikasi otomatis (HMAC-SHA256 dengan Private Key; callback lewat header X-Callback-Signature) — cukup isi kredensial dengan benar.')
  . $warn('<strong>Perhatian:</strong> HTTPS wajib untuk callback. Jika channel tak muncul di checkout, cek kredensial &amp; mode lalu simpan ulang (cache channel akan disegarkan).')
);

echo $section('paypal',
  $L('Integrasi PayPal', 'Integrasi PayPal', 'PayPal Integration'),
  $L('Terima pembayaran internasional (kartu / saldo PayPal) untuk pembeli luar negeri.', 'Terima bayaran antarabangsa.', 'Accept international payments.'),
  '<p class="mb-4">Gateway <strong>PayPal</strong> cocok untuk pembeli <strong>luar negeri</strong> (mis. Malaysia, Singapura). PayPal tidak mendukung Rupiah, jadi Averion menagih dalam <strong>USD</strong> — nominal dikonversi otomatis dari total IDR memakai <strong>kurs</strong> yang kamu tetapkan. Pencatatan order tetap dalam IDR.</p>'
  . $steps([
      ['Buat akun Bisnis PayPal', 'Daftar/masuk di ' . $mono('developer.paypal.com') . ' → buat App untuk dapat kredensial.'],
      ['Ambil kredensial', 'Salin <strong>Client ID</strong> + <strong>Secret</strong> (mode Sandbox untuk uji, Live untuk produksi).'],
      ['Isi di Averion', $mono('/admin/integrations') . ' → PayPal → tempel Client ID + Secret, pilih mode.'],
      ['Set kurs IDR→USD', 'Isi <strong>kurs</strong> (mis. 1 USD = Rp16.000). Tanpa kurs terisi, PayPal tidak akan ditawarkan di checkout.'],
      ['Aktifkan', 'Toggle aktif. Tombol PayPal muncul di checkout untuk pembeli.'],
  ])
  . $info('Alur: pembeli klik PayPal → diarahkan ke PayPal → bayar → kembali &amp; akses produk otomatis aktif (mirror seperti gateway lain). Sudah teruji end-to-end di sandbox.')
  . $warn('Karena PayPal menagih USD, pastikan <strong>kurs selalu diperbarui</strong> agar nominal wajar. <code class="text-xs">orders.total</code> tetap IDR — USD hanya alat tagih.')
);

// ═══════════════════════ NOTIFIKASI ═══════════════════════

echo $section('notifikasi',
  $L('Template Notifikasi', 'Templat Notifikasi', 'Notification Templates'),
  $L('Atur isi pesan otomatis per event & channel.', 'Atur mesej automatik per acara & saluran.', 'Configure automated messages per event & channel.'),
  '<p class="mb-3">Buka ' . $mono('/admin/notifications') . '. Setiap template terikat ke <strong>event</strong> (mis. pembayaran berhasil, keranjang tertinggal) dan <strong>channel</strong> (Email / WhatsApp).</p>'
  . $h3('Event Penting')
  . $ul([
      $mono('abandoned_cart') . ' — keranjang tertinggal',
      $mono('abandoned_cart_bonus') . ' — <strong>tambahan urgensi bonus</strong> yang di-<em>append</em> ke reminder abandoned cart bila order punya <strong>Bonus Kilat</strong> (placeholder ' . $mono('{{flash_bonus_list}}') . '); pesan dasar abandoned cart tetap dipakai',
      $mono('commission_approved') . ' — komisi affiliate disetujui',
      $mono('affiliate_new_order') . ' — <strong>notifikasi ke affiliate</strong> saat ada order via link mereka yang <strong>lunas &amp; menghasilkan komisi</strong>',
      $mono('payout_processing') . ' / ' . $mono('payout_paid') . ' / ' . $mono('payout_rejected') . ' — status pencairan',
      $mono('public_checkout_welcome') . ' — sambutan checkout',
  ])
  . $info('<strong>Anti-notif-dobel:</strong> tiap order kini hanya menerima notifikasi transaksional (pesanan masuk, pembayaran sukses, akses aktif) <strong>satu kali</strong> per penerima — aman meski gateway mengirim callback berulang. Notifikasi yang benar-benar gagal tetap boleh dikirim ulang lewat ' . $mono('/admin/system-health') . '.')
  . $h3('Placeholder')
  . $code('{name} {email} {product_name} {total} {order_number} {checkout_url} {amount} {bank_name} {app_name}')
  . $warn('<strong>Aktivasi:</strong> Beberapa template di-seed dalam keadaan <strong>nonaktif</strong>. Aktifkan template yang dibutuhkan (terutama abandoned cart, komisi, dan payout) agar pesan benar-benar terkirim.')
);

echo $section('whatsapp',
  $L('WhatsApp', 'WhatsApp', 'WhatsApp'),
  $L('Kirim notifikasi & OTP ke buyer via WhatsApp.', 'Hantar notifikasi & OTP via WhatsApp.', 'Send notifications & OTP via WhatsApp.'),
  $h3('Provider Didukung')
  . $badges(['Kirimdev (Cloud API resmi)', 'OneSender', 'Fonte / Fonnte', 'StarSender', 'DripSender'])
  . $steps([
      ['Buka Integrasi', 'Akses ' . $mono('/admin/integrations') . ' → bagian WhatsApp.'],
      ['Pilih & isi provider', 'Aktifkan toggle provider lalu isi kredensialnya.'],
      ['Test kirim', 'Simpan dulu, lalu gunakan tombol <strong>Test Kirim</strong> ke nomor sendiri.'],
  ])
  . $h3('Catatan per Provider')
  . $ul([
      '<strong>Kirimdev</strong> — WhatsApp Cloud API resmi. Butuh <strong>API Key</strong> + <strong>Phone Number ID</strong> (dari dashboard Kirimdev → GET /accounts).',
      '<strong>OneSender</strong> — provider unofficial, isi <strong>Bearer token</strong>. Tidak terikat 24-hour window.',
      '<strong>Fonte (Fonnte)</strong> — isi Authorization token; cocok untuk OTP ke nomor manapun.',
      '<strong>StarSender / DripSender</strong> — provider unofficial, isi API key.',
  ])
  . $info('<strong>Info:</strong> Provider WhatsApp juga dipakai untuk <strong>OTP login</strong> dan channel WhatsApp pada Abandoned Cart/Broadcast.')
);

echo $section('telegram',
  $L('Telegram (Notifikasi Internal Admin)', 'Telegram (Notifikasi Internal Admin)', 'Telegram (Internal Admin Alerts)'),
  $L('Alert real-time ke chat/grup Telegram admin — bukan ke pelanggan.', 'Alert masa nyata ke chat/kumpulan Telegram admin — bukan ke pelanggan.', 'Real-time alerts to your admin Telegram chat/group.'),
  $h3('Yang Bisa Dikirim')
  . $ul([
      '<strong>Order masuk</strong> — nama pembeli, email, WA, produk, total, waktu.',
      '<strong>Order gagal / dibatalkan</strong> — beserta alasannya.',
      '<strong>Login admin dari IP baru</strong> — email, IP, perangkat, waktu.',
  ])
  . $h3('Cara Setup')
  . $steps([
      ['Buat bot &amp; salin Bot Token', 'Buka <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer" class="font-medium underline" style="color:var(--brand-color)">@BotFather</a> di Telegram → ketik <strong>/newbot</strong> → ikuti langkahnya → salin <strong>Bot Token</strong> yang diberikan.'],
      ['Dapatkan Chat ID', 'Untuk chat pribadi: buka <a href="https://t.me/ChatIdInfoBot" target="_blank" rel="noopener noreferrer" class="font-medium underline" style="color:var(--brand-color)">@ChatIdInfoBot</a> lalu ketik <strong>/start</strong> — Chat ID kamu langsung tampil. Untuk <strong>channel/grup</strong>: forward salah satu pesan dari channel itu ke <a href="https://t.me/ChatIdInfoBot" target="_blank" rel="noopener noreferrer" class="font-medium underline" style="color:var(--brand-color)">@ChatIdInfoBot</a> → Chat ID channel muncul (diawali <strong>-100</strong>).'],
      ['Izinkan bot mengirim', 'Kirim <strong>/start</strong> ke <strong>bot baru buatanmu</strong> (bukan @ChatIdInfoBot) agar bot boleh mengirim pesan ke kamu. Untuk grup/channel: <strong>tambahkan bot sebagai anggota/admin</strong> dulu.'],
      ['Isi di Averion', 'Buka ' . $mono('/admin/integrations') . ' → tab <strong>Telegram</strong> → tempel <strong>Bot Token</strong> &amp; <strong>Chat ID</strong> → nyalakan <strong>Aktifkan Notifikasi Telegram</strong> → <strong>Simpan</strong>.'],
      ['Test Kirim', 'Klik tombol <strong>Test Kirim</strong>. Kalau pesan tes masuk ke Telegram-mu, konfigurasi sudah benar.'],
  ])
  . $h3('Atur Jenis Notifikasi')
  . $ul([
      'Toggle <strong>Order gagal/dibatalkan</strong> &amp; <strong>Login admin IP baru</strong> ada langsung di tab Telegram.',
      'Teks pesan <strong>Order masuk</strong> bisa kamu edit di ' . $mono('/admin/notifications') . ' (channel <strong>Telegram</strong>) — sekaligus bisa dinonaktifkan di sana.',
  ])
  . $info('<strong>Aman:</strong> Bot Token disimpan tersembunyi (masking). Telegram di sini <strong>hanya untuk internal admin/tim</strong>, tidak pernah dikirim ke pelanggan.')
  . $warn('Kalau Test gagal: pastikan kamu sudah menekan <strong>/start</strong> ke bot-mu, Chat ID benar (channel diawali <strong>-100</strong>), dan Bot Token tidak salah salin.')
);

echo $section('email-setup',
  $L('Email (Mailketing)', 'Emel (Mailketing)', 'Email (Mailketing)'),
  $L('Pengirim email untuk notifikasi & drip campaign.', 'Penghantar emel untuk notifikasi.', 'Email sender for notifications.'),
  $steps([
      ['Daftar Mailketing', 'Buat akun di Mailketing dan ambil <strong>API Key</strong> dari dashboard.'],
      ['Isi di Averion', $mono('/admin/integrations') . ' → Mailketing → isi API Key, <strong>Sender Email</strong>, dan <strong>Sender Name</strong>.'],
      ['Pakai', 'Email otomatis dipakai untuk notifikasi email, Email Sequence, dan Abandoned Cart (channel email).'],
  ])
  . $info('<strong>Tips:</strong> Gunakan sender email berdomain sendiri (mis. ' . $mono('noreply@domainmu.com') . ') agar keterkiriman lebih baik.')
  . $h3('List Otomatis — Pembeli Masuk List Mailketing')
  . '<p class="mb-3">Averion bisa <strong>otomatis menambahkan pembeli</strong> ke list Mailketing kamu (ala Sejoli): satu list saat <strong>order dibuat</strong> (checkout) dan satu list saat <strong>order lunas</strong>. Nama, email, &amp; nomor HP pembeli ikut terkirim. Cocok untuk pipeline follow-up (mis. list "Pending Payment" &amp; list "Sudah Beli").</p>'
  . $steps([
      ['Simpan API Key dulu', 'Pastikan API Key Mailketing sudah tersimpan (langkah di atas).'],
      ['Muat daftar list', 'Di ' . $mono('/admin/integrations') . ' → kartu Mailketing → bagian <strong>List Otomatis</strong> → klik <strong>Muat daftar list</strong> (mengambil list dari akun Mailketing-mu).'],
      ['Pilih list', 'Tetapkan <strong>List saat Order dibuat</strong> &amp; <strong>List saat Order Lunas</strong> dari dropdown. Biarkan <strong>— Nonaktif —</strong> bila salah satunya tak dipakai.'],
      ['Simpan', 'Klik <strong>Simpan Pengaturan Email</strong>. Mulai sekarang, pembeli otomatis masuk list sesuai kondisinya.'],
  ])
  . $info('<strong>Cara kerja:</strong> penambahan dijalankan di latar (antre) sehingga tak memperlambat checkout. Aman jika email sudah ada di list (Mailketing tak menggandakan).')
  . $warn('Kalau dropdown kosong / list tak muncul, pastikan API Key benar lalu klik <strong>Muat daftar list</strong> lagi.')
);

// ═══════════════════════ LISENSI PRODUK & API ═══════════════════════

echo $section('lisensi-produk',
  $L('Lisensi Produk', 'Lesen Produk', 'Product Licenses'),
  $L('Kelola license key untuk produk digital yang kamu jual (software, plugin, tema).', 'Urus kunci lesen untuk produk digital yang dijual.', 'Manage license keys for the digital products you sell.'),
  $h3('Cara Kerja')
  . '<p class="mb-5">Untuk produk yang dijual dengan proteksi lisensi (mis. software/plugin), Averion membuatkan <strong>license key</strong> per pembelian. Software milik buyer lalu memverifikasi key tersebut ke API Averion (lihat <strong>API Developer</strong>).</p>'
  . $steps([
      ['Aktifkan lisensi di produk', 'Di form produk, aktifkan opsi lisensi dan tentukan jumlah <strong>seat</strong> (maks aktivasi) serta masa berlaku (opsional).'],
      ['Key otomatis terbit', 'Saat order dibayar, license key dibuat otomatis dan dikirim ke buyer.'],
      ['Kelola lisensi', 'Buka ' . $mono('/admin/licenses') . ' → filter status, cari berdasarkan key / email / nama.'],
      ['Detail & aksi', 'Buka detail (' . $mono('/admin/licenses/{id}') . ') untuk melihat aktivasi per domain/device, log API, lalu <strong>Suspend</strong> / <strong>Revoke</strong> bila perlu.'],
  ])
  . $h3('Status Lisensi')
  . $badges(['Active', 'Suspended', 'Revoked', 'Expired'])
  . $info('<strong>Info:</strong> <strong>Seat</strong> = jumlah domain/device yang boleh mengaktifkan satu key. Buyer dapat melihat license miliknya di halaman akun mereka.')
);

echo $section('api-keys-overview',
  $L('Status Integrasi', 'Status Integrasi', 'Integration Status'),
  $L('Pantau status semua integrasi (pembayaran, email, WhatsApp, tracking) dalam satu halaman.', 'Pantau status semua integrasi.', 'Monitor all integration statuses in one place.'),
  '<p class="mb-4">Buka ' . $mono('/admin/api-keys-overview') . ' untuk melihat ringkasan integrasi yang <strong>Aktif / Nonaktif</strong>, dikelompokkan per tipe:</p>'
  . $badges(['Payment (Duitku, Xendit, Moota)', 'Email (Mailketing)', 'WhatsApp (5 provider)', 'Tracking (Pixel/CAPI)'])
  . '<p class="mb-4">Halaman ini juga menampilkan <strong>log terbaru</strong> dari integrasi & transaksi pembayaran — berguna untuk cepat mendiagnosis integrasi mana yang belum tersambung.</p>'
  . $info('<strong>Tips:</strong> Jadikan halaman ini cek rutin setelah mengubah kredensial gateway/provider untuk memastikan semuanya hijau.')
);

// ═══════════════════════ SISTEM & TIM ═══════════════════════

echo $section('staff',
  $L('Staff &amp; Akses', 'Staf &amp; Akses', 'Staff &amp; Access'),
  $L('Akun staff dengan akses panel admin terbatas.', 'Akaun staf dengan akses terhad.', 'Staff accounts with limited admin access.'),
  $steps([
      ['Buka Staff', 'Akses ' . $mono('/admin/staff') . ' (khusus admin utama) → <strong>Tambah Staff</strong>.'],
      ['Buat akun', 'Isi nama, email, password staff.'],
      ['Pilih permission', 'Centang akses: Produk, Transaksi, Users, Konten, Kupon, Affiliate, Download, Email Sequence, Sertifikat, Abandoned Cart, Broadcast, dll.'],
  ])
  . $warn('<strong>Batasan:</strong> Staff <strong>tidak bisa</strong> mengubah Settings, Branding, License, Integrasi, dan tidak bisa mengelola staff lain. Membuka menu tanpa izin akan dialihkan ke halaman No Access.')
);

echo $section('users',
  $L('Users &amp; Impersonate', 'Pengguna &amp; Impersonate', 'Users &amp; Impersonate'),
  $L('Kelola akun pengguna dan masuk sebagai user untuk troubleshooting.', 'Urus akaun pengguna.', 'Manage user accounts.'),
  $steps([
      ['Buka Users', 'Akses ' . $mono('/admin/users') . '.'],
      ['Kelola akun', 'Lihat detail, ubah status (aktif/nonaktif), dan atur akses produk user.'],
      ['Impersonate', 'Gunakan fitur <strong>Impersonate</strong> untuk login sebagai user tertentu — berguna saat membantu/troubleshoot keluhan akses.'],
  ])
  . $warn('<strong>Perhatian:</strong> Akhiri sesi impersonate setelah selesai. Gunakan hanya untuk dukungan, bukan bertindak atas nama user tanpa izin.')
);

echo $section('transaksi',
  $L('Transaksi &amp; Invoice', 'Transaksi &amp; Invois', 'Transactions &amp; Invoice'),
  $L('Pantau order, konfirmasi pembayaran, invoice PDF, dan masa berlaku order.', 'Pantau order & invois.', 'Manage orders & invoices.'),
  $steps([
      ['Buka Transaksi', 'Akses ' . $mono('/admin/transactions') . ' untuk daftar semua order.'],
      ['Detail & konfirmasi', 'Buka detail order untuk konfirmasi pembayaran manual (Manual Transfer) dan aktivasi akses buyer.'],
      ['Invoice PDF', 'Unduh/lihat invoice dalam format PDF dari halaman detail transaksi.'],
  ])
  . $h3('Kolom Affiliate')
  . '<p class="mb-3">Daftar transaksi punya kolom <strong>Affiliate</strong> — menampilkan nama affiliate perujuk order (dari kode referral), atau <strong>&ldquo;Langsung&rdquo;</strong> bila bukan dari affiliate. Memudahkan melihat sumber tiap penjualan sekilas.</p>'
  . $h3('Input Transaksi Manual')
  . '<p class="mb-3">Tombol <strong>Input Transaksi Manual</strong> membuat order untuk pembeli tanpa lewat checkout (mis. pembayaran di luar sistem, migrasi data). Bisa sekalian pilih produk, nominal, status, backdate masa berlaku, dan senyapkan notifikasi.</p>'
  . $steps([
      ['Assign komisi ke affiliate (opsional)', 'Pada form manual, cari &amp; pilih affiliate lewat kolom <strong>Affiliate</strong>. Sistem otomatis mencatat <strong>komisi pending</strong> untuk affiliate itu (rate mengikuti Aturan Komisi / kolom produk). Menutup celah lama: transaksi manual dulu tak pernah memberi komisi.'],
      ['Anti self-referral', 'Tidak bisa meng-assign komisi ke akun pembeli itu sendiri.'],
  ])
  . $h3('Edit Transaksi')
  . '<p class="mb-3">Dari halaman detail (khusus <strong>admin</strong>), tombol <strong>Edit</strong> membuka form untuk mengubah <strong>nama, email, WhatsApp, harga, status,</strong> dan <strong>affiliate</strong> sebuah order — berguna memperbaiki salah ketik atau salah atribusi.</p>'
  . $ul([
      'Ubah <strong>harga</strong> (meski sudah lunas) → komisi yang <strong>belum dibayar</strong> otomatis dihitung ulang dari harga baru; komisi yang sudah <strong>dibayar</strong> dilindungi.',
      'Ganti/hapus <strong>affiliate</strong> → komisi lama di-<em>reverse</em>, komisi baru dibuat pending. <strong>Diblokir</strong> bila order sudah punya komisi berstatus <em>dibayar</em>.',
      'Ganti <strong>status</strong> memakai pipeline yang sama dengan konfirmasi biasa (aktivasi/pencabutan akses &amp; komisi konsisten).',
      'Email divalidasi unik antar akun.',
  ])
  . $h3('Hapus Massal Transaksi Dibatalkan')
  . '<p class="mb-3">Untuk merapikan daftar, transaksi berstatus <strong>Dibatalkan</strong> bisa dicentang lalu dihapus massal (tombol muncul kontekstual di bar seleksi). Guard ketat di server: <strong>hanya</strong> order status <em>cancelled</em> yang bisa terhapus — order lunas/pending tidak akan pernah ikut terhapus.</p>'
  . $h3('Order Expiry (Masa Berlaku Order)')
  . '<p class="mb-3">Atur ' . $mono('order_expiry_hours') . ' di ' . $mono('/admin/settings') . '. Order pending yang melewati batas akan otomatis kedaluwarsa.</p>'
  . $info('<strong>Info:</strong> Set ke <strong>0</strong> untuk menonaktifkan kedaluwarsa otomatis (order pending tidak akan dibatalkan sistem).')
);

echo $section('google-oauth',
  $L('Google OAuth', 'Google OAuth', 'Google OAuth'),
  $L('Izinkan user login dengan akun Google (opsional).', 'Benarkan log masuk dengan Google.', 'Allow sign in with Google (optional).'),
  $info('<strong>Info:</strong> Fitur ini <strong>OFF</strong> secara default — aktifkan di ' . $mono('/admin/settings') . ' (' . $mono('google_oauth_enabled') . ').')
  . $steps([
      ['Buat project', 'Buka ' . $mono('console.cloud.google.com') . ' → buat project baru.'],
      ['Buat credentials', 'APIs &amp; Services → Credentials → <strong>Create OAuth 2.0 Client ID</strong>.'],
      ['Application type', 'Pilih <strong>Web application</strong>.'],
      ['Redirect URI', 'Tambahkan Authorized redirect URI (di bawah).'],
      ['Isi di Averion', $mono('/admin/settings') . ' → isi Client ID + Client Secret → aktifkan toggle.'],
  ])
  . $code('https://domain-kamu.com/auth/google/callback')
  . $warn('<strong>Catatan:</strong> Admin tidak bisa login via Google (by design) — gunakan email &amp; password.')
  . $info('<strong>Info:</strong> User dengan email yang sama akan di-merge otomatis.')
);

echo $section('pwa',
  $L('PWA — Install sebagai Aplikasi', 'PWA — Pasang sebagai Aplikasi', 'PWA — Install as App'),
  $L('Pembeli/member bisa memasang situsmu sebagai aplikasi di HP/desktop (white-label).', 'Pelanggan boleh pasang laman sebagai aplikasi.', 'Let buyers install your site as an app.'),
  $h3('Apa itu')
  . '<p class="mb-4">Averion mendukung <strong>PWA (Progressive Web App)</strong>: pengunjung/member bisa <strong>memasang</strong> situsmu sebagai aplikasi di layar HP atau desktop — lengkap dengan <strong>nama &amp; ikon brand-mu</strong> (white-label). Dibuka layaknya aplikasi biasa (jendela sendiri, tanpa address bar).</p>'
  . $ul([
      'Manifest dibuat <strong>dinamis</strong> mengikuti nama &amp; warna brand; ikon di-generate otomatis dari logo/ikon yang kamu set.',
      'Tombol <strong>Install</strong> muncul otomatis di topbar bila browser mendukung (Chrome/Edge/Android). Di iPhone lewat Safari → <strong>Bagikan → Tambah ke Layar Utama</strong>.',
      'Sifatnya <strong>installable</strong> saja — belum termasuk mode offline atau push notification.',
  ])
  . $steps([
      ['Atur nama & ikon', 'Nama app = <strong>Nama Aplikasi</strong> di ' . $mono('/admin/branding') . '. Ikon PWA bisa kamu unggah di ' . $mono('/admin/branding') . ' (bila kosong, dipakai logo situs).'],
      ['Nyalakan / matikan', 'Toggle <strong>PWA</strong> di ' . $mono('/admin/branding') . ' (bawaan: <strong>aktif</strong>).'],
  ])
  . $info('Karena memakai ikon &amp; nama brand pembeli, tiap instalasi tampil sebagai aplikasi milik mereka sendiri — bukan &ldquo;Averion&rdquo;.')
);

echo $section('member-online',
  $L('Member Sedang Online', 'Ahli Sedang Online', 'Members Online Now'),
  $L('Indikator realtime berapa member yang sedang membuka area member.', 'Penunjuk realtime ahli yang sedang dalam talian.', 'Realtime indicator of members currently active.'),
  $h3('Apa itu')
  . '<p class="mb-4">Di <strong>Dashboard admin</strong>, kartu <strong>Active Members</strong> menampilkan indikator <strong>&ldquo;X sedang online&rdquo;</strong> (titik hijau berdenyut) — jumlah member yang <strong>sedang membuka area member</strong> saat ini.</p>'
  . $ul([
      'Dihitung dari aktivitas <strong>2 menit terakhir</strong>, per-user (satu member dihitung sekali walau membuka banyak tab).',
      'Hanya menghitung <strong>member (role user)</strong> yang sedang di area member; admin/staff/tamu tidak dihitung.',
      'Berjalan otomatis lewat <em>heartbeat</em> ringan di latar — tanpa setelan apa pun.',
  ])
  . $info('Berguna untuk membaca denyut aktivitas real-time: lonjakan setelah broadcast/peluncuran, atau jam-jam ramai member belajar.')
);

echo $section('update-sistem',
  $L('Update Sistem', 'Kemas Kini Sistem', 'System Update'),
  $L('Update Averion ke versi terbaru dengan aman.', 'Kemas kini ke versi terkini.', 'Update Averion safely.'),
  $h3('Sebelum Update (wajib)')
  . $ul([
      '<strong>Backup Database dulu</strong> — buka ' . $mono('/admin/backups') . ' → <strong>Backup Sekarang</strong> (lihat topik Backup Database). Ini jaring pengaman utama.',
      'Pastikan <strong>tidak ada transaksi / pembayaran</strong> sedang berjalan.',
  ])
  . $h3('Langkah Update')
  . $steps([
      ['Cek versi', 'Buka ' . $mono('/admin/update') . ' → <strong>Cek Update</strong> (menghubungi server lisensi).'],
      ['Lihat changelog', 'Jika ada update, info versi & changelog ditampilkan.'],
      ['Update sekarang', 'Klik <strong>Update Sekarang</strong> → konfirmasi → proses otomatis.'],
      ['Proses', 'Download → verifikasi → backup otomatis → apply file → jalankan migrasi database → selesai.'],
  ])
  . '<p class="mb-3">Averion membuat <strong>snapshot file otomatis</strong> tepat sebelum menerapkan update. Lokasi utama di luar folder aplikasi (' . $mono('backups/') . '); bila hosting membatasi akses, fallback ke ' . $mono('storage/update-backups/') . '. Retensi mempertahankan tiga snapshot terbaru ditambah snapshot sukses terakhir bila lebih lama; pembersihan lama berjalan bertahap lewat cron. Backup database di menu Backup punya retensi terpisah. ' . $mono('config.php') . ' &amp; folder ' . $mono('uploads/') . ' <strong>tidak</strong> ditimpa.</p>'
  . $h3('Pemberitahuan otomatis saat ada update')
  . $ul([
      'Sistem <strong>mengecek update sendiri 2&times; sehari</strong> — tidak perlu cek manual untuk tahu ada rilis baru.',
      'Saat versi baru tersedia: tanda <strong>!</strong> di menu Update Sistem, kartu pemberitahuan kecil di pojok dashboard (bisa ditunda), dan <strong>email ke admin</strong> — sekali per versi.',
      'Bila update melompati beberapa versi, seluruh perubahan database versi di antaranya tetap dijalankan berurutan otomatis.',
  ])
  . $h3('Kalau Update Gagal / Status "Partial"')
  . '<p class="mb-3">Kadang <strong>file berhasil ter-update</strong> tetapi <strong>migrasi database gagal</strong> (mis. karena versi database hosting berbeda). Panel menandai status <strong>"Partial"</strong>: nomor versi terlihat sudah baru, tapi sebagian struktur database belum berubah — sehingga sebagian fitur (mis. Modul &amp; Materi) bisa menampilkan error.</p>'
  . $warn('<strong>JANGAN menimpa / me-restore database dengan backup lama</strong> untuk "memperbaiki". Justru sebaliknya: database jadi makin tertinggal dari file versi baru → makin banyak fitur error. Ini kesalahan yang paling sering &amp; paling merusak.')
  . $steps([
      ['1. Backup dulu', 'Buat Backup Database sebelum melakukan apa pun.'],
      ['2. Catat pesan error', 'Salin pesan error migrasi yang tampil di panel Update (biasanya menyebut nama file migrasi + kode error). Screenshot boleh.'],
      ['3. Hubungi support', 'Kirim pesan error itu ke support. Kamu akan diberi <strong>SQL pemulihan yang aman (idempoten)</strong> untuk menyamakan struktur database tanpa merusak data.'],
  ])
  . $info('<strong>Jangan</strong> menjalankan SQL sembarangan atau mengubah struktur database sendiri — skema portal saling terkait lewat <em>foreign key</em>, perbaikan yang salah bisa memperparah. Biarkan support memandu.')
  . $warn('<strong>Perhatian:</strong> Pastikan tidak ada transaksi berjalan saat update.')
);

// ═══════════════════════ DEVELOPER / API ═══════════════════════

$mBadge = function (string $m): string {
    $c = $m === 'GET'
        ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
        : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400';
    return '<span class="align-middle text-xs px-2 py-0.5 rounded ' . $c . ' font-mono font-bold">' . $m . '</span>';
};
$ptable = function (array $rows): string {
    $o = '<div class="overflow-x-auto mb-5"><table class="w-full text-sm border border-gray-200 dark:border-gray-800 rounded-lg overflow-hidden">'
       . '<thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">'
       . '<th class="text-left px-3 py-2 font-semibold">Parameter</th><th class="text-left px-3 py-2 font-semibold">Tipe</th><th class="text-left px-3 py-2 font-semibold">Wajib</th><th class="text-left px-3 py-2 font-semibold">Keterangan</th></tr></thead>'
       . '<tbody class="divide-y divide-gray-100 dark:divide-gray-800">';
    foreach ($rows as [$p, $t, $req, $desc]) {
        $reqBadge = $req
            ? '<span class="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">wajib</span>'
            : '<span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">opsional</span>';
        $o .= '<tr><td class="px-3 py-2 font-mono text-xs text-gray-900 dark:text-white">' . $p . '</td>'
            . '<td class="px-3 py-2 text-gray-500">' . $t . '</td>'
            . '<td class="px-3 py-2">' . $reqBadge . '</td>'
            . '<td class="px-3 py-2 text-gray-600 dark:text-gray-300">' . $desc . '</td></tr>';
    }
    return $o . '</tbody></table></div>';
};
$pre = fn(string $body, string $color = 'text-gray-300'): string =>
    '<div class="bg-gray-900 rounded-xl p-4 mb-5 overflow-x-auto"><pre class="' . $color . ' text-xs font-mono leading-relaxed">' . $body . '</pre></div>';

$myBodies['api-developer'] =
  $h3('Gambaran Umum')
  . '<p class="mb-4">Endpoint ini dipanggil oleh perisian/aplikasi milik pembeli (server-to-server) untuk melindungi produk digital yang anda jual. Semua endpoint:</p>'
  . $ul([
      'Bersifat <strong>awam</strong> — tanpa log masuk, tanpa CSRF.',
      'Sentiasa membalas <strong>JSON</strong> (' . $mono('Content-Type: application/json') . ').',
      'Dihadkan <strong>60 request / minit setiap IP</strong> (melebihi → ' . $mono('429') . ').',
      'Direkodkan di log per-lesen (' . $mono('/admin/licenses/{id}') . ') untuk audit.',
  ])
  . $h3('Konsep')
  . $ptable([
      ['license_key', 'string', true,  'Kunci unik setiap pembelian, format ' . $mono('AVRN-XXXX-XXXX-XXXX') . '.'],
      ['string',      'string', true,  'Identifier unik instance pembeli: domain, IP, atau device id (maks 255 aksara).'],
      ['seat',        'int',    false, 'Bilangan identifier yang boleh aktif serentak (' . $mono('used_seats') . ' / ' . $mono('max_seats') . ').'],
      ['expires_at',  'datetime', false, 'Tarikh tamat tempoh lesen; ' . $mono('null') . ' = selamanya.'],
  ])
  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'
  . $h3($mBadge('POST') . ' &nbsp;' . $mono('/api/license/activate'))
  . '<p class="mb-3">Mengaktifkan satu seat untuk sesebuah identifier. <strong>Idempoten</strong>: memanggil semula untuk identifier yang sama tidak menambah seat, hanya mengemas kini data.</p>'
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Body (JSON / form-data)</p>'
  . $ptable([
      ['license_key', 'string', true,  'License key yang akan diaktifkan.'],
      ['string',      'string', true,  'Identifier unik instance pembeli.'],
      ['app_version', 'string', false, 'Versi aplikasi pembeli (maks 20 aksara) — untuk rekod.'],
  ])
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Contoh Request</p>'
  . $pre('curl -X POST https://domain-anda.com/api/license/activate \
  -H "Content-Type: application/json" \
  -d \'{"license_key":"AVRN-1234-5678-9012","string":"buyerdomain.com","app_version":"1.0.0"}\'', 'text-green-400')
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Berjaya (200)</p>'
  . $pre('{
  "success": true,
  "message": "Aktivasi berhasil",
  "data": {
    "license_key":  "AVRN-1234-5678-9012",
    "product":      "Nama Produk",
    "status":       "active",
    "seats_used":   1,
    "seats_max":    3,
    "expires_at":   null,
    "activated_at": "2026-06-29 10:00:00"
  }
}')
  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'
  . $h3($mBadge('GET') . ' &nbsp;' . $mono('/api/license/validate'))
  . '<p class="mb-3">Memeriksa sama ada sesebuah identifier masih sah & aktif. Sesuai dipanggil secara berkala oleh aplikasi pembeli.</p>'
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Query Parameter</p>'
  . $ptable([
      ['license_key', 'string', true, 'License key yang disemak.'],
      ['string',      'string', true, 'Identifier yang disemak.'],
  ])
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Contoh Request</p>'
  . $pre('GET https://domain-anda.com/api/license/validate?license_key=AVRN-1234-5678-9012&amp;string=buyerdomain.com', 'text-green-400')
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Sah (200)</p>'
  . $pre('{ "valid": true, "status": "active", "product": "Nama Produk", "expires_at": null, "seats_used": 1, "seats_max": 3 }')
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Belum Diaktifkan (200)</p>'
  . $pre('{ "valid": false, "status": "not_activated", "message": "Identifier ini belum diaktifkan" }')
  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'
  . $h3($mBadge('POST') . ' &nbsp;' . $mono('/api/license/deactivate'))
  . '<p class="mb-3">Melepaskan slot seat untuk sesebuah identifier, supaya kunci boleh dipindahkan ke domain/peranti lain.</p>'
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Body (JSON / form-data)</p>'
  . $ptable([
      ['license_key', 'string', true, 'License key berkaitan.'],
      ['string',      'string', true, 'Identifier yang dilepaskan.'],
  ])
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Berjaya (200)</p>'
  . $pre('{ "success": true, "message": "Aktivasi berhasil dihapus. Slot dapat digunakan di device/domain lain." }')
  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'
  . $h3('Kod Status & Error')
  . $ptable([
      ['200', 'OK',           false, 'Berjaya, atau valid:false dengan status (cth. ' . $mono('not_activated') . ').'],
      ['400', 'bad_request',  false, 'Parameter ' . $mono('license_key') . ' / ' . $mono('string') . ' kosong.'],
      ['403', 'suspended / revoked / expired', false, 'Lesen disuspend, direvoke, atau sudah tamat tempoh.'],
      ['403', 'seats_full',   false, 'Kuota pengaktifan penuh (' . $mono('used_seats &ge; max_seats') . ').'],
      ['404', 'not_found',    false, 'License key tidak dijumpai.'],
      ['405', '—',            false, 'Kaedah salah (activate/deactivate wajib POST).'],
      ['429', 'rate_limited', false, 'Melebihi 60 request/minit setiap IP.'],
      ['500', 'error',        false, 'Ralat sistem.'],
  ])
  . $h3('Amalan Terbaik')
  . $ul([
      'Panggil <strong>activate</strong> sekali semasa pemasangan/pengaktifan di pihak pembeli.',
      'Panggil <strong>validate</strong> secara berkala (cth. harian) dan <strong>cache</strong> hasilnya — jangan validasi pada setiap request.',
      'Panggil <strong>deactivate</strong> semasa uninstall / pindah domain supaya seat kembali.',
      'Guna ' . $mono('string') . ' yang stabil & unik setiap instance (domain utama atau machine id).',
      'Tangani ' . $mono('429') . ' dengan jeda/backoff sebelum mencuba lagi.',
  ])
  . $warn('<strong>Perhatian:</strong> Endpoint ini awam — jangan letak data sensitif di parameter. Pengesahan dilakukan berdasarkan ' . $mono('license_key') . ' + ' . $mono('string') . ' sahaja.');

echo $section('backup-db',
  $L('Backup Database', 'Sandaran Pangkalan Data', 'Database Backup'),
  $L('Backup otomatis harian seluruh database — unduh salinannya secara berkala.', 'Sandaran automatik harian seluruh pangkalan data.', 'Automatic daily database backups.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Averion mem-backup <strong>seluruh database</strong> (struktur + data) otomatis <strong>sekali sehari</strong> ke file ' . $mono('.sql.gz') . ' di folder ' . $mono('backups/db') . ' — di luar folder web bila hosting mengizinkan, atau terlindungi ' . $mono('.htaccess') . ' bila tidak. Tanpa perlu setup cron. Backup lama dihapus otomatis sesuai <strong>retensi</strong> (default 7 hari).</p>'
  . $steps([
      ['Buka halaman', 'Akses ' . $mono('/admin/backups') . ' (menu <strong>Backup Database</strong> — khusus akun admin, staff tidak bisa).'],
      ['Backup manual', 'Klik <strong>Backup Sekarang</strong> kapan pun — mis. sebelum update sistem atau perubahan besar.'],
      ['Download', 'Klik <strong>Download</strong> pada file yang diinginkan, simpan di komputer/cloud pribadi kamu.'],
      ['Atur', 'Aktif/nonaktifkan backup otomatis &amp; ubah retensi di bagian <strong>Pengaturan</strong>.'],
  ])
  . $h3('Memulihkan (restore) backup')
  . '<p class="mb-3">Restore dipakai untuk <strong>pemulihan bencana</strong> — mis. data terhapus / rusak — pada versi Averion yang <strong>SAMA</strong> dengan backup.</p>'
  . $ul([
      'Ekstrak file ' . $mono('.sql.gz') . ' (jadi ' . $mono('.sql') . ') dengan WinRAR/7-Zip atau ' . $mono('gunzip') . '.',
      'Buka <strong>phpMyAdmin</strong> di hosting → pilih database portal → tab <strong>Import</strong> → pilih file → Go.',
      'Restore <strong>MENIMPA</strong> data sekarang dengan isi backup — pastikan memilih file yang benar.',
  ])
  . $warn('<strong>JANGAN me-restore backup LAMA setelah update versi.</strong> Backup lama berisi struktur database <em>versi lama</em>; bila ditimpakan ke file yang sudah <em>versi baru</em>, database jadi tertinggal dari kode → sebagian fitur error. Kalau update bermasalah, <strong>jangan timpa database</strong> — ikuti panduan "Status Partial" di topik <strong>Update Sistem</strong> (hubungi support).')
  . $warn('<strong>Penting:</strong> Backup tersimpan di server yang sama — TIDAK melindungi dari kehilangan server/hosting total. Biasakan <strong>download salinannya</strong> secara berkala (mingguan) ke tempat lain.')
);

echo $section('data-import',
  $L('Import Data', 'Import Data', 'Data Import'),
  $L('Pindahkan user &amp; riwayat transaksi dari platform lain (mis. Sejoli) ke Averion, lengkap dengan omzet dan akses produk.', 'Pindahkan pengguna &amp; sejarah transaksi dari platform lain ke Averion.', 'Bulk-import users & transaction history from other platforms.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Halaman ' . $mono('/admin/data-import') . ' (khusus admin) memigrasikan data massal dari platform lain — saat ini <strong>Sejoli (WordPress)</strong> — via file CSV/TSV hasil ekspor. Setiap baris dicatat sebagai user + order + payment + item order, sehingga omzet &amp; riwayat transaksi lama langsung tampil di dashboard Averion. Pemberian akses produk bersifat <strong>otomatis dan opsional</strong> — tidak wajib membuat produknya dulu.</p>'
  . $h3('Kolom yang dikenali')
  . $code('order_number/INV, created_at, product_name, email, name, phone, grand_total, quantity, affiliate_name')
  . $steps([
      ['Ekspor dari Sejoli', 'Unduh data order (status selesai/completed) dari Sejoli dalam format CSV atau TSV.'],
      ['Unggah file', 'Seret file ke dropzone atau klik untuk memilih — sistem otomatis mendeteksi delimiter &amp; header.'],
      ['Atur opsi', '<strong>Beri akses otomatis</strong> (cocokkan nama produk Sejoli ↔ produk portal), <strong>Ikutkan transaksi Rp0</strong> (order gratis/bonus), <strong>Catat produk tak dikenal</strong> (tetap dicatat via placeholder, akses menyusul).'],
      ['Pratinjau (wajib)', 'Klik <strong>Pratinjau</strong> — dry-run menampilkan ringkasan (total omzet, jumlah transaksi &amp; user, produk yang cocok/tidak) TANPA menulis apa pun ke database.'],
      ['Jalankan Import', 'Setelah pratinjau sesuai, klik <strong>Jalankan Import</strong> — data ditulis bertahap (chunked) dengan progress bar real-time.'],
  ])
  . $h3('Produk belum dibuat?')
  . '<p class="mb-4">Transaksi dengan nama produk yang belum ada di Averion tetap dicatat (omzet ikut terhitung) memakai produk placeholder tersembunyi. Setelah kamu membuat produk aslinya, <strong>unggah &amp; jalankan file yang sama lagi</strong> — akses member diberikan secara <strong>retroaktif</strong> ke transaksi yang tadinya belum cocok.</p>'
  . $info('<strong>Senyap total:</strong> Proses import <strong>tidak mengirim</strong> notifikasi email/WhatsApp, tidak memicu komisi affiliate, social proof, atau tracking pixel — murni tulis data historis.')
  . $info('<strong>Idempoten:</strong> Import yang sama boleh dijalankan berkali-kali dengan aman — order yang sudah pernah masuk otomatis dilewati (dideteksi dari nomor order), tidak ada duplikasi.')
  . $info('<strong>Login user hasil import:</strong> Password lama dari platform sumber tidak ikut terbawa (hash tidak kompatibel) — user login via <strong>Google</strong> atau <strong>OTP WhatsApp</strong> memakai email/nomor yang sama.')
  . $warn('<strong>Sebelum import besar:</strong> Buat <strong>Backup Database</strong> terlebih dulu (lihat panduan di atas) sebagai jaring pengaman, meski proses ini sudah dirancang aman &amp; idempoten.')
);

echo $section('kesehatan-sistem',
  $L('Kesehatan Sistem', 'Kesihatan Sistem', 'System Health'),
  $L('Pantau proses otomatis (cron) &amp; kegagalan notifikasi — masalah senyap jadi kelihatan.', 'Pantau proses automatik &amp; kegagalan notifikasi.', 'Monitor cron & notification failures.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Halaman ' . $mono('/admin/system-health') . ' menampilkan dua hal yang biasanya gagal <em>tanpa ketahuan</em>: (1) apakah <strong>proses otomatis (cron)</strong> berjalan — penggerak email sequence, reminder, backup, dan waitlist launch; (2) <strong>notifikasi yang gagal terkirim</strong> beserta penyebabnya. Bila ada masalah, banner merah muncul otomatis di Dashboard dan badge di sidebar.</p>'
  . $h3('Status Cron')
  . $ul([
      '<strong>Hijau</strong> = cron berjalan &lt; 30 menit lalu. <strong>Kuning</strong> = 30 menit–3 jam. <strong>Merah</strong> = &gt; 3 jam / belum pernah.',
      'Auto-Cron menumpang kunjungan situs — situs sepi pengunjung membuat proses tertunda. Ini normal untuk toko baru.',
      'Solusi permanen: setup <strong>cron asli</strong> di cPanel (panduan + perintah lengkapnya tampil di halaman saat status tidak hijau — cukup copy-paste sekali).',
  ])
  . $h3('Notifikasi Gagal')
  . $steps([
      ['Lihat daftar', 'Semua kegagalan 30 hari terakhir tampil: event, channel, tujuan, dan pesan error dari provider.'],
      ['Perbaiki penyebab', 'Penyebab umum: token WhatsApp kedaluwarsa (perbarui di Integrasi), kuota email habis, atau nomor/email tujuan salah.'],
      ['Kirim Ulang', 'Setelah penyebab diperbaiki, klik <strong>Kirim Ulang</strong> — pesan asli dikirim ulang persis ke penerima yang sama.'],
  ])
  . $info('<strong>Info:</strong> Isi pesan yang gagal disimpan otomatis agar bisa dikirim ulang, lalu dihapus setelah sukses. Log lama (&gt;90 hari) dibersihkan otomatis.')
);

echo $section('keamanan-2fa',
  $L('Keamanan Login (2FA)', 'Keselamatan Log Masuk (2FA)', 'Login Security (2FA)'),
  $L('Verifikasi 2 langkah dengan Google Authenticator — password bocor pun akun tetap aman.', 'Pengesahan 2 langkah dengan Google Authenticator.', 'Two-factor authentication with Google Authenticator.'),
  $h3('Cara kerja')
  . '<p class="mb-4">Setelah 2FA aktif, login akun kamu butuh password <strong>plus kode 6 digit</strong> yang berganti tiap 30 detik dari aplikasi authenticator di HP (Google Authenticator, Authy, Microsoft Authenticator, dll). Bekerja offline, tanpa SMS/WhatsApp. Berlaku untuk akun <strong>admin &amp; staff</strong>, diaktifkan per akun (opsional).</p>'
  . $steps([
      ['Buka pengaturan', 'Klik avatar kanan atas → <strong>Profil Saya</strong> → kartu <strong>Keamanan — Verifikasi 2 Langkah</strong> → <strong>Aktifkan</strong>.'],
      ['Tambahkan ke aplikasi', 'Di HP, buka Google Authenticator → tombol <strong>+</strong> → <strong>Masukkan kunci penyiapan</strong> → salin kunci yang tampil di layar. (Membuka halaman ini di HP? Cukup ketuk link yang tersedia.)'],
      ['Konfirmasi', 'Masukkan kode 6 digit yang tampil di aplikasi → <strong>Aktifkan 2FA</strong>.'],
      ['SIMPAN backup codes', '8 kode darurat sekali-pakai tampil SEKALI — salin &amp; simpan di tempat aman (catatan/password manager). Ini penyelamat kalau HP hilang.'],
  ])
  . $h3('Saat login')
  . $ul([
      'Setelah password benar, muncul halaman <strong>Verifikasi 2 Langkah</strong> — masukkan kode dari aplikasi (atau salah satu backup code).',
      'Centang <strong>Ingat perangkat ini 30 hari</strong> agar komputer pribadi tidak ditanya kode setiap login.',
      'Berlaku di semua cara login: password, WhatsApp OTP, maupun Google (staff).',
  ])
  . $h3('Kehilangan akses?')
  . $ul([
      'HP hilang / aplikasi terhapus → login pakai salah satu <strong>backup code</strong>, lalu nonaktifkan &amp; aktifkan ulang 2FA dengan HP baru.',
      'Backup code ikut habis/hilang → nonaktifkan lewat database: buka <strong>phpMyAdmin</strong> → tabel ' . $mono('users') . ' → set ' . $mono('totp_enabled') . ' = 0 pada akun kamu.',
  ])
  . $warn('<strong>Penting:</strong> Pastikan jam HP akurat (mode otomatis/jaringan) — kode TOTP berbasis waktu; jam meleset membuat kode selalu salah.')
);

echo $section('activity-logs',
  $L('Log Aktivitas', 'Log Aktiviti', 'Activity Logs'),
  $L('Jejak audit semua aksi penting di panel — siapa melakukan apa &amp; kapan.', 'Jejak audit semua tindakan penting.', 'Audit trail of all important actions.'),
  $h3('Apa yang dicatat')
  . $ul([
      'Perubahan pengaturan, gateway pembayaran, &amp; template notifikasi',
      'Pembayaran sukses via gateway, konfirmasi &amp; pembatalan transaksi',
      'Aksi lisensi, pengelolaan staff, &amp; perubahan akun user',
  ])
  . $h3('Cara pakai')
  . $steps([
      ['Buka Activity Logs', 'Akses ' . $mono('/admin/activity-logs') . '.'],
      ['Saring log', 'Filter berdasarkan peran (admin / user / system), kata kunci aksi, user tertentu, atau rentang tanggal.'],
      ['Telusuri', 'Gunakan untuk audit keamanan, melacak perubahan konfigurasi, atau menyelidiki keluhan pelanggan.'],
  ])
  . $info('<strong>Tips:</strong> Bila menemukan aktivitas mencurigakan dari akun staff, tinjau hak aksesnya di ' . $mono('/admin/staff') . '.')
);

echo $section('api-developer',
  $L('API License (Developer)', 'API Lesen (Pembangun)', 'License API (Developer)'),
  $L('Referensi lengkap endpoint publik untuk aktivasi, validasi, dan deaktivasi license key produk.', 'Rujukan penuh endpoint awam lesen.', 'Full reference for the public license endpoints.'),

  $h3('Gambaran Umum')
  . '<p class="mb-4">Endpoint ini dipanggil oleh software/aplikasi milik buyer (server-to-server) untuk memproteksi produk digital yang kamu jual. Semua endpoint:</p>'
  . $ul([
      'Bersifat <strong>publik</strong> — tanpa login, tanpa CSRF.',
      'Selalu membalas <strong>JSON</strong> (' . $mono('Content-Type: application/json') . ').',
      'Dibatasi <strong>60 request / menit per IP</strong> (melebihi → ' . $mono('429') . ').',
      'Dicatat di log per-lisensi (' . $mono('/admin/licenses/{id}') . ') untuk audit.',
  ])
  . $h3('Konsep')
  . $ptable([
      ['license_key', 'string', true,  'Kunci unik per pembelian, format ' . $mono('AVRN-XXXX-XXXX-XXXX') . '.'],
      ['string',      'string', true,  'Identifier unik instance buyer: domain, IP, atau device id (maks 255 char).'],
      ['seat',        'int',    false, 'Jumlah identifier yang boleh aktif sekaligus (' . $mono('used_seats') . ' / ' . $mono('max_seats') . ').'],
      ['expires_at',  'datetime', false, 'Tanggal kedaluwarsa lisensi; ' . $mono('null') . ' = selamanya.'],
  ])

  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'

  // ── 1. ACTIVATE ──
  . $h3($mBadge('POST') . ' &nbsp;' . $mono('/api/license/activate'))
  . '<p class="mb-3">Mengaktifkan satu seat untuk sebuah identifier. <strong>Idempoten</strong>: memanggil ulang untuk identifier yang sama tidak menambah seat, hanya memperbarui data.</p>'
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Body (JSON / form-data)</p>'
  . $ptable([
      ['license_key', 'string', true,  'License key yang akan diaktifkan.'],
      ['string',      'string', true,  'Identifier unik instance buyer.'],
      ['app_version', 'string', false, 'Versi aplikasi buyer (maks 20 char) — untuk pencatatan.'],
  ])
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Contoh Request</p>'
  . $pre('curl -X POST https://domain-kamu.com/api/license/activate \
  -H "Content-Type: application/json" \
  -d \'{"license_key":"AVRN-1234-5678-9012","string":"buyerdomain.com","app_version":"1.0.0"}\'', 'text-green-400')
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Sukses (200)</p>'
  . $pre('{
  "success": true,
  "message": "Aktivasi berhasil",
  "data": {
    "license_key":  "AVRN-1234-5678-9012",
    "product":      "Nama Produk",
    "status":       "active",
    "seats_used":   1,
    "seats_max":    3,
    "expires_at":   null,
    "activated_at": "2026-06-29 10:00:00"
  }
}')

  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'

  // ── 2. VALIDATE ──
  . $h3($mBadge('GET') . ' &nbsp;' . $mono('/api/license/validate'))
  . '<p class="mb-3">Memeriksa apakah sebuah identifier masih sah & aktif. Cocok dipanggil berkala oleh aplikasi buyer.</p>'
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Query Parameter</p>'
  . $ptable([
      ['license_key', 'string', true, 'License key yang dicek.'],
      ['string',      'string', true, 'Identifier yang dicek.'],
  ])
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Contoh Request</p>'
  . $pre('GET https://domain-kamu.com/api/license/validate?license_key=AVRN-1234-5678-9012&amp;string=buyerdomain.com', 'text-green-400')
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Valid (200)</p>'
  . $pre('{ "valid": true, "status": "active", "product": "Nama Produk", "expires_at": null, "seats_used": 1, "seats_max": 3 }')
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Belum Diaktivasi (200)</p>'
  . $pre('{ "valid": false, "status": "not_activated", "message": "Identifier ini belum diaktifkan" }')

  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'

  // ── 3. DEACTIVATE ──
  . $h3($mBadge('POST') . ' &nbsp;' . $mono('/api/license/deactivate'))
  . '<p class="mb-3">Melepas slot seat untuk sebuah identifier, sehingga key bisa dipindah ke domain/device lain.</p>'
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Body (JSON / form-data)</p>'
  . $ptable([
      ['license_key', 'string', true, 'License key terkait.'],
      ['string',      'string', true, 'Identifier yang dilepas.'],
  ])
  . '<p class="mb-2 font-medium text-gray-700 dark:text-gray-300">Respons Sukses (200)</p>'
  . $pre('{ "success": true, "message": "Aktivasi berhasil dihapus. Slot dapat digunakan di device/domain lain." }')

  . '<hr class="border-gray-100 dark:border-gray-800 my-6">'

  // ── Status codes ──
  . $h3('Kode Status & Error')
  . $ptable([
      ['200', 'OK',           false, 'Sukses, atau valid:false dengan status (mis. ' . $mono('not_activated') . ').'],
      ['400', 'bad_request',  false, 'Parameter ' . $mono('license_key') . ' / ' . $mono('string') . ' kosong.'],
      ['403', 'suspended / revoked / expired', false, 'Lisensi disuspend, direvoke, atau sudah kedaluwarsa.'],
      ['403', 'seats_full',   false, 'Kuota aktivasi penuh (' . $mono('used_seats &ge; max_seats') . ').'],
      ['404', 'not_found',    false, 'License key tidak ditemukan.'],
      ['405', '—',            false, 'Metode salah (activate/deactivate wajib POST).'],
      ['429', 'rate_limited', false, 'Melebihi 60 request/menit per IP.'],
      ['500', 'error',        false, 'Kesalahan sistem.'],
  ])

  . $h3('Praktik Terbaik')
  . $ul([
      'Panggil <strong>activate</strong> sekali saat instalasi/aktivasi di sisi buyer.',
      'Panggil <strong>validate</strong> berkala (mis. harian) dan <strong>cache</strong> hasilnya — jangan validasi di setiap request.',
      'Panggil <strong>deactivate</strong> saat uninstall / pindah domain agar seat kembali.',
      'Gunakan ' . $mono('string') . ' yang stabil & unik per instance (domain utama atau machine id).',
      'Tangani ' . $mono('429') . ' dengan jeda/backoff sebelum mencoba lagi.',
  ])
  . $warn('<strong>Perhatian:</strong> Endpoint ini publik — jangan menaruh data sensitif di parameter. Verifikasi dilakukan berdasarkan ' . $mono('license_key') . ' + ' . $mono('string') . ' saja.')
);

// ─── Section: Integrasi App Eksternal (handoff kit developer) ───
$intgWhVal   = $intgWhSecret !== '' ? $intgWhSecret : 'BUAT_WEBHOOK_DULU_DI_INTEGRASI_WEBHOOK';
$intgEnvText = "# .env aplikasi tujuan\nAVERION_WEBHOOK_SECRET={$intgWhVal}\nAVERION_REDIRECT_SECRET={$intgRedirectSecret}";

$intgPromptWebhook = <<<'PROMPT'
Buatkan SATU endpoint API (webhook) di aplikasi Next.js (App Router) saya untuk
MENGAKTIFKAN AKSES MEMBER dari sistem penjualan Averion. Saat pembelian lunas,
Averion memanggil endpoint ini (server-ke-server) untuk memberi akses program ke
user berdasarkan EMAIL + KODE PROGRAM.

BUAT: route handler POST di `app/api/averion/grant-access/route.ts`.

KONTRAK REQUEST (persis seperti yang dikirim Averion):
- Method: POST
- Headers:
  - Content-Type: application/json
  - X-Averion-Event: order.paid
  - X-Averion-Timestamp: <unix detik>
  - X-Averion-Signature: sha256=<HMAC-SHA256 hex dari RAW BODY, key = secret>
- Body (JSON):
  {
    "event": "order.paid",
    "status": "active",
    "email": "pembeli@email.com",
    "name": "Nama Pembeli",
    "phone": "628xxxxxxxxxx",
    "program_code": "KODE_PROGRAM",
    "order_id": "INV-123",
    "timestamp": 1699999999
  }
  CATATAN: saat ini Averion HANYA mengirim status:"active". Nilai "revoked" (cabut
  akses saat refund) BELUM dikirim — boleh siapkan strukturnya, tapi jangan diandalkan.

KEAMANAN (wajib):
1. Baca RAW body (jangan di-parse dulu) untuk verifikasi signature.
2. Hitung sha256 = HMAC-SHA256(raw_body, process.env.AVERION_WEBHOOK_SECRET), lalu
   bandingkan dengan header X-Averion-Signature pakai timingSafeEqual. Tak cocok → 401.
3. Tolak bila selisih X-Averion-Timestamp dengan waktu server > 5 menit (anti replay) → 401.
4. Secret hanya di env AVERION_WEBHOOK_SECRET (jangan hardcode).

LOGIKA:
1. Validasi field wajib (email, program_code, status). Kurang → 400.
2. Cari program berdasarkan program_code. Tak ada → 404.
3. Cari user by email; kalau belum ada DAN status="active" → buat user (email + name +
   phone), set password sementara / kirim link set-password sesuai alur app saya.
4. status="active" → beri/aktifkan akses user ke program tsb.
5. IDEMPOTEN: kalau order_id sudah pernah diproses ATAU akses sudah aktif → tetap 200
   (jangan gandakan/gagalkan). Simpan order_id yang sudah diproses.

RESPONSE (JSON): sukses 200 {ok:true,...}; auth gagal 401; data kurang 400; program
tak ditemukan 404.

TAMBAHAN:
- Log tiap request (email, program_code, order_id, hasil) untuk audit.
- Endpoint harus live di URL yang nanti saya daftarkan di Averion (mis.
  https://APP-KAMU/api/averion/grant-access).
Sesuaikan bagian "cari/buat user" & "beri akses program" dengan skema DB app saya.
PROMPT;

$intgPromptLogin = <<<'PROMPT'
Buatkan SATU route auto-login di aplikasi Next.js (App Router) saya untuk MELOGIN-KAN
PEMBELI otomatis setelah bayar di Averion. Setelah lunas, browser pembeli diarahkan
ke route ini membawa token bertanda tangan; route memverifikasi token, membuat sesi
login, lalu mengantar ke dashboard — tanpa form login.

PENTING — beda dari webhook grant-access:
- Webhook (/api/averion/grant-access): server→server, MEMBERI AKSES, secret = AVERION_WEBHOOK_SECRET.
- Route ini (/api/averion/login): browser→server, HANYA LOGIN, secret = AVERION_REDIRECT_SECRET.
  DUA SECRET INI BERBEDA. Jangan tertukar.

BUAT: route handler GET di `app/api/averion/login/route.ts`.
INPUT: /api/averion/login?token=<JWT>
  token = JWT HS256 ditandatangani AVERION_REDIRECT_SECRET, dengan klaim:
  { "sub": "email", "name": "...", "order_id": "...", "program_code": "...",
    "iat": <detik>, "exp": <detik — kedaluwarsa 15 menit> }

KEAMANAN:
1. Verifikasi JWT dengan pustaka `jose` (jwtVerify) + AVERION_REDIRECT_SECRET.
   Tak valid → redirect /login?error=invalid.
2. Tolak bila kedaluwarsa (cek exp; clockTolerance ~30 dtk) → /login?error=expired.
3. Secret hanya di env. Jangan pernah log token mentah.

LOGIKA:
1. Ambil email, name, order_id, program_code dari klaim yang sudah terverifikasi.
2. Cari user by email.
3. DITEMUKAN → buat sesi login (sesuai auth app: NextAuth/iron-session/cookie custom)
   → redirect 302 ke URL BERSIH (mis. /dashboard) TANPA token di URL.
4. BELUM DITEMUKAN (SERING TERJADI — lihat RACE) → JANGAN langsung gagal. Tampilkan
   halaman ringan "Mengaktifkan akunmu…" yang otomatis retry tiap 2 dtk s/d ~40 dtk;
   begitu user muncul → login + redirect ke /dashboard. Kalau tetap belum ada →
   redirect /login?email=<email>&notice=activating.

CATATAN RACE (penting): Averion mengirim webhook grant-access lewat ANTREAN (bisa
telat beberapa detik s/d ~1 menit), sedang redirect browser tiba hampir seketika.
Jadi route ini KEMUNGKINAN BESAR jalan SEBELUM webhook membuat akun — wajib tangani
kasus "user belum ada" seperti langkah 4.

ANTI REUSE (disarankan): tandai order_id yang sudah dipakai login (boleh pakai store
idempotensi webhook). Token yang sama tak membuat sesi baru lagi.

RESPONSE: route redirect (bukan JSON) — 302 ke /dashboard (sukses) atau /login?...
TAMBAHAN: log hasil (email, order_id, hasil) TANPA menuliskan token mentah.
Sesuaikan "cari user" & "buat sesi login" dengan mekanisme auth app saya.
PROMPT;

$intgPromptAdminPage = <<<'PROMPT'
Buatkan SATU halaman admin "Averion Integration" di aplikasi Next.js (App Router) saya
untuk MENGELOLA konfigurasi integrasi Averion (webhook pesanan + auto-login) dari satu
layar Settings — menggantikan pengeditan .env manual, plus memantau diagnostik auto-login.

BUAT:
- Halaman admin `app/(dashboard)/settings/integrations/averion/page.tsx` (proteksi dengan
  guard auth ADMIN app saya). Breadcrumb: Dashboard / Settings / Integrations / Averion.
- Server action / route POST untuk menyimpan konfigurasi (tombol "Save Configuration").
- Route `POST /api/averion/test-connection` untuk tombol "Test Connection".

MODEL DATA (simpan di DB — tabel `averion_integration` satu-baris atau settings key-value):
- webhook_secret       (HMAC-SHA256; SIMPAN TERENKRIPSI at-rest)
- redirect_secret      (JWT HS256;  SIMPAN TERENKRIPSI at-rest)
- target_program_code  (kode produk/program yang dipetakan, mis. BLUEPRINT_2_8M)
- (URL endpoint TIDAK disimpan — dihitung dari domain app.)

LAYOUT (header + 2 kartu config + tombol simpan + kartu diagnostik):

HEADER
- Judul "Averion Integration" + badge status: "CONNECTED" (hijau) bila webhook_secret &
  redirect_secret terisi, selain itu "NOT CONNECTED" (abu).
- Subjudul: "Kelola konfigurasi integrasi webhook pesanan dan auto-login instan dari
  checkout Averion."
- Pojok kanan atas: tombol "Test Connection".

KARTU 1 — "AVERION WEBHOOK" (badge CONNECTED bila webhook_secret terisi)
- Deskripsi: "Menerima payload event order.paid saat transaksi berhasil di Averion."
- Field READ-ONLY "Webhook Endpoint URL (pasang di Averion Admin)" =
  `${APP_URL}/api/averion/grant-access`, dengan tombol Salin. Helper: "Salin endpoint ini
  ke menu Settings -> Webhooks di portal."
- Field "Webhook Secret (HMAC-SHA256)" = input password (bertopeng) + tombol mata untuk
  lihat/sembunyikan. Bila sudah tersimpan: tampilkan badge "Terpasang (Terenkripsi)" dan
  BIARKAN field kosong; helper: "Kosongkan jika tidak ingin mengubah secret yang tersimpan."
  WRITE-ONLY: JANGAN pernah kirim secret tersimpan ke browser.
- Field "Target Program / Product Code" = input teks. Bila cocok dengan produk aktif di DB,
  tampilkan "Terhubung ke produk aktif di database".

KARTU 2 — "AVERION AUTO LOGIN (REDIRECT JWT)" (badge CONNECTED bila redirect_secret terisi)
- Deskripsi: "Memungkinkan pembeli langsung login otomatis ke member area setelah pembayaran
  di Averion Checkout."
- Field READ-ONLY "Auto Login Redirect URL" = `${APP_URL}/api/averion/login?token={token}`
  + tombol Salin. Helper: 'Tempel URL ini lengkap dengan ?token={token} ke kolom "Redirect
  Setelah Bayar" produk di Averion. Averion mengganti {token} dengan JWT auto-login secara
  otomatis; tanpa bagian itu pembeli tetap diminta login.'
- Field "Redirect Secret (JWT HS256)" = input password bertopeng + tombol mata; bila tersimpan
  tampilkan "Terpasang (Terenkripsi)"; WRITE-ONLY sama seperti webhook secret.

TOMBOL "Save Configuration"
- Simpan hanya field secret yang DIISI. Secret dibiarkan kosong = JANGAN ubah yang tersimpan.
- Enkripsi secret sebelum simpan. Setelah tersimpan, route grant-access & login membaca secret
  dari store ini (bukan lagi hardcode .env).

KARTU "DIAGNOSTIK AUTO-LOGIN" (10 percobaan terakhir)
- Sumber: tabel `averion_login_attempts` yang DIISI oleh route /api/averion/login setiap dipanggil.
- Kolom tabel: WAKTU | STATUS (badge SUCCESS hijau / FAILED merah) | TOKEN ("Ada"/"Tidak ada") |
  PARAMETER DITERIMA (nama query-param yang benar-benar diterima, mis. `token`, atau "-") |
  PENYEBAB / HASIL (mis. "Auto-login berhasil (resolved_via: provisioned_from_signed_token,
  user_id: 18)." untuk sukses, atau "Redirect tidak menyertakan token auto-login." untuk gagal).
- Footer: "Kolom Parameter Diterima menampilkan nama parameter yang benar-benar dikirim Averion
  pada redirect. Bila kosong atau tidak memuat token, masalahnya ada di konfigurasi redirect
  Averion, bukan di aplikasi ini."

PERBARUI ROUTE /api/averion/login AGAR MENCATAT (untuk mengisi diagnostik):
Tiap panggilan tulis 1 baris ke `averion_login_attempts`: waktu, ada/tidak token, nama param
diterima, status success/failed, penyebab. Untuk sukses simpan cara resolusi (resolved_via) +
user_id. JANGAN menuliskan token/secret mentah.

TEST CONNECTION
- Cek: webhook_secret & redirect_secret terisi, target_program_code cocok dengan produk di DB,
  route grant-access & login reachable. Balikan ok/gagal + detail; perbarui badge status.

KEAMANAN
- Halaman & semua action hanya untuk ADMIN.
- Secret disimpan TERENKRIPSI, tidak pernah dikirim balik ke klien (write-only), input bertopeng.
- Jangan pernah log token/secret mentah.
Sesuaikan penyimpanan DB, guard admin, dan nilai APP_URL dengan app saya.
PROMPT;

// Blok copyable (pre + tombol Salin)
$copyBlock = function (string $id, string $label, string $text, string $extraPreClass = ''): string {
    return '<div class="flex items-center justify-between gap-2 mb-2 mt-1">'
         . '<p class="font-medium text-gray-700 dark:text-gray-300">' . $label . '</p>'
         . '<button type="button" onclick="copyDoc(\'' . $id . '\', this)" class="text-xs font-semibold hover:underline shrink-0" style="color:var(--brand-color)">Salin</button>'
         . '</div>'
         . '<div class="bg-gray-900 rounded-xl p-4 mb-5 overflow-x-auto ' . $extraPreClass . '">'
         . '<pre id="' . $id . '" class="text-gray-200 text-xs font-mono leading-relaxed whitespace-pre-wrap">' . e($text) . '</pre></div>';
};

echo $section('integrasi-eksternal',
  $L('Integrasi Aplikasi Eksternal', 'Integrasi Aplikasi Luaran', 'External App Integration'),
  $L('Sambungkan aplikasi lain (mis. membership Next.js milikmu) ke ' . e($appName) . ' — beri akses &amp; auto-login pembeli otomatis saat pembelian lunas.',
     'Sambungkan aplikasi lain ke ' . e($appName) . '.', ''),

  $info('Panduan ini untuk <strong>developer aplikasi tujuan</strong>. Salin prompt siap-pakai di bawah, tempel ke AI coding tool (Claude Code / Cursor / v0), integrasi jadi. Nilai <span class="font-mono text-xs">.env</span> sudah terisi otomatis untuk instalasi ini.')

  . $h3('Cara kerja — 2 bagian (secret berbeda)')
  . $ul([
      '<strong>Webhook</strong> (server-ke-server) — <em>memberi akses</em> begitu pembelian lunas. Andal, tak bergantung browser pembeli. <span class="text-gray-400">Wajib.</span>',
      '<strong>Auto-login</strong> (browser) — <em>mengantar &amp; melogin-kan</em> pembeli ke app tujuan setelah bayar. <span class="text-gray-400">Opsional; aktif bila produk mengisi "Redirect Setelah Bayar" dengan placeholder ' . $mono('{token}') . '.</span>',
  ])

  . $copyBlock('intg-env', 'Langkah 1 — Nilai <span class="font-mono">.env</span> app tujuan', $intgEnvText)
  . ($intgWhSecret === '' ? $warn('Belum ada webhook. Buat dulu di <strong>Konfigurasi → Integrasi Webhook</strong> agar ' . $mono('AVERION_WEBHOOK_SECRET') . ' tersedia.') : '')
  . '<p class="mb-5 text-sm">' . $mono('AVERION_WEBHOOK_SECRET') . ' memverifikasi webhook (Langkah 2). ' . $mono('AVERION_REDIRECT_SECRET') . ' memverifikasi token auto-login (Langkah 3).</p>'

  . $copyBlock('intg-prompt-webhook', 'Langkah 2 — Prompt: Endpoint Webhook (beri akses)', $intgPromptWebhook, 'max-h-80 overflow-y-auto')
  . $copyBlock('intg-prompt-login', 'Langkah 3 — Prompt: Auto-Login Setelah Bayar (opsional)', $intgPromptLogin, 'max-h-80 overflow-y-auto')

  . '<p class="mb-2 mt-1 text-sm">Ingin mengelola kedua secret &amp; memantau auto-login dari <strong>satu halaman</strong> (bukan mengedit ' . $mono('.env') . ' manual)? Prompt berikut membuat halaman admin <strong>Averion Integration</strong> — dua kartu konfigurasi (Webhook + Auto-Login), tombol <em>Test Connection</em> &amp; <em>Save Configuration</em>, plus tabel <strong>Diagnostik Auto-Login</strong> yang menampilkan 10 percobaan terakhir.</p>'
  . $copyBlock('intg-prompt-adminpage', 'Langkah 4 (opsional) — Prompt: Halaman Admin &ldquo;Averion Integration&rdquo; (kelola secret + diagnostik)', $intgPromptAdminPage, 'max-h-80 overflow-y-auto')

  . $h3('Langkah 5 — Daftarkan endpoint di ' . e($appName))
  . $steps([
      ['Buat webhook', 'Buka <strong>Konfigurasi → Integrasi Webhook</strong> → <strong>+ Tambah Webhook</strong>, isi URL endpoint app tujuan (mis. ' . $mono('https://app-kamu.com/api/averion/grant-access') . ').'],
      ['Petakan produk', 'Di tiap produk yang mau memicu integrasi: <strong>Produk → Edit → tab Lanjutan → Kode Program Eksternal</strong> (mis. ' . $mono('PROGRAM_A') . '). Webhook hanya terkirim untuk produk yang dipetakan.'],
      ['Auto-login (opsional)', 'Isi <strong>Redirect Setelah Bayar</strong> di produk dengan ' . $mono('https://app-kamu.com/api/averion/login?token={token}') . '.'],
      ['Uji', 'Klik <strong>Tes Kirim</strong> di daftar webhook untuk mengirim payload contoh ke endpoint.'],
  ])

  . $h3('Referensi — Kontrak Mentah (untuk stack selain Next.js)')
  . $ul([
      '<strong>Webhook header:</strong> ' . $mono('X-Averion-Event') . ', ' . $mono('X-Averion-Timestamp') . ' (unix), ' . $mono('X-Averion-Signature: sha256=HMAC_SHA256(raw_body, AVERION_WEBHOOK_SECRET)') . '.',
      '<strong>Webhook body (JSON):</strong> ' . $mono('event, status(="active"), email, name, phone, program_code, order_id, timestamp') . '.',
      '<strong>Token auto-login:</strong> JWT ' . $mono('HS256') . ' (key = ' . $mono('AVERION_REDIRECT_SECRET') . '), klaim ' . $mono('sub(email), name, order_id, program_code, iat, exp') . ' (kedaluwarsa 15 menit).',
  ])
  . $warn('Portal saat ini <strong>hanya</strong> mengirim ' . $mono('status:"active"') . '. Pencabutan akses saat refund (' . $mono('status:"revoked"') . ') belum dikirim.')
);

echo $section('hermes-agent',
  $L('Hermes Agent (Ingest API)', 'Hermes Agent (Ingest API)', 'Hermes Agent (Ingest API)'),
  $L('Izinkan sistem otomatis / AI eksternal (mis. "Hermes") menulis draft dan menjadwalkan tindak lanjut pembayaran lewat API ber-token. Pengiriman tetap dikendalikan oleh cron dan pengaturan instalasimu.',
     'Benarkan sistem automasi / AI luaran menulis draf dan menjadualkan susulan pembayaran melalui API ber-token. Penghantaran kekal dikawal oleh pemasangan anda.',
     'Let an external automation / AI write drafts and schedule payment follow-ups through a token-based API. Your installation controls delivery.'),

  $h3('Apa itu')
  . '<p class="mb-4"><strong>Hermes Agent</strong> adalah pintu masuk <strong>Ingest API</strong>: sistem luar bisa mengirim <em>draft</em> (mis. email sequence otomatis) dan, bila diizinkan admin, menjadwalkan tindak lanjut pembayaran tertunda. Draft diperiksa dan diaktifkan oleh admin; tindak lanjut dikirim oleh cron ' . e($appName) . '. Berbeda dengan Integrasi Claude (MCP) yang dipakai lewat percakapan di aplikasi Claude, Hermes Agent dipakai oleh <strong>program lain</strong> yang memanggil endpoint secara langsung.</p>'
  . $info('<strong>Mati secara default.</strong> Hermes tidak memegang token email/WhatsApp. Draft sequence selalu NONAKTIF. Bila tier kelola dan pemulihan abandoned-cart lewat email diaktifkan admin, Hermes dapat menjadwalkan follow-up. Pengiriman dilakukan cron dan provider ' . e($appName) . '.')

  . $h3('Cara Mengaktifkan (sekali saja)')
  . $steps([
      ['Buka pengaturan', 'Masuk ke ' . $mono('/admin/hermes-agent') . ' (menu <strong>Hermes Agent</strong> di grup Konfigurasi).'],
      ['Generate Token', 'Klik <strong>Generate Token</strong>. Token tampil <strong>sekali</strong> — salin &amp; simpan sekarang (kami hanya menyimpan hash-nya).'],
      ['Nyalakan Hermes Agent', 'Aktifkan toggle utama. Tanpa ini, semua permintaan ditolak walau token benar.'],
      ['(Opsional) Izinkan tier kelola', 'Kemampuan tertentu butuh tier tambahan (<em>manage</em>). Nyalakan hanya bila integrasimu memang memerlukannya.'],
      ['Beri tahu sistem eksternal', 'Sistem luar memanggil endpoint dengan header ' . $mono('Authorization: Bearer &lt;token&gt;') . '. Mulai dari endpoint <em>discovery</em> di bawah untuk melihat kemampuan yang aktif.'],
  ])
  . $code(rtrim(APP_URL, '/') . '/api/hermes/capabilities')

  . $h3('Kemampuan (Capability)')
  . $ul([
      $mono('email_sequence_ingest') . ' — <strong>tersedia</strong>. Sistem luar membuat / memperbarui <em>draft</em> email sequence (dikenali lewat ' . $mono('external_ref') . '). Draft <strong>selalu nonaktif</strong>; mendukung ' . $mono('dry_run') . ' untuk pratinjau sebelum benar-benar ditulis. Endpoint: ' . $mono('GET/POST /api/hermes/email-sequences') . '.',
  ])
  . $info('Daftar kemampuan bersifat dinamis — endpoint ' . $mono('/api/hermes/capabilities') . ' selalu memberitahu sistem luar mana yang <em>aktif</em> di instalasimu saat ini, plus tier yang dibutuhkan.')
  . $info('<strong>Follow-up pembayaran tertunda</strong> tidak lagi lewat Hermes. Pemulihan order belum lunas kini ditangani fitur native <strong>Abandoned Cart Recovery</strong> (reminder bertahap hingga 3x, template ' . $mono('abandoned_cart') . ' yang bisa diedit di Notifikasi) — tanpa integrasi eksternal.')

  . $h3('Keamanan &amp; Kontrol')
  . $ul([
      '<strong>Toggle utama</strong> — default <strong>mati</strong>. Mati = semua permintaan ditolak walau token benar.',
      '<strong>Draft selalu nonaktif</strong> — sistem luar tidak bisa menyalakan sequence; field status ditolak. Kamu yang meninjau &amp; mengaktifkan dari panel.',
      '<strong>Tier terpisah</strong> — kemampuan yang lebih sensitif butuh izin <em>kelola</em> tambahan, di luar toggle utama.',
      '<strong>Token</strong> — hanya tersimpan sebagai hash, tak bisa dilihat ulang. Bocor? <strong>Generate Ulang</strong> / <strong>Cabut Token</strong> — koneksi lama langsung putus.',
      '<strong>Tak pernah mengirim langsung</strong> — Hermes tak memegang kredensial email/WA; pengiriman tetap lewat cron &amp; provider ' . e($appName) . ', dan tetap menghormati <em>opt-out</em>/unsubscribe pelanggan.',
  ])
  . $warn('Hermes Agent ditujukan untuk <strong>developer / sistem otomatis</strong>. Bila kamu hanya ingin bertanya data bisnis lewat percakapan, pakai <strong>Integrasi Claude (MCP)</strong> — lihat bagian di atas.')
);

?>
        </div>
      </div>

    </main>
  </div>
</div>

<script>
function copyDoc(id, btn){
  var el=document.getElementById(id); if(!el) return;
  var text=el.innerText || el.textContent || '';
  var done=function(){ var o=btn.textContent; btn.textContent='Tersalin!'; setTimeout(function(){btn.textContent=o;},1500); };
  if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(text).then(done).catch(done); }
  else {
    var r=document.createRange(); r.selectNodeContents(el);
    var sel=window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
    try{ document.execCommand('copy'); }catch(e){} sel.removeAllRanges(); done();
  }
}
let currentDocsLang = localStorage.getItem('docs_lang') || 'id';
if (currentDocsLang !== 'id' && currentDocsLang !== 'my') currentDocsLang = 'id'; // dok cuma 2 bahasa

function setLang(lang) {
  currentDocsLang = lang;
  document.querySelectorAll('[data-lang]').forEach(el => el.classList.add('hidden'));
  document.querySelectorAll('[data-lang="' + lang + '"]').forEach(el => el.classList.remove('hidden'));
  document.querySelectorAll('.lang-btn').forEach(btn => btn.classList.remove('lang-active'));
  const active = document.getElementById('lang-' + lang);
  if (active) active.classList.add('lang-active');
  localStorage.setItem('docs_lang', lang);
  // Re-filter agar hasil pencarian mengikuti bahasa yang dipilih.
  if (typeof window.__applyDocsSearch === 'function') window.__applyDocsSearch();
}

document.addEventListener('DOMContentLoaded', () => {
  setLang(currentDocsLang);

  const sections = Array.from(document.querySelectorAll('section[id]'));
  const navLinks = Array.from(document.querySelectorAll('.doc-nav'));
  // Grup nav docs saja (jangan sentuh sidebar admin) — diturunkan dari link .doc-nav.
  const navGroups = [...new Set(navLinks.map(l => l.closest('nav')).filter(Boolean))];

  // ── Highlight menu aktif saat scroll ──
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

  // ── Smooth scroll ──
  navLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      const target = document.getElementById(link.getAttribute('href').slice(1));
      if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
  });

  // ── Pencarian panduan ──
  const input    = document.getElementById('docsSearch');
  const clearBtn = document.getElementById('docsSearchClear');
  const noRes    = document.getElementById('docsNoResults');
  const countEl  = document.getElementById('docsSearchCount');

  // Ambil teks (judul + deskripsi + isi) pada bahasa aktif. Membaca elemen
  // [data-lang=<aktif>] langsung agar tidak terpengaruh status display:none.
  function sectionText(sec) {
    let t = '';
    sec.querySelectorAll('[data-lang="' + currentDocsLang + '"]').forEach(el => { t += ' ' + el.textContent; });
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

    // Sembunyikan grup nav docs yang seluruh itemnya tersembunyi.
    navGroups.forEach(nav => {
      const any = Array.from(nav.querySelectorAll('.doc-nav')).some(a => !a.classList.contains('hidden'));
      if (nav.parentElement) nav.parentElement.classList.toggle('hidden', !any);
    });

    noRes.classList.toggle('hidden', visible !== 0);
    if (q === '') {
      countEl.classList.add('hidden');
    } else {
      countEl.classList.remove('hidden');
      countEl.textContent = visible + ' panduan ditemukan untuk "' + input.value.trim() + '"';
    }
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
