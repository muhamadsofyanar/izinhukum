<?php
// GET /actions/admin/search-users?q=... — pencarian member untuk combobox
// Input Transaksi Manual (server-side; daftar inline lama mentok di 2000 user).
// Read-only + requireAdminOrStaff, respons JSON maks 20 baris.
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json; charset=utf-8');

if (!staffCan('transactions')) {
    http_response_code(403);
    echo json_encode(['users' => [], 'error' => 'forbidden']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));

try {
    $pdo = getDB();

    if ($q === '') {
        // Tanpa kata kunci: tampilkan member terbaru (UX saat field baru difokus).
        $stmt = $pdo->query(
            "SELECT id, name, email, phone FROM users
             WHERE status = 'active' ORDER BY id DESC LIMIT 20"
        );
    } else {
        $like   = '%' . $q . '%';
        $conds  = ['name LIKE ?', 'email LIKE ?'];
        $params = [$like, $like];

        // Pencarian nomor WA toleran format (08xx / 628xx / 8xx): buang prefix
        // 62/0 dari query lalu cocokkan substring — data lama bisa tersimpan
        // dgn format apa pun.
        $digits = preg_replace('/\D/', '', $q);
        if (strlen($digits) >= 4) {
            $core = $digits;
            if (str_starts_with($core, '62'))    $core = substr($core, 2);
            elseif (str_starts_with($core, '0')) $core = substr($core, 1);
            if ($core !== '') {
                $conds[]  = 'phone LIKE ?';
                $params[] = '%' . $core . '%';
            }
        }

        $stmt = $pdo->prepare(
            "SELECT id, name, email, phone FROM users
             WHERE status = 'active' AND (" . implode(' OR ', $conds) . ")
             ORDER BY name ASC LIMIT 20"
        );
        $stmt->execute($params);
    }

    $users = array_map(fn($u) => [
        'id'    => (int) $u['id'],
        'name'  => (string) $u['name'],
        'email' => (string) $u['email'],
        'phone' => (string) ($u['phone'] ?? ''),
    ], $stmt->fetchAll());

    echo json_encode(['users' => $users], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('search-users error: ' . $e->getMessage());
    echo json_encode(['users' => []]);
}
exit;
