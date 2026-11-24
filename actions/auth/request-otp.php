<?php
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Request tidak valid.']);
    exit;
}

// Toggle login WA hanya dicek di halaman (/login & /login-otp menyembunyikan
// tombolnya) — endpoint-nya sendiri tak pernah cek, jadi POST langsung tetap
// mengirim OTP walau admin sudah mematikan fitur ini (dan tetap menagih kuota
// provider WA). Tegakkan di sini juga.
if (getSetting('wa_otp_login_enabled', '0') !== '1') {
    echo json_encode(['success' => false, 'message' => 'Login via WhatsApp sedang tidak tersedia.']);
    exit;
}

// Normalkan nomor ke format internasional (ID 62 & MY 60), konsisten dgn adapter WA.
$phone = normalizeWaNumber($_POST['phone'] ?? '');

if (strlen($phone) < 10 || strlen($phone) > 15) {
    echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp tidak valid.']);
    exit;
}

try {
    $pdo = getDB();

    // Cek nomor terdaftar — coba 628xxx dulu, lalu fallback ke 08xxx
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE phone = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if (!$user) {
        $altPhone = '0' . substr($phone, 2);
        $stmt->execute([$altPhone]);
        $user = $stmt->fetch();
    }

    // Anti-enumerasi: JANGAN bocorkan apakah nomor terdaftar. Balas identik dengan
    // jalur sukses (frontend lanjut ke input OTP) TANPA mengirim OTP. Nomor asing
    // tak akan pernah punya kode valid → verifikasi gagal wajar, tapi attacker tidak
    // bisa membedakan "terdaftar" vs "tidak" dari respons endpoint ini.
    if (!$user) {
        echo json_encode(['success' => true]);
        exit;
    }

    // Rate limiting
    $rateCheck = checkOTPRateLimit($phone);
    if (!$rateCheck['allowed']) {
        echo json_encode(['success' => false, 'message' => $rateCheck['message']]);
        exit;
    }

    // Generate & simpan OTP
    $code      = generateOTPCode();
    $expiresAt = date('Y-m-d H:i:s', time() + 600);

    $pdo->prepare(
        "INSERT INTO otp_codes (user_id, phone, code, expires_at) VALUES (?, ?, ?, ?)"
    )->execute([$user['id'], $phone, $code, $expiresAt]);

    // GC: buang OTP lama agar tabel tetap kecil (tanpa cron). Hanya berbasis waktu
    // (≥1 hari) — JANGAN hapus berdasarkan is_used, karena rate-limit 5/jam
    // menghitung semua baris dalam 1 jam terakhir.
    try {
        $pdo->exec('DELETE FROM otp_codes WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Exception $gcEx) {
        error_log('otp_codes GC error: ' . $gcEx->getMessage());
    }

    // Kirim via provider OTP yang dipilih admin
    $otpProvider = getSetting('wa_otp_provider', '');
    if (empty($otpProvider)) {
        echo json_encode(['success' => false, 'message' => 'Provider WhatsApp untuk OTP belum dikonfigurasi.']);
        exit;
    }

    // Cek apakah provider OTP yang dipilih sedang aktif (toggle enabled)
    if (getSetting($otpProvider . '_enabled', '0') !== '1') {
        error_log("Request OTP: provider {$otpProvider} dipilih tapi sedang dinonaktifkan admin.");
        echo json_encode(['success' => false, 'message' => 'Provider WhatsApp untuk OTP sedang nonaktif, hubungi admin.']);
        exit;
    }

    require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
    $adapter = NotificationDispatcher::getAdapterPublic($otpProvider);

    if (!$adapter) {
        echo json_encode(['success' => false, 'message' => 'Provider WhatsApp tidak dikenali.']);
        exit;
    }

    // Kirimdev mendukung dua mode: template message (bypass 24h window) atau teks bebas.
    // Mode template aktif hanya setelah template disetujui Meta dan admin mengaktifkannya.
    if ($otpProvider === 'kirimdev' && getSetting('kirimdev_otp_method', 'text') === 'template') {
        $sendResult = $adapter->sendOtpViaTemplate($phone, $code);
    } else {
        // Teks pesan OTP bisa diedit admin di /admin/integrations (provider teks-bebas).
        $otpMsgDefault = "Kode OTP login *{app_name}* kamu:\n\n*{code}*\n\nBerlaku {minutes} menit. Jangan bagikan kode ini kepada siapapun.";
        $otpTpl = getSetting('wa_otp_message', $otpMsgDefault);
        if (trim($otpTpl) === '') $otpTpl = $otpMsgDefault;
        // {code} wajib ada — kalau admin menghapusnya, paksa pakai default agar OTP tetap terkirim.
        if (!str_contains($otpTpl, '{code}')) $otpTpl = $otpMsgDefault;
        $message = strtr($otpTpl, [
            '{code}'     => $code,
            '{app_name}' => getSetting('app_name', 'STIFIn Mulia'),
            '{minutes}'  => '10',
        ]);
        $sendResult = $adapter->send($phone, '', $message);
    }

    // Catat ke notification_logs untuk audit trail
    try {
        $pdo->prepare(
            "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
             VALUES ('otp_login', 'whatsapp', ?, ?, ?, ?, NOW())"
        )->execute([
            $otpProvider,
            $phone,
            $sendResult['success'] ? 'sent' : 'failed',
            is_string($sendResult['raw_response'] ?? null)
                ? substr($sendResult['raw_response'], 0, 500)
                : ($sendResult['message'] ?? ''),
        ]);
    } catch (Exception $logEx) {
        error_log('OTP notification_log insert error: ' . $logEx->getMessage());
    }

    if (!$sendResult['success']) {
        error_log('OTP send failed [' . $otpProvider . ']: ' . ($sendResult['message'] ?? ''));
        echo json_encode(['success' => false, 'message' => 'Gagal mengirim kode OTP, coba lagi.']);
        exit;
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('Request OTP error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
}
