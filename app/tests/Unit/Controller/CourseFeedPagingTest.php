<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\FeedController;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\FeedItemMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit (S39): CourseFeed.vue pages with ?limit=50&offset=N, but courseFeed() took neither,
 * so "Load more" fetched the newest 50 items again and appended duplicates on every click.
 * Controller, service and mapper run for real here; only the database is fake.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseFeedPagingTest extends TestCase {

    /** @return array{0: FeedController, 1: FakeQueryBuilder} */
    private function controller(): array {
        $qb = new FakeQueryBuilder();
        $service = new FeedService(
            new FeedItemMapper(new FakeDbConnection([$qb])),
            $this->createMock(CourseMemberMapper::class)
        );
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn(['id' => 7]);
        $controller = new FeedController(
            'learning',
            $this->createMock(IRequest::class),
            $service,
            $this->createMock(CourseMapper::class),
            $courseService,
            'alice'
        );
        return [$controller, $qb];
    }

    public function testOffsetAndLimitReachTheQuery(): void {
        [$controller, $qb] = $this->controller();

        $resp = $controller->courseFeed(7, 50, 100);

        self::assertSame(200, $resp->getStatus());
        self::assertSame(100, $qb->firstResult);
        self::assertSame(50, $qb->maxResults);
        // a stable order, so consecutive pages neither overlap nor skip rows with equal timestamps
        self::assertSame(['created_at', 'id'], array_column($qb->orderBys, 'field'));
    }

    public function testDefaultsAreTheFirstPage(): void {
        [$controller, $qb] = $this->controller();

        $controller->courseFeed(7);

        self::assertSame(0, (int)$qb->firstResult);
        self::assertSame(50, $qb->maxResults);
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int}> */
    public static function outOfRange(): array {
        return [
            'negative offset' => [50, -10, 50, 0],
            'zero limit'      => [0, 0, 1, 0],
            'huge limit'      => [100000, 0, 100, 0],
        ];
    }

    #[DataProvider('outOfRange')]
    public function testOutOfRangeValuesAreClamped(int $limit, int $offset, int $expectedLimit, int $expectedOffset): void {
        [$controller, $qb] = $this->controller();

        $controller->courseFeed(7, $limit, $offset);

        self::assertSame($expectedLimit, $qb->maxResults);
        self::assertSame($expectedOffset, $qb->firstResult);
    }

    /**
     * Audit #207: the aggregated feed (index) had no bounds at all.
     */
    #[DataProvider('outOfRange')]
    public function testUserFeedClampsLimitAndOffset(int $limit, int $offset, int $expectedLimit, int $expectedOffset): void {
        $service = $this->createMock(FeedService::class);
        $service->expects($this->once())->method('getUserFeed')
            ->with('alice', $expectedLimit, $expectedOffset)
            ->willReturn([]);
        $controller = new FeedController(
            'learning',
            $this->createMock(IRequest::class),
            $service,
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseService::class),
            'alice'
        );

        self::assertSame(200, $controller->index($limit, $offset)->getStatus());
    }
}
