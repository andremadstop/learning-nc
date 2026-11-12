<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\RagImportService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit S01: a paragraph whose first line was a heading lost its body, and a CRLF text
 * stayed one paragraph, so a single heading line in it dropped the whole document.
 */
#[AllowMockObjectsWithoutExpectations]
class RagImportServiceHeadingTest extends TestCase {
    /** @return array<string, array{0: string, 1: list<array{0: string, 1: ?string}>}> */
    public static function cases(): array {
        return HeadingChunkCases::cases();
    }

    /** @param list<array{0: string, 1: ?string}> $expected */
    #[DataProvider('cases')]
    public function testBodyUnderAHeadingReachesAChunk(string $markdown, array $expected): void {
        $inserted = [];
        $mapper = $this->createMock(RagChunkMapper::class);
        $mapper->method('insert')->willReturnCallback(function (RagChunk $chunk) use (&$inserted) {
            $inserted[] = [$chunk->getText(), $chunk->getChapter()];
            return $chunk;
        });

        (new RagImportService($mapper, $this->createMock(LoggerInterface::class)))
            ->importText(7, 'Notiz', $markdown);

        $this->assertSame($expected, $inserted);
    }
}
