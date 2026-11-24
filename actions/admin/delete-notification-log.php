<?php
// POST /actions/admin/delete-notification-log — hapus log notifikasi.
//   • id=<n>            → hapus 1 baris
//   • scope=all_failed  → hapus SEMUA log berstatus gagal (bersih-bersih)
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('notifications')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/system-health');
}

try {
    $pdo = getDB();
    if (($_POST['scope'] ?? '') === 'all_failed') {
        $n = $pdo->exec("DELETE FROM notification_logs WHERE status = 'failed'");
        logActivity('admin', 'notification.clear_failed', 'notification_logs', null, "Bersihkan log gagal: $n baris.");
        flash('success', 'Berhasil menghapus ' . (int) $n . ' log notifikasi gagal.');
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) { flash('error', 'Log tidak valid.', 'error'); redirect('/admin/system-health'); }
        $pdo->prepare("DELETE FROM notification_logs WHERE id = ?")->execute([$id]);
        logActivity('admin', 'notification.delete', 'notification_logs', $id, 'Hapus 1 log notifikasi.');
        flash('success', 'Log notifikasi dihapus.');
    }
} catch (Throwable $e) {
    error_log('delete-notification-log error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus log.', 'error');
}

redirect('/admin/system-health');
