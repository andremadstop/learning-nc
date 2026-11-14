<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Db\Question;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #34: the shared JSON import cases (app/tests/Fixtures/import-json-cases.json) through
 * the real ImportController::importJson(). The import preview in the dialog runs the same file,
 * so what the preview promises and what the server writes cannot drift apart.
 *
 * Error texts are never compared (they are translated); counts and the written content are.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportJsonFixturesTest extends TestCase {
    use ImportControllerHarness;

    private const FIXTURE = __DIR__ . '/../../Fixtures/import-json-cases.json';

    /** @return array<string, array{0: mixed, 1: array<string, mixed>}> */
    public static function cases(): array {
        $raw = file_get_contents(self::FIXTURE);
        self::assertIsString($raw, 'fixture file missing: ' . self::FIXTURE);
        $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($doc['cases'] as $case) {
            $out[$case['name']] = [$case['input'], $case['expect']];
        }
        return $out;
    }

    public function testTheFixtureHoldsEnoughCases(): void {
        $this->assertGreaterThanOrEqual(15, count(self::cases()));
    }

    /** @param array<string, mixed> $expect */
    #[DataProvider('cases')]
    public function testCase(mixed $input, array $expect): void {
        $response = $this->importController()->importJson(7, json_encode($input, JSON_THROW_ON_ERROR));
        $data = $response->getData();
        $context = 'response: ' . json_encode($data);

        [$importable, $errors] = self::counts($response);
        $this->assertSame($expect['importable'], $importable, $context);
        $this->assertSame($expect['errors'], $errors, $context);
        $this->assertSame($expect['importable'] > 0 ? 201 : 400, $response->getStatus(), $context);
        $this->assertCount(count($expect['questions']), $this->questions, $context);

        foreach ($expect['questions'] as $i => $want) {
            $q = $this->questions[$i];
            $this->assertSame($want['text'], $q->getText(), "question $i text");
            $this->assertSame($want['type'], $q->getQuestionType(), "question $i type");
            $this->assertSame($want['answers'], $this->storedAnswers($q), "question $i answers");
            foreach (self::optionalFields() as $field => $getter) {
                if (array_key_exists($field, $want)) {
                    $this->assertSame($want[$field], $getter($q), "question $i $field");
                }
            }
        }
    }

    /** @return array<string, callable(Question): mixed> */
    private static function optionalFields(): array {
        return [
            'difficulty' => static fn (Question $q) => $q->getDifficulty(),
            'chapter_key' => static fn (Question $q) => $q->getChapterKey(),
            'chapter_title' => static fn (Question $q) => $q->getChapterTitle(),
            'chapter_order' => static fn (Question $q) => $q->getChapterOrder() === null ? null : (int)$q->getChapterOrder(),
            'exam_key' => static fn (Question $q) => $q->getExamKey(),
            'subtype' => static fn (Question $q) => $q->getPbqSubtype(),
        ];
    }

    /**
     * Counting rules of the fixture file: a top-level shape error counts as 0 importable, 1 error.
     *
     * @return array{0: int, 1: int}
     */
    private static function counts(DataResponse $response): array {
        $data = $response->getData();
        if (isset($data['error'])) {
            return [0, 1];
        }
        return [(int)$data['imported'], count($data['errors'])];
    }

    // ---------------------------------------------------------------------------------------
    // Messages are translated (IL10N), with the values as parameters
    // ---------------------------------------------------------------------------------------

    public function testItemErrorsAndWarningsGoThroughTheTranslator(): void {
        $r = $this->importController(null, '[tr] ')->importJson(7, json_encode([
            ['answers' => ['a', 'b']],
            ['text' => 'Unmarked', 'answers' => ['x', 'y']],
            ['text' => 'Bad marker', 'options' => ['a', 'b'], 'answer' => 'zzz'],
        ], JSON_THROW_ON_ERROR));

        $data = $r->getData();
        $this->assertSame(['[tr] Item 1: Missing question text', '[tr] Item 3: Correct answer "zzz" matches no answer — numbers count from 0 (0 = first answer, or use correct_number to count from 1), letters from A, or give the exact answer text'], $data['errors']);
        $this->assertSame(['[tr] Item 2: No correct answer marked — defaulted to first answer'], $data['warnings']);
    }

    public function testShapeAndAccessErrorsGoThroughTheTranslator(): void {
        $c = $this->importController(null, '[tr] ');
        $this->assertSame('[tr] The file contains 2 pools, but this dialog imports into one pool. Import them one at a time, or create all pools at once with "occ learning:import-pool-json"',
            $c->importJson(7, '{"pools":[{"questions":[]},{"questions":[]}]}')->getData()['error']);
        $this->assertSame('[tr] Invalid JSON — check for missing brackets, commas, or quotes', $c->importJson(7, '{nope')->getData()['error']);
        $this->assertSame('[tr] No data to import', $c->importCsv(7, "\n\n")->getData()['error']);
        $this->assertSame('[tr] PBQ #1: invalid subtype \'teleport\'',
            $c->importJson(7, '[{"type":"pbq","text":"x","subtype":"teleport","pbq_config":{}}]')->getData()['errors'][0]);
    }
}
