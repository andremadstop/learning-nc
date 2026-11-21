<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\BadgeService;
use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class BadgeServiceTest extends TestCase {
    private IDBConnection $db;
    private INotificationManager $notificationManager;
    private IConfig $config;
    private ActivityPublisher $activityManager;
    private BadgeService $service;
    private FakeCacheFactory $cacheFactory;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->config = $this->createMock(IConfig::class);
        $this->activityManager = $this->createMock(ActivityPublisher::class);
        $this->cacheFactory = new FakeCacheFactory();

        $this->service = new BadgeService(
            $this->db,
            $this->notificationManager,
            $this->config,
            $this->activityManager,
            $this->cacheFactory
        );
    }

    public function testGetUserBadgesIncludesLegacyAndActiveMetadataWhenGamificationDisabled(): void {
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->with('learning', 'gamification_enabled', 'yes')
            ->willReturn('no');

        $badges = $this->service->getUserBadges('alice');

        $legacyBadge = $this->findBadge($badges, 'first_session');
        $activeBadge = $this->findBadge($badges, 'pioneer');

        $this->assertTrue($legacyBadge['legacy']);
        $this->assertFalse($legacyBadge['earned']);
        $this->assertFalse($activeBadge['legacy']);
        $this->assertSame('badge_pioneer_name', $activeBadge['name_key']);
        $this->assertSame('badge_pioneer_trigger', $activeBadge['trigger_key']);
        $this->assertSame(50, $activeBadge['threshold']);
    }

    public function testAwardIfNewReturnsNothingForLegacyBadges(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');

        $method = new \ReflectionMethod(BadgeService::class, 'awardIfNew');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'alice', 'first_session', true);

        $this->assertSame([], $result);
    }

    private function findBadge(array $badges, string $badgeId): array {
        foreach ($badges as $badge) {
            if (($badge['badge_id'] ?? null) === $badgeId) {
                return $badge;
            }
        }

        $this->fail('Badge not found: ' . $badgeId);
    }

    /**
     * GitHub #85: a badge goes to the activity through the publisher, whose author is not the
     * learner. The old code published it with author = the learner, a "self action" for which the
     * activity app never sends an email — even after the learner switched the email on.
     */
    public function testADispatchedBadgeIsPublishedThroughThePublisher(): void {
        $this->config->method('getAppValue')->willReturn('yes');
        $notification = $this->createMock(\OCP\Notification\INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $m) {
            $notification->method($m)->willReturnSelf();
        }
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');
        $this->activityManager->expects($this->once())->method('badgeEarned')
            ->with('alice', 'first_session', 'First Steps', "\u{2B50}");

        $this->service->dispatchNotifications('alice', [['badge_id' => 'first_session']]);
    }

    /**
     * GitHub #101: Today lists the achievements from /api/v1/user/state, which is cached for 30 s.
     * A badge can come without any XP (a RAG contribution, a finished coop simulator), so XpService
     * never cleared that cache and the activity link opened Today without the new achievement.
     * Awarding a badge clears the same keys XpService clears.
     */
    public function testAwardingABadgeClearsTheCachedUserStateAndProfile(): void {
        $db = new FakeDbConnection();
        $service = new BadgeService(
            $db,
            $this->notificationManager,
            $this->config,
            $this->activityManager,
            $this->cacheFactory
        );

        $method = new \ReflectionMethod(BadgeService::class, 'awardIfNew');
        $awarded = $method->invoke($service, 'alice', 'swarm', false);

        $this->assertSame('swarm', $awarded[0]['badge_id'] ?? null);
        $this->assertSame('learning_user_badges', $db->ignoreConflictInserts[0]['table'] ?? null);
        $this->assertEqualsCanonicalizing(
            ['user_state_alice', 'profile_alice_all', 'lernprofil_alice_all'],
            $this->cacheFactory->cache->removedKeys
        );
    }

    /**
     * (Pre-existing) LeitnerService::answerQuestion() checks the mastery badge inside its write
     * transaction when a card reaches box 5. The old award found "already earned" by a failing
     * INSERT on UNIQUE(user_id, badge_id); on PostgreSQL that aborts the transaction (25P02), the
     * stats UPDATE after it fails, and the box change and the XP are rolled back — on every further
     * card that reached box 5 once 'mastermind' was earned.
     */
    public function testAnEarnedBadgeInsideATransactionLeavesTheTransactionUsable(): void {
        $db = new PostgresLikeBadgeConnection();
        $service = new BadgeService($db, $this->notificationManager, $this->config, $this->activityManager, $this->cacheFactory);
        $method = new \ReflectionMethod(BadgeService::class, 'awardIfNew');

        $db->beginTransaction();
        $awarded = $method->invoke($service, 'alice', 'mastermind', false);

        $this->assertSame([], $awarded, 'the badge was earned before');
        $this->assertFalse($db->aborted, 'a failed statement aborts a PostgreSQL transaction; the box update after it is lost');
        $this->assertSame([['learning_user_badges', 'alice', 'mastermind']], $db->ignoredConflicts);
    }

    public function testASecondAwardReturnsNothingAndIssuesNoPlainInsert(): void {
        $db = new FakeDbConnection();
        $db->ignoreConflictResults = [1, 0];
        $service = new BadgeService($db, $this->notificationManager, $this->config, $this->activityManager, $this->cacheFactory);
        $method = new \ReflectionMethod(BadgeService::class, 'awardIfNew');

        $first = $method->invoke($service, 'alice', 'swarm', false);
        $second = $method->invoke($service, 'alice', 'swarm', false);

        $this->assertSame('swarm', $first[0]['badge_id'] ?? null);
        $this->assertSame([], $second);
        $this->assertCount(2, $db->ignoreConflictInserts);
        $this->assertSame('learning_user_badges', $db->ignoreConflictInserts[0]['table']);
        $this->assertSame(['user_id' => 'alice', 'badge_id' => 'swarm'], array_intersect_key($db->ignoreConflictInserts[0]['values'], ['user_id' => 1, 'badge_id' => 1]));
        $this->assertIsInt($db->ignoreConflictInserts[0]['values']['earned_at'] ?? null);
        foreach ($db->issuedBuilders as $builder) {
            $this->assertNotSame('insert', $builder->operation, 'a plain INSERT that fails on the UNIQUE index aborts a PostgreSQL transaction');
        }
    }

    public function testALegacyBadgeThatIsNotAwardedLeavesTheCacheAlone(): void {
        $method = new \ReflectionMethod(BadgeService::class, 'awardIfNew');
        $method->invoke($this->service, 'alice', 'first_session', true);

        $this->assertSame([], $this->cacheFactory->cache->removedKeys);
    }
}

/**
 * A PostgreSQL connection on which the badge already exists: a plain INSERT fails on the UNIQUE
 * index and, inside a transaction, aborts it; INSERT … ON CONFLICT DO NOTHING answers 0.
 */
final class PostgresLikeBadgeConnection implements IDBConnection {
    public bool $aborted = false;
    /** @var list<array{string, mixed, mixed}> */
    public array $ignoredConflicts = [];
    private bool $inTx = false;

    public function getQueryBuilder(): FakeQueryBuilder {
        return new class($this) extends FakeQueryBuilder {
            public function __construct(private PostgresLikeBadgeConnection $connection) {
                parent::__construct();
            }

            public function executeStatement(): int {
                if ($this->connection->aborted) {
                    throw new \RuntimeException('current transaction is aborted, commands ignored until end of transaction block');
                }
                if ($this->operation === 'insert' && $this->table === 'learning_user_badges') {
                    $this->connection->failStatement();
                    throw new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate key value violates unique constraint');
                }
                return 1;
            }
        };
    }

    public function failStatement(): void {
        if ($this->inTx) {
            $this->aborted = true;
        }
    }

    public function insertIgnoreConflict(string $table, array $values): int {
        if ($this->aborted) {
            throw new \RuntimeException('current transaction is aborted, commands ignored until end of transaction block');
        }
        $this->ignoredConflicts[] = [$table, $values['user_id'] ?? null, $values['badge_id'] ?? null];
        return 0;
    }

    public function executeQuery(string $sql, array $params = []): FakeResult {
        return new FakeResult();
    }

    public function escapeLikeParameter(string $input): string {
        return addcslashes($input, '\\%_');
    }

    public function beginTransaction(): void {
        $this->inTx = true;
    }

    public function commit(): void {
        $this->inTx = false;
        $this->aborted = false;
    }

    public function rollBack(): void {
        $this->inTx = false;
        $this->aborted = false;
    }

    public function inTransaction(): bool {
        return $this->inTx;
    }
}
