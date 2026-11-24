<?php
// POST /actions/admin/studio-generate-token
// Generate token API Averion Studio: random_bytes(32) → bin2hex (64 char).
// Yang disimpan HANYA hash sha256 (settings key-value, tanpa migration);
// plaintext ditaruh sekali di session untuk modal "tampilkan sekali" lalu hilang.
// Generate ulang = revoke token lama (hash tertimpa).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/admin/studio-integration');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Coba lagi.', 'error');
    redirect(APP_URL . '/admin/studio-integration');
}

try {
    $token = bin2hex(random_bytes(32)); // 64 karakter hex
    setSetting('studio_api_token_hash', hash('sha256', $token), 'integrations');
    setSetting('studio_api_token_created_at', (string) time(), 'integrations');

    // Tampilkan sekali di halaman tujuan (dibaca lalu di-unset di sana).
    $_SESSION['studio_token_show'] = $token;

    logActivity('admin', 'studio.token_generate', null, null, 'Token API Averion Studio dibuat/di-generate ulang');
    flash('success', 'Token Studio berhasil dibuat. Salin sekarang — tidak akan ditampilkan lagi.');
} catch (Exception $e) {
    error_log('studio-generate-token error: ' . $e->getMessage());
    flash('error', 'Gagal membuat token. Coba lagi.', 'error');
}

redirect(APP_URL . '/admin/studio-integration');
