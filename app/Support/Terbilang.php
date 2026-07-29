<?php

namespace App\Support;

class Terbilang
{
    private const WORDS = [
        '',
        'satu',
        'dua',
        'tiga',
        'empat',
        'lima',
        'enam',
        'tujuh',
        'delapan',
        'sembilan',
        'sepuluh',
        'sebelas',
    ];

    public static function rupiah(int $amount): string
    {
        return ucfirst(trim(self::number(max(0, $amount)))).' rupiah';
    }

    private static function number(int $number): string
    {
        if ($number < 12) {
            return self::WORDS[$number];
        }

        if ($number < 20) {
            return self::number($number - 10).' belas';
        }

        if ($number < 100) {
            return self::number(intdiv($number, 10)).' puluh '.self::number($number % 10);
        }

        if ($number < 200) {
            return 'seratus '.self::number($number - 100);
        }

        if ($number < 1000) {
            return self::number(intdiv($number, 100)).' ratus '.self::number($number % 100);
        }

        if ($number < 2000) {
            return 'seribu '.self::number($number - 1000);
        }

        if ($number < 1000000) {
            return self::number(intdiv($number, 1000)).' ribu '.self::number($number % 1000);
        }

        if ($number < 1000000000) {
            return self::number(intdiv($number, 1000000)).' juta '.self::number($number % 1000000);
        }

        if ($number < 1000000000000) {
            return self::number(intdiv($number, 1000000000)).' miliar '.self::number($number % 1000000000);
        }

        return self::number(intdiv($number, 1000000000000)).' triliun '.self::number($number % 1000000000000);
    }
}
