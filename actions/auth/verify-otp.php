<?php
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Request tidak valid.']);
    exit;
}

// Normalisasi nomor — sama dengan request-otp.php (ID 62 & MY 60).
$phone = normalizeWaNumber($_POST['phone'] ?? '');

$code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');

if (strlen($code) !== 6) {
    echo json_encode(['success' => false, 'message' => 'Kode OTP harus 6 digit.']);
    exit;
}

try {
    $pdo = getDB();

    // Ambil OTP terbaru yang belum dipakai
    $stmt = $pdo->prepare(
        "SELECT * FROM otp_codes WHERE phone = ? AND is_used = 0 ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$phone]);
    $otp = $stmt->fetch();

    if (!$otp) {
        echo json_encode(['success' => false, 'message' => 'Kode OTP tidak ditemukan. Minta kode baru.']);
        exit;
    }

    // Cek expired
    if (strtotime($otp['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'Kode OTP sudah kedaluwarsa. Minta kode baru.']);
        exit;
    }

    // Cek max attempts (5x salah → OTP hangus)
    if ((int) $otp['attempts'] >= 5) {
        echo json_encode(['success' => false, 'message' => 'Terlalu banyak percobaan salah. Minta kode baru.']);
        exit;
    }

    // Verifikasi kode — constant-time comparison
    if (!hash_equals($otp['code'], $code)) {
        $pdo->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$otp['id']]);
        $remaining = 5 - ((int) $otp['attempts'] + 1);
        $suffix    = $remaining > 0
            ? "Sisa percobaan: {$remaining}."
            : 'Kode sudah tidak bisa dipakai, minta kode baru.';
        echo json_encode(['success' => false, 'message' => 'Kode salah. ' . $suffix]);
        exit;
    }

    // Kode benar — invalidate OTP sebelum login (cegah re-use race condition)
    $pdo->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?")->execute([$otp['id']]);

    // Ambil data user
    try {
        $userStmt = $pdo->prepare("SELECT id, name, email, role, status, totp_enabled FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$otp['user_id']]);
    } catch (PDOException $pe) {
        // Skema tertinggal (kolom totp_enabled belum ada, errno 1054) — jangan
        // kunci user; login tanpa 2FA. Lihat catatan serupa di login.php.
        if ((int) ($pe->errorInfo[1] ?? 0) !== 1054) { throw $pe; }
        $userStmt = $pdo->prepare("SELECT id, name, email, role, status, 0 AS totp_enabled FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$otp['user_id']]);
    }
    $user = $userStmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Akun tidak aktif atau tidak ditemukan.']);
        exit;
    }

    // 2FA TOTP (admin/staff yang mengaktifkan): OTP WA valid ≠ login selesai.
    if (totpChallengeRequired($user)) {
        echo json_encode(['success' => true, 'redirect_to' => APP_URL . '/login/2fa']);
        exit;
    }

    // Login
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];

    // Tandai nomor terverifikasi — sukses OTP membuktikan kepemilikan nomor
    $pdo->prepare("UPDATE users SET phone_verified = 1 WHERE id = ?")->execute([$user['id']]);

    logActivity('user', 'auth.login_otp', 'user', $user['id'], 'Login via WhatsApp OTP');

    flash('success', 'Selamat datang kembali, ' . $user['name'] . '!');

    // Default tujuan; bila ada login_redirect valid (mis. dari /lp/{slug}), hormati.
    // login_redirect sudah termasuk base path → dipakai sebagai URL root-relative.
    $redirectTo = ($user['role'] === 'admin') ? APP_URL . '/admin' : APP_URL . '/dashboard';
    $intended = (string) ($_SESSION['login_redirect'] ?? '');
    unset($_SESSION['login_redirect']);
    if (isSafeRedirectPath($intended)) {
        $redirectTo = $intended;
    }
    echo json_encode(['success' => true, 'redirect_to' => $redirectTo]);

} catch (Exception $e) {
    error_log('Verify OTP error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
}
