<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\FeedItem;
use OCA\Learning\Db\FeedItemMapper;
use OCA\Learning\Db\CourseMemberMapper;

class FeedService {
    private FeedItemMapper $feedItemMapper;
    private CourseMemberMapper $courseMemberMapper;

    public function __construct(
        FeedItemMapper $feedItemMapper,
        CourseMemberMapper $courseMemberMapper
    ) {
        $this->feedItemMapper = $feedItemMapper;
        $this->courseMemberMapper = $courseMemberMapper;
    }

    /**
     * Get aggregated feed for a user across all enrolled courses.
     *
     * @return FeedItem[]
     */
    public function getUserFeed(string $userId, int $limit = 50, int $offset = 0): array {
        $memberships = $this->courseMemberMapper->findByUser($userId);
        $courseIds = array_map(fn($m) => $m->getCourseId(), $memberships);

        if (empty($courseIds)) {
            return [];
        }

        return $this->feedItemMapper->findByUserCourses($courseIds, $limit, $offset);
    }

    /**
     * Get feed items for a single course.
     *
     * @return FeedItem[]
     */
    public function getCourseFeed(int $courseId, int $limit = 50, int $offset = 0): array {
        return $this->feedItemMapper->findByCourse($courseId, $limit, $offset);
    }

    /**
     * Create an auto-generated feed item.
     */
    public function createAutoItem(
        int $courseId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actorId = null,
        ?array $meta = null
    ): FeedItem {
        $item = new FeedItem();
        $item->setCourseId($courseId);
        $item->setType($type);
        $item->setTitle(mb_substr($title, 0, 255));
        $item->setBody($body);
        $item->setActorId($actorId);
        $item->setMeta($meta !== null ? json_encode($meta) : null);
        $item->setCreatedAt(time());

        /** @var FeedItem $inserted */
        $inserted = $this->feedItemMapper->insert($item);
        return $inserted;
    }

    /**
     * Delete the feed copy of a course announcement (GitHub #40).
     *
     * Since GitHub #40 the feed row carries meta.announcement_id. Rows written before that have no
     * meta; they are matched by title and creation time, which the announcement insert and the
     * feed insert take within the same or the next second. A row whose meta names another
     * announcement is never touched.
     *
     * @return int Number of deleted feed rows
     */
    public function deleteAnnouncementItems(int $courseId, int $announcementId, ?string $title, ?int $createdAt): int {
        $ids = [];
        foreach ($this->feedItemMapper->findByCourseAndType($courseId, 'announcement') as $item) {
            if (self::belongsToAnnouncement($item, $announcementId, $title, $createdAt)) {
                $ids[] = (int)$item->getId();
            }
        }
        return $this->feedItemMapper->deleteByIds($courseId, $ids);
    }

    /**
     * Give the feed copy of an edited announcement the new title and text (GitHub #40).
     *
     * The rows are found the same way as on delete, with the title and creation time the
     * announcement had before the edit. Each matched row keeps its other meta and gets
     * announcement_id (a legacy row gets its link this way) and edited_at.
     *
     * @return int Number of updated feed rows
     */
    public function updateAnnouncementItems(
        int $courseId,
        int $announcementId,
        ?string $oldTitle,
        ?int $createdAt,
        string $title,
        string $body,
        int $editedAt
    ): int {
        $updated = 0;
        foreach ($this->feedItemMapper->findByCourseAndType($courseId, 'announcement') as $item) {
            if (!self::belongsToAnnouncement($item, $announcementId, $oldTitle, $createdAt)) {
                continue;
            }
            $meta = $item->getMeta();
            $decoded = $meta !== null && $meta !== '' ? json_decode($meta, true) : null;
            $decoded = is_array($decoded) ? $decoded : [];
            $decoded['announcement_id'] = $announcementId;
            $decoded['edited_at'] = $editedAt;

            $item->setTitle(mb_substr($title, 0, 255));
            $item->setBody($body);
            $item->setMeta(json_encode($decoded));
            $this->feedItemMapper->update($item);
            $updated++;
        }
        return $updated;
    }

    /**
     * Whether a feed row is the copy of the given announcement.
     */
    public static function belongsToAnnouncement(FeedItem $item, int $announcementId, ?string $title, ?int $createdAt): bool {
        $meta = $item->getMeta();
        $decoded = $meta !== null && $meta !== '' ? json_decode($meta, true) : null;
        if (is_array($decoded) && array_key_exists('announcement_id', $decoded)) {
            return (int)$decoded['announcement_id'] === $announcementId;
        }
        // Legacy row without the link: same title (stored cut to 255 characters) and created
        // within two seconds of the announcement.
        if ($title === null || $createdAt === null) {
            return false;
        }
        return $item->getTitle() === mb_substr($title, 0, 255)
            && abs((int)$item->getCreatedAt() - $createdAt) <= 2;
    }

    /**
     * Delete feed items older than N days.
     *
     * @return int Number of deleted items
     */
    public function cleanupOldItems(int $daysOld = 90): int {
        $threshold = time() - ($daysOld * 86400);
        return $this->feedItemMapper->deleteOlderThan($threshold);
    }
}
