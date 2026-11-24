<?php
// POST /actions/auth/verify-2fa
// Selesaikan login yang tertahan 2FA: verifikasi kode TOTP ATAU backup code.
require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/login');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('login_error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/login/2fa');
}

$pending = $_SESSION['totp_pending'] ?? null;
if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time()) {
    unset($_SESSION['totp_pending']);
    flash('login_error', 'Sesi verifikasi kedaluwarsa. Silakan login ulang.', 'error');
    redirect('/login');
}

// Rate limit: maksimal 5 percobaan per sesi tantangan → paksa login ulang.
$_SESSION['totp_attempts'] = (int) ($_SESSION['totp_attempts'] ?? 0) + 1;
if ($_SESSION['totp_attempts'] > 5) {
    unset($_SESSION['totp_pending'], $_SESSION['totp_attempts']);
    flash('login_error', 'Terlalu banyak percobaan salah. Silakan login ulang.', 'error');
    redirect('/login');
}

$code = trim($_POST['code'] ?? '');

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, name, email, role, status, totp_secret, totp_enabled FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $pending['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || empty($user['totp_enabled'])) {
        unset($_SESSION['totp_pending'], $_SESSION['totp_attempts']);
        flash('login_error', 'Akun tidak valid untuk verifikasi ini. Silakan login ulang.', 'error');
        redirect('/login');
    }

    $valid = totpVerify((string) $user['totp_secret'], $code);
    if (!$valid) {
        // Bukan kode TOTP → coba sebagai backup code (sekali pakai).
        $valid = totpConsumeBackupCode((int) $user['id'], $code);
    }

    if (!$valid) {
        $sisa = 5 - (int) $_SESSION['totp_attempts'];
        flash('login_error', 'Kode salah. ' . ($sisa > 0 ? "Sisa percobaan: $sisa." : 'Silakan login ulang.'), 'error');
        redirect($sisa > 0 ? '/login/2fa' : '/login');
    }

    // ── 2FA lolos → selesaikan login (pola sama actions/auth/login.php) ──
    unset($_SESSION['totp_pending'], $_SESSION['totp_attempts']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];

    if (!empty($_POST['trust_device'])) {
        totpTrustDevice((int) $user['id'], 30);
    }

    flash('success', 'Selamat datang kembali, ' . $user['name'] . '!');
    logActivity($user['role'] === 'admin' ? 'admin' : 'user', 'auth.login_2fa', 'user', (int) $user['id'], 'Login + 2FA: ' . $user['email']);

    // Auto re-aktivasi lisensi utk admin dgn status pending (mirror login.php).
    $forceActivateRedirect = false;
    if ($user['role'] === 'admin' && licenseEnforcementActive()) {
        try {
            $licRow = $pdo->query("SELECT status, license_key FROM license_info ORDER BY id ASC LIMIT 1")->fetch();
        } catch (Exception $e) {
            $licRow = null;
        }
        $needsActivation = !$licRow || (($licRow['status'] ?? '') === 'pending_activation');
        if ($needsActivation) {
            $licKey      = trim((string) ($licRow['license_key'] ?? ''));
            $reactivated = false;
            if ($licKey !== '' && function_exists('attemptLicenseActivation')) {
                $res         = attemptLicenseActivation($licKey, parseDomainFromAppUrl());
                $reactivated = $res['success'];
            }
            if (!$reactivated) {
                $forceActivateRedirect = true;
                flash('license_pending', 'Lisensi belum berhasil diaktivasi saat instalasi. Silakan aktivasi sekarang untuk melanjutkan.', 'warning');
            }
        }
    }
    if ($forceActivateRedirect) {
        redirect('/admin/license-activate');
    }

    $intendedUri = $_SESSION['login_redirect'] ?? '';
    unset($_SESSION['login_redirect']);
    if (isSafeRedirectPath($intendedUri)) {
        header('Location: ' . $intendedUri);
        exit;
    }

    redirect(in_array($user['role'], ['admin', 'staff'], true) ? '/admin' : '/dashboard');

} catch (Exception $e) {
    error_log('Verify 2FA error: ' . $e->getMessage());
    flash('login_error', 'Terjadi kesalahan pada sistem. Silakan coba lagi.', 'error');
    redirect('/login/2fa');
}
