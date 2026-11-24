<?php
require_once __DIR__ . '/../../bootstrap.php';

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/register');
}

// Validasi CSRF
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('register_error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/register');
}

// Rate limit per-IP: cegah pembuatan akun massal + spam email notifikasi
// (dulu register satu-satunya form publik tanpa throttle sama sekali).
$regIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkIpRateLimit('register', $regIp, 5, 30)) {
    flash('register_error', 'Terlalu banyak pendaftaran dari jaringan kamu. Coba lagi dalam 30 menit.', 'error');
    redirect('/register');
}
recordIpAction('register', $regIp);

// ─── Anti-bot: honeypot + jebakan waktu ──────────────────────────────────────
// Bot spam merender form (dapat CSRF) lalu mengisi SEMUA field — termasuk honeypot
// tersembunyi — dan mem-POST hampir seketika. Dua sinyal ini menyaring mayoritas
// bot dengan nol friksi bagi manusia (honeypot tak terlihat; manusia isi > 2 detik).
// Ditolak DIAM-DIAM (pesan generik) agar bot tak mempelajari polanya.
$hpFilled = trim((string) ($_POST['website'] ?? '')) !== '';
$regTs    = (int) ($_SESSION['reg_form_ts'] ?? 0);
$tooFast  = $regTs > 0 && (time() - $regTs) < 2;
if ($hpFilled || $tooFast) {
    error_log('[register] ditolak anti-bot (' . ($hpFilled ? 'honeypot' : 'terlalu-cepat') . ') ip=' . $regIp);
    flash('register_error', 'Pendaftaran tidak dapat diproses. Silakan coba lagi.', 'error');
    redirect('/register');
}

// Ambil dan sanitasi input
$name            = trim($_POST['name']            ?? '');
$email           = trim($_POST['email']           ?? '');
$password        = $_POST['password']             ?? '';
$confirmPassword = $_POST['confirm_password']     ?? '';
$refCode         = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($_POST['referral_code'] ?? '')));

// Fallback ke session ref_code jika POST kosong
if (empty($refCode) && !empty($_SESSION['ref_code'])) {
    $refCode = $_SESSION['ref_code'];
}

// Referral saat registrasi bersifat AMBIENT (dari ?ref= / session / field prefilled).
// Hormati toggle yang sama dengan checkout: bila program afiliasi mati atau General
// Cookie mati, jangan buat record referral maupun binding Lock Customer dari ambient.
if (!affiliateAmbientEnabled()) {
    $refCode = '';
}

// ─── Validasi ─────────────────────────────────────────────────
$errors = [];

if (strlen($name) < 2) {
    $errors[] = 'Nama minimal 2 karakter.';
} elseif (strlen($name) > 100) {
    $errors[] = 'Nama maksimal 100 karakter.';
}

$email = filter_var($email, FILTER_SANITIZE_EMAIL);
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Format email tidak valid.';
}

if (strlen($password) < 8) {
    $errors[] = 'Password minimal 8 karakter.';
}

if ($password !== $confirmPassword) {
    $errors[] = 'Konfirmasi password tidak cocok.';
}

if (!empty($errors)) {
    flash('register_error', implode(' ', $errors), 'error');
    redirect('/register');
}

// ─── Proses registrasi ────────────────────────────────────────
try {
    $pdo = getDB();

    // Cek email duplikat
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        flash('register_error', 'Email ini sudah terdaftar. Silakan login atau gunakan email lain.', 'error');
        redirect('/register');
    }

    // Mulai transaksi DB
    $pdo->beginTransaction();

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // Generate affiliate code unik
    $affiliateCode = generateAffiliateCode();

    // Insert user baru
    $stmt = $pdo->prepare('
        INSERT INTO users (name, email, password, affiliate_code, role, status)
        VALUES (?, ?, ?, ?, \'user\', \'active\')
    ');
    $stmt->execute([$name, $email, $hashedPassword, $affiliateCode]);
    $userId = (int) $pdo->lastInsertId();

    // Auto-create affiliate record untuk user baru
    $defaultRate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
    $stmt = $pdo->prepare('
        INSERT INTO affiliates (user_id, code, commission_rate, status)
        VALUES (?, ?, ?, \'active\')
    ');
    $stmt->execute([$userId, $affiliateCode, $defaultRate]);

    // Proses referral jika ada kode
    if (!empty($refCode)) {
        $stmt = $pdo->prepare('SELECT id FROM affiliates WHERE code = ? AND status = \'active\' LIMIT 1');
        $stmt->execute([$refCode]);
        $affiliate = $stmt->fetch();

        if ($affiliate) {
            $stmt = $pdo->prepare('
                INSERT INTO referrals (affiliate_id, referred_user_id, status)
                VALUES (?, ?, \'registered\')
            ');
            $stmt->execute([$affiliate['id'], $userId]);
            bindCustomerToAffiliate($pdo, $userId, (int) $affiliate['id']);
        }
    }

    $pdo->commit();

    // Hapus ref_code dari session setelah dipakai
    unset($_SESSION['ref_code']);

    // Trigger notifikasi registrasi (di luar transaksi agar tidak rollback jika gagal)
    try {
        require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
        NotificationDispatcher::trigger('register', [
            'name'          => $name,
            'email'         => $email,
            'login_url'     => baseUrl('login'),
            'dashboard_url' => baseUrl('dashboard'),
        ], $email, ''); // register tidak mengumpulkan nomor WA (dikumpulkan saat checkout)
    } catch (Exception $e) {
        error_log('Register notification trigger error: ' . $e->getMessage());
    }

    // Set session — user langsung login setelah register
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['name']    = $name;
    $_SESSION['email']   = $email;
    $_SESSION['role']    = 'user';

    enrollUserToSequences('user_register', $userId, 0, $email);

    logActivity('user', 'auth.register', 'user', $userId, 'User baru terdaftar: ' . $email);
    flash('success', 'Registrasi berhasil! Selamat datang, ' . $name . '!');

    // Hormati tujuan awal (mis. checkout produk) yg diset dari halaman register.
    // Validasi ulang & raw header agar konsisten dgn actions/auth/login.php.
    $intendedUri = $_SESSION['login_redirect'] ?? '';
    unset($_SESSION['login_redirect']);
    $intendedOk = isSafeRedirectPath($intendedUri);
    if ($intendedOk) {
        header('Location: ' . $intendedUri);
        exit;
    }

    redirect('/dashboard');

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Register action error: ' . $e->getMessage());
    flash('register_error', 'Terjadi kesalahan saat mendaftarkan akun. Silakan coba lagi.', 'error');
    redirect('/register');
}
