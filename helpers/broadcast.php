<?php
// ─── Broadcast + Open/Click Tracking ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

// ── Open/Click tracking (pixel + link-wrap milik Averion sendiri) ──
// Mailketing webhook open/click tak membawa campaign_id & bercampur semua email,
// jadi kita ukur sendiri: token ber-HMAC per penerima. Token klik menandatangani
// URL tujuan juga → endpoint hanya redirect ke URL yang KITA tanda tangani
// (mencegah open-redirect / pemalsuan).
function broadcastTrackSecret(): string
{
    static $mem = null;
    if ($mem !== null) return $mem;
    $s = (string) getSetting('broadcast_track_secret', '');
    if (strlen($s) < 16) { $s = bin2hex(random_bytes(24)); setSetting('broadcast_track_secret', $s, 'broadcast'); }
    return $mem = $s;
}

function broadcastOpenToken(int $rid): string
{
    return $rid . '.' . substr(hash_hmac('sha256', 'o|' . $rid, broadcastTrackSecret()), 0, 20);
}

function broadcastClickToken(int $rid, string $url): string
{
    return $rid . '.' . substr(hash_hmac('sha256', 'c|' . $rid . '|' . $url, broadcastTrackSecret()), 0, 20);
}

/** Verifikasi token; return recipient_id (>0) bila sah, else 0. $url=null utk open. */
function broadcastVerifyToken(string $token, ?string $url): int
{
    if (!str_contains($token, '.')) return 0;
    [$ridRaw, $sig] = explode('.', $token, 2);
    $rid = (int) $ridRaw;
    if ($rid <= 0 || $sig === '') return 0;
    $scope  = $url === null ? ('o|' . $rid) : ('c|' . $rid . '|' . $url);
    $expect = substr(hash_hmac('sha256', $scope, broadcastTrackSecret()), 0, 20);
    return hash_equals($expect, $sig) ? $rid : 0;
}

/**
 * Sisipkan tracking ke HTML broadcast untuk satu penerima: bungkus link http(s)
 * di dalam <a href> → /bt/c/{token}?u=… (URL ditandatangani) + tambah pixel open
 * 1×1 di akhir body. Hanya untuk broadcast HTML. mailto/tel & href non-http dilewati.
 */
function broadcastInjectTracking(string $html, int $rid): string
{
    $base = baseUrl();
    $html = preg_replace_callback(
        '/(<a\b[^>]*?\bhref=")(https?:\/\/[^"]+)(")/i',
        function ($m) use ($base, $rid) {
            $url = $m[2];
            return $m[1] . $base . '/bt/c/' . broadcastClickToken($rid, $url) . '?u=' . rawurlencode($url) . $m[3];
        },
        $html
    );
    $pixel = '<img src="' . $base . '/bt/o/' . broadcastOpenToken($rid) . '" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px" />';
    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
    } else {
        $html .= $pixel;
    }
    return $html;
}

// ── Unsubscribe (berhenti berlangganan email promosi) ──
// Token ber-HMAC atas alamat email (reuse rahasia tracking, per-install). Link di
// footer setiap broadcast email → halaman /unsubscribe/{token} (tanpa login).
function unsubscribeToken(string $email): string
{
    $email   = strtolower(trim($email));
    $payload = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    $sig     = substr(hash_hmac('sha256', 'u|' . $email, broadcastTrackSecret()), 0, 24);
    return $payload . '.' . $sig;
}

/** Verifikasi token unsubscribe → email (lowercase) bila sah, else '' */
function unsubscribeVerify(string $token): string
{
    if (!str_contains($token, '.')) return '';
    [$payload, $sig] = explode('.', $token, 2);
    $email = base64_decode(strtr($payload, '-_', '+/'), true);
    if ($email === false || trim($email) === '') return '';
    $email  = strtolower(trim($email));
    $expect = substr(hash_hmac('sha256', 'u|' . $email, broadcastTrackSecret()), 0, 24);
    return hash_equals($expect, (string) $sig) ? $email : '';
}

/** URL unsubscribe penuh untuk sebuah email. */
function unsubscribeUrl(string $email): string
{
    return baseUrl() . '/unsubscribe/' . unsubscribeToken($email);
}

/**
 * Sisipkan footer "Berhenti berlangganan" ke body email broadcast (satu penerima).
 * Selalu HTML (broadcast teks pun dikirim sebagai HTML via nl2br). Ditaruh SETELAH
 * broadcastInjectTracking supaya link unsubscribe TIDAK ikut dibungkus click-tracking.
 */
function broadcastAppendUnsubscribe(string $body, string $email): string
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return $body;
    $url    = e(unsubscribeUrl($email));
    $footer = '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #e5e7eb;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9ca3af;text-align:center;line-height:1.5">'
            . 'Tidak ingin menerima email promosi lagi? '
            . '<a href="' . $url . '" style="color:#6b7280;text-decoration:underline">Berhenti berlangganan</a>.'
            . '</div>';
    if (stripos($body, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $footer . '</body>', $body, 1);
    }
    return $body . $footer;
}

/**
 * Daftar provider broadcast yang AKTIF per channel (sumber tunggal — dipakai halaman
 * broadcast & broadcast funnel). Email: Mailketing (bila api key terisi). WhatsApp:
 * provider yang {provider}_enabled='1' DAN {provider}_api_key terisi.
 * Return: ['email' => [['value','label'],…], 'whatsapp' => [...]].
 */
function broadcastActiveProviders(PDO $pdo): array
{
    $out = ['email' => [], 'whatsapp' => []];
    try {
        $mkKey = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='mailketing_api_key' LIMIT 1")->fetchColumn();
        if (!empty($mkKey)) $out['email'][] = ['value' => 'mailketing', 'label' => 'Mailketing'];

        // Urutan = prioritas (averionrelay paling buntut), selaras dengan
        // NotificationDispatcher::WA_PROVIDER_PRIORITY. Averion Relay perlu api_key
        // terisi untuk kirim NYATA (tanpa key = mode simulasi) → ikut syarat sama.
        $waLabels = ['fonte' => 'Fonte', 'onesender' => 'OneSender', 'starsender' => 'StarSender', 'dripsender' => 'DripSender', 'kirimdev' => 'Kirimdev', 'averionrelay' => 'Averion Relay'];
        $keyStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        foreach ($waLabels as $provider => $label) {
            $keyStmt->execute([$provider . '_api_key']);
            $apiKey = $keyStmt->fetchColumn();
            $keyStmt->execute([$provider . '_enabled']);
            $isEnabled = $keyStmt->fetchColumn();
            if (!empty($apiKey) && $isEnabled === '1') {
                $out['whatsapp'][] = ['value' => $provider, 'label' => $label];
            }
        }
    } catch (Throwable $e) {
        error_log('broadcastActiveProviders error: ' . $e->getMessage());
    }
    return $out;
}

/** Pilih satu provider aktif untuk channel ($channel = 'email'|'whatsapp'). '' bila tak ada. */
function broadcastPickProvider(PDO $pdo, string $channel): string
{
    $list = broadcastActiveProviders($pdo);
    return $list[$channel][0]['value'] ?? '';
}

/**
 * Susun baris penerima broadcast dari segmen + filter kecualikan-pembeli-produk
 * & skip-email-gagal. Return array baris: [campaign_id, user_id, lead_id, recipient, status, error].
 */
function broadcastRecipientRows(PDO $pdo, array $campaign): array
{
    $channel = $campaign['channel'];
    $kind    = 'user';
    // Segmen engagement email (openers/clickers) gabung user+lead → tiap baris
    // membawa user_id/lead_id sendiri, tidak mengikuti $kind tunggal.
    $isEngagement = in_array($campaign['target_segment'], ['email_openers', 'email_clickers'], true);
    // Segmen yang tiap barisnya membawa user_id/lead_id sendiri (bukan ikut $kind
    // tunggal): engagement email + peserta funnel gate.
    $selfKeyed = $isEngagement || $campaign['target_segment'] === 'funnel_submissions';
    $recipients = [];
    switch ($campaign['target_segment']) {
        case 'all_users':
            $recipients = $pdo->query("SELECT id, name, email, phone FROM users WHERE role='user' AND status='active'")->fetchAll();
            break;
        case 'active_members':
            $recipients = $pdo->query("SELECT DISTINCT u.id, u.name, u.email, u.phone FROM users u JOIN member_access ma ON ma.user_id=u.id WHERE ma.status='active' AND u.status='active'")->fetchAll();
            break;
        case 'specific_level':
            $s = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE membership_level_id=? AND status='active'");
            $s->execute([$campaign['target_membership_level_id']]);
            $recipients = $s->fetchAll();
            break;
        case 'leads_all':
            $kind = 'lead';
            $recipients = $pdo->query("SELECT id, name, email, whatsapp AS phone FROM leads ORDER BY id DESC")->fetchAll();
            break;
        case 'leads_by_magnet':
            $kind = 'lead';
            $s = $pdo->prepare("SELECT id, name, email, whatsapp AS phone FROM leads WHERE lead_magnet_id=? ORDER BY id DESC");
            $s->execute([(int) $campaign['target_lead_magnet_id']]);
            $recipients = $s->fetchAll();
            break;
        case 'product_buyers':
            // Pembeli produk terpilih (punya order paid berisi produk itu). Segmen user.
            $tids = array_values(array_filter(array_map('intval', explode(',', (string) ($campaign['target_product_ids'] ?? ''))), fn($v) => $v > 0));
            if ($tids) {
                $in = implode(',', $tids);
                $recipients = $pdo->query(
                    "SELECT DISTINCT u.id, u.name, u.email, u.phone
                     FROM users u
                     JOIN orders o ON o.user_id = u.id
                     JOIN order_items oi ON oi.order_id = o.id
                     WHERE o.status = 'paid' AND oi.product_id IN ($in) AND u.status = 'active'"
                )->fetchAll();
            }
            break;
        case 'email_openers':
        case 'email_clickers':
            // Alamat yang pernah membuka / mengklik broadcast email HTML
            // (broadcast_recipients.opened_at / clicked_at). Dedup per-email,
            // gabung user + leads. Opsional dibatasi N hari terakhir (engagement_days).
            $col  = $campaign['target_segment'] === 'email_clickers' ? 'clicked_at' : 'opened_at';
            $days = (int) ($campaign['engagement_days'] ?? 0);
            $where  = "br.recipient <> '' AND br.$col IS NOT NULL";
            $params = [];
            if ($days > 0) {
                $where   .= " AND br.$col >= (NOW() - INTERVAL ? DAY)";
                $params[] = $days;
            }
            $s = $pdo->prepare(
                "SELECT br.recipient AS email,
                        MAX(br.user_id) AS user_id,
                        MAX(br.lead_id) AS lead_id,
                        COALESCE(MAX(u.name), MAX(l.name), '') AS name
                   FROM broadcast_recipients br
                   LEFT JOIN users u ON u.id = br.user_id
                   LEFT JOIN leads l ON l.id = br.lead_id
                  WHERE $where
                  GROUP BY br.recipient"
            );
            $s->execute($params);
            $recipients = $s->fetchAll();
            break;
        case 'funnel_submissions':
            // Peserta yang mengisi funnel gate tertentu. Dedup per kontak (email utk
            // channel email, whatsapp utk WA). user_id dibawa per-baris agar
            // {{name}} terisi untuk peserta yang punya akun member.
            $formId = (int) ($campaign['target_funnel_form_id'] ?? 0);
            if ($formId > 0) {
                $conds  = ['form_id = ?'];
                $params = [$formId];
                $st = trim((string) ($campaign['target_submission_status'] ?? ''));
                if (in_array($st, ['submitted', 'approved', 'rejected', 'pending'], true)) {
                    $conds[] = 'status = ?';
                    $params[] = $st;
                } else {
                    $conds[] = "status <> 'draft'"; // 'semua' = semua submission nyata (tanpa draft)
                }
                $minScore = (int) ($campaign['target_min_score'] ?? 0);
                if ($minScore > 0) { $conds[] = 'score >= ?'; $params[] = $minScore; }
                $contactCol = $channel === 'email' ? 'email' : 'whatsapp';
                $conds[] = "$contactCol IS NOT NULL AND TRIM($contactCol) <> ''";
                $where    = implode(' AND ', $conds);
                $groupKey = $channel === 'email' ? 'LOWER(TRIM(email))' : 'TRIM(whatsapp)';
                $s = $pdo->prepare(
                    "SELECT MAX(email) AS email, MAX(whatsapp) AS phone, MAX(user_id) AS user_id, NULL AS lead_id
                       FROM funnel_gate_submissions
                      WHERE $where
                      GROUP BY $groupKey"
                );
                $s->execute($params);
                $recipients = $s->fetchAll();
            }
            break;
    }

    // Kecualikan pembeli produk (segmen user — termasuk bagian user dari engagement).
    $excludedUsers = [];
    $excludeIds = array_values(array_filter(array_map('intval', explode(',', (string) ($campaign['exclude_product_ids'] ?? ''))), fn($v) => $v > 0));
    if ($excludeIds && ($kind === 'user' || $selfKeyed)) {
        $in = implode(',', $excludeIds);
        foreach ($pdo->query("SELECT DISTINCT o.user_id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.status='paid' AND oi.product_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $excludedUsers[(int) $uid] = 1;
        }
    }

    // Skip email yang sebelumnya gagal (channel email) — dari broadcast lama & notification_logs.
    $failedEmails = [];
    if (!empty($campaign['skip_failed_emails']) && $channel === 'email') {
        foreach ($pdo->query("SELECT DISTINCT recipient FROM broadcast_recipients WHERE status='failed' AND recipient<>''")->fetchAll(PDO::FETCH_COLUMN) as $e) { $failedEmails[strtolower(trim($e))] = 1; }
        foreach ($pdo->query("SELECT DISTINCT recipient FROM notification_logs WHERE channel='email' AND status='failed' AND recipient<>''")->fetchAll(PDO::FETCH_COLUMN) as $e) { $failedEmails[strtolower(trim($e))] = 1; }
    }

    // Berhenti berlangganan email promosi (users.email_opt_out) — channel email saja.
    // Broadcast = email promosi → hormati opt-out. Email transaksional tak lewat sini.
    $optedOut = [];
    if ($channel === 'email') {
        if (function_exists('ensureUserEmailOptOutColumn')) ensureUserEmailOptOutColumn($pdo);
        try {
            foreach ($pdo->query("SELECT email FROM users WHERE email_opt_out = 1 AND email <> ''")->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $optedOut[strtolower(trim($e))] = 1;
            }
        } catch (Throwable $e) { /* kolom belum ada → anggap tak ada opt-out */ }
    }

    $cid = (int) $campaign['id'];
    $rows = [];
    foreach ($recipients as $r) {
        if ($selfKeyed) {
            $uid = !empty($r['user_id']) ? (int) $r['user_id'] : null;
            $lid = !empty($r['lead_id']) ? (int) $r['lead_id'] : null;
        } else {
            $uid = $kind === 'user' ? (int) $r['id'] : null;
            $lid = $kind === 'lead' ? (int) $r['id'] : null;
        }
        if ($uid !== null && isset($excludedUsers[$uid])) continue;              // pembeli produk dikecualikan
        $field = trim((string) ($channel === 'email' ? ($r['email'] ?? '') : ($r['phone'] ?? '')));
        if ($channel === 'email' && $field !== '' && isset($failedEmails[strtolower($field)])) continue; // email gagal → skip
        if ($channel === 'email' && $field !== '' && isset($optedOut[strtolower($field)])) continue;     // berhenti berlangganan → skip
        if ($field === '') {
            $rows[] = [$cid, $uid, $lid, '', 'failed', 'Alamat tujuan kosong'];
        } else {
            $rows[] = [$cid, $uid, $lid, mb_substr($field, 0, 150), 'pending', null];
        }
    }
    return $rows;
}

/** Mulai campaign: bangun ulang worklist penerima + tandai 'sending'. Return [total, valid]. */
function startBroadcastCampaign(PDO $pdo, array $campaign): array
{
    $cid  = (int) $campaign['id'];
    $rows = broadcastRecipientRows($pdo, $campaign);
    $valid = 0; foreach ($rows as $r) { if ($r[4] === 'pending') $valid++; }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM broadcast_recipients WHERE campaign_id=?")->execute([$cid]);
        foreach (array_chunk($rows, 200) as $chunk) {
            $ph = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, NOW())'));
            $pdo->prepare("INSERT INTO broadcast_recipients (campaign_id, user_id, lead_id, recipient, status, error_message, created_at) VALUES $ph")
                ->execute(array_merge(...$chunk));
        }
        $pdo->prepare("UPDATE broadcast_campaigns SET status='sending', total_recipients=?, total_sent=0, total_failed=0, sent_at=NULL WHERE id=?")
            ->execute([count($rows), $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['total' => count($rows), 'valid' => $valid];
}

/** Hitung penerima per status. */
function broadcastCounts(PDO $pdo, int $cid): array
{
    $s = $pdo->prepare("SELECT SUM(status='sent') sent, SUM(status='failed') failed, SUM(status='pending') pending, COUNT(*) total FROM broadcast_recipients WHERE campaign_id=?");
    $s->execute([$cid]);
    $r = $s->fetch() ?: [];
    return ['sent' => (int) ($r['sent'] ?? 0), 'failed' => (int) ($r['failed'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0), 'total' => (int) ($r['total'] ?? 0)];
}

/** Update total + status campaign; 'sent' bila tak ada pending. */
function broadcastFinalize(PDO $pdo, int $cid, string $name): array
{
    $c = broadcastCounts($pdo, $cid);
    $status = $c['pending'] > 0 ? 'sending' : 'sent';
    $pdo->prepare("UPDATE broadcast_campaigns SET total_sent=?, total_failed=?, status=?, sent_at=CASE WHEN ?='sent' THEN NOW() ELSE sent_at END WHERE id=?")
        ->execute([$c['sent'], $c['failed'], $status, $status, $cid]);
    if ($status === 'sent' && function_exists('logActivity')) {
        logActivity('admin', 'broadcast.sent', 'broadcast_campaigns', $cid, 'Broadcast "' . $name . '": ' . $c['sent'] . ' terkirim, ' . $c['failed'] . ' gagal.');
    }
    return $c;
}

/**
 * Proses antrian broadcast (dipakai cron + pump halaman). Lock per-campaign
 * (GET_LOCK) agar tak dobel-kirim antar worker. Promosikan scheduled yang due.
 * @param int      $limit   maksimal penerima diproses per panggilan
 * @param int|null $onlyId  fokus 1 campaign (mode pump halaman); null = ambil yg terlama
 */
function processBroadcastQueue(int $limit = 100, ?int $onlyId = null): array
{
    $out = ['campaign_id' => null, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'total' => 0, 'status' => null, 'last' => null, 'promoted' => 0];
    try {
        $pdo = getDB();

        // Promosikan scheduled yang sudah due → bangun penerima + sending.
        if ($onlyId === null) {
            $due = $pdo->query("SELECT * FROM broadcast_campaigns WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW() ORDER BY scheduled_at ASC LIMIT 3")->fetchAll();
            foreach ($due as $camp) {
                try { startBroadcastCampaign($pdo, $camp); $out['promoted']++; }
                catch (Throwable $e) { error_log('broadcast promote error: ' . $e->getMessage()); }
            }
        }

        // Pilih campaign 'sending'.
        if ($onlyId) {
            $s = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id=? AND status='sending' LIMIT 1");
            $s->execute([$onlyId]); $campaign = $s->fetch();
        } else {
            $campaign = $pdo->query("SELECT * FROM broadcast_campaigns WHERE status='sending' ORDER BY id ASC LIMIT 1")->fetch();
        }
        if (!$campaign) return $out;
        $cid = (int) $campaign['id'];
        $out['campaign_id'] = $cid;

        // Lock per-campaign (0 = jangan tunggu). Gagal lock = worker lain sedang jalan.
        $lock = 'averion_bcast_' . $cid;
        if ((int) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 0)")->fetchColumn() !== 1) {
            $out = array_merge($out, broadcastCounts($pdo, $cid));
            $out['status'] = 'sending';
            return $out;
        }
        try {
            require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
            if ($campaign['channel'] === 'whatsapp' && getSetting($campaign['provider'] . '_enabled', '0') !== '1') {
                $pdo->prepare("UPDATE broadcast_campaigns SET status='failed' WHERE id=?")->execute([$cid]);
                $out['status'] = 'failed'; return $out;
            }
            $adapter = NotificationDispatcher::getAdapterPublic($campaign['provider']);
            if (!$adapter) {
                $pdo->prepare("UPDATE broadcast_campaigns SET status='failed' WHERE id=?")->execute([$cid]);
                $out['status'] = 'failed'; return $out;
            }

            $ps = $pdo->prepare(
                "SELECT br.id, br.recipient, COALESCE(u.name, l.name, '') AS name
                 FROM broadcast_recipients br
                 LEFT JOIN users u ON u.id=br.user_id LEFT JOIN leads l ON l.id=br.lead_id
                 WHERE br.campaign_id=? AND br.status='pending' ORDER BY br.id ASC LIMIT ?"
            );
            $ps->bindValue(1, $cid, PDO::PARAM_INT);
            $ps->bindValue(2, max(1, $limit), PDO::PARAM_INT);
            $ps->execute();
            $batch = $ps->fetchAll();

            $updOk   = $pdo->prepare("UPDATE broadcast_recipients SET status='sent', sent_at=NOW(), error_message=NULL WHERE id=?");
            $updFail = $pdo->prepare("UPDATE broadcast_recipients SET status='failed', error_message=? WHERE id=?");
            $insLog  = $pdo->prepare("INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $subject = $campaign['subject'] ?? '';
            $bodyFmt = $campaign['body_format'] ?? 'text';

            foreach ($batch as $row) {
                $body = str_replace('{{name}}', $row['name'] ?? '', $campaign['body']);
                if ($campaign['channel'] === 'email') {
                    if ($bodyFmt !== 'html') $body = nl2br($body);
                    // Broadcast HTML: sisipkan tracking open/click per penerima.
                    else $body = broadcastInjectTracking($body, (int) $row['id']);
                    // Footer "Berhenti berlangganan" (setelah tracking → link tak dibungkus).
                    $body = broadcastAppendUnsubscribe($body, (string) $row['recipient']);
                }
                $ok = false; $resp = '';
                try {
                    $res  = $adapter->send($row['recipient'], $subject, $body);
                    $ok   = !empty($res['success']);
                    // Keterangan error: utamakan raw_response (string/JSON), lalu message,
                    // lalu fallback agar kolom Error di Kesehatan Sistem tak kosong.
                    $raw  = $res['raw_response'] ?? null;
                    $resp = is_string($raw) ? $raw : (is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE) : '');
                    if (trim($resp) === '') $resp = (string) ($res['message'] ?? '');
                    if (!$ok && trim($resp) === '') $resp = 'Gagal kirim — provider tidak mengembalikan detail';
                    $resp = mb_substr($resp, 0, 500);
                } catch (Throwable $e) {
                    $ok = false; $resp = mb_substr('Exception: ' . $e->getMessage(), 0, 500);
                }
                if ($ok) { $updOk->execute([$row['id']]); $out['sent']++; }
                else     { $updFail->execute([substr($resp, 0, 255), $row['id']]); $out['failed']++; }
                $insLog->execute(['broadcast_' . $cid, $campaign['channel'], $campaign['provider'], $row['recipient'], $ok ? 'sent' : 'failed', $resp]);
                $out['processed']++; $out['last'] = $row['recipient'];
                usleep(300000); // 300ms anti rate-limit
            }

            $out = array_merge($out, broadcastFinalize($pdo, $cid, $campaign['name'] ?? ''));
            $out['status'] = $out['pending'] > 0 ? 'sending' : 'sent';
        } finally {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
        }
    } catch (Throwable $e) {
        error_log('processBroadcastQueue error: ' . $e->getMessage());
    }
    return $out;
}
