<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\PoolJsonFormat;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Unit\Controller\ImportControllerHarness;
use OCA\Learning\Tests\Unit\Support\PoolImportHarness;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #34 round 2: a pool exported from the app (interface export or occ learning:export-pool)
 * comes back without losses through BOTH importers — the interface import and
 * occ learning:import-pool-json. The export used to write only text, type, scenario,
 * explanation and answers, so PBQ questions arrived without subtype and configuration (and
 * were refused or dropped), and difficulty and chapter were gone.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolExportRoundTripTest extends TestCase {
    use ImportControllerHarness;
    use PoolImportHarness;

    private const RANKING = ['intro_note' => 'Order them', 'items' => [['id' => 'a', 'label' => 'Plan', 'correct_position' => 1], ['id' => 'b', 'label' => 'Do', 'correct_position' => 2]]];
    private const DROPDOWN = ['questions' => [['id' => 'q1', 'prompt' => 'HTTPS', 'options' => ['22', '443'], 'correct' => '443']]];

    /** @return array<int, mixed> the exported pool, decoded as a file reader would see it */
    private function export(): array {
        $mc = new Question();
        $mc->setId(21);
        $mc->setText('Which port does HTTPS use?');
        $mc->setQuestionType('single');
        $mc->setExplanation('443/tcp.');
        $mc->setDifficulty('hard');
        $mc->setScenario("A web server.\nBehind a proxy.");
        $mc->setChapterKey('3.2');
        $mc->setChapterTitle('Transport');
        $mc->setChapterOrder(4);
        $mc->setHandbookKey('ccna');
        $mc->setHandbookTitle('CCNA');
        $mc->setExamKey('ccna-q7');

        $ranking = new Question();
        $ranking->setId(22);
        $ranking->setText('Order the PDCA steps');
        $ranking->setQuestionType('pbq');
        $ranking->setPbqSubtype('ranking');
        $ranking->setPbqConfig(json_encode(self::RANKING));
        $ranking->setDifficulty('easy');
        $ranking->setExplanation('Plan comes first.');
        $ranking->setScenario('A team meeting.');
        $ranking->setChapterKey('1.0');

        $dropdown = new Question();
        $dropdown->setId(23);
        $dropdown->setText('Match the ports');
        $dropdown->setQuestionType('pbq');
        $dropdown->setPbqSubtype('dropdown');
        $dropdown->setPbqConfig(json_encode(self::DROPDOWN));

        $a = static function (int $qid, string $text, bool $correct): Answer {
            $answer = new Answer();
            $answer->setQuestionId($qid);
            $answer->setText($text);
            $answer->setIsCorrect($correct);
            return $answer;
        };

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('findById')->willReturn(new Pool());
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByPoolId')->willReturn([$mc, $ranking, $dropdown]);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestions')->willReturn([21 => [$a(21, '80', false), $a(21, '443', true)]]);

        $export = (new DataMobilityService(
            $poolMapper,
            $questionMapper,
            $answerMapper,
            $this->createMock(CourseMapper::class),
            $this->createMock(CoursePoolMapper::class),
            $this->createMock(CourseMemberMapper::class),
            new FakeDbConnection(),
            $this->createMock(IUserManager::class)
        ))->exportPoolJson(5);

        // Through JSON, exactly as the downloaded file is read back.
        return json_decode(json_encode($export, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheExportCarriesPbqAndMetadata(): void {
        $export = $this->export();

        $this->assertTrue(array_is_list($export), 'the export stays a bare list (PoolJsonRoundTripTest wraps it itself)');
        $this->assertSame('hard', $export[0]['difficulty']);
        $this->assertSame(['3.2', 'Transport', 4, 'ccna', 'CCNA', 'ccna-q7'], [
            $export[0]['chapter_key'], $export[0]['chapter_title'], $export[0]['chapter_order'],
            $export[0]['handbook_key'], $export[0]['handbook_title'], $export[0]['exam_key'],
        ]);
        $this->assertSame('ranking', $export[1]['subtype']);
        $this->assertSame(self::RANKING, $export[1]['pbq_config']);
        $this->assertArrayNotHasKey('chapter_title', $export[1], 'empty fields are left out');
    }

    public function testTheInterfaceImportRestoresEverything(): void {
        $r = $this->importController()->importJson(9, json_encode($this->export(), JSON_THROW_ON_ERROR));

        $this->assertSame(201, $r->getStatus(), json_encode($r->getData()));
        $this->assertSame(3, $r->getData()['imported'], json_encode($r->getData()));
        [$mc, $ranking, $dropdown] = $this->questions;

        $this->assertSame(['single', 'hard', '443/tcp.', "A web server.\nBehind a proxy."], [$mc->getQuestionType(), $mc->getDifficulty(), $mc->getExplanation(), $mc->getScenario()]);
        $this->assertSame(['3.2', 'Transport', 4, 'ccna', 'CCNA', 'ccna-q7'], [$mc->getChapterKey(), $mc->getChapterTitle(), (int)$mc->getChapterOrder(), $mc->getHandbookKey(), $mc->getHandbookTitle(), $mc->getExamKey()]);
        $this->assertSame([['text' => '80', 'correct' => false], ['text' => '443', 'correct' => true]], $this->storedAnswers($mc));

        $this->assertSame(['pbq', 'ranking', 'easy', 'Plan comes first.', 'A team meeting.', '1.0'], [
            $ranking->getQuestionType(), $ranking->getPbqSubtype(), $ranking->getDifficulty(), $ranking->getExplanation(), $ranking->getScenario(), $ranking->getChapterKey(),
        ]);
        $this->assertSame(self::RANKING, json_decode((string)$ranking->getPbqConfig(), true));
        $this->assertSame('Order the PDCA steps', $ranking->getText());
        $this->assertSame(['pbq', 'dropdown'], [$dropdown->getQuestionType(), $dropdown->getPbqSubtype()]);
        $this->assertSame(self::DROPDOWN, json_decode((string)$dropdown->getPbqConfig(), true));
    }

    public function testOccImportRestoresEverything(): void {
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
        $file = tempnam(sys_get_temp_dir(), 'learning-export-');
        $this->assertNotFalse($file);
        file_put_contents($file, json_encode($this->export(), JSON_THROW_ON_ERROR));

        [, $display] = $this->runOcc($this->occImportCommand($pools, $questions), ['file' => $file, '--user' => 'alice', '--pool-name' => 'Copy']);
        unlink($file);

        $this->assertCount(3, $created, $display);
        // create(poolId, user, text, expl, difficulty, answers, type, sub, cfg, note, visible, hbKey, hbTitle, examKey, chKey, chTitle, chOrder, scenario)
        [$mc, $ranking, $dropdown] = $created;
        $this->assertSame(['Which port does HTTPS use?', '443/tcp.', 'hard', 'single'], [$mc[2], $mc[3], $mc[4], $mc[6]]);
        $this->assertSame([['text' => '80', 'is_correct' => false], ['text' => '443', 'is_correct' => true]], $mc[5]);
        $this->assertSame(['ccna', 'CCNA', 'ccna-q7', '3.2', 'Transport', 4, "A web server.\nBehind a proxy."], array_slice($mc, 11, 7));

        $this->assertSame(['Order the PDCA steps', 'Plan comes first.', 'easy', 'pbq', 'ranking'], [$ranking[2], $ranking[3], $ranking[4], $ranking[6], $ranking[7]]);
        $this->assertSame(self::RANKING, json_decode((string)$ranking[8], true));
        $this->assertSame(['1.0', 'A team meeting.'], [$ranking[14], $ranking[17]]);
        $this->assertSame(['pbq', 'dropdown'], [$dropdown[6], $dropdown[7]]);
        $this->assertSame(self::DROPDOWN, json_decode((string)$dropdown[8], true));
    }

    /**
     * @param list<Question> $questions
     * @param array<int, list<Answer>> $answers
     */
    private function service(array $questions, array $answers): DataMobilityService {
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('findById')->willReturn(new Pool());
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByPoolId')->willReturn($questions);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestions')->willReturn($answers);
        return new DataMobilityService(
            $poolMapper,
            $questionMapper,
            $answerMapper,
            $this->createMock(CourseMapper::class),
            $this->createMock(CoursePoolMapper::class),
            $this->createMock(CourseMemberMapper::class),
            new FakeDbConnection(),
            $this->createMock(IUserManager::class)
        );
    }

    private static function answer(int $qid, string $text, bool $correct): Answer {
        $a = new Answer();
        $a->setQuestionId($qid);
        $a->setText($text);
        $a->setIsCorrect($correct);
        return $a;
    }

    /**
     * The CSV export wrote with fputcsv's default backslash escape, the importer reads RFC 4180
     * (no escape): a field containing \" came back torn apart.
     */
    public function testTheCsvExportComesBackThroughTheCsvImport(): void {
        $q = new Question();
        $q->setId(31);
        $q->setText('Which path is "C:\\"? Say \\"yes\\"');
        $q->setQuestionType('single');
        $q->setExplanation('Backslash \\" quote, and a comma');
        $csv = $this->service([$q], [31 => [self::answer(31, 'He said \\"no\\"', false), self::answer(31, 'C:\\', true)]])->exportPoolCsv(5);

        $r = $this->importController()->importCsv(9, $csv);

        $this->assertSame(1, $r->getData()['imported'] ?? null, $csv . "\n" . json_encode($r->getData()));
        $this->assertSame('Which path is "C:\\"? Say \\"yes\\"', $this->questions[0]->getText());
        $this->assertSame('Backslash \\" quote, and a comma', $this->questions[0]->getExplanation());
        $this->assertSame([['text' => 'He said \\"no\\"', 'correct' => false], ['text' => 'C:\\', 'correct' => true]], $this->storedAnswers($this->questions[0]));
    }

    /** A multi question with exactly one correct answer comes back as multi through both importers. */
    public function testAMultiQuestionWithOneCorrectAnswerStaysMulti(): void {
        $q = new Question();
        $q->setId(41);
        $q->setText('Pick all that apply');
        $q->setQuestionType('multi');
        $export = $this->service([$q], [41 => [self::answer(41, 'a', true), self::answer(41, 'b', false), self::answer(41, 'c', false)]])->exportPoolJson(5);
        $json = json_encode($export, JSON_THROW_ON_ERROR);

        $r = $this->importController()->importJson(9, $json);
        $this->assertSame(1, $r->getData()['imported'], json_encode($r->getData()));
        $this->assertSame('multi', $this->questions[0]->getQuestionType());

        $created = [];
        $pools = $this->createMock(PoolService::class);
        $pools->method('create')->willReturnCallback(static function (): Pool {
            $p = new Pool();
            $p->setId(78);
            return $p;
        });
        $questions = $this->createMock(QuestionService::class);
        $questions->method('create')->willReturnCallback(static function (...$args) use (&$created): array {
            $created[] = $args;
            return [];
        });
        $file = tempnam(sys_get_temp_dir(), 'learning-export-');
        $this->assertNotFalse($file);
        file_put_contents($file, $json);
        [, $display] = $this->runOcc($this->occImportCommand($pools, $questions), ['file' => $file, '--user' => 'alice', '--pool-name' => 'Copy']);
        unlink($file);
        $this->assertCount(1, $created, $display);
        $this->assertSame('multi', $created[0][6]);
    }

    public function testRankingIsAnAllowedPbqSubtypeEverywhere(): void {
        $this->assertContains('ranking', PoolJsonFormat::PBQ_SUBTYPES);
        $r = $this->importController()->importJson(9, json_encode([['type' => 'pbq', 'text' => 'Order', 'subtype' => 'ranking', 'pbq_config' => self::RANKING]], JSON_THROW_ON_ERROR));
        $this->assertSame(1, $r->getData()['imported'], json_encode($r->getData()));
        $this->assertSame('ranking', $this->questions[0]->getPbqSubtype());
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
}
