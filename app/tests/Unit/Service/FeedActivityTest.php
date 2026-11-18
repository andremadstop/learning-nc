<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\FeedItem;
use OCA\Learning\Db\FeedItemMapper;
use OCA\Learning\Service\FeedService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #85: a new feed entry is also an activity for the course's members (email off by
 * default), with ids and titles only — never the announcement's body.
 */
#[AllowMockObjectsWithoutExpectations]
final class FeedActivityTest extends TestCase {
    /** @var list<array<int, mixed>> */
    private array $activities = [];

    private function service(): FeedService {
        $mapper = $this->createMock(FeedItemMapper::class);
        $mapper->method('insert')->willReturnArgument(0);
        $publisher = $this->createMock(ActivityPublisher::class);
        $publisher->method('toCourseMembers')->willReturnCallback(function (...$args): void {
            $this->activities[] = $args;
        });
        return new FeedService($mapper, $this->createMock(CourseMemberMapper::class), $publisher);
    }

    public function testANewPoolIsAnActivityWithItsIdAndName(): void {
        $item = $this->service()->createAutoItem(7, 'pool_added', 'Neuer Fragenpool: Pool A', null, 'teacher', ['pool_id' => 3, 'pool_name' => 'Pool A']);

        $this->assertInstanceOf(FeedItem::class, $item);
        $this->assertSame([['feed_pool_added', 7, 'teacher', ['pool_id' => 3, 'pool_name' => 'Pool A']]], $this->activities);
    }

    public function testAnAnnouncementIsAnActivityWithItsTitleButNotItsBody(): void {
        $this->service()->createAutoItem(7, 'announcement', 'Prüfung verschoben', 'Geheimer Raum 4', 'teacher', ['announcement_id' => 12]);

        $this->assertSame([['feed_announcement', 7, 'teacher', ['announcement_id' => 12, 'title' => 'Prüfung verschoben']]], $this->activities);
        $this->assertStringNotContainsString('Geheimer', json_encode($this->activities, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testOtherFeedKindsPublishNothing(): void {
        $this->service()->createAutoItem(7, 'something_else', 'X', null, 'teacher');

        $this->assertSame([], $this->activities);
    }
}
