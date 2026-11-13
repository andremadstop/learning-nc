<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Practice attempts (learning_sessions.exam_kind set: course practice exams, pool practice runs)
 * never count. The learning profile's exam score per pool and the >85 % exam badges read
 * mode='exam' rows and must leave them out, like every certificate query does.
 */
#[AllowMockObjectsWithoutExpectations]
final class PracticeAttemptsNotCountedTest extends TestCase {
    private const PRACTICE_EXCLUDED = ['type' => 'isNull', 'field' => 'exam_kind'];

    public function testLearningProfileExamScoresLeaveOutPracticeAttempts(): void {
        $query = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $service = new LernprofilService(new FakeDbConnection([$query]), new FakeCacheFactory());

        $method = new \ReflectionMethod(LernprofilService::class, 'getExamStatsByPool');
        $method->invoke($service, 'alice', [42]);

        $this->assertSame('learning_sessions', $query->from['table'] ?? null);
        $this->assertContains(self::PRACTICE_EXCLUDED, $query->andWhereCalls);
    }

    public function testExamBadgeRunCountLeavesOutPracticeAttempts(): void {
        $query = new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 0]));
        $service = new BadgeService(
            new FakeDbConnection([$query]),
            $this->createMock(INotificationManager::class),
            $this->createMock(IConfig::class),
            $this->createMock(IActivityManager::class),
        );

        $method = new \ReflectionMethod(BadgeService::class, 'getSuccessfulExamRunCount');
        $this->assertSame(0, $method->invoke($service, 'alice'));

        $this->assertSame('learning_sessions', $query->from['table'] ?? null);
        $this->assertContains(self::PRACTICE_EXCLUDED, $query->andWhereCalls);
    }

    /**
     * A fast, perfect exam run: 100 %, finished in 100 s of a 3600 s limit. Every count query
     * answers 3, so the exam_ready threshold (3 runs above 85 %) is met as well.
     */
    private const FAST_PERFECT_EXAM = [
        'mode' => 'exam',
        'total_questions' => 10,
        'correct_answers' => 10,
        'started_at' => 1000,
        'completed_at' => 1100,
        'time_limit_seconds' => 3600,
    ];

    /**
     * Badge ids inserted by one session_complete check. Read from the issued insert builders,
     * not from the query order: the weekend check adds two queries on Saturday and Sunday only.
     *
     * @return list<string>
     */
    private function awardedSessionBadges(array $sessionData): array {
        $builders = [];
        for ($i = 0; $i < 12; $i++) {
            $builders[] = new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 3]));
        }
        $db = new FakeDbConnection($builders);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('yes');
        $service = new BadgeService(
            $db,
            $this->createMock(INotificationManager::class),
            $config,
            $this->createMock(IActivityManager::class),
        );

        $service->checkAndAward('alice', 'session_complete', $sessionData, false);

        $awarded = [];
        foreach ($db->issuedBuilders as $builder) {
            if ($builder->operation === 'insert' && $builder->table === 'learning_user_badges') {
                $awarded[] = $builder->insertValues['badge_id']['value'];
            }
        }
        return $awarded;
    }

    /** Control: a real exam run (exam_kind NULL, or no key at all) still earns both exam badges. */
    public function testRealExamRunEarnsTheExamBadges(): void {
        foreach ([self::FAST_PERFECT_EXAM, self::FAST_PERFECT_EXAM + ['exam_kind' => null]] as $data) {
            $awarded = $this->awardedSessionBadges($data);
            $this->assertContains('quick_thinker', $awarded);
            $this->assertContains('exam_ready', $awarded);
        }
    }

    /** @return array<string, array{string}> */
    public static function practiceKinds(): array {
        return ['course practice exam' => ['practice'], 'pool practice run' => ['pool_practice']];
    }

    #[DataProvider('practiceKinds')]
    public function testPracticeAttemptEarnsNoExamBadges(string $examKind): void {
        $awarded = $this->awardedSessionBadges(self::FAST_PERFECT_EXAM + ['exam_kind' => $examKind]);

        $this->assertNotContains('quick_thinker', $awarded);
        $this->assertNotContains('exam_ready', $awarded);
    }
}
