<?php

/**
 * Tripay gateway (Closed Payment + pemilih channel).
 *
 * Alur: ambil daftar channel aktif (getChannels) → pembeli pilih → createInvoice
 * dengan `method` = kode channel → redirect ke `checkout_url` (halaman hosted Tripay).
 * Konsisten dengan gateway hosted-redirect lain di Averion.
 *
 * Spec resmi Tripay (BUKAN varian AksenSnap):
 *   - Auth  : Authorization: Bearer {API Key}
 *   - Signature create  : HMAC-SHA256(merchant_code + merchant_ref + amount, PrivateKey)
 *   - Signature callback: HMAC-SHA256(raw body, PrivateKey) → header X-Callback-Signature
 *   - Sandbox: https://tripay.co.id/api-sandbox  | Production: https://tripay.co.id/api
 *
 * Konfigurasi (tabel payment_gateways, provider='tripay'):
 *   merchant_code = Kode Merchant (mis. T6627)
 *   api_key       = API Key  (Bearer)
 *   secret_key    = Private Key (signature + verifikasi callback)
 *   mode          = sandbox | production
 *
 * Tanpa dependency eksternal (raw cURL).
 */
class TripayGateway {

    private string $merchantCode;
    private string $apiKey;
    private string $privateKey;
    private string $mode;
    private string $baseUrl;

    public function __construct() {
        $c = $this->getConfig();
        $this->merchantCode = (string) ($c['merchant_code'] ?? '');
        $this->apiKey       = (string) ($c['api_key'] ?? '');
        $this->privateKey   = (string) ($c['secret_key'] ?? '');
        $this->mode         = ($c['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
        $this->baseUrl      = $this->mode === 'production'
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';
    }

    private function getConfig(): array {
        $stmt = getDB()->prepare("SELECT * FROM payment_gateways WHERE provider = 'tripay' LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }

    public function isConfigured(): bool {
        return $this->merchantCode !== '' && $this->apiKey !== '' && $this->privateKey !== '';
    }

    /** cURL helper. $method GET|POST. Return ['ok'=>bool,'http'=>int,'body'=>array|null,'raw'=>string,'error'=>string]. */
    private function http(string $path, string $httpMethod = 'GET', ?array $payload = null): array {
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($httpMethod === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($payload ?? []); // Tripay terima form-urlencoded
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok' => false, 'http' => 0, 'body' => null, 'raw' => '', 'error' => $err];
        $body = json_decode((string) $raw, true);
        return ['ok' => ($http >= 200 && $http < 300), 'http' => $http, 'body' => is_array($body) ? $body : null, 'raw' => (string) $raw, 'error' => ''];
    }

    /**
     * Daftar channel pembayaran aktif (di-cache per-mode 1 jam agar tak fetch tiap render checkout).
     * @return array<int,array{code:string,name:string,group:string,icon:string,fee:string}>
     */
    public function getChannels(bool $forceRefresh = false): array {
        if (!$this->isConfigured()) return [];
        $cacheKey = 'tripay_channels_cache_' . $this->mode;
        $atKey    = 'tripay_channels_cache_at_' . $this->mode;
        if (!$forceRefresh) {
            $at = (int) getSetting($atKey, '0');
            if ($at > 0 && (time() - $at) < 3600) {
                $cached = json_decode((string) getSetting($cacheKey, ''), true);
                if (is_array($cached)) return $cached;
            }
        }
        $res = $this->http('/merchant/payment-channel', 'GET');
        if (!$res['ok'] || empty($res['body']['success']) || !is_array($res['body']['data'] ?? null)) {
            // Gagal → pakai cache lama bila ada (biar checkout tak kosong).
            $cached = json_decode((string) getSetting($cacheKey, ''), true);
            return is_array($cached) ? $cached : [];
        }
        $channels = [];
        foreach ($res['body']['data'] as $ch) {
            if (empty($ch['active'])) continue;
            $channels[] = [
                'code'  => (string) ($ch['code'] ?? ''),
                'name'  => (string) ($ch['name'] ?? ''),
                'group' => (string) ($ch['group'] ?? ''),
                'icon'  => (string) ($ch['icon_url'] ?? ''),
                'fee'   => (string) (($ch['total_fee']['flat'] ?? '') !== '' ? $ch['total_fee']['flat'] : ''),
            ];
        }
        setSetting($cacheKey, json_encode($channels), 'integrations');
        setSetting($atKey, (string) time(), 'integrations');
        return $channels;
    }

    /** Validasi kode channel benar-benar aktif (dipakai server sebelum create). */
    public function isValidChannel(string $code): bool {
        if ($code === '') return false;
        foreach ($this->getChannels() as $c) {
            if ($c['code'] === $code) return true;
        }
        return false;
    }

    /**
     * Buat transaksi Tripay (Closed Payment) untuk channel terpilih.
     * @return array ['success'=>bool,'payment_url'=>string(checkout_url),'reference'=>string,'raw'=>mixed]
     */
    public function createInvoice(array $orderData, string $method): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Tripay belum dikonfigurasi'];
        }
        $method = trim($method);
        if ($method === '') {
            return ['success' => false, 'message' => 'Pilih metode pembayaran Tripay dulu.'];
        }

        try {
            $merchantRef = (string) $orderData['order_number'];
            $amount      = (int) round((float) $orderData['total']);
            if ($amount <= 0) return ['success' => false, 'message' => 'Nominal tidak valid'];

            $signature = hash_hmac('sha256', $this->merchantCode . $merchantRef . $amount, $this->privateKey);

            $payload = [
                'method'         => $method,
                'merchant_ref'   => $merchantRef,
                'amount'         => $amount,
                'customer_name'  => mb_substr((string) ($orderData['name'] ?? 'Customer'), 0, 50),
                'customer_email' => (string) ($orderData['email'] ?? ''),
                'order_items'    => [[
                    'sku'      => 'ORDER-' . $merchantRef,
                    'name'     => mb_substr((string) ($orderData['product_name'] ?? 'Pembelian Produk'), 0, 50),
                    'price'    => $amount,
                    'quantity' => 1,
                ]],
                'return_url'   => rtrim(APP_URL, '/') . '/transactions/' . rawurlencode($merchantRef),
                'callback_url' => rtrim(APP_URL, '/') . '/webhooks/tripay-callback.php',
                'expired_time' => time() + (24 * 3600),
                'signature'    => $signature,
            ];
            $phone = preg_replace('/[^0-9]/', '', (string) ($orderData['phone'] ?? ''));
            if ($phone !== '') $payload['customer_phone'] = $phone;

            $res = $this->http('/transaction/create', 'POST', $payload);
            $this->logTransaction(null, $merchantRef, 'create_transaction', $payload, $res['raw'],
                $res['ok'] ? 'success' : 'failed');

            if ($res['ok'] && !empty($res['body']['success']) && !empty($res['body']['data']['checkout_url'])) {
                return [
                    'success'     => true,
                    'payment_url' => $res['body']['data']['checkout_url'],
                    'reference'   => $res['body']['data']['reference'] ?? $merchantRef,
                    'raw'         => $res['body'],
                ];
            }
            $msg = $res['body']['message'] ?? ($res['error'] !== '' ? 'Koneksi ke Tripay gagal' : 'Gagal membuat transaksi Tripay');
            return ['success' => false, 'message' => $msg, 'raw' => $res['body']];
        } catch (Exception $e) {
            error_log('TripayGateway createInvoice error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /** Verifikasi X-Callback-Signature = HMAC-SHA256(raw body, private_key). */
    public function verifyCallbackSignature(string $rawBody, string $signatureHeader): bool {
        if ($signatureHeader === '' || $this->privateKey === '') return false;
        $calc = hash_hmac('sha256', $rawBody, $this->privateKey);
        return hash_equals($calc, $signatureHeader);
    }

    /** Peta status Tripay → status internal. */
    public function mapStatus(string $status): string {
        switch (strtoupper($status)) {
            case 'PAID':
            case 'AUTHORIZED': return 'paid';
            case 'EXPIRED':    return 'expired';
            case 'FAILED':
            case 'REFUND':     return 'failed';
            default:           return 'pending'; // UNPAID
        }
    }

    private function logTransaction(?int $orderId, string $reference, string $eventType,
                                     array $request, ?string $response, string $status): void {
        try {
            unset($request['signature']);
            $stmt = getDB()->prepare(
                "INSERT INTO payment_transaction_logs
                 (order_id, gateway_provider, reference_id, event_type, request_payload, response_payload, status, created_at)
                 VALUES (?, 'tripay', ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $orderId, $reference, $eventType,
                json_encode($request, JSON_UNESCAPED_UNICODE),
                $response, $status,
            ]);
        } catch (Exception $e) {
            error_log('TripayGateway logTransaction error: ' . $e->getMessage());
        }
    }
}
