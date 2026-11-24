<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/commission-rules');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/commission-rules');
}

$ruleId = (int) ($_POST['rule_id'] ?? 0);

if ($ruleId <= 0) {
    flash('error', 'Rule tidak valid.', 'error');
    redirect('/admin/commission-rules');
}

try {
    $stmt = getDB()->prepare("DELETE FROM commission_rules WHERE id = ?");
    $stmt->execute([$ruleId]);

    if ($stmt->rowCount() > 0) {
        logActivity('admin', 'commission_rule.delete', 'commission_rule', $ruleId, 'Rule komisi dihapus');
        flash('success', 'Rule komisi berhasil dihapus.');
    } else {
        flash('error', 'Rule tidak ditemukan.', 'error');
    }

} catch (Exception $e) {
    error_log('Delete commission rule error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus rule komisi.', 'error');
}

redirect('/admin/commission-rules');
