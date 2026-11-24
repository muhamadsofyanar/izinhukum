<?php
// Serve lampiran tiket dengan guard akses (pemilik tiket ATAU admin/staff).
// File disimpan di storage/ (deny-all HTTP) → hanya lewat handler ini.
require_once __DIR__ . '/../../bootstrap.php';

if (!isLoggedIn()) { http_response_code(403); exit('Silakan login.'); }

$replyId = (int) ($_GET['r'] ?? 0);
if ($replyId <= 0) { http_response_code(404); exit('Tidak ditemukan.'); }

try {
    $pdo = getDB();
    $st  = $pdo->prepare(
        "SELECT r.attachment_path, t.user_id
         FROM support_ticket_replies r
         JOIN support_tickets t ON t.id = r.ticket_id
         WHERE r.id = ? LIMIT 1"
    );
    $st->execute([$replyId]);
    $row = $st->fetch();
} catch (Throwable $e) {
    error_log('ticket-file: ' . $e->getMessage());
    http_response_code(500); exit('Terjadi kesalahan.');
}

if (!$row || empty($row['attachment_path'])) { http_response_code(404); exit('Lampiran tidak ditemukan.'); }

// Guard: pemilik tiket atau admin/staff.
if (!isAdminOrStaff() && (int) $row['user_id'] !== (int) $_SESSION['user_id']) {
    http_response_code(403); exit('Kamu tidak memiliki akses ke lampiran ini.');
}

// Cegah path traversal: hanya izinkan di dalam folder lampiran tiket.
$rel  = ltrim(str_replace('\\', '/', (string) $row['attachment_path']), '/');
if (strpos($rel, 'storage/ticket-attachments/') !== 0 || strpos($rel, '..') !== false) {
    http_response_code(400); exit('Path tidak valid.');
}
$full = realpath(__DIR__ . '/../../' . $rel);
$baseDir = realpath(TICKET_ATTACH_DIR);
if ($full === false || $baseDir === false || strpos($full, $baseDir) !== 0 || !is_file($full)) {
    http_response_code(404); exit('Berkas tidak ada.');
}

$ext   = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
$mime  = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: inline; filename="lampiran-tiket.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');
readfile($full);
exit;
