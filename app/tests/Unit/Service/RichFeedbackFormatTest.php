<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Pool;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\RichFeedbackFormat;
use OCA\Learning\Tests\Unit\Controller\ImportControllerHarness;
use OCA\Learning\Tests\Unit\Support\PoolImportHarness;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #66: how the pool JSON format reads rich feedback (hint, feedback per option, background
 * fact, key takeaway) — the shared cases in tests/Fixtures/rich-feedback-cases.json, which
 * src/utils/richFeedback.js is held to as well (richFeedback.test.js).
 */
#[AllowMockObjectsWithoutExpectations]
class RichFeedbackFormatTest extends TestCase {
    use ImportControllerHarness;
    use PoolImportHarness;

    private const FIXTURE = __DIR__ . '/../../Fixtures/rich-feedback-cases.json';

    /** @return array<string, mixed> */
    private static function fixture(): array {
        return json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function cases(): iterable {
        foreach (self::fixture()['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function tooLongCases(): iterable {
        foreach (self::fixture()['too_long'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testTheFixtureCasesReadAsExpected(array $case): void {
        $this->assertSame($case['expected'], RichFeedbackFormat::fromItem($case['item'], null, $case['is_correct']));
    }

    /**
     * An item of a too_long case: the field is set to `length` characters (multibyte, so a byte
     * count would be wrong).
     *
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    public static function tooLongItem(array $case): array {
        $value = str_repeat('ä', $case['length']);
        $item = ['text' => 'Q?', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]];
        if (in_array($case['field'], ['why', 'misconception'], true)) {
            $item['feedback_per_option'] = [['option_index' => 1, $case['field'] => $value]];
        } else {
            $item[$case['field']] = $value;
        }
        return $item;
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('tooLongCases')]
    public function testTheEditorLimitsAreChecked(array $case): void {
        $rich = RichFeedbackFormat::fromItem(self::tooLongItem($case));
        unset($rich['dropped_languages'], $rich['verdict_mismatches'], $rich['unmatched']);
        try {
            RichFeedbackFormat::normalize($rich);
            $this->assertFalse($case['skipped'], 'expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue($case['skipped'], 'refused at the limit');
            $this->assertSame(RichFeedbackFormat::TOO_LONG_MESSAGE, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('tooLongCases')]
    public function testTheInterfaceImportSkipsATooLongQuestion(array $case): void {
        $r = $this->importController()->importJson(9, json_encode([self::tooLongItem($case), ['text' => 'Other', 'answers' => [['text' => 'x', 'is_correct' => true], ['text' => 'y']]]], JSON_THROW_ON_ERROR));

        $this->assertSame($case['skipped'] ? 1 : 2, $r->getData()['imported'], json_encode($r->getData()));
        if ($case['skipped']) {
            $this->assertSame(['Item 1: A feedback field is too long — question skipped'], $r->getData()['errors']);
        }
    }

    public function testNormalizeKeepsOnlyTheKeysItWasGiven(): void {
        $this->assertSame([], RichFeedbackFormat::normalize([]));
        $this->assertSame(['hint' => null], RichFeedbackFormat::normalize(['hint' => "  \n "]));
        $this->assertSame(
            ['option_feedback' => [['position' => 0, 'why' => 'w', 'misconception' => null], ['position' => 2, 'why' => null, 'misconception' => 'm']]],
            RichFeedbackFormat::normalize(['option_feedback' => [
                ['position' => 2, 'misconception' => ' m '],
                ['position' => 1, 'why' => '', 'misconception' => ''],
                ['position' => 0, 'why' => 'w'],
                ['why' => 'no position'],
                'not an object',
            ]])
        );
    }

    public function testTheExportReadsBackToWhatIsStored(): void {
        foreach (self::fixture()['cases'] as $case) {
            $stored = RichFeedbackFormat::fromItem($case['item'], null, $case['is_correct']);
            $answers = array_map(static fn (bool $c, int $i): array => ['position' => $i, 'is_correct' => $c], $case['is_correct'], array_keys($case['is_correct']));
            $export = RichFeedbackFormat::toExport($stored['hint'], $stored['background_fact'], $stored['key_takeaway'], RichFeedbackFormat::encodeOptions($stored['option_feedback']), $answers);

            $back = RichFeedbackFormat::fromItem($export + ['answers' => array_fill(0, count($answers), ['text' => 'x'])], null, $case['is_correct']);

            foreach (['hint', 'background_fact', 'key_takeaway', 'option_feedback'] as $field) {
                $this->assertSame($stored[$field], $back[$field], $case['name'] . ': ' . $field);
            }
            $this->assertSame(0, $back['verdict_mismatches'], 'the export writes the verdict from the answer key');
            foreach ($export['feedback_per_option'] ?? [] as $option) {
                $this->assertSame($case['is_correct'][$option['option_index']] ? 'correct' : 'wrong', $option['verdict']);
                $this->assertSame(chr(65 + $option['option_index']), $option['letter']);
            }
        }
    }

    public function testAnUnreadableStoredListIsEmpty(): void {
        $this->assertSame([], RichFeedbackFormat::decodeOptions('{broken'));
        $this->assertSame([], RichFeedbackFormat::decodeOptions(null));
        $this->assertNull(RichFeedbackFormat::encodeOptions([]));
    }

    /** The interface import keeps the position of an answer after a blank one is dropped. */
    public function testTheInterfaceImportMapsFeedbackPastADroppedBlankAnswer(): void {
        $r = $this->importController()->importJson(9, json_encode([[
            'text' => 'Q?',
            'answers' => [['text' => 'a', 'is_correct' => true], ['text' => ''], ['text' => 'c']],
            'feedback_per_option' => [['option_index' => 2, 'why' => 'c is wrong'], ['option_index' => 0, 'why' => 'a is right']],
        ]], JSON_THROW_ON_ERROR));

        $this->assertSame(1, $r->getData()['imported'], json_encode($r->getData()));
        $this->assertSame(
            [['position' => 0, 'why' => 'a is right', 'misconception' => null], ['position' => 1, 'why' => 'c is wrong', 'misconception' => null]],
            RichFeedbackFormat::decodeOptions($this->questions[0]->getOptionFeedback())
        );
    }

    public function testTheInterfaceImportReportsWhatItLeftOut(): void {
        $case = self::fixture()['cases'][2]; // en before de, a contradicting verdict
        $r = $this->importController()->importJson(9, json_encode([$case['item']], JSON_THROW_ON_ERROR));

        $this->assertSame(1, $r->getData()['imported']);
        $this->assertSame([
            'Item 1: Feedback texts in de were not imported — one language per question',
            'Item 1: A "verdict" in feedback_per_option contradicts the marked correct answers — the marked answers count',
        ], $r->getData()['warnings']);
        $q = $this->questions[0];
        $this->assertSame('The boiling point falls with the air pressure.', $q->getBackgroundFact());
        $this->assertSame($case['expected']['option_feedback'], RichFeedbackFormat::decodeOptions($q->getOptionFeedback()));
    }

    public function testThePoolImportPassesTheFeedbackAndReportsWhatItLeftOut(): void {
        $created = [];
        $pools = $this->createMock(PoolService::class);
        $pools->method('create')->willReturnCallback(static function (): Pool {
            $p = new Pool();
            $p->setId(77);
            return $p;
        });
        $questions = $this->createMock(QuestionService::class);
        $questions->method('create')->willReturnCallback(static function (...$args) use (&$created): array {
            $created[] = $args;
            return [];
        });
        $case = self::fixture()['cases'][1]; // language objects
        $results = $this->poolImporter($pools, $questions)->import(['pools' => [['name' => 'P', 'questions' => [$case['item']]]]], 'alice', false, $this->englishL10n());

        $this->assertSame(1, $results[0]['imported'], json_encode($results));
        $this->assertSame([
            'hint' => $case['expected']['hint'],
            'background_fact' => null,
            'key_takeaway' => $case['expected']['key_takeaway'],
            'option_feedback' => $case['expected']['option_feedback'],
        ], $created[0][18]);
        $this->assertSame(["Q1: feedback texts in en were not imported — one language per question"], $results[0]['warnings']);
    }

    public function testThePoolImportNamesATooLongFieldInTheCallersLanguage(): void {
        $pools = $this->createMock(PoolService::class);
        $questions = $this->createMock(QuestionService::class);
        $questions->method('validateForImport')->willThrowException(new \InvalidArgumentException(RichFeedbackFormat::TOO_LONG_MESSAGE));

        $results = $this->poolImporter($pools, $questions)->import(['pools' => [['name' => 'P', 'questions' => [self::tooLongItem(['field' => 'hint', 'length' => 2001])]]]], 'alice', true, $this->l10nStub('»'));

        $this->assertSame(['»Q1: »Feedback field exceeds maximum length'], $results[0]['question_errors']);
    }
}
