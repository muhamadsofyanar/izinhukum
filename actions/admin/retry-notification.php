<?php
// POST /actions/admin/retry-notification — kirim ulang notifikasi yang gagal.
// Hanya bisa untuk baris yang payload-nya tersimpan (kolom body terisi).
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('notifications')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/system-health');
}

$id = (int) ($_POST['id'] ?? 0);

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM notification_logs WHERE id = ? AND status = 'failed' LIMIT 1");
    $stmt->execute([$id]);
    $log = $stmt->fetch();

    if (!$log || $log['body'] === null || $log['body'] === '') {
        flash('error', 'Log tidak ditemukan atau isi pesan tidak tersimpan — tidak bisa dikirim ulang.', 'error');
        redirect('/admin/system-health');
    }

    $res = retryNotificationLog($log);

    if ($res['ok']) {
        logActivity('admin', 'notification.retry', 'notification_log', $id, 'Kirim ulang notifikasi #' . $id . ' → ' . $log['recipient'] . ' (sukses)');
        flash('success', 'Notifikasi berhasil dikirim ulang ke ' . $log['recipient'] . '.');
    } else {
        flash('error', 'Kirim ulang masih gagal: ' . $res['message'], 'error');
    }
} catch (Exception $e) {
    error_log('retry-notification error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat mengirim ulang.', 'error');
}

redirect('/admin/system-health');
