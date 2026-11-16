<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\ByteRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** GitHub #66: the Range header of the question media endpoint (audio seeking). */
final class ByteRangeTest extends TestCase {
    /** @return array<string, array{0: string, 1: int, 2: array{0: int, 1: int, 2: bool}|false}> */
    public static function cases(): array {
        return [
            'no header' => ['', 100, [0, 99, false]],
            'empty file, no header' => ['', 0, [0, 0, false]],
            'closed' => ['bytes=10-19', 100, [10, 19, true]],
            'open end' => ['bytes=90-', 100, [90, 99, true]],
            'end past the file is clamped' => ['bytes=90-500', 100, [90, 99, true]],
            'suffix' => ['bytes=-10', 100, [90, 99, true]],
            'suffix longer than the file' => ['bytes=-500', 100, [0, 99, true]],
            'start at the end' => ['bytes=100-', 100, false],
            'start after end' => ['bytes=20-10', 100, false],
            'zero suffix' => ['bytes=-0', 100, false],
            'just a dash' => ['bytes=-', 100, false],
            'several ranges' => ['bytes=0-1,5-6', 100, false],
            'other unit' => ['items=0-1', 100, false],
            'negative start' => ['bytes=-5-10', 100, false],
            'any range on an empty file' => ['bytes=0-', 0, false],
        ];
    }

    /** @param array{0: int, 1: int, 2: bool}|false $expected */
    #[DataProvider('cases')]
    public function testParse(string $header, int $size, array|false $expected): void {
        $this->assertSame($expected, ByteRange::parse($header, $size));
    }
}
