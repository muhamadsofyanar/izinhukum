<?php
/**
 * Aksi TULIS Integrasi Claude (MCP) — FASE 5. TERPISAH dari _data.php (read).
 * Dipakai HANYA oleh handler MCP tools/call untuk tool ber-tanda 'write'=>true,
 * dan HANYA saat mcpWriteEnabled() (toggle admin default MATI) + persetujuan user
 * di Claude per aksi. Tiap fungsi: validasi ketat, kirim via mesin notifikasi yang
 * SUDAH ada, catat ke notification_logs + logActivity. Melempar InvalidArgumentException
 * utk input tak valid (→ hasil tool isError, bukan error protokol).
 *
 * BATAS TERGEMBOK: tak ada aksi payout/refund/saldo/hapus di sini — selamanya.
 */
require_once __DIR__ . '/_auth.php';
// _actions.php ada di api/internal/ → root = 2 level naik (dirname __DIR__ 2x).
require_once dirname(__DIR__, 2) . '/notifications/NotificationDispatcher.php';

/**
 * Kirim pesan WA/email ke SATU member (per id atau email). Satu member per panggilan
 * = unit aman (kirim ke banyak = panggil berulang, tiap kali disetujui user di Claude).
 * Kontak diambil penuh dari users (kirim butuh nomor/email asli, bukan yang ter-mask).
 */
function mcpActionSendMessage(PDO $pdo, array $a): array
{
    $channel = strtolower(trim((string) ($a['channel'] ?? '')));
    if (!in_array($channel, ['whatsapp', 'email'], true)) {
        throw new InvalidArgumentException('channel wajib "whatsapp" atau "email".');
    }
    $message = trim((string) ($a['message'] ?? ''));
    if ($message === '') {
        throw new InvalidArgumentException('message (isi pesan) wajib diisi.');
    }
    if (mb_strlen($message) > 4000) {
        throw new InvalidArgumentException('message terlalu panjang (maks 4000 karakter).');
    }

    // Resolusi member: member_id ATAU member_email.
    $memberId = (int) ($a['member_id'] ?? 0);
    $email    = trim((string) ($a['member_email'] ?? ''));
    if ($memberId > 0) {
        $u = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE id = ? AND role = 'user' LIMIT 1");
        $u->execute([$memberId]);
    } elseif ($email !== '') {
        $u = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE email = ? AND role = 'user' LIMIT 1");
        $u->execute([$email]);
    } else {
        throw new InvalidArgumentException('Sertakan member_id atau member_email.');
    }
    $member = $u->fetch();
    if (!$member) {
        throw new InvalidArgumentException('Member tidak ditemukan.');
    }

    $subject = trim((string) ($a['subject'] ?? 'Pesan dari ' . getSetting('app_name', 'STIFIn Mulia')));

    // ── Kirim via adapter yang SUDAH ada ──────────────────────────────
    if ($channel === 'whatsapp') {
        $phone = trim((string) ($member['phone'] ?? ''));
        if ($phone === '') {
            throw new InvalidArgumentException('Member ini tak punya nomor WhatsApp.');
        }
        $provider = NotificationDispatcher::resolveActiveWaProvider();
        if ($provider === '') {
            // Tak ada provider WA aktif di instalasi ini → jangan pura-pura terkirim.
            return mcpSendResult(false, 'whatsapp', '-', $phone, (int) $member['id'],
                'Tidak ada provider WhatsApp yang aktif di instalasi ini. Aktifkan salah satu di Admin > Integrasi dulu.');
        }
        $adapter = NotificationDispatcher::getAdapterPublic($provider);
        $res = $adapter ? $adapter->send($phone, '', $message) : ['success' => false, 'message' => 'adapter tak tersedia'];
        return mcpSendResult(!empty($res['success']), 'whatsapp', $provider, $phone, (int) $member['id'], (string) ($res['message'] ?? ''), $subject, $message);
    }

    // email
    $to = trim((string) ($member['email'] ?? ''));
    if ($to === '') {
        throw new InvalidArgumentException('Member ini tak punya email.');
    }
    $body    = function_exists('emailLayout') ? emailLayout($subject, nl2br(e($message)), 'info') : $message;
    $adapter = NotificationDispatcher::getAdapterPublic('mailketing');
    $res = $adapter ? $adapter->send($to, $subject, $body) : ['success' => false, 'message' => 'adapter email tak tersedia'];
    return mcpSendResult(!empty($res['success']), 'email', 'mailketing', $to, (int) $member['id'], (string) ($res['message'] ?? ''), $subject, $message);
}

/** Catat ke notification_logs + logActivity, kembalikan ringkasan hasil ke Claude. */
function mcpSendResult(bool $ok, string $channel, string $provider, string $recipient, int $memberId, string $msg, string $subject = '', string $body = ''): array
{
    try {
        getDB()->prepare(
            "INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, subject, body, created_at)
             VALUES ('mcp_message', ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$channel, $provider, $recipient, $ok ? 'sent' : 'failed', mb_substr($msg, 0, 500),
                    $ok ? null : mb_substr($subject, 0, 255), $ok ? null : $body]);
    } catch (Throwable $e) {
        error_log('mcpSendResult log error: ' . $e->getMessage());
    }
    logActivity('admin', 'mcp.send_message', 'user', $memberId,
        "channel={$channel} provider={$provider} status=" . ($ok ? 'sent' : 'failed'));

    // recipient di respons DISAMARKAN mengikuti setting mask (jangan bocorkan kontak
    // penuh balik ke Claude bila admin memilih mask).
    $shown = $channel === 'email' ? mcpMaskEmail($recipient) : mcpMaskPhone($recipient);
    return [
        'sent'      => $ok,
        'channel'   => $channel,
        'provider'  => $provider,
        'recipient' => $shown,
        'note'      => $ok ? 'Pesan terkirim.' : ('Gagal: ' . ($msg !== '' ? $msg : 'tak diketahui')),
    ];
}

/**
 * Kirim alert ke channel Telegram INTERNAL admin (bukan pelanggan). Reuse
 * notifyAdminTelegram() yg sudah men-gate telegram_enabled + mencatat log.
 */
function mcpActionTelegramAlert(string $message): array
{
    $message = trim($message);
    if ($message === '') {
        throw new InvalidArgumentException('message (isi alert) wajib diisi.');
    }
    if (mb_strlen($message) > 3000) {
        throw new InvalidArgumentException('message terlalu panjang (maks 3000 karakter).');
    }
    $ok = function_exists('notifyAdminTelegram') ? notifyAdminTelegram('mcp_alert', $message) : false;
    logActivity('admin', 'mcp.telegram_alert', null, null, 'status=' . ($ok ? 'sent' : 'failed'));

    return [
        'sent' => $ok,
        'note' => $ok
            ? 'Alert terkirim ke Telegram admin.'
            : 'Gagal / Telegram belum dikonfigurasi. Aktifkan di Admin > Notifikasi (Telegram) dulu.',
    ];
}
