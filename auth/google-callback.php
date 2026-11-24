<?php
// ─── Google OAuth — Step 2: callback dari Google ────────────────
require_once __DIR__ . '/../bootstrap.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? '/admin' : '/dashboard');
}

$enabled      = getSetting('google_oauth_enabled') === '1';
$clientId     = getSetting('google_oauth_client_id', '');
$clientSecret = getSetting('google_oauth_client_secret', '');

// Guard: fitur tidak aktif / belum dikonfigurasi penuh.
if (!$enabled || empty($clientId) || empty($clientSecret)) {
    flash('login_error', 'Login dengan Google tidak tersedia.', 'error');
    redirect('/login');
}

// ── Validasi state (CSRF) ──
$state         = $_GET['state'] ?? '';
$expectedState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

if (empty($state) || empty($expectedState) || !hash_equals($expectedState, $state)) {
    flash('login_error', 'Permintaan tidak valid. Silakan coba lagi.', 'error');
    redirect('/login');
}

// ── Tangkap error / pembatalan dari Google ──
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $error = (string) ($_GET['error'] ?? 'unknown');
    flash('login_error', 'Login Google dibatalkan: ' . $error, 'error');
    redirect('/login');
}

// ── STEP 1: Tukar authorization code → access_token ──
$tokenPayload = http_build_query([
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => rtrim(APP_URL, '/') . '/auth/google/callback',
    'grant_type'    => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $tokenPayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$tokenResponse = curl_exec($ch);
$tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($tokenHttpCode !== 200 || !$tokenResponse) {
    error_log('[GoogleOAuth] Gagal tukar token (HTTP ' . $tokenHttpCode . '): ' . $tokenResponse);
    flash('login_error', 'Gagal menghubungi Google. Silakan coba lagi.', 'error');
    redirect('/login');
}

$tokenData   = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? '';

if (empty($accessToken)) {
    error_log('[GoogleOAuth] access_token kosong: ' . $tokenResponse);
    flash('login_error', 'Gagal mendapatkan token dari Google.', 'error');
    redirect('/login');
}

// ── STEP 2: Ambil profil user dari Google ──
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$profileResponse = curl_exec($ch);
$profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($profileHttpCode !== 200 || !$profileResponse) {
    error_log('[GoogleOAuth] Gagal ambil profil (HTTP ' . $profileHttpCode . '): ' . $profileResponse);
    flash('login_error', 'Gagal mengambil data profil Google.', 'error');
    redirect('/login');
}

$profile = json_decode($profileResponse, true);

// Validasi email terverifikasi (userinfo v3 mengembalikan boolean true/false).
if (empty($profile['email_verified']) || $profile['email_verified'] !== true) {
    flash('login_error', 'Email Google kamu belum diverifikasi.', 'error');
    redirect('/login');
}

$googleId = (string) ($profile['sub']     ?? '');
$email    = strtolower(trim((string) ($profile['email'] ?? '')));
$name     = trim((string) ($profile['name'] ?? ''));
$avatar   = $profile['picture'] ?? null;

if (empty($googleId) || empty($email)) {
    flash('login_error', 'Data profil Google tidak lengkap.', 'error');
    redirect('/login');
}
if ($name === '') {
    $name = strstr($email, '@', true) ?: 'Pengguna';
}

// ── STEP 3: Cari atau buat user ──
try {
    $pdo = getDB();

    // 3a. Cari berdasarkan google_id (tercepat).
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if (!$user) {
        // 3b. Cari berdasarkan email (merge akun lama).
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // MERGE: hubungkan akun lama dengan Google. Avatar hanya diisi
            // jika kosong agar tidak menimpa foto yang sudah di-set user.
            $pdo->prepare(
                'UPDATE users SET google_id = ?, avatar = COALESCE(NULLIF(avatar, ""), ?), updated_at = NOW()
                 WHERE id = ?'
            )->execute([$googleId, $avatar, $user['id']]);
        } else {
            // REGISTER BARU via Google — sejalan dengan actions/auth/register.php
            // (password NULL, sekaligus buat affiliate_code + record affiliates).
            $pdo->beginTransaction();

            $affiliateCode = generateAffiliateCode();

            $pdo->prepare(
                "INSERT INTO users
                   (name, email, password, google_id, avatar, affiliate_code, role, status, email_verified_at, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, ?, ?, 'user', 'active', NOW(), NOW(), NOW())"
            )->execute([$name, $email, $googleId, $avatar, $affiliateCode]);

            $newId = (int) $pdo->lastInsertId();

            $defaultRate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
            $pdo->prepare(
                "INSERT INTO affiliates (user_id, code, commission_rate, status)
                 VALUES (?, ?, ?, 'active')"
            )->execute([$newId, $affiliateCode, $defaultRate]);

            $pdo->commit();

            logActivity('user', 'auth.register', 'user', $newId, 'User baru via Google: ' . $email);
        }

        // Muat ulang record user terbaru (google_id sudah pasti terisi di sini).
        $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
        $stmt->execute([$googleId]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        throw new RuntimeException('User tidak ditemukan setelah proses Google OAuth.');
    }

    // Cek status akun.
    if (($user['status'] ?? '') !== 'active') {
        $msg = ($user['status'] ?? '') === 'banned'
            ? 'Akun kamu telah diblokir. Hubungi admin untuk bantuan.'
            : 'Akun kamu tidak aktif. Hubungi admin.';
        flash('login_error', $msg, 'error');
        redirect('/login');
    }

    // Admin tidak boleh login via Google.
    if (($user['role'] ?? '') === 'admin') {
        flash('login_error', 'Akun admin tidak bisa login via Google.', 'error');
        redirect('/login');
    }

    // 2FA TOTP (staff yang mengaktifkan): Google OAuth valid ≠ login selesai.
    if (totpChallengeRequired($user)) {
        $intended = $_SESSION['google_oauth_intended'] ?? '';
        unset($_SESSION['google_oauth_intended']);
        if (isSafeRedirectPath((string) $intended)) {
            $_SESSION['login_redirect'] = $intended;
        }
        redirect('/login/2fa');
    }

    // ── STEP 4: Set session (pola sama dengan actions/auth/login.php) ──
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];

    logActivity('user', 'auth.login', 'user', (int) $user['id'], 'Login via Google: ' . $user['email']);
    flash('success', 'Selamat datang, ' . $user['name'] . '!');

    $intended = $_SESSION['google_oauth_intended'] ?? '/dashboard';
    unset($_SESSION['google_oauth_intended']);
    redirect($intended);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[GoogleOAuth] DB error: ' . $e->getMessage());
    flash('login_error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
    redirect('/login');
}
