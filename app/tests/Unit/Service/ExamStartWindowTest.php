<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\ExamStartWindow;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #92: the next possible start per pool for the Exam tab, from the starts
 * TrainingService::enforceExamStartPolicy() counts, by ExamAttemptPolicy::nextStartAt().
 */
#[AllowMockObjectsWithoutExpectations]
class ExamStartWindowTest extends TestCase {
    private const NOW = 1_700_000_000;

    private function window(array $rows, int $limitPerDay = 5, int $cooldownMinutes = 10, ?FakeQueryBuilder &$builder = null): ExamStartWindow {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn(string $app, string $key, string $default): string => match ($key) {
                'exam_attempt_limit_per_day' => (string)$limitPerDay,
                'exam_attempt_cooldown_minutes' => (string)$cooldownMinutes,
                default => $default,
            },
        );
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: $rows));
        return new ExamStartWindow(new FakeDbConnection([$builder]), new ExamAttemptPolicy($config));
    }

    public function testOnlyPoolsThatMustWaitAreNamed(): void {
        $rows = [
            ['pool_id' => '3', 'started_at' => (string)(self::NOW - 120)],   // cooldown runs until +480
            ['pool_id' => '4', 'started_at' => (string)(self::NOW - 3600)],  // cooldown long over
        ];

        $this->assertSame([3 => self::NOW + 480], $this->window($rows)->nextStartByPool('alice', 7, self::NOW));
    }

    public function testTheDailyLimitIsCountedPerPool(): void {
        // Two starts on pool 3 with a limit of 2 per day: the pool waits until the older one is
        // 24 hours old. One start on pool 4 under the same limit (no cooldown) may start again.
        $rows = [
            ['pool_id' => '3', 'started_at' => (string)(self::NOW - 7200)],
            ['pool_id' => '3', 'started_at' => (string)(self::NOW - 3600)],
            ['pool_id' => '4', 'started_at' => (string)(self::NOW - 1800)],
        ];

        $this->assertSame(
            [3 => self::NOW - 7200 + 86401],
            $this->window($rows, limitPerDay: 2, cooldownMinutes: 0)->nextStartByPool('alice', 7, self::NOW),
        );
    }

    public function testNoStartsMeansNoWait(): void {
        $this->assertSame([], $this->window([])->nextStartByPool('alice', 7, self::NOW));
    }

    // The same answer as for the result page, which asks for one pool.
    public function testOnePoolAgreesWithThePerPoolMap(): void {
        $rows = [['pool_id' => '3', 'started_at' => (string)(self::NOW - 120)]];

        $this->assertSame(self::NOW + 480, $this->window($rows)->nextStartAt('alice', 7, 3, self::NOW));
        $this->assertSame(self::NOW + 480, $this->window($rows)->nextStartByPool('alice', 7, self::NOW)[3]);
    }

    // Counting exams of this user in this course within the last 24 hours, like the start gate.
    public function testTheQueryCountsWhatTheStartGateCounts(): void {
        $this->window([], builder: $builder)->nextStartByPool('alice', 7, self::NOW);

        $params = array_column($builder->namedParameters, 'value');
        $this->assertSame('learning_sessions', $builder->from['table']);
        $this->assertContains('alice', $params);
        $this->assertContains(7, $params);
        $this->assertContains('exam', $params);
        $this->assertContains(self::NOW - 86400, $params);
    }
}
