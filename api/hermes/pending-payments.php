<?php
// Hermes Agent Fase 3: baca kandidat dan jadwalkan satu follow-up per order.
require_once __DIR__ . '/../../bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    hermesApiSend(405, ['ok' => false, 'error' => 'Metode tidak diizinkan. Gunakan GET atau POST.']);
}
if ($method === 'POST' && !str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    hermesApiSend(415, ['ok' => false, 'error' => 'Content-Type harus application/json.']);
}
hermesApiAuthorize(true);
$pdo = getDB();
$delayHours = max(1, (int) getSetting('abandoned_cart_delay_hours', '1'));

if ($method === 'GET') {
    $lookup = trim((string) ($_GET['request_id'] ?? ''));
    if ($lookup !== '') {
        if (strlen($lookup) > 191 || !preg_match('/^[A-Za-z0-9:_-]+$/', $lookup)) {
            hermesApiSend(422, ['ok' => false, 'error' => 'request_id tidak valid.']);
        }
        $job = hermesFollowupFindJob($pdo, $lookup);
        if (!$job) hermesApiSend(404, ['ok' => false, 'error' => 'Pekerjaan follow-up tidak ditemukan.']);
        hermesApiSend(200, ['ok' => true, 'job' => $job]);
    }
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    $perPage = filter_var($_GET['per_page'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($page === false || $perPage === false) hermesApiSend(422, ['ok' => false, 'error' => 'page atau per_page tidak valid.']);
    $st = $pdo->prepare(
        'SELECT o.order_number, o.status, o.created_at, u.email, u.phone,
                (SELECT oi.product_name FROM order_items oi WHERE oi.order_id=o.id AND oi.is_bump=0 ORDER BY oi.id LIMIT 1) AS product_name
         FROM orders o JOIN users u ON u.id=o.user_id
         WHERE ' . abandonedCartCandidateWhere() . "
           AND u.email_opt_out=0 AND u.email<>''
           AND NOT EXISTS (SELECT 1 FROM hermes_followup_jobs hj WHERE hj.order_id=o.id)
           AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.is_bump=0)
         ORDER BY o.created_at ASC, o.id ASC LIMIT ? OFFSET ?"
    );
    $st->bindValue(1, $delayHours, PDO::PARAM_INT);
    $st->bindValue(2, (int) $perPage, PDO::PARAM_INT);
    $st->bindValue(3, ((int) $page - 1) * (int) $perPage, PDO::PARAM_INT);
    $st->execute();
    $rows = array_map(static function (array $r): array {
        return ['order_number' => $r['order_number'], 'status' => $r['status'],
                'age_hours' => max(0, (int) floor((time() - strtotime($r['created_at'])) / 3600)),
                'product_name' => $r['product_name'],
                'email_masked' => hermesFollowupMaskEmail((string) $r['email']),
                'phone_masked' => hermesFollowupMaskPhone((string) ($r['phone'] ?? ''))];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    hermesApiSend(200, ['ok' => true, 'candidates' => $rows, 'page' => (int) $page,
                         'per_page' => (int) $perPage, 'has_more' => count($rows) === (int) $perPage,
                         'sending_enabled' => hermesFollowupSendingEnabled()]);
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) hermesApiSend(400, ['ok' => false, 'error' => 'Body JSON tidak valid.']);
$unknown = array_diff(array_keys($in), ['order_number', 'request_id', 'dry_run', 'confirm_hash']);
if ($unknown) hermesApiSend(400, ['ok' => false, 'error' => 'Field tidak diizinkan: ' . implode(', ', $unknown)]);
$number = trim((string) ($in['order_number'] ?? ''));
$requestId = trim((string) ($in['request_id'] ?? ''));
if ($number === '' || strlen($number) > 50 || !preg_match('/^[A-Za-z0-9_-]+$/', $number)) {
    hermesApiSend(422, ['ok' => false, 'error' => 'order_number tidak valid.']);
}
if ($requestId === '' || strlen($requestId) > 191 || !preg_match('/^[A-Za-z0-9:_-]+$/', $requestId)) {
    hermesApiSend(422, ['ok' => false, 'error' => 'request_id wajib, maksimal 191 karakter, dan hanya boleh huruf, angka, :, _ atau -.']);
}
if (isset($in['dry_run']) && !is_bool($in['dry_run'])) {
    hermesApiSend(422, ['ok' => false, 'error' => 'dry_run wajib boolean.']);
}
$dry = $in['dry_run'] ?? false;
if (!$dry) {
    $existing = hermesFollowupFindJob($pdo, $requestId);
    if ($existing) {
        if ($existing['order_number'] !== $number) hermesApiSend(409, ['ok' => false, 'error' => 'request_id sudah dipakai untuk order lain.']);
        hermesApiSend(200, ['ok' => true, 'reused' => true, 'job' => $existing]);
    }
}

try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id,status,recovery_sent_at FROM orders WHERE order_number=? LIMIT 1 FOR UPDATE');
    $lock->execute([$number]);
    $order = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$order) { $pdo->rollBack(); hermesApiSend(404, ['ok' => false, 'error' => 'Order tidak ditemukan.']); }
    $candidate = $pdo->prepare(
        'SELECT o.id, o.order_number, o.created_at, u.email, u.phone,
                (SELECT oi.product_name FROM order_items oi WHERE oi.order_id=o.id AND oi.is_bump=0 ORDER BY oi.id LIMIT 1) AS product_name
         FROM orders o JOIN users u ON u.id=o.user_id
         WHERE ' . abandonedCartCandidateWhere() . " AND o.id=? AND u.email_opt_out=0 AND u.email<>''
           AND NOT EXISTS (SELECT 1 FROM hermes_followup_jobs hj WHERE hj.order_id=o.id)
           AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id AND oi.is_bump=0) LIMIT 1"
    );
    $candidate->execute([$delayHours, (int) $order['id']]);
    $row = $candidate->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); hermesApiSend(409, ['ok' => false, 'error' => 'Order tidak layak untuk follow-up atau sudah pernah dipulihkan.']); }
    $confirmHash = hash('sha256', json_encode([$number, $requestId, (int) $row['id'], $row['created_at']], JSON_UNESCAPED_SLASHES));
    if (isset($in['confirm_hash']) && !hash_equals($confirmHash, (string) $in['confirm_hash'])) {
        $pdo->rollBack();
        hermesApiSend(409, ['ok' => false, 'error' => 'confirm_hash tidak cocok. Jalankan dry_run lagi.', 'confirm_hash' => $confirmHash]);
    }
    if ($dry) {
        $pdo->rollBack();
        hermesApiSend(200, ['ok' => true, 'dry_run' => true, 'order_number' => $number,
                             'product_name' => $row['product_name'], 'email_masked' => hermesFollowupMaskEmail($row['email']),
                             'status' => 'followup_eligible', 'sending_enabled' => hermesFollowupSendingEnabled(),
                             'confirm_hash' => $confirmHash]);
    }
    if (!hermesFollowupSendingEnabled()) {
        $pdo->rollBack();
        hermesApiSend(409, ['ok' => false, 'error' => 'Aktifkan pemulihan abandoned-cart lewat email dan lengkapi kredensial Mailketing sebelum menjadwalkan.']);
    }
    $template = $pdo->query("SELECT 1 FROM notification_templates WHERE event_key='abandoned_cart' AND channel='email' AND is_active=1 LIMIT 1")->fetchColumn();
    if (!$template) {
        $pdo->rollBack();
        hermesApiSend(409, ['ok' => false, 'error' => 'Template email abandoned_cart belum aktif.']);
    }
    $pdo->prepare("INSERT INTO hermes_followup_jobs (order_id,request_id,status) VALUES (?,?,'queued')")
        ->execute([(int) $row['id'], $requestId]);
    $pdo->commit();
    hermesLog('follow-up dijadwalkan order=' . $number);
    hermesApiSend(202, ['ok' => true, 'reused' => false, 'order_number' => $number,
                         'request_id' => $requestId, 'status' => 'queued', 'confirm_hash' => $confirmHash]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') {
        $same = hermesFollowupFindJob($pdo, $requestId);
        if ($same && $same['order_number'] === $number) hermesApiSend(200, ['ok' => true, 'reused' => true, 'job' => $same]);
        hermesApiSend(409, ['ok' => false, 'error' => 'Order atau request_id sudah punya pekerjaan follow-up.']);
    }
    error_log('Hermes follow-up enqueue: ' . $e->getMessage());
    hermesApiSend(500, ['ok' => false, 'error' => 'Gagal menjadwalkan follow-up.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Hermes follow-up enqueue: ' . $e->getMessage());
    hermesApiSend(500, ['ok' => false, 'error' => 'Gagal menjadwalkan follow-up.']);
}
