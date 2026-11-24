<?php
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/profile');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/profile');
}

$action = trim($_POST['action'] ?? '');
$userId = (int) $_SESSION['user_id'];

try {
    $pdo = getDB();

    switch ($action) {

        // ── Update Info Dasar ─────────────────────────────────
        case 'update_info':
            $name  = trim($_POST['name']  ?? '');
            $phone = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));

            if (strlen($name) < 2) {
                flash('error', 'Nama minimal 2 karakter.', 'error');
                redirect('/profile');
            }
            if (strlen($name) > 100) {
                flash('error', 'Nama maksimal 100 karakter.', 'error');
                redirect('/profile');
            }
            if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
                flash('error', 'Nomor WhatsApp wajib diisi (10–15 digit).', 'error');
                redirect('/profile');
            }
            $phone = normalizeWaNumber($phone);

            $stmt = $pdo->prepare(
                'UPDATE users SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute([$name, $phone, $userId]);

            // Update session name
            $_SESSION['name'] = $name;

            flash('success', 'Profil berhasil diperbarui.');
            redirect('/profile');

        // ── Ganti Password ────────────────────────────────────
        case 'change_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword     = $_POST['new_password']     ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            // Ambil data user untuk menentukan apakah ini akun Google tanpa password.
            $stmt = $pdo->prepare('SELECT google_id, password FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                flash('error', 'Akun tidak ditemukan.', 'error');
                redirect('/profile');
            }

            // User Google yang belum punya password lokal → tidak perlu password lama.
            $isGoogleUser = !empty($user['google_id']) && empty($user['password']);

            if (empty($newPassword)) {
                flash('error', 'Password baru wajib diisi.', 'error');
                redirect('/profile');
            }

            if (strlen($newPassword) < 8) {
                flash('error', 'Password baru minimal 8 karakter.', 'error');
                redirect('/profile');
            }

            if ($newPassword !== $confirmPassword) {
                flash('error', 'Konfirmasi password tidak cocok.', 'error');
                redirect('/profile');
            }

            if (!$isGoogleUser) {
                // Verifikasi password lama (alur user biasa).
                if (empty($currentPassword)) {
                    flash('error', 'Password saat ini wajib diisi.', 'error');
                    redirect('/profile');
                }

                if (!password_verify($currentPassword, $user['password'])) {
                    flash('error', 'Password saat ini tidak sesuai.', 'error');
                    redirect('/profile');
                }

                // Jangan izinkan password sama
                if (password_verify($newPassword, $user['password'])) {
                    flash('error', 'Password baru tidak boleh sama dengan password lama.', 'error');
                    redirect('/profile');
                }
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt    = $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$newHash, $userId]);

            flash('success', 'Password berhasil diubah. Silakan login kembali.');

            // Paksa logout setelah ganti password
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            session_destroy();

            header('Location: ' . rtrim(APP_URL, '/') . '/login');
            exit;

        default:
            flash('error', 'Aksi tidak dikenali.', 'error');
            redirect('/profile');
    }

} catch (Exception $e) {
    error_log('Update profile error [' . $action . ']: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
    redirect('/profile');
}
