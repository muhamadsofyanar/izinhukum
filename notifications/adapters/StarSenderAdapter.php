<?php

require_once __DIR__ . '/../NotificationAdapter.php';

class StarSenderAdapter implements NotificationAdapter {

    private string $apiKey;
    private string $apiUrl = 'https://api.starsender.online/api/send';

    public function __construct(string $apiKey = '') {
        $this->apiKey = $apiKey ?: getSetting('starsender_api_key', '');
    }

    public function getName(): string {
        return 'starsender';
    }

    public function send(string $recipient, string $subject, string $body): array {
        if (empty($this->apiKey)) {
            return [
                'success'      => true,
                'message'      => '[SIMULASI] WA StarSender ke ' . $recipient,
                'raw_response' => null,
            ];
        }

        try {
            $normalizedRecipient = $this->normalizeNumber($recipient);
            $payloadJson = json_encode([
                'messageType' => 'text',
                'to'          => $normalizedRecipient,
                'body'        => $body,
                'delay'       => 0,
            ]);

            // Retry AMAN-DOBEL: hanya diulang bila koneksi TCP BELUM pernah terjalin
            // (CONNECT_TIME = 0) → request dijamin belum sampai ke StarSender, jadi
            // mustahil menghasilkan WA ganda. Ini menutup penyebab kegagalan massal
            // v1.2.154 ("Gagal terhubung": DNS/connect blip) TANPA risiko dobel.
            //
            // TIDAK di-retry (bisa menghasilkan WA DOBEL karena pesan mungkin sudah
            // dikirim/masuk antrean StarSender walau kita tak menerima konfirmasi):
            //   - read-timeout SETELAH connect (koneksi terjalin, respons tak diterima)
            //   - respons HTTP apa pun, termasuk 5xx (request sudah lengkap terkirim)
            // Kasus ini dilaporkan apa adanya; admin bisa "Kirim Ulang" manual bila perlu.
            $maxAttempts = 3;
            $attempt = 0;
            $failMsg = 'Gagal kirim StarSender';
            $failRaw = null;

            do {
                $attempt++;
                $ch = curl_init($this->apiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payloadJson,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: ' . $this->apiKey, // TANPA "Bearer " — sesuai dokumentasi resmi
                    ],
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $response  = curl_exec($ch);
                $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $connTime  = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
                $curlErr   = curl_error($ch);
                $curlErrNo = curl_errno($ch);
                curl_close($ch);

                if ($response === false || $curlErr !== '') {
                    // Kegagalan koneksi. Sertakan detail cURL agar admin tahu
                    // penyebab pastinya (bukan sekadar "Gagal terhubung").
                    $failMsg   = 'Gagal terhubung ke StarSender (cURL ' . $curlErrNo . ': ' . $curlErr . ')';
                    $failRaw   = null;

                    if (self::requestLikelySent($connTime)) {
                        // TCP SUDAH terjalin (CONNECT_TIME > 0) → request (POST body,
                        // ukurannya kecil) sudah terkirim ke StarSender; yang TIDAK kita
                        // terima hanyalah RESPONS-nya (read-timeout / koneksi putus saat
                        // membaca balasan, mis. cURL 28 setelah connect). Dalam kondisi ini
                        // WhatsApp hampir pasti sudah diantrekan/dikirim oleh StarSender.
                        //
                        // Maka JANGAN perlakukan sebagai gagal:
                        //   - Menandai 'failed' → alarm palsu "gagal padahal terkirim".
                        //   - Retry (di sini MAUPUN via cron) → WA DOBEL ke pembeli.
                        // Laporkan sebagai TERKIRIM tanpa konfirmasi respons. Bila ternyata
                        // benar-benar tak sampai (jarang), admin bisa "Kirim Ulang" manual.
                        error_log('[StarSender] respons tak terbaca setelah connect (' . $failMsg
                            . ') — dianggap TERKIRIM tanpa konfirmasi, tidak di-retry');
                        return [
                            'success'      => true,
                            'unconfirmed'  => true,
                            'message'      => 'Terkirim (tanpa konfirmasi respons)',
                            'raw_response' => 'TERKIRIM TANPA KONFIRMASI — ' . $failMsg,
                        ];
                    }

                    // Belum pernah connect (CONNECT_TIME = 0) → request DIJAMIN belum
                    // sampai ke StarSender → aman diulang (mustahil menghasilkan WA dobel).
                    $transient = true;
                } else {
                    $result  = json_decode($response, true);
                    $success = $httpCode >= 200 && $httpCode < 300 && !empty($result['success']);
                    if ($success) {
                        return [
                            'success'      => true,
                            'message'      => $result['message'] ?? 'Pesan terkirim',
                            'raw_response' => $response,
                        ];
                    }
                    // Sudah ada respons HTTP = request lengkap terkirim → jangan retry
                    // (anti-dobel), termasuk 5xx.
                    $failMsg   = $result['message'] ?? ('Gagal (HTTP ' . $httpCode . ')');
                    $failRaw   = $response;
                    $transient = false;
                }

                if ($transient && $attempt < $maxAttempts) {
                    error_log('[StarSender] connect blip percobaan ' . $attempt . '/' . $maxAttempts . ' — ' . $failMsg);
                    usleep(600000 * $attempt); // backoff 0,6s lalu 1,2s
                    continue;
                }
                break;
            } while (true);

            return ['success' => false, 'message' => $failMsg, 'raw_response' => $failRaw];

        } catch (Exception $e) {
            error_log('StarSenderAdapter exception: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'raw_response' => null];
        }
    }

    /**
     * Apakah request dianggap SUDAH terkirim ke StarSender meski responsnya gagal
     * kita baca? Ya bila TCP sudah connect (CONNECT_TIME > 0): setelah handshake,
     * body POST yang kecil praktis pasti terkirim; kegagalan hanya di sisi membaca
     * balasan. Dipakai untuk memutuskan "TERKIRIM tanpa konfirmasi" (jangan gagal,
     * jangan retry) vs "belum terkirim" (aman diulang). Static+public agar dapat diuji.
     */
    public static function requestLikelySent(float $connectTime): bool {
        return $connectTime > 0.0;
    }

    /**
     * Normalisasi ke format internasional via helper bersama (ID 62 & MY 60).
     * StarSender menerima format internasional dengan baik.
     */
    private function normalizeNumber(string $number): string {
        return normalizeWaNumber($number);
    }
}
