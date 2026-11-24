<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('email_sequences')) {
    flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error');
    redirect('/admin/email-sequences');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Permintaan tidak valid. Coba lagi.', 'error');
    redirect('/admin/email-sequences');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Kiriman tidak valid.', 'error');
    redirect('/admin/email-sequences');
}

try {
    $pdo = getDB();
    // Hanya kiriman gagal/dilewati yang boleh dikirim ulang.
    $stmt = $pdo->prepare("SELECT id, enrollment_id FROM email_sequence_sends WHERE id = ? AND status IN ('failed','skipped') LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('error', 'Kiriman tidak ditemukan atau statusnya tidak dapat dikirim ulang.', 'error');
        redirect('/admin/email-sequences');
    }

    // Reset ke pending (due sekarang) supaya pipeline mengirim ulang.
    $pdo->prepare("UPDATE email_sequence_sends SET status='pending', scheduled_at=NOW(), sent_at=NULL, response=NULL WHERE id=?")
        ->execute([$id]);
    // Enrollment yang sudah 'completed' dikembalikan 'active' — pipeline pengiriman
    // mensyaratkan enrollment.status='active' (lihat processDueSequenceSends).
    $pdo->prepare("UPDATE email_sequence_enrollments SET status='active' WHERE id=? AND status='completed'")
        ->execute([(int) $row['enrollment_id']]);

    flash('success', 'Email dijadwalkan ulang & akan dikirim saat antrean diproses.');
} catch (Exception $e) {
    error_log('retry-sequence-send error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
}
redirect('/admin/email-sequences');
