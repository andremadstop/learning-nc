<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\ImportVaultCommand;
use OCA\Learning\Tests\Unit\Service\HeadingChunkCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit S25: occ learning:import-vault dropped the text right under a Markdown heading
 * when no blank line separated them (the usual Obsidian layout).
 */
class ImportVaultCommandHeadingTest extends TestCase {
    /** @return array<string, array{0: string, 1: list<array{0: string, 1: ?string}>}> */
    public static function cases(): array {
        // The vault importer never knew setext headings; that case is not part of S25.
        $cases = HeadingChunkCases::cases();
        unset($cases['body directly under setext heading']);
        return $cases;
    }

    /** @param list<array{0: string, 1: ?string}> $expected */
    #[DataProvider('cases')]
    public function testBodyUnderAHeadingReachesAChunk(string $markdown, array $expected): void {
        $class = new \ReflectionClass(ImportVaultCommand::class);
        $command = $class->newInstanceWithoutConstructor();
        $clean = $class->getMethod('cleanMarkdown');
        $split = $class->getMethod('splitIntoChunks');

        $chunks = $split->invoke($command, $clean->invoke($command, $markdown), null);
        $actual = array_map(static fn(array $c): array => [$c['text'], $c['chapter']], $chunks);

        $this->assertSame($expected, $actual);
    }
}
