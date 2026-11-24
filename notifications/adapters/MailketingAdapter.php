<?php

require_once __DIR__ . '/../NotificationAdapter.php';

class MailketingAdapter implements NotificationAdapter {

    // Kredensial & endpoint dikelola oleh MailketingClient (lihat send()).
    public function __construct(string $apiKey = '') {
        // Param dipertahankan untuk kompatibilitas; kredensial dibaca saat kirim.
    }

    public function getName(): string {
        return 'mailketing';
    }

    public function send(string $recipient, string $subject, string $body): array {
        require_once __DIR__ . '/../../helpers/MailketingClient.php';

        // Simulasi bila kredensial belum diisi (perilaku lama dipertahankan).
        // Resolusi key sama dengan MailketingClient: token baru → fallback key lama.
        $token = getSetting('mailketing_api_token', '') ?: getSetting('mailketing_api_key', '');
        if (empty($token)) {
            error_log('MailketingAdapter: API key belum diset, mode simulasi.');
            return [
                'success'      => true,
                'message'      => '[SIMULASI] Email akan dikirim ke ' . $recipient . ' setelah API key diisi.',
                'raw_response' => null,
            ];
        }

        // Delegasi ke implementasi battle-tested (payload form-encoded api_token +
        // from_email/from_name + retry Bearer). Satu sumber kebenaran untuk pengiriman.
        $result = MailketingClient::sendEmail($recipient, $subject, $body);

        return [
            'success'      => $result['ok'] ?? false,
            'message'      => $result['message'] ?? '',
            'raw_response' => $result['raw'] ?? null,
        ];
    }
}
