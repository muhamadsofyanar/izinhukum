<?php
// ─── Marketing Automation ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

function getProductRatingSummary(int $productId): array
{
    $empty = ['total'=>0,'average'=>0,'five_star'=>0,'four_star'=>0,'three_star'=>0,'two_star'=>0,'one_star'=>0];
    try {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) AS total, ROUND(AVG(rating),1) AS average,
                    SUM(rating=5) AS five_star, SUM(rating=4) AS four_star, SUM(rating=3) AS three_star,
                    SUM(rating=2) AS two_star, SUM(rating=1) AS one_star
             FROM product_reviews WHERE product_id = ? AND status = 'approved'"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return [
            'total'=>(int)($row['total']??0),'average'=>(float)($row['average']??0),
            'five_star'=>(int)($row['five_star']??0),'four_star'=>(int)($row['four_star']??0),
            'three_star'=>(int)($row['three_star']??0),'two_star'=>(int)($row['two_star']??0),'one_star'=>(int)($row['one_star']??0),
        ];
    } catch (Exception $e) {
        error_log('getProductRatingSummary error: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * Provider untuk abandoned cart mengikuti channel:
 * email → mailketing; whatsapp → provider WA pertama yang aktif ('' bila tak ada).
 * Mencegah salah-kirim (mis. email ke nomor HP saat channel WhatsApp).
 */
function resolveAbandonedCartProvider(string $channel): string
{
    if ($channel === 'whatsapp') {
        foreach (['fonte', 'onesender', 'starsender', 'dripsender', 'kirimdev'] as $wp) {
            if (getSetting($wp . '_enabled', '0') === '1') return $wp;
        }
        return '';
    }
    return 'mailketing';
}

/** Kriteria order pemulihan bersama untuk cron dan kandidat Hermes. */
function abandonedCartCandidateWhere(): string
{
    return "o.status = 'pending' AND o.recovery_sent_at IS NULL
               AND o.order_stage <> 'settlement'
               AND o.created_at <= NOW() - INTERVAL ? HOUR AND u.status = 'active'";
}

function processAbandonedCarts(int $limit = 30): array
{
    $result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    try {
        if (getSetting('abandoned_cart_enabled', '0') !== '1') return $result;

        $delayHours = max(1, (int) getSetting('abandoned_cart_delay_hours', '1'));
        $gap2Hours  = max(1, (int) getSetting('abandoned_cart_gap2_hours', '24')); // jeda setelah reminder #1
        $gap3Hours  = max(1, (int) getSetting('abandoned_cart_gap3_hours', '48')); // jeda setelah reminder #2
        $channel    = getSetting('abandoned_cart_channel', 'email');
        $provider   = resolveAbandonedCartProvider($channel);

        $pdo  = getDB();
        // Kandidat reminder BERTAHAP (hingga 3x per order). Tahap 1 dipicu dari
        // created_at (delay awal); tahap 2 & 3 dari recovery_sent_at (waktu reminder
        // TERAKHIR) + jeda, agar jarak antar-kirim nyata (tidak menumpuk dalam satu
        // tick cron).
        $stmt = $pdo->prepare(
            "SELECT o.id AS order_id, o.order_number, o.total, o.recovery_remind_stage,
                    u.id AS user_id, u.name AS user_name,
                    u.email AS user_email, u.phone AS user_phone, u.email_opt_out, oi.product_name
             FROM orders o JOIN users u ON u.id = o.user_id
             JOIN order_items oi ON oi.order_id = o.id AND oi.is_bump = 0
             WHERE o.status = 'pending' AND o.order_stage <> 'settlement' AND u.status = 'active'
               AND (
                 (o.recovery_remind_stage = 0 AND o.created_at <= NOW() - INTERVAL :d1 HOUR)
                 OR (o.recovery_remind_stage = 1 AND o.recovery_sent_at <= NOW() - INTERVAL :g2 HOUR)
                 OR (o.recovery_remind_stage = 2 AND o.recovery_sent_at <= NOW() - INTERVAL :g3 HOUR)
               )
             GROUP BY o.id
             ORDER BY o.created_at ASC LIMIT :lim"
        );
        $stmt->bindValue(':d1',  $delayHours, PDO::PARAM_INT);
        $stmt->bindValue(':g2',  $gap2Hours,  PDO::PARAM_INT);
        $stmt->bindValue(':g3',  $gap3Hours,  PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,      PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll();
        if (!$orders) return $result;

        require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';

        // Template reminder per TAHAP (0,1,2 → reminder #1,#2,#3). Tahap 1 =
        // 'abandoned_cart' (dasar/wajib). Tahap 2 = 'abandoned_cart_2', tahap 3 =
        // 'abandoned_cart_3'. Bila template tahap lanjutan belum ada/nonaktif →
        // fallback ke template dasar (backward-compatible: instalasi yang belum
        // mengimpor template baru tetap mengirim 3x memakai pesan dasar).
        $tplStmt    = $pdo->prepare("SELECT subject, body FROM notification_templates WHERE event_key=? AND channel=? AND is_active=1 LIMIT 1");
        $tplByStage = [];
        foreach ([0 => 'abandoned_cart', 1 => 'abandoned_cart_2', 2 => 'abandoned_cart_3'] as $stage => $ek) {
            $tplStmt->execute([$ek, $channel]);
            $tplByStage[$stage] = $tplStmt->fetch() ?: null;
        }
        $template = $tplByStage[0]; // template dasar — juga fallback & penanda "boleh kirim"

        // Bonus Kilat yang terkunci di order (is_bump=1 price=0). Bila ada, urgensi
        // bonus DI-APPEND ke pesan reminder (template dasar 'abandoned_cart' TIDAK
        // diubah). Teks urgensi = template editable 'abandoned_cart_bonus' (admin bisa
        // ubah/nonaktifkan di /admin/notifications). Nonaktif/absen → tak ada sisipan.
        $fbStmt       = $pdo->prepare("SELECT GROUP_CONCAT(product_name ORDER BY id SEPARATOR ', ') FROM order_items WHERE order_id = ? AND is_bump = 1 AND price = 0");
        $bonusTplStmt = $pdo->prepare("SELECT body FROM notification_templates WHERE event_key='abandoned_cart_bonus' AND channel=? AND is_active=1 LIMIT 1");
        $bonusTplStmt->execute([$channel]);
        $bonusTpl   = $bonusTplStmt->fetch();
        $brandColor = getSetting('brand_color', '#6366f1');
        // Klaim atomik per-tahap: naikkan recovery_remind_stage dari currentStage ke
        // currentStage+1 HANYA bila masih di currentStage → cron ganda tak bisa dobel.
        $claim    = $pdo->prepare("UPDATE orders SET recovery_remind_stage = ?, recovery_sent_at = NOW()
                                   WHERE id = ? AND status='pending' AND recovery_remind_stage = ?");
        // Setop permanen (recipient kosong / opt-out): langsung ke tahap akhir (3) agar
        // order tidak dicoba ulang di tick berikutnya.
        $markDone = $pdo->prepare("UPDATE orders SET recovery_remind_stage = 3, recovery_sent_at = NOW()
                                   WHERE id = ? AND status='pending'");
        $aclIns   = $pdo->prepare("INSERT INTO abandoned_cart_logs (order_id, user_id, channel, provider, recipient, status, response) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $nlIns    = $pdo->prepare("INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at) VALUES ('abandoned_cart', ?, ?, ?, ?, ?, NOW())");
        $appName  = getSetting('app_name', 'STIFIn Mulia');

        foreach ($orders as $o) {
            $recipient = $channel === 'whatsapp' ? ($o['user_phone'] ?? '') : $o['user_email'];
            if (empty($recipient)) {
                $markDone->execute([$o['order_id']]);
                $aclIns->execute([$o['order_id'], $o['user_id'], $channel, $provider, '', 'skipped', 'Recipient kosong']);
                $result['skipped']++; $result['processed']++; continue;
            }
            if (!$template) { $result['skipped']++; $result['processed']++; continue; } // jangan ubah tahap → bisa retry setelah template aktif
            if ($channel === 'email' && (int) $o['email_opt_out'] === 1) {
                $markDone->execute([$o['order_id']]);
                $aclIns->execute([$o['order_id'], $o['user_id'], $channel, $provider, $recipient, 'skipped', 'Penerima berhenti berlangganan']);
                $result['skipped']++; $result['processed']++; continue;
            }

            // Pilih template sesuai tahap reminder order ini (fallback ke dasar).
            $curStage  = (int) $o['recovery_remind_stage'];
            $activeTpl = $tplByStage[$curStage] ?: $template;

            $checkoutUrl = baseUrl() . '/transactions/' . $o['order_number'];
            $ph = ['name' => $o['user_name'], 'product_name' => $o['product_name'], 'total' => formatRupiah((float) $o['total']),
                   'order_number' => $o['order_number'], 'checkout_url' => $checkoutUrl, 'app_name' => $appName];
            $subject = renderPlaceholders($activeTpl['subject'] ?? '', $ph, $channel);
            $body    = renderPlaceholders($activeTpl['body'] ?? '', $ph, $channel);

            // Urgensi Bonus Kilat — hanya bila order punya bonus terkunci DAN template
            // 'abandoned_cart_bonus' aktif. Di-append (pesan dasar tak berubah).
            if ($bonusTpl) {
                $fbStmt->execute([$o['order_id']]);
                $bonusNames = trim((string) $fbStmt->fetchColumn());
                if ($bonusNames !== '') {
                    $bonusBody = renderPlaceholders($bonusTpl['body'] ?? '', ['flash_bonus_list' => $bonusNames, 'app_name' => $appName, 'brand_color' => $brandColor], $channel);
                    if (trim($bonusBody) !== '') {
                        $body .= ($channel === 'email' ? $bonusBody : "\n\n" . $bonusBody);
                    }
                }
            }

            if ($channel === 'email' && function_exists('emailLayout')) $body = emailLayout($subject, $body, 'info');
            if ($channel === 'email') $body = sequenceApplyUnsubscribeFooter($body, (string) $recipient);

            $adapter = NotificationDispatcher::getAdapterPublic($provider);
            if (!$adapter) { $result['skipped']++; $result['processed']++; continue; }

            // Klaim atomik per-tahap sebelum I/O eksternal: cron ganda dan Hermes tidak
            // bisa sama-sama mengirim reminder tahap yang sama untuk satu order.
            // ($curStage sudah dihitung di atas saat memilih template.)
            $claim->execute([$curStage + 1, $o['order_id'], $curStage]);
            if ($claim->rowCount() !== 1) { $result['skipped']++; $result['processed']++; continue; }
            $check = $pdo->prepare('SELECT status FROM orders WHERE id=?');
            $check->execute([$o['order_id']]);
            if ($check->fetchColumn() !== 'pending') { $result['skipped']++; $result['processed']++; continue; }

            $res  = $adapter->send($recipient, $subject, $body);
            $ok   = !empty($res['success']);
            $resp = is_array($res['raw_response'] ?? null) ? json_encode($res['raw_response']) : (string) ($res['raw_response'] ?? $res['message'] ?? '');

            $aclIns->execute([$o['order_id'], $o['user_id'], $channel, $provider, $recipient, $ok ? 'sent' : 'failed', $resp]);
            $nlIns->execute([$channel, $provider, $recipient, $ok ? 'sent' : 'failed', $resp]);
            $result['processed']++;
            $ok ? $result['sent']++ : $result['failed']++;
        }
    } catch (Exception $e) {
        error_log('processAbandonedCarts error: ' . $e->getMessage());
    }
    return $result;
}

/**
 * Pilih template reminder keranjang sesuai TAHAP pengiriman (0,1,2 = reminder
 * #1,#2,#3). Tahap 1 = 'abandoned_cart' (dasar/wajib); tahap 2/3 =
 * 'abandoned_cart_2'/'abandoned_cart_3'. Bila template tahap lanjutan belum
 * ada/nonaktif → fallback ke template dasar (backward-compatible). Return baris
 * {subject, body} atau null bila template DASAR pun tak aktif.
 * Dipakai oleh pengiriman manual (send-cart-recovery.php); worker cron memuat
 * petanya sendiri sekali agar tak query per-order.
 */
function abandonedCartTemplateForStage(int $stage, string $channel): ?array
{
    $pdo = getDB();
    $sel = $pdo->prepare("SELECT subject, body FROM notification_templates WHERE event_key=? AND channel=? AND is_active=1 LIMIT 1");
    $sel->execute(['abandoned_cart', $channel]);
    $base = $sel->fetch() ?: null;
    $eventByStage = [0 => 'abandoned_cart', 1 => 'abandoned_cart_2', 2 => 'abandoned_cart_3'];
    $ek = $eventByStage[$stage] ?? 'abandoned_cart';
    if ($ek === 'abandoned_cart') return $base;
    $sel->execute([$ek, $channel]);
    $row = $sel->fetch() ?: null;
    return $row ?: $base; // fallback ke template dasar
}

function enrollUserToSequences(string $triggerEvent, int $userId, int $productId = 0, string $triggerRef = ''): void
{
    try {
        $pdo = getDB();
        // Cocokkan produk via junction email_sequence_products:
        //   - target: ada baris include yg == produk INI, ATAU (tak ada include sama sekali
        //     → fallback ke kolom lama trigger_product_id: NULL=semua produk / =produk INI).
        //   - kecualikan: tak boleh ada baris exclude yg == produk INI.
        $seqStmt = $pdo->prepare(
            "SELECT es.id FROM email_sequences es
             WHERE es.status = 'active' AND es.trigger_event = ?
               AND (
                 EXISTS (SELECT 1 FROM email_sequence_products ep
                         WHERE ep.sequence_id = es.id AND ep.kind = 'include' AND ep.product_id = ?)
                 OR (
                   NOT EXISTS (SELECT 1 FROM email_sequence_products ep
                               WHERE ep.sequence_id = es.id AND ep.kind = 'include')
                   AND (es.trigger_product_id IS NULL OR es.trigger_product_id = ?)
                 )
               )
               AND NOT EXISTS (SELECT 1 FROM email_sequence_products ep
                               WHERE ep.sequence_id = es.id AND ep.kind = 'exclude' AND ep.product_id = ?)"
        );
        $seqStmt->execute([$triggerEvent, $productId, $productId, $productId]);
        $sequences = $seqStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$sequences) return;

        $enrollIns = $pdo->prepare(
            "INSERT IGNORE INTO email_sequence_enrollments (sequence_id, user_id, trigger_ref, enrolled_at, status)
             VALUES (?, ?, ?, NOW(), 'active')"
        );
        $enrollFind = $pdo->prepare("SELECT id FROM email_sequence_enrollments WHERE sequence_id = ? AND user_id = ? LIMIT 1");
        $stepStmt = $pdo->prepare("SELECT id, delay_days FROM email_sequence_steps WHERE sequence_id = ? AND status = 'active'");
        $sendIns  = $pdo->prepare(
            "INSERT IGNORE INTO email_sequence_sends (enrollment_id, step_id, status, scheduled_at)
             VALUES (?, ?, 'pending', NOW() + INTERVAL ? DAY)"
        );

        foreach ($sequences as $seqId) {
            $enrollIns->execute([$seqId, $userId, $triggerRef]);
            $enrollFind->execute([$seqId, $userId]);
            $enrollmentId = (int) $enrollFind->fetchColumn();
            if (!$enrollmentId) continue;
            $stepStmt->execute([$seqId]);
            foreach ($stepStmt->fetchAll() as $step) {
                $sendIns->execute([$enrollmentId, (int) $step['id'], max(0, (int) $step['delay_days'])]);
            }
        }
    } catch (Exception $e) {
        error_log('enrollUserToSequences error: ' . $e->getMessage());
    }
}

/**
 * Pasang footer unsubscribe NATIVE Averion (per-penerima, HMAC token → /unsubscribe/{token})
 * ke body email sequence. Memakai broadcastAppendUnsubscribe() (helpers/broadcast.php,
 * dimuat via bootstrap) — TIDAK menduplikasi logikanya, TIDAK memakai %%unsubscribelink%%
 * provider. Idempoten: lewati bila body SUDAH memuat link /unsubscribe/ (footer native)
 * agar tidak dobel. Fail-soft: body dikembalikan apa adanya bila helper absen / email kosong.
 * Berlaku untuk HTML dokumen penuh, fragment, maupun body yang sudah dibungkus emailLayout().
 */
function sequenceApplyUnsubscribeFooter(string $body, string $email): string
{
    $email = trim($email);
    if ($email === '' || !function_exists('broadcastAppendUnsubscribe')) {
        return $body;
    }
    // Sudah ada link unsubscribe native di body → jangan tambah footer kedua.
    if (stripos($body, '/unsubscribe/') !== false) {
        return $body;
    }
    return broadcastAppendUnsubscribe($body, $email);
}

function processDueSequenceSends(int $limit = 20): array
{
    $result = ['processed' => 0, 'sent' => 0, 'failed' => 0];
    try {
        $pdo = getDB();
        ensureEmailStepBodyFormatColumn($pdo);
        $stmt = $pdo->prepare(
            "SELECT ess.id AS send_id, ess.enrollment_id, estp.subject, estp.body, estp.body_format,
                    ese.user_id, ese.trigger_ref, esq.provider, esq.name AS sequence_name,
                    u.email, u.name AS user_name
             FROM email_sequence_sends ess
             JOIN email_sequence_enrollments ese ON ese.id = ess.enrollment_id
             JOIN email_sequence_steps estp ON estp.id = ess.step_id
             JOIN email_sequences esq ON esq.id = ese.sequence_id
             JOIN users u ON u.id = ese.user_id
             WHERE ess.status = 'pending' AND ess.scheduled_at <= NOW()
               AND ese.status = 'active' AND estp.status = 'active' AND esq.status = 'active'
               AND u.email_opt_out = 0
             ORDER BY ess.scheduled_at ASC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!$rows) return $result;

        require_once dirname(__DIR__) . '/notifications/NotificationDispatcher.php';
        $upd = $pdo->prepare("UPDATE email_sequence_sends SET status = ?, sent_at = NOW(), response = ? WHERE id = ?");
        $logIns = $pdo->prepare(
            "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
             VALUES ('email_sequence', 'email', ?, ?, ?, ?, NOW())"
        );
        $appName = getSetting('app_name', 'STIFIn Mulia');

        foreach ($rows as $r) {
            $result['processed']++;
            $data = ['name' => $r['user_name'], 'sequence_name' => $r['sequence_name'], 'trigger_ref' => $r['trigger_ref'] ?? '', 'app_name' => $appName];
            $subject = renderPlaceholders($r['subject'] ?? '', $data);
            $body    = renderPlaceholders($r['body'] ?? '', $data);
            // Mode 'html' = template email lengkap → kirim apa adanya. Mode 'text'
            // = teks biasa → nl2br + dibungkus emailLayout.
            if (($r['body_format'] ?? 'text') === 'html') {
                // biarkan body apa adanya (template embed)
            } else {
                $body = nl2br($body);
                if (function_exists('emailLayout')) $body = emailLayout($subject, $body, 'info');
            }

            // Footer unsubscribe NATIVE Averion (per-penerima) — SETELAH body final &
            // SEBELUM kirim. Idempoten; tanpa %%unsubscribelink%% provider. Tanpa ini
            // email sequence tak punya cara berhenti berlangganan yang sah.
            $body = sequenceApplyUnsubscribeFooter($body, (string) ($r['email'] ?? ''));

            $adapter = NotificationDispatcher::getAdapterPublic($r['provider'] ?: 'mailketing');
            if (!$adapter || empty($r['email'])) {
                $upd->execute(['skipped', 'Adapter/email tidak tersedia', (int) $r['send_id']]);
                continue;
            }
            $res = $adapter->send($r['email'], $subject, $body);
            $ok  = !empty($res['success']);
            $resp = is_array($res['raw_response'] ?? null) ? json_encode($res['raw_response']) : (string) ($res['raw_response'] ?? $res['message'] ?? '');
            $upd->execute([$ok ? 'sent' : 'failed', $resp, (int) $r['send_id']]);
            $logIns->execute([$r['provider'] ?: 'mailketing', $r['email'], $ok ? 'sent' : 'failed', $resp]);
            $ok ? $result['sent']++ : $result['failed']++;
        }

        // Tandai enrollment selesai bila semua send sudah bukan pending
        $pdo->query(
            "UPDATE email_sequence_enrollments ese SET status = 'completed'
             WHERE ese.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM email_sequence_sends s WHERE s.enrollment_id = ese.id AND s.status = 'pending')
               AND EXISTS (SELECT 1 FROM email_sequence_sends s2 WHERE s2.enrollment_id = ese.id)"
        );
    } catch (Exception $e) {
        error_log('processDueSequenceSends error: ' . $e->getMessage());
    }
    return $result;
}
