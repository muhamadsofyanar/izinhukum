<?php

namespace Tests\Unit;

use App\Services\WhatsApp\PhoneNumberNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberNormalizerTest extends TestCase
{
    #[DataProvider('indonesianNumbers')]
    public function test_it_normalizes_indonesian_numbers(string $input, string $expected): void
    {
        self::assertSame($expected, (new PhoneNumberNormalizer())->normalize($input));
    }

    public static function indonesianNumbers(): array
    {
        return [
            ['0812-3456-7890', '6281234567890'],
            ['81234567890', '6281234567890'],
            ['+62 812 3456 7890', '6281234567890'],
            ['0062 812 3456 7890', '6281234567890'],
        ];
    }

    public function test_it_rejects_an_invalid_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PhoneNumberNormalizer())->normalize('123');
    }
}
