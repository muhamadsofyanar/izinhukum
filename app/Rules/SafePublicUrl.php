<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafePublicUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $url = filter_var((string) $value, FILTER_VALIDATE_URL);
        if ($url === false) {
            $fail('URL media tidak valid.');
            return;
        }

        $parts = parse_url((string) $url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($scheme !== 'https') {
            $fail('URL media wajib menggunakan HTTPS.');
            return;
        }

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            $fail('URL media harus mengarah ke host publik.');
            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && ! $this->isPublicIp($host)) {
            $fail('URL media tidak boleh mengarah ke jaringan privat atau lokal.');
            return;
        }

        // Menolak host yang secara eksplisit memetakan ke alamat privat. Kegagalan DNS tidak
        // menggagalkan validasi agar URL CDN baru tetap dapat disimpan, tetapi provider tetap
        // akan menangani kegagalan pengambilan file saat pengiriman.
        $addresses = @gethostbynamel($host);
        if (is_array($addresses)) {
            foreach ($addresses as $address) {
                if (! $this->isPublicIp($address)) {
                    $fail('URL media terdeteksi mengarah ke jaringan privat atau lokal.');
                    return;
                }
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
