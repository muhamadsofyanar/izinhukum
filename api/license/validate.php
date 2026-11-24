<?php
/**
 * Public API — Validasi License Key (Produk Digital Vendor)
 *
 * METHOD : GET
 * URL    : /api/license/validate?license_key=AVRN-...&string=yourdomain.com
 *
 * Endpoint PUBLIC: TANPA session login, TANPA CSRF. Selalu balas JSON.
 * Memeriksa apakah identifier tertentu sudah teraktivasi & license masih sah.
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function license_api_respond(int $httpCode, array $payload): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ── Rate limit ────────────────────────────────────────────
    if (!licenseApiRateLimitOk(60)) {
        license_api_respond(429, ['valid' => false, 'status' => 'rate_limited', 'message' => 'Terlalu banyak permintaan. Coba lagi sebentar.']);
    }

    $licenseKey = trim((string) ($_GET['license_key'] ?? ''));
    $identifier = substr(trim((string) ($_GET['string'] ?? '')), 0, 255);

    if ($licenseKey === '' || $identifier === '') {
        logLicenseApi(null, 'validate', $identifier ?: null, 'failed', 'Parameter tidak lengkap');
        license_api_respond(400, ['valid' => false, 'status' => 'bad_request', 'message' => 'license_key dan string wajib diisi.']);
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
        logLicenseApi(null, 'validate', $identifier, 'not_found', 'Key tidak ditemukan');
        license_api_respond(404, ['valid' => false, 'status' => 'not_found', 'message' => 'License key tidak ditemukan']);
    }

    $licenseId = (int) $license['id'];

    // ── Cek status ────────────────────────────────────────────
    if ($license['status'] === 'suspended') {
        logLicenseApi($licenseId, 'validate', $identifier, 'suspended', 'License disuspend');
        license_api_respond(403, ['valid' => false, 'status' => 'suspended', 'message' => 'License disuspend']);
    }
    if ($license['status'] === 'revoked') {
        logLicenseApi($licenseId, 'validate', $identifier, 'suspended', 'License direvoke');
        license_api_respond(403, ['valid' => false, 'status' => 'revoked', 'message' => 'License direvoke']);
    }

    // ── Cek expiry ────────────────────────────────────────────
    if ($license['status'] === 'expired' ||
        ($license['expires_at'] !== null && strtotime($license['expires_at']) < time())) {
        if ($license['status'] !== 'expired') {
            $pdo->prepare("UPDATE product_licenses SET status = 'expired' WHERE id = ?")->execute([$licenseId]);
        }
        logLicenseApi($licenseId, 'validate', $identifier, 'expired', 'License sudah expired');
        license_api_respond(403, ['valid' => false, 'status' => 'expired', 'message' => 'License sudah expired']);
    }

    // ── Cek apakah identifier teraktivasi & active ────────────
    $actStmt = $pdo->prepare(
        "SELECT * FROM license_activations
         WHERE license_id = ? AND string_identifier = ? AND status = 'active' LIMIT 1"
    );
    $actStmt->execute([$licenseId, $identifier]);
    $activation = $actStmt->fetch();

    if (!$activation) {
        logLicenseApi($licenseId, 'validate', $identifier, 'failed', 'Identifier belum diaktifkan');
        license_api_respond(200, [
            'valid'   => false,
            'status'  => 'not_activated',
            'message' => 'Identifier ini belum diaktifkan',
        ]);
    }

    // ── Update timestamp validasi ─────────────────────────────
    $pdo->prepare("UPDATE license_activations SET last_validated_at = NOW() WHERE id = ?")
        ->execute([(int) $activation['id']]);
    $pdo->prepare("UPDATE product_licenses SET last_checked_at = NOW() WHERE id = ?")
        ->execute([$licenseId]);

    logLicenseApi($licenseId, 'validate', $identifier, 'success', 'Valid');
    license_api_respond(200, [
        'valid'      => true,
        'status'     => 'active',
        'product'    => $license['product_name'],
        'expires_at' => $license['expires_at'],
        'seats_used' => (int) $license['used_seats'],
        'seats_max'  => (int) $license['max_seats'],
    ]);

} catch (Exception $e) {
    error_log('license validate error: ' . $e->getMessage());
    license_api_respond(500, ['valid' => false, 'status' => 'error', 'message' => 'Terjadi kesalahan sistem.']);
}
