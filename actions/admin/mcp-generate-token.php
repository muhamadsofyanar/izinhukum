<?php
// POST /actions/admin/mcp-generate-token
// Generate token API Integrasi Claude (MCP): random_bytes(32) → bin2hex (64 char).
// Yang disimpan HANYA hash sha256 (settings key-value, tanpa migration — pola sama
// studio_api_token_hash); plaintext ditaruh sekali di session untuk modal
// "tampilkan sekali" lalu hilang. Generate ulang = revoke token lama (hash tertimpa).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/admin/mcp-integration');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Coba lagi.', 'error');
    redirect(APP_URL . '/admin/mcp-integration');
}

try {
    $token = bin2hex(random_bytes(32)); // 64 karakter hex
    setSetting('mcp_api_token_hash', hash('sha256', $token), 'integrations');
    setSetting('mcp_api_token_created_at', (string) time(), 'integrations');

    // Tampilkan sekali di halaman tujuan (dibaca lalu di-unset di sana).
    $_SESSION['mcp_token_show'] = $token;

    logActivity('admin', 'mcp.token_generate', null, null, 'Token API Integrasi Claude dibuat/di-generate ulang');
    flash('success', 'Token Claude berhasil dibuat. Salin sekarang — tidak akan ditampilkan lagi.');
} catch (Exception $e) {
    error_log('mcp-generate-token error: ' . $e->getMessage());
    flash('error', 'Gagal membuat token. Coba lagi.', 'error');
}

redirect(APP_URL . '/admin/mcp-integration');
