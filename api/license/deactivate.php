<?php
/**
 * Public API — Deaktivasi (Lepas) Seat License Key
 *
 * METHOD : POST
 * URL    : /api/license/deactivate
 * BODY   : JSON atau form-data
 *   - license_key (required)
 *   - string      (required)
 *
 * Endpoint PUBLIC: TANPA session login, TANPA CSRF. Selalu balas JSON.
 * Melepas slot aktivasi sehingga bisa dipakai di domain/device lain.
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function license_api_respond(int $httpCode, array $payload): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        license_api_respond(405, ['success' => false, 'message' => 'Gunakan metode POST.']);
    }

    if (!licenseApiRateLimitOk(60)) {
        license_api_respond(429, ['success' => false, 'message' => 'Terlalu banyak permintaan. Coba lagi sebentar.']);
    }

    $raw   = file_get_contents('php://input');
    $json  = json_decode($raw ?: '', true);
    $input = is_array($json) ? $json : $_POST;

    $licenseKey = trim((string) ($input['license_key'] ?? ''));
    $identifier = substr(trim((string) ($input['string'] ?? '')), 0, 255);

    if ($licenseKey === '' || $identifier === '') {
        logLicenseApi(null, 'deactivate', $identifier ?: null, 'failed', 'Parameter tidak lengkap');
        license_api_respond(400, ['success' => false, 'message' => 'license_key dan string wajib diisi.']);
    }

    $pdo = getDB();

    // ── Cari license ──────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM product_licenses WHERE license_key = ? LIMIT 1");
    $stmt->execute([$licenseKey]);
    $license = $stmt->fetch();

    if (!$license) {
        logLicenseApi(null, 'deactivate', $identifier, 'not_found', 'Key tidak ditemukan');
        license_api_respond(404, ['success' => false, 'message' => 'License key tidak ditemukan']);
    }

    $licenseId = (int) $license['id'];

    if ($license['status'] === 'revoked') {
        logLicenseApi($licenseId, 'deactivate', $identifier, 'suspended', 'License direvoke');
        license_api_respond(403, ['success' => false, 'message' => 'License direvoke']);
    }

    // ── Cari aktivasi aktif untuk identifier ini ──────────────
    $actStmt = $pdo->prepare(
        "SELECT * FROM license_activations
         WHERE license_id = ? AND string_identifier = ? AND status = 'active' LIMIT 1"
    );
    $actStmt->execute([$licenseId, $identifier]);
    $activation = $actStmt->fetch();

    if (!$activation) {
        logLicenseApi($licenseId, 'deactivate', $identifier, 'failed', 'Identifier tidak terdaftar/aktif');
        license_api_respond(200, [
            'success' => false,
            'message' => 'Identifier ini tidak terdaftar atau sudah dinonaktifkan',
        ]);
    }

    // ── Deaktivasi (atomik) ───────────────────────────────────
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE license_activations SET status = 'deactivated', deactivated_at = NOW() WHERE id = ?"
        )->execute([(int) $activation['id']]);

        $pdo->prepare(
            "UPDATE product_licenses SET used_seats = GREATEST(0, used_seats - 1) WHERE id = ?"
        )->execute([$licenseId]);

        $pdo->commit();
    } catch (Exception $txEx) {
        $pdo->rollBack();
        throw $txEx;
    }

    logLicenseApi($licenseId, 'deactivate', $identifier, 'success', 'deactivated');
    license_api_respond(200, [
        'success' => true,
        'message' => 'Aktivasi berhasil dihapus. Slot dapat digunakan di device/domain lain.',
    ]);

} catch (Exception $e) {
    error_log('license deactivate error: ' . $e->getMessage());
    license_api_respond(500, ['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
}
