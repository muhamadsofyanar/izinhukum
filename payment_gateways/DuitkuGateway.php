<?php

class DuitkuGateway {

    private string $merchantCode;
    private string $apiKey;
    private string $baseUrl;

    public function __construct() {
        $config = $this->getConfig();
        $this->merchantCode = $config['merchant_code'] ?? '';
        $this->apiKey       = $config['api_key'] ?? '';
        // Duitku POP API — halaman pembayaran hosted SEMUA kanal (tanpa wajib
        // paymentMethod). Endpoint webapi/v2/inquiry lama mewajibkan paymentMethod
        // per-kanal → integrasi lama SELALU gagal "paymentMethod is mandatory".
        $this->baseUrl      = ($config['mode'] ?? 'sandbox') === 'production'
            ? 'https://api-prod.duitku.com/api/merchant'
            : 'https://api-sandbox.duitku.com/api/merchant';
    }

    private function getConfig(): array {
        $stmt = getDB()->prepare("SELECT * FROM payment_gateways WHERE provider = 'duitku' LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }

    public function isConfigured(): bool {
        return !empty($this->merchantCode) && !empty($this->apiKey);
    }

    /**
     * Buat invoice/VA baru di Duitku.
     * @return array ['success' => bool, 'payment_url' => string, 'reference' => string, 'raw' => mixed]
     */
    public function createInvoice(array $orderData): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Duitku belum dikonfigurasi'];
        }

        try {
            $merchantOrderId = $orderData['order_number'];
            $amount          = (int) $orderData['total'];

            // POP API: signature di HEADER — sha256(merchantCode + timestamp + apiKey),
            // timestamp milidetik (jam server harus akurat).
            $timestamp = (string) round(microtime(true) * 1000);
            $signature = hash('sha256', $this->merchantCode . $timestamp . $this->apiKey);

            $payload = [
                'paymentAmount'   => $amount,
                'merchantOrderId' => $merchantOrderId,
                'productDetails'  => mb_substr($orderData['product_name'] ?? 'Pembelian Produk', 0, 255),
                'email'           => $orderData['email'],
                'customerVaName'  => mb_substr($orderData['name'] ?? 'Customer', 0, 20),
                'callbackUrl'     => rtrim(APP_URL, '/') . '/webhooks/duitku-callback.php',
                'returnUrl'       => rtrim(APP_URL, '/') . '/transactions/' . $merchantOrderId,
                'expiryPeriod'    => 60, // menit
            ];

            $ch = curl_init($this->baseUrl . '/createInvoice');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-duitku-signature: '    . $signature,
                    'x-duitku-timestamp: '    . $timestamp,
                    'x-duitku-merchantcode: ' . $this->merchantCode,
                ],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log('DuitkuGateway curl error: ' . $curlErr);
                return ['success' => false, 'message' => 'Koneksi ke Duitku gagal'];
            }

            $this->logTransaction(null, $merchantOrderId, 'create_invoice', $payload, $response,
                $httpCode === 200 ? 'success' : 'failed');

            $result = json_decode($response, true);

            if ($httpCode === 200 && !empty($result['paymentUrl'])) {
                return [
                    'success'     => true,
                    'payment_url' => $result['paymentUrl'],
                    'reference'   => $result['reference'] ?? $merchantOrderId,
                    'raw'         => $result,
                ];
            }

            $msg = $result['Message'] ?? ($result['message'] ?? 'Gagal membuat invoice');
            return ['success' => false, 'message' => $msg, 'raw' => $result];

        } catch (Exception $e) {
            error_log('DuitkuGateway createInvoice error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Verifikasi signature callback Duitku (POP maupun webapi memakai format sama):
     * signature = MD5(merchantCode + amount + merchantOrderId + apiKey).
     * Versi lama di sini memakai HMAC-SHA256 — SALAH: callback asli tak pernah
     * lolos verifikasi, order lunas tidak pernah aktif otomatis.
     */
    public function verifyCallbackSignature(array $callbackData): bool {
        $expected = md5(
            $this->merchantCode .
            ($callbackData['amount'] ?? '') .
            ($callbackData['merchantOrderId'] ?? '') .
            $this->apiKey
        );
        return hash_equals($expected, strtolower((string) ($callbackData['signature'] ?? '')));
    }

    private function logTransaction(?int $orderId, string $reference, string $eventType,
                                     array $request, ?string $response, string $status): void {
        try {
            // Hapus nilai sensitif sebelum log
            unset($request['signature']);

            $stmt = getDB()->prepare(
                "INSERT INTO payment_transaction_logs
                 (order_id, gateway_provider, reference_id, event_type, request_payload, response_payload, status, created_at)
                 VALUES (?, 'duitku', ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $orderId, $reference, $eventType,
                json_encode($request, JSON_UNESCAPED_UNICODE),
                $response,
                $status,
            ]);
        } catch (Exception $e) {
            error_log('DuitkuGateway logTransaction error: ' . $e->getMessage());
        }
    }
}
