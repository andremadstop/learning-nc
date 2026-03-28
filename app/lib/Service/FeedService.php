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
    public function getCourseFeed(int $courseId, int $limit = 50): array {
        return $this->feedItemMapper->findByCourse($courseId, $limit);
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
     * Delete feed items older than N days.
     *
     * @return int Number of deleted items
     */
    public function cleanupOldItems(int $daysOld = 90): int {
        $threshold = time() - ($daysOld * 86400);
        return $this->feedItemMapper->deleteOlderThan($threshold);
    }
}
