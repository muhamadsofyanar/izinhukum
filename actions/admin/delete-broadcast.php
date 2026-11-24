<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

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

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, name, status FROM broadcast_campaigns WHERE id = ? LIMIT 1");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) {
    flash('error', 'Campaign tidak ditemukan.', 'error');
    redirect('/admin/broadcast');
}

try {
    // broadcast_recipients terhapus otomatis via ON DELETE CASCADE
    $pdo->prepare("DELETE FROM broadcast_campaigns WHERE id = ?")->execute([$campaignId]);

    if (function_exists('logActivity')) {
        logActivity('admin', 'broadcast.deleted', 'broadcast_campaigns', $campaignId,
            'Campaign dihapus: ' . $campaign['name']);
    }

    flash('success', 'Campaign "' . $campaign['name'] . '" berhasil dihapus.');
} catch (Exception $e) {
    error_log('Delete broadcast error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus campaign.', 'error');
}

redirect('/admin/broadcast');
