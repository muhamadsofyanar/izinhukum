<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('integrations')) { flash('error', 'Tanpa akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/webhooks'); }
$id = (int) ($_POST['id'] ?? 0);
try {
    if ($id > 0) { getDB()->prepare("DELETE FROM outgoing_webhooks WHERE id=?")->execute([$id]); logActivity('admin','webhook.delete','outgoing_webhooks',$id,'Hapus webhook'); flash('success', 'Webhook dihapus.'); }
} catch (Throwable $e) { error_log('delete-webhook: '.$e->getMessage()); flash('error', 'Gagal menghapus.', 'error'); }
redirect('/admin/webhooks');
