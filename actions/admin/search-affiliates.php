<?php
// GET /actions/admin/search-affiliates?q=... — pencarian AFFILIATE untuk combobox
// "Assign komisi ke affiliate" di Input Transaksi Manual. Read-only, JSON maks 20.
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json; charset=utf-8');

if (!staffCan('transactions')) {
    http_response_code(403);
    echo json_encode(['affiliates' => [], 'error' => 'forbidden']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));

try {
    $pdo = getDB();
    $base = "SELECT a.id, a.code, u.name, u.email
             FROM affiliates a JOIN users u ON u.id = a.user_id
             WHERE a.status = 'active' AND u.status = 'active'";

    if ($q === '') {
        $stmt = $pdo->query($base . " ORDER BY a.id DESC LIMIT 20");
    } else {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            $base . " AND (u.name LIKE ? OR u.email LIKE ? OR a.code LIKE ?)
                      ORDER BY u.name ASC LIMIT 20"
        );
        $stmt->execute([$like, $like, $like]);
    }

    $affiliates = array_map(fn($a) => [
        'id'    => (int) $a['id'],
        'code'  => (string) $a['code'],
        'name'  => (string) $a['name'],
        'email' => (string) $a['email'],
    ], $stmt->fetchAll());

    echo json_encode(['affiliates' => $affiliates], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('search-affiliates error: ' . $e->getMessage());
    echo json_encode(['affiliates' => []]);
}
exit;
