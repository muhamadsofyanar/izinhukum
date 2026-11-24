<?php
// POST /actions/admin/studio-revoke-token
// Cabut token API Averion Studio: hapus setting hash + tanggal. Endpoint
// /api/studio/products langsung menolak (403) token lama setelah ini.
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
    getDB()->prepare("DELETE FROM settings WHERE setting_key IN ('studio_api_token_hash', 'studio_api_token_created_at')")
        ->execute();

    logActivity('admin', 'studio.token_revoke', null, null, 'Token API Averion Studio dicabut');
    flash('success', 'Token Studio dicabut. Koneksi Studio dengan token lama tidak berlaku lagi.');
} catch (Exception $e) {
    error_log('studio-revoke-token error: ' . $e->getMessage());
    flash('error', 'Gagal mencabut token. Coba lagi.', 'error');
}

redirect(APP_URL . '/admin/studio-integration');
