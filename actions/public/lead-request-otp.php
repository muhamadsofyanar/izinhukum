<?php
// ════════════════════════════════════════════════════════════════
// Lead Magnet — kirim OTP WhatsApp untuk promote lead anonim → user.
// Nomor diambil dari $_SESSION['lead_pending'] (diset oleh submit-lead),
// JADI tidak menerima nomor sembarang dari client. OTP disimpan di session.
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Request tidak valid.']);
    exit;
}

$pending = $_SESSION['lead_pending'] ?? null;
if (!is_array($pending) || empty($pending['phone']) || empty($pending['lead_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi pendaftaran tidak ditemukan. Silakan daftar ulang.']);
    exit;
}

$phone = normalizeWaNumber((string) $pending['phone']);
if (strlen($phone) < 10 || strlen($phone) > 15) {
    echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp tidak valid.']);
    exit;
}

// ─── Throttle (session): min 30 dtk antar kirim, maks 5 kirim per sesi ──
$otp  = $_SESSION['lead_otp'] ?? [];
$now  = time();
if (!empty($otp['sent_at']) && ($now - (int) $otp['sent_at']) < 30) {
    $wait = 30 - ($now - (int) $otp['sent_at']);
    echo json_encode(['success' => false, 'message' => "Tunggu {$wait} detik sebelum minta kode lagi."]);
    exit;
}
if ((int) ($otp['sends'] ?? 0) >= 5) {
    echo json_encode(['success' => false, 'message' => 'Terlalu banyak permintaan kode. Coba lagi nanti.']);
    exit;
}

// ─── Provider OTP (sama dengan login OTP) ────────────────────
$otpProvider = getSetting('wa_otp_provider', '');
if (empty($otpProvider)) {
    echo json_encode(['success' => false, 'message' => 'Verifikasi WhatsApp belum dikonfigurasi. Hubungi admin.']);
    exit;
}
if (getSetting($otpProvider . '_enabled', '0') !== '1') {
    echo json_encode(['success' => false, 'message' => 'Provider WhatsApp untuk OTP sedang nonaktif.']);
    exit;
}

require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';
$adapter = NotificationDispatcher::getAdapterPublic($otpProvider);
if (!$adapter) {
    echo json_encode(['success' => false, 'message' => 'Provider WhatsApp tidak dikenali.']);
    exit;
}

$code = generateOTPCode();

if ($otpProvider === 'kirimdev' && getSetting('kirimdev_otp_method', 'text') === 'template') {
    $sendResult = $adapter->sendOtpViaTemplate($phone, $code);
} else {
    $tplDefault = "Kode verifikasi *{app_name}* kamu:\n\n*{code}*\n\nBerlaku {minutes} menit. Jangan bagikan kode ini kepada siapapun.";
    $tpl = getSetting('wa_otp_message', $tplDefault);
    if (trim($tpl) === '' || !str_contains($tpl, '{code}')) $tpl = $tplDefault;
    $message = strtr($tpl, [
        '{code}'     => $code,
        '{app_name}' => getSetting('app_name', 'STIFIn Mulia'),
        '{minutes}'  => '10',
    ]);
    $sendResult = $adapter->send($phone, '', $message);
}

// Audit log
try {
    getDB()->prepare(
        "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
         VALUES ('lead_otp', 'whatsapp', ?, ?, ?, ?, NOW())"
    )->execute([
        $otpProvider,
        $phone,
        $sendResult['success'] ? 'sent' : 'failed',
        is_string($sendResult['raw_response'] ?? null) ? substr($sendResult['raw_response'], 0, 500) : ($sendResult['message'] ?? ''),
    ]);
} catch (Exception $e) {
    error_log('Lead OTP log error: ' . $e->getMessage());
}

if (!$sendResult['success']) {
    error_log('Lead OTP send failed [' . $otpProvider . ']: ' . ($sendResult['message'] ?? ''));
    echo json_encode(['success' => false, 'message' => 'Gagal mengirim kode OTP, coba lagi.']);
    exit;
}

// Simpan OTP di session
$_SESSION['lead_otp'] = [
    'code'     => $code,
    'phone'    => $phone,
    'expires'  => $now + 600,
    'attempts' => 0,
    'sent_at'  => $now,
    'sends'    => (int) ($otp['sends'] ?? 0) + 1,
];

echo json_encode(['success' => true]);
