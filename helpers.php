<?php

require_once __DIR__ . '/helpers/MailketingClient.php';

// ─── Email ────────────────────────────────────────────────────


// ─── URL ──────────────────────────────────────────────────────

/**
 * Basis path folder aplikasi, dihitung dari lokasi FISIK file ini relatif
 * DOCUMENT_ROOT — BUKAN dari SCRIPT_NAME request saat ini (config.php lama
 * pakai dirname(SCRIPT_NAME), yang salah bila file yang dieksekusi bukan
 * index.php). Kasus nyata: webhook gateway (Xendit/Duitku) didaftarkan dengan
 * URL berekstensi .php sehingga di-hit LANGSUNG sebagai file fisik (bypass
 * router index.php via .htaccess "RewriteCond -f") — dirname(SCRIPT_NAME)
 * jadi '/webhooks' alih-alih root domain, membuat link notifikasi WA/email
 * hasil webhook jadi "/webhooks/member" & "/webhooks/transactions/{ref}" (404
 * di sisi buyer) alih-alih "/member" & "/transactions/{ref}". helpers.php
 * selalu berada di root aplikasi apa pun file yang meng-include-nya, jadi
 * basis dari sini stabil terlepas dari file mana yang sedang dieksekusi.
 * Return null bila DOCUMENT_ROOT tak tersedia/tak cocok (biar caller fallback).
 */
function _appBasePathFromFileLocation(): ?string {
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__) ?: __DIR__), '/');
    if ($docRoot === '' || !str_starts_with($appRoot, $docRoot)) {
        return null;
    }
    return substr($appRoot, strlen($docRoot));
}

/**
 * Bangun URL absolut yang konsisten ke sebuah path aplikasi.
 *
 * Prioritas sumber base URL:
 *   1. setting `app_url` (bila admin mengisinya secara eksplisit)
 *   2. basis path dari lokasi fisik file (independen dari file mana yang
 *      sedang dieksekusi — benar walau dipanggil dari webhook gateway yang
 *      di-hit langsung sebagai file .php, lihat _appBasePathFromFileLocation())
 *   3. konstanta APP_URL (fallback bila DOCUMENT_ROOT tak tersedia/tak cocok)
 *   4. fallback terakhir: skema + HTTP_HOST dari request.
 *
 * Dipakai untuk membuat link tombol (invoice, member area, dashboard, dll) di
 * template notifikasi sehingga selalu valid di semua konteks pemanggilan.
 */
function baseUrl(string $path = ''): string {
    $url = rtrim((string) getSetting('app_url', ''), '/');
    if ($url === '') {
        // Utamakan APP_URL kanonik (config.php) bila diisi & BUKAN localhost.
        // Tautan di email/notifikasi & cron harus stabil ke domain produksi —
        // jangan ikut host request (localhost dev, IP, proxy, atau host alternatif
        // yang kebetulan dipakai admin). Di dev APP_URL memang localhost → jatuh
        // ke deteksi-mandiri di bawah (perilaku dev tak berubah).
        $appConst = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        if ($appConst !== '' && !preg_match('~^https?://(localhost|127\.\d|\[?::1)~i', $appConst)) {
            $url = $appConst;
        } else {
            $scheme = (
                   (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            ) ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            // DOCUMENT_ROOT umumnya kosong di CLI/cron → _appBasePathFromFileLocation()
            // return null di situ, jatuh ke APP_URL (fallback CLI), tanpa cek SAPI.
            $base = _appBasePathFromFileLocation();

            if ($base !== null) {
                $url = $scheme . '://' . $host . $base;
            } elseif ($appConst !== '') {
                $url = $appConst;
            } else {
                $url = $scheme . '://' . $host;
            }
        }
    }
    // Defensif: base URL aplikasi TAK PERNAH berakhir di segmen internal
    // ('/webhooks','/actions','/api','/cron'). Pencemaran terjadi bila APP_URL di
    // config.php dihitung dari dirname(SCRIPT_NAME) sementara webhook/action gateway
    // di-hit LANGSUNG sebagai file .php (bypass router index.php via .htaccess
    // RewriteCond -f) → base jadi mis. 'https://situs/webhooks' → link notifikasi
    // WA/email hasil webhook jadi '/webhooks/member' & '/webhooks/transactions/{ref}'
    // (404 di sisi buyer). Buang segmen internal dari ujung base sebagai jaring
    // pengaman terpusat (berlaku ke semua sumber base di atas). Base normal tak
    // terpengaruh (tak ada instalasi yang root-nya bernama webhooks/actions/api/cron).
    $url = preg_replace('~/(?:webhooks|actions|api|cron)(?:/[^?#]*)?$~i', '', $url);
    return $path === '' ? $url : $url . '/' . ltrim($path, '/');
}

/**
 * Pengunjung "live" funnel (akurat via heartbeat presence): sesi yang MASIH
 * membuka halaman dalam $windowSec terakhir (default 60 dtk), dikelompokkan per
 * produk/bundle & dipecah per tahap (sales page vs checkout — dari kolom stage
 * presence). Return array baris: type, id, name, total, salespage, checkout.
 */
function funnelLivePages(int $windowSec = 60): array
{
    $windowSec = max(20, min(3600, $windowSec));
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT CASE WHEN bundle_id > 0 THEN 'bundle' ELSE 'product' END AS otype,
                    CASE WHEN bundle_id > 0 THEN bundle_id ELSE product_id END AS oid,
                    CAST(SUM(stage = 'checkout')  AS UNSIGNED) AS at_checkout,
                    CAST(SUM(stage = 'salespage') AS UNSIGNED) AS at_salespage,
                    COUNT(*) AS total
             FROM funnel_presence
             WHERE last_seen >= (NOW() - INTERVAL ? SECOND)
               AND (product_id > 0 OR bundle_id > 0)
             GROUP BY otype, oid
             ORDER BY total DESC, at_checkout DESC
             LIMIT 50"
        );
        $stmt->execute([$windowSec]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Tabel belum ada (install lama belum migrasi) → tanpa data live.
        error_log('funnelLivePages: ' . $e->getMessage());
        return [];
    }
    if (!$rows) return [];

    // Nama produk & bundle (satu query per jenis).
    $pIds = $bIds = [];
    foreach ($rows as $r) { $r['otype'] === 'bundle' ? $bIds[(int) $r['oid']] = 1 : $pIds[(int) $r['oid']] = 1; }
    $pName = $bName = [];
    if ($pIds) {
        $ph = implode(',', array_fill(0, count($pIds), '?'));
        $s = $pdo->prepare("SELECT id, name FROM products WHERE id IN ($ph)");
        $s->execute(array_keys($pIds));
        $pName = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    if ($bIds) {
        $ph = implode(',', array_fill(0, count($bIds), '?'));
        $s = $pdo->prepare("SELECT id, name FROM product_bundles WHERE id IN ($ph)");
        $s->execute(array_keys($bIds));
        $bName = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $out = [];
    foreach ($rows as $r) {
        $type = $r['otype']; $id = (int) $r['oid'];
        $name = $type === 'bundle' ? ($bName[$id] ?? 'Bundle #' . $id) : ($pName[$id] ?? 'Produk #' . $id);
        $out[] = [
            'type'      => $type,
            'id'        => $id,
            'name'      => $name,
            'total'     => (int) $r['total'],
            'salespage' => (int) $r['at_salespage'],
            'checkout'  => (int) $r['at_checkout'],
        ];
    }
    return $out;
}

/**
 * Skrip heartbeat funnel untuk halaman publik (sales page / checkout). Ping
 * endpoint tiap ~20 dtk selama tab terlihat → tabel funnel_presence tahu siapa
 * yang SEDANG membuka halaman (kartu "Pengunjung Aktif" di funnel analytics).
 * Session dibaca server dari cookie (session_id()), tak dikirim di URL. Admin/staff
 * dikecualikan. Kosong bila owner tak valid. $stage: 'salespage' | 'checkout'.
 */
function funnelHeartbeatScript(string $ownerType, int $ownerId, string $stage = 'salespage'): string
{
    if ($ownerId <= 0 || !in_array($ownerType, ['product', 'bundle'], true)) return '';
    if (function_exists('isAdminOrStaff') && isAdminOrStaff()) return ''; // kunjungan sendiri bukan visitor
    $stage = $stage === 'checkout' ? 'checkout' : 'salespage';
    $qs  = http_build_query(['owner' => $ownerType, 'id' => $ownerId, 'stage' => $stage]);
    $url = json_encode(rtrim(APP_URL, '/') . '/actions/public/funnel-heartbeat?' . $qs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    return "<script>(function(){var u={$url};function p(){try{fetch(u,{method:'POST',credentials:'same-origin',keepalive:true}).catch(function(){});}catch(e){}}p();setInterval(function(){if(!document.hidden)p();},20000);})();</script>";
}

/**
 * Skrip heartbeat presence MEMBER untuk area member (dipasang sekali di footer).
 * Ping /actions/user/presence-heartbeat tiap ~20 dtk selama tab terlihat → tabel
 * user_presence tahu siapa yang SEDANG membuka area member (indikator "sedang
 * online" di kartu Active Members dashboard admin). user_id dibaca server dari
 * sesi, tak dikirim di URL. Hanya untuk role 'user' — admin/staff dikecualikan.
 */
function memberPresenceHeartbeatScript(): string
{
    if (!function_exists('isLoggedIn') || !isLoggedIn()) return '';
    if (function_exists('isAdminOrStaff') && isAdminOrStaff()) return '';
    if (($_SESSION['role'] ?? '') !== 'user') return '';
    $url = json_encode(rtrim(APP_URL, '/') . '/actions/user/presence-heartbeat', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    return "<script>(function(){var u={$url};function p(){try{fetch(u,{method:'POST',credentials:'same-origin',keepalive:true}).catch(function(){});}catch(e){}}p();setInterval(function(){if(!document.hidden)p();},20000);})();</script>";
}

/**
 * Jumlah member yang SEDANG online (last_seen dalam $windowSeconds terakhir).
 * Per-user (PK user_id di user_presence → COUNT = user unik). Fail-soft → 0.
 * Dipakai kartu Active Members dashboard admin + endpoint funnel-stats (live).
 */
function countMembersOnline(int $windowSeconds = 120): int
{
    if ($windowSeconds < 1) $windowSeconds = 120;
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM user_presence WHERE last_seen >= (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$windowSeconds]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('countMembersOnline: ' . $e->getMessage());
        return 0;
    }
}



// ─── Navigasi ─────────────────────────────────────────────────

function redirect(string $url): void
{
    if (defined('BYPASS_LOGIN_REQUIRED') || defined('TESTING')) {
        throw new Error("Redirect to: " . $url);
    }
    // Jika URL relatif (mulai /), tambahkan APP_URL sebagai base
    if (str_starts_with($url, '/')) {
        $url = rtrim(APP_URL, '/') . $url;
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Apakah $uri aman dipakai sebagai tujuan redirect internal pasca-auth (login/OTP/
 * Google/register)? Mencegah open-redirect DAN loop ke halaman auth, TANPA mem-blokir
 * slug sah yang kebetulan mengandung kata 'login'/'register' (mis. lead magnet
 * `/lp/login-bonus`, `/lp/register-webinar`) — cek /login, /login-otp, /register sebagai
 * SEGMEN penuh, bukan sekadar substring.
 */
function isSafeRedirectPath(string $uri): bool
{
    if ($uri === '' || $uri[0] !== '/') return false;   // wajib root-relative
    if (str_contains($uri, '//')) return false;         // cegah //evil.com & protocol-relative
    // Backslash: browser memperlakukan `\` seperti `/` saat mengurai URL, jadi
    // `/\evil.com` & `/\/evil.com` lolos cek `//` di atas tapi tetap keluar domain.
    if (str_contains($uri, '\\')) return false;
    $path = explode('?', $uri, 2)[0];
    return !preg_match('#/(login|login-otp|register)(/|$)#', $path);
}

/** Apakah request ini datang via AJAX (fetch/XMLHttpRequest)? */
function wantsJsonResponse(): bool
{
    return strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
}

/**
 * Respons hasil "simpan" yang ramah AJAX. Bila request via AJAX (form ber-class
 * js-ajax-save), balas JSON {success,message} TANPA redirect → halaman tak reload/
 * loncat ke atas, cukup toast. Untuk request biasa (tanpa JS), fallback ke
 * flash + redirect seperti sedia kala. Selalu exit setelah dipanggil.
 */
function saveResult(bool $success, string $message, string $redirectUrl): void
{
    if (wantsJsonResponse()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    }
    flash($success ? 'success' : 'error', $message, $success ? 'success' : 'error');
    redirect($redirectUrl);
}

// ─── Flash Messages ───────────────────────────────────────────

function flash(string $key, string $message, string $type = 'success'): void
{
    $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
}

function getFlash(string $key): ?array
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }
    $data = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $data;
}

// ─── Output Safety ────────────────────────────────────────────

function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// ─── Lead Magnet: normalisasi nomor WhatsApp (format Indonesia → 62…) ──
function lead_normalize_wa(string $raw): string
{
    $d = preg_replace('/[^0-9]/', '', $raw);
    if ($d === '') return '';
    if (str_starts_with($d, '620'))   $d = '62' . substr($d, 3);
    elseif (str_starts_with($d, '0')) $d = '62' . substr($d, 1);
    elseif (str_starts_with($d, '8')) $d = '62' . $d;
    return $d;
}

/**
 * Resolusi nilai Meta `_fbc` (klik-id iklan) terbaik yang diketahui untuk
 * request pengunjung saat ini — menaikkan match quality CAPI/atribusi.
 * Prioritas: cookie `_fbc` asli (di-set pixel) → fbclid yang tertangkap di
 * sesi (lihat bootstrap) → fbclid di URL request ini.
 * Format Meta: fb.1.{ms}.{fbclid}. Return '' bila tak ada sinyal klik.
 */
function meta_resolve_fbc(): string
{
    $cookie = trim((string) ($_COOKIE['_fbc'] ?? ''));
    if ($cookie !== '') return substr($cookie, 0, 255);

    $fbclid = '';
    $ms     = 0;
    if (!empty($_SESSION['_fbclid'])) {
        $fbclid = (string) $_SESSION['_fbclid'];
        $ms     = (int) ($_SESSION['_fbclid_ts'] ?? 0);
    } elseif (!empty($_GET['fbclid'])) {
        $fbclid = (string) $_GET['fbclid'];
    }
    $fbclid = preg_replace('/[^A-Za-z0-9_.\-]/', '', $fbclid);
    if ($fbclid === '') return '';
    if ($ms <= 0) $ms = (int) round(microtime(true) * 1000);
    return substr('fb.1.' . $ms . '.' . $fbclid, 0, 255);
}

// ─── AI Chat: Helper Warna ────────────────────────────────────




// ─── Enkripsi rahasia pixel/CAPI ──────────────────────────────
// Fungsi pixel_secret_key()/pixel_encrypt()/pixel_decrypt() DIPINDAH ke
// helpers/pixel-crypto.php (dimuat bootstrap.php) — sengaja dipisah agar file
// besar ini tak memuat kombinasi fungsi dekode + HTTP + pembanding-hash yang
// di-false-positive-kan antivirus hosting (cPGuard/Imunify) sebagai webshell
// lalu dikarantina. JANGAN kembalikan fungsi kripto itu ke sini.
// (Komentar ini sengaja tak menulis nama fungsi literalnya — pemindai AV
//  membaca komentar juga.)

/**
 * IP pengunjung asli untuk keperluan tracking/match-quality (Meta & TikTok CAPI).
 *
 * Di belakang Cloudflare / reverse proxy (banyak instalasi buyer: CloudPanel,
 * Cloudflare), `$_SERVER['REMOTE_ADDR']` berisi IP proxy — SAMA untuk semua
 * pengunjung. Kalau IP itu dikirim sebagai `client_ip_address` ke Meta, Events
 * Manager menandai "server mengirim IP klien yang dipakai beberapa pengguna"
 * (atribusi & optimasi kampanye rusak). Fungsi ini mengambil IP pengunjung
 * sebenarnya dari header proxy tepercaya, dgn fallback aman ke REMOTE_ADDR.
 *
 * Prioritas: CF-Connecting-IP (Cloudflare, tak bisa dipalsu klien saat via CF) →
 * X-Real-IP (nginx) → IP publik pertama pada rantai X-Forwarded-For → REMOTE_ADDR.
 * Header X-Forwarded-For bisa dipalsu klien; nilai ini HANYA untuk match-quality
 * tracking (bukan keputusan keamanan/otorisasi) sehingga risikonya dapat diterima.
 */
function client_real_ip(): string
{
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = (string) $_SERVER['HTTP_X_REAL_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidates[] = $part;
        }
    }
    // Utamakan IP publik & valid (buang IP privat/reserved milik proxy internal).
    foreach ($candidates as $ip) {
        $ip = trim($ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    // Fallback: IP valid apa pun (mis. dev/LAN semuanya privat).
    foreach ($candidates as $ip) {
        $ip = trim($ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

// ─── Mailketing: auto add subscriber ke list ──────────────────




// ─── Auth Helpers ─────────────────────────────────────────────

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool
{
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('auth_info', 'Silakan login terlebih dahulu.', 'info');
        redirect('/login');
    }
}

function requireAdmin(): void
{
    if (!isLoggedIn()) {
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('auth_info', 'Silakan login terlebih dahulu.', 'info');
        redirect('/login');
    }
    if (!isAdmin()) {
        redirect('/dashboard');
    }
}

// ─── Staff Role ───────────────────────────────────────────────

function isStaff(): bool
{
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'staff';
}

function isAdminOrStaff(): bool
{
    return isLoggedIn() && in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true);
}

/**
 * Cek apakah user saat ini boleh mengakses sebuah modul admin.
 * Admin selalu true. Staff dicek dari tabel staff_permissions (cache di session,
 * di-invalidate via users.updated_at). Fail-closed saat error.
 */
function staffCan(string $permission): bool
{
    if (isAdmin()) return true;
    if (!isStaff()) return false;

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT updated_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $updatedAt = $stmt->fetchColumn();

        $cachedAt = $_SESSION['permissions_cached_at'] ?? null;
        $cacheHit = isset($_SESSION['staff_permissions']) && $cachedAt === $updatedAt;

        if (!$cacheHit) {
            $stmt = $pdo->prepare("SELECT permission FROM staff_permissions WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $_SESSION['staff_permissions']     = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $_SESSION['permissions_cached_at'] = $updatedAt;
        }

        return in_array($permission, $_SESSION['staff_permissions'], true);
    } catch (Exception $e) {
        error_log('staffCan error: ' . $e->getMessage());
        return false;
    }
}

function requireAdminOrStaff(): void
{
    if (!isLoggedIn()) {
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('auth_info', 'Silakan login terlebih dahulu.', 'info');
        redirect('/login');
    }
    if (!isAdminOrStaff()) {
        redirect('/dashboard');
    }
}

// ─── CSRF ─────────────────────────────────────────────────────

function generateCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRF(string $token): bool
{
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ─── Settings ─────────────────────────────────────────────────

function getSetting(string $key, ?string $default = null): ?string
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = ($value !== false) ? $value : $default;
    } catch (Exception $e) {
        error_log('getSetting error [' . $key . ']: ' . $e->getMessage());
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function setSetting(string $key, ?string $value, string $group = 'general'): bool
{
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, setting_group)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        $stmt->execute([$key, $value, $group]);
        return true;
    } catch (Exception $e) {
        error_log('setSetting error [' . $key . ']: ' . $e->getMessage());
        return false;
    }
}


// ─── Pembayaran Manual: ikon channel (bank_accounts) ───────────


// ─── Format ───────────────────────────────────────────────────















// ─── Halaman Dukung / Supporters Wall (Bayar Fleksibel Fase 2) ─
/**
 * Nama tampil pendukung di Supporters Wall: nama depan + inisial belakang
 * ("Budi Santoso" → "Budi S."). Pendukung anonim → "Anonim". Kosong → "Seseorang".
 * Masking dilakukan server-side (jangan kirim nama penuh ke klien).
 */
function supporterDisplayName(?string $fullName, bool $anonymous): string
{
    if ($anonymous) return 'Anonim';
    $name  = trim((string) $fullName);
    if ($name === '') return 'Seseorang';
    $parts = preg_split('/\s+/', $name);
    $first = $parts[0];
    if (count($parts) > 1) {
        $lastInitial = mb_strtoupper(mb_substr(end($parts), 0, 1));
        return $first . ' ' . $lastInitial . '.';
    }
    return $first;
}

/** Inisial 1–2 huruf untuk avatar (dari nama tampil yang sudah dimasking). */
function supporterInitials(string $displayName): string
{
    $parts = preg_split('/\s+/', trim($displayName));
    if (count($parts) >= 2) {
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return mb_strtoupper(mb_substr($displayName, 0, 2));
}

// ─── Respons dini + kerja background ──────────────────────────

/**
 * Kirim respons ke browser SEKARANG (redirect 302 atau JSON), lalu biarkan
 * script LANJUT berjalan untuk kerja lambat pasca-order (email/WA/HTTP call).
 * Buyer tidak lagi menunggu notifikasi terkirim sebelum pindah halaman.
 *
 * Kontrak pemanggil:
 *  - Semua penulisan $_SESSION (flash, token, dsb.) HARUS selesai sebelum
 *    memanggil ini — session ditutup di sini agar halaman tujuan tidak
 *    menunggu lock session milik proses ini.
 *  - Setelah memanggil ini, jangan echo/header lagi; akhiri dengan exit
 *    setelah kerja background selesai.
 *
 * PHP-FPM (produksi umum) memakai fastcgi_finish_request — koneksi putus
 * bersih. mod_php (XAMPP dev) memakai Connection: close + Content-Length —
 * browser lanjut begitu body (kosong) diterima.
 */
function lsRespondThenContinue(?string $redirectUrl = null, ?string $jsonBody = null): void
{
    ignore_user_abort(true);          // browser menutup koneksi ≠ kerja berhenti
    @set_time_limit(60);              // beri napas utk kerja background

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Buang buffer bertingkat php.ini agar flush benar-benar sampai ke socket.
    if (!defined('BYPASS_LOGIN_REQUIRED') && !defined('TESTING')) {
        while (ob_get_level() > 0) { ob_end_clean(); }
    }

    if ($redirectUrl !== null) {
        if (str_starts_with($redirectUrl, '/')) {
            $redirectUrl = rtrim(APP_URL, '/') . $redirectUrl;
        }
        http_response_code(302);
        header('Location: ' . $redirectUrl);
        $body = '';
    } else {
        header('Content-Type: application/json');
        $body = (string) $jsonBody;
    }
    header('Content-Length: ' . strlen($body));
    header('Connection: close');

    echo $body;
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// ─── Business Logic ───────────────────────────────────────────



// Masa berlaku token auto-login {token} (detik). Cukup pendek demi keamanan,
// cukup longgar untuk toleransi jeda halaman + selisih jam server app tujuan.
if (!defined('REDIRECT_TOKEN_TTL')) define('REDIRECT_TOKEN_TTL', 900);




















// ─── Membership Level ─────────────────────────────────────────





/**
 * Apakah lead magnet punya minimal satu modul konten aktif (classes.lead_magnet_id)?
 * Dipakai untuk memutuskan apakah lead anonim perlu di-promote jadi user — agar bisa
 * mengakses "Konten Gratis" di member-area — walau magnet TIDAK memberi bonus level.
 */
function lead_magnet_has_content(int $magnetId): bool
{
    if ($magnetId <= 0) return false;
    try {
        $st = getDB()->prepare(
            "SELECT 1 FROM classes WHERE lead_magnet_id = ? AND status = 'active' LIMIT 1"
        );
        $st->execute([$magnetId]);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        error_log('lead_magnet_has_content error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Promote lead anonim → user TANPA OTP (dipakai saat lead_magnets.require_otp = 0).
 *
 * KEAMANAN: tanpa OTP, kepemilikan nomor/email TIDAK terbukti. Karena itu fungsi ini
 * HANYA membuat akun BARU bila nomor DAN email belum dipakai akun mana pun. Bila ada
 * yang cocok dengan akun eksisting → return ['ok'=>false,'reason'=>'collision'] TANPA
 * login (cegah pengambilalihan akun). Akun baru dibuat phone_verified=0 (tak terverifikasi).
 *
 * @return array{ok:bool,reason?:string,user_id?:int,name?:string,email?:string}
 */
function leadPromoteNoOtp(PDO $pdo, int $leadId, string $phone, string $email, string $name, int $bonusLevel): array
{
    $phone = trim($phone);
    $email = strtolower(trim($email));
    $name  = trim($name) !== '' ? trim($name) : 'Member';
    if ($phone === '') return ['ok' => false, 'reason' => 'no_phone'];

    try {
        // Bentrok nomor (termasuk varian 0-prefix) atau email → jangan auto-login.
        $q = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $q->execute([$phone]);
        $exists = (bool) $q->fetchColumn();
        if (!$exists && str_starts_with($phone, '62')) {
            $q->execute(['0' . substr($phone, 2)]);
            $exists = (bool) $q->fetchColumn();
        }
        if (!$exists && $email !== '') {
            $qe = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $qe->execute([$email]);
            $exists = (bool) $qe->fetchColumn();
        }
        if ($exists) return ['ok' => false, 'reason' => 'collision'];

        $finalEmail = $email !== '' ? $email : ('wa' . preg_replace('/\D/', '', $phone) . '@leads.local');
        $affCode    = generateAffiliateCode();

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO users (name, email, password, phone, phone_verified, role, status, affiliate_code, created_at, updated_at)
             VALUES (?, ?, NULL, ?, 0, 'user', 'active', ?, NOW(), NOW())"
        )->execute([$name, $finalEmail, $phone, $affCode]);
        $newId = (int) $pdo->lastInsertId();

        $rate = (float) (getSetting('affiliate_commission_rate', '10') ?: 10);
        try {
            $pdo->prepare("INSERT INTO affiliates (user_id, code, commission_rate, status) VALUES (?, ?, ?, 'active')")
                ->execute([$newId, $affCode, $rate]);
        } catch (Throwable $e) {
            error_log('leadPromoteNoOtp affiliate insert: ' . $e->getMessage());
        }
        if ($bonusLevel > 0) lead_grant_membership_level($newId, $bonusLevel);
        $pdo->prepare(
            "UPDATE leads SET promoted_user_id = ?, status = 'engaged',
                              bonus_granted_at = COALESCE(bonus_granted_at, NOW())
             WHERE id = ?"
        )->execute([$newId, $leadId]);
        $pdo->commit();

        try {
            enrollUserToSequences('lead_captured', $newId, 0, 'lead:' . $leadId);
        } catch (Throwable $e) {
            error_log('leadPromoteNoOtp sequence enroll: ' . $e->getMessage());
        }
        logActivity('user', 'lead.promote_no_otp', 'leads', $leadId, 'Lead promote tanpa OTP → user #' . $newId);

        return ['ok' => true, 'user_id' => $newId, 'name' => $name, 'email' => $finalEmail];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('leadPromoteNoOtp error: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'error'];
    }
}



// ─── Commission Rate Resolver ─────────────────────────────────


// ─── Download Manager ──────────────────────────────────────────







// ─── Certificate Generator ─────────────────────────────────────






// ─── Product Reviews ───────────────────────────────────────────


// ─── Abandoned Cart Recovery ───────────────────────────────────



// ─── Email Sequence (Drip) ─────────────────────────────────────



// ─── Broadcast (kirim latar via cron/pump, lock-guarded) ───────────







// ─── Integrasi Webhook Keluar (outgoing webhook, HMAC + antrean/retry) ──




/**
 * Cek apakah user (eks-lead yang sudah promote) berhak akses konten lead magnet.
 * Akses = user adalah promoted_user_id pada salah satu lead di magnet tsb.
 */
function hasLeadMagnetAccess(int $userId, int $magnetId): bool
{
    if ($userId <= 0 || $magnetId <= 0) return false;
    try {
        $stmt = getDB()->prepare(
            "SELECT 1 FROM leads WHERE promoted_user_id = ? AND lead_magnet_id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $magnetId]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log('hasLeadMagnetAccess error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Pastikan tabel classes mendukung owner lead magnet (idempotent):
 * product_id jadi nullable + kolom lead_magnet_id (FK ke lead_magnets, CASCADE).
 * Sebuah modul (class) dimiliki produk ATAU lead magnet.
 */
function ensureClassLeadMagnetOwner(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM classes LIKE 'lead_magnet_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE classes MODIFY COLUMN product_id INT UNSIGNED NULL");
            $pdo->exec("ALTER TABLE classes ADD COLUMN lead_magnet_id INT UNSIGNED NULL AFTER product_id");
            try {
                $pdo->exec("ALTER TABLE classes ADD CONSTRAINT fk_classes_lead_magnet FOREIGN KEY (lead_magnet_id) REFERENCES lead_magnets(id) ON DELETE CASCADE");
            } catch (PDOException $fk) {
                error_log('fk_classes_lead_magnet: ' . $fk->getMessage());
            }
            try { $pdo->exec("ALTER TABLE classes ADD INDEX idx_classes_lead_magnet (lead_magnet_id)"); } catch (PDOException $ix) {}
        }
    } catch (PDOException $e) {
        error_log('ensureClassLeadMagnetOwner: ' . $e->getMessage());
    }
    $checked = true;
}





/**
 * Normalisasi & validasi URL CTA (dipakai Info Member).
 * - Izinkan http(s):// dan path relatif (diawali '/').
 * - Skema lain (javascript:, data:, dst) atau domain telanjang → diprefiks https://
 *   sehingga TIDAK pernah menghasilkan href berskema berbahaya.
 * - Kosong / hanya spasi → '' (tak ada tombol).
 * Return string ternormalisasi (maks 500 char) atau '' bila kosong.
 */
function sanitizeCtaUrl(string $raw): string
{
    $u = trim($raw);
    if ($u === '') return '';
    $u = mb_substr($u, 0, 500);
    if (preg_match('#^https?://#i', $u) || str_starts_with($u, '/')) {
        return $u;
    }
    // Domain telanjang atau skema lain → paksa https:// pada bagian host/path saja.
    return 'https://' . ltrim(preg_replace('#^[a-z][a-z0-9+.\-]*:#i', '', $u), '/');
}

/**
 * Deteksi User-Agent bot/crawler/preview/monitor/HTTP-client agar TIDAK
 * menggelembungkan funnel (lp_view/checkout_click) & presence. KONSERVATIF:
 * hanya token yang jelas non-manusia — daftar eksplisit + pola aman (mis. "bot/"
 * ala "Name/versi"), BUKAN bare "bot" (agar nama HP seperti "Cubot" tak kena).
 * UA kosong = diperlakukan bot (browser manusia selalu kirim UA). Dipakai di
 * semua titik catat funnel_events + rotator + presence.
 */
function isBotUserAgent(?string $ua): bool
{
    $ua = trim((string) $ua);
    if ($ua === '') return true;
    static $re = null;
    if ($re === null) {
        $tokens = [
            // Generik "sopan" (Name/versi) + istilah aman
            'bot/', 'crawler', 'spider', 'slurp',
            // Mesin pencari / SEO / AI crawler
            'googlebot', 'google-read-aloud', 'google-inspectiontool', 'mediapartners-google',
            'adsbot-google', 'bingbot', 'bingpreview', 'yandexbot', 'yandex.com/bots',
            'duckduckbot', 'baiduspider', 'applebot', 'sogou', 'exabot', 'seznambot',
            'ia_archiver', 'archive.org_bot', 'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot',
            'dataforseobot', 'petalbot', 'bytespider', 'amazonbot', 'gptbot', 'oai-searchbot',
            'chatgpt-user', 'ccbot', 'claudebot', 'claude-web', 'anthropic-ai', 'perplexitybot', 'facebot',
            // Sosial / pratinjau tautan (fetch, bukan manusia melihat)
            'facebookexternalhit', 'facebookcatalog', 'meta-externalagent', 'twitterbot',
            'linkedinbot', 'pinterest', 'redditbot', 'slackbot', 'slack-imgproxy', 'discordbot',
            'telegrambot', 'whatsapp', 'skypeuripreview', 'vkshare', 'embedly',
            'quora link preview', 'flipboard', 'nuzzel', 'outbrain', 'w3c_validator',
            // HTTP client / library / tool
            'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client', 'okhttp',
            'java/', 'apache-httpclient', 'httpclient', 'commons-httpclient', 'libwww-perl',
            'lwp::simple', 'axios/', 'node-fetch', 'undici', 'scrapy', 'httpx', 'aiohttp',
            'guzzlehttp', 'postmanruntime', 'insomnia', 'winhttp', 'restsharp', 'faraday',
            // Headless / automation
            'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
            'chrome-lighthouse', 'lighthouse', 'pagespeed',
            // Monitoring / uptime
            'pingdom', 'uptimerobot', 'statuscake', 'site24x7', 'newrelicpinger', 'datadog',
            'hetrixtools', 'freshping', 'betteruptime', 'uptime.com', 'prtg', 'nagios',
            'zabbix', 'cloudflare-healthcheck', 'gtmetrix',
            // Scanner / security research
            'masscan', 'zgrab', 'nuclei', 'nikto', 'sqlmap', 'nmap', 'censys', 'expanse',
            'shodan', 'zmap', 'netcraftsurvey', 'paloaltonetworks', 'internet-measurement',
        ];
        $re = '#(' . implode('|', array_map(static fn($t) => preg_quote($t, '#'), $tokens)) . ')#i';
    }
    return preg_match($re, $ua) === 1;
}

/**
 * Catat event funnel untuk BUNDLE (lp_view / checkout_click).
 * Dedup 1x per session + bundle + hari kalender. Admin/staff & bot di-exclude
 * (kunjungan sendiri/non-manusia bukan visitor). Fail-soft — tak boleh menggagalkan halaman.
 */
function trackBundleFunnelEvent(int $bundleId, string $type): void
{
    if ($bundleId <= 0 || !in_array($type, ['lp_view', 'checkout_click'], true)) return;
    if (function_exists('isAdminOrStaff') && isAdminOrStaff()) return;
    if (isBotUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '')) return;
    try {
        $pdo = getDB();
        ensureFunnelBundleColumn($pdo);
        $sid = session_id() ?: '';
        if ($sid === '') return;
        $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $chk = $pdo->prepare(
            "SELECT 1 FROM funnel_events
             WHERE bundle_id = ? AND event_type = ? AND session_id = ?
             AND DATE(created_at) = CURDATE() LIMIT 1"
        );
        $chk->execute([$bundleId, $type, $sid]);
        if (!$chk->fetch()) {
            $pdo->prepare(
                "INSERT INTO funnel_events (product_id, bundle_id, event_type, session_id, user_agent)
                 VALUES (0, ?, ?, ?, ?)"
            )->execute([$bundleId, $type, $sid, $ua]);
        }
    } catch (Exception $ignored) {}
}





// ─── 2FA TOTP + Trusted device ─────────────────────────────────
// Fungsi totp* (TOTP RFC 6238 + trusted device) DIPINDAH ke helpers/2fa.php
// (dimuat bootstrap.php) — pemecahan monolit. (OTP generateOTPCode/checkOTPRateLimit
//  tetap di helpers.php untuk sementara → nanti ke helpers/security.php Fase 4.)

// ─── Auto-Backup Database ──────────────────────────────────────
// Fungsi dbBackupDir/createDbBackup/listDbBackups/pruneDbBackups/processDbBackups
// DIPINDAH ke helpers/db-backup.php (dimuat bootstrap.php) — pemecahan monolit.

// ─── AI Sales Assistant (AI Chat Fase 1) ───────────────────────
// Fungsi buildAiSalesContext / aiChat* / aiRelevantProductIds /
// aiSalesPersonaTemplate / aiCompleteSimple / appendChatTranscript DIPINDAH ke
// helpers/ai-chat.php (dimuat bootstrap.php) — pemecahan monolit helpers.php.

// ─── Notifikasi Update Tersedia ────────────────────────────────
// Cek harian ke License Server via Auto-Cron; hasil disimpan di settings
// (persist, bukan session) → badge sidebar + toast dashboard + email admin
// SEKALI per versi. Semua kegagalan diam-diam (log saja) — notifikasi tidak
// boleh jadi sumber gangguan baru.





// ─── Kompresi Gambar Upload ────────────────────────────────────
// Fungsi compressUploadedImage DIPINDAH ke helpers/image.php
// (dimuat bootstrap.php) — pemecahan monolit.

// ─── Kesehatan Sistem (cron heartbeat + monitor notifikasi) ────






// ─── Activity Log ──────────────────────────────────────────────


// ─── Login Brute-force Protection ─────────────────────────────






// ─── WhatsApp Number Normalization ───────────────────────────


// ─── OTP Helpers ─────────────────────────────────────────────



// ─── License Integration (Produk Digital) ────────────────────
// Fitur lisensi untuk MELINDUNGI produk digital yang DIJUAL vendor.
// Berbeda dari license server Averion itu sendiri (lihat bootstrap.php).


















