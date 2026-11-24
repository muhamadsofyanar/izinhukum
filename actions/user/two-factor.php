<?php
// POST /actions/user/two-factor  (action=start|confirm|disable|forget_devices)
// Kelola 2FA TOTP milik sendiri — khusus akun admin/staff.
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    redirect('/profile');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/profile/2fa');
}

$action = trim($_POST['action'] ?? '');
$userId = (int) $_SESSION['user_id'];
$pdo    = getDB();

try {
    switch ($action) {

        // ── Mulai aktivasi: generate secret (belum disimpan ke DB) ──
        case 'start':
            $_SESSION['totp_setup_secret'] = totpGenerateSecret();
            redirect('/profile/2fa');
            break;

        // ── Konfirmasi kode pertama → 2FA resmi aktif ─────────────
        case 'confirm':
            $secret = (string) ($_SESSION['totp_setup_secret'] ?? '');
            if ($secret === '') {
                flash('error', 'Sesi setup kedaluwarsa. Mulai ulang aktivasi.', 'error');
                redirect('/profile/2fa');
            }
            if (!totpVerify($secret, trim($_POST['code'] ?? ''))) {
                flash('error', 'Kode salah. Pastikan jam HP akurat, lalu coba lagi.', 'error');
                redirect('/profile/2fa');
            }

            $codes = totpGenerateBackupCodes(8);
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_backup_codes = ? WHERE id = ?')
                ->execute([$secret, json_encode($codes['hashes']), $userId]);

            unset($_SESSION['totp_setup_secret']);
            $_SESSION['totp_new_backup_codes'] = $codes['plain']; // ditampilkan SEKALI
            logActivity(($_SESSION['role'] === 'admin') ? 'admin' : 'user', 'auth.2fa_enabled', 'user', $userId, '2FA TOTP diaktifkan');
            flash('success', '2FA berhasil diaktifkan. Simpan backup codes di tempat aman!');
            redirect('/profile/2fa');
            break;

        // ── Nonaktifkan (wajib password akun) ─────────────────────
        case 'disable':
            $row = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $row->execute([$userId]);
            $hash = (string) ($row->fetchColumn() ?: '');
            if ($hash === '' || !password_verify($_POST['password'] ?? '', $hash)) {
                flash('error', 'Password salah. 2FA tidak dinonaktifkan.', 'error');
                redirect('/profile/2fa');
            }
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_backup_codes = NULL WHERE id = ?')
                ->execute([$userId]);
            totpForgetDevices($userId);
            logActivity(($_SESSION['role'] === 'admin') ? 'admin' : 'user', 'auth.2fa_disabled', 'user', $userId, '2FA TOTP dinonaktifkan');
            flash('success', '2FA dinonaktifkan.');
            redirect('/profile/2fa');
            break;

        // ── Lupakan semua perangkat terpercaya ────────────────────
        case 'forget_devices':
            totpForgetDevices($userId);
            flash('success', 'Semua perangkat terpercaya dilupakan — login berikutnya minta kode 2FA lagi.');
            redirect('/profile/2fa');
            break;

        default:
            redirect('/profile/2fa');
    }
} catch (Exception $e) {
    error_log('two-factor action error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
    redirect('/profile/2fa');
}
