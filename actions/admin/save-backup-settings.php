<?php
// POST /actions/admin/save-backup-settings — pengaturan auto-backup DB.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/backups');
}

$enabled   = isset($_POST['db_backup_enabled']) ? '1' : '0';
$retention = (string) min(90, max(1, (int) ($_POST['db_backup_retention_days'] ?? 7)));

setSetting('db_backup_enabled', $enabled, 'backup');
setSetting('db_backup_retention_days', $retention, 'backup');

flash('success', 'Pengaturan backup disimpan.');
redirect('/admin/backups');
