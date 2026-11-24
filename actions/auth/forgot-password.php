<?php
// POST /actions/auth/forgot-password — kirim link reset password via email.
// Respons SELALU generik ("jika email terdaftar, link terkirim") agar tidak
// membocorkan apakah sebuah email punya akun (anti user-enumeration).
require_once __DIR__ . '/../../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/forgot-password');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('forgot_error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/forgot-password');
}

$email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('forgot_error', 'Format email tidak valid.', 'error');
    redirect('/forgot-password');
}

// Rate limit per-IP: cegah spam email reset & probing massal.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkIpRateLimit('forgot', $ip, 5, 30)) {
    flash('forgot_error', 'Terlalu banyak permintaan reset. Coba lagi dalam 30 menit.', 'error');
    redirect('/forgot-password');
}
recordIpAction('forgot', $ip);

$genericMsg = 'Jika email itu terdaftar, link reset password sudah dikirim ke sana. Cek inbox (dan folder spam) kamu.';

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Batasi juga per-akun (3 link aktif / jam) agar satu email tak dibanjiri
        // dari banyak IP berbeda.
        $cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM password_resets
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $cnt->execute([(int) $user['id']]);
        if ((int) $cnt->fetchColumn() < 3) {
            // Token mentah hanya ada di email; DB cuma simpan hash-nya —
            // kebocoran DB tidak bisa dipakai mereset akun orang.
            $token = bin2hex(random_bytes(32));
            // Batalkan token lama yang belum terpakai (satu link aktif terakhir saja).
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([(int) $user['id']]);
            $pdo->prepare(
                "INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
            )->execute([(int) $user['id'], hash('sha256', $token)]);

            $resetUrl = baseUrl('reset-password?token=' . $token);
            $appName  = getSetting('app_name', 'STIFIn Mulia');
            $body = '<p>Halo <strong>' . e($user['name']) . '</strong>,</p>'
                  . '<p>Kami menerima permintaan reset password untuk akun kamu di ' . e($appName) . '. Klik tombol di bawah untuk membuat password baru — link berlaku <strong>1 jam</strong>.</p>'
                  . '<p style="text-align:center;margin:28px 0"><a href="' . e($resetUrl) . '" style="display:inline-block;background:' . e(getSetting('brand_color', '#6366f1')) . ';color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">Reset Password &rarr;</a></p>'
                  . '<p style="font-size:13px;color:#8a94a6">Atau buka tautan ini: ' . e($resetUrl) . '</p>'
                  . '<p style="font-size:13px;color:#8a94a6">Bukan kamu yang meminta? Abaikan email ini — password kamu tidak berubah.</p>';
            sendEmail($user['email'], 'Reset Password — ' . $appName, emailLayout('Reset Password', $body, 'info'), (int) $user['id']);
        }
    }
} catch (Exception $e) {
    error_log('forgot-password error: ' . $e->getMessage());
}

// Selalu pesan yang sama, ada akun ataupun tidak.
flash('forgot_success', $genericMsg);
redirect('/forgot-password');
