<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;

/**
 * Answers by table instead of by call order, so a query added or moved inside getCheatSheet()
 * does not shift every later result onto the wrong query.
 */
final class CheatSheetTableRoutedQueryBuilder extends FakeQueryBuilder {
    /** @param array<string, list<array<string, mixed>>> $rowsByTable */
    public function __construct(private array $rowsByTable) {
        parent::__construct();
    }

    public function executeQuery(): FakeResult {
        $table = (string)($this->from['table'] ?? '');
        if ($table === 'learning_pools' && in_array('name', $this->selects, true)) {
            $table = 'learning_pools:names';
        }
        if ($table === 'learning_course_pools' && count($this->selects) > 1) {
            $table = 'learning_course_pools:courses';
        }
        $rows = $this->rowsByTable[$table] ?? [];
        // The trouble-item query restricts to the accessible pools with li.pool_id IN (...);
        // apply that condition like the database would.
        foreach (array_merge($this->whereCalls, $this->andWhereCalls) as $condition) {
            if (is_array($condition) && ($condition['type'] ?? null) === 'in' && $condition['left'] === 'li.pool_id') {
                $allowed = array_map('intval', $condition['right']['value']);
                $rows = array_values(array_filter($rows, static fn (array $row): bool => in_array((int)$row['pool_id'], $allowed, true)));
            }
        }
        return FakeResult::fromFetchAll($rows);
    }
}

/**
 * 2026-11 audit S24: the cheat sheet lists correct answers and explanations of every trouble
 * question across all pools — also of the pool the student is taking a graded exam on right now.
 * Every other answer-bearing read path withholds them during an active exam.
 */
class LernprofilCheatSheetExamTest extends TestCase {
    private function cheatSheet(array $openExamPools): array {
        $rows = [
            'learning_pools' => [['id' => 42], ['id' => 43]],
            'learning_pools:names' => [['id' => 42, 'name' => 'Exam pool'], ['id' => 43, 'name' => 'Other pool']],
            'learning_leitner_items' => [
                ['question_id' => 10, 'pool_id' => 42, 'correct_count' => 0, 'incorrect_count' => 3],
                ['question_id' => 11, 'pool_id' => 43, 'correct_count' => 1, 'incorrect_count' => 3],
            ],
            'learning_questions' => [
                ['id' => 10, 'text' => 'Exam question', 'explanation' => 'Exam explanation', 'chapter_key' => null, 'chapter_title' => null, 'pool_id' => 42],
                ['id' => 11, 'text' => 'Other question', 'explanation' => 'Other explanation', 'chapter_key' => null, 'chapter_title' => null, 'pool_id' => 43],
            ],
            'learning_answers' => [
                ['question_id' => 10, 'text' => 'Exam answer'],
                ['question_id' => 11, 'text' => 'Other answer'],
            ],
            'learning_sessions' => array_map(static fn (int $id): array => ['pool_id' => $id], $openExamPools),
        ];
        $builders = [];
        for ($i = 0; $i < 20; $i++) {
            $builders[] = new CheatSheetTableRoutedQueryBuilder($rows);
        }
        $service = new LernprofilService(new FakeDbConnection($builders), new FakeCacheFactory());
        return $service->getCheatSheet('student');
    }

    public function testPoolWithOpenGradedExamIsLeftOut(): void {
        $sheet = $this->cheatSheet([42]);

        $this->assertSame([43], array_column($sheet['spots'], 'pool_id'));
        $this->assertStringNotContainsString('Exam answer', (string)json_encode($sheet));
        $this->assertStringNotContainsString('Exam explanation', (string)json_encode($sheet));
        $this->assertSame(1, $sheet['total_found']);
    }

    public function testWithoutExamEveryTroubleSpotIsListed(): void {
        $sheet = $this->cheatSheet([]);

        $this->assertSame([42, 43], array_column($sheet['spots'], 'pool_id'));
        $this->assertSame(['Exam answer'], $sheet['spots'][0]['correct_answers']);
    }
}
