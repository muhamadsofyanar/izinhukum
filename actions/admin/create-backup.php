<?php
// POST /actions/admin/create-backup — backup DB manual ("Backup Sekarang").
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/backups');
}

$r = createDbBackup();
if (!empty($r['success'])) {
    logActivity('admin', 'backup.create', null, null,
        'Backup DB manual: ' . $r['file'] . ' (' . number_format($r['size'] / 1024, 1) . ' KB, ' . $r['tables'] . ' tabel, ' . $r['duration'] . 's)');
    flash('success', 'Backup berhasil dibuat: ' . $r['file'] . ' (' . number_format($r['size'] / 1024, 1) . ' KB).');
} else {
    flash('error', $r['message'] ?? 'Backup gagal.', 'error');
}
redirect('/admin/backups');
