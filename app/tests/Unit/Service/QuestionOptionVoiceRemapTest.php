<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionCuesMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTrack;
use OCA\Learning\Db\QuestionTrackMapper;
use OCA\Learning\Service\QuestionMediaStorage;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\Files\IAppData;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #66: the spoken explanation of an answer option is stored as opt<answer position>.
 * When an editor reorders or removes answers, option_feedback moves with its answers
 * (RichFeedbackFormat::positionMap) — the voices stayed on their positions, so the voice
 * "… is correct" played under whichever answer took that place, and the voice of a removed
 * answer came back for the next answer added there. They follow the same map now.
 */
#[AllowMockObjectsWithoutExpectations]
final class QuestionOptionVoiceRemapTest extends TestCase {
    /** @var array<int, QuestionTrack> the stored track rows by id */
    private array $rows = [];
    private int $nextId = 100;

    public function testSwappedAnswersTakeTheirVoicesAlong(): void {
        $this->storeVoices([0, 1, 2]);

        $this->service(['A', 'B', 'C'])->update(42, 'author', 'Q?', null, null, [
            ['text' => 'B', 'is_correct' => false],
            ['text' => 'A', 'is_correct' => true],
            ['text' => 'C', 'is_correct' => false],
        ]);

        $this->assertSame([
            'opt0-en' => 'q42-opt1-en.mp3',   // B's voice, now first
            'opt1-en' => 'q42-opt0-en.mp3',   // A's voice, now second
            'opt2-en' => 'q42-opt2-en.mp3',
        ], $this->voices());
        $this->assertSame('intro', $this->rows[1]->getKind(), 'the intro is no option voice');
    }

    public function testTheVoiceOfARemovedAnswerIsDroppedAndNeverComesBackForTheNextAnswer(): void {
        $this->storeVoices([0, 1, 2]);

        $this->service(['A', 'B', 'C'])->update(42, 'author', 'Q?', null, null, [
            ['text' => 'A', 'is_correct' => true],
            ['text' => 'C', 'is_correct' => false],
        ]);
        $this->assertSame(['opt0-en' => 'q42-opt0-en.mp3', 'opt1-en' => 'q42-opt2-en.mp3'], $this->voices());

        $this->service(['A', 'C'])->update(42, 'author', 'Q?', null, null, [
            ['text' => 'A', 'is_correct' => true],
            ['text' => 'C', 'is_correct' => false],
            ['text' => 'D', 'is_correct' => false],
        ]);
        $this->assertSame(['opt0-en' => 'q42-opt0-en.mp3', 'opt1-en' => 'q42-opt2-en.mp3'], $this->voices(), 'D has no voice');
    }

    public function testSavingTheSameAnswersLeavesTheVoicesAlone(): void {
        $this->storeVoices([0, 1]);
        $before = $this->rows;

        $this->service(['A', 'B'])->update(42, 'author', 'New text', null, null, [
            ['text' => 'A', 'is_correct' => true],
            ['text' => 'B', 'is_correct' => false],
        ]);

        $this->assertSame($before, $this->rows);
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<int> $positions an intro plus one English voice per position, as the import writes them */
    private function storeVoices(array $positions): void {
        $this->rows = [];
        $this->addRow('intro');
        foreach ($positions as $position) {
            $this->addRow(QuestionMediaStorage::optionKind($position));
        }
    }

    private function addRow(string $kind): void {
        $track = new QuestionTrack();
        $track->setId(count($this->rows) + 1);
        $track->setQuestionId(42);
        $track->setPoolId(7);
        $track->setKind($kind);
        $track->setLang('en');
        $track->setFileName(QuestionMediaStorage::fileName(42, $kind, 'en'));
        $track->setSize(10);
        $track->setSha256('sha-' . $kind);
        $this->rows[$track->getId()] = $track;
    }

    /** @return array<string, string> slot => file name of the option voices */
    private function voices(): array {
        $out = [];
        foreach ($this->rows as $track) {
            if (QuestionMediaStorage::optionPosition($track->getKind()) !== null) {
                $out[$track->getKind() . '-' . $track->getLang()] = $track->getFileName();
            }
        }
        ksort($out);
        return $out;
    }

    /** @param list<string> $storedAnswerTexts the answers as stored before the save */
    private function service(array $storedAnswerTexts): QuestionService {
        $tracks = $this->createMock(QuestionTrackMapper::class);
        $tracks->method('findByQuestion')->willReturnCallback(fn (): array => array_values($this->rows));
        $tracks->method('delete')->willReturnCallback(function (QuestionTrack $t): QuestionTrack {
            unset($this->rows[$t->getId()]);
            return $t;
        });
        $tracks->method('insert')->willReturnCallback(function (QuestionTrack $t): QuestionTrack {
            foreach ($this->rows as $row) {
                if ($row->getKind() === $t->getKind() && $row->getLang() === $t->getLang()) {
                    $this->fail('unique index (question_id, kind, lang) violated by ' . $t->getKind());
                }
            }
            $t->setId($this->nextId++);
            $this->rows[$t->getId()] = $t;
            return $t;
        });
        $storage = new QuestionMediaStorage(
            $this->createMock(IAppData::class), $tracks, $this->createMock(QuestionCuesMapper::class),
            new FakeDbConnection(), $this->createMock(LoggerInterface::class),
        );

        $stored = new Question();
        $stored->setId(42);
        $stored->setPoolId(7);
        $stored->setText('Old');
        $stored->setQuestionType('single');
        $questions = $this->createMock(QuestionMapper::class);
        $questions->method('findById')->willReturn($stored);
        $questions->method('createOrUpdate')->willReturnArgument(0);
        $answers = $this->createMock(AnswerMapper::class);
        $answers->method('createOrUpdate')->willReturnArgument(0);
        $answers->method('findByQuestion')->willReturn(array_map(static function (string $text, int $i): Answer {
            $a = new Answer();
            $a->setText($text);
            $a->setPosition($i);
            return $a;
        }, $storedAnswerTexts, array_keys($storedAnswerTexts)));
        $pools = $this->createMock(PoolMapper::class);
        $pools->method('find')->willReturn(new Pool());

        return new QuestionService(
            $questions, $answers, $this->createMock(PoolShareMapper::class), $pools,
            new FakeDbConnection(), $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class), $storage,
        );
    }
}
