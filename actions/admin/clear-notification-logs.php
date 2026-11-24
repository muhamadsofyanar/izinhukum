<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Request tidak valid.', 'error');
    redirect('/admin/notifications?tab=logs');
}

try {
    $pdo = getDB();

    $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM notification_logs")->fetchColumn();
    $pdo->exec("TRUNCATE TABLE notification_logs");

    logActivity('admin', 'notification_logs.clear_all', 'notification_logs', null,
        "Admin membersihkan seluruh log notifikasi ({$countBefore} baris)");

    flash('success', "Berhasil menghapus {$countBefore} baris log notifikasi.");

} catch (Exception $e) {
    error_log('Clear notification logs error: ' . $e->getMessage());
    flash('error', 'Gagal membersihkan log notifikasi.', 'error');
}

redirect('/admin/notifications?tab=logs');
