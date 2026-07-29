<?php

namespace App\Services\WhatsApp;

use InvalidArgumentException;

class PhoneNumberNormalizer
{
    public function normalize(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        if (strlen($digits) < 9 || strlen($digits) > 16) {
            throw new InvalidArgumentException('Nomor WhatsApp tidak valid. Gunakan format 08xx atau 628xx.');
        }

        return $digits;
    }
}
