<?php

/**
 * Midtrans Snap gateway (alur hosted-redirect).
 *
 * Membuat transaksi Snap via REST (POST /snap/v1/transactions, Basic auth dari
 * server key) lalu memakai `redirect_url` sebagai payment_url — konsisten dengan
 * gateway hosted lain di Averion (Duitku/Xendit/PayPal). Verifikasi notifikasi
 * webhook memakai signature SHA512 (order_id + status_code + gross_amount + serverKey).
 *
 * Konfigurasi dibaca dari tabel `payment_gateways` (provider='midtrans'):
 *   - api_key      = Server Key  (untuk Basic auth create-transaction + verifikasi signature)
 *   - secret_key   = Client Key  (opsional; hanya perlu bila kelak pakai Snap.js popup)
 *   - merchant_code = Merchant ID (informatif)
 *   - mode         = sandbox | production
 *
 * Tanpa dependency eksternal (raw cURL) — mengikuti konvensi gateway Averion.
 */
class MidtransGateway {

    private string $serverKey;
    private string $mode;
    private string $snapUrl;

    public function __construct() {
        $config          = $this->getConfig();
        $this->serverKey = (string) ($config['api_key'] ?? '');
        $this->mode      = ($config['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
        $this->snapUrl   = $this->mode === 'production'
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    private function getConfig(): array {
        $stmt = getDB()->prepare("SELECT * FROM payment_gateways WHERE provider = 'midtrans' LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }

    public function isConfigured(): bool {
        return $this->serverKey !== '';
    }

    public function getServerKey(): string {
        return $this->serverKey;
    }

    /**
     * Buat transaksi Snap. Mengembalikan redirect_url (halaman pembayaran Midtrans).
     * @return array ['success'=>bool, 'payment_url'=>string, 'reference'=>string(token), 'raw'=>mixed]
     */
    public function createInvoice(array $orderData): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Midtrans belum dikonfigurasi'];
        }

        try {
            $orderId = (string) $orderData['order_number'];
            // gross_amount WAJIB integer IDR dan HARUS sama dengan total item_details.
            $amount  = (int) round((float) $orderData['total']);
            if ($amount <= 0) {
                return ['success' => false, 'message' => 'Nominal tidak valid'];
            }

            $payload = [
                'transaction_details' => [
                    'order_id'     => $orderId,
                    'gross_amount' => $amount,
                ],
                'item_details' => [[
                    'id'       => 'ORDER',
                    'price'    => $amount,
                    'quantity' => 1,
                    'name'     => mb_substr($orderData['product_name'] ?? 'Pembelian Produk', 0, 50),
                ]],
                'customer_details' => [
                    'first_name' => mb_substr($orderData['name'] ?? 'Customer', 0, 50),
                    'email'      => (string) ($orderData['email'] ?? ''),
                ],
                'callbacks' => [
                    // Pembeli kembali ke halaman transaksi Averion setelah selesai di Snap.
                    'finish' => rtrim(APP_URL, '/') . '/transactions/' . $orderId,
                ],
            ];

            $ch = curl_init($this->snapUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    // Basic auth: base64(serverKey + ':') — password kosong.
                    'Authorization: Basic ' . base64_encode($this->serverKey . ':'),
                ],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log('MidtransGateway curl error: ' . $curlErr);
                return ['success' => false, 'message' => 'Koneksi ke Midtrans gagal'];
            }

            $this->logTransaction(null, $orderId, 'create_transaction', $payload, $response,
                ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'failed');

            $result = json_decode((string) $response, true);

            if ($httpCode >= 200 && $httpCode < 300 && !empty($result['redirect_url'])) {
                return [
                    'success'     => true,
                    'payment_url' => $result['redirect_url'],
                    'reference'   => $result['token'] ?? $orderId,
                    'raw'         => $result,
                ];
            }

            // Midtrans mengembalikan error_messages[] pada kegagalan.
            $msg = !empty($result['error_messages'])
                ? implode('; ', (array) $result['error_messages'])
                : ($result['status_message'] ?? 'Gagal membuat transaksi Midtrans');
            return ['success' => false, 'message' => $msg, 'raw' => $result];

        } catch (Exception $e) {
            error_log('MidtransGateway createInvoice error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }

    /**
     * Verifikasi signature notifikasi webhook Midtrans.
     * signature_key = SHA512(order_id + status_code + gross_amount + serverKey).
     */
    public function verifyNotificationSignature(array $notif): bool {
        $orderId     = (string) ($notif['order_id'] ?? '');
        $statusCode  = (string) ($notif['status_code'] ?? '');
        $grossAmount = (string) ($notif['gross_amount'] ?? '');
        $signature   = (string) ($notif['signature_key'] ?? '');
        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signature === '' || $this->serverKey === '') {
            return false;
        }
        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);
        return hash_equals($expected, $signature);
    }

    /**
     * Peta transaction_status + fraud_status Midtrans → status internal.
     * @return string 'paid' | 'pending' | 'failed' | 'expired'
     */
    public function mapStatus(string $transactionStatus, string $fraudStatus = ''): string {
        switch ($transactionStatus) {
            case 'capture':
                // Kartu kredit: 'accept' = lunas; 'challenge' = perlu review manual → pending.
                return ($fraudStatus === 'challenge') ? 'pending' : 'paid';
            case 'settlement':
                return 'paid';
            case 'pending':
                return 'pending';
            case 'deny':
            case 'cancel':
            case 'failure':
                return 'failed';
            case 'expire':
                return 'expired';
            case 'refund':
            case 'partial_refund':
                // Dana dikembalikan → perlakukan sebagai gagal (akses tidak diberikan/dicabut manual).
                return 'failed';
            default:
                return 'pending';
        }
    }

    private function logTransaction(?int $orderId, string $reference, string $eventType,
                                     array $request, ?string $response, string $status): void {
        try {
            unset($request['signature'], $request['signature_key']);
            $stmt = getDB()->prepare(
                "INSERT INTO payment_transaction_logs
                 (order_id, gateway_provider, reference_id, event_type, request_payload, response_payload, status, created_at)
                 VALUES (?, 'midtrans', ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $orderId, $reference, $eventType,
                json_encode($request, JSON_UNESCAPED_UNICODE),
                $response,
                $status,
            ]);
        } catch (Exception $e) {
            error_log('MidtransGateway logTransaction error: ' . $e->getMessage());
        }
    }
}
