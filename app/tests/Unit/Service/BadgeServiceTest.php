<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\BadgeService;
use OCA\Learning\Activity\ActivityPublisher;
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

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->config = $this->createMock(IConfig::class);
        $this->activityManager = $this->createMock(ActivityPublisher::class);

        $this->service = new BadgeService(
            $this->db,
            $this->notificationManager,
            $this->config,
            $this->activityManager
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
}
