<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Unit\Support\PoolImportHarness;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * PoolBulkImportService — the import of whole pools behind occ learning:import-pool-json and
 * the pool import in the Pools tab (GitHub #34):
 *  - the dry run writes nothing and promises exactly what the real import stores;
 *  - every pool has its own transaction: a pool that fails leaves the others in place.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolBulkImportServiceTest extends TestCase {
    use PoolImportHarness;

    /** @var list<array<string, mixed>> */
    private array $createdPools = [];
    /** @var list<array<int, mixed>> */
    private array $createdQuestions = [];
    private FakeDbConnection $db;

    /**
     * @param list<string> $failingPools pool names whose create() throws
     * @param list<string> $failingInserts question texts whose insert throws (a failed statement)
     */
    private function importer(array $failingPools = [], array $failingInserts = []): PoolBulkImportService {
        $this->createdPools = [];
        $this->createdQuestions = [];
        $this->db = new FakeDbConnection();
        // The metadata checks are the real ones of PoolService, in validateForImport() and — as
        // in the real create() — before the pool row is written.
        $realPools = new PoolService($this->createMock(PoolMapper::class), $this->createMock(PoolShareMapper::class), new FakeDbConnection());
        $pools = $this->createMock(PoolService::class);
        $pools->method('validateForImport')->willReturnCallback(
            static fn (?string $hk, ?string $ht, ?string $ck, ?string $ct, ?int $co) => $realPools->validateForImport($hk, $ht, $ck, $ct, $co)
        );
        $pools->method('create')->willReturnCallback(function (string $name, ?string $description, string $userId, ?string $hk = null, ?string $ht = null, ?string $ck = null, ?string $ct = null, ?int $co = null) use ($failingPools, $realPools): Pool {
            if (in_array($name, $failingPools, true)) {
                throw new \RuntimeException('SQLSTATE[22001]: value too long');
            }
            $realPools->validateForImport($hk, $ht, $ck, $ct, $co);
            $this->createdPools[] = ['name' => $name, 'user' => $userId];
            $pool = new Pool();
            $pool->setId(100 + count($this->createdPools));
            return $pool;
        });
        $questions = $this->createMock(QuestionService::class);
        // Stands for the checks inside QuestionService::create(): a text it refuses.
        $questions->method('validateForImport')->willReturnCallback(static function (string $text): void {
            if ($text === 'Refused by the service') {
                throw new \InvalidArgumentException('Question text must be 1-5000 characters');
            }
        });
        $questions->method('create')->willReturnCallback(function (...$args) use ($failingInserts): array {
            if (in_array($args[2], $failingInserts, true)) {
                throw new \RuntimeException('SQLSTATE[25P02]: in failed sql transaction');
            }
            if ($args[2] === 'Refused on insert') {
                throw new \InvalidArgumentException('Must have 2-8 answers');
            }
            $this->createdQuestions[] = $args;
            return [];
        });
        return $this->poolImporter($pools, $questions, $this->db);
    }

    /** @return array<string, mixed> */
    private static function mixedDocument(): array {
        $ok = static fn (string $text): array => ['text' => $text, 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]];
        return ['pools' => [
            ['name' => 'Ports', 'chapter_key' => '3.1', 'questions' => [$ok('HTTPS?'), $ok('SSH?'), ['text' => 'No answers'], $ok('Refused by the service')]],
            ['description' => 'no name', 'questions' => [$ok('Lost')]],
            ['name' => 'Empty'],
            ['name' => 'PBQ', 'questions' => [
                ['text' => 'Order', 'type' => 'pbq', 'subtype' => 'ranking', 'pbq_config' => ['items' => []]],
                ['text' => 'Teleport', 'type' => 'pbq', 'subtype' => 'teleport', 'pbq_config' => []],
            ]],
        ]];
    }

    public function testDryRunWritesNothing(): void {
        $result = $this->importer()->import(self::mixedDocument(), 'teacher', true, $this->englishL10n());

        $this->assertSame([], $this->createdPools);
        $this->assertSame([], $this->createdQuestions);
        $this->assertSame(0, $this->db->beginTransactionCalls);
        $this->assertSame(
            [['Ports', 4, 2, null], ['', 1, 0, null], ['Empty', 0, 0, null], ['PBQ', 2, 1, null]],
            array_map(static fn (array $p): array => [$p['name'], $p['questions_total'], $p['importable'], $p['id']], $result)
        );
        $this->assertSame(['Q3: missing text or answers — skipped', 'Q4: Question text must be 1-5000 characters'], $result[0]['question_errors']);
        $this->assertSame([], $result[0]['errors']);
        $this->assertSame(['Pool #2 missing name — skipped'], $result[1]['errors']);
        $this->assertSame(["Pool 'Empty' contains no questions"], $result[2]['warnings']);
        $this->assertSame(["Q2: invalid PBQ subtype 'teleport' — skipped"], $result[3]['question_errors']);
    }

    public function testTheDryRunPromisesExactlyWhatTheImportStores(): void {
        $dry = $this->importer()->import(self::mixedDocument(), 'teacher', true, $this->englishL10n());
        $real = $this->importer()->import(self::mixedDocument(), 'teacher', false, $this->englishL10n());

        $this->assertSame(array_column($dry, 'importable'), array_column($real, 'imported'));
        $this->assertSame(array_column($dry, 'errors'), array_column($real, 'errors'));
        $this->assertSame(array_column($dry, 'question_errors'), array_column($real, 'question_errors'));
        $this->assertSame(array_column($dry, 'warnings'), array_column($real, 'warnings'));
        $this->assertCount(3, $this->createdQuestions);
    }

    public function testSeveralPoolsAreCreatedForTheCaller(): void {
        $result = $this->importer()->import(self::mixedDocument(), 'teacher', false, $this->englishL10n());

        $this->assertSame([['Ports', 'teacher'], ['Empty', 'teacher'], ['PBQ', 'teacher']], array_map(static fn (array $p): array => [$p['name'], $p['user']], $this->createdPools));
        $this->assertSame([101, null, 102, 103], array_column($result, 'id'));
        $this->assertSame([2, 0, 0, 1], array_column($result, 'imported'));
        $this->assertSame([101, 101, 103], array_map(static fn (array $q) => $q[0], $this->createdQuestions), 'questions land in their own pool');
        $this->assertSame(['teacher'], array_values(array_unique(array_map(static fn (array $q) => $q[1], $this->createdQuestions))));
        $this->assertSame(3, $this->db->beginTransactionCalls, 'one transaction per pool that is written');
        $this->assertSame(3, $this->db->commitCalls);
    }

    public function testAFailingPoolLeavesTheOthersInPlace(): void {
        $doc = ['pools' => [
            ['name' => 'First', 'questions' => [['text' => 'Q', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]]]],
            ['name' => 'Broken', 'questions' => [['text' => 'Q', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]]]],
            ['name' => 'Third', 'questions' => [['text' => 'Q', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]]]],
        ]];
        $result = $this->importer(['Broken'])->import($doc, 'teacher', false, $this->englishL10n());

        $this->assertSame(['First', 'Third'], array_column($this->createdPools, 'name'));
        $this->assertSame([101, null, 102], array_column($result, 'id'));
        $this->assertSame([1, 0, 1], array_column($result, 'imported'));
        $this->assertSame(["Pool 'Broken' create failed — skipped"], $result[1]['errors']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result, JSON_THROW_ON_ERROR), 'no SQL detail in the answer');
        $this->assertSame([3, 2, 1], [$this->db->beginTransactionCalls, $this->db->commitCalls, $this->db->rollBackCalls]);
    }

    /**
     * A failed statement inside the pool's transaction may have poisoned it (PostgreSQL: COMMIT
     * then rolls back without an error), so it fails the whole pool instead of being reported as
     * one skipped question of a pool that silently never got stored.
     */
    public function testAFailedQuestionInsertRollsItsPoolBack(): void {
        $ok = static fn (string $text): array => ['text' => $text, 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]];
        $doc = ['pools' => [
            ['name' => 'First', 'questions' => [$ok('Q1')]],
            ['name' => 'Second', 'questions' => [$ok('Fine'), $ok('Insert fails'), $ok('After')]],
            ['name' => 'Third', 'questions' => [$ok('Q3')]],
        ]];
        $result = $this->importer([], ['Insert fails'])->import($doc, 'teacher', false, $this->englishL10n());

        $this->assertSame([101, null, 103], array_column($result, 'id'));
        $this->assertSame([1, 0, 1], array_column($result, 'imported'));
        $this->assertSame(["Pool 'Second' create failed — skipped"], $result[1]['errors']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame([3, 2, 1], [$this->db->beginTransactionCalls, $this->db->commitCalls, $this->db->rollBackCalls]);
    }

    public function testABareListIsOnePool(): void {
        $doc = PoolBulkImportService::asPoolsDocument([['text' => 'Q', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]]], 'pool-12');
        $this->assertNotNull($doc);
        $result = $this->importer()->import($doc, 'teacher', false, $this->englishL10n());

        $this->assertSame(['pool-12'], array_column($this->createdPools, 'name'));
        $this->assertSame(1, $result[0]['imported']);
        $this->assertSame(1, PoolBulkImportService::questionCount($doc));
    }

    public function testTooLongNameOrDescriptionSkipsThePoolBeforeAnyWrite(): void {
        $result = $this->importer()->import(['pools' => [
            ['name' => str_repeat('n', 201)],
            ['name' => 'D', 'description' => str_repeat('d', 2001)],
        ]], 'teacher', false, $this->englishL10n());

        $this->assertSame([], $this->createdPools);
        $this->assertSame(0, $this->db->beginTransactionCalls);
        $this->assertSame(['Pool #1: name longer than 200 characters — skipped'], $result[0]['errors']);
        $this->assertSame(["Pool 'D': description longer than 2000 characters — skipped"], $result[1]['errors']);
    }

    /**
     * PoolService::create() refuses handbook/chapter fields over 64/255 characters. The dry run
     * checked only the questions, so it promised N/N for a pool whose real import then threw in
     * create() and came back as "create failed" without a reason.
     */
    public function testAPoolWithATooLongChapterKeyIsRefusedTheSameInDryRunAndImport(): void {
        $q = static fn (string $text): array => ['text' => $text, 'chapter_key' => '1.1', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]];
        $doc = ['pools' => [
            ['name' => 'Long key', 'chapter_key' => str_repeat('k', 65), 'questions' => [$q('A'), $q('B')]],
            ['name' => 'Long title', 'chapter_title' => str_repeat('t', 256)],
            ['name' => 'Fine', 'chapter_key' => str_repeat('k', 64), 'questions' => [$q('C')]],
        ]];

        $dry = $this->importer()->import($doc, 'teacher', true, $this->englishL10n());
        $real = $this->importer()->import($doc, 'teacher', false, $this->englishL10n());

        $expected = [["Pool 'Long key': Field exceeds maximum length — skipped"], ["Pool 'Long title': Field exceeds maximum length — skipped"], []];
        $this->assertSame($expected, array_column($dry, 'errors'));
        $this->assertSame($expected, array_column($real, 'errors'));
        $this->assertSame([0, 0, 1], array_column($dry, 'importable'));
        $this->assertSame([0, 0, 1], array_column($real, 'imported'));
        $this->assertSame([null, null, 101], array_column($real, 'id'));
        $this->assertSame(['Fine'], array_column($this->createdPools, 'name'));
        $this->assertSame([1, 1, 0], [$this->db->beginTransactionCalls, $this->db->commitCalls, $this->db->rollBackCalls], 'a refused pool opens no transaction');
    }

    /**
     * The reasons QuestionService and PoolService give are fixed English texts. They reach the
     * caller through its IL10N, in the dry run, on insert and for the pool itself — not as an
     * English sentence behind a translated prefix.
     */
    public function testRefusalReasonsGoThroughTheCallersL10n(): void {
        // Marks every translated text, so an untranslated reason is visible.
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn (string $text, $parameters = []): string => '<' . vsprintf($text, is_array($parameters) ? $parameters : [$parameters]) . '>'
        );
        $ok = static fn (string $text): array => ['text' => $text, 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]];
        $doc = ['pools' => [
            ['name' => 'P', 'questions' => [$ok('Refused by the service'), $ok('Refused on insert')]],
            ['name' => 'K', 'handbook_key' => str_repeat('h', 65), 'questions' => [$ok('X')]],
        ]];

        $dry = $this->importer()->import($doc, 'teacher', true, $l);
        $real = $this->importer()->import($doc, 'teacher', false, $l);

        $this->assertSame(['<Q1: <Question text must be 1-5000 characters>>'], $dry[0]['question_errors']);
        $this->assertSame(['<Q1: <Question text must be 1-5000 characters>>', '<Q2: <Must have 2-8 answers>>'], $real[0]['question_errors']);
        $this->assertSame(["<Pool 'K': <Field exceeds maximum length> — skipped>"], $dry[1]['errors']);
        $this->assertSame(["<Pool 'K': <Field exceeds maximum length> — skipped>"], $real[1]['errors']);
    }

    /**
     * An \InvalidArgumentException from create() whose text is none of the services' known
     * messages may come from a deeper layer: its text goes to the log, not into the answer.
     */
    public function testAnUnknownRefusalFromCreateIsNotReturned(): void {
        $pools = $this->createMock(PoolService::class);
        $pools->method('create')->willThrowException(new \InvalidArgumentException('Column "x" of relation oc_learning_pools: internal detail'));
        $result = $this->poolImporter($pools, $this->createMock(QuestionService::class))
            ->import(['pools' => [['name' => 'P']]], 'teacher', false, $this->englishL10n());

        $this->assertSame(["Pool 'P' create failed — skipped"], $result[0]['errors']);
        $this->assertStringNotContainsString('internal detail', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** The English IL10N (occ) prints the services' own text unchanged, the scenario limit included. */
    public function testEnglishReasonsStayTheServicesText(): void {
        $questions = $this->createMock(QuestionService::class);
        $questions->method('validateForImport')->willThrowException(
            new \InvalidArgumentException('Scenario must be max ' . QuestionService::SCENARIO_MAX_LENGTH . ' characters')
        );
        $importer = $this->poolImporter($this->createMock(PoolService::class), $questions);
        $result = $importer->import(['pools' => [['name' => 'S', 'questions' => [
            ['text' => 'Q', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]],
        ]]]], 'teacher', true, $this->englishL10n());

        $this->assertSame(['Q1: Scenario must be max ' . QuestionService::SCENARIO_MAX_LENGTH . ' characters'], $result[0]['question_errors']);
    }
}
