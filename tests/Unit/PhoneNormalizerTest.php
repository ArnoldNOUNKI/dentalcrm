<?php

namespace Tests\Unit;

use App\Services\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            ['03 87 00 00 00', '+33387000000'],
            ['03.87.00.00.00', '+33387000000'],
            ['0033387000000', '+33387000000'],
            ['+33 (0)3 87 00 00 00', '+33387000000'],
            ['33387000000', '+33387000000'],
            ['387000000', '+33387000000'],     // zéro supprimé par Excel
            ['+352 621 123 456', '+352621123456'],
            ['', null],
            ['abc', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalize(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($raw));
    }

    public function test_format(): void
    {
        $this->assertSame('+33 3 87 00 00 00', PhoneNormalizer::format('+33387000000'));
    }
}
