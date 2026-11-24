<?php
// POST /actions/admin/test-ai-chat
// Tes koneksi ke provider AI (Gemini / Groq). Dipanggil via AJAX dari halaman
// admin. Selalu mengembalikan JSON. TIDAK pernah membocorkan API key / respons
// mentah ke client.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permintaan tidak valid.']);
    exit;
}

// ── Lepas lock session setelah auth/CSRF: panggilan ke provider AI bisa lama
// (sampai timeout). Tanpa ini, lock file-sesi menahan SEMUA request lain dari
// admin yang sama (pindah menu, aset) → panel terasa "loading". Tak ada tulis
// session setelah titik ini.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Whitelist diambil dari daftar provider terpusat (helpers.php) — dulu daftarnya
// ditulis ulang di sini, jadi provider baru yang muncul di UI akan ditolak diam-diam
// oleh endpoint ini.
$provider = strtolower(trim($_POST['provider'] ?? ''));
if (!in_array($provider, aiChatProviderKeys(), true)) {
    $provider = getSetting('ai_chat_provider', 'gemini');
}

$apiKey = trim($_POST['api_key'] ?? '');
if ($apiKey === '') {
    $apiKey = (string) getSetting('ai_chat_api_key', '');
}

if ($apiKey === '') {
    echo json_encode(['success' => false, 'message' => 'API key belum diisi.']);
    exit;
}

/**
 * HTTP POST JSON sederhana via cURL (fallback stream). Return [http_code, body|null].
 */
function aiChatHttpPost(string $url, array $body, array $headers, int $timeout = 10): array
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
            error_log('aiChatHttpPost curl error: ' . $err);
            return [0, null];
        }
        return [$code, $resp];
    }

    // Fallback: stream context
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

try {
    // Ping ringan (10 token) lewat perakit terpusat — tanpa system prompt & tanpa
    // temperature, sama seperti sebelumnya.
    $req = aiChatBuildRequest($provider, $apiKey, [
        'user'       => 'Balas hanya dengan kata: OK',
        'max_tokens' => 10,
    ]);
    [$code, $body] = aiChatHttpPost($req['url'], $req['body'], $req['headers']);
    $data = $body !== null ? json_decode($body, true) : null;
    if ($code === 200 && is_array($data) && aiChatResponseHasPayload($provider, $data)) {
        echo json_encode(['success' => true, 'message' => 'Koneksi berhasil! Provider siap digunakan.']);
        exit;
    }

    // Gagal — susun pesan aman (tanpa membocorkan payload sensitif).
    $safeMsg = 'Koneksi gagal.';
    $rawMsg   = isset($data['error']['message']) && is_string($data['error']['message']) ? $data['error']['message'] : '';
    $rawLower = mb_strtolower($rawMsg);

    if ($rawMsg !== '' && (str_contains($rawLower, 'quota') || str_contains($rawLower, 'billing') || str_contains($rawLower, 'exceeded'))) {
        $safeMsg = 'Kuota/billing provider habis. Cek limit harian atau aktifkan billing di dashboard provider, lalu coba lagi.';
    } elseif ($rawMsg !== '' && (str_contains($rawLower, 'decommission') || str_contains($rawLower, 'deprecat') || str_contains($rawLower, 'not found') || str_contains($rawLower, 'does not exist') || str_contains($rawLower, 'not supported'))) {
        $safeMsg = 'Model AI tidak lagi didukung provider. Update aplikasi ke versi terbaru untuk model yang aktif.';
    } elseif ($code === 401 || $code === 403 || ($rawMsg !== '' && (str_contains($rawLower, 'api key') || str_contains($rawLower, 'unauthorized') || str_contains($rawLower, 'invalid')))) {
        $safeMsg = 'API key ditolak (tidak valid atau tidak berizin). Periksa kembali API key kamu.';
    } elseif ($code === 429) {
        $safeMsg = 'Terlalu banyak permintaan / kuota habis. Tunggu sebentar lalu coba lagi.';
    } elseif ($code === 0) {
        $safeMsg = 'Gagal menghubungi server provider. Periksa koneksi internet server.';
    } elseif ($rawMsg !== '') {
        $safeMsg = 'Gagal: ' . mb_substr($rawMsg, 0, 200);
    } else {
        $safeMsg = 'Gagal (HTTP ' . (int) $code . '). Periksa API key & provider.';
    }
    error_log('test-ai-chat failed: provider=' . $provider . ' code=' . $code);
    echo json_encode(['success' => false, 'message' => $safeMsg]);
} catch (Throwable $e) {
    error_log('test-ai-chat exception: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat menguji koneksi.']);
}
