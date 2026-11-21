<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #65: the real ImportController::importCsv() reads the shipped examples and a set of
 * hand-written CSV texts exactly as before the three CSV findings were fixed
 * (app/tests/Fixtures/import-csv-regression.json, recorded from the old readers). The import
 * preview runs the same file (importCsvRegression.test.js). Error texts are not compared.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportCsvRegressionTest extends TestCase {
    use ImportControllerHarness;

    private const FIXTURE = __DIR__ . '/../../Fixtures/import-csv-regression.json';
    private const EXAMPLES = __DIR__ . '/../../../examples/';

    /** @return array<string, mixed> */
    private static function fixture(): array {
        $raw = file_get_contents(self::FIXTURE);
        self::assertIsString($raw, 'fixture file missing: ' . self::FIXTURE);
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function cases(): array {
        $doc = self::fixture();
        $out = [];
        foreach ($doc['files'] as $file => $expect) {
            $csv = file_get_contents(self::EXAMPLES . $file);
            self::assertIsString($csv, 'example missing: ' . $file);
            $out['example ' . $file] = [$csv, $expect];
        }
        foreach ($doc['cases'] as $case) {
            $out[$case['name']] = [$case['csv'], $case['expect']];
        }
        return $out;
    }

    public function testTheFixtureCoversBothExamplesAndEnoughCases(): void {
        $doc = self::fixture();
        $files = array_keys($doc['files']);
        sort($files);
        $this->assertSame(['comptia-security-plus.csv', 'general-knowledge.csv'], $files);
        $this->assertGreaterThanOrEqual(20, count($doc['cases']));
    }

    /** @param array<string, mixed> $expect */
    #[DataProvider('cases')]
    public function testCase(string $csv, array $expect): void {
        $response = $this->importController()->importCsv(7, $csv);
        $data = $response->getData();
        $context = 'response: ' . json_encode($data);

        if (isset($expect['error'])) {
            $this->assertArrayHasKey('error', $data, $context);
            return;
        }
        $this->assertSame($expect['importable'], $data['imported'] ?? null, $context);
        $this->assertSame($expect['errors'], count($data['errors'] ?? []), $context);
        $this->assertSame($expect['warnings'], count($data['warnings'] ?? []), $context);

        $stored = array_values(array_filter($expect['items'], static fn (array $i): bool => $i['ok']));
        $this->assertCount(count($stored), $this->questions, $context);
        foreach ($stored as $i => $item) {
            $want = $item['question'];
            $q = $this->questions[$i];
            $this->assertSame($want['text'], $q->getText(), "question $i text");
            $this->assertSame($want['type'], $q->getQuestionType(), "question $i type");
            $this->assertSame($want['answers'], $this->storedAnswers($q), "question $i answers");
            $this->assertSame($want['explanation'], $q->getExplanation(), "question $i explanation");
        }
    }
}
