<?php

require_once __DIR__ . '/../NotificationAdapter.php';

class OneSenderAdapter implements NotificationAdapter {

    private string $apiKey;
    private string $apiUrl;

    public function __construct(string $apiKey = '') {
        $this->apiKey = $apiKey ?: getSetting('onesender_api_key', '');
        // OneSender umumnya SELF-HOSTED — tiap user punya URL server sendiri.
        // Baca dari setting (kosong = default OneSender Cloud). Ini akar bug
        // "tidak jalan": URL dulu hardcode api.onesender.id yang bukan server
        // milik user. Diadopsi dari averion-license yang sudah stabil.
        $url          = trim((string) getSetting('onesender_api_url', ''));
        $this->apiUrl = $url !== '' ? $url : 'https://api.onesender.id/api/v1/messages';
    }

    public function getName(): string {
        return 'onesender';
    }

    public function send(string $recipient, string $subject, string $body): array {
        if (empty($this->apiKey)) {
            error_log('[OneSender] Simulasi kirim ke ' . $recipient . ' (API key kosong).');
            return [
                'success'      => true,
                'message'      => '[SIMULASI] WA OneSender ke ' . $recipient,
                'raw_response' => null,
            ];
        }

        try {
            $normalizedRecipient = $this->normalizeNumber($recipient);

            if (strlen($normalizedRecipient) < 10 || strlen($normalizedRecipient) > 15) {
                error_log('[OneSender] Nomor tidak valid: ' . $normalizedRecipient);
                return ['success' => false, 'message' => 'Format nomor tujuan tidak valid', 'raw_response' => null];
            }

            $payload = [
                'recipient_type' => 'individual',
                'to'             => $normalizedRecipient,
                'type'           => 'text',
                'text'           => [
                    'body' => $body,
                ],
            ];

            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log('[OneSender] curl error ke ' . $normalizedRecipient . ': ' . $curlError);
                return ['success' => false, 'message' => 'Gagal terhubung ke OneSender', 'raw_response' => null];
            }

            // OneSender v1 bisa return body KOSONG saat sukses — treat sebagai array kosong
            $result = json_decode((string) $response, true);
            if ($result === null && trim((string) $response) === '') {
                $result = [];
            }

            $isHttpSuccess = ($httpCode >= 200 && $httpCode < 300);
            $hasBodyErrors = is_array($result) && (
                isset($result['errors']) ||
                (isset($result['code']) && $result['code'] >= 400)
            );

            if ($isHttpSuccess && !$hasBodyErrors) {
                return ['success' => true, 'message' => 'Pesan terkirim', 'raw_response' => $response];
            }

            $errorMsg = $result['message']
                ?? (isset($result['errors']) ? json_encode($result['errors']) : 'Gagal mengirim (HTTP ' . $httpCode . ')');
            error_log('[OneSender] Gagal kirim ke ' . $normalizedRecipient . ' HTTP ' . $httpCode . ': ' . $response);

            return ['success' => false, 'message' => $errorMsg, 'raw_response' => $response];

        } catch (Exception $e) {
            error_log('[OneSender] Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'raw_response' => null];
        }
    }

    /**
     * Normalisasi nomor ke format 62xxx yang WAJIB dipakai OneSender.
     * Berbeda dari StarSender yang terima 0xxx — OneSender tidak terima awalan 0.
     */
    private function normalizeNumber(string $number): string {
        // Delegasi ke helper bersama (mendukung ID 62 & MY 60).
        return normalizeWaNumber($number);
    }
}
