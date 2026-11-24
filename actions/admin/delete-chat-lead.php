<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/chat-leads');
}

$id = (int) ($_POST['id'] ?? 0);
try {
    getDB()->prepare('DELETE FROM chat_leads WHERE id = ?')->execute([$id]);
    flash('success', 'Lead dihapus.');
} catch (Exception $e) {
    error_log('delete-chat-lead: ' . $e->getMessage());
    flash('error', 'Gagal menghapus lead.', 'error');
}
redirect('/admin/chat-leads');
