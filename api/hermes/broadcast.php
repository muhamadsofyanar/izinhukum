<?php
// ════════════════════════════════════════════════════════════════════════
// Hermes Agent — Ingest Broadcast (konten pemberitahuan siap-kirim)
//   GET  /api/hermes/broadcast            → daftar campaign (draft ingest)
//   GET  /api/hermes/broadcast?ref=<x>    → satu campaign (by external_ref)
//   POST /api/hermes/broadcast            → buat/perbarui (upsert by external_ref)
//
// Prinsip tergembok (konsisten ingest Hermes lain):
//   - Campaign masuk SELALU status 'draft' (kirim/jadwal tetap admin via /admin/broadcast).
//     Field 'status', 'provider', 'scheduled_at', 'send_mode' tidak diterima.
//   - Idempoten via external_ref UNIQUE (retry Hermes aman).
//   - TIDAK boleh mengubah campaign yang sudah lepas-draft (sending/scheduled/paused/sent/
//     failed) — admin sudah memproses, jangan ditimpa.
//   - dry_run: validasi + preview + confirm_hash, TANPA menulis.
//   - Hermes HANYA menulis KONTEN (nama/subjek/isi/segmen). TAK pernah mengirim: provider
//     dipilih server (aktif) hanya sebagai nilai awal; pengiriman tetap tombol admin.
//   - UI sama persis: campaign muncul di /admin/broadcast sebagai draft, diedit & dikirim
//     lewat UI broadcast yang sudah ada.
// Auth: Bearer token + hermes_enabled (tier master; ingest draft tak butuh manage).
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/hermes.php';
require_once __DIR__ . '/../../helpers/broadcast.php';

// Segmen yang boleh diisi Hermes — subset aman yang tak butuh referensi master rumit
// (membership level / lead magnet / funnel form). Admin bebas mengganti segmen lain saat
// meninjau draft di /admin/broadcast.
const HERMES_BCAST_SEGMENTS = ['all_users', 'active_members', 'leads_all', 'product_buyers', 'email_openers', 'email_clickers'];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    hermesApiSend(405, ['ok' => false, 'error' => 'Metode tidak diizinkan. Gunakan GET atau POST.']);
}
if ($method === 'POST' && !str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    hermesApiSend(415, ['ok' => false, 'error' => 'Content-Type harus application/json.']);
}
hermesApiAuthorize(false); // master tier (draft ingest)
$pdo = getDB();

/** Serialisasi satu campaign untuk readback. */
function hermesBcastOutput(array $row): array
{
    return [
        'id'            => (int) $row['id'],
        'external_ref'  => $row['external_ref'],
        'name'          => $row['name'],
        'channel'       => $row['channel'],
        'subject'       => $row['subject'],
        'body'          => $row['body'],
        'body_format'   => $row['body_format'],
        'target_segment'=> $row['target_segment'],
        'target_product_ids' => $row['target_product_ids'] !== null && $row['target_product_ids'] !== ''
            ? array_map('intval', explode(',', (string) $row['target_product_ids'])) : [],
        'exclude_product_ids' => $row['exclude_product_ids'] !== null && $row['exclude_product_ids'] !== ''
            ? array_map('intval', explode(',', (string) $row['exclude_product_ids'])) : [],
        'skip_failed_emails' => (int) $row['skip_failed_emails'] === 1,
        'engagement_days' => $row['engagement_days'] !== null ? (int) $row['engagement_days'] : null,
        'provider'      => $row['provider'],
        'status'        => $row['status'],
        'created_at'    => $row['created_at'],
    ];
}

const HERMES_BCAST_COLS = 'id, external_ref, name, channel, subject, body, body_format, target_segment, target_product_ids, exclude_product_ids, skip_failed_emails, engagement_days, provider, status, created_at';

// ─── GET: readback ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $ref = trim((string) ($_GET['ref'] ?? ''));
    if ($ref !== '') {
        $st = $pdo->prepare('SELECT ' . HERMES_BCAST_COLS . ' FROM broadcast_campaigns WHERE external_ref = ? LIMIT 1');
        $st->execute([$ref]);
        $row = $st->fetch();
        if (!$row) hermesApiSend(404, ['ok' => false, 'error' => 'Campaign tidak ditemukan untuk external_ref ini.']);
        hermesApiSend(200, ['ok' => true, 'broadcast' => hermesBcastOutput($row)]);
    }
    // Hanya tampilkan campaign yang BERASAL dari Hermes (punya external_ref) agar readback
    // tak membocorkan isi broadcast yang disusun admin manual.
    $rows = $pdo->query('SELECT ' . HERMES_BCAST_COLS . ' FROM broadcast_campaigns WHERE external_ref IS NOT NULL ORDER BY id DESC')->fetchAll();
    hermesApiSend(200, ['ok' => true, 'broadcasts' => array_map('hermesBcastOutput', $rows)]);
}

// ─── POST: validasi ──────────────────────────────────────────────────────
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) hermesApiSend(400, ['ok' => false, 'error' => 'Body JSON tidak valid.']);
$allowed = ['external_ref', 'name', 'channel', 'subject', 'body', 'body_format', 'target_segment',
    'target_product_ids', 'exclude_product_ids', 'skip_failed_emails', 'engagement_days', 'dry_run', 'confirm_hash'];
$unknown = array_values(array_diff(array_keys($in), $allowed));
if ($unknown) hermesApiSend(400, ['ok' => false, 'error' => 'Field tidak diizinkan: ' . implode(', ', $unknown) . '. (status selalu draft, provider/jadwal diatur admin.)']);

$ref = trim((string) ($in['external_ref'] ?? ''));
if ($ref === '' || mb_strlen($ref) > 191) hermesApiSend(422, ['ok' => false, 'error' => 'external_ref wajib dan maksimal 191 karakter.']);

$name = trim((string) ($in['name'] ?? ''));
if ($name === '' || mb_strlen($name) > 150) hermesApiSend(422, ['ok' => false, 'error' => 'name wajib dan maksimal 150 karakter.']);

$channel = (string) ($in['channel'] ?? '');
if (!in_array($channel, ['email', 'whatsapp'], true)) hermesApiSend(422, ['ok' => false, 'error' => "channel wajib 'email' atau 'whatsapp'."]);

// subject: hanya relevan untuk email. WA → diabaikan (null).
$subject = trim(str_replace(["\r", "\n"], '', (string) ($in['subject'] ?? '')));
if ($channel === 'email') {
    if (mb_strlen($subject) > 200) hermesApiSend(422, ['ok' => false, 'error' => 'subject maksimal 200 karakter.']);
} else {
    $subject = '';
}

$body = (string) ($in['body'] ?? '');
if (trim($body) === '' || strlen($body) > 60000) hermesApiSend(422, ['ok' => false, 'error' => 'body wajib dan maksimal 60.000 karakter.']);

// body_format: html hanya untuk email; WA selalu text.
$bodyFormat = ($channel === 'email' && (string) ($in['body_format'] ?? '') === 'html') ? 'html' : 'text';

// target_segment (opsional, default all_users dari subset aman).
$segment = (string) ($in['target_segment'] ?? 'all_users');
if ($segment === '') $segment = 'all_users';
if (!in_array($segment, HERMES_BCAST_SEGMENTS, true)) {
    hermesApiSend(422, ['ok' => false, 'error' => 'target_segment tidak didukung Hermes. Pilihan: ' . implode(', ', HERMES_BCAST_SEGMENTS) . '. (Segmen lain diatur admin di UI broadcast.)']);
}
// Segmen engagement email berbasis open/klik hanya untuk channel email.
if (in_array($segment, ['email_openers', 'email_clickers'], true) && $channel !== 'email') {
    hermesApiSend(422, ['ok' => false, 'error' => 'Segmen email_openers/email_clickers hanya untuk channel email.']);
}

/** Validasi daftar id produk → produk 'active'. Kembalikan array int unik terurut. */
function hermesBcastProductIds(PDO $pdo, $raw, string $field): array
{
    if (!is_array($raw)) hermesApiSend(422, ['ok' => false, 'error' => $field . ' wajib array id produk.']);
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($v) => $v > 0)));
    if (count($ids) > 100) hermesApiSend(422, ['ok' => false, 'error' => $field . ' maksimal 100 produk.']);
    foreach ($ids as $pid) {
        $chk = $pdo->prepare("SELECT id FROM products WHERE id = ? AND status = 'active' LIMIT 1");
        $chk->execute([$pid]);
        if (!$chk->fetchColumn()) hermesApiSend(422, ['ok' => false, 'error' => $field . ': product_id ' . $pid . ' tidak ditemukan atau tidak active.']);
    }
    sort($ids);
    return $ids;
}

// target_product_ids: wajib untuk product_buyers, diabaikan untuk segmen lain.
$targetProductIds = [];
if ($segment === 'product_buyers') {
    if (!isset($in['target_product_ids'])) hermesApiSend(422, ['ok' => false, 'error' => 'target_product_ids wajib untuk segmen product_buyers.']);
    $targetProductIds = hermesBcastProductIds($pdo, $in['target_product_ids'], 'target_product_ids');
    if (!$targetProductIds) hermesApiSend(422, ['ok' => false, 'error' => 'target_product_ids tidak boleh kosong untuk product_buyers.']);
}

// exclude_product_ids: opsional (kecualikan pembeli produk tsb) — untuk semua segmen.
$excludeProductIds = [];
if (isset($in['exclude_product_ids'])) {
    $excludeProductIds = hermesBcastProductIds($pdo, $in['exclude_product_ids'], 'exclude_product_ids');
}

// skip_failed_emails: hanya email.
$skipFailed = ($channel === 'email' && !empty($in['skip_failed_emails'])) ? 1 : 0;

// engagement_days: hanya segmen openers/clickers, nilai 30/60/90 (lainnya = NULL).
$engagementDays = null;
if (in_array($segment, ['email_openers', 'email_clickers'], true)) {
    $ed = (int) ($in['engagement_days'] ?? 0);
    $engagementDays = in_array($ed, [30, 60, 90], true) ? $ed : null;
}

$targetProductCsv = $targetProductIds ? implode(',', $targetProductIds) : null;
$excludeCsv       = $excludeProductIds ? implode(',', $excludeProductIds) : null;

// confirm_hash: hash kanonik payload (deterministik) — Hermes bisa verifikasi preview==apply.
$canon = json_encode([
    'name' => $name, 'channel' => $channel, 'subject' => $subject, 'body' => $body,
    'body_format' => $bodyFormat, 'target_segment' => $segment,
    'target_product_ids' => $targetProductIds, 'exclude_product_ids' => $excludeProductIds,
    'skip_failed_emails' => $skipFailed, 'engagement_days' => $engagementDays,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$confirmHash = hash('sha256', (string) $canon);

$preview = [
    'external_ref' => $ref, 'name' => $name, 'channel' => $channel, 'subject' => $subject,
    'body_format' => $bodyFormat, 'target_segment' => $segment,
    'target_product_ids' => $targetProductIds, 'exclude_product_ids' => $excludeProductIds,
    'skip_failed_emails' => (bool) $skipFailed, 'engagement_days' => $engagementDays,
    'status' => 'draft',
];

$dry = !empty($in['dry_run']);
if ($dry) {
    hermesApiSend(200, ['ok' => true, 'dry_run' => true, 'confirm_hash' => $confirmHash] + $preview);
}
// Bila client mengirim confirm_hash, wajib cocok.
if (isset($in['confirm_hash']) && !hash_equals($confirmHash, (string) $in['confirm_hash'])) {
    hermesApiSend(409, ['ok' => false, 'error' => 'confirm_hash tidak cocok dengan payload. Jalankan dry_run lagi.', 'confirm_hash' => $confirmHash]);
}

// Provider awal: pilih yang aktif untuk channel (boleh kosong → admin pilih saat kirim).
$provider = broadcastPickProvider($pdo, $channel);

// ─── Apply (upsert by external_ref) ──────────────────────────────────────
try {
    $ex = $pdo->prepare('SELECT id, status FROM broadcast_campaigns WHERE external_ref = ? LIMIT 1');
    $ex->execute([$ref]);
    $existing = $ex->fetch();
    if ($existing && $existing['status'] !== 'draft') {
        hermesApiSend(409, ['ok' => false, 'error' => 'Campaign ini sudah diproses admin (status ' . $existing['status'] . '). Tak bisa diubah via Hermes.']);
    }

    if ($existing) {
        $cid = (int) $existing['id'];
        $pdo->prepare(
            "UPDATE broadcast_campaigns SET
                name=?, channel=?, provider=?, subject=?, body=?, body_format=?, target_segment=?,
                target_membership_level_id=NULL, target_lead_magnet_id=NULL, target_product_ids=?,
                engagement_days=?, exclude_product_ids=?, skip_failed_emails=?, scheduled_at=NULL
             WHERE id=? AND status='draft'"
        )->execute([$name, $channel, $provider, $subject ?: null, $body, $bodyFormat, $segment,
            $targetProductCsv, $engagementDays, $excludeCsv, $skipFailed, $cid]);
    } else {
        try {
            $pdo->prepare(
                "INSERT INTO broadcast_campaigns
                 (name, channel, provider, subject, body, body_format, target_segment,
                  target_product_ids, engagement_days, exclude_product_ids, skip_failed_emails,
                  status, created_by, created_at, external_ref)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NULL, NOW(), ?)"
            )->execute([$name, $channel, $provider, $subject ?: null, $body, $bodyFormat, $segment,
                $targetProductCsv, $engagementDays, $excludeCsv, $skipFailed, $ref]);
            $cid = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Race: external_ref ditulis bersamaan → ambil pemenang lalu update (bila masih draft).
            if ($e->getCode() === '23000') {
                $r2 = $pdo->prepare('SELECT id, status FROM broadcast_campaigns WHERE external_ref = ? LIMIT 1');
                $r2->execute([$ref]);
                $w = $r2->fetch();
                if (!$w) throw $e;
                if ($w['status'] !== 'draft') hermesApiSend(409, ['ok' => false, 'error' => 'Campaign ini sudah diproses admin (status ' . $w['status'] . ').']);
                $cid = (int) $w['id'];
                $pdo->prepare(
                    "UPDATE broadcast_campaigns SET
                        name=?, channel=?, provider=?, subject=?, body=?, body_format=?, target_segment=?,
                        target_membership_level_id=NULL, target_lead_magnet_id=NULL, target_product_ids=?,
                        engagement_days=?, exclude_product_ids=?, skip_failed_emails=?, scheduled_at=NULL
                     WHERE id=? AND status='draft'"
                )->execute([$name, $channel, $provider, $subject ?: null, $body, $bodyFormat, $segment,
                    $targetProductCsv, $engagementDays, $excludeCsv, $skipFailed, $cid]);
            } else {
                throw $e;
            }
        }
    }

    hermesLog('broadcast upsert ref=' . $ref . ' id=' . $cid . ' channel=' . $channel . ' seg=' . $segment . ' ' . ($existing ? 'update' : 'create'));

    $st = $pdo->prepare('SELECT ' . HERMES_BCAST_COLS . ' FROM broadcast_campaigns WHERE id=?');
    $st->execute([$cid]);
    hermesApiSend(200, ['ok' => true, 'created' => !$existing, 'confirm_hash' => $confirmHash, 'broadcast' => hermesBcastOutput($st->fetch())]);
} catch (Throwable $e) {
    hermesLog('500 broadcast gagal ref=' . $ref . ' : ' . $e->getMessage());
    hermesApiSend(500, ['ok' => false, 'error' => 'Gagal menyimpan broadcast.']);
}
