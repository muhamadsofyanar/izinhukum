<?php
// POST /actions/chat/lead — PUBLIC endpoint (tanpa login, tanpa CSRF; pola
// sama dgn /actions/chat/send). Menyimpan lead dari widget AI Chat (nama +
// WhatsApp, form muncul setelah pesan ke-2) beserta transcript percakapan
// sejauh ini. Return token sesi — dipakai widget agar pesan berikutnya ikut
// di-append ke transcript (send.php/stream.php).
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');

function chatLeadJson(int $httpCode, array $payload): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (getSetting('ai_chat_enabled') !== '1') {
    chatLeadJson(403, ['error' => 'Chat tidak tersedia.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatLeadJson(405, ['error' => 'Metode tidak diizinkan.']);
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip = substr(trim(explode(',', $ip)[0]), 0, 45);

$name = mb_substr(trim(strip_tags($_POST['name'] ?? '')), 0, 120);
$wa   = normalizeWaNumber(trim($_POST['whatsapp'] ?? ''));

if (mb_strlen($name) < 2) {
    chatLeadJson(422, ['error' => 'Nama minimal 2 karakter.']);
}
if (!preg_match('/^62[0-9]{8,13}$/', $wa)) {
    chatLeadJson(422, ['error' => 'Nomor WhatsApp tidak valid.']);
}

try {
    $pdo = getDB();

    // Rate limit ringan: maks 3 lead per IP per jam (anti-spam form).
    $rl = $pdo->prepare('SELECT COUNT(*) FROM chat_leads WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $rl->execute([$ip]);
    if ((int) $rl->fetchColumn() >= 3) {
        chatLeadJson(429, ['error' => 'Terlalu banyak percobaan. Coba lagi nanti.']);
    }

    // Transcript percakapan sejauh ini (dikirim widget, format history chat).
    $history = json_decode($_POST['history'] ?? '[]', true);
    $lines   = [];
    if (is_array($history)) {
        foreach (array_slice($history, -30) as $h) {
            if (!is_array($h) || !isset($h['role'], $h['content']) || !is_string($h['content'])) continue;
            $who = ($h['role'] === 'user') ? 'Pengunjung' : 'AI';
            $lines[] = $who . ': ' . mb_substr(trim($h['content']), 0, 2000);
        }
    }
    $transcript = $lines ? implode("\n", $lines) : null;

    $token = bin2hex(random_bytes(16));
    $pdo->prepare(
        'INSERT INTO chat_leads (name, whatsapp, token, transcript, ip_address) VALUES (?, ?, ?, ?, ?)'
    )->execute([$name, $wa, $token, $transcript, $ip]);

    chatLeadJson(200, ['success' => true, 'token' => $token]);
} catch (Exception $e) {
    error_log('chat/lead error: ' . $e->getMessage());
    chatLeadJson(500, ['error' => 'Gagal menyimpan. Coba lagi.']);
}
