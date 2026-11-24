<?php
/**
 * Public API — Aktivasi License Key (Produk Digital Vendor)
 *
 * METHOD : POST
 * URL    : /api/license/activate
 * BODY   : JSON atau form-data
 *   - license_key (required)
 *   - string      (required) — identifier unik: domain / IP / device id
 *   - app_version (optional)
 *
 * Endpoint PUBLIC: TANPA session login, TANPA CSRF. Dipanggil server-to-server
 * atau dari software pihak ketiga milik buyer. Selalu balas JSON.
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/** Helper balas JSON + exit. */
function license_api_respond(int $httpCode, array $payload): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ── Method guard ──────────────────────────────────────────
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        license_api_respond(405, ['success' => false, 'message' => 'Gunakan metode POST.']);
    }

    // ── Rate limit (60 req/menit/IP) ──────────────────────────
    if (!licenseApiRateLimitOk(60)) {
        license_api_respond(429, ['success' => false, 'message' => 'Terlalu banyak permintaan. Coba lagi sebentar.']);
    }

    // ── Parse input: JSON body atau form-data ─────────────────
    $raw   = file_get_contents('php://input');
    $json  = json_decode($raw ?: '', true);
    $input = is_array($json) ? $json : $_POST;

    $licenseKey = trim((string) ($input['license_key'] ?? ''));
    $identifier = substr(trim((string) ($input['string'] ?? '')), 0, 255);
    $appVersion = substr(trim((string) ($input['app_version'] ?? '')), 0, 20);

    if ($licenseKey === '' || $identifier === '') {
        logLicenseApi(null, 'activate', $identifier ?: null, 'failed', 'Parameter tidak lengkap');
        license_api_respond(400, ['success' => false, 'message' => 'license_key dan string wajib diisi.']);
    }

    $pdo = getDB();

    // ── Cari license ──────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT pl.*, p.name AS product_name
         FROM product_licenses pl
         JOIN products p ON pl.product_id = p.id
         WHERE pl.license_key = ? LIMIT 1"
    );
    $stmt->execute([$licenseKey]);
    $license = $stmt->fetch();

    if (!$license) {
        logLicenseApi(null, 'activate', $identifier, 'not_found', 'Key tidak ditemukan');
        license_api_respond(404, ['success' => false, 'message' => 'License key tidak ditemukan']);
    }

    $licenseId = (int) $license['id'];

    // ── Cek status ────────────────────────────────────────────
    if ($license['status'] === 'suspended') {
        logLicenseApi($licenseId, 'activate', $identifier, 'suspended', 'License disuspend');
        license_api_respond(403, ['success' => false, 'message' => 'License disuspend']);
    }
    if ($license['status'] === 'revoked') {
        logLicenseApi($licenseId, 'activate', $identifier, 'suspended', 'License direvoke');
        license_api_respond(403, ['success' => false, 'message' => 'License direvoke']);
    }
    if ($license['status'] === 'expired') {
        logLicenseApi($licenseId, 'activate', $identifier, 'expired', 'License sudah expired');
        license_api_respond(403, ['success' => false, 'message' => 'License sudah expired']);
    }

    // ── Cek expires_at (auto-flag expired) ────────────────────
    if ($license['expires_at'] !== null && strtotime($license['expires_at']) < time()) {
        $pdo->prepare("UPDATE product_licenses SET status = 'expired' WHERE id = ?")->execute([$licenseId]);
        logLicenseApi($licenseId, 'activate', $identifier, 'expired', 'License sudah expired');
        license_api_respond(403, ['success' => false, 'message' => 'License sudah expired']);
    }

    // ── Apakah identifier ini sudah aktif? (idempotent) ───────
    $actStmt = $pdo->prepare(
        "SELECT * FROM license_activations
         WHERE license_id = ? AND string_identifier = ? AND status = 'active' LIMIT 1"
    );
    $actStmt->execute([$licenseId, $identifier]);
    $existingAct = $actStmt->fetch();

    if ($existingAct) {
        // Sudah aktif → tidak menambah seat, cukup refresh timestamp.
        $pdo->prepare(
            "UPDATE license_activations SET last_validated_at = NOW(), ip_address = ?, app_version = ? WHERE id = ?"
        )->execute([
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            $appVersion ?: null,
            (int) $existingAct['id'],
        ]);
        $pdo->prepare("UPDATE product_licenses SET last_checked_at = NOW() WHERE id = ?")->execute([$licenseId]);

        logLicenseApi($licenseId, 'activate', $identifier, 'success', 'Sudah aktif sebelumnya');
        license_api_respond(200, [
            'success' => true,
            'message' => 'Aktivasi berhasil',
            'data'    => [
                'license_key'  => $license['license_key'],
                'product'      => $license['product_name'],
                'status'       => 'active',
                'seats_used'   => (int) $license['used_seats'],
                'seats_max'    => (int) $license['max_seats'],
                'expires_at'   => $license['expires_at'],
                'activated_at' => $existingAct['activated_at'],
            ],
        ]);
    }

    // ── Aktivasi baru — race-safe (cek seat + increment ATOMIK) ──
    // Kunci baris lisensi (FOR UPDATE) agar cek kuota seat & increment tidak
    // bisa dibalap request paralel. Mencegah used_seats melebihi max_seats.
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;

    $pdo->beginTransaction();
    try {
        // Baca seat terkini di dalam lock (nilai authoritative, bukan dari SELECT awal).
        $lockStmt = $pdo->prepare(
            "SELECT used_seats, max_seats FROM product_licenses WHERE id = ? FOR UPDATE"
        );
        $lockStmt->execute([$licenseId]);
        $lockRow   = $lockStmt->fetch();
        $usedSeats = (int) ($lockRow['used_seats'] ?? 0);
        $maxSeats  = (int) ($lockRow['max_seats'] ?? 0);

        // Cek ulang DI DALAM lock: identifier ini bisa jadi baru saja diaktifkan
        // request paralel (race same-identifier) → idempotent, jangan tambah seat.
        $reAct = $pdo->prepare(
            "SELECT id, activated_at FROM license_activations
             WHERE license_id = ? AND string_identifier = ? AND status = 'active' LIMIT 1"
        );
        $reAct->execute([$licenseId, $identifier]);
        $already = $reAct->fetch();

        if ($already) {
            $pdo->prepare(
                "UPDATE license_activations SET last_validated_at = NOW(), ip_address = ?, app_version = ? WHERE id = ?"
            )->execute([$ip, $appVersion ?: null, (int) $already['id']]);
            $pdo->prepare("UPDATE product_licenses SET last_checked_at = NOW() WHERE id = ?")->execute([$licenseId]);
            $pdo->commit();

            logLicenseApi($licenseId, 'activate', $identifier, 'success', 'Sudah aktif (race-safe)');
            license_api_respond(200, [
                'success' => true,
                'message' => 'Aktivasi berhasil',
                'data'    => [
                    'license_key'  => $license['license_key'],
                    'product'      => $license['product_name'],
                    'status'       => 'active',
                    'seats_used'   => $usedSeats,
                    'seats_max'    => $maxSeats,
                    'expires_at'   => $license['expires_at'],
                    'activated_at' => $already['activated_at'],
                ],
            ]);
        }

        // Butuh seat baru → cek kuota di dalam lock.
        if ($usedSeats >= $maxSeats) {
            $pdo->rollBack();
            logLicenseApi($licenseId, 'activate', $identifier, 'seats_full', 'Maks aktivasi tercapai');
            license_api_respond(403, [
                'success' => false,
                'message' => 'Maks aktivasi tercapai (' . $usedSeats . '/' . $maxSeats . ')',
            ]);
        }

        // Insert / aktifkan kembali (ON DUPLICATE KEY uk_license_string) + increment seat.
        $pdo->prepare(
            "INSERT INTO license_activations
             (license_id, string_identifier, ip_address, app_version, status, activated_at, last_validated_at)
             VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = 'active', ip_address = VALUES(ip_address),
                app_version = VALUES(app_version), activated_at = NOW(),
                last_validated_at = NOW(), deactivated_at = NULL"
        )->execute([$licenseId, $identifier, $ip, $appVersion ?: null]);

        $pdo->prepare(
            "UPDATE product_licenses SET used_seats = used_seats + 1, last_checked_at = NOW() WHERE id = ?"
        )->execute([$licenseId]);

        $pdo->commit();
    } catch (Exception $txEx) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txEx;
    }

    $newUsed = $usedSeats + 1;
    logLicenseApi($licenseId, 'activate', $identifier, 'success', 'Aktivasi baru');
    license_api_respond(200, [
        'success' => true,
        'message' => 'Aktivasi berhasil',
        'data'    => [
            'license_key'  => $license['license_key'],
            'product'      => $license['product_name'],
            'status'       => 'active',
            'seats_used'   => $newUsed,
            'seats_max'    => $maxSeats,
            'expires_at'   => $license['expires_at'],
            'activated_at' => date('Y-m-d H:i:s'),
        ],
    ]);

} catch (Exception $e) {
    error_log('license activate error: ' . $e->getMessage());
    license_api_respond(500, ['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
}
