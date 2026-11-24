<?php
// POST /actions/admin/pause-broadcast — jeda / lanjutkan pengiriman broadcast.
//   action=pause  : sending → paused (cron/pump berhenti memproses)
//   action=resume : paused  → sending (cron/pump lanjut dari sisa pending)
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('broadcast')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/broadcast');
}

$id     = (int) ($_POST['campaign_id'] ?? 0);
$action = ($_POST['action'] ?? 'pause') === 'resume' ? 'resume' : 'pause';
if ($id <= 0) { flash('error', 'Campaign tidak valid.', 'error'); redirect('/admin/broadcast'); }

try {
    $pdo = getDB();
    if ($action === 'pause') {
        // Hanya campaign yang sedang mengirim yang bisa dijeda.
        $n = $pdo->prepare("UPDATE broadcast_campaigns SET status='paused' WHERE id=? AND status='sending'");
        $n->execute([$id]);
        if ($n->rowCount() > 0) {
            logActivity('admin', 'broadcast.pause', 'broadcast_campaigns', $id, 'Broadcast dijeda.');
            flash('success', 'Broadcast dijeda. Pengiriman berhenti dan bisa dilanjutkan kapan saja.');
        } else {
            flash('error', 'Broadcast tidak dalam status mengirim.', 'error');
        }
    } else {
        $n = $pdo->prepare("UPDATE broadcast_campaigns SET status='sending' WHERE id=? AND status='paused'");
        $n->execute([$id]);
        if ($n->rowCount() > 0) {
            logActivity('admin', 'broadcast.resume', 'broadcast_campaigns', $id, 'Broadcast dilanjutkan.');
            flash('success', 'Broadcast dilanjutkan. Sisa penerima akan dikirim.');
        } else {
            flash('error', 'Broadcast tidak dalam status dijeda.', 'error');
        }
    }
} catch (Throwable $e) {
    error_log('pause-broadcast error: ' . $e->getMessage());
    flash('error', 'Gagal mengubah status broadcast.', 'error');
}

redirect('/admin/broadcast');
