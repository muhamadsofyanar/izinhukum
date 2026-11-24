<?php
// POST /actions/auth/reset-password — simpan password baru dari link reset.
// Token sekali pakai (used_at), kadaluarsa 1 jam, disimpan sebagai hash SHA-256.
require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/forgot-password');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('forgot_error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/forgot-password');
}

$token    = (string) ($_POST['token'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$confirm  = (string) ($_POST['confirm_password'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    flash('forgot_error', 'Link reset tidak valid. Minta link baru.', 'error');
    redirect('/forgot-password');
}
$backToForm = '/reset-password?token=' . urlencode($token);
if (strlen($password) < 8) {
    flash('reset_error', 'Password minimal 8 karakter.', 'error');
    redirect($backToForm);
}
if ($password !== $confirm) {
    flash('reset_error', 'Password dan konfirmasi tidak sama.', 'error');
    redirect($backToForm);
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // Kunci baris token (FOR UPDATE) agar dua submit paralel tak bisa sama-sama
    // memakai token yang sama.
    $stmt = $pdo->prepare(
        "SELECT pr.id, pr.user_id, u.email
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id AND u.status = 'active'
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $pdo->rollBack();
        flash('forgot_error', 'Link reset tidak valid atau sudah kadaluarsa. Minta link baru.', 'error');
        redirect('/forgot-password');
    }

    $userId = (int) $reset['user_id'];
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
        ->execute([(int) $reset['id']]);

    // Password baru = kepemilikan akun terbukti — cabut trusted device 2FA lama
    // (bila ada) agar sesi/perangkat lama pemegang password lama ikut gugur.
    try {
        $pdo->prepare("DELETE FROM user_trusted_devices WHERE user_id = ?")->execute([$userId]);
    } catch (Exception $e) {
        // Tabel belum ada di instalasi lama (pra-2FA) — abaikan.
    }

    $pdo->commit();

    logActivity('user', 'auth.password_reset', 'user', $userId, 'Password direset via link email');
    flash('success', 'Password berhasil diganti. Silakan login dengan password baru kamu.');
    redirect('/login?email=' . urlencode($reset['email']));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('reset-password error: ' . $e->getMessage());
    flash('forgot_error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
    redirect('/forgot-password');
}
