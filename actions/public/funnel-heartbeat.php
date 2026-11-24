<?php
// Heartbeat presence funnel — dipanggil tiap ~20 dtk dari halaman sales/checkout.
// Publik. Session dibaca dari cookie (session_id()), bukan dari request.
// Balas 204 tanpa body. Fail-soft: error tak boleh mengganggu pengunjung.
require_once __DIR__ . '/../../bootstrap.php';

http_response_code(204);

// Admin/staff bukan "visitor" — konsisten dengan pencatatan funnel lain.
if (function_exists('isAdminOrStaff') && isAdminOrStaff()) { exit; }

$sid = session_id();
if ($sid === '') { exit; }

$owner = $_GET['owner'] ?? $_POST['owner'] ?? '';
$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stage = ($_GET['stage'] ?? $_POST['stage'] ?? '') === 'checkout' ? 'checkout' : 'salespage';
if ($id <= 0 || !in_array($owner, ['product', 'bundle'], true)) { exit; }

$productId = $owner === 'product' ? $id : 0;
$bundleId  = $owner === 'bundle'  ? $id : 0;

try {
    $pdo = getDB();
    $pdo->prepare(
        "INSERT INTO funnel_presence (session_id, product_id, bundle_id, stage, last_seen)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE stage = VALUES(stage), last_seen = NOW()"
    )->execute([$sid, $productId, $bundleId, $stage]);

    // Prune sesekali (≈2%): buang presence basi >1 jam agar tabel ramping.
    if (random_int(1, 50) === 1) {
        $pdo->exec("DELETE FROM funnel_presence WHERE last_seen < (NOW() - INTERVAL 1 HOUR)");
    }
} catch (Throwable $e) {
    error_log('funnel-heartbeat: ' . $e->getMessage());
}
exit;
