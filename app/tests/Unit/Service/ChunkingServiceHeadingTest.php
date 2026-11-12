<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\ChunkingService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit S16: a heading line anywhere in a paragraph turned the whole paragraph into a
 * chapter title, so the text under '## Title' (no blank line) never reached a chunk.
 */
#[AllowMockObjectsWithoutExpectations]
class ChunkingServiceHeadingTest extends TestCase {
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

        $doc = new CourseDocument();
        $doc->setId(3);
        $doc->setCourseId(7);
        $doc->setFileName('notiz.md');
        $doc->setExtractedText($markdown);

        (new ChunkingService($mapper, $this->createMock(LoggerInterface::class)))->chunkDocument($doc);

        $this->assertSame($expected, $inserted);
    }
}
