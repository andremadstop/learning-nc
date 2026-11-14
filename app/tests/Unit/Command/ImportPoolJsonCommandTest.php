<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Db\Pool;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Tests\Unit\Support\PoolImportHarness;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * occ learning:import-pool-json — GitHub #34 round 2:
 *  - a bare question list (what `occ learning:export-pool` and the export in the interface
 *    write) imports as one pool, named by --pool-name or else by the file name;
 *  - PBQ questions survive (they used to be dropped for having no answers);
 *  - pool chapter fields fill questions that have none of their own, as in the interface.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportPoolJsonCommandTest extends TestCase {
    use PoolImportHarness;

    /** @var list<array<string, mixed>> */
    public array $createdPools = [];
    /** @var list<array<int, mixed>> arguments of each QuestionService::create call */
    public array $createdQuestions = [];
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    /** @return array{0: int, 1: string} */
    private function runCommand(array $input): array {
        return $this->runOcc($this->command(), $input);
    }

    /**
     * Run an occ command with recording mocks: the unit suite has only minimal Symfony console
     * stubs (tests/Support/PhpUnitStubs.php), whose CommandTester discards the output.
     * Keys: argument names bare, options as '--name'.
     *
     * @return array{0: int, 1: string} exit code and output
     */
    private function runOcc(\Symfony\Component\Console\Command\Command $command, array $args): array {
        $input = $this->createMock(\Symfony\Component\Console\Input\InputInterface::class);
        $input->method('getArgument')->willReturnCallback(static fn (string $n) => $args[$n] ?? null);
        $input->method('getOption')->willReturnCallback(static fn (string $n) => $args['--' . $n] ?? null);
        $lines = [];
        $output = $this->createMock(\Symfony\Component\Console\Output\OutputInterface::class);
        $output->method('writeln')->willReturnCallback(static function ($messages) use (&$lines): void {
            foreach ((array)$messages as $m) {
                $lines[] = (string)$m;
            }
        });
        $exit = $command->run($input, $output);
        return [$exit, implode("\n", $lines)];
    }

    private function command(): ImportPoolJsonCommand {
        $this->createdPools = [];
        $this->createdQuestions = [];
        $pools = $this->createMock(PoolService::class);
        $pools->method('create')->willReturnCallback(function (string $name, ?string $description, string $userId, ...$rest): Pool {
            $this->createdPools[] = ['name' => $name, 'description' => $description, 'user' => $userId, 'rest' => $rest];
            $pool = new Pool();
            $pool->setId(40 + count($this->createdPools));
            return $pool;
        });
        $questions = $this->createMock(QuestionService::class);
        $questions->method('create')->willReturnCallback(function (...$args): array {
            $this->createdQuestions[] = $args;
            return [];
        });
        return $this->occImportCommand($pools, $questions);
    }

    public function writeJson(mixed $data, string $basename = 'export'): string {
        $dir = sys_get_temp_dir() . '/learning-import-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/' . $basename . '.json';
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
        $this->files[] = $file;
        return $file;
    }

    /** @return list<array<string, mixed>> */
    private static function bareList(): array {
        return [
            ['text' => 'Single', 'type' => 'single', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]],
            ['text' => 'Open', 'type' => 'open', 'answers' => [['text' => 'model', 'is_correct' => true]]],
        ];
    }

    public function testABareListImportsAsOnePoolNamedAfterTheFile(): void {
        [$exit, $display] = $this->runCommand(['file' => $this->writeJson(self::bareList(), 'pool-12'), '--user' => 'alice']);

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertSame(['pool-12'], array_column($this->createdPools, 'name'), $display);
        $this->assertSame('alice', $this->createdPools[0]['user']);
        $this->assertSame(['Single', 'Open'], array_map(static fn (array $a) => $a[2], $this->createdQuestions));
        $this->assertSame(['single', 'open'], array_map(static fn (array $a) => $a[6], $this->createdQuestions));
        $this->assertStringContainsString('1 pools, 2 questions', $display);
    }

    public function testPoolNameOptionNamesTheBareListPool(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson(self::bareList()), '--user' => 'alice', '--pool-name' => 'Network basics']);

        $this->assertSame(['Network basics'], array_column($this->createdPools, 'name'), $display);
    }

    public function testAQuestionsWrapperUsesItsOwnNameAndMeta(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson([
            '_meta' => ['default_difficulty' => 'hard'],
            'name' => 'From the file',
            'description' => 'Desc',
            'questions' => self::bareList(),
        ]), '--user' => 'alice']);

        $this->assertSame([['From the file', 'Desc']], array_map(static fn (array $p) => [$p['name'], $p['description']], $this->createdPools), $display);
        $this->assertSame(['hard', 'hard'], array_map(static fn (array $a) => $a[4], $this->createdQuestions));
    }

    public function testPoolNameOptionWinsOverTheFilesOwnName(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson(['name' => 'From the file', 'questions' => self::bareList()]), '--user' => 'alice', '--pool-name' => 'From the option']);

        $this->assertSame(['From the option'], array_column($this->createdPools, 'name'), $display);
    }

    public function testDryRunWorksForABareList(): void {
        [$exit, $display] = $this->runCommand(['file' => $this->writeJson(self::bareList(), 'x'), '--user' => 'alice', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertSame([], $this->createdPools);
        $this->assertStringContainsString('Dry-run: 1 pools, 2 questions', $display);
    }

    public function testPoolNameWithAPoolsFileIsRefused(): void {
        [$exit, $display] = $this->runCommand(['file' => $this->writeJson(['pools' => [['name' => 'P', 'questions' => self::bareList()]]]), '--user' => 'alice', '--pool-name' => 'Other']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertSame([], $this->createdPools);
    }

    public function testAnObjectWithoutPoolsOrQuestionsIsStillRefused(): void {
        [$exit, $display] = $this->runCommand(['file' => $this->writeJson(['name' => 'nothing']), '--user' => 'alice']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([], $this->createdPools);
    }

    public function testPbqQuestionsAreImportedWithSubtypeAndConfig(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson([
            ['text' => 'Order the steps', 'type' => 'pbq', 'subtype' => 'ranking', 'pbq_config' => ['items' => [['label' => 'a', 'correct_position' => 1]]], 'answers' => []],
            ['text' => 'Ports', 'question_type' => 'pbq', 'subtype' => 'dropdown', 'pbq_config' => ['items' => []]],
            ['text' => 'Unknown', 'type' => 'pbq', 'subtype' => 'teleport', 'pbq_config' => []],
            ['text' => 'No config', 'type' => 'pbq', 'subtype' => 'cli'],
        ]), '--user' => 'alice']);

        $this->assertSame(['Order the steps', 'Ports'], array_map(static fn (array $a) => $a[2], $this->createdQuestions), $display);
        $this->assertSame(['pbq', 'pbq'], array_map(static fn (array $a) => $a[6], $this->createdQuestions));
        $this->assertSame(['ranking', 'dropdown'], array_map(static fn (array $a) => $a[7], $this->createdQuestions));
        $this->assertSame(['items' => [['label' => 'a', 'correct_position' => 1]]], json_decode((string)$this->createdQuestions[0][8], true));
        $this->assertSame([], $this->createdQuestions[0][5], 'a PBQ has no answers');
        $this->assertStringContainsString('teleport', $display);
        $this->assertStringContainsString('pbq_config', $display);
    }

    public function testPoolChapterFillsOnlyQuestionsWithoutTheirOwn(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson(['pools' => [[
            'name' => 'P', 'chapter_key' => '1.1', 'chapter_title' => 'Basics', 'chapter_order' => 3, 'exam_key_prefix' => 'ccna',
            'questions' => [
                ['original_number' => 5, 'text' => 'Inherits', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]],
                ['text' => 'Own', 'chapter_key' => '2.4', 'chapterTitle' => 'Own title', 'chapter_order' => 9, 'exam_key' => 'k', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]],
            ],
        ]]]), '--user' => 'alice']);

        // create(poolId, user, text, expl, difficulty, answers, type, sub, cfg, note, visible, hbKey, hbTitle, examKey, chKey, chTitle, chOrder, scenario)
        $meta = array_map(static fn (array $a) => [$a[13], $a[14], $a[15], $a[16]], $this->createdQuestions);
        $this->assertSame([['ccna-q5', '1.1', 'Basics', 3], ['k', '2.4', 'Own title', 9]], $meta, $display);
        // The pool itself keeps the pool-level chapter.
        $this->assertSame([null, null, '1.1', 'Basics', 3], $this->createdPools[0]['rest']);
    }

    /** Same rule as the import in the interface: several correct answers mean multi, also under a pbq default. */
    public function testDefaultPbqDoesNotApplyWhenSeveralAnswersAreCorrect(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson([
            '_meta' => ['default_question_type' => 'pbq'],
            'pools' => [['name' => 'P', 'questions' => [
                ['text' => 'Two flagged', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => true], ['text' => 'c']]],
                ['text' => 'Order', 'subtype' => 'ranking', 'pbq_config' => ['items' => ['a', 'b']]],
            ]]],
        ]), '--user' => 'alice']);

        $this->assertSame([['Two flagged', 'multi'], ['Order', 'pbq']], array_map(static fn (array $a) => [$a[2], $a[6]], $this->createdQuestions), $display);
    }

    /** difficulty is a 20-character column, chapter_order an unsigned one: cut and drop instead of a failed insert. */
    public function testDifficultyIsCutAndANegativeChapterOrderIsDropped(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson(['pools' => [[
            'name' => 'P', 'chapter_order' => -2,
            'questions' => [
                ['text' => 'Q', 'difficulty' => 'abcdefghijklmnopqrstuvwxyz', 'chapter_order' => -5, 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]],
                ['text' => 'Inherits', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]],
            ],
        ]]]), '--user' => 'alice']);

        $this->assertSame([['abcdefghijklmnopqrst', null], ['medium', null]], array_map(static fn (array $a) => [$a[4], $a[16]], $this->createdQuestions), $display);
        $this->assertNull($this->createdPools[0]['rest'][4]);
    }

    /**
     * chapter_order is valid only from 1 to 9999 (QuestionService/PoolService throw outside).
     * The same fixture case the interface import and its preview run must end the same way here:
     * an out-of-range value is dropped, not a skipped question. A pool with chapter_order 0 is
     * created without it instead of failing ("create failed") with all its questions.
     */
    public function testChapterOrderOutsideTheRangeEndsAsInTheInterface(): void {
        $case = self::fixtureCase('chapter_order outside 1 to 9999 is dropped, 1 and 9999 are kept');
        [, $display] = $this->runCommand(['file' => $this->writeJson(['pools' => [['name' => 'P', 'chapter_order' => 0, 'questions' => $case['input']]]]), '--user' => 'alice']);

        $this->assertCount(1, $this->createdPools, $display);
        $this->assertNull($this->createdPools[0]['rest'][4]);
        $this->assertSame(
            array_map(static fn (array $q) => [$q['text'], $q['chapter_order']], $case['expect']['questions']),
            array_map(static fn (array $a) => [$a[2], $a[16]], $this->createdQuestions),
            $display
        );
    }

    /** @return array<string, mixed> */
    private static function fixtureCase(string $name): array {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/import-json-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        self::fail('fixture case missing: ' . $name);
    }

    /**
     * The dry run counts what the import would store: questions that pass every check and pools
     * that would be created — not every question item and every named pool (audit 2026-11:
     * "Dry-run: 1 Pools, 25 Fragen" for a file whose 25 questions were all skipped).
     */
    public function testDryRunCountsOnlyWhatTheImportWouldStore(): void {
        $refused = [
            ['text' => 'Unknown type', 'type' => 'essay', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]],
            ['text' => 'No answers'],
        ];
        [$exit, $display] = $this->runCommand(['file' => $this->writeJson($refused, 'x'), '--user' => 'alice', '--dry-run' => true]);
        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Dry-run: 1 pools, 0 questions', $display);

        [, $display] = $this->runCommand(['file' => $this->writeJson(['pools' => [
            ['name' => 'Kept', 'questions' => self::bareList()],
            ['name' => 'Too long', 'description' => str_repeat('d', 2001), 'questions' => self::bareList()],
        ]]), '--user' => 'alice', '--dry-run' => true]);
        $this->assertStringContainsString('Dry-run: 1 pools, 2 questions', $display);
    }

    /**
     * "pool_name" (app/examples/pools/*.json, app/data) names the pool like "name" — in a
     * questions wrapper and in a pools list; an empty "name" falls through to it (audit 2026-11).
     */
    public function testPoolNameKeyNamesThePool(): void {
        [, $display] = $this->runCommand(['file' => $this->writeJson(['pool_name' => 'Subnetting', 'questions' => self::bareList()], 'subnetting_questions'), '--user' => 'alice']);
        $this->assertSame(['Subnetting'], array_column($this->createdPools, 'name'), $display);

        [, $display] = $this->runCommand(['file' => $this->writeJson(['name' => '', 'pool_name' => 'Fallback', 'questions' => self::bareList()]), '--user' => 'alice']);
        $this->assertSame(['Fallback'], array_column($this->createdPools, 'name'), $display);

        [, $display] = $this->runCommand(['file' => $this->writeJson(['name' => 'Name wins', 'pool_name' => 'Other', 'questions' => self::bareList()]), '--user' => 'alice']);
        $this->assertSame(['Name wins'], array_column($this->createdPools, 'name'), $display);

        [, $display] = $this->runCommand(['file' => $this->writeJson(['pools' => [['pool_name' => 'From the list', 'questions' => self::bareList()]]]), '--user' => 'alice']);
        $this->assertSame(['From the list'], array_column($this->createdPools, 'name'), $display);
    }

    /** The audit's own file: named by its pool_name, all 25 multiple_choice questions importable. */
    public function testTheSubnettingDataFileDryRunsCompletely(): void {
        [$exit, $display] = $this->runCommand(['file' => __DIR__ . '/../../../examples/pools/subnetting-ip-adressierung-n10-009-de.json', '--user' => 'alice', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Subnetting & IP-Adressierung (N10-009)', $display);
        $this->assertStringContainsString('Dry-run: 1 pools, 25 questions', $display);
    }

    /**
     * Audit #136: a file whose every pool was refused still exited 0, so a script reported success.
     * The output was also half German ("Imported: 0 Pools, 0 Fragen.") although occ speaks English.
     */
    public function testNothingImportedWithErrorsExitsWithFailure(): void {
        $refused = ['pools' => [['name' => 'Too long', 'description' => str_repeat('d', 2001), 'questions' => self::bareList()]]];

        [$exit, $display] = $this->runCommand(['file' => $this->writeJson($refused), '--user' => 'alice']);
        $this->assertSame(Command::FAILURE, $exit, $display);
        $this->assertStringContainsString('Imported: 0 pools, 0 questions.', $display);
        $this->assertStringNotContainsString('Fragen', $display);

        [$exit, $display] = $this->runCommand(['file' => $this->writeJson($refused), '--user' => 'alice', '--dry-run' => true]);
        $this->assertSame(Command::FAILURE, $exit, $display);
    }

    public function testAPartlyRefusedFileStillSucceeds(): void {
        [$exit, $display] = $this->runCommand(['file' => $this->writeJson(['pools' => [
            ['name' => 'Kept', 'questions' => self::bareList()],
            ['name' => 'Too long', 'description' => str_repeat('d', 2001), 'questions' => self::bareList()],
        ]]), '--user' => 'alice']);

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('(2 questions, chapter', $display);
    }
}
