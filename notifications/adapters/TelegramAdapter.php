<?php

require_once __DIR__ . '/../NotificationAdapter.php';

/**
 * Adapter notifikasi Telegram (channel INTERNAL admin — bukan customer-facing).
 *
 * Mengirim pesan ke Telegram Bot API (metode sendMessage). "recipient" di sini
 * adalah CHAT ID tujuan (numerik untuk chat pribadi/grup, atau '@username' untuk
 * channel publik) — di-resolve oleh NotificationDispatcher dari setting
 * telegram_chat_id, bukan dari data per-customer.
 *
 * Pesan dikirim sebagai TEKS POLOS (tanpa parse_mode) — konsisten dengan pola
 * adapter WhatsApp: aman untuk nama/catatan buyer ber-karakter & < > tanpa perlu
 * escaping, dan emoji + baris baru tetap tampil apa adanya.
 */
class TelegramAdapter implements NotificationAdapter {

    private string $botToken;

    public function __construct(string $botToken = '') {
        // ?: short-circuit → getSetting() TIDAK dipanggil bila token sudah diberikan.
        $this->botToken = $botToken ?: getSetting('telegram_bot_token', '');
    }

    public function getName(): string {
        return 'telegram';
    }

    public function send(string $recipient, string $subject, string $body): array {
        // Token kosong → mode simulasi (selaras semua adapter lain): tak ada HTTP,
        // dispatcher tetap mencatat 'sent' saat uji lokal tanpa kredензial.
        if (empty($this->botToken)) {
            return [
                'success'      => true,
                'message'      => '[SIMULASI] Telegram ke ' . $recipient,
                'raw_response' => null,
            ];
        }

        if (trim($recipient) === '') {
            return ['success' => false, 'message' => 'Chat ID Telegram kosong', 'raw_response' => null];
        }

        // Telegram tak punya "subject"; pakai body, fallback ke subject bila body kosong.
        $text = trim($body) !== '' ? $body : $subject;

        try {
            $url     = 'https://api.telegram.org/bot' . $this->botToken . '/sendMessage';
            $payload = [
                'chat_id'                  => $recipient,
                'text'                     => $text,
                'disable_web_page_preview' => true,
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log('TelegramAdapter cURL error: ' . $curlError);
                return ['success' => false, 'message' => 'Gagal terhubung ke Telegram', 'raw_response' => null];
            }

            $result  = json_decode($response, true);
            $success = $httpCode >= 200 && $httpCode < 300 && !empty($result['ok']);

            return [
                'success'      => $success,
                'message'      => $success
                    ? 'Pesan terkirim'
                    : ('Gagal: ' . ($result['description'] ?? ('HTTP ' . $httpCode))),
                'raw_response' => $response,
            ];

        } catch (\Throwable $e) {
            error_log('TelegramAdapter exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'raw_response' => null];
        }
    }
}
