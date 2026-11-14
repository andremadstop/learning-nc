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
            // audit #151: 'що таке' / 'что такое' / 'qu’est-ce que' are stopwords now
            'Ukrainian' => ['Що таке маршрутизація?', ['маршрутизація']],
            'Russian' => ['Что такое маршрутизация?', ['маршрутизация']],
            'Arabic' => ['ما هي الشبكة؟', ['الشبكة']],
            'French accents' => ['Qu’est-ce que la sécurité réseau ?', ['sécurité', 'réseau']],
            'German unchanged' => ['Wie funktioniert Subnetting?', ['funktioniert', 'subnetting']],
            // audit #151: stopwords of fr/ru/uk/ar and the German umlaut forms
            'French stopwords' => ['Pourquoi les VLAN sont utiles pour la sécurité dans une entreprise ?', ['vlan', 'utiles', 'sécurité', 'entreprise']],
            'Russian stopwords' => ['Почему для маршрутизации нужен шлюз или маска?', ['маршрутизации', 'нужен', 'шлюз', 'маска']],
            'Ukrainian stopwords' => ['Чому для маршрутизації потрібен шлюз або маска?', ['маршрутизації', 'потрібен', 'шлюз', 'маска']],
            'Arabic stopwords' => ['لماذا نحتاج إلى بوابة في الشبكة؟', ['نحتاج', 'بوابة', 'الشبكة']],
            'German umlaut stopwords' => ['Tipps für Subnetting über VLANs', ['tipps', 'subnetting', 'vlans']],
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
