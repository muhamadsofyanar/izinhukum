<?php
// Funnel Gate — Submit final. Validasi wajib → hitung skor → simpan (upsert draft)
// → resolusi redirect (by skor) → balas JSON {ok, redirect}.
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function fg_fail(string $msg, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fg_fail('Metode tidak diizinkan.', 405);
if (!validateCSRF($_POST['csrf_token'] ?? '')) fg_fail('Sesi kedaluwarsa. Muat ulang halaman.', 403);

$formId = (int) ($_POST['form_id'] ?? 0);
if ($formId <= 0) fg_fail('Form tidak valid.');

$pdo = getDB();

// ─── Muat form + fields ──────────────────────────────────────
try {
    $st = $pdo->prepare("SELECT * FROM funnel_gate_forms WHERE id = ? AND status = 'published' LIMIT 1");
    $st->execute([$formId]);
    $form = $st->fetch();
    if (!$form) fg_fail('Form ini sedang tidak tersedia.');
    $fs = $pdo->prepare('SELECT * FROM funnel_gate_fields WHERE form_id = ? ORDER BY sort_order ASC');
    $fs->execute([$formId]);
    $fields = $fs->fetchAll();
} catch (Exception $e) {
    error_log('FG submit load: ' . $e->getMessage());
    fg_fail('Terjadi kesalahan. Coba lagi.');
}

$contactFields = ['name' => null, 'whatsapp' => null, 'email' => null];
$questions = [];
foreach ($fields as $f) {
    if ($f['field_role'] !== 'none') $contactFields[$f['field_role']] = $f;
    else $questions[] = $f;
}

// ─── Kontak ──────────────────────────────────────────────────
$name  = trim((string) ($_POST['contact_name'] ?? ''));
$wa    = lead_normalize_wa(trim((string) ($_POST['contact_whatsapp'] ?? '')));
$email = trim((string) ($_POST['contact_email'] ?? ''));

if ($name === '' || mb_strlen($name) < 2) fg_fail('Nama lengkap wajib diisi.');
if ($wa === '' || strlen($wa) < 10 || strlen($wa) > 15) fg_fail('Nomor WhatsApp tidak valid.');
$emailRequired = $contactFields['email'] && ((int) $contactFields['email']['is_required']) === 1;
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fg_fail('Format email tidak valid.');
if ($emailRequired && $email === '') fg_fail('Email wajib diisi.');
if (!$contactFields['email']) $email = ''; // form tak minta email

// ─── Answers (filter ke key valid) ───────────────────────────
$answersRaw = $_POST['answers'] ?? [];
if (!is_array($answersRaw)) $answersRaw = [];
$answers = [];
foreach ($questions as $q) {
    $k = $q['field_key'];
    $v = $answersRaw[$k] ?? null;
    if (is_array($v)) {
        $answers[$k] = array_values(array_map(fn($x) => substr((string) $x, 0, 500), $v));
    } elseif ($v !== null) {
        $answers[$k] = substr((string) $v, 0, 2000);
    }
}

// ─── Validasi wajib per pertanyaan ───────────────────────────
foreach ($questions as $q) {
    if (((int) $q['is_required']) !== 1) continue;
    $k = $q['field_key'];
    $v = $answers[$k] ?? null;
    $empty = ($v === null) || (is_array($v) ? count($v) === 0 : trim((string) $v) === '');
    if ($empty) fg_fail('Mohon lengkapi semua isian wajib.');
}

// ─── Skoring ─────────────────────────────────────────────────
$score = 0.0;
$scoreEnabled = ((int) ($form['score_enabled'] ?? 1)) === 1;
if ($scoreEnabled) {
    foreach ($questions as $q) {
        $k = $q['field_key'];
        $v = $answers[$k] ?? null;
        if ($v === null) continue;
        // Field non-checkbox hanya menerima skalar; bila payload dimanipulasi jadi
        // array, ambil elemen pertama agar skoring tidak memicu "Array to string"/warning.
        if ($q['field_type'] !== 'checkbox' && is_array($v)) {
            $v = reset($v);
            if (!is_scalar($v)) continue;
        }
        switch ($q['field_type']) {
            case 'select':
            case 'radio':
                $opts = json_decode((string) $q['options'], true) ?: [];
                foreach ($opts as $o) {
                    if ((string) ($o['value'] ?? '') === (string) $v) { $score += (float) ($o['score'] ?? 0); break; }
                }
                break;
            case 'checkbox':
                $opts = json_decode((string) $q['options'], true) ?: [];
                $chosen = is_array($v) ? $v : [$v];
                foreach ($opts as $o) {
                    if (in_array((string) ($o['value'] ?? ''), array_map('strval', $chosen), true)) {
                        $score += (float) ($o['score'] ?? 0);
                    }
                }
                break;
            case 'number':
                $score += (float) $v * (float) $q['weight'];
                break;
            case 'rating':
                $score += (float) $v * (float) $q['weight'];
                break;
        }
    }
}
$scoreInt = (int) round($score);

// ─── Resolusi redirect (by skor → default → inline success) ──
$redirectUrl = null;
$rules = $form['redirect_rules'] ? json_decode((string) $form['redirect_rules'], true) : null;
if (is_array($rules)) {
    foreach ($rules as $r) {
        if (!is_array($r)) continue;
        $min = isset($r['min']) ? (float) $r['min'] : -INF;
        $max = isset($r['max']) && $r['max'] !== '' ? (float) $r['max'] : INF;
        $url = trim((string) ($r['url'] ?? ''));
        if ($url !== '' && $scoreInt >= $min && $scoreInt <= $max) { $redirectUrl = $url; break; }
    }
}
if ($redirectUrl === null) {
    $def = trim((string) ($form['default_redirect_url'] ?? ''));
    if ($def !== '') $redirectUrl = $def;
}

$base = rtrim(APP_URL, '/');
$doneUrl = $base . '/apply/' . $form['slug'] . '?done=1';
$finalRedirect = $redirectUrl ?: $doneUrl;

// ─── Simpan (upsert baris draft milik sesi ini) ──────────────
$userId = isLoggedIn() ? (int) ($_SESSION['user_id'] ?? 0) : 0;
$token  = $_SESSION['fg_token'][$formId] ?? null;
$answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE);
$ip = client_real_ip();

try {
    $existing = null;
    if ($token) {
        $sel = $pdo->prepare('SELECT id, status FROM funnel_gate_submissions WHERE form_id = ? AND session_token = ? LIMIT 1');
        $sel->execute([$formId, $token]);
        $existing = $sel->fetch() ?: null;
    }

    if ($existing && $existing['status'] === 'draft') {
        $pdo->prepare(
            "UPDATE funnel_gate_submissions
             SET name = ?, whatsapp = ?, email = ?, answers = ?, score = ?, status = 'submitted',
                 redirect_url = ?, user_id = ?, ip_address = ?, submitted_at = NOW()
             WHERE id = ?"
        )->execute([
            $name, $wa, ($email ?: null), $answersJson, $scoreInt,
            ($redirectUrl ?: null), ($userId ?: null), $ip, (int) $existing['id'],
        ]);
        $subId = (int) $existing['id'];
    } elseif ($existing && $existing['status'] !== 'draft') {
        // Sudah pernah terkirim di sesi ini → jangan duplikat.
        unset($_SESSION['fg_token'][$formId]);
        echo json_encode(['ok' => true, 'redirect' => $finalRedirect]);
        exit;
    } else {
        // Tanpa draft (mis. JS autosave gagal) → insert baru. Token null aman
        // (UNIQUE(form_id, session_token) tak bentrok karena NULL tak sama).
        $pdo->prepare(
            "INSERT INTO funnel_gate_submissions
               (form_id, session_token, user_id, name, whatsapp, email, answers, score, status, redirect_url, ip_address, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, ?, NOW())"
        )->execute([
            $formId, ($token ?: null), ($userId ?: null), $name, $wa, ($email ?: null),
            $answersJson, $scoreInt, ($redirectUrl ?: null), $ip,
        ]);
        $subId = (int) $pdo->lastInsertId();
    }

    // Counter submission (non-draft).
    $pdo->prepare(
        "UPDATE funnel_gate_forms
         SET total_submissions = (SELECT COUNT(*) FROM funnel_gate_submissions WHERE form_id = ? AND status <> 'draft')
         WHERE id = ?"
    )->execute([$formId, $formId]);

    if (function_exists('logActivity')) {
        logActivity('public', 'funnel_gate.submitted', 'funnel_gate_submissions', $subId,
            'Submission Funnel Gate "' . $form['title'] . '" (skor ' . $scoreInt . ').');
    }

    // Sesi selesai → token baru untuk aplikasi berikutnya.
    unset($_SESSION['fg_token'][$formId]);

    echo json_encode(['ok' => true, 'redirect' => $finalRedirect, 'score' => $scoreInt]);
} catch (Exception $e) {
    error_log('FG submit save: ' . $e->getMessage());
    fg_fail('Gagal menyimpan. Coba lagi sebentar.');
}
