<?php
// POST /actions/chat/stream — PUBLIC endpoint (tanpa login, tanpa CSRF).
// Streaming (Server-Sent Events) jawaban AI dari Gemini/Groq ke browser.
// Logika guard/rate-limit/validasi/prompt IDENTIK dengan send.php. send.php
// tetap ada sebagai fallback non-streaming.
require_once __DIR__ . '/../../bootstrap.php';

// ── Lepas lock session (endpoint ini tidak menulis session; stream bisa lama)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// ── Anti-buffering: penting agar chunk sampai real-time (Nginx/CloudPanel) ──
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
set_time_limit(120);
ignore_user_abort(false);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);

/** Kirim satu event SSE lalu flush. */
function sse(string $event, array $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

/** Kirim event error lalu berhenti. */
function sseErrorExit(string $message): void
{
    sse('error', ['message' => $message]);
    exit;
}

// ── LANGKAH 1: GUARD enabled ────────────────────────────────────────────
if (getSetting('ai_chat_enabled') !== '1') {
    sseErrorExit('Chat tidak tersedia.');
}

// ── LANGKAH 2: RATE LIMITING (per IP, window 60 detik, maks 10 request) ──
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip = trim(explode(',', $ip)[0]);
$ip = substr($ip, 0, 45);

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, request_count, window_start FROM ai_chat_rate_limits WHERE ip_address = ? LIMIT 1');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->prepare('INSERT INTO ai_chat_rate_limits (ip_address, request_count, window_start) VALUES (?, 1, NOW())')
            ->execute([$ip]);
    } else {
        $elapsed = time() - strtotime((string) $row['window_start']);
        if ($elapsed > 60) {
            $pdo->prepare('UPDATE ai_chat_rate_limits SET request_count = 1, window_start = NOW() WHERE id = ?')
                ->execute([$row['id']]);
        } elseif ((int) $row['request_count'] >= 10) {
            sseErrorExit('Terlalu banyak permintaan. Coba lagi dalam semenit.');
        } else {
            $pdo->prepare('UPDATE ai_chat_rate_limits SET request_count = request_count + 1 WHERE id = ?')
                ->execute([$row['id']]);
        }
    }

    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM ai_chat_rate_limits WHERE window_start < (NOW() - INTERVAL 1 HOUR)');
    }
} catch (Exception $e) {
    // Fail-open: DB rate limit error tidak boleh mematikan chat.
    error_log('chat/stream rate-limit error: ' . $e->getMessage());
}

// ── LANGKAH 3: AMBIL & VALIDASI INPUT ───────────────────────────────────
$message = trim($_POST['message'] ?? '');
if ($message === '') {
    sseErrorExit('Pesan tidak boleh kosong.');
}
$message = mb_substr(strip_tags($message), 0, 500);

$history = json_decode($_POST['history'] ?? '[]', true);
if (!is_array($history)) $history = [];
$history = array_slice($history, -10);

$cleanHistory = [];
foreach ($history as $item) {
    if (!is_array($item)) continue;
    $role    = $item['role'] ?? '';
    $content = $item['content'] ?? '';
    if (!is_string($role) || !is_string($content)) continue;
    if (!in_array($role, ['user', 'model', 'assistant'], true)) continue;
    $content = mb_substr(strip_tags(trim($content)), 0, 500);
    if ($content === '') continue;
    $cleanHistory[] = ['role' => $role, 'content' => $content];
}

// ── LANGKAH 4: BUILD SYSTEM PROMPT ──────────────────────────────────────
$systemPrompt = (string) getSetting('ai_chat_system_prompt', 'Kamu adalah asisten toko yang helpful.');

if (getSetting('ai_chat_inject_products') === '1') {
    // Konteks jualan lengkap: katalog (harga, link beli, upsell, AI Knowledge
    // per produk) + FAQ toko — lihat buildAiSalesContext() di helpers.php.
    // focus_product_id (opsional) dari widget salespage canvas → fokus produk halaman.
    $focusPid = (int) ($_POST['focus_product_id'] ?? 0);
    $systemPrompt .= buildAiSalesContext($message, $focusPid);
}

// ── LANGKAH 5: STREAMING KE AI PROVIDER ─────────────────────────────────
$provider = getSetting('ai_chat_provider', 'gemini');
$apiKey   = (string) getSetting('ai_chat_api_key', '');
if ($apiKey === '') {
    sseErrorExit('Layanan chat belum dikonfigurasi.');
}
if (!function_exists('curl_init')) {
    sseErrorExit('Streaming tidak didukung server.');
}

// URL, body, header, dan cara ekstraksi teks dirakit terpusat (helpers.php) —
// lihat aiChatBuildRequest(). Menambah provider tak lagi menyentuh file ini.
$req = aiChatBuildRequest($provider, $apiKey, [
    'system'      => $systemPrompt,
    'history'     => $cleanHistory,
    'user'        => $message,
    'max_tokens'  => 500,
    'temperature' => 0.7,
    'stream'      => true,
]);
$url        = $req['url'];
$reqBody    = $req['body'];
$reqHeaders = $req['headers'];
$extract    = static fn(array $p): string => aiChatExtractDelta($provider, $p);

$fullReply = '';
$buffer    = '';
$sawText   = false;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($reqBody, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => $reqHeaders,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 60,
    // Baca stream chunk-per-chunk → parse SSE provider → forward ke browser.
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer, &$fullReply, &$sawText, $extract) {
        if (connection_aborted()) return 0; // client menutup → hentikan cURL
        $buffer .= $data;
        $buffer = str_replace("\r\n", "\n", $buffer); // normalisasi CRLF → LF

        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $block  = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            foreach (explode("\n", $block) as $line) {
                $line = ltrim($line);
                if (strncmp($line, 'data:', 5) !== 0) continue;
                $json = trim(substr($line, 5));
                if ($json === '' || $json === '[DONE]') continue;
                $parsed = json_decode($json, true);
                if (!is_array($parsed)) continue;
                $text = $extract($parsed);
                if ($text !== '') {
                    $fullReply .= $text;
                    $sawText = true;
                    sse('chunk', ['text' => $text]);
                }
            }
        }
        return strlen($data);
    },
]);

try {
    $ok       = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
} catch (Throwable $e) {
    error_log('[AI Chat Stream] exception: ' . $e->getMessage());
    sseErrorExit('AI sedang tidak tersedia. Coba lagi.');
}

// Gagal & belum ada teks terkirim → pesan yang JUJUR sesuai sebabnya.
//
// Dulu semua sebab dibalas "AI sedang tidak tersedia. Coba lagi." — pengunjung
// mengira layanannya rusak & pergi, padahal sebab tersering (kuota per menit
// habis) cuma butuh tunggu sebentar. Sebabnya sudah dicatat di log tapi tak
// pernah sampai ke siapa pun yang bisa bertindak. Detail teknis TETAP tak
// dibocorkan ke pengunjung — yang berbeda cuma arahannya.
if (!$sawText) {
    error_log('[AI Chat Stream] no text. provider=' . $provider . ' http=' . $httpCode . ' err=' . $curlErr);
    sseErrorExit(aiChatErrorMessage($httpCode, $curlErr));
}

// ── LANGKAH 6: DETEKSI FALLBACK WHATSAPP + EVENT DONE ───────────────────
$showWa    = false;
$fullReply = trim($fullReply);
if (getSetting('ai_chat_fallback_wa') === '1' && getSetting('ai_chat_fallback_wa_number', '') !== '') {
    $fallbackKeywords = ['tidak tahu', 'tidak bisa', 'maaf', 'tidak dapat', 'hubungi', 'admin'];
    $replyLower = mb_strtolower($fullReply);
    foreach ($fallbackKeywords as $kw) {
        if (mb_strpos($replyLower, $kw) !== false) {
            $showWa = true;
            break;
        }
    }
}

// Lead sudah tertangkap di sesi ini → percakapan lanjutan ikut ke transcript.
if (!empty($_POST['lead_token']) && $fullReply !== '') {
    appendChatTranscript((string) $_POST['lead_token'], $message, $fullReply);
}

sse('done', ['show_wa' => $showWa, 'full_reply' => $fullReply]);
exit;
