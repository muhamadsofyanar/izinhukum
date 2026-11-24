<?php
// Simpan konfigurasi Meta (multi-pixel + CAPI + event funnel mapping).
// Juga menangani test event per-pixel (AJAX).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$action = $_POST['action'] ?? '';

// ─── Test event per-pixel (AJAX) ──────────────────────────────
if ($action === 'test_meta_pixel') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'Request tidak valid.']);
        exit;
    }
    $rid = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['pixel_row_id'] ?? ''));
    $list = json_decode((string) getSetting('meta_pixels_json', ''), true);
    $found = null;
    if (is_array($list)) {
        foreach ($list as $r) {
            if (is_array($r) && (string) ($r['id'] ?? '') === $rid) { $found = $r; break; }
        }
    }
    if (!$found || trim((string) ($found['pixel_id'] ?? '')) === '' || trim((string) ($found['token_enc'] ?? '')) === '') {
        echo json_encode(['status' => 'error', 'message' => 'Pixel ini belum punya Pixel ID / Access Token tersimpan. Simpan dulu.']);
        exit;
    }
    require_once __DIR__ . '/../../tracking/PixelHelper.php';
    $res = PixelHelper::sendMetaTestEvent([
        'pixel_id'        => (string) $found['pixel_id'],
        'token'           => pixel_decrypt((string) $found['token_enc']),
        'test_event_code' => (string) ($found['test_event_code'] ?? ''),
    ]);
    echo json_encode([
        'status'    => $res['ok'] ? 'success' : 'error',
        'http_code' => $res['http'] ?? 0,
        'message'   => $res['ok'] ? 'Test event terkirim. Cek Meta Events Manager → Test Events.' : ('Gagal: ' . ($res['message'] ?? 'unknown')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Simpan konfigurasi ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/tracking-pixels');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/tracking-pixels');
}

// Pixel lama (by id) untuk mempertahankan token bila field dikosongkan.
$existing = json_decode((string) getSetting('meta_pixels_json', ''), true);
$existingById = [];
if (is_array($existing)) {
    foreach ($existing as $r) {
        if (is_array($r) && isset($r['id'])) $existingById[(string) $r['id']] = $r;
    }
}

$order = $_POST['meta_pixel_order'] ?? [];
if (!is_array($order)) $order = [];
$pixels = [];
foreach ($order as $ridRaw) {
    $rid = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $ridRaw);
    if ($rid === '') continue;
    $pid = preg_replace('/[^0-9]/', '', (string) ($_POST['meta_pixel_id'][$rid] ?? ''));
    if ($pid === '') continue;
    $enabled = ((string) ($_POST['meta_pixel_enabled'][$rid] ?? '1') === '1') ? '1' : '0';
    $tokenIn = trim((string) ($_POST['meta_pixel_token'][$rid] ?? ''));
    $tokenEnc = $tokenIn !== '' ? pixel_encrypt($tokenIn) : (string) ($existingById[$rid]['token_enc'] ?? '');
    $testCode = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['meta_pixel_test_event_code'][$rid] ?? ''));
    if (strlen($testCode) > 80) $testCode = substr($testCode, 0, 80);
    $pixels[] = [
        'id'              => $rid,
        'enabled'         => $enabled,
        'pixel_id'        => $pid,
        'token_enc'       => $tokenEnc,
        'test_event_code' => $testCode,
    ];
}

// Event funnel mapping
$allowed = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Lead', 'Purchase', 'CompleteRegistration', 'Subscribe', 'Contact', 'CustomEvent'];
$defaults = ['checkout' => 'AddToCart', 'invoice' => 'Lead', 'paid' => 'Purchase'];

try {
    setSetting('meta_pixel_enabled', isset($_POST['meta_pixel_enabled_global']) ? '1' : '0', 'tracking');
    setSetting('meta_capi_enabled', isset($_POST['meta_capi_enabled_global']) ? '1' : '0', 'tracking');
    // Corong penuh: kirim ViewContent (sales page) & event checkout via CAPI
    // selain Purchase. Opt-in (default mati).
    setSetting('meta_full_funnel_enabled', isset($_POST['meta_full_funnel_enabled']) ? '1' : '0', 'tracking');
    setSetting('meta_pixels_json', json_encode($pixels, JSON_UNESCAPED_UNICODE), 'tracking');

    foreach ($defaults as $stage => $def) {
        $v = (string) ($_POST['meta_event_' . $stage] ?? '');
        if (!in_array($v, $allowed, true)) $v = $def;
        setSetting('meta_event_' . $stage, $v, 'tracking');
        $cv = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['meta_event_' . $stage . '_custom'] ?? ''));
        if (strlen($cv) > 40) $cv = substr($cv, 0, 40);
        setSetting('meta_event_' . $stage . '_custom', $cv, 'tracking');
    }

    flash('success', 'Konfigurasi Meta tracking berhasil disimpan (' . count($pixels) . ' pixel).');
} catch (Exception $e) {
    error_log('save-meta-tracking error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan konfigurasi Meta.', 'error');
}

redirect('/admin/tracking-pixels');
