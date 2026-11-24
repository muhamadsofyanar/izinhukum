<?php
// Pencarian user untuk picker target "User tertentu" di Info Member (JSON, admin only).
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

try {
    $st = getDB()->prepare(
        "SELECT id, name, email FROM users
         WHERE (name LIKE ? OR email LIKE ?) AND status = 'active'
         ORDER BY name LIMIT 10"
    );
    $like = '%' . $q . '%';
    $st->execute([$like, $like]);
    $out = array_map(fn($r) => [
        'id'    => (int) $r['id'],
        'name'  => (string) $r['name'],
        'email' => (string) $r['email'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('info-user-search: ' . $e->getMessage());
    echo json_encode([]);
}
