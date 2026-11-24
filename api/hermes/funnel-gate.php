<?php
// ════════════════════════════════════════════════════════════════════════
// Hermes Agent — Ingest Funnel Gate (sistem kualifikasi prospek)
//   GET  /api/hermes/funnel-gate            → daftar form
//   GET  /api/hermes/funnel-gate?ref=<x>    → satu form (by external_ref)
//   POST /api/hermes/funnel-gate            → buat/perbarui (upsert by external_ref)
//
// Prinsip tergembok (selaras ingest email-sequences):
//   - Form masuk SELALU status 'draft' (publikasi tetap admin). Field 'status'
//     tidak diterima di payload.
//   - Idempoten via external_ref UNIQUE (retry Hermes aman).
//   - TIDAK boleh mengubah form yang sudah 'published' (admin set ke draft/paused dulu).
//   - dry_run: validasi + preview + confirm_hash, TANPA menulis.
//   - Hermes menyusun KONTEN (pertanyaan, skor, aturan redirect) — tak pernah menerbitkan.
// Auth: Bearer token + hermes_enabled (tier master; ingest draft tak butuh manage).
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/hermes.php';

const HERMES_FG_FIELD_TYPES = ['text', 'textarea', 'select', 'radio', 'checkbox', 'number', 'rating'];
const HERMES_FG_CHOICE_TYPES = ['select', 'radio', 'checkbox'];
const HERMES_FG_MAX_STEPS = 30;
const HERMES_FG_MAX_QUESTIONS = 100;
const HERMES_FG_MAX_OPTIONS = 50;
const HERMES_FG_MAX_RULES = 30;
const HERMES_FG_WEIGHT_ABS = 9999.99; // DECIMAL(6,2)

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    hermesApiSend(405, ['ok' => false, 'error' => 'Metode tidak diizinkan. Gunakan GET atau POST.']);
}
if ($method === 'POST' && !str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    hermesApiSend(415, ['ok' => false, 'error' => 'Content-Type harus application/json.']);
}
hermesApiAuthorize(false); // master tier (draft ingest)
$pdo = getDB();

// ─── Helper sanitasi (konsisten dengan actions/admin/save-funnel-gate.php) ──
/** Slug: huruf/angka + tanda hubung. Kosong hanya bila input seluruhnya simbol. */
function hermesFgSlug(string $raw): string
{
    $slug = strtolower(trim($raw));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}
/** field_key unik dalam satu form (slugify label). */
function hermesFgFieldKey(string $label, array &$used): string
{
    $k = strtolower(trim($label));
    $k = preg_replace('/[^a-z0-9]+/', '_', $k);
    $k = trim($k, '_');
    if ($k === '') $k = 'field';
    $k = substr($k, 0, 50);
    $base = $k; $n = 2;
    while (in_array($k, $used, true)) { $k = $base . '_' . $n; $n++; }
    $used[] = $k;
    return $k;
}
/** URL redirect: relatif (/checkout) atau absolut (https://…); skema aneh ditolak. */
function hermesFgCleanUrl(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (str_starts_with($u, '/')) return substr($u, 0, 500);
    if (preg_match('#^https?://#i', $u)) return substr($u, 0, 500);
    return '';
}

/** Serialisasi satu form + steps + fields (untuk readback). */
function hermesFgOutput(PDO $pdo, array $row): array
{
    $fid = (int) $row['id'];
    $ss = $pdo->prepare('SELECT id, title, description, sort_order FROM funnel_gate_steps WHERE form_id = ? ORDER BY sort_order ASC, id ASC');
    $ss->execute([$fid]);
    $steps = array_map(static fn(array $s): array => [
        'id'          => (int) $s['id'],
        'title'       => $s['title'],
        'description' => $s['description'],
        'sort_order'  => (int) $s['sort_order'],
    ], $ss->fetchAll());

    $ff = $pdo->prepare('SELECT step_id, field_role, field_key, field_type, label, placeholder, help_text, is_required, options, weight, sort_order FROM funnel_gate_fields WHERE form_id = ? ORDER BY sort_order ASC, id ASC');
    $ff->execute([$fid]);
    $fields = array_map(static function (array $f): array {
        return [
            'step_id'     => $f['step_id'] !== null ? (int) $f['step_id'] : null,
            'field_role'  => $f['field_role'],
            'field_key'   => $f['field_key'],
            'field_type'  => $f['field_type'],
            'label'       => $f['label'],
            'placeholder' => $f['placeholder'],
            'help_text'   => $f['help_text'],
            'is_required' => (int) $f['is_required'] === 1,
            'options'     => $f['options'] !== null ? json_decode((string) $f['options'], true) : null,
            'weight'      => (float) $f['weight'],
            'sort_order'  => (int) $f['sort_order'],
        ];
    }, $ff->fetchAll());

    return [
        'id'                   => $fid,
        'external_ref'         => $row['external_ref'],
        'title'                => $row['title'],
        'slug'                 => $row['slug'],
        'public_url'           => '/apply/' . $row['slug'],
        'status'               => $row['status'],
        'intro'                => $row['intro'],
        'submit_text'          => $row['submit_text'],
        'success_message'      => $row['success_message'],
        'score_enabled'        => (int) $row['score_enabled'] === 1,
        'form_mode'            => $row['form_mode'],
        'default_redirect_url' => $row['default_redirect_url'],
        'redirect_rules'       => $row['redirect_rules'] !== null ? json_decode((string) $row['redirect_rules'], true) : [],
        'mailketing_list_id'   => $row['mailketing_list_id'],
        'steps'                => $steps,
        'fields'               => $fields,
        'created_at'           => $row['created_at'],
        'updated_at'           => $row['updated_at'],
    ];
}

const HERMES_FG_SELECT = 'SELECT id, external_ref, title, slug, status, intro, submit_text, success_message, score_enabled, form_mode, default_redirect_url, redirect_rules, mailketing_list_id, created_at, updated_at FROM funnel_gate_forms';

// ─── GET: readback ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $ref = trim((string) ($_GET['ref'] ?? ''));
    if ($ref !== '') {
        $st = $pdo->prepare(HERMES_FG_SELECT . ' WHERE external_ref = ? LIMIT 1');
        $st->execute([$ref]);
        $row = $st->fetch();
        if (!$row) hermesApiSend(404, ['ok' => false, 'error' => 'Funnel Gate tidak ditemukan untuk external_ref ini.']);
        hermesApiSend(200, ['ok' => true, 'form' => hermesFgOutput($pdo, $row)]);
    }
    $rows = $pdo->query(HERMES_FG_SELECT . ' ORDER BY updated_at DESC, id DESC')->fetchAll();
    hermesApiSend(200, ['ok' => true, 'forms' => array_map(fn($r) => hermesFgOutput($pdo, $r), $rows)]);
}

// ─── POST: validasi ──────────────────────────────────────────────────────
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) hermesApiSend(400, ['ok' => false, 'error' => 'Body JSON tidak valid.']);
$allowed = ['external_ref', 'title', 'slug', 'intro', 'submit_text', 'success_message',
    'score_enabled', 'contact', 'form_mode', 'steps', 'questions', 'redirect_rules',
    'default_redirect_url', 'mailketing_list_id', 'dry_run', 'base_updated_at', 'confirm_hash'];
$unknown = array_values(array_diff(array_keys($in), $allowed));
if ($unknown) hermesApiSend(400, ['ok' => false, 'error' => 'Field tidak diizinkan: ' . implode(', ', $unknown) . '. (status selalu draft, tak bisa diatur di sini.)']);

// external_ref + judul
$ref = trim((string) ($in['external_ref'] ?? ''));
if ($ref === '' || mb_strlen($ref) > 191) hermesApiSend(422, ['ok' => false, 'error' => 'external_ref wajib dan maksimal 191 karakter.']);
$title = trim((string) ($in['title'] ?? ''));
if ($title === '' || mb_strlen($title) > 200) hermesApiSend(422, ['ok' => false, 'error' => 'title wajib dan maksimal 200 karakter.']);

// slug (dari title bila kosong)
$slug = hermesFgSlug((string) ($in['slug'] ?? '') !== '' ? (string) $in['slug'] : $title);
if ($slug === '') hermesApiSend(422, ['ok' => false, 'error' => 'slug tidak valid (kosong). Pakai huruf/angka.']);
if (mb_strlen($slug) > 150) $slug = substr($slug, 0, 150);

// teks opsional
$intro      = trim((string) ($in['intro'] ?? ''));
$submitText = trim((string) ($in['submit_text'] ?? ''));
if (mb_strlen($submitText) > 100) hermesApiSend(422, ['ok' => false, 'error' => 'submit_text maksimal 100 karakter.']);
$successMsg = trim((string) ($in['success_message'] ?? ''));
$scoreEnabled = !empty($in['score_enabled']) ? 1 : 0;

// kontak (kunci desain: kontak di awal, follow-up-able)
$contact = is_array($in['contact'] ?? null) ? $in['contact'] : [];
$nameLabel  = trim((string) ($contact['name_label'] ?? '')) ?: 'Nama Lengkap';
$waLabel    = trim((string) ($contact['whatsapp_label'] ?? '')) ?: 'Nomor WhatsApp';
$emailOn    = !empty($contact['email_enabled']);
$emailReq   = !empty($contact['email_required']) ? 1 : 0;
$emailLabel = trim((string) ($contact['email_label'] ?? '')) ?: 'Email';
foreach (['name_label' => $nameLabel, 'whatsapp_label' => $waLabel, 'email_label' => $emailLabel] as $k => $v) {
    if (mb_strlen($v) > 255) hermesApiSend(422, ['ok' => false, 'error' => "contact.$k maksimal 255 karakter."]);
}

// mode form
$formMode = (($in['form_mode'] ?? 'single') === 'multi') ? 'multi' : 'single';

// steps (mode multi). cid = id klien untuk memetakan field→step.
$steps = [];
if ($formMode === 'multi') {
    $rawSteps = is_array($in['steps'] ?? null) ? $in['steps'] : [];
    if (count($rawSteps) > HERMES_FG_MAX_STEPS) hermesApiSend(422, ['ok' => false, 'error' => 'steps maksimal ' . HERMES_FG_MAX_STEPS . ' item.']);
    $seenCid = [];
    foreach ($rawSteps as $i => $stp) {
        if (!is_array($stp)) hermesApiSend(422, ['ok' => false, 'error' => 'Tiap steps[] wajib object {cid, title, description}.']);
        $cid = trim((string) ($stp['cid'] ?? ('s' . ($i + 1))));
        if ($cid === '' || isset($seenCid[$cid])) hermesApiSend(422, ['ok' => false, 'error' => 'steps[].cid wajib unik & tidak kosong.']);
        $stitle = trim((string) ($stp['title'] ?? '')) ?: ('Langkah ' . ($i + 1));
        $sdesc  = trim((string) ($stp['description'] ?? ''));
        if (mb_strlen($stitle) > 200) hermesApiSend(422, ['ok' => false, 'error' => 'steps[].title maksimal 200 karakter.']);
        if (mb_strlen($sdesc) > 500) hermesApiSend(422, ['ok' => false, 'error' => 'steps[].description maksimal 500 karakter.']);
        $seenCid[$cid] = true;
        $steps[] = ['cid' => $cid, 'title' => substr($stitle, 0, 200), 'description' => ($sdesc !== '' ? substr($sdesc, 0, 500) : null)];
    }
    if (empty($steps)) $steps[] = ['cid' => 's1', 'title' => 'Langkah 1', 'description' => null];
}
$validCids = array_column($steps, 'cid');
$firstCid  = $validCids[0] ?? null;

// questions (pertanyaan skoring)
$rawQuestions = is_array($in['questions'] ?? null) ? $in['questions'] : [];
if (count($rawQuestions) > HERMES_FG_MAX_QUESTIONS) hermesApiSend(422, ['ok' => false, 'error' => 'questions maksimal ' . HERMES_FG_MAX_QUESTIONS . ' item.']);
$questions = [];
foreach ($rawQuestions as $i => $q) {
    if (!is_array($q)) hermesApiSend(422, ['ok' => false, 'error' => 'Pertanyaan ke-' . ($i + 1) . ' wajib object.']);
    $type = in_array(($q['type'] ?? ''), HERMES_FG_FIELD_TYPES, true) ? $q['type'] : null;
    if ($type === null) hermesApiSend(422, ['ok' => false, 'error' => 'questions[' . $i . '].type tidak valid. Pilihan: ' . implode(', ', HERMES_FG_FIELD_TYPES) . '.']);
    $label = trim((string) ($q['label'] ?? ''));
    if ($label === '' || mb_strlen($label) > 255) hermesApiSend(422, ['ok' => false, 'error' => 'questions[' . $i . '].label wajib dan maksimal 255 karakter.']);
    $ph   = trim((string) ($q['placeholder'] ?? ''));
    $help = trim((string) ($q['help'] ?? ''));
    if (mb_strlen($ph) > 255) hermesApiSend(422, ['ok' => false, 'error' => 'questions[' . $i . '].placeholder maksimal 255 karakter.']);
    if (mb_strlen($help) > 500) hermesApiSend(422, ['ok' => false, 'error' => 'questions[' . $i . '].help maksimal 500 karakter.']);
    $req  = !empty($q['required']) ? 1 : 0;

    // step mapping (mode multi): step_cid harus menunjuk cid valid; fallback step-1.
    $stepCid = null;
    if ($formMode === 'multi') {
        $wanted = trim((string) ($q['step_cid'] ?? ''));
        $stepCid = ($wanted !== '' && in_array($wanted, $validCids, true)) ? $wanted : $firstCid;
    }

    $weight = 0.0;
    $optionsJson = null;
    if (in_array($type, HERMES_FG_CHOICE_TYPES, true)) {
        $rawOpts = is_array($q['options'] ?? null) ? $q['options'] : [];
        if (count($rawOpts) > HERMES_FG_MAX_OPTIONS) hermesApiSend(422, ['ok' => false, 'error' => 'questions[' . $i . '].options maksimal ' . HERMES_FG_MAX_OPTIONS . ' item.']);
        $opts = [];
        foreach ($rawOpts as $oi => $o) {
            if (!is_array($o)) continue;
            $ol = trim((string) ($o['label'] ?? ''));
            if ($ol === '') continue;
            if (mb_strlen($ol) > 255) $ol = substr($ol, 0, 255);
            $os = (float) ($o['score'] ?? 0);
            if ($os > HERMES_FG_WEIGHT_ABS) $os = HERMES_FG_WEIGHT_ABS;
            if ($os < -HERMES_FG_WEIGHT_ABS) $os = -HERMES_FG_WEIGHT_ABS;
            $opts[] = ['label' => $ol, 'value' => (string) count($opts), 'score' => $os];
        }
        if (empty($opts)) hermesApiSend(422, ['ok' => false, 'error' => 'questions[' . $i . '] tipe pilihan wajib punya minimal 1 options {label, score}.']);
        $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
    } elseif ($type === 'number') {
        $weight = (float) ($q['weight'] ?? 0);
    } elseif ($type === 'rating') {
        $weight = (float) ($q['weight'] ?? 0);
        $max = (int) ($q['rating_max'] ?? 5);
        if ($max < 2)  $max = 2;
        if ($max > 10) $max = 10;
        $optionsJson = json_encode(['max' => $max], JSON_UNESCAPED_UNICODE);
    }
    if ($weight > HERMES_FG_WEIGHT_ABS) $weight = HERMES_FG_WEIGHT_ABS;
    if ($weight < -HERMES_FG_WEIGHT_ABS) $weight = -HERMES_FG_WEIGHT_ABS;

    $questions[] = [
        'type' => $type, 'label' => $label, 'placeholder' => ($ph ?: null), 'help' => ($help ?: null),
        'required' => $req, 'step_cid' => $stepCid, 'weight' => $weight, 'options_json' => $optionsJson,
    ];
}

// redirect rules by-skor: [{min?,max?,url,label?}]
$redirectRules = [];
$rawRules = is_array($in['redirect_rules'] ?? null) ? $in['redirect_rules'] : [];
if (count($rawRules) > HERMES_FG_MAX_RULES) hermesApiSend(422, ['ok' => false, 'error' => 'redirect_rules maksimal ' . HERMES_FG_MAX_RULES . ' item.']);
foreach ($rawRules as $r) {
    if (!is_array($r)) continue;
    $url = hermesFgCleanUrl((string) ($r['url'] ?? ''));
    if ($url === '') continue;
    $rule = ['url' => $url, 'label' => substr(trim((string) ($r['label'] ?? '')), 0, 100)];
    if (isset($r['min']) && is_numeric($r['min'])) $rule['min'] = (float) $r['min'];
    if (isset($r['max']) && is_numeric($r['max'])) $rule['max'] = (float) $r['max'];
    $redirectRules[] = $rule;
}
$redirectRulesJson = !empty($redirectRules) ? json_encode($redirectRules, JSON_UNESCAPED_UNICODE) : null;

$defaultRedirect = hermesFgCleanUrl((string) ($in['default_redirect_url'] ?? ''));
$mailketingList  = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) ($in['mailketing_list_id'] ?? '')));
$mailketingList  = $mailketingList !== '' ? substr($mailketingList, 0, 40) : null;

// ─── confirm_hash: hash kanonik payload (preview == apply) ────────────────
$canonContact = ['name_label' => $nameLabel, 'whatsapp_label' => $waLabel, 'email_enabled' => $emailOn, 'email_required' => (bool) $emailReq, 'email_label' => $emailLabel];
$canon = json_encode([
    'title' => $title, 'slug' => $slug, 'intro' => $intro, 'submit_text' => $submitText,
    'success_message' => $successMsg, 'score_enabled' => $scoreEnabled, 'contact' => $canonContact,
    'form_mode' => $formMode, 'steps' => $steps, 'questions' => $questions,
    'redirect_rules' => $redirectRules, 'default_redirect_url' => $defaultRedirect, 'mailketing_list_id' => $mailketingList,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$confirmHash = hash('sha256', (string) $canon);

// preview steps/fields untuk dry_run (apa yang akan dibuat)
$previewFields = [];
$previewFields[] = ['field_role' => 'name', 'field_type' => 'text', 'label' => $nameLabel, 'is_required' => true];
$previewFields[] = ['field_role' => 'whatsapp', 'field_type' => 'phone', 'label' => $waLabel, 'is_required' => true];
if ($emailOn) $previewFields[] = ['field_role' => 'email', 'field_type' => 'email', 'label' => $emailLabel, 'is_required' => (bool) $emailReq];
foreach ($questions as $q) {
    $previewFields[] = ['field_role' => 'none', 'field_type' => $q['type'], 'label' => $q['label'], 'is_required' => (bool) $q['required'],
        'options' => $q['options_json'] !== null ? json_decode($q['options_json'], true) : null, 'weight' => $q['weight'], 'step_cid' => $q['step_cid']];
}

$dry = !empty($in['dry_run']);
if ($dry) {
    hermesApiSend(200, ['ok' => true, 'dry_run' => true, 'external_ref' => $ref, 'title' => $title, 'slug' => $slug,
        'public_url' => '/apply/' . $slug, 'status' => 'draft', 'score_enabled' => (bool) $scoreEnabled,
        'form_mode' => $formMode, 'steps' => $steps, 'fields' => $previewFields,
        'redirect_rules' => $redirectRules, 'default_redirect_url' => $defaultRedirect, 'confirm_hash' => $confirmHash]);
}
if (isset($in['confirm_hash']) && !hash_equals($confirmHash, (string) $in['confirm_hash'])) {
    hermesApiSend(409, ['ok' => false, 'error' => 'confirm_hash tidak cocok dengan payload. Jalankan dry_run lagi.', 'confirm_hash' => $confirmHash]);
}

// ─── Apply (upsert by external_ref) ──────────────────────────────────────
try {
    // Slug dipakai form LAIN? (UNIQUE uniq_fgf_slug). Cek dini agar pesan jelas.
    $slugChk = $pdo->prepare('SELECT id FROM funnel_gate_forms WHERE slug = ? AND (external_ref IS NULL OR external_ref <> ?) LIMIT 1');
    $slugChk->execute([$slug, $ref]);
    if ($slugChk->fetchColumn()) hermesApiSend(409, ['ok' => false, 'error' => 'Slug "' . $slug . '" sudah dipakai form lain. Pilih slug berbeda.']);

    $ex = $pdo->prepare('SELECT id, status, updated_at FROM funnel_gate_forms WHERE external_ref = ? LIMIT 1');
    $ex->execute([$ref]);
    $existing = $ex->fetch();
    if ($existing && $existing['status'] === 'published') {
        hermesApiSend(409, ['ok' => false, 'error' => 'Funnel Gate ini sudah TERBIT (published). Set ke draft/paused di admin sebelum mengubah via Hermes.']);
    }
    if ($existing && isset($in['base_updated_at']) && (string) $in['base_updated_at'] !== (string) $existing['updated_at']) {
        hermesApiSend(409, ['ok' => false, 'error' => 'Konflik: form berubah sejak terakhir dibaca. Baca ulang lalu coba lagi.', 'current_updated_at' => $existing['updated_at']]);
    }

    $pdo->beginTransaction();
    if ($existing) {
        $formId = (int) $existing['id'];
        $pdo->prepare(
            "UPDATE funnel_gate_forms
               SET title=?, slug=?, intro=?, submit_text=?, success_message=?, status='draft',
                   score_enabled=?, default_redirect_url=?, redirect_rules=?, mailketing_list_id=?,
                   form_mode=?, updated_at=NOW()
             WHERE id=?"
        )->execute([
            $title, $slug, ($intro ?: null), ($submitText ?: null), ($successMsg ?: null),
            $scoreEnabled, ($defaultRedirect ?: null), $redirectRulesJson, $mailketingList, $formMode, $formId,
        ]);
    } else {
        try {
            $pdo->prepare(
                "INSERT INTO funnel_gate_forms
                   (title, slug, external_ref, intro, submit_text, success_message, status, score_enabled, notify_admin,
                    default_redirect_url, redirect_rules, mailketing_list_id, form_mode)
                 VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, 1, ?, ?, ?, ?)"
            )->execute([
                $title, $slug, $ref, ($intro ?: null), ($submitText ?: null), ($successMsg ?: null),
                $scoreEnabled, ($defaultRedirect ?: null), $redirectRulesJson, $mailketingList, $formMode,
            ]);
            $formId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Race external_ref (UNIQUE) → ambil pemenang lalu update.
            if ($e->getCode() === '23000') {
                $pdo->rollBack();
                $r2 = $pdo->prepare('SELECT id, status FROM funnel_gate_forms WHERE external_ref = ? LIMIT 1');
                $r2->execute([$ref]);
                $w = $r2->fetch();
                if (!$w) throw $e;
                if ($w['status'] === 'published') hermesApiSend(409, ['ok' => false, 'error' => 'Funnel Gate ini sudah TERBIT. Set ke draft dulu.']);
                $formId = (int) $w['id'];
                $pdo->beginTransaction();
                $pdo->prepare(
                    "UPDATE funnel_gate_forms
                       SET title=?, slug=?, intro=?, submit_text=?, success_message=?, status='draft',
                           score_enabled=?, default_redirect_url=?, redirect_rules=?, mailketing_list_id=?,
                           form_mode=?, updated_at=NOW()
                     WHERE id=?"
                )->execute([
                    $title, $slug, ($intro ?: null), ($submitText ?: null), ($successMsg ?: null),
                    $scoreEnabled, ($defaultRedirect ?: null), $redirectRulesJson, $mailketingList, $formMode, $formId,
                ]);
            } else {
                throw $e;
            }
        }
    }

    // ─── Rebuild steps + fields (urutan hapus: fields dulu, lalu steps — FK step_id)
    $pdo->prepare('DELETE FROM funnel_gate_fields WHERE form_id = ?')->execute([$formId]);
    $pdo->prepare('DELETE FROM funnel_gate_steps WHERE form_id = ?')->execute([$formId]);

    $stepMap = []; $firstStepId = null;
    if ($formMode === 'multi') {
        $insStep = $pdo->prepare('INSERT INTO funnel_gate_steps (form_id, title, description, sort_order) VALUES (?, ?, ?, ?)');
        $so = 0;
        foreach ($steps as $stp) {
            $insStep->execute([$formId, $stp['title'], $stp['description'], $so++]);
            $sid = (int) $pdo->lastInsertId();
            $stepMap[$stp['cid']] = $sid;
            if ($firstStepId === null) $firstStepId = $sid;
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO funnel_gate_fields
           (form_id, step_id, field_role, field_key, field_type, label, placeholder, help_text, is_required, options, weight, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $used = []; $sort = 0;

    // Kontak di awal (mode multi → step-1).
    $contactStep = ($formMode === 'multi') ? $firstStepId : null;
    $ins->execute([$formId, $contactStep, 'name', 'name', 'text', $nameLabel, null, null, 1, null, 0, $sort++]);
    $ins->execute([$formId, $contactStep, 'whatsapp', 'whatsapp', 'phone', $waLabel, '08xxxxxxxxxx', null, 1, null, 0, $sort++]);
    if ($emailOn) $ins->execute([$formId, $contactStep, 'email', 'email', 'email', $emailLabel, null, null, $emailReq, null, 0, $sort++]);
    $used = ['name', 'whatsapp', 'email'];

    // Pertanyaan.
    foreach ($questions as $q) {
        $stepId = ($formMode === 'multi') ? ($stepMap[$q['step_cid']] ?? $firstStepId) : null;
        $key = hermesFgFieldKey($q['label'], $used);
        $ins->execute([$formId, $stepId, 'none', $key, $q['type'], $q['label'], $q['placeholder'], $q['help'], $q['required'], $q['options_json'], $q['weight'], $sort++]);
    }

    $pdo->commit();
    hermesLog('funnel-gate upsert ref=' . $ref . ' id=' . $formId . ' q=' . count($questions) . ' ' . ($existing ? 'update' : 'create'));

    $st = $pdo->prepare(HERMES_FG_SELECT . ' WHERE id = ?');
    $st->execute([$formId]);
    hermesApiSend(200, ['ok' => true, 'created' => !$existing, 'confirm_hash' => $confirmHash, 'form' => hermesFgOutput($pdo, $st->fetch())]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    hermesLog('500 funnel-gate gagal ref=' . $ref . ' : ' . $e->getMessage());
    hermesApiSend(500, ['ok' => false, 'error' => 'Gagal menyimpan Funnel Gate.']);
}
