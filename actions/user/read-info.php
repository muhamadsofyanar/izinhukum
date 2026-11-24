<?php
// Tandai satu Info Member sudah dibaca oleh user login (dipanggil async dari lonceng).
require_once __DIR__ . '/../../bootstrap.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$annId = (int) ($_POST['announcement_id'] ?? 0);
$uid   = (int) $_SESSION['user_id'];
if ($annId > 0) {
    try {
        getDB()->prepare('INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)')
            ->execute([$annId, $uid]);
    } catch (Throwable $e) {
        error_log('read-info: ' . $e->getMessage());
    }
}
echo json_encode(['ok' => true]);
