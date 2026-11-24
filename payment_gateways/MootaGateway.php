<?php

class MootaGateway {

    private string $callbackToken;
    private string $apiKey = '';  // API Server Key V2 (ACCESS_TOKEN) Moota — untuk tes koneksi
    private array  $extraConfig = [];

    public function __construct() {
        $config              = $this->getConfig();
        $this->callbackToken = $config['callback_token'] ?? '';
        $this->apiKey        = $config['api_key'] ?? '';
        $this->extraConfig   = !empty($config['config_json'])
            ? (json_decode($config['config_json'], true) ?: [])
            : [];
    }

    private function getConfig(): array {
        $stmt = getDB()->prepare("SELECT * FROM payment_gateways WHERE provider = 'moota' LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }

    /** Info rekening tujuan (ditampilkan ke buyer di halaman transaksi Moota). */
    public function bankInfo(): string {
        return trim((string) ($this->extraConfig['bank_info'] ?? ''));
    }

    /**
     * Generate kode unik nominal (1-999) agar tiap order Moota punya total unik —
     * supaya webhook bisa mencocokkan mutasi secara pasti. Hindari bentrok dengan
     * order Moota pending lain yang nominalnya sama.
     *
     * @return array ['unique_code'=>int, 'total_amount'=>float]
     */
    public function generateUniqueCode(float $baseAmount): array {
        $pdo = getDB();
        for ($i = 0; $i < 20; $i++) {
            $code   = random_int(1, 999);
            $amount = $baseAmount + $code;
            $stmt   = $pdo->prepare(
                "SELECT id FROM orders WHERE total = ? AND payment_gateway = 'moota' AND status = 'pending' LIMIT 1"
            );
            $stmt->execute([$amount]);
            if (!$stmt->fetch()) {
                return ['unique_code' => $code, 'total_amount' => $amount];
            }
        }
        return ['unique_code' => 0, 'total_amount' => $baseAmount]; // fallback (sangat jarang)
    }

    /**
     * Tes koneksi ke API Moota v2 memakai API Server Key (ACCESS_TOKEN).
     * GET https://app.moota.co/api/v2/bank — HTTP 2xx = terhubung.
     *
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function testConnection(): array {
        if (trim($this->apiKey) === '') {
            return ['success' => false, 'message' => 'API Server Key Moota belum diisi (simpan dulu).'];
        }

        $ch = curl_init('https://app.moota.co/api/v2/bank');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . trim($this->apiKey),
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['success' => false, 'message' => 'Gagal menghubungi Moota: ' . $err];
        }
        if ($code === 401 || $code === 403) {
            return ['success' => false, 'message' => 'API Server Key ditolak (HTTP ' . $code . '). Periksa kembali key.'];
        }
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'Moota merespons HTTP ' . $code . '.'];
        }

        $data  = json_decode((string) $resp, true);
        $count = (is_array($data) && isset($data['data']) && is_array($data['data'])) ? count($data['data']) : null;
        return [
            'success' => true,
            'message' => 'Terhubung ke Moota.' . ($count !== null ? " ({$count} rekening terdaftar)" : ''),
        ];
    }

    /**
     * Ambil daftar rekening yang TERDAFTAR di akun Moota (GET /api/v2/bank).
     * Dipakai agar Info Rekening terisi OTOMATIS (bukan diketik manual admin).
     *
     * @return array ['success'=>bool,'message'=>string,'accounts'=>array<array{bank_type,label,account_number,atas_nama}>]
     */
    public function fetchBankAccounts(): array {
        if (trim($this->apiKey) === '') {
            return ['success' => false, 'message' => 'API Server Key Moota belum diisi (simpan dulu).', 'accounts' => []];
        }

        $ch = curl_init('https://app.moota.co/api/v2/bank');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . trim($this->apiKey)],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { return ['success' => false, 'message' => 'Gagal menghubungi Moota: ' . $err, 'accounts' => []]; }

        if ($code < 200 || $code >= 300) {
            // Tampilkan alasan ASLI dari Moota (mis. "Unauthenticated.") agar bisa didiagnosis.
            $body   = json_decode((string) $resp, true);
            $detail = is_array($body) ? trim((string) ($body['message'] ?? $body['error'] ?? '')) : '';
            if ($code === 401 || $code === 403) {
                return ['success' => false, 'accounts' => [], 'message' =>
                    'Token ditolak Moota (HTTP ' . $code . ')' . ($detail !== '' ? ': ' . $detail : '') . '. '
                    . 'Pastikan yang diisi = Access Token API V2 (Moota → Apps & API / Akses Token), BUKAN Secret Token webhook. '
                    . 'Bila baru diganti: ketik ULANG token di kolom "API Key (Server Key V2)" lalu Simpan, baru Test Koneksi '
                    . '(kolom sengaja tampil kosong demi keamanan).'];
            }
            return ['success' => false, 'accounts' => [], 'message' => 'Moota merespons HTTP ' . $code . ($detail !== '' ? ' — ' . $detail : '') . '.'];
        }

        $data = json_decode((string) $resp, true);
        $rows = (is_array($data) && isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];
        $accounts = [];
        foreach ($rows as $b) {
            if (!is_array($b)) continue;
            $accounts[] = [
                'bank_type'      => strtoupper(trim((string) ($b['bank_type'] ?? $b['type'] ?? ''))),
                'label'          => trim((string) ($b['label'] ?? $b['name'] ?? '')),
                'account_number' => trim((string) ($b['account_number'] ?? $b['no_rekening'] ?? '')),
                'atas_nama'      => trim((string) ($b['atas_nama'] ?? $b['holder'] ?? $b['account_holder'] ?? '')),
            ];
        }
        return ['success' => true, 'message' => 'Terhubung ke Moota. ' . count($accounts) . ' rekening terdaftar.', 'accounts' => $accounts];
    }

    /**
     * Format daftar rekening Moota jadi teks siap-tampil ke pembeli.
     * Contoh baris: "BCA 1234567890 a.n. PT Averion".
     */
    public static function formatBankInfo(array $accounts): string {
        $lines = [];
        foreach ($accounts as $a) {
            $no = trim((string) ($a['account_number'] ?? ''));
            if ($no === '') continue;
            $bank = trim((string) ($a['bank_type'] ?? '')) ?: trim((string) ($a['label'] ?? ''));
            $nm   = trim((string) ($a['atas_nama'] ?? ''));
            $line = trim($bank . ' ' . $no);
            if ($nm !== '') $line .= ' a.n. ' . $nm;
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Verifikasi webhook Moota via Signature header (cara RESMI, sesuai SDK Moota V2).
     * Moota mengirim header `Signature` = HMAC-SHA256(raw_body, secret_token).
     * Lihat dokumentasi: Webhook::getResponse($signature, $payload).
     *
     * @param string $rawBody   Raw request body (php://input) — JANGAN di-decode/re-encode.
     * @param string $signature Nilai header `Signature` dari request.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool {
        if (empty($this->callbackToken)) {
            error_log('MootaGateway: secret token (callback_token) belum dikonfigurasi');
            return false;
        }
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->callbackToken);
        return hash_equals($expected, $signature);
    }

    /**
     * (Legacy) Verifikasi token mentah via Bearer/query param.
     * Dipertahankan sebagai fallback untuk setup lama yang menaruh ?token= di URL webhook.
     * Untuk webhook Moota resmi, gunakan verifyWebhookSignature().
     */
    public function verifyCallbackToken(string $receivedToken): bool {
        if (empty($this->callbackToken)) {
            error_log('MootaGateway: callback_token belum dikonfigurasi');
            return false;
        }
        return hash_equals($this->callbackToken, $receivedToken);
    }

    /**
     * Cocokkan mutasi masuk dengan order pending (atau cancelled — buyer yang
     * transfer SETELAH order di-expire oleh cron processExpiredOrders tetap
     * harus dilayani; dulu filter 'pending' saja membuat uang masuk tanpa
     * akses & tanpa notifikasi apa pun, senyap. Xendit/Duitku sudah berperilaku
     * begitu: lookup tanpa filter status, guard hanya 'sudah paid').
     *
     * Strategi 1 (lebih reliable): cari order_number di keterangan transfer.
     * Strategi 2 (fallback): cocokkan nominal exact — order pending diprioritaskan
     * di atas cancelled agar order aktif tak pernah kalah dari order kadaluarsa
     * yang kebetulan bernominal sama.
     *
     * @return array|null Order row jika ketemu, null jika tidak
     */
    public function matchMutationToOrder(array $mutation): ?array {
        $amount      = (float) ($mutation['amount'] ?? 0);
        $description = trim($mutation['description'] ?? '');
        $pdo         = getDB();

        // Strategi 1 (paling andal): order_number (INV...) di keterangan transfer.
        if (preg_match('/INV[A-Z0-9]{6,}/i', $description, $matches)) {
            $stmt = $pdo->prepare(
                "SELECT * FROM orders
                 WHERE order_number = ? AND status IN ('pending','cancelled') AND payment_gateway = 'moota'
                 LIMIT 1"
            );
            $stmt->execute([strtoupper($matches[0])]);
            $order = $stmt->fetch();
            if ($order) return $order;
        }

        // Strategi 2: nominal unik (total = base + kode unik) — kini pasti karena tiap
        // order Moota punya total unik (lihat generateUniqueCode()). Pending dulu,
        // baru cancelled (kode unik hanya dijamin unik antar order AKTIF, jadi
        // order pending selalu menang bila nominal kebetulan sama).
        if ($amount > 0) {
            foreach (['pending', 'cancelled'] as $st) {
                $stmt = $pdo->prepare(
                    "SELECT * FROM orders
                     WHERE total = ? AND status = ? AND payment_gateway = 'moota'
                     ORDER BY created_at DESC
                     LIMIT 1"
                );
                $stmt->execute([$amount, $st]);
                $order = $stmt->fetch();
                if ($order) return $order;
            }
        }

        return null;
    }
}
