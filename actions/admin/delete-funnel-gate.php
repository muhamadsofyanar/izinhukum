<?php
// Hapus Funnel Gate (form + fields + submissions via FK CASCADE).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/funnel-gate');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/funnel-gate');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Form tidak valid.', 'error');
    redirect('/admin/funnel-gate');
}

try {
    $pdo = getDB();
    $st  = $pdo->prepare('SELECT title FROM funnel_gate_forms WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $title = $st->fetchColumn();
    if ($title === false) {
        flash('error', 'Form tidak ditemukan.', 'error');
        redirect('/admin/funnel-gate');
    }
    $pdo->prepare('DELETE FROM funnel_gate_forms WHERE id = ?')->execute([$id]);
    if (function_exists('logActivity')) {
        logActivity('admin', 'funnel_gate.deleted', 'funnel_gate_forms', $id, 'Funnel Gate "' . $title . '" dihapus.');
    }
    flash('success', 'Funnel Gate berhasil dihapus.');
} catch (Exception $e) {
    error_log('Delete funnel gate error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus form.', 'error');
}
redirect('/admin/funnel-gate');
