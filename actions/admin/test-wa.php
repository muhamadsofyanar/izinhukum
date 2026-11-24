<?php
// POST /actions/admin/test-wa
// Kirim pesan WhatsApp percobaan via adapter provider tertentu untuk verifikasi konfigurasi.
// Mengadaptasi test_onesender/test_dripsender/test_starsender (project lain) ke konvensi Averion:
// memakai adapter notifications/* yang sudah ada, bukan library terpisah.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman.']);
    exit;
}

$provider = strtolower(trim($_POST['provider'] ?? ''));
$to       = trim($_POST['to'] ?? '');
$message  = trim($_POST['message'] ?? '');

// Map provider → adapter (konsisten dengan NotificationDispatcher).
$map = [
    'fonte'      => 'FonteAdapter',
    'onesender'  => 'OneSenderAdapter',
    'starsender' => 'StarSenderAdapter',
    'dripsender' => 'DripSenderAdapter',
    'kirimdev'   => 'KirimdevAdapter',
    'averionrelay' => 'AverionRelayAdapter',
];

if (!isset($map[$provider])) {
    echo json_encode(['success' => false, 'message' => 'Provider tidak dikenal.']);
    exit;
}

if ($to === '') {
    echo json_encode(['success' => false, 'message' => 'Nomor tujuan wajib diisi.']);
    exit;
}

if ($message === '') {
    $appName = getSetting('app_name', defined('APP_NAME') ? APP_NAME : 'STIFIn Mulia');
    $message = 'Test WhatsApp dari ' . $appName . '. Jika kamu menerima pesan ini, konfigurasi sudah benar. ✅';
}

$class = $map[$provider];

try {
    require_once __DIR__ . '/../../notifications/adapters/' . $class . '.php';
    /** @var NotificationAdapter $adapter */
    $adapter = new $class();

    // Adapter WA hanya memakai $body; $subject diabaikan tapi tetap diisi sesuai kontrak.
    $result = $adapter->send($to, 'Test WhatsApp', $message);

    echo json_encode([
        'success' => !empty($result['success']),
        'message' => $result['message'] ?? (!empty($result['success']) ? 'Pesan terkirim.' : 'Gagal mengirim.'),
    ]);
} catch (Throwable $e) {
    error_log('test-wa error [' . $provider . ']: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat mengirim pesan tes.']);
}
