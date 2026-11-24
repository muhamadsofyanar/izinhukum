<?php
// ─── Lisensi Produk Digital (dijual vendor) ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Generate license key format: AVRN-XXXX-XXXX-XXXX-XXXX
 * Loop hingga unique di tabel product_licenses.
 */
function generateProductLicenseKey(): string {
    $pdo = getDB();
    do {
        $segments = [];
        for ($i = 0; $i < 4; $i++) {
            $segments[] = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        }
        $key = 'AVRN-' . implode('-', $segments);
        $stmt = $pdo->prepare("SELECT id FROM product_licenses WHERE license_key = ?");
        $stmt->execute([$key]);
    } while ($stmt->fetch());
    return $key;
}

/**
 * Issue license key setelah order paid.
 * Dipanggil dari: activateAccess() call sites — checkout, webhook callback,
 * konfirmasi manual admin.
 * Idempotent: jika sudah ada license untuk order+product ini, kembalikan key lama.
 *
 * @return string|null license_key bila berhasil/sudah ada, null bila produk
 *                      tidak mengaktifkan lisensi atau terjadi error.
 */
function issueLicense(int $orderId, int $productId, int $userId): ?string {
    try {
        $pdo = getDB();

        // Idempotency: jangan double-issue
        $existing = $pdo->prepare(
            "SELECT license_key FROM product_licenses WHERE order_id = ? AND product_id = ?"
        );
        $existing->execute([$orderId, $productId]);
        if ($row = $existing->fetch()) {
            return $row['license_key'];
        }

        // Ambil konfigurasi license dari produk
        $product = $pdo->prepare(
            "SELECT license_enabled, license_seats, license_validity_days FROM products WHERE id = ?"
        );
        $product->execute([$productId]);
        $p = $product->fetch();

        // Jika produk tidak enable license, return null (bukan error)
        if (!$p || !$p['license_enabled']) {
            return null;
        }

        // Produk yang dipetakan ke server lisensi VENDOR: kuncinya diterbitkan vendor
        // (lewat antrean vfEnqueue), BUKAN di sini. Tanpa guard ini buyer menerima DUA
        // kunci berformat sama persis (AVRN-XXXX-XXXX-XXXX-XXXX, tak bisa dibedakan
        // mata) — satu di antaranya lokal & akan DITOLAK saat aktivasi.
        if (function_exists('vfPlanForProduct') && vfPlanForProduct($productId) !== '') {
            return null;
        }

        $licenseKey   = generateProductLicenseKey();
        $maxSeats     = max(1, (int) $p['license_seats']);
        $validityDays = $p['license_validity_days'] ? (int) $p['license_validity_days'] : null;
        $expiresAt    = $validityDays
            ? date('Y-m-d H:i:s', strtotime("+{$validityDays} days"))
            : null;

        $pdo->prepare(
            "INSERT INTO product_licenses
             (license_key, order_id, product_id, user_id, status, max_seats, used_seats, expires_at)
             VALUES (?, ?, ?, ?, 'active', ?, 0, ?)"
        )->execute([$licenseKey, $orderId, $productId, $userId, $maxSeats, $expiresAt]);

        return $licenseKey;

    } catch (Exception $e) {
        error_log('issueLicense error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Catat satu request ke public API lisensi (audit trail).
 * Tidak pernah throw — kegagalan logging tidak menghentikan flow API.
 */
function logLicenseApi(
    ?int    $licenseId,
    string  $endpoint,
    ?string $stringIdentifier,
    string  $result,
    string  $message = ''
): void {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
        if ($ip !== null) {
            // X-Forwarded-For bisa berisi rantai "client, proxy1, proxy2" — ambil yang pertama.
            $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
        }
        getDB()->prepare(
            "INSERT INTO license_api_logs
             (license_id, endpoint, string_identifier, ip_address, result, message)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$licenseId, $endpoint, $stringIdentifier, $ip, $result, $message]);
    } catch (Exception $e) {
        error_log('logLicenseApi error: ' . $e->getMessage());
    }
}

/**
 * Rate limiting sederhana untuk public API lisensi: maks N request per menit per IP.
 * Memakai tabel license_api_logs sebagai counter. Fail-open saat DB error.
 *
 * @return bool true bila masih boleh request, false bila melebihi limit.
 */
function licenseApiRateLimitOk(int $maxPerMinute = 60): bool {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip !== '') {
            $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
        }
        if ($ip === '') {
            return true; // tanpa IP tak bisa di-rate-limit — biarkan lewat
        }
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM license_api_logs
             WHERE ip_address = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() < $maxPerMinute;
    } catch (Exception $e) {
        error_log('licenseApiRateLimitOk error: ' . $e->getMessage());
        return true;
    }
}

/**
 * Sidik-jari instalasi (watermark) yang stabil per instalasi. Dibuat sekali &
 * disimpan di settings; dikirim ke server lisensi tiap activate/validate agar
 * copy yang dibajak/diklon (instance berbeda) bisa dibedakan & dilacak vendor.
 */
function licenseInstanceId(): string
{
    try {
        $id = (string) getSetting('license_instance_id', '');
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            setSetting('license_instance_id', $id, 'license');
        }
        return $id;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Verifikasi lisensi instalasi Darmutiara dan simpan bukti yang valid.
 * @return array{success:bool,network_error:bool,message:string,status:?string}
 */
function attemptLicenseActivation(string $licenseKey, string $domain): array
{
    $result = requestDarmutiaraLicense($licenseKey, $domain);
    $out = ['success' => false, 'network_error' => !empty($result['network_error']),
        'message' => $result['error'] ?? '', 'status' => null];
    try {
        $db = getDB();
        if (!empty($result['denied'])) {
            $db->prepare("UPDATE license_info SET signed_token = NULL, token_valid_until = NULL,
                status = 'expired', last_checked_at = NOW() WHERE BINARY license_key = ? AND domain = ?")
                ->execute([$licenseKey, $domain]);
        }
        if (!isset($result['token'])) {
            return $out;
        }
        $id = $db->query('SELECT id FROM license_info ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id !== false) {
            $db->prepare("UPDATE license_info SET license_key = ?, domain = ?, signed_token = ?,
                token_valid_until = FROM_UNIXTIME(?), status = 'active', activation_pending_reason = NULL,
                plan = NULL, plan_effective = NULL, expires_at = NULL, raw_response = NULL,
                last_checked_at = NOW(), activated_at = NOW() WHERE id = ?")
                ->execute([$licenseKey, $domain, $result['token'], $result['exp'], $id]);
        } else {
            $db->prepare("INSERT INTO license_info (license_key, domain, signed_token, token_valid_until,
                status, last_checked_at, activated_at) VALUES (?, ?, ?, FROM_UNIXTIME(?), 'active', NOW(), NOW())")
                ->execute([$licenseKey, $domain, $result['token'], $result['exp']]);
        }
        return ['success' => true, 'network_error' => false, 'message' => 'Lisensi Darmutiara aktif.', 'status' => 'active'];
    } catch (Throwable $error) {
        error_log('[darmutiara-license] Gagal menyimpan status aktivasi.');
        $out['message'] = 'Status aktivasi gagal disimpan. Coba lagi.';
        return $out;
    }
}
