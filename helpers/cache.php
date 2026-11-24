<?php
// ════════════════════════════════════════════════════════════════════════
// Cache file sederhana ber-TTL untuk hasil agregat MAHAL (dibaca AI/polling:
// Hermes insights, capabilities, MCP analytics). Portabel — tanpa APCu/Redis
// (tak dijamin ada di hosting pembeli). FAIL-SOFT: error cache TIDAK BOLEH
// mematahkan fitur; selalu jatuh ke komputasi langsung.
//
// Bukan untuk data yang harus selalu real-time (uang/akses/transaksi pending).
// Hanya untuk agregat read-only yang boleh basi beberapa puluh detik.
// ════════════════════════════════════════════════════════════════════════

/** Direktori cache (dibuat lazy). Di bawah storage/ (sudah di-hardening .htaccess). */
function _cacheDir(): string
{
    $dir = __DIR__ . '/../storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Path file cache untuk sebuah key (dinormalisasi agar aman jadi nama file). */
function _cachePath(string $key): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $key);
    $safe = substr($safe, 0, 180);
    return _cacheDir() . '/' . $safe . '.json';
}

/**
 * Ambil nilai cache. Return null bila miss / kedaluwarsa / error.
 * CATATAN: nilai null tidak bisa di-cache (null = "tak ada"). Untuk agregat yang
 * selalu array, ini aman.
 *
 * @return mixed|null
 */
function cacheGet(string $key)
{
    try {
        $f = _cachePath($key);
        if (!is_file($f)) {
            return null;
        }
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('exp', $data) || !array_key_exists('val', $data)) {
            return null;
        }
        if ((int) $data['exp'] > 0 && (int) $data['exp'] < time()) {
            return null; // kedaluwarsa
        }
        return $data['val'];
    } catch (Throwable $e) {
        return null; // fail-soft
    }
}

/** Simpan nilai cache dengan TTL (detik). ttl <= 0 = tanpa kedaluwarsa. */
function cacheSet(string $key, $val, int $ttl = 60): void
{
    try {
        $payload = json_encode(
            ['exp' => $ttl > 0 ? time() + $ttl : 0, 'val' => $val],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload !== false) {
            @file_put_contents(_cachePath($key), $payload, LOCK_EX);
        }
    } catch (Throwable $e) {
        // fail-soft: cache gagal tak boleh mematahkan apa pun.
    }
}

/**
 * Pola remember: kembalikan nilai cache bila ada, selain itu jalankan $cb,
 * simpan hasilnya (bila bukan null), lalu kembalikan. Fail-soft — bila cache
 * bermasalah, $cb tetap dijalankan.
 *
 * @return mixed
 */
function cacheRemember(string $key, int $ttl, callable $cb)
{
    $hit = cacheGet($key);
    if ($hit !== null) {
        return $hit;
    }
    $val = $cb();
    if ($val !== null) {
        cacheSet($key, $val, $ttl);
    }
    return $val;
}
