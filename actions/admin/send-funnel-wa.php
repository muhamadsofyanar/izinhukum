<?php
/**
 * Kirim pesan WhatsApp 1-on-1 ke satu peserta Funnel Gate (AJAX/JSON).
 *
 * Dipanggil dari modal Detail di /admin/funnel-gate/submissions. Mengirim lewat
 * provider WhatsApp aktif (gateway yang sama dengan notifikasi & broadcast),
 * TANPA menyimpan jejak di tabel submission — hanya dicatat ke notification_logs
 * (muncul di Kesehatan Sistem). Tidak ada auto-retry (sesuai aturan WA: read-timeout
 * StarSender sudah diperlakukan sebagai "mungkin terkirim" oleh adapter → jangan
 * kirim ulang).
 *
 * METHOD: POST {csrf_token, id, message}
 */

require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

function sfw_json(array $p, int $code = 200): void {
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST')            sfw_json(['success' => false, 'message' => 'Metode tidak valid.'], 405);
if (!validateCSRF($_POST['csrf_token'] ?? ''))        sfw_json(['success' => false, 'message' => 'Token keamanan tidak valid.'], 403);

$id   = (int) ($_POST['id'] ?? 0);
$body = trim((string) ($_POST['message'] ?? ''));
if ($id <= 0)                 sfw_json(['success' => false, 'message' => 'Submission tidak valid.'], 400);
if ($body === '')             sfw_json(['success' => false, 'message' => 'Isi pesan belum diisi.'], 400);
if (mb_strlen($body) > 4000)  sfw_json(['success' => false, 'message' => 'Pesan terlalu panjang (maks 4000 karakter).'], 400);

$pdo = getDB();
try {
    $st = $pdo->prepare('SELECT id, name, whatsapp FROM funnel_gate_submissions WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $sub = $st->fetch();
} catch (Throwable $e) {
    error_log('FG WA load: ' . $e->getMessage());
    sfw_json(['success' => false, 'message' => 'Gagal memuat data peserta.'], 500);
}
if (!$sub)                                       sfw_json(['success' => false, 'message' => 'Peserta tidak ditemukan.'], 404);

$waRaw = trim((string) ($sub['whatsapp'] ?? ''));
if ($waRaw === '')                               sfw_json(['success' => false, 'message' => 'Peserta ini tidak punya nomor WhatsApp.'], 400);

require_once dirname(__DIR__, 2) . '/notifications/NotificationDispatcher.php';
$provider = NotificationDispatcher::resolveActiveWaProvider();
if ($provider === '')                            sfw_json(['success' => false, 'message' => 'Belum ada provider WhatsApp yang aktif. Aktifkan di menu Integrasi dulu.'], 400);
$adapter = NotificationDispatcher::getAdapterPublic($provider);
if (!$adapter)                                   sfw_json(['success' => false, 'message' => 'Provider WhatsApp tidak tersedia.'], 500);

$wa  = function_exists('normalizeWaNumber') ? normalizeWaNumber($waRaw) : $waRaw;
$msg = str_replace('{{name}}', (string) ($sub['name'] ?? ''), $body);

// Jejak aktor SEBELUM menutup sesi (logActivity membaca $_SESSION untuk pelaku).
if (function_exists('logActivity')) {
    logActivity('admin', 'funnel_gate.wa_manual', 'funnel_gate_submissions', $id, 'Kirim WhatsApp manual ke peserta funnel.');
}

// Lepas lock file-sesi sebelum HTTP keluar (kirim WA bisa 15–20 dtk) agar
// request lain milik admin tak ikut menunggu. Sesi tetap terbaca di memori.
if (PHP_SESSION_ACTIVE === session_status()) session_write_close();

$ok = false; $resp = '';
try {
    $res  = $adapter->send($wa, '', $msg);
    $ok   = !empty($res['success']);
    $raw  = $res['raw_response'] ?? null;
    $resp = is_string($raw) ? $raw : (is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE) : '');
    if (trim($resp) === '')        $resp = (string) ($res['message'] ?? '');
    if (!$ok && trim($resp) === '') $resp = 'Gagal kirim — provider tidak mengembalikan detail.';
    $resp = mb_substr($resp, 0, 500);
} catch (Throwable $e) {
    $ok = false; $resp = mb_substr('Exception: ' . $e->getMessage(), 0, 500);
}

// Jejak ke notification_logs (terlihat di Kesehatan Sistem).
try {
    $pdo->prepare("INSERT INTO notification_logs (event_key, channel, provider, recipient, status, response, created_at)
                   VALUES (?, 'whatsapp', ?, ?, ?, ?, NOW())")
        ->execute(['funnel_wa_manual', $provider, $wa, $ok ? 'sent' : 'failed', $resp]);
} catch (Throwable $e) {
    error_log('FG WA log: ' . $e->getMessage());
}

if ($ok) sfw_json(['success' => true,  'message' => 'Pesan WhatsApp terkirim ke ' . (($sub['name'] ?? '') !== '' ? $sub['name'] : $wa) . '.']);
sfw_json(['success' => false, 'message' => 'Gagal mengirim: ' . $resp]);
