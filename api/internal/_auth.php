<?php
/**
 * Guard bersama untuk seluruh endpoint /api/internal/* — fitur "Integrasi Claude"
 * (MCP, Model Context Protocol). Reuse pola api/studio/products.php (Bearer token,
 * hash sha256, tanpa session/CSRF, selalu JSON) + rate-limit checkIpRateLimit() yang
 * sudah dipakai actions/auth/*.
 *
 * FITUR PRODUK — ikut ke SEMUA pembeli Averion via update system. Tiap instalasi
 * punya token & pengaturan sendiri (tabel settings, tanpa migrasi — pola sama
 * studio_api_token_hash: dibuat lazy via setSetting() saat admin generate token).
 *
 * Default AMAN untuk pembeli: mcp_enabled='0' (OFF sampai admin aktifkan sendiri),
 * mcp_mask_pii='1' (email/WA di-mask). Admin instalasi masing-masing yang mengatur
 * ulang dari halaman admin "Integrasi Claude".
 */
require_once __DIR__ . '/../../bootstrap.php';

/** Balas JSON + exit. Satu jalur keluar seragam untuk semua endpoint internal. */
function mcpRespond(int $httpCode, array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    // JSON_INVALID_UTF8_SUBSTITUTE: byte non-UTF-8 pada data (nama/catatan pelanggan)
    // diganti U+FFFD, bukan bikin json_encode gagal senyap (pola sama api/studio).
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        echo '{"error":"encode_failed"}';
        exit;
    }
    http_response_code($httpCode);
    echo $json;
    exit;
}

/**
 * Guard utama — panggil di baris pertama tiap endpoint dengan scope yang endpoint
 * itu butuhkan, mis. mcpAuthenticate('reports:read'). Exit otomatis (403/401/429)
 * bila gagal di titik mana pun; TIDAK PERNAH return pada kegagalan.
 *
 * Urutan cek (lihat dokumen rencana Fase 1): toggle aktif → method → rate-limit →
 * Bearer ada → hash cocok → scope dikenal → catat audit.
 */
function mcpAuthenticate(string $requiredScope): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        mcpRespond(405, ['error' => 'method_not_allowed']);
    }

    if (!mcpEnabled()) {
        mcpRespond(403, ['error' => 'mcp_disabled', 'message' => 'Integrasi Claude (MCP) belum diaktifkan admin instalasi ini.']);
    }

    $ip = client_real_ip();
    // Satu ember rate-limit bersama utk semua endpoint internal (60/menit/IP) —
    // reuse checkIpRateLimit()+recordIpAction() yg sudah dipakai actions/auth/*.
    if (!checkIpRateLimit('mcp_api', $ip, 60, 1)) {
        mcpRespond(429, ['error' => 'rate_limited', 'message' => 'Terlalu banyak permintaan. Coba lagi sebentar.']);
    }
    recordIpAction('mcp_api', $ip);

    $token = mcpExtractBearer();
    if ($token === '') {
        mcpRespond(401, ['error' => 'unauthorized', 'message' => 'Header Authorization Bearer wajib disertakan.']);
    }

    if (!mcpTokenValid($token)) {
        // Token salah ATAU belum pernah dibuat/sudah dicabut → sama-sama 403 (pola api/studio).
        mcpRespond(403, ['error' => 'invalid_token', 'message' => 'Token tidak valid atau sudah dicabut.']);
    }

    // Scope: Fase 1 hanya SATU token per instalasi (admin, tanpa OAuth multi-user —
    // keputusan terkunci) = otomatis punya semua scope read di bawah ini. Parameter
    // $requiredScope tetap WAJIB dideklarasikan tiap endpoint (bukan dekorasi) —
    // itu kontrak yang dipetakan token bertingkat saat Fase 5 menambah scope write
    // terpisah. Scope tak dikenal = bug endpoint, bukan kegagalan auth pengguna.
    $knownScopes = ['reports:read', 'products:read', 'transactions:read', 'affiliates:read', 'members:read', 'support:read', 'analytics:read'];
    if (!in_array($requiredScope, $knownScopes, true)) {
        error_log("mcpAuthenticate: scope tak dikenal '{$requiredScope}'");
        mcpRespond(500, ['error' => 'server_error']);
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    logActivity('admin', 'mcp.api_call', 'mcp', null, "GET {$uri} scope={$requiredScope} ip={$ip}");
}

/**
 * Ekstrak token dari header — TOLERAN thd variasi konfigurasi konektor Claude &
 * proxy hosting. Diterima (urutan cek):
 *   1. Authorization: Bearer <token>   (standar)
 *   2. Authorization: <token>          (mentah, tanpa "Bearer")
 *   3. X-Api-Key / Api-Token / X-Api-Token: <token>  (nama header alternatif —
 *      "api-token" adalah default yg ditawarkan UI konektor Claude)
 * Mengembalikan '' bila tak ada. Reuse oleh endpoint REST & handler MCP.
 */
function mcpExtractBearer(): string {
    // Authorization (Bearer atau mentah)
    $auth = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) { $auth = (string) $_SERVER[$k]; break; }
    }
    $all = function_exists('getallheaders') ? getallheaders() : [];
    if ($auth === '') {
        foreach ($all as $n => $v) {
            if (strcasecmp((string) $n, 'Authorization') === 0) { $auth = (string) $v; break; }
        }
    }
    if ($auth !== '') {
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) return $m[1];
        $t = trim($auth);
        if ($t !== '' && strpos($t, ' ') === false) return $t; // token mentah di Authorization
    }
    // Nama header alternatif (token mentah)
    foreach (['HTTP_X_API_KEY', 'HTTP_API_TOKEN', 'HTTP_X_API_TOKEN'] as $k) {
        if (!empty($_SERVER[$k])) return trim((string) $_SERVER[$k]);
    }
    foreach ($all as $n => $v) {
        $nl = strtolower((string) $n);
        if (in_array($nl, ['x-api-key', 'api-token', 'x-api-token'], true)) {
            $t = trim((string) $v);
            if ($t !== '') return $t;
        }
    }
    return '';
}

/** Token cocok dengan hash tersimpan? (timing-safe). False bila token belum pernah dibuat. */
function mcpTokenValid(string $token): bool {
    $storedHash = trim((string) getSetting('mcp_api_token_hash', ''));
    return $storedHash !== '' && hash_equals($storedHash, hash('sha256', $token));
}

/** Integrasi Claude diaktifkan admin instalasi ini? (toggle, default OFF). */
function mcpEnabled(): bool {
    return (string) getSetting('mcp_enabled', '0') === '1';
}

/**
 * Aksi TULIS (kirim pesan/alert) diizinkan admin? Toggle TERPISAH dari mcp_enabled,
 * DEFAULT MATI. Read tetap jalan saat ini mati; hanya tool ber-tanda write yang
 * digerbangi. Pembeli harus opt-in sadar sebelum Claude bisa mengirim apa pun nyata.
 */
function mcpWriteEnabled(): bool {
    return (string) getSetting('mcp_write_enabled', '0') === '1';
}

/** Masking PII aktif untuk instalasi ini? Default AMAN (ON) bila belum diatur admin. */
function mcpMaskEnabled(): bool {
    return (string) getSetting('mcp_mask_pii', '1') === '1';
}

/** "budi@gmail.com" → "b***@gmail.com". Tak menyentuh domain (masih berguna utk verifikasi). */
function mcpMaskEmail(string $email): string {
    if (!mcpMaskEnabled() || $email === '') return $email;
    $at = strpos($email, '@');
    if ($at === false || $at < 1) return str_repeat('*', max(1, mb_strlen($email)));
    return mb_substr($email, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($email, $at);
}

/** "628123456789" → "6281*****89". Sisakan prefix+akhiran utk verifikasi tanpa bongkar penuh. */
function mcpMaskPhone(string $phone): string {
    if (!mcpMaskEnabled() || $phone === '') return $phone;
    $len = mb_strlen($phone);
    if ($len <= 6) return str_repeat('*', $len);
    return mb_substr($phone, 0, 4) . str_repeat('*', $len - 6) . mb_substr($phone, -2);
}

/** Clamp angka limit dari query string — cegah `limit=999999` membebani DB. */
function mcpClampLimit($raw, int $default, int $max): int {
    $n = (int) $raw;
    if ($n <= 0) return $default;
    return min($n, $max);
}

/**
 * Klausa WHERE tanggal dari daftar periode PUTIH (bukan input mentah user) — aman
 * diinterpolasi langsung ke SQL. $raw yang tak dikenal jatuh ke $fallback.
 * Dipakai bersama reports/dashboard, reports/revenue, reports/product-sales,
 * affiliates/leaderboard — satu sumber kebenaran, hindari switch berulang.
 */
function mcpDateCondition(string $raw, string $column, string $fallback = '30d'): array {
    $period = in_array($raw, ['today', '7d', '30d', '90d', '365d', 'all'], true) ? $raw : $fallback;
    $sql = match ($period) {
        'today' => "DATE({$column}) = CURDATE()",
        '7d'    => "{$column} >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        '90d'   => "{$column} >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
        '365d'  => "{$column} >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)",
        'all'   => '1=1',
        default => "{$column} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", // '30d'
    };
    return ['period' => $period, 'sql' => $sql];
}
