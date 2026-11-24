<?php
// ─── Lock check — tolak akses jika sudah pernah install ───────
// Defense-in-depth dengan tiga kondisi:
//   (1) install.lock + config.php  → instalasi normal & selesai
//   (2) config.php tanpa lock      → tetap dianggap terpasang (cegah re-run)
//   (3) install.lock TANPA config  → instalasi RUSAK (config gagal ditulis /
//       terhapus) → tampilkan pesan minta hubungi support, jangan diam-diam
//       mulai ulang seolah fresh install.
$lockExists   = file_exists(__DIR__ . '/install.lock');
$configExists = file_exists(dirname(__DIR__) . '/config.php');

session_start();

// Penanda entry-point: view hanya boleh dirender lewat index.php ini
// (mencegah akses HTTP langsung ke installer/views/*.php yang melewati
// guard lock/config di bawah).
define('INSTALLER_ENTRY', true);

// Instalasi baru saja diselesaikan DI SESI INI? (step3 menulis config + lock,
// lalu redirect ke ?step=4). Bila ya, halaman finish tetap boleh tampil sekali
// meski lock/config sudah ada — supaya user melihat ringkasan + peringatan
// keamanan "hapus folder installer". Pengunjung lain tetap diblokir.
$justFinished = !empty($_SESSION['install_step3_done']);

function installer_block_page(string $title, string $heading, string $body, string $link = ''): void
{
    http_response_code(403);
    $linkHtml = $link !== '' ? $link : '';
    die('<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
         <meta name="viewport" content="width=device-width, initial-scale=1.0">
         <title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>
         <style>
           body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
                min-height:100vh;margin:0;background:#eef2ff;padding:1rem;box-sizing:border-box;}
           .box{text-align:center;background:#fff;border-radius:24px;padding:48px 40px;max-width:420px;
                box-shadow:0 8px 32px rgba(99,102,241,.12);}
           h2{color:#1f2937;margin:0 0 10px;font-size:1.25rem;}
           p{color:#6b7280;margin:0 0 20px;font-size:.925rem;line-height:1.6;}
           a{color:#6366f1;font-weight:600;text-decoration:none;}
           code{background:#f3f4f6;padding:2px 6px;border-radius:6px;font-size:.85em;}
         </style>
         </head>
         <body>
           <div class="box">
             <h2>' . $heading . '</h2>
             <p>' . $body . '</p>
             ' . $linkHtml . '
           </div>
         </body></html>');
}

if ($lockExists && !$configExists) {
    // Kondisi (3): instalasi rusak.
    installer_block_page(
        'Instalasi Rusak',
        'Instalasi tidak lengkap',
        'File <code>config.php</code> tidak ditemukan padahal installer sudah pernah '
        . 'dijalankan sampai selesai. Instalasi kemungkinan rusak. Hapus '
        . '<code>installer/install.lock</code> untuk mengulang instalasi, atau hubungi support.',
        '<a href="https://darmutiara.com">Darmutiara &rarr;</a>'
    );
}

if ($lockExists || $configExists) {
    if ($justFinished) {
        // Kondisi (1): baru selesai di sesi ini → tampilkan halaman finish.
        require __DIR__ . '/views/step4-finish.php';
        exit;
    }
    // Kondisi (2): sudah terpasang (sesi lain / pengunjung) → blokir.
    installer_block_page(
        'Sudah Terpasang',
        'Aplikasi sudah terpasang',
        'Installer tidak bisa dijalankan ulang demi keamanan.',
        '<a href="../login">Masuk ke Aplikasi &rarr;</a>'
    );
}

// CSRF token per sesi installer
if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}

$step = isset($_GET['step']) ? max(1, min(4, (int) $_GET['step'])) : 1;

// Jaga urutan — tidak bisa loncat step tanpa menyelesaikan yang sebelumnya
if ($step > 1 && empty($_SESSION['install_step1_done'])) {
    $step = 1;
}
if ($step > 2 && empty($_SESSION['install_step2_done'])) {
    $step = 2;
}
if ($step > 3 && empty($_SESSION['install_step3_done'])) {
    $step = 3;
}

switch ($step) {
    case 1: require __DIR__ . '/views/step1-requirements.php'; break;
    case 2: require __DIR__ . '/views/step2-database.php';     break;
    case 3: require __DIR__ . '/views/step3-admin.php';        break;
    case 4: require __DIR__ . '/views/step4-finish.php';       break;
}
