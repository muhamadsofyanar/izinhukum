<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) { flash('error', 'Kamu tidak memiliki akses.', 'error'); redirect('/admin'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid.', 'error');
    redirect('/admin/email-sequences');
}

$id         = (int) ($_POST['id'] ?? 0);
$sequenceId = (int) ($_POST['sequence_id'] ?? 0);

if (!$id || !$sequenceId) {
    flash('error', 'Step tidak valid.', 'error');
    redirect('/admin/email-sequence-steps?id=' . $sequenceId);
}

try {
    $pdo = getDB();
    // Hapus step. Antrian terjadwal (email_sequence_sends) yang masih pending
    // untuk step ini ikut dihapus agar tidak ada kiriman yatim.
    try {
        $pdo->prepare("DELETE FROM email_sequence_sends WHERE step_id = ? AND status = 'pending'")->execute([$id]);
    } catch (Exception $e) {
        // abaikan bila tabel/relasi tidak ada — lanjut hapus step
    }
    $del = $pdo->prepare("DELETE FROM email_sequence_steps WHERE id = ? AND sequence_id = ?");
    $del->execute([$id, $sequenceId]);

    if ($del->rowCount() > 0) {
        if (function_exists('logActivity')) {
            logActivity('admin', 'email_sequence_step.deleted', 'email_sequence_steps', $id,
                'Step email sequence dihapus (sequence #' . $sequenceId . ').');
        }
        flash('success', 'Step berhasil dihapus.');
    } else {
        flash('error', 'Step tidak ditemukan.', 'error');
    }
} catch (Exception $e) {
    error_log('delete-email-sequence-step error: ' . $e->getMessage());
    flash('error', 'Gagal menghapus step.', 'error');
}

redirect('/admin/email-sequence-steps?id=' . $sequenceId);
