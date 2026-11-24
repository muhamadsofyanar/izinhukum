<?php
// POST /actions/admin/save-hermes — simpan konfigurasi Hermes Agent (admin):
// toggle master + tier manajemen. Token TIDAK diubah di sini (lihat hermes-token).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/hermes-agent');
}

$enabled = isset($_POST['hermes_enabled']) ? '1' : '0';
$manage  = isset($_POST['hermes_manage_enabled']) ? '1' : '0';
$read    = isset($_POST['hermes_read_enabled']) ? '1' : '0';

// Cegah mengaktifkan tanpa token — endpoint akan menolak semua request.
if (($enabled === '1' || $manage === '1' || $read === '1') && !hermesTokenIsSet()) {
    flash('error', 'Buat token dulu sebelum mengaktifkan Hermes Agent.', 'error');
    redirect('/admin/hermes-agent');
}

setSetting('hermes_enabled', $enabled, 'hermes');
setSetting('hermes_manage_enabled', $manage, 'hermes');
setSetting('hermes_read_enabled', $read, 'hermes');

flash('success', 'Pengaturan Hermes Agent disimpan.');
redirect('/admin/hermes-agent');
