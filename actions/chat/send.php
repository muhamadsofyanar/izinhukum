<?php
// POST /actions/chat/send  — PUBLIC endpoint (tanpa login, tanpa CSRF).
// Proxy pesan chat visitor ke provider AI (Gemini / Groq). Rate-limited per IP.
// SELALU mengembalikan JSON. TIDAK pernah membocorkan API key / respons mentah.
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');

/** Kirim JSON + kode HTTP lalu berhenti. */
function chatJson(int $httpCode, array $payload): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── LANGKAH 1: GUARD enabled ────────────────────────────────────────────
if (getSetting('ai_chat_enabled') !== '1') {
    chatJson(403, ['error' => 'Chat tidak tersedia.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatJson(405, ['error' => 'Metode tidak diizinkan.']);
}

// ── Lepas lock session: proxy ke provider AI bisa lama. Endpoint publik ini tidak
// menulis $_SESSION (rate-limit & histori pakai DB), jadi lock sesi hanya membuat
// request lain dari pengunjung yang sama (aset/polling) antre → widget terasa berat.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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
            // Reset window.
            $pdo->prepare('UPDATE ai_chat_rate_limits SET request_count = 1, window_start = NOW() WHERE id = ?')
                ->execute([$row['id']]);
        } elseif ((int) $row['request_count'] >= 10) {
            chatJson(429, ['error' => 'Terlalu banyak permintaan. Coba lagi dalam semenit.']);
        } else {
            $pdo->prepare('UPDATE ai_chat_rate_limits SET request_count = request_count + 1 WHERE id = ?')
                ->execute([$row['id']]);
        }
    }

    // GC oportunistik (~2%): buang baris window lama agar tabel tetap kecil.
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM ai_chat_rate_limits WHERE window_start < (NOW() - INTERVAL 1 HOUR)');
    }
} catch (Exception $e) {
    // Fail-open: DB rate limit error tidak boleh mematikan chat. Log saja.
    error_log('chat/send rate-limit error: ' . $e->getMessage());
}

// ── LANGKAH 3: AMBIL & VALIDASI INPUT ───────────────────────────────────
$message = trim($_POST['message'] ?? '');
if ($message === '') {
    chatJson(400, ['error' => 'Pesan tidak boleh kosong.']);
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
    // focus_product_id (opsional) dikirim widget di salespage canvas → AI fokus
    // ke produk halaman + pakai AI Knowledge produk itu sebagai bekal utama.
    $focusPid = (int) ($_POST['focus_product_id'] ?? 0);
    $systemPrompt .= buildAiSalesContext($message, $focusPid);
}

// ── LANGKAH 5: KIRIM KE AI PROVIDER ─────────────────────────────────────
$provider = getSetting('ai_chat_provider', 'gemini');
$apiKey   = (string) getSetting('ai_chat_api_key', '');
if ($apiKey === '') {
    chatJson(503, ['error' => 'Layanan chat belum dikonfigurasi.']);
}

/** HTTP POST JSON. Return [http_code, body|null]. */
function chatHttpPost(string $url, array $body, array $headers, int $timeout = 15): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            error_log('chatHttpPost curl error: ' . $err);
            return [0, null];
        }
        return [$code, $resp];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $payload,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $mm)) {
        $code = (int) $mm[1];
    }
    return [$code, $resp === false ? null : $resp];
}

$reply = null;

try {
    // URL/body/header dirakit terpusat (helpers.php::aiChatBuildRequest) —
    // menambah provider tak lagi menyentuh file ini.
    $req = aiChatBuildRequest($provider, $apiKey, [
        'system'      => $systemPrompt,
        'history'     => $cleanHistory,
        'user'        => $message,
        'max_tokens'  => 500,
        'temperature' => 0.7,
    ]);
    [$code, $body] = chatHttpPost($req['url'], $req['body'], $req['headers']);

    if ($code < 200 || $code >= 300) {
        error_log('chat/send ' . $provider . ' http ' . $code . ' — ' . mb_substr((string) $body, 0, 300));
        chatJson(503, ['error' => aiChatErrorMessage($code)]);
    }
    $data = $body !== null ? json_decode($body, true) : null;
    if (!is_array($data)) {
        error_log('chat/send ' . $provider . ' invalid json');
        chatJson(503, ['error' => 'Respons AI tidak valid.']);
    }
    $reply = aiChatExtractText($provider, $data);
} catch (Throwable $e) {
    error_log('chat/send provider exception: ' . $e->getMessage());
    chatJson(503, ['error' => aiChatErrorMessage(0, $e->getMessage())]);
}

if (!is_string($reply) || trim($reply) === '') {
    error_log('chat/send empty reply from provider ' . $provider);
    chatJson(503, ['error' => aiChatErrorMessage(-1)]);
}
$reply = trim($reply);

// ── LANGKAH 6: DETEKSI FALLBACK WHATSAPP ────────────────────────────────
$showWa = false;
if (getSetting('ai_chat_fallback_wa') === '1' && getSetting('ai_chat_fallback_wa_number', '') !== '') {
    $fallbackKeywords = ['tidak tahu', 'tidak bisa', 'maaf', 'tidak dapat', 'hubungi', 'admin'];
    $replyLower = mb_strtolower($reply);
    foreach ($fallbackKeywords as $kw) {
        if (mb_strpos($replyLower, $kw) !== false) {
            $showWa = true;
            break;
        }
    }
}

// ── LANGKAH 7: RETURN ───────────────────────────────────────────────────
// Lead sudah tertangkap di sesi ini → percakapan lanjutan ikut ke transcript.
if (!empty($_POST['lead_token'])) {
    appendChatTranscript((string) $_POST['lead_token'], $message, $reply);
}

chatJson(200, ['reply' => $reply, 'show_wa' => $showWa]);
