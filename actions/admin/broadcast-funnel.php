<?php
/**
 * Broadcast peserta Funnel Gate — START endpoint (AJAX/JSON).
 *
 * Dipanggil dari halaman /admin/funnel-gate/submissions. Membuat SATU campaign
 * broadcast per channel terpilih (email/whatsapp) dengan segmen 'funnel_submissions'
 * yang menarget peserta funnel tertentu (opsional difilter status + skor minimum),
 * lalu memulai pengiriman (atau menjadwalkan). Pengiriman sebenarnya dijalankan
 * oleh processBroadcastQueue (pump halaman + cron) — sama seperti broadcast biasa.
 *
 * METHOD: POST {csrf_token, form_id, channels[], status, min_score, subject, body,
 *               body_format, send_mode, scheduled_at}
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();

header('Content-Type: application/json; charset=utf-8');

function bfn_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Jumlah peserta kandidat (punya kontak pada channel ini) SEBELUM filter
 * skip-email-gagal / opt-out. Dedup per kontak — sama seperti penyusun penerima.
 * Dipakai agar pesan "0 penerima" bisa menjelaskan sebabnya (dilewati vs tak ada kontak).
 */
function bfn_candidateCount(PDO $pdo, string $channel, int $formId, string $status, int $minScore): int {
    $col = $channel === 'email' ? 'email' : 'whatsapp';
    $conds = ['form_id = ?']; $params = [$formId];
    if (in_array($status, ['submitted', 'approved', 'rejected', 'pending'], true)) { $conds[] = 'status = ?'; $params[] = $status; }
    else { $conds[] = "status <> 'draft'"; }
    if ($minScore > 0) { $conds[] = 'score >= ?'; $params[] = $minScore; }
    $conds[] = "$col IS NOT NULL AND TRIM($col) <> ''";
    $grp = $channel === 'email' ? 'LOWER(TRIM(email))' : 'TRIM(whatsapp)';
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM funnel_gate_submissions WHERE " . implode(' AND ', $conds) . " GROUP BY $grp) t");
        $s->execute($params);
        return (int) $s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

if (!staffCan('broadcast')) {
    bfn_json(['success' => false, 'message' => 'Kamu tidak memiliki akses untuk aksi ini.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bfn_json(['success' => false, 'message' => 'Metode tidak valid.'], 405);
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    bfn_json(['success' => false, 'message' => 'Token keamanan tidak valid.'], 403);
}

// Tangkap identitas admin SEBELUM melepas lock sesi.
$createdBy = (int) ($_SESSION['user_id'] ?? 0);

// Lepas lock file-sesi setelah auth/CSRF — penyusunan penerima bisa panjang & tak
// boleh menahan sesi (memblokir request lain dari admin yang sama).
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
@set_time_limit(0);

// ─── Input ────────────────────────────────────────────────────
$formId   = (int) ($_POST['form_id'] ?? 0);
$channels = (array) ($_POST['channels'] ?? []);
$channels = array_values(array_intersect(['email', 'whatsapp'], array_map('strval', $channels)));
$status   = trim((string) ($_POST['status'] ?? ''));          // '' = semua non-draft
$minScore = max(0, (int) ($_POST['min_score'] ?? 0));
$subject  = trim((string) ($_POST['subject'] ?? ''));
$body     = trim((string) ($_POST['body'] ?? ''));
$bodyFmt  = (($_POST['body_format'] ?? '') === 'html') ? 'html' : 'text';
$sendMode = (($_POST['send_mode'] ?? 'now') === 'scheduled') ? 'scheduled' : 'now';
$schedRaw = trim((string) ($_POST['scheduled_at'] ?? ''));
// Lewati email yang pernah gagal terkirim — sama seperti toggle broadcast biasa
// (default aktif). Dimatikan bila admin ingin tetap kirim ke alamat yang pernah gagal
// (mis. saat tes ke email sendiri yang sempat gagal).
$skipFailedEmails = (($_POST['skip_failed_emails'] ?? '1') === '1') ? 1 : 0;

// Status target: hanya status submission nyata; selain itu → '' (semua non-draft).
if (!in_array($status, ['submitted', 'approved', 'rejected', 'pending'], true)) {
    $status = '';
}

// ─── Validasi ─────────────────────────────────────────────────
if ($formId <= 0)       bfn_json(['success' => false, 'message' => 'Form funnel tidak valid.']);
if (empty($channels))   bfn_json(['success' => false, 'message' => 'Pilih minimal satu channel (Email / WhatsApp).']);
if ($body === '')       bfn_json(['success' => false, 'message' => 'Isi pesan tidak boleh kosong.']);
if (in_array('email', $channels, true) && $subject === '') {
    bfn_json(['success' => false, 'message' => 'Subjek email wajib diisi.']);
}

$pdo = getDB();

// Form harus ada
$fStmt = $pdo->prepare("SELECT id, title FROM funnel_gate_forms WHERE id = ? LIMIT 1");
$fStmt->execute([$formId]);
$form = $fStmt->fetch();
if (!$form) bfn_json(['success' => false, 'message' => 'Form funnel tidak ditemukan.']);

// Jadwal (opsional)
$campaignStatus = 'draft';
$scheduledTs    = null;
if ($sendMode === 'scheduled' && $schedRaw !== '') {
    $ts = strtotime($schedRaw);
    if ($ts && $ts > time()) {
        $campaignStatus = 'scheduled';
        $scheduledTs    = date('Y-m-d H:i:s', $ts);
    } else {
        bfn_json(['success' => false, 'message' => 'Waktu jadwal harus di masa depan.']);
    }
}

require_once __DIR__ . '/../../notifications/NotificationDispatcher.php';

$insert = $pdo->prepare(
    "INSERT INTO broadcast_campaigns
     (name, channel, provider, subject, body, body_format, target_segment,
      target_funnel_form_id, target_submission_status, target_min_score,
      skip_failed_emails, status, scheduled_at, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 'funnel_submissions', ?, ?, ?, ?, ?, ?, ?, NOW())"
);

$statusLabel = $status !== '' ? ucfirst($status) : 'Semua';
$campaigns = [];
$errors    = [];

foreach ($channels as $channel) {
    // Pilih provider aktif otomatis
    $provider = broadcastPickProvider($pdo, $channel);
    if ($provider === '') {
        $errors[] = $channel === 'email'
            ? 'Provider Email (Mailketing) belum aktif/terisi — channel Email dilewati.'
            : 'Belum ada provider WhatsApp aktif — channel WhatsApp dilewati.';
        continue;
    }
    // Defense-in-depth: WA harus benar-benar enabled
    if ($channel === 'whatsapp' && getSetting($provider . '_enabled', '0') !== '1') {
        $errors[] = 'Provider WhatsApp "' . $provider . '" tidak aktif — channel WhatsApp dilewati.';
        continue;
    }
    if (!NotificationDispatcher::getAdapterPublic($provider)) {
        $errors[] = 'Provider "' . $provider . '" tidak dikenali — channel ' . $channel . ' dilewati.';
        continue;
    }

    $chLabel   = $channel === 'email' ? 'Email' : 'WhatsApp';
    $name      = 'Funnel: ' . mb_substr((string) $form['title'], 0, 80) . ' — ' . $chLabel . ' — ' . date('d/m H:i');
    $subjForCh = $channel === 'email' ? ($subject ?: null) : null;
    $fmtForCh  = $channel === 'email' ? $bodyFmt : 'text';
    $skipFail  = $channel === 'email' ? $skipFailedEmails : 0;

    try {
        $insert->execute([
            $name, $channel, $provider, $subjForCh, $body, $fmtForCh,
            $formId, ($status !== '' ? $status : null), ($minScore > 0 ? $minScore : null),
            $skipFail, $campaignStatus, $scheduledTs, $createdBy,
        ]);
        $cid = (int) $pdo->lastInsertId();

        $valid = null; $total = null;
        if ($campaignStatus === 'draft') {
            // Kirim sekarang → susun penerima + tandai 'sending'. Pump dilakukan frontend.
            try {
                $res   = startBroadcastCampaign($pdo, array_merge(
                    ['id' => $cid, 'channel' => $channel, 'target_segment' => 'funnel_submissions',
                     'target_funnel_form_id' => $formId, 'target_submission_status' => ($status !== '' ? $status : null),
                     'target_min_score' => ($minScore > 0 ? $minScore : null), 'skip_failed_emails' => $skipFail,
                     'exclude_product_ids' => null],
                    []
                ));
                $valid = (int) ($res['valid'] ?? 0);
                $total = (int) ($res['total'] ?? 0);
                if ($valid <= 0) {
                    // Tak ada penerima valid → kembalikan draft agar tak nyangkut 'sending'
                    // dan JANGAN senyap: jelaskan sebabnya per channel (jangan masukkan ke
                    // $campaigns, karena tak ada yang dikirim di channel ini).
                    $pdo->prepare("UPDATE broadcast_campaigns SET status='draft' WHERE id=?")->execute([$cid]);
                    $cand = bfn_candidateCount($pdo, $channel, $formId, $status, $minScore);
                    if ($channel === 'email') {
                        $errors[] = $cand > 0
                            ? 'Email: ' . $cand . ' peserta punya email, tapi semuanya dilewati (pernah gagal terkirim / berhenti langganan). Matikan "Lewati email yang pernah gagal" untuk tetap kirim.'
                            : 'Email: tidak ada peserta dengan alamat email pada filter ini.';
                    } else {
                        $errors[] = 'WhatsApp: tidak ada peserta dengan nomor WhatsApp pada filter ini.';
                    }
                    continue;
                }
            } catch (Throwable $e) {
                error_log('broadcast-funnel start error: ' . $e->getMessage());
                $pdo->prepare("UPDATE broadcast_campaigns SET status='draft' WHERE id=?")->execute([$cid]);
                $errors[] = 'Gagal menyiapkan antrian untuk channel ' . $chLabel . '.';
                continue;
            }
        }

        $campaigns[] = [
            'id'       => $cid,
            'channel'  => $channel,
            'provider' => $provider,
            'valid'    => $valid,
            'total'    => $total,
            'scheduled' => $campaignStatus === 'scheduled',
        ];
    } catch (Throwable $e) {
        error_log('broadcast-funnel insert error: ' . $e->getMessage());
        $errors[] = 'Gagal membuat campaign untuk channel ' . $chLabel . '.';
    }
}

if (empty($campaigns)) {
    bfn_json(['success' => false, 'message' => implode(' ', $errors ?: ['Tidak ada campaign yang dibuat.'])]);
}

// Peringatan bila ada penerima 0 di salah satu channel yang dikirim sekarang.
$startable = array_values(array_filter($campaigns, fn($c) => !$c['scheduled'] && (int) $c['valid'] > 0));

if (function_exists('logActivity')) {
    logActivity('admin', 'broadcast.funnel', 'funnel_gate_forms', $formId,
        'Broadcast peserta funnel "' . $form['title'] . '" (' . implode('+', $channels) . ', status: ' . $statusLabel . ').');
}

bfn_json([
    'success'    => true,
    'scheduled'  => $campaignStatus === 'scheduled',
    'campaigns'  => $campaigns,
    'startable'  => array_map(fn($c) => ['id' => $c['id'], 'channel' => $c['channel'], 'valid' => $c['valid']], $startable),
    'errors'     => $errors,
]);
