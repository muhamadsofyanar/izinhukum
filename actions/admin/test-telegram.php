<?php
// POST /actions/admin/test-telegram
// Kirim pesan Telegram percobaan untuk verifikasi konfigurasi bot (Telegram Alerts).
// Memakai TelegramAdapter yang sama dengan dispatcher — bukan library terpisah.
// Pola mengikuti actions/admin/test-wa.php (respons JSON).
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

// Chat ID tujuan: override dari form bila diisi, selain itu pakai setting tersimpan.
$to = trim($_POST['to'] ?? '');
if ($to === '') {
    $to = trim((string) getSetting('telegram_chat_id', ''));
}
if ($to === '') {
    echo json_encode(['success' => false, 'message' => 'Chat ID Telegram belum diisi/tersimpan. Simpan dulu lalu coba lagi.']);
    exit;
}

try {
    require_once __DIR__ . '/../../notifications/adapters/TelegramAdapter.php';
    // Konstruktor tanpa argumen → baca telegram_bot_token tersimpan (sama dgn test-wa).
    $adapter = new TelegramAdapter();

    $appName = getSetting('app_name', defined('APP_NAME') ? APP_NAME : 'STIFIn Mulia');
    $message = "\xE2\x9C\x85 Test Telegram dari " . $appName . "\n"
             . "Konfigurasi bot berhasil. Notifikasi internal siap. \xF0\x9F\x9A\x80\n"
             . "Waktu: " . date('d M Y, H:i') . " WIB";

    $result = $adapter->send($to, 'Test Telegram', $message);

    try {
        getDB()->prepare(
            "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
             VALUES ('test_send', 'telegram', 'telegram', ?, ?, ?, NOW())"
        )->execute([
            $to,
            !empty($result['success']) ? 'sent' : 'failed',
            (string) ($result['message'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('test-telegram log error: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => !empty($result['success']),
        'message' => $result['message'] ?? (!empty($result['success']) ? 'Pesan terkirim.' : 'Gagal mengirim.'),
    ]);
} catch (Throwable $e) {
    error_log('test-telegram error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat mengirim pesan tes.']);
}
