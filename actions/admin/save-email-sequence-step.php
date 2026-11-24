<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/email-sequences'); }

$id         = (int) ($_POST['id'] ?? 0);
$sequenceId = (int) ($_POST['sequence_id'] ?? 0);
$delayDays  = max(0, (int) ($_POST['delay_days'] ?? 0));
$subject    = trim($_POST['subject'] ?? '');
$body       = trim($_POST['body'] ?? '');
$status     = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active';
$bodyFormat = ($_POST['body_format'] ?? '') === 'html' ? 'html' : 'text';

$pdo = getDB();
ensureEmailStepBodyFormatColumn($pdo);
$chk = $pdo->prepare("SELECT id FROM email_sequences WHERE id = ?"); $chk->execute([$sequenceId]);
if (!$chk->fetchColumn()) { flash('error', 'Sequence tidak valid.', 'error'); redirect('/admin/email-sequences'); }
if (strlen($subject) < 3) { flash('error', 'Subject minimal 3 karakter.', 'error'); redirect('/admin/email-sequence-steps?id=' . $sequenceId); }
if (strlen($body) < 10)   { flash('error', 'Body minimal 10 karakter.', 'error'); redirect('/admin/email-sequence-steps?id=' . $sequenceId); }

if ($id) {
    $pdo->prepare("UPDATE email_sequence_steps SET delay_days=?, subject=?, body=?, body_format=?, status=? WHERE id=? AND sequence_id=?")
        ->execute([$delayDays, $subject, $body, $bodyFormat, $status, $id, $sequenceId]);
} else {
    $stepNo = (int) $pdo->query("SELECT COALESCE(MAX(step_number),0)+1 FROM email_sequence_steps WHERE sequence_id=" . $sequenceId)->fetchColumn();
    $pdo->prepare("INSERT INTO email_sequence_steps (sequence_id, step_number, delay_days, subject, body, body_format, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$sequenceId, $stepNo, $delayDays, $subject, $body, $bodyFormat, $status]);
}
flash('success', 'Step berhasil disimpan.');
redirect('/admin/email-sequence-steps?id=' . $sequenceId);
