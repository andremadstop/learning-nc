<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\RemoveOrphanedAnnouncementFeedItems;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #40: before the feed row named its announcement, deleting an announcement left its feed
 * copy visible to every course member. The step removes exactly those copies and nothing else.
 *
 * The fake database keeps both tables as rows, applies the step's WHERE type = … on the feed
 * select and its DELETE by primary key.
 */
#[AllowMockObjectsWithoutExpectations]
class RemoveOrphanedAnnouncementFeedItemsTest extends TestCase {
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    private function step(): RemoveOrphanedAnnouncementFeedItems {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
        return new RemoveOrphanedAnnouncementFeedItems($db);
    }

    private function queryBuilder(): IQueryBuilder {
        $q = ['op' => null, 'table' => null, 'where' => null];
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(fn ($col, $val) => [$col, $val]);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('select')->willReturnCallback(function () use ($qb, &$q) { $q['op'] = 'select'; return $qb; });
        $qb->method('from')->willReturnCallback(function ($t) use ($qb, &$q) { $q['table'] = $t; return $qb; });
        $qb->method('delete')->willReturnCallback(function ($t) use ($qb, &$q) { $q['op'] = 'delete'; $q['table'] = $t; return $qb; });
        $qb->method('where')->willReturnCallback(function ($w) use ($qb, &$q) { $q['where'] = $w; return $qb; });

        $qb->method('executeQuery')->willReturnCallback(function () use (&$q) {
            $this->assertSame('select', $q['op']);
            $rows = array_values($this->tables[$q['table']] ?? []);
            if ($q['where'] !== null) {
                [$col, $val] = $q['where'];
                $rows = array_values(array_filter($rows, fn ($r) => $r[$col] === $val));
            }
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnCallback(function () use (&$rows) {
                return array_shift($rows) ?? false;
            });
            return $result;
        });
        $qb->method('executeStatement')->willReturnCallback(function () use (&$q) {
            $this->assertSame('delete', $q['op']);
            [$col, $id] = $q['where'];
            $this->assertSame('id', $col, 'rows are deleted by primary key only');
            if (!isset($this->tables[$q['table']][$id])) {
                return 0;
            }
            unset($this->tables[$q['table']][$id]);
            return 1;
        });
        return $qb;
    }

    private function feed(int $id, int $courseId, string $type, string $title, ?array $meta): void {
        $this->tables['learning_feed_items'][$id] = [
            'id' => $id,
            'course_id' => $courseId,
            'type' => $type,
            'title' => $title,
            'meta' => $meta !== null ? json_encode($meta) : null,
        ];
    }

    private function announcement(int $id, int $courseId, string $title): void {
        $this->tables['learning_course_announcements'][$id] = ['id' => $id, 'course_id' => $courseId, 'title' => $title];
    }

    public function testOnlyUnlinkedCopiesOfDeletedAnnouncementsAreRemoved(): void {
        $this->announcement(1, 5, 'Exam moved');
        // the expired one is still a row, so its copy stays
        $this->announcement(2, 5, 'Old but expired');
        $long = str_repeat('x', 300);
        $this->announcement(3, 5, $long);

        $this->feed(10, 5, 'announcement', 'Exam moved', null);           // live announcement
        $this->feed(11, 5, 'announcement', 'Deleted news', null);         // orphan
        $this->feed(12, 5, 'announcement', 'Old but expired', null);      // expired, still exists
        $this->feed(13, 5, 'announcement', 'Gone too', ['announcement_id' => 99]); // linked: new code's business
        $this->feed(14, 5, 'pool_added', 'Deleted news', null);           // not an announcement
        $this->feed(15, 6, 'announcement', 'Exam moved', null);           // same title, other course: orphan
        $this->feed(16, 5, 'announcement', mb_substr($long, 0, 255), null); // stored cut to 255
        $this->feed(17, 5, 'announcement', 'Deleted with meta', ['other' => 1]); // meta without the link: orphan

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('info')->with('Removed 3 feed entries of deleted announcements.');
        $this->step()->run($output);

        $this->assertSame([10, 12, 13, 14, 16], array_keys($this->tables['learning_feed_items']));
        $this->assertCount(3, $this->tables['learning_course_announcements']);
    }

    public function testASecondRunChangesNothing(): void {
        $this->announcement(1, 5, 'Exam moved');
        $this->feed(10, 5, 'announcement', 'Exam moved', null);
        $this->feed(11, 5, 'announcement', 'Deleted news', null);

        $this->step()->run($this->createMock(IOutput::class));
        $after = $this->tables;

        $output = $this->createMock(IOutput::class);
        $output->expects($this->never())->method('info');
        $this->step()->run($output);
        $this->assertSame($after, $this->tables);
        $this->assertSame([10], array_keys($this->tables['learning_feed_items']));
    }

    public function testNoAnnouncementsAndNoFeedIsANoOp(): void {
        $output = $this->createMock(IOutput::class);
        $output->expects($this->never())->method('info');
        $this->step()->run($output);
        $this->assertSame([], $this->tables['learning_feed_items'] ?? []);
    }
}
