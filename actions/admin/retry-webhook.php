<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('integrations')) { flash('error', 'Tanpa akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/webhooks'); }
$id = (int) ($_POST['id'] ?? 0);
try {
    getDB()->prepare("UPDATE webhook_deliveries SET status='pending', attempts=0, next_attempt_at=NOW() WHERE id=? AND status='failed'")->execute([$id]);
    $r = processWebhookQueue(5);
    flash('success', 'Kirim ulang diproses: '.(int)($r['sent']??0).' terkirim.');
} catch (Throwable $e) { error_log('retry-webhook: '.$e->getMessage()); flash('error', 'Gagal kirim ulang.', 'error'); }
redirect('/admin/webhooks');
