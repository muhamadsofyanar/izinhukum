<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/email-sequences'); }

$result = processDueSequenceSends(50);
logActivity('admin', 'email_sequence.manual_process', 'email_sequence', null,
    "Manual process: {$result['processed']} diproses, {$result['sent']} terkirim, {$result['failed']} gagal");
flash('success', "Selesai: {$result['sent']} email terkirim, {$result['failed']} gagal.");
redirect('/admin/email-sequences');
