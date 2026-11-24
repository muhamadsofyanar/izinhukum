<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/integrations?tab=payment');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    saveResult(false, 'Token keamanan tidak valid. Silakan coba lagi.', '/admin/integrations?tab=payment');
}

$allowedProviders = ['duitku', 'xendit', 'moota', 'paypal', 'midtrans', 'tripay'];
$provider = trim($_POST['provider'] ?? '');

if (!in_array($provider, $allowedProviders, true)) {
    saveResult(false, 'Provider tidak valid.', '/admin/integrations?tab=payment');
}

$isActive      = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
$mode          = in_array($_POST['mode'] ?? '', ['sandbox', 'production']) ? $_POST['mode'] : 'sandbox';
$merchantCode  = trim($_POST['merchant_code'] ?? '');
$apiKey        = trim($_POST['api_key'] ?? '');
$secretKey     = trim($_POST['secret_key'] ?? '');
$callbackToken = trim($_POST['callback_token'] ?? '');

// Validasi: jangan izinkan production jika kredensial kosong
if ($isActive && $mode === 'production' && empty($apiKey)) {
    saveResult(false, 'Tidak bisa mengaktifkan mode production dengan API Key kosong.', '/admin/integrations?tab=payment');
}

try {
    $pdo = getDB();

    // Jika field dikirim kosong (password field yg tidak diubah), jangan overwrite nilai lama
    // — ambil nilai saat ini dari DB lalu merge
    $current = $pdo->prepare("SELECT * FROM payment_gateways WHERE provider = ? LIMIT 1");
    $current->execute([$provider]);
    $existing = $current->fetch() ?: [];

    $finalMerchantCode  = $merchantCode  !== '' ? $merchantCode  : ($existing['merchant_code']  ?? null);
    $finalApiKey        = $apiKey        !== '' ? $apiKey        : ($existing['api_key']        ?? null);
    $finalSecretKey     = $secretKey     !== '' ? $secretKey     : ($existing['secret_key']     ?? null);
    $finalCallbackToken = $callbackToken !== '' ? $callbackToken : ($existing['callback_token'] ?? null);

    // config_json dipertahankan apa adanya. Info Rekening Moota (bank_info) TIDAK diisi
    // dari sini — diambil OTOMATIS dari API Moota saat "Test Koneksi" (test-moota.php).
    $finalConfigJson = $existing['config_json'] ?? null;

    // PayPal: simpan kurs IDR→USD di config_json (merge dgn config lama).
    if ($provider === 'paypal') {
        $cfg = [];
        if (!empty($existing['config_json'])) { $cfg = json_decode((string) $existing['config_json'], true) ?: []; }
        $rateRaw = str_replace([',', ' '], ['.', ''], trim((string) ($_POST['paypal_rate'] ?? '')));
        if ($rateRaw !== '' && is_numeric($rateRaw)) {
            $cfg['idr_usd_rate'] = round((float) $rateRaw, 2);
        }
        $finalConfigJson = !empty($cfg) ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null;
    }

    // Validasi khusus PayPal: aktif tanpa kurs → tolak (tak bisa nagih USD).
    if ($provider === 'paypal' && $isActive) {
        $cfgChk = json_decode((string) ($finalConfigJson ?? ''), true) ?: [];
        if ((float) ($cfgChk['idr_usd_rate'] ?? 0) <= 0) {
            saveResult(false, 'Isi kurs IDR→USD dulu sebelum mengaktifkan PayPal.', '/admin/integrations?tab=payment');
        }
        if (empty($finalApiKey) || empty($finalSecretKey)) {
            saveResult(false, 'Client ID & Secret PayPal wajib diisi untuk mengaktifkan.', '/admin/integrations?tab=payment');
        }
    }

    $pdo->prepare(
        "UPDATE payment_gateways
         SET is_active = ?, mode = ?, merchant_code = ?, api_key = ?,
             secret_key = ?, callback_token = ?, config_json = ?, updated_at = NOW()
         WHERE provider = ?"
    )->execute([
        $isActive,
        $mode,
        $finalMerchantCode,
        $finalApiKey,
        $finalSecretKey,
        $finalCallbackToken,
        $finalConfigJson,
        $provider,
    ]);

    logActivity('admin', 'payment_gateway.update', 'payment_gateway', null,
        'Konfigurasi ' . strtoupper($provider) . ' diperbarui (mode: ' . $mode . ', aktif: ' . ($isActive ? 'ya' : 'tidak') . ')');

} catch (Exception $e) {
    error_log('save-payment-gateway error: ' . $e->getMessage());
    saveResult(false, 'Gagal menyimpan konfigurasi. Silakan coba lagi.', '/admin/integrations?tab=payment');
}

saveResult(true, 'Konfigurasi ' . strtoupper($provider) . ' berhasil disimpan.', '/admin/integrations?tab=payment');
