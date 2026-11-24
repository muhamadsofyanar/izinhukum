<?php
// ─── Google OAuth — Step 1: redirect ke halaman consent Google ──
require_once __DIR__ . '/../bootstrap.php';

// Jika sudah login, tidak perlu OAuth lagi.
if (isLoggedIn()) {
    redirect(isAdmin() ? '/admin' : '/dashboard');
}

// Fitur harus aktif DAN Client ID terisi.
$enabled  = getSetting('google_oauth_enabled') === '1';
$clientId = getSetting('google_oauth_client_id', '');
if (!$enabled || empty($clientId)) {
    flash('login_error', 'Login dengan Google tidak tersedia.', 'error');
    redirect('/login');
}

// State untuk proteksi CSRF.
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

// Simpan halaman tujuan setelah login berhasil (hanya path internal).
$intended = $_GET['intended'] ?? '/dashboard';
if (!str_starts_with($intended, '/') || str_contains($intended, '//')) {
    $intended = '/dashboard';
}
$_SESSION['google_oauth_intended'] = $intended;

$params = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => rtrim(APP_URL, '/') . '/auth/google/callback',
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
