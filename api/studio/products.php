<?php
/**
 * Public API — Daftar Produk untuk Averion Studio (integrasi tarik produk).
 *
 * METHOD : GET
 * URL    : /api/studio/products
 * AUTH   : Authorization: Bearer {token} — sha256(token) di-hash_equals dengan
 *          setting `studio_api_token_hash` (di-generate admin di halaman
 *          "Integrasi Studio"). Tanpa header → 401. Token salah/dicabut → 403.
 *
 * Endpoint PUBLIC ber-token: TANPA session login, TANPA CSRF. Selalu balas JSON.
 * READ-ONLY — tidak menulis apa pun kecuali counter rate limit di storage/.
 *
 * PRIVASI (dokumen arsitektur Studio §7.1): response HANYA berisi
 * id, name, price, short_desc, benefits, checkout_url produk AKTIF.
 * DILARANG KERAS menambah field lain (customer/order/revenue/email dsb).
 */

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

function studio_api_respond(int $httpCode, array $payload): void {
    // JSON_INVALID_UTF8_SUBSTITUTE: byte non-UTF-8 pada data produk (lazim dari
    // copy-paste Word/Excel) diganti U+FFFD, BUKAN membuat json_encode gagal
    // senyap lalu `echo false` mencetak string kosong — itulah penyebab respons
    // "HTTP 200 + body kosong" yang bikin Studio gagal "bukan JSON" di sebagian
    // instalasi member (data bersih di satu install, kotor di install lain).
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
 * Rate limit ringan per IP (default 30/menit) berbasis file di storage/ —
 * tanpa migration (settings & tabel tak disentuh). File counter per IP+menit,
 * file menit-menit lama dibersihkan oportunistik.
 */
function studioApiRateLimitOk(int $maxPerMinute = 30): bool {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip !== '') {
            $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
        }
        if ($ip === '') {
            return true; // tanpa IP tak bisa di-rate-limit — biarkan lewat
        }
        $dir = __DIR__ . '/../../storage/studio-api';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return true; // storage tak bisa ditulis — jangan blokir karena infra
        }
        $minute = date('YmdHi');
        $file   = $dir . '/rl-' . hash('crc32b', $ip) . '-' . $minute . '.cnt';
        $count  = is_file($file) ? (int) @file_get_contents($file) : 0;
        if ($count >= $maxPerMinute) {
            return false;
        }
        @file_put_contents($file, (string) ($count + 1), LOCK_EX);
        // Bersihkan file menit lama sesekali (1 dari ~20 request).
        if (random_int(1, 20) === 1) {
            foreach (glob($dir . '/rl-*.cnt') ?: [] as $old) {
                if (strpos($old, '-' . $minute . '.cnt') === false) { @unlink($old); }
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('studioApiRateLimitOk error: ' . $e->getMessage());
        return true;
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        studio_api_respond(405, ['error' => 'method_not_allowed']);
    }

    if (!studioApiRateLimitOk(30)) {
        studio_api_respond(429, ['error' => 'rate_limited', 'message' => 'Terlalu banyak permintaan. Coba lagi sebentar.']);
    }

    // ── Authorization: Bearer ─────────────────────────────────
    $hdr = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) { $hdr = (string) $_SERVER[$k]; break; }
    }
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $n => $v) {
            if (strcasecmp((string) $n, 'Authorization') === 0) { $hdr = (string) $v; break; }
        }
    }
    if (!preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
        studio_api_respond(401, ['error' => 'unauthorized', 'message' => 'Header Authorization Bearer wajib disertakan.']);
    }

    $storedHash = trim((string) getSetting('studio_api_token_hash', ''));
    if ($storedHash === '' || !hash_equals($storedHash, hash('sha256', $m[1]))) {
        // Token salah ATAU sudah dicabut/belum pernah dibuat → sama-sama 403.
        studio_api_respond(403, ['error' => 'forbidden', 'message' => 'Token tidak valid atau sudah dicabut.']);
    }

    // ── Produk aktif — HANYA field yang diizinkan ─────────────
    // Keputusan Ady (gerbang P10): produk AKTIF yang hide_from_catalog IKUT
    // di-expose — penawaran tersembunyi/eksklusif justru bahan promosi member
    // (ini produk milik member sendiri). draft/inactive/coming_soon tetap keluar.
    $rows = getDB()->query(
        "SELECT id, name, slug, price, short_description, description, checkout_mode
           FROM products
          WHERE status = 'active'
          ORDER BY sort_order ASC, name ASC"
    )->fetchAll();

    $products = [];
    foreach ($rows as $r) {
        // benefits: derivasi dari description per baris (strip tag, maks 10 baris)
        // — core tidak punya kolom benefit terstruktur; kosong = [] (Studio aman).
        $benefits = [];
        $descText = trim(strip_tags((string) ($r['description'] ?? '')));
        if ($descText !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $descText) as $line) {
                $line = trim($line, " \t-•*");
                if ($line !== '' && mb_strlen($line) <= 160) {
                    $benefits[] = $line;
                }
                if (count($benefits) >= 10) break;
            }
        }

        // checkout_url publik: mode public → /order/{slug},
        // selain itu → /checkout?product_id= (butuh login, tetap URL sah).
        $checkoutUrl = (($r['checkout_mode'] ?? 'login') === 'public')
            ? APP_URL . '/order/' . $r['slug']
            : APP_URL . '/checkout?product_id=' . (int) $r['id'];

        $products[] = [
            'id'           => (int) $r['id'],
            'name'         => (string) $r['name'],
            'price'        => (float) $r['price'],
            'short_desc'   => (string) ($r['short_description'] ?? ''),
            'benefits'     => $benefits,
            'checkout_url' => $checkoutUrl,
        ];
    }

    studio_api_respond(200, ['products' => $products]);
} catch (Throwable $e) {
    error_log('api/studio/products error: ' . $e->getMessage());
    studio_api_respond(500, ['error' => 'server_error']);
}
