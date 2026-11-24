<?php

require_once __DIR__ . '/../NotificationAdapter.php';

class DripSenderAdapter implements NotificationAdapter {

    private string $apiKey;

    // Endpoint resmi DripSender (docs.dripsender.id/api-reference — OpenAPI):
    // POST https://api.dripsender.id/send. Auth: field 'api_key' DI BODY
    // (bukan header X-Api-Key). Field pesan bernama 'text' (bukan 'message').
    private string $apiUrl = 'https://api.dripsender.id/send';

    public function __construct(string $apiKey = '') {
        $this->apiKey = $apiKey ?: getSetting('dripsender_api_key', '');
    }

    public function getName(): string {
        return 'dripsender';
    }

    public function send(string $recipient, string $subject, string $body): array {
        if (empty($this->apiKey)) {
            error_log('DripSenderAdapter: API key belum diset, mode simulasi.');
            return [
                'success'      => true,
                'message'      => '[SIMULASI] WhatsApp DripSender akan dikirim ke ' . $recipient . ' setelah API key diisi.',
                'raw_response' => null,
            ];
        }

        try {
            // Payload PERSIS spec resmi DripSender: api_key + phone + text.
            // phone = internasional tanpa '+' (mis. 628xxx) → normalizeWaNumber.
            $payload = json_encode([
                'api_key' => $this->apiKey,
                'phone'   => normalizeWaNumber($recipient), // ID 62 & MY 60
                'text'    => $body,
            ]);

            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log('DripSenderAdapter curl error: ' . $curlErr);
                return ['success' => false, 'message' => 'Koneksi gagal: ' . $curlErr, 'raw_response' => null];
            }

            // Sukses = HTTP 2xx TANPA error di body (DripSender balas JSON
            // {status:false,message:...} saat gagal meski kadang HTTP 200).
            $data         = json_decode((string) $response, true);
            $isHttpOk     = $httpCode >= 200 && $httpCode < 300;
            $hasBodyError = is_array($data)
                && ((isset($data['status']) && $data['status'] === false)
                    || (isset($data['success']) && $data['success'] === false)
                    || isset($data['error']));

            if ($isHttpOk && !$hasBodyError) {
                return ['success' => true, 'message' => 'WhatsApp terkirim via DripSender', 'raw_response' => $response];
            }

            $errMsg = (is_array($data) && !empty($data['message']))
                ? $data['message'] : 'Gagal (HTTP ' . $httpCode . ')';
            error_log('DripSenderAdapter gagal HTTP ' . $httpCode . ': ' . $response);
            return ['success' => false, 'message' => $errMsg, 'raw_response' => $response];

        } catch (Exception $e) {
            error_log('DripSenderAdapter error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'raw_response' => null];
        }
    }
}
