<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\RagImportService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #80: the name warning for student contributions only knew [A-ZÄÖÜ][a-zäöüß]+,
 * so a Cyrillic name after a name cue never produced the hint (ru/uk are interface languages).
 */
#[AllowMockObjectsWithoutExpectations]
class RagImportPiiNameTest extends TestCase {
    /** @return list<string> */
    private function nameWarnings(string $text): array {
        $service = new RagImportService($this->createMock(RagChunkMapper::class), $this->createMock(LoggerInterface::class));
        $types = array_column($service->detectPii($text), 'type');
        return array_values(array_filter($types, static fn(string $t): bool => $t === 'name'));
    }

    /** @return array<string, array{0: string}> */
    public static function withName(): array {
        return [
            'German cue, Cyrillic name' => ['Kontakt: Иван Петров'],
            'Russian cue' => ['Привет, меня зовут Ольга Смирнова, вопрос про VLAN.'],
            'Ukrainian cue' => ['Мене звати Тарас Шевченко.'],
            'Russian name label' => ['Имя: Анна Козлова'],
            'German, as before' => ['Hallo, ich bin Max Mustermann.'],
            'capitalised label' => ['Name: Erika Musterfrau'],
        ];
    }

    #[DataProvider('withName')]
    public function testANameAfterANameCueIsFlagged(string $text): void {
        $this->assertSame(['name'], $this->nameWarnings($text));
    }

    /** @return array<string, array{0: string}> */
    public static function withoutName(): array {
        return [
            'German sentence' => ['Ich bin heute müde, das OSI-Modell hat sieben Schichten.'],
            'Russian sentence' => ['Я думаю, что маска подсети нужна для маршрутизации.'],
            'English sentence' => ['I am sure the router forwards packets.'],
        ];
    }

    #[DataProvider('withoutName')]
    public function testOrdinarySentencesAreNotFlagged(string $text): void {
        $this->assertSame([], $this->nameWarnings($text));
    }
}
