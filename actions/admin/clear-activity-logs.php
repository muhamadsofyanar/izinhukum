<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Request tidak valid.', 'error');
    redirect('/admin/activity-logs');
}

$confirmText = trim($_POST['confirm_text'] ?? '');
if ($confirmText !== 'HAPUS') {
    flash('error', 'Konfirmasi tidak sesuai. Ketik "HAPUS" persis untuk melanjutkan.', 'error');
    redirect('/admin/activity-logs');
}

$mode = $_POST['clear_mode'] ?? 'older_than';

try {
    $pdo = getDB();

    if ($mode === 'all') {
        $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

        $pdo->exec("TRUNCATE TABLE activity_logs");

        // Catat aksi clear ini sebagai entri PERTAMA setelah TRUNCATE
        // supaya audit trail tidak pernah benar-benar kosong total
        logActivity('admin', 'activity_logs.clear_all', 'activity_logs', null,
            "Admin menghapus SELURUH activity log ({$countBefore} baris)");

        flash('success', "Berhasil menghapus {$countBefore} baris activity log.");

    } elseif ($mode === 'older_than') {
        $days = max(1, (int) ($_POST['days'] ?? 90));

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $countStmt->execute([$days]);
        $countToDelete = (int) $countStmt->fetchColumn();

        $pdo->prepare(
            "DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        )->execute([$days]);

        logActivity('admin', 'activity_logs.clear_old', 'activity_logs', null,
            "Admin menghapus log lebih lama dari {$days} hari ({$countToDelete} baris)");

        flash('success', "Berhasil menghapus {$countToDelete} baris activity log yang lebih lama dari {$days} hari.");

    } else {
        flash('error', 'Mode penghapusan tidak valid.', 'error');
    }

} catch (Exception $e) {
    error_log('Clear activity logs error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus activity logs.', 'error');
}

redirect('/admin/activity-logs');
