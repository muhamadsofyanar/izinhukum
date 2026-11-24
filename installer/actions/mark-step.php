<?php
// ─── Action: tandai step sebagai selesai ──────────────────────
// Dipanggil via POST dari tombol "Lanjut" di setiap step.
// PRG pattern: set session flag → redirect ke step berikutnya.

// Tolak jika sudah terpasang (konsisten dengan guard di index.php)
if (file_exists(dirname(__DIR__) . '/install.lock') || file_exists(dirname(__DIR__, 2) . '/config.php')) {
    http_response_code(403);
    die('Aplikasi sudah terpasang.');
}

session_start();

// Hanya menerima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// Validasi CSRF
if (!hash_equals($_SESSION['installer_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    http_response_code(403);
    die('Token tidak valid.');
}

$step = (int) ($_POST['step'] ?? 0);

// ── Guard server-side: kebutuhan WAJIB step 1 ────────────────────
// Jangan percaya tombol client (bisa dilewati). Re-cek semua kebutuhan
// wajib di server. gd/zip TIDAK dicek di sini (opsional/non-blocker).
if ($step === 1) {
    require_once dirname(__DIR__) . '/requirements.php';
    $failed = [];
    foreach (installer_required_checks() as $c) {
        if (!$c['pass']) {
            $failed[] = trim($c['label'] . ' ' . $c['detail']);
        }
    }
    if ($failed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Kebutuhan server wajib belum terpenuhi. Perbaiki dulu lalu coba lagi.',
            'failed'  => $failed,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

switch ($step) {
    case 1:
        $_SESSION['install_step1_done'] = true;
        header('Location: ../index.php?step=2');
        break;
    case 2:
        $_SESSION['install_step2_done'] = true;
        header('Location: ../index.php?step=3');
        break;
    default:
        header('Location: ../index.php');
        break;
}
exit;
