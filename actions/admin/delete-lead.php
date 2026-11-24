<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/lead-magnets?tab=leads');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/lead-magnets?tab=leads');
}

$leadId = (int) ($_POST['lead_id'] ?? 0);
if ($leadId <= 0) {
    flash('error', 'ID lead tidak valid.', 'error');
    redirect('/admin/lead-magnets?tab=leads');
}

try {
    $pdo = getDB();
    // Sinkronkan counter total_leads pada magnet induk.
    $stmt = $pdo->prepare('SELECT lead_magnet_id FROM leads WHERE id = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $magnetId = (int) ($stmt->fetchColumn() ?: 0);

    $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$leadId]);

    if ($magnetId > 0) {
        $pdo->prepare(
            'UPDATE lead_magnets
             SET total_leads = (SELECT COUNT(*) FROM leads WHERE lead_magnet_id = ?)
             WHERE id = ?'
        )->execute([$magnetId, $magnetId]);
    }
    flash('success', 'Lead berhasil dihapus.');
} catch (Exception $e) {
    error_log('Delete lead error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus lead.', 'error');
}

redirect('/admin/lead-magnets?tab=leads');
