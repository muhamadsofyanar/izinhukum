<?php
// Funnel Gate — Autosave draft (dipanggil fetch/sendBeacon dari form publik).
// Upsert satu baris submission status='draft' per (form_id, session_token).
// Kunci desain: kontak disimpan lebih dulu → draft yang kabur tetap bisa di-follow-up.
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$formId = (int) ($_POST['form_id'] ?? 0);
if ($formId <= 0) { echo json_encode(['ok' => false]); exit; }

$pdo = getDB();
try {
    $st = $pdo->prepare("SELECT id FROM funnel_gate_forms WHERE id = ? AND status = 'published' LIMIT 1");
    $st->execute([$formId]);
    if (!$st->fetch()) { echo json_encode(['ok' => false]); exit; }
} catch (Exception $e) {
    error_log('FG autosave form check: ' . $e->getMessage());
    echo json_encode(['ok' => false]); exit;
}

// Session token (dibuat di halaman; buat bila belum ada).
if (!isset($_SESSION['fg_token']) || !is_array($_SESSION['fg_token'])) $_SESSION['fg_token'] = [];
if (empty($_SESSION['fg_token'][$formId])) $_SESSION['fg_token'][$formId] = bin2hex(random_bytes(16));
$token = $_SESSION['fg_token'][$formId];

// Throttle server: min ~1 dtk antar tulis per form.
$now = microtime(true);
if (!isset($_SESSION['fg_autosave_last']) || !is_array($_SESSION['fg_autosave_last'])) $_SESSION['fg_autosave_last'] = [];
$last = (float) ($_SESSION['fg_autosave_last'][$formId] ?? 0);
if ($now - $last < 1.0) { echo json_encode(['ok' => true, 'skip' => 1]); exit; }
$_SESSION['fg_autosave_last'][$formId] = $now;

// Kontak.
$name  = trim((string) ($_POST['contact_name'] ?? ''));
$wa    = lead_normalize_wa(trim((string) ($_POST['contact_whatsapp'] ?? '')));
$email = trim((string) ($_POST['contact_email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
if ($name === '' && $wa === '') { echo json_encode(['ok' => true, 'empty' => 1]); exit; }

// Answers: hanya key pertanyaan valid milik form.
$validKeys = [];
try {
    $kq = $pdo->prepare("SELECT field_key FROM funnel_gate_fields WHERE form_id = ? AND field_role = 'none'");
    $kq->execute([$formId]);
    $validKeys = $kq->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $validKeys = []; }

$answersRaw = $_POST['answers'] ?? [];
$answers = [];
if (is_array($answersRaw)) {
    foreach ($answersRaw as $k => $v) {
        if (!in_array($k, $validKeys, true)) continue;
        if (is_array($v)) {
            $answers[$k] = array_values(array_map(fn($x) => substr((string) $x, 0, 500), $v));
        } else {
            $answers[$k] = substr((string) $v, 0, 2000);
        }
    }
}
$answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE);
$ip = client_real_ip();

try {
    $sel = $pdo->prepare('SELECT id, status FROM funnel_gate_submissions WHERE form_id = ? AND session_token = ? LIMIT 1');
    $sel->execute([$formId, $token]);
    $row = $sel->fetch();

    if ($row) {
        // Jangan timpa baris yang sudah dikirim.
        if ($row['status'] !== 'draft') { echo json_encode(['ok' => true, 'locked' => 1]); exit; }
        $pdo->prepare(
            'UPDATE funnel_gate_submissions
             SET name = ?, whatsapp = ?, email = ?, answers = ?, ip_address = ?
             WHERE id = ?'
        )->execute([($name ?: null), ($wa ?: null), ($email ?: null), $answersJson, $ip, (int) $row['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO funnel_gate_submissions
               (form_id, session_token, name, whatsapp, email, answers, status, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)"
        )->execute([$formId, $token, ($name ?: null), ($wa ?: null), ($email ?: null), $answersJson, $ip]);
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('FG autosave write: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
