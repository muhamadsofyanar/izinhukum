<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/email-sequences');
}

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    flash('error', 'Sequence tidak valid.', 'error');
    redirect('/admin/email-sequences');
}

try {
    $pdo = getDB();

    $chk = $pdo->prepare("SELECT name FROM email_sequences WHERE id = ? LIMIT 1");
    $chk->execute([$id]);
    $name = $chk->fetchColumn();
    if ($name === false) {
        flash('error', 'Sequence tidak ditemukan.', 'error');
        redirect('/admin/email-sequences');
    }

    // Steps, enrollments, dan sends ikut terhapus otomatis via FK ON DELETE CASCADE.
    $pdo->prepare("DELETE FROM email_sequences WHERE id = ?")->execute([$id]);

    if (function_exists('logActivity')) {
        logActivity('admin', 'email_sequence.deleted', 'email_sequences', $id,
            'Email sequence dihapus: ' . $name);
    }

    flash('success', 'Sequence "' . $name . '" berhasil dihapus.');
} catch (Exception $e) {
    error_log('delete-email-sequence error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus sequence.', 'error');
}

redirect('/admin/email-sequences');
