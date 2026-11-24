<?php

require_once __DIR__ . '/../NotificationAdapter.php';

class FonteAdapter implements NotificationAdapter {

    private string $apiKey;
    private string $apiUrl = 'https://api.fonnte.com/send';

    public function __construct(string $apiKey = '') {
        $this->apiKey = $apiKey ?: getSetting('fonte_api_key', '');
    }

    public function getName(): string {
        return 'fonte';
    }

    public function send(string $recipient, string $subject, string $body): array {
        error_log('[FONTE DEBUG] send() dipanggil. Recipient asli: ' . $recipient);

        if (empty($this->apiKey)) {
            error_log('[FONTE DEBUG] MASUK MODE SIMULASI - apiKey kosong');
            return [
                'success'      => true,
                'message'      => '[SIMULASI] WA Fonte ke ' . $recipient,
                'raw_response' => null,
            ];
        }

        try {
            // Normalisasi ke internasional (ID 62 & MY 60). target sudah lengkap
            // dengan kode negara, jadi countryCode tidak perlu di-hardcode lagi.
            $cleanedRecipient = normalizeWaNumber($recipient);
            error_log('[FONTE DEBUG] Nomor setelah normalisasi: ' . $cleanedRecipient);

            // PENTING: Fonnte pakai PHP array langsung — BUKAN json_encode().
            // cURL otomatis kirim sebagai application/x-www-form-urlencoded.
            $payload = [
                'target'      => $cleanedRecipient,
                'message'     => $body,
            ];

            error_log('[FONTE DEBUG] Payload (form array): ' . json_encode($payload));
            error_log('[FONTE DEBUG] URL: ' . $this->apiUrl);

            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $payload, // array langsung, tanpa json_encode
                CURLOPT_HTTPHEADER     => [
                    'Authorization: ' . $this->apiKey, // TANPA "Bearer " prefix
                ],
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            error_log('[FONTE DEBUG] HTTP Code: ' . $httpCode);
            error_log('[FONTE DEBUG] cURL Error: ' . ($curlError ?: 'tidak ada'));
            error_log('[FONTE DEBUG] Raw Response: ' . $response);

            if ($curlError) {
                return ['success' => false, 'message' => 'Gagal terhubung ke Fonte', 'raw_response' => null];
            }

            $result      = json_decode($response, true);
            $isHttpSuccess = ($httpCode >= 200 && $httpCode < 300);
            $statusField  = $result['status'] ?? null;

            // Beberapa API kirim error dengan HTTP 200 — jika ada field 'status'
            // eksplisit bernilai false, anggap gagal. Sesuaikan setelah lihat
            // raw response sungguhan dari log.
            $success = $isHttpSuccess && $statusField !== false;

            error_log('[FONTE DEBUG] Dianggap ' . ($success ? 'SUKSES' : 'GAGAL') . ', status field: ' . json_encode($statusField));

            return [
                'success'      => $success,
                'message'      => $success
                    ? ($result['detail'] ?? 'Pesan terkirim')
                    : ($result['reason'] ?? $result['detail'] ?? 'Gagal mengirim (HTTP ' . $httpCode . ')'),
                'raw_response' => $response,
            ];

        } catch (Exception $e) {
            error_log('[FONTE DEBUG] EXCEPTION: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'raw_response' => null];
        }
    }
}
