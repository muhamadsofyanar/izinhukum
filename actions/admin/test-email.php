<?php
// POST /actions/admin/test-email
// Kirim email percobaan via Mailketing untuk memverifikasi konfigurasi.
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

$email = trim($_POST['email'] ?? '');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Format email tidak valid.']);
    exit;
}

$appName = getSetting('app_name', defined('APP_NAME') ? APP_NAME : 'STIFIn Mulia');

$subject = 'Test Email dari ' . $appName;
$inner =
    '<p style="margin:0 0 12px;">Halo!</p>'
    . '<p style="margin:0 0 12px;">Ini adalah email test dari <strong>' . e($appName) . '</strong>.</p>'
    . '<p style="margin:0;">Jika kamu menerima email ini, konfigurasi Mailketing sudah benar. ✅</p>';
$body = function_exists('emailLayout') ? emailLayout($subject, $inner) : $inner;

try {
    $result = MailketingClient::sendEmail($email, $subject, $body);

    if (!empty($result['ok'])) {
        echo json_encode(['success' => true, 'message' => 'Email tes berhasil dikirim ke ' . $email . '.']);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengirim: ' . ($result['message'] ?? 'kesalahan tidak diketahui') . '.',
        ]);
    }
} catch (Throwable $e) {
    error_log('test-email error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat mengirim email tes.']);
}
