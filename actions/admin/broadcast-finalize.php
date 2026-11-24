<?php
/**
 * Broadcast — tandai campaign yang nyangkut di status 'sending' menjadi selesai.
 * Dipakai bila stream SSE terputus. Totals dihitung ulang dari broadcast_recipients.
 * METHOD: POST (CSRF)
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('broadcast')) { flash('error', 'Tidak memiliki akses.', 'error'); redirect('/admin/broadcast'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/broadcast');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/broadcast');
}

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
if ($campaignId <= 0) {
    flash('error', 'ID campaign tidak valid.', 'error');
    redirect('/admin/broadcast');
}

try {
    $pdo = getDB();

    $cStmt = $pdo->prepare("SELECT id, name FROM broadcast_campaigns WHERE id = ? LIMIT 1");
    $cStmt->execute([$campaignId]);
    $campaign = $cStmt->fetch();
    if (!$campaign) {
        flash('error', 'Campaign tidak ditemukan.', 'error');
        redirect('/admin/broadcast');
    }

    // Sisa 'pending' dianggap gagal saat ditandai selesai manual.
    $pdo->prepare("UPDATE broadcast_recipients SET status = 'failed', error_message = 'Dibatalkan (ditandai selesai manual)' WHERE campaign_id = ? AND status = 'pending'")
        ->execute([$campaignId]);

    $counts = $pdo->prepare(
        "SELECT SUM(status='sent') AS sent, SUM(status='failed') AS failed FROM broadcast_recipients WHERE campaign_id = ?"
    );
    $counts->execute([$campaignId]);
    $c = $counts->fetch() ?: ['sent' => 0, 'failed' => 0];

    $pdo->prepare(
        "UPDATE broadcast_campaigns SET status = 'sent', sent_at = NOW(), total_sent = ?, total_failed = ? WHERE id = ?"
    )->execute([(int) $c['sent'], (int) $c['failed'], $campaignId]);

    if (function_exists('logActivity')) {
        logActivity('admin', 'broadcast.finalized', 'broadcast_campaigns', $campaignId,
            'Broadcast "' . $campaign['name'] . '" ditandai selesai manual.');
    }

    flash('success', 'Campaign ditandai selesai: ' . (int) $c['sent'] . ' terkirim, ' . (int) $c['failed'] . ' gagal.');
} catch (Exception $e) {
    error_log('broadcast-finalize error: ' . $e->getMessage());
    flash('error', 'Gagal menandai selesai.', 'error');
}

redirect('/admin/broadcast');
