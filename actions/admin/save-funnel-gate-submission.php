<?php
// Ubah status + catatan internal satu submission Funnel Gate.
// Bonus tie-in: saat status jadi 'approved' & form punya mailketing_list_id,
// enqueue subscriber ke list Mailketing (reuse antrean mailketing_queue).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$backForm = (int) ($_POST['form_id'] ?? 0);
$back = '/admin/funnel-gate/submissions' . ($backForm ? '?form=' . $backForm : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($back);
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect($back);
}

$id     = (int) ($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$notes  = trim((string) ($_POST['internal_notes'] ?? ''));
if ($id <= 0 || !in_array($status, ['submitted', 'approved', 'rejected', 'pending'], true)) {
    flash('error', 'Data tidak valid.', 'error');
    redirect($back);
}

$pdo = getDB();
try {
    // Muat submission + form (untuk list mailketing & guard).
    $st = $pdo->prepare(
        'SELECT s.*, f.mailketing_list_id
         FROM funnel_gate_submissions s
         JOIN funnel_gate_forms f ON f.id = s.form_id
         WHERE s.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $sub = $st->fetch();
    if (!$sub) { flash('error', 'Submission tidak ditemukan.', 'error'); redirect($back); }

    $wasApproved = ($sub['status'] === 'approved');

    $pdo->prepare('UPDATE funnel_gate_submissions SET status = ?, internal_notes = ? WHERE id = ?')
        ->execute([$status, ($notes !== '' ? $notes : null), $id]);

    if (function_exists('logActivity')) {
        logActivity('admin', 'funnel_gate.submission_status', 'funnel_gate_submissions', $id,
            'Status submission → ' . $status . '.');
    }

    // Bonus: baru pertama kali approved → masuk list Mailketing (bila dikonfigurasi).
    // Enqueue langsung ke mailketing_queue (prosesor auto-cron pakai list_id per baris).
    $listId = trim((string) ($sub['mailketing_list_id'] ?? ''));
    $email  = trim((string) ($sub['email'] ?? ''));
    if ($status === 'approved' && !$wasApproved && $listId !== ''
        && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            // Dedup: jangan enqueue ulang bila email ini sudah pernah masuk antrean
            // list yg sama (mis. admin un-approve lalu approve lagi). Baris 'sent'
            // tetap tersimpan (di-purge 7 hari), jadi cukup cek keberadaan.
            $dup = $pdo->prepare(
                "SELECT 1 FROM mailketing_queue
                 WHERE event = 'funnel_gate' AND list_id = ? AND email = ? LIMIT 1"
            );
            $dup->execute([substr($listId, 0, 40), substr($email, 0, 255)]);
            if (!$dup->fetchColumn()) {
                $parts = explode(' ', trim(preg_replace('/\s+/', ' ', (string) $sub['name'])));
                $first = array_shift($parts);
                $last  = $parts ? implode(' ', $parts) : '';
                $mobile = trim((string) ($sub['whatsapp'] ?? ''));
                $pdo->prepare(
                    "INSERT INTO mailketing_queue (event, list_id, email, first_name, last_name, mobile)
                     VALUES ('funnel_gate', ?, ?, ?, ?, ?)"
                )->execute([
                    substr($listId, 0, 40), substr($email, 0, 255),
                    ($first !== '' ? substr($first, 0, 120) : null),
                    ($last !== '' ? substr($last, 0, 120) : null),
                    ($mobile !== '' ? substr($mobile, 0, 40) : null),
                ]);
            }
        } catch (Throwable $e) {
            error_log('FG mailketing enqueue: ' . $e->getMessage());
        }
    }

    flash('success', 'Status submission diperbarui.');
} catch (Exception $e) {
    error_log('FG submission update: ' . $e->getMessage());
    flash('error', 'Gagal memperbarui submission.', 'error');
}
redirect($back);
