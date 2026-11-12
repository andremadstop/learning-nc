<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\ChunkSearchService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit S15: the keyword trim only knew a-z0-9äöüß, so every Cyrillic or Arabic word
 * became empty and accented French words were cut ('été' -> 't'); the chat then ran without any
 * course context.
 */
#[AllowMockObjectsWithoutExpectations]
class ChunkSearchServiceKeywordTest extends TestCase {
    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function queries(): array {
        return [
            'Ukrainian' => ['Що таке маршрутизація?', ['таке', 'маршрутизація']],
            'Russian' => ['Что такое маршрутизация?', ['что', 'такое', 'маршрутизация']],
            'Arabic' => ['ما هي الشبكة؟', ['الشبكة']],
            'French accents' => ['Qu’est-ce que la sécurité réseau ?', ['qu’est-ce', 'que', 'sécurité', 'réseau']],
            'German unchanged' => ['Wie funktioniert Subnetting?', ['funktioniert', 'subnetting']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('queries')]
    public function testNonLatinWordsBecomeKeywords(string $query, array $expected): void {
        $seen = null;
        $mapper = $this->createMock(RagChunkMapper::class);
        $mapper->method('searchByKeywords')->willReturnCallback(function (int $courseId, array $keywords) use (&$seen) {
            $seen = $keywords;
            return [];
        });

        (new ChunkSearchService($mapper, $this->createMock(LoggerInterface::class)))->search(7, $query);

        $this->assertSame($expected, $seen);
    }
}
