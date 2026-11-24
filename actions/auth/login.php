<?php
require_once __DIR__ . '/../../bootstrap.php';

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/login');
}

// Validasi CSRF
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('login_error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/login');
}

// Sanitasi input
$email    = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$password = $_POST['password'] ?? '';

// Validasi dasar (cepat, sebelum query DB)
if (empty($email) || empty($password)) {
    flash('login_error', 'Email dan password wajib diisi.', 'error');
    redirect('/login');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('login_error', 'Format email tidak valid.', 'error');
    redirect('/login');
}

// Brute-force protection: blokir setelah 5 percobaan gagal / 15 menit per email+IP.
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateCheck = checkLoginRateLimit($email, $ipAddress);
if (!$rateCheck['allowed']) {
    flash('login_error', $rateCheck['message'], 'error');
    redirect('/login');
}

// Query dan verifikasi
try {
    $pdo  = getDB();
    try {
        $stmt = $pdo->prepare('SELECT id, name, email, password, role, status, totp_enabled FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
    } catch (PDOException $pe) {
        // Skema tertinggal (kolom totp_enabled belum ada, errno 1054) — mis.
        // instalasi yang migrasinya terlewat saat update lompat versi. Jangan
        // kunci admin di luar: login tanpa 2FA agar bisa jalankan Update Sistem
        // (migrasi catch-up 1.1.58 memulihkan skema).
        if ((int) ($pe->errorInfo[1] ?? 0) !== 1054) { throw $pe; }
        $stmt = $pdo->prepare('SELECT id, name, email, password, role, status, 0 AS totp_enabled FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
    }
    $user = $stmt->fetch();

    if (!$user) {
        // Samakan waktu respons dengan jalur "password salah" (dummy verify)
        // agar tidak bocor apakah email terdaftar via timing attack.
        password_verify($password, '$2y$10$1pdlWTRxAZCO.ZZPA3r7q.CNpcs7io6wsTV4aM9n3VOr.x94/Mpji');
        recordLoginFailure($email, $ipAddress);
        flash('login_error', 'Email atau password salah.', 'error');
        redirect('/login');
    }

    // Password DULU, baru status. Kalau status dicek lebih dulu, siapa pun yang
    // menebak email (tanpa tahu password) bisa membedakan "akun ada tapi diblokir"
    // dari "email tidak terdaftar" lewat pesan errornya = enumerasi akun.
    if (!password_verify($password, $user['password'])) {
        recordLoginFailure($email, $ipAddress);
        flash('login_error', 'Email atau password salah.', 'error');
        redirect('/login');
    }

    // Password benar = pemilik sah → aman memberi tahu alasan sebenarnya.
    if ($user['status'] !== 'active') {
        $msg = $user['status'] === 'banned'
            ? 'Akun kamu telah diblokir. Hubungi admin untuk bantuan.'
            : 'Akun kamu tidak aktif. Hubungi admin.';
        flash('login_error', $msg, 'error');
        redirect('/login');
    }

    // Login berhasil — reset counter brute-force
    clearLoginAttempts($email, $ipAddress);

    // 2FA TOTP (admin/staff yang mengaktifkan): password valid ≠ login selesai.
    // Session login BELUM diset — user harus lolos kode 2FA dulu di /login/2fa.
    if (totpChallengeRequired($user)) {
        redirect('/login/2fa');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];

    flash('success', 'Selamat datang kembali, ' . $user['name'] . '!');

    // Deteksi IP baru HARUS sebelum logActivity menuliskan login ini — kalau
    // sesudah, baris login saat ini sudah masuk activity_logs → selalu "pernah".
    $isNewLoginIp = true;
    if ($user['role'] === 'admin') {
        try {
            $seen = $pdo->prepare("SELECT 1 FROM activity_logs WHERE user_id = ? AND action = 'auth.login' AND ip_address = ? LIMIT 1");
            $seen->execute([(int) $user['id'], $ipAddress]);
            $isNewLoginIp = !$seen->fetchColumn();
        } catch (Throwable $e) {
            $isNewLoginIp = false; // gagal cek → jangan spam alert
        }
    }

    logActivity($user['role'] === 'admin' ? 'admin' : 'user', 'auth.login', 'user', (int) $user['id'], 'Login: ' . $user['email']);

    // Alert Telegram: login admin dari IP baru (Telegram Alerts Fase 1). Fail-soft,
    // fire-and-forget; hanya admin + IP yang belum pernah dipakai login → anti-spam
    // login rutin. Digerbangi telegram_enabled + telegram_alert_admin_login (default ON).
    // Catatan: admin ber-2FA menyelesaikan login di verify-2fa.php (redirect di atas),
    // jadi alert ini mencakup admin tanpa 2FA; jalur 2FA menyusul di fase berikutnya.
    if ($user['role'] === 'admin' && $isNewLoginIp
        && function_exists('notifyAdminTelegram')
        && getSetting('telegram_enabled', '0') === '1'
        && getSetting('telegram_alert_admin_login', '1') === '1') {
        try {
            $ua  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 180);
            $msg = "\xF0\x9F\x94\x90 LOGIN ADMIN (IP baru)\n\n"
                 . "User: "   . $user['email'] . "\n"
                 . "IP: "     . $ipAddress . "\n"
                 . "Device: " . ($ua !== '' ? $ua : '-') . "\n"
                 . "Waktu: "  . date('d M Y, H:i') . " WIB";
            notifyAdminTelegram('admin_login_alert', $msg);
        } catch (Throwable $e) {
            error_log('login telegram alert error: ' . $e->getMessage());
        }
    }

    // ── Auto re-aktivasi lisensi: HANYA admin, HANYA saat pending_activation ──
    // Dicoba SEKALI (timeout pendek di attemptLicenseActivation). TIDAK pernah
    // memblokir login. Status selain pending_activation ditangani _enforceLicense()
    // di bootstrap pada setiap request — jangan duplikasi enforcement di sini.
    // Gate pada flag enforcement: saat flag mati (masa transisi sebelum keypair
    // vendor siap), JANGAN ganggu login admin sama sekali (regression-safe).
    $forceActivateRedirect = false;
    if ($user['role'] === 'admin' && licenseEnforcementActive()) {
        try {
            $licRow = $pdo->query("SELECT status, license_key FROM license_info ORDER BY id ASC LIMIT 1")->fetch();
        } catch (Exception $e) {
            $licRow = null;
        }
        // Pending bila: belum pernah ada lisensi sama sekali, ATAU status pending_activation.
        $needsActivation = !$licRow || (($licRow['status'] ?? '') === 'pending_activation');
        if ($needsActivation) {
            $licKey      = trim((string) ($licRow['license_key'] ?? ''));
            $reactivated = false;
            if ($licKey !== '') {
                $res         = attemptLicenseActivation($licKey, parseDomainFromAppUrl());
                $reactivated = $res['success'];
            }
            if (!$reactivated) {
                $forceActivateRedirect = true;
                flash('license_pending', 'Lisensi belum berhasil diaktivasi saat instalasi. Silakan aktivasi sekarang untuk melanjutkan.', 'warning');
            }
        }
    }

    // Tentukan redirect tujuan.
    $intendedUri = $_SESSION['login_redirect'] ?? '';
    unset($_SESSION['login_redirect']);

    // Admin dgn lisensi pending & re-aktivasi otomatis gagal → paksa ke halaman
    // aktivasi (override tujuan awal), supaya admin menyelesaikan aktivasi dulu.
    if ($forceActivateRedirect) {
        redirect('/admin/license-activate');
    }

    // Tujuan awal dianggap aman bila path internal & bukan halaman auth.
    $intendedOk = isSafeRedirectPath($intendedUri);

    // Hormati tujuan awal (mis. /admin/license saat lisensi suspend) agar admin
    // tidak ter-loop kembali ke dashboard yang di-enforce.
    if ($intendedOk) {
        header('Location: ' . $intendedUri);
        exit;
    }

    redirect(in_array($user['role'], ['admin', 'staff'], true) ? '/admin' : '/dashboard');

} catch (Exception $e) {
    error_log('Login action error: ' . $e->getMessage());
    flash('login_error', 'Terjadi kesalahan pada sistem. Silakan coba lagi.', 'error');
    redirect('/login');
}
