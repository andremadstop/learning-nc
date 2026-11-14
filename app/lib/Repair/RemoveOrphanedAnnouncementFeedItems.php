<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Remove the feed copies of announcements that were deleted before GitHub #40.
 *
 * Until #40 the feed row of an announcement carried no link to it, and deleting the announcement
 * left the feed row visible to every course member (course feed and dashboard feed). The app now
 * stores meta.announcement_id and deletes both; this step clears the copies left behind.
 *
 * A feed row is deleted only when all of this holds:
 * - its type is 'announcement' (pool entries are never touched);
 * - its meta has no announcement_id (a linked row belongs to the new code, which deletes it itself);
 * - no announcement of the same course, expired ones included, has the same title (both cut to
 *   255 characters as on insert).
 * The creation time is deliberately not compared: before #40 a title could not change, while a
 * time window could, on a slow request, take the copy of an announcement that still exists.
 * A legacy copy that shares its title with a live announcement therefore stays.
 *
 * Comparison happens in PHP (no JSON or case rules in SQL, which differ per engine); rows are
 * deleted by primary key. Running the step again changes nothing. Post-migration only: a fresh
 * install has no legacy rows.
 */
class RemoveOrphanedAnnouncementFeedItems implements IRepairStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function getName(): string {
        return 'Remove feed entries of deleted course announcements';
    }

    public function run(IOutput $output): void {
        $titles = [];
        $select = $this->db->getQueryBuilder();
        $select->select('course_id', 'title')->from('learning_course_announcements');
        $result = $select->executeQuery();
        while (($row = $result->fetch()) !== false) {
            $titles[(int)$row['course_id']][mb_substr((string)$row['title'], 0, 255)] = true;
        }
        $result->closeCursor();

        $feed = $this->db->getQueryBuilder();
        $feed->select('id', 'course_id', 'title', 'meta')
            ->from('learning_feed_items')
            ->where($feed->expr()->eq('type', $feed->createNamedParameter('announcement')));
        $result = $feed->executeQuery();
        $orphans = [];
        while (($row = $result->fetch()) !== false) {
            if (self::isOrphan($row, $titles)) {
                $orphans[] = (int)$row['id'];
            }
        }
        $result->closeCursor();

        foreach ($orphans as $id) {
            $delete = $this->db->getQueryBuilder();
            $delete->delete('learning_feed_items')
                ->where($delete->expr()->eq('id', $delete->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $delete->executeStatement();
        }

        if ($orphans !== []) {
            $output->info(sprintf('Removed %d feed entr%s of deleted announcements.', count($orphans), count($orphans) === 1 ? 'y' : 'ies'));
        }
    }

    /**
     * @param array<string, mixed> $row feed row (id, course_id, title, meta)
     * @param array<int, array<string, true>> $titles announcement titles per course
     */
    private static function isOrphan(array $row, array $titles): bool {
        $meta = $row['meta'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded) && array_key_exists('announcement_id', $decoded)) {
                return false;
            }
        }
        $title = mb_substr((string)$row['title'], 0, 255);
        return !isset($titles[(int)$row['course_id']][$title]);
    }
}
