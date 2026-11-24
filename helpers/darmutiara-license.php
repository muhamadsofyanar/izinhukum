<?php

function darmutiaraLicenseClaims(string $token, string $domain): ?array
{
    $publicKey = defined('DARMUTIARA_ISSUER_PUBLIC_KEY')
        ? DARMUTIARA_ISSUER_PUBLIC_KEY
        : getenv('DARMUTIARA_ISSUER_PUBLIC_KEY');
    if (!is_string($publicKey) || !function_exists('sodium_crypto_sign_verify_detached')
        || $domain === '' || strlen($token) > 4096
        || !preg_match('/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/', $token, $parts)
        || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $publicKey)) {
        return null;
    }
    $key = base64UrlDecode($publicKey);
    $signature = base64UrlDecode($parts[2]);
    $body = base64UrlDecode($parts[1]);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        || $body === false || !sodium_crypto_sign_verify_detached($signature, $parts[1], $key)) {
        return null;
    }
    $claims = json_decode($body, true);
    $now = time();
    if (!is_array($claims) || ($claims['iss'] ?? null) !== 'darmutiara-license-v1'
        || ($claims['domain'] ?? null) !== $domain
        || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
        || $claims['iat'] < 0 || $claims['iat'] > $now || $claims['exp'] <= $now
        || $claims['exp'] - $claims['iat'] > 86400) {
        return null;
    }
    return $claims;
}

function requestDarmutiaraLicense(string $key, string $domain): array
{
    if (!preg_match('/\ADRM-[A-Za-z0-9_-]{43}\z/', $key)) {
        return ['error' => 'Masukkan kunci lisensi Darmutiara yang diawali DRM-.'];
    }
    if (!function_exists('curl_init')) {
        return ['error' => 'Ekstensi PHP cURL belum aktif.'];
    }

    $body = '';
    $ch = curl_init('https://lisensi.darmutiara.com/v1/activate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['license_key' => $key, 'domain' => $domain]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 4096) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status === 403) {
        return ['denied' => true, 'error' => 'Penerbit menolak lisensi untuk domain ini.'];
    }
    if ($ok === false || $status !== 200) {
        return ['network_error' => true, 'error' => 'Penerbit lisensi sedang tidak tersedia.'];
    }
    $token = json_decode($body, true)['token'] ?? null;
    $claims = is_string($token) ? darmutiaraLicenseClaims($token, $domain) : null;
    if ($claims === null) {
        return ['error' => 'Bukti aktivasi tidak valid. Periksa kunci publik penerbit, domain, dan jam server.'];
    }
    return ['token' => $token, 'exp' => $claims['exp']];
}
