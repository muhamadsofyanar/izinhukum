<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) { flash('error', 'Permintaan tidak valid.', 'error'); redirect('/admin/email-sequences'); }

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare("SELECT status FROM email_sequences WHERE id = ?"); $stmt->execute([$id]);
$cur = $stmt->fetchColumn();
if ($cur === false) { flash('error', 'Sequence tidak ditemukan.', 'error'); redirect('/admin/email-sequences'); }

$new = $cur === 'active' ? 'inactive' : 'active';
$pdo->prepare("UPDATE email_sequences SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$new, $id]);
logActivity('admin', 'email_sequence.toggle', 'email_sequence', $id, "Sequence ID $id → $new");
flash('success', 'Status sequence diperbarui.');
redirect('/admin/email-sequences');
