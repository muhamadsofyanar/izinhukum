<?php
// POST /actions/admin/mcp-save-settings
// Simpan 2 setting Integrasi Claude: mcp_enabled (toggle aktif/nonaktif) &
// mcp_mask_pii (masking email/WA — default '1' aman). Form mengirim KEDUANYA
// tiap kali salah satu diubah (auto-submit onchange), jadi checkbox yang TAK
// tercentang harus dibaca sebagai '0', bukan diabaikan (perilaku standar HTML).
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
    $enabled = (isset($_POST['mcp_enabled']) && $_POST['mcp_enabled'] === '1') ? '1' : '0';
    $mask    = ((string) ($_POST['mcp_mask_pii'] ?? '1') === '0') ? '0' : '1'; // default aman bila nilai aneh
    $write   = (isset($_POST['mcp_write_enabled']) && $_POST['mcp_write_enabled'] === '1') ? '1' : '0';

    $wasEnabled = (string) getSetting('mcp_enabled', '0') === '1';
    $wasWrite   = (string) getSetting('mcp_write_enabled', '0') === '1';

    setSetting('mcp_enabled', $enabled, 'integrations');
    setSetting('mcp_mask_pii', $mask, 'integrations');
    setSetting('mcp_write_enabled', $write, 'integrations');

    logActivity('admin', 'mcp.settings_save', null, null,
        "Integrasi Claude: enabled={$enabled} mask_pii={$mask} write={$write}");

    if ($enabled === '1' && !$wasEnabled) {
        flash('success', 'Integrasi Claude diaktifkan.');
    } elseif ($enabled === '0' && $wasEnabled) {
        flash('success', 'Integrasi Claude dinonaktifkan — semua permintaan API akan ditolak.');
    } elseif ($write === '1' && !$wasWrite) {
        flash('success', 'Aksi Tulis diaktifkan — Claude kini bisa mengirim pesan (dengan persetujuanmu).');
    } elseif ($write === '0' && $wasWrite) {
        flash('success', 'Aksi Tulis dinonaktifkan.');
    } else {
        flash('success', 'Pengaturan disimpan.');
    }
} catch (Exception $e) {
    error_log('mcp-save-settings error: ' . $e->getMessage());
    flash('error', 'Gagal menyimpan pengaturan. Coba lagi.', 'error');
}

redirect(APP_URL . '/admin/mcp-integration');
