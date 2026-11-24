<?php
// POST /actions/admin/mcp-revoke-token
// Cabut token API Integrasi Claude: hapus setting hash + tanggal. Semua endpoint
// /api/internal/* langsung menolak (403) token lama setelah ini.
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
    getDB()->prepare("DELETE FROM settings WHERE setting_key IN ('mcp_api_token_hash', 'mcp_api_token_created_at')")
        ->execute();

    logActivity('admin', 'mcp.token_revoke', null, null, 'Token API Integrasi Claude dicabut');
    flash('success', 'Token Claude dicabut. Koneksi dengan token lama tidak berlaku lagi.');
} catch (Exception $e) {
    error_log('mcp-revoke-token error: ' . $e->getMessage());
    flash('error', 'Gagal mencabut token. Coba lagi.', 'error');
}

redirect(APP_URL . '/admin/mcp-integration');
