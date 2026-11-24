<?php
// Kirim 1 event Purchase TEST ke Meta CAPI dari konfigurasi tersimpan.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Request tidak valid.']);
    exit;
}

try {
    $st = getDB()->prepare("SELECT * FROM tracking_pixels WHERE platform = 'meta_capi' LIMIT 1");
    $st->execute();
    $config = $st->fetch();

    if (!$config || trim((string) ($config['pixel_id'] ?? '')) === '' || trim((string) ($config['access_token'] ?? '')) === '') {
        echo json_encode(['status' => 'error', 'message' => 'Meta CAPI belum dikonfigurasi (Pixel ID / Access Token kosong). Simpan dulu.']);
        exit;
    }

    require_once __DIR__ . '/../../tracking/PixelHelper.php';
    $res = PixelHelper::sendMetaTestEvent($config);

    echo json_encode([
        'status'    => $res['ok'] ? 'success' : 'error',
        'http_code' => $res['http'] ?? 0,
        'message'   => $res['ok']
            ? 'Test event terkirim. Cek Meta Events Manager → Test Events.'
            : ('Gagal: ' . ($res['message'] ?? 'unknown')),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('test-meta-capi error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan sistem.']);
}
