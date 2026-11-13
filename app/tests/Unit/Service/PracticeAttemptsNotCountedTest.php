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
}
