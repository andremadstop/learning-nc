<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\CsvExportL10n;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #48: the person column of the certificate report and the at-risk list used the key
 * 'Name', which the catalogue translates as the name of a thing (ru "Название"). The headers
 * are resolved through the real catalogues here, so a wrong key or translation turns red.
 */
class CsvExportPersonNameHeaderTest extends TestCase {
    private function catalogue(string $lang): IL10N {
        $file = __DIR__ . '/../../../l10n/' . $lang . '.json';
        $translations = json_decode((string)file_get_contents($file), true)['translations'];
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn (string $text, $params = []): string => vsprintf($translations[$text] ?? $text, (array)$params)
        );
        return $l;
    }

    /** @return array<string, array{string, string}> */
    public static function languages(): array {
        return [
            'de' => ['de', 'Name'],
            'en' => ['en', 'Name'],
            'fr' => ['fr', 'Nom'],
            'ru' => ['ru', 'Имя'],
            'ar' => ['ar', 'الاسم'],
            'uk' => ['uk', "Ім'я"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function testThePersonColumnIsCalledAPersonsName(string $lang, string $expected): void {
        $csv = new CsvExportL10n($this->catalogue($lang));
        $this->assertSame($expected, $csv->certReportHeader()[0]);
        $this->assertSame($expected, $csv->atRiskHeader()[0]);
    }
}
