<?php
/**
 * PayPalGateway — PayPal Orders API v2 (redirect flow, seperti Xendit).
 *
 * Alur: createInvoice() buat order (intent=CAPTURE, USD) → kembalikan URL approve
 * PayPal (payment_url). Pembeli approve → PayPal redirect balik ke return handler →
 * captureOrder() + verifikasi COMPLETED → tandai lunas (pipeline onOrderPaid).
 *
 * Mata uang: PayPal TIDAK mendukung IDR → tagih USD. Nominal = total_IDR / kurs
 * (config_json.idr_usd_rate). orders.total tetap IDR (komisi/membership tak berubah).
 * BYO kredensial: client_id (api_key) + secret (secret_key) milik seller → uang ke
 * PayPal seller. Averion hanya orkestrasi.
 */
class PayPalGateway {

    private string $clientId;
    private string $secret;
    private string $mode;         // 'sandbox' | 'production'
    private float  $rate;         // IDR per 1 USD
    private ?string $token = null;

    public function __construct() {
        $c = $this->getConfig();
        $this->clientId = trim((string) ($c['api_key'] ?? ''));
        $this->secret   = trim((string) ($c['secret_key'] ?? ''));
        $this->mode     = ($c['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
        $cfg = [];
        if (!empty($c['config_json'])) { $cfg = json_decode((string) $c['config_json'], true) ?: []; }
        $this->rate = (float) ($cfg['idr_usd_rate'] ?? 0);
    }

    private function getConfig(): array {
        try {
            $st = getDB()->prepare("SELECT * FROM payment_gateways WHERE provider = 'paypal' LIMIT 1");
            $st->execute();
            return $st->fetch() ?: [];
        } catch (Exception $e) {
            error_log('PayPalGateway getConfig error: ' . $e->getMessage());
            return [];
        }
    }

    public function isConfigured(): bool {
        return $this->clientId !== '' && $this->secret !== '';
    }

    public function getRate(): float { return $this->rate; }

    private function apiBase(): string {
        return $this->mode === 'production'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /** Konversi total IDR → USD (2 desimal). 0 bila kurs tak valid. */
    public function idrToUsd(float $idr): float {
        if ($this->rate <= 0) return 0.0;
        return round($idr / $this->rate, 2);
    }

    /** Ambil OAuth access token (client_credentials). Cache per-instance. */
    private function getAccessToken(): ?string {
        if ($this->token !== null) return $this->token;
        $ch = curl_init($this->apiBase() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->secret,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) { error_log('PayPal token curl error: ' . $err); return null; }
        $j = json_decode((string) $res, true);
        if ($code === 200 && !empty($j['access_token'])) {
            $this->token = (string) $j['access_token'];
            return $this->token;
        }
        error_log('PayPal token gagal (HTTP ' . $code . '): ' . substr((string) $res, 0, 300));
        return null;
    }

    /** POST JSON ber-Bearer. $body = array/stdClass ({} untuk capture). */
    private function postJson(string $url, $body, string $token): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'json' => json_decode((string) $res, true) ?: [], 'raw' => (string) $res, 'err' => (string) $err];
    }

    /**
     * Buat order PayPal (USD). @param array $orderData order_number,total(IDR),name,email,product_name
     * @return array ['success','payment_url','reference','usd_amount','rate','currency','message','raw']
     */
    public function createInvoice(array $orderData): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'PayPal belum dikonfigurasi'];
        }
        if ($this->rate <= 0) {
            return ['success' => false, 'message' => 'Kurs IDR→USD PayPal belum diisi admin'];
        }
        $usd = $this->idrToUsd((float) ($orderData['total'] ?? 0));
        if ($usd < 0.01) {
            return ['success' => false, 'message' => 'Nominal terlalu kecil untuk PayPal (min $0.01)'];
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return ['success' => false, 'message' => 'Gagal autentikasi ke PayPal. Cek kredensial.'];
        }

        $orderNumber = (string) ($orderData['order_number'] ?? '');
        $base = rtrim(APP_URL, '/');
        $brand = getSetting('app_name', 'STIFIn Mulia');

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $orderNumber,
                'custom_id'    => $orderNumber,
                'description'  => mb_substr((string) ($orderData['product_name'] ?? 'Pembelian Produk'), 0, 127),
                'amount'       => [
                    'currency_code' => 'USD',
                    'value'         => number_format($usd, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'brand_name'          => mb_substr((string) $brand, 0, 127),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'PAY_NOW',
                'return_url'          => $base . '/actions/checkout/paypal-return',
                'cancel_url'          => $base . '/transactions/' . rawurlencode($orderNumber) . '?paypal=cancel',
            ],
        ];

        $r = $this->postJson($this->apiBase() . '/v2/checkout/orders', $payload, $token);
        $this->logTransaction(null, $orderNumber, 'create_order', $payload, $r['raw'], ($r['code'] === 201 ? 'success' : 'failed'));

        if ($r['err']) {
            error_log('PayPal createOrder curl error: ' . $r['err']);
            return ['success' => false, 'message' => 'Koneksi ke PayPal gagal'];
        }
        if ($r['code'] === 201 && !empty($r['json']['id'])) {
            $approve = '';
            foreach (($r['json']['links'] ?? []) as $lnk) {
                if (($lnk['rel'] ?? '') === 'approve') { $approve = (string) $lnk['href']; break; }
            }
            if ($approve === '') {
                return ['success' => false, 'message' => 'PayPal tak mengembalikan URL persetujuan', 'raw' => $r['json']];
            }
            return [
                'success'     => true,
                'payment_url' => $approve,
                'reference'   => (string) $r['json']['id'],
                'usd_amount'  => $usd,
                'rate'        => $this->rate,
                'currency'    => 'USD',
                'raw'         => $r['json'],
            ];
        }
        $msg = $r['json']['message'] ?? ($r['json']['name'] ?? 'Gagal membuat order PayPal');
        return ['success' => false, 'message' => $msg, 'raw' => $r['json']];
    }

    /**
     * Capture order PayPal yang sudah di-approve pembeli.
     * @return array ['success','completed','capture_id','custom_id','amount','currency','message','raw']
     */
    public function captureOrder(string $paypalOrderId): array {
        if (!$this->isConfigured()) return ['success' => false, 'message' => 'PayPal belum dikonfigurasi'];
        $token = $this->getAccessToken();
        if ($token === null) return ['success' => false, 'message' => 'Gagal autentikasi ke PayPal'];

        $r = $this->postJson($this->apiBase() . '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture', new stdClass(), $token);
        // stdClass → "{}" (PayPal butuh body kosong objek). postJson json_encode object → "{}".
        $this->logTransaction(null, $paypalOrderId, 'capture', ['order' => $paypalOrderId], $r['raw'], (($r['code'] === 201 || $r['code'] === 200) ? 'success' : 'failed'));

        if ($r['err']) return ['success' => false, 'message' => 'Koneksi ke PayPal gagal saat capture'];

        $j = $r['json'];
        $status   = (string) ($j['status'] ?? '');
        $pu       = $j['purchase_units'][0] ?? [];
        $cap      = $pu['payments']['captures'][0] ?? [];
        $customId = (string) ($pu['reference_id'] ?? ($cap['custom_id'] ?? ''));
        if ($customId === '') $customId = (string) ($pu['payments']['captures'][0]['custom_id'] ?? '');
        $amount   = (float) ($cap['amount']['value'] ?? 0);
        $currency = (string) ($cap['amount']['currency_code'] ?? '');
        $capId    = (string) ($cap['id'] ?? '');
        $capStatus = (string) ($cap['status'] ?? $status);

        $completed = ($status === 'COMPLETED') && ($capStatus === 'COMPLETED');
        return [
            'success'   => ($r['code'] === 201 || $r['code'] === 200),
            'completed' => $completed,
            'capture_id'=> $capId,
            'custom_id' => $customId,
            'amount'    => $amount,
            'currency'  => $currency,
            'message'   => $completed ? '' : ($j['message'] ?? ('Status: ' . $status)),
            'raw'       => $j,
        ];
    }

    private function logTransaction(?int $orderId, string $reference, string $eventType,
                                     $request, ?string $response, string $status): void {
        try {
            getDB()->prepare(
                "INSERT INTO payment_transaction_logs
                 (order_id, gateway_provider, reference_id, event_type, request_payload, response_payload, status, created_at)
                 VALUES (?, 'paypal', ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $orderId, $reference, $eventType,
                json_encode($request, JSON_UNESCAPED_UNICODE),
                $response !== null ? mb_substr($response, 0, 60000) : null,
                $status,
            ]);
        } catch (Exception $e) {
            error_log('PayPalGateway logTransaction error: ' . $e->getMessage());
        }
    }
}
