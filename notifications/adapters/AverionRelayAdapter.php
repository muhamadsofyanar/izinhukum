<?php

require_once __DIR__ . '/../NotificationAdapter.php';

/**
 * Averion Relay — WhatsApp gateway self-hosted milik sendiri (bukan pihak ketiga).
 * Kirim lewat endpoint publik Relay: POST /api/send dengan header X-Api-Key.
 * Kontrak: body {"to": "<nomor>", "message": "<teks>"} -> {"success": bool, ...}.
 */
class AverionRelayAdapter implements NotificationAdapter {

    private string $apiKey;
    private string $apiUrl;

    public function __construct(string $apiKey = '') {
        $this->apiKey = $apiKey ?: getSetting('averionrelay_api_key', '');
        // Endpoint Relay bisa dikonfigurasi. Default ke URL produksi (relay.averion.id)
        // supaya instalasi customer tidak salah mengarah ke localhost. Saat dev lokal,
        // isi setting averionrelay_api_url secara eksplisit ke http://localhost/...
        $url          = trim((string) getSetting('averionrelay_api_url', ''));
        $this->apiUrl = $url !== '' ? $url : 'https://relay.averion.id/api/send';
    }

    public function getName(): string {
        return 'averionrelay';
    }

    public function send(string $recipient, string $subject, string $body): array {
        if (empty($this->apiKey)) {
            error_log('[AverionRelay] Simulasi kirim ke ' . $recipient . ' (API key kosong).');
            return [
                'success'      => true,
                'message'      => '[SIMULASI] WA Averion Relay ke ' . $recipient,
                'raw_response' => null,
            ];
        }

        try {
            $normalizedRecipient = normalizeWaNumber($recipient);

            if (strlen($normalizedRecipient) < 10 || strlen($normalizedRecipient) > 15) {
                error_log('[AverionRelay] Nomor tidak valid: ' . $normalizedRecipient);
                return ['success' => false, 'message' => 'Format nomor tujuan tidak valid', 'raw_response' => null];
            }

            $payload = [
                'to'      => $normalizedRecipient,
                'message' => $body,
            ];

            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Api-Key: ' . $this->apiKey,
                ],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log('[AverionRelay] curl error ke ' . $normalizedRecipient . ': ' . $curlError);
                return ['success' => false, 'message' => 'Gagal terhubung ke Averion Relay', 'raw_response' => null];
            }

            $result = json_decode((string) $response, true);
            $isHttpSuccess = ($httpCode >= 200 && $httpCode < 300);

            if ($isHttpSuccess && is_array($result) && !empty($result['success'])) {
                return ['success' => true, 'message' => 'Pesan terkirim', 'raw_response' => $response];
            }

            // Terjemahkan error umum dari Relay agar admin paham.
            $err = is_array($result) ? ($result['error'] ?? '') : '';
            $errorMsg = match ($err) {
                // Relay dijual sbg layanan berlangganan (hosted): pemakai punya
                // License Key, BUKAN config.php. Penyebab 401 paling sering = key
                // salah tempel atau langganan sudah habis — arahkan ke sana, jangan
                // ke file server yang tak mereka miliki.
                'unauthorized'          => 'License Key Averion Relay ditolak (401). Pastikan key benar dan langganan masih aktif — perpanjang di vendor.averion.id bila sudah kedaluwarsa.',
                'session_not_connected' => 'Nomor WA di Relay belum terhubung. Scan QR dulu di dashboard Relay.',
                'rate_limited'          => 'Terlalu banyak permintaan ke Relay (rate limit).',
                'invalid_number'        => 'Format nomor tujuan tidak valid.',
                'empty_message'         => 'Isi pesan kosong.',
                default                 => $err !== '' ? $err : ('Gagal mengirim via Relay (HTTP ' . $httpCode . ')'),
            };
            error_log('[AverionRelay] Gagal kirim ke ' . $normalizedRecipient . ' HTTP ' . $httpCode . ': ' . $response);

            return ['success' => false, 'message' => $errorMsg, 'raw_response' => $response];

        } catch (Exception $e) {
            error_log('[AverionRelay] Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'raw_response' => null];
        }
    }
}
