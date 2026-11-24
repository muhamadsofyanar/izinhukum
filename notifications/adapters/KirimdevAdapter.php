<?php

require_once __DIR__ . '/../NotificationAdapter.php';

class KirimdevAdapter implements NotificationAdapter {

    private string $apiKey;
    private string $phoneNumberId;
    private string $otpTemplateName;
    private string $apiUrl = 'https://api.kirimdev.com/v1';

    public function __construct(string $apiKey = '', string $phoneNumberId = '') {
        $this->apiKey          = $apiKey        ?: getSetting('kirimdev_api_key',          '');
        $this->phoneNumberId   = $phoneNumberId ?: getSetting('kirimdev_phone_number_id',  '');
        $this->otpTemplateName = getSetting('kirimdev_otp_template_name', '');
    }

    public function getName(): string {
        return 'kirimdev';
    }

    /**
     * Kirim pesan teks bebas (free-form text).
     * Hanya berhasil jika penerima sudah chat ke nomor bisnis dalam 24 jam terakhir.
     * Untuk OTP ke nomor arbitrary, gunakan sendOtpViaTemplate() setelah template disetujui Meta.
     */
    public function send(string $recipient, string $subject, string $body): array {
        if (empty($this->apiKey) || empty($this->phoneNumberId)) {
            return [
                'success'      => true,
                'message'      => '[SIMULASI] WhatsApp via Kirimdev akan dikirim ke ' . $recipient . ' setelah dikonfigurasi.',
                'raw_response' => null,
            ];
        }

        try {
            $recipient = normalizeWaNumber($recipient); // ID 62 & MY 60
            $url       = $this->apiUrl . '/' . $this->phoneNumberId . '/messages';

            $payload = json_encode([
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $recipient,
                'type'              => 'text',
                'text'              => ['body' => $body, 'preview_url' => false],
            ]);

            // Idempotency-Key per menit: cegah pesan dobel saat retry
            $idempotencyKey = hash('sha256', $recipient . $body . date('YmdHi'));

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'Idempotency-Key: ' . $idempotencyKey,
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log('KirimdevAdapter curl error: ' . $curlErr);
                return ['success' => false, 'message' => 'Koneksi gagal: ' . $curlErr, 'raw_response' => null];
            }

            $result = json_decode($response, true);

            if ($httpCode === 200 && !empty($result['data']['id'])) {
                return [
                    'success'      => true,
                    'message'      => 'Pesan terkirim, status: ' . ($result['data']['status'] ?? 'queued'),
                    'raw_response' => $response,
                ];
            }

            $errorCode = $result['error']['code'] ?? $result['data']['error']['code'] ?? 'unknown';
            error_log('KirimdevAdapter send failed [' . $errorCode . ']: ' . $response);

            $errorMessages = [
                'outside_24h_window'   => 'Nomor tujuan belum chat dalam 24 jam. Gunakan template message untuk OTP.',
                'marketing_opted_out'  => 'Penerima berhenti dari pesan marketing.',
                'account_restricted'   => 'Akun WhatsApp Kirimdev dibatasi, cek dashboard.',
                'consent_required'     => 'Penerima belum opt-in.',
                'customer_suspended'   => 'Akun Kirimdev disuspend.',
                'upstream_error'       => 'Koneksi ke WhatsApp terputus.',
                'invalid_phone_number' => 'Format nomor tujuan tidak valid.',
            ];

            return [
                'success'      => false,
                'message'      => $errorMessages[$errorCode] ?? 'Gagal mengirim (kode: ' . $errorCode . ')',
                'raw_response' => $response,
            ];

        } catch (Exception $e) {
            error_log('KirimdevAdapter error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem.', 'raw_response' => null];
        }
    }

    /**
     * Kirim OTP via WhatsApp Template Message — bypass 24-hour conversation window.
     * Template HARUS sudah disetujui Meta sebelum method ini efektif.
     * Aktifkan di /admin/integrations setelah approval selesai.
     *
     * @param string $recipient Nomor tujuan format internasional (628xxx)
     * @param string $otpCode   Kode OTP 6 digit
     */
    public function sendOtpViaTemplate(string $recipient, string $otpCode): array {
        if (empty($this->apiKey) || empty($this->phoneNumberId)) {
            return [
                'success'      => true,
                'message'      => '[SIMULASI] Template OTP Kirimdev akan dikirim setelah dikonfigurasi.',
                'raw_response' => null,
            ];
        }

        if (empty($this->otpTemplateName)) {
            error_log('KirimdevAdapter: nama template OTP belum diset di settings (kirimdev_otp_template_name).');
            return [
                'success' => false,
                'message' => 'Template OTP belum dikonfigurasi. Isi nama template di pengaturan integrasi.',
                'raw_response' => null,
            ];
        }

        try {
            $recipient = normalizeWaNumber($recipient); // ID 62 & MY 60
            $url       = $this->apiUrl . '/' . $this->phoneNumberId . '/messages';

            $payload = json_encode([
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $recipient,
                'type'              => 'template',
                'template'          => [
                    'name'       => $this->otpTemplateName,
                    'language'   => ['code' => getSetting('kirimdev_otp_template_lang', 'id')],
                    // Template AUTHENTICATION otp_verif punya 2 variabel: {{1}} di body
                    // DAN {{1}} di tombol URL (wa.me OTP autofill/copy). Keduanya WAJIB,
                    // kalau body saja → Meta error 131008 "Required parameter is missing".
                    // PENTING: 'index' harus INTEGER (bukan string) — Kirimdev menolak
                    // string dengan invalid_field_value.
                    'components' => [
                        [
                            'type'       => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $otpCode],
                            ],
                        ],
                        [
                            'type'       => 'button',
                            'sub_type'   => 'url',
                            'index'      => 0,
                            'parameters' => [
                                ['type' => 'text', 'text' => $otpCode],
                            ],
                        ],
                    ],
                ],
            ]);

            $idempotencyKey = hash('sha256', $recipient . $otpCode . date('YmdHi'));

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'Idempotency-Key: ' . $idempotencyKey,
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log('KirimdevAdapter template curl error: ' . $curlErr);
                return ['success' => false, 'message' => 'Koneksi gagal: ' . $curlErr, 'raw_response' => null];
            }

            $result = json_decode($response, true);

            if ($httpCode === 200 && !empty($result['data']['id'])) {
                return [
                    'success'      => true,
                    'message'      => 'Template OTP terkirim, status: ' . ($result['data']['status'] ?? 'queued'),
                    'raw_response' => $response,
                ];
            }

            $errorCode = $result['error']['code'] ?? $result['data']['error']['code'] ?? 'unknown';
            error_log('KirimdevAdapter template failed [' . $errorCode . ']: ' . $response);

            $errorMessages = [
                'template_not_found'       => 'Template OTP tidak ditemukan. Cek nama template di pengaturan.',
                'template_paused'          => 'Template OTP sedang dijeda Meta.',
                'template_policy_violation'=> 'Template OTP melanggar kebijakan Meta.',
            ];

            return [
                'success'      => false,
                'message'      => $errorMessages[$errorCode] ?? 'Gagal kirim template (kode: ' . $errorCode . ')',
                'raw_response' => $response,
            ];

        } catch (Exception $e) {
            error_log('KirimdevAdapter sendOtpViaTemplate error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem.', 'raw_response' => null];
        }
    }
}
