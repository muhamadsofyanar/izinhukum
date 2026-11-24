<?php

class XenditGateway {

    private string $apiKey;
    private string $callbackToken;

    public function __construct() {
        $config              = $this->getConfig();
        $this->apiKey        = $config['api_key'] ?? '';
        $this->callbackToken = $config['callback_token'] ?? '';
    }

    private function getConfig(): array {
        $stmt = getDB()->prepare("SELECT * FROM payment_gateways WHERE provider = 'xendit' LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }

    public function isConfigured(): bool {
        return !empty($this->apiKey);
    }

    /**
     * Buat invoice baru di Xendit.
     * @return array ['success' => bool, 'payment_url' => string, 'reference' => string, 'raw' => mixed]
     */
    public function createInvoice(array $orderData): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Xendit belum dikonfigurasi'];
        }

        try {
            $payload = [
                'external_id'          => $orderData['order_number'],
                'amount'               => (int) $orderData['total'],
                'payer_email'          => $orderData['email'],
                'description'          => mb_substr($orderData['product_name'] ?? 'Pembelian Produk', 0, 255),
                'success_redirect_url' => rtrim(APP_URL, '/') . '/transactions/' . $orderData['order_number'],
                'failure_redirect_url' => rtrim(APP_URL, '/') . '/transactions/' . $orderData['order_number'],
                'currency'             => 'IDR',
                'customer'             => [
                    'given_names' => mb_substr($orderData['name'] ?? 'Customer', 0, 50),
                    'email'       => $orderData['email'],
                ],
            ];

            $ch = curl_init('https://api.xendit.co/v2/invoices');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_USERPWD        => $this->apiKey . ':',  // Basic Auth, password kosong
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log('XenditGateway curl error: ' . $curlErr);
                return ['success' => false, 'message' => 'Koneksi ke Xendit gagal'];
            }

            $this->logTransaction(null, $orderData['order_number'], 'create_invoice',
                $payload, $response, $httpCode === 200 ? 'success' : 'failed');

            $result = json_decode($response, true);

            if ($httpCode === 200 && !empty($result['invoice_url'])) {
                return [
                    'success'     => true,
                    'payment_url' => $result['invoice_url'],
                    'reference'   => $result['id'] ?? $orderData['order_number'],
                    'raw'         => $result,
                ];
            }

            $msg = $result['message'] ?? ($result['error_code'] ?? 'Gagal membuat invoice Xendit');
            return ['success' => false, 'message' => $msg, 'raw' => $result];

        } catch (Exception $e) {
            error_log('XenditGateway createInvoice error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Verifikasi callback token dari header x-callback-token.
     * Xendit tidak pakai HMAC — cukup bandingkan token dengan yang disimpan di config.
     */
    public function verifyCallbackToken(string $receivedToken): bool {
        if (empty($this->callbackToken)) {
            error_log('XenditGateway: callback_token belum dikonfigurasi');
            return false;
        }
        return hash_equals($this->callbackToken, $receivedToken);
    }

    private function logTransaction(?int $orderId, string $reference, string $eventType,
                                     array $request, ?string $response, string $status): void {
        try {
            $stmt = getDB()->prepare(
                "INSERT INTO payment_transaction_logs
                 (order_id, gateway_provider, reference_id, event_type, request_payload, response_payload, status, created_at)
                 VALUES (?, 'xendit', ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $orderId, $reference, $eventType,
                json_encode($request, JSON_UNESCAPED_UNICODE),
                $response,
                $status,
            ]);
        } catch (Exception $e) {
            error_log('XenditGateway logTransaction error: ' . $e->getMessage());
        }
    }
}
