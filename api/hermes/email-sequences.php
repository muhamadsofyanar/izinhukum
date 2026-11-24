<?php
// ════════════════════════════════════════════════════════════════════════
// Hermes Agent — Ingest Email Sequence
//   GET  /api/hermes/email-sequences            → daftar sequence
//   GET  /api/hermes/email-sequences?ref=<x>    → satu sequence (by external_ref)
//   POST /api/hermes/email-sequences            → buat/perbarui (upsert by external_ref)
//
// Prinsip tergembok:
//   - Sequence masuk SELALU status 'inactive' (aktivasi tetap admin). Field 'status'
//     tidak diterima di payload.
//   - Idempoten via external_ref UNIQUE (retry Hermes aman).
//   - TIDAK boleh mengubah sequence yang sudah 'active' (admin harus nonaktifkan dulu).
//   - dry_run: validasi + preview + confirm_hash, TANPA menulis.
//   - body HTML diteruskan sebagai HTML email (bukan konten publik). Tidak pernah kirim.
// Auth: Bearer token + hermes_enabled (tier master; ingest draft tak butuh manage).
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/hermes.php';

const HERMES_SEQ_TRIGGERS = ['order_paid', 'member_access_activated', 'user_register', 'waitlist_joined', 'booking_paid', 'lead_captured'];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    hermesApiSend(405, ['ok' => false, 'error' => 'Metode tidak diizinkan. Gunakan GET atau POST.']);
}
if ($method === 'POST' && !str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    hermesApiSend(415, ['ok' => false, 'error' => 'Content-Type harus application/json.']);
}
hermesApiAuthorize(false); // master tier (draft ingest)
$pdo = getDB();

/** Serialisasi satu sequence + steps + products (untuk readback). */
function hermesSeqOutput(PDO $pdo, array $row): array
{
    $sid = (int) $row['id'];
    $st = $pdo->prepare('SELECT step_number, delay_days, subject, body, body_format, status FROM email_sequence_steps WHERE sequence_id = ? ORDER BY step_number ASC');
    $st->execute([$sid]);
    $steps = array_map(static function (array $s): array {
        $s['step_number'] = (int) $s['step_number'];
        $s['delay_days']  = (int) $s['delay_days'];
        return $s;
    }, $st->fetchAll());
    $pp = $pdo->prepare('SELECT product_id, kind FROM email_sequence_products WHERE sequence_id = ? ORDER BY id ASC');
    $pp->execute([$sid]);
    $products = array_map(static fn(array $p): array => ['product_id' => (int) $p['product_id'], 'kind' => $p['kind']], $pp->fetchAll());
    return [
        'id'           => $sid,
        'external_ref' => $row['external_ref'],
        'name'         => $row['name'],
        'trigger'      => $row['trigger_event'],
        'status'       => $row['status'],
        'products'     => $products,
        'steps'        => $steps,
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
    ];
}

// ─── GET: readback ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $ref = trim((string) ($_GET['ref'] ?? ''));
    if ($ref !== '') {
        $st = $pdo->prepare('SELECT id,external_ref,name,trigger_event,status,created_at,updated_at FROM email_sequences WHERE external_ref = ? LIMIT 1');
        $st->execute([$ref]);
        $row = $st->fetch();
        if (!$row) hermesApiSend(404, ['ok' => false, 'error' => 'Sequence tidak ditemukan untuk external_ref ini.']);
        hermesApiSend(200, ['ok' => true, 'sequence' => hermesSeqOutput($pdo, $row)]);
    }
    $rows = $pdo->query('SELECT id,external_ref,name,trigger_event,status,created_at,updated_at FROM email_sequences ORDER BY updated_at DESC, id DESC')->fetchAll();
    hermesApiSend(200, ['ok' => true, 'sequences' => array_map(fn($r) => hermesSeqOutput($pdo, $r), $rows)]);
}

// ─── POST: validasi ──────────────────────────────────────────────────────
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) hermesApiSend(400, ['ok' => false, 'error' => 'Body JSON tidak valid.']);
$allowed = ['external_ref', 'name', 'trigger', 'products', 'steps', 'dry_run', 'base_updated_at', 'confirm_hash'];
$unknown = array_values(array_diff(array_keys($in), $allowed));
if ($unknown) hermesApiSend(400, ['ok' => false, 'error' => 'Field tidak diizinkan: ' . implode(', ', $unknown) . '. (status selalu inactive, tak bisa diatur di sini.)']);

$ref = trim((string) ($in['external_ref'] ?? ''));
if ($ref === '' || mb_strlen($ref) > 191) hermesApiSend(422, ['ok' => false, 'error' => 'external_ref wajib dan maksimal 191 karakter.']);
$name = trim((string) ($in['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 160) hermesApiSend(422, ['ok' => false, 'error' => 'name wajib dan maksimal 160 karakter.']);
$trigger = (string) ($in['trigger'] ?? '');
if (!in_array($trigger, HERMES_SEQ_TRIGGERS, true)) hermesApiSend(422, ['ok' => false, 'error' => 'trigger tidak valid. Pilihan: ' . implode(', ', HERMES_SEQ_TRIGGERS) . '.']);

// products (opsional): targeting via junction, sama seperti admin.
$products = [];
if (array_key_exists('products', $in)) {
    if (!is_array($in['products']) || count($in['products']) > 100) hermesApiSend(422, ['ok' => false, 'error' => 'products maksimal 100 item.']);
    $seen = [];
    foreach ($in['products'] as $p) {
        if (!is_array($p)) hermesApiSend(422, ['ok' => false, 'error' => 'Tiap products[] wajib object {product_id, kind}.']);
        $pid  = (int) ($p['product_id'] ?? 0);
        $kind = ($p['kind'] ?? 'include') === 'exclude' ? 'exclude' : 'include';
        if ($pid <= 0 || isset($seen[$pid])) continue;
        $chk = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
        $chk->execute([$pid]);
        if (!$chk->fetchColumn()) hermesApiSend(422, ['ok' => false, 'error' => 'product_id ' . $pid . ' tidak ditemukan.']);
        $seen[$pid] = true;
        $products[] = ['product_id' => $pid, 'kind' => $kind];
    }
}

// steps
if (!isset($in['steps']) || !is_array($in['steps']) || count($in['steps']) < 1 || count($in['steps']) > 50) {
    hermesApiSend(422, ['ok' => false, 'error' => 'steps wajib array 1-50 item.']);
}
$steps = []; $numbers = [];
foreach ($in['steps'] as $i => $step) {
    if (!is_array($step)) hermesApiSend(422, ['ok' => false, 'error' => 'Step ke-' . ($i + 1) . ' wajib object.']);
    $number     = (int) ($step['step_number'] ?? ($i + 1));
    $delay      = (int) ($step['delay_days'] ?? 0);
    $subject    = trim(str_replace(["\r", "\n"], '', (string) ($step['subject'] ?? '')));
    $body       = (string) ($step['body'] ?? '');
    $format     = ($step['body_format'] ?? 'html') === 'text' ? 'text' : 'html';
    $stepStatus = ($step['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    if ($number < 1 || $number > 50 || in_array($number, $numbers, true)) hermesApiSend(422, ['ok' => false, 'error' => 'step_number harus unik dan 1-50.']);
    if ($delay < 0 || $delay > 365) hermesApiSend(422, ['ok' => false, 'error' => 'delay_days harus 0-365.']);
    if ($subject === '' || mb_strlen($subject) > 255) hermesApiSend(422, ['ok' => false, 'error' => 'Subject step ke-' . ($i + 1) . ' wajib dan maksimal 255 karakter.']);
    if ($body === '' || strlen($body) > 500000) hermesApiSend(422, ['ok' => false, 'error' => 'Body step ke-' . ($i + 1) . ' wajib dan maksimal 500 KB.']);
    $numbers[] = $number;
    $steps[] = ['step_number' => $number, 'delay_days' => $delay, 'subject' => $subject, 'body' => $body, 'body_format' => $format, 'status' => $stepStatus];
}
usort($steps, fn($a, $b) => $a['step_number'] <=> $b['step_number']);

// confirm_hash: hash kanonik payload (deterministik) — Hermes bisa verifikasi preview==apply.
$canon = json_encode(['name' => $name, 'trigger' => $trigger, 'products' => $products, 'steps' => $steps], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$confirmHash = hash('sha256', (string) $canon);

$dry = !empty($in['dry_run']);
if ($dry) {
    hermesApiSend(200, ['ok' => true, 'dry_run' => true, 'external_ref' => $ref, 'name' => $name, 'trigger' => $trigger, 'status' => 'inactive', 'products' => $products, 'steps' => $steps, 'confirm_hash' => $confirmHash]);
}
// Bila client mengirim confirm_hash, wajib cocok (opsional tapi bila ada → divalidasi).
if (isset($in['confirm_hash']) && !hash_equals($confirmHash, (string) $in['confirm_hash'])) {
    hermesApiSend(409, ['ok' => false, 'error' => 'confirm_hash tidak cocok dengan payload. Jalankan dry_run lagi.', 'confirm_hash' => $confirmHash]);
}

// ─── Apply (upsert by external_ref) ──────────────────────────────────────
try {
    // Sudah ada? Tak boleh mengubah yang AKTIF.
    $ex = $pdo->prepare('SELECT id, status, updated_at FROM email_sequences WHERE external_ref = ? LIMIT 1');
    $ex->execute([$ref]);
    $existing = $ex->fetch();
    if ($existing && $existing['status'] === 'active') {
        hermesApiSend(409, ['ok' => false, 'error' => 'Sequence ini sudah AKTIF. Nonaktifkan dulu di admin sebelum mengubah via Hermes.']);
    }
    // Optimistic lock opsional.
    if ($existing && isset($in['base_updated_at']) && (string) $in['base_updated_at'] !== (string) $existing['updated_at']) {
        hermesApiSend(409, ['ok' => false, 'error' => 'Konflik: sequence berubah sejak terakhir dibaca. Baca ulang lalu coba lagi.', 'current_updated_at' => $existing['updated_at']]);
    }

    $pdo->beginTransaction();
    if ($existing) {
        $seqId = (int) $existing['id'];
        $pdo->prepare("UPDATE email_sequences SET name=?, trigger_event=?, trigger_product_id=NULL, provider='mailketing', status='inactive', updated_at=NOW() WHERE id=?")
            ->execute([$name, $trigger, $seqId]);
    } else {
        try {
            $pdo->prepare("INSERT INTO email_sequences (name, trigger_event, trigger_product_id, channel, provider, external_ref, status) VALUES (?, ?, NULL, 'email', 'mailketing', ?, 'inactive')")
                ->execute([$name, $trigger, $ref]);
            $seqId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Race: external_ref ditulis bersamaan → ambil pemenang lalu update.
            if ($e->getCode() === '23000') {
                $pdo->rollBack();
                $r2 = $pdo->prepare('SELECT id, status FROM email_sequences WHERE external_ref = ? LIMIT 1');
                $r2->execute([$ref]);
                $w = $r2->fetch();
                if (!$w) throw $e;
                if ($w['status'] === 'active') hermesApiSend(409, ['ok' => false, 'error' => 'Sequence ini sudah AKTIF. Nonaktifkan dulu.']);
                $seqId = (int) $w['id'];
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE email_sequences SET name=?, trigger_event=?, trigger_product_id=NULL, provider='mailketing', status='inactive', updated_at=NOW() WHERE id=?")
                    ->execute([$name, $trigger, $seqId]);
            } else {
                throw $e;
            }
        }
    }

    // Replace steps (hapus sends dulu → FK sends.step_id).
    $pdo->prepare('DELETE FROM email_sequence_sends WHERE step_id IN (SELECT id FROM email_sequence_steps WHERE sequence_id=?)')->execute([$seqId]);
    $pdo->prepare('DELETE FROM email_sequence_steps WHERE sequence_id=?')->execute([$seqId]);
    $insStep = $pdo->prepare('INSERT INTO email_sequence_steps (sequence_id, step_number, delay_days, subject, body, body_format, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($steps as $s) $insStep->execute([$seqId, $s['step_number'], $s['delay_days'], $s['subject'], $s['body'], $s['body_format'], $s['status']]);

    // Replace products (junction).
    $pdo->prepare('DELETE FROM email_sequence_products WHERE sequence_id=?')->execute([$seqId]);
    if ($products) {
        $insP = $pdo->prepare('INSERT IGNORE INTO email_sequence_products (sequence_id, product_id, kind) VALUES (?, ?, ?)');
        foreach ($products as $p) $insP->execute([$seqId, $p['product_id'], $p['kind']]);
    }

    $pdo->commit();
    hermesLog('email-sequence upsert ref=' . $ref . ' id=' . $seqId . ' steps=' . count($steps) . ' ' . ($existing ? 'update' : 'create'));

    $st = $pdo->prepare('SELECT id,external_ref,name,trigger_event,status,created_at,updated_at FROM email_sequences WHERE id=?');
    $st->execute([$seqId]);
    hermesApiSend(200, ['ok' => true, 'created' => !$existing, 'confirm_hash' => $confirmHash, 'sequence' => hermesSeqOutput($pdo, $st->fetch())]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    hermesLog('500 email-sequence gagal ref=' . $ref . ' : ' . $e->getMessage());
    hermesApiSend(500, ['ok' => false, 'error' => 'Gagal menyimpan email sequence.']);
}
