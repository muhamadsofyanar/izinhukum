<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('abandoned_carts')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/abandoned-carts'); }

$r = processAbandonedCarts(50);
logActivity('admin', 'abandoned_cart.manual_process', 'order', null, "Manual: {$r['processed']} diproses, {$r['sent']} terkirim, {$r['failed']} gagal, {$r['skipped']} skip");
if ($r['processed'] === 0) {
    flash('info', 'Tidak ada abandoned cart yang perlu diproses.', 'info');
} else {
    flash('success', "Selesai: {$r['sent']} reminder terkirim" . ($r['failed'] > 0 ? ", {$r['failed']} gagal" : '') . ($r['skipped'] > 0 ? ", {$r['skipped']} dilewati" : '') . '.');
}
redirect('/admin/abandoned-carts');
