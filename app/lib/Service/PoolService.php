<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class PoolService {
    private PoolMapper $mapper;
    private PoolShareMapper $shareMapper;
    private IDBConnection $db;
    private ?QuestionMediaStorage $mediaStorage;

    /**
     * @param QuestionMediaStorage|null $mediaStorage removes the pool's question media on delete
     *        (GitHub #66); null only in tests that construct the service by hand
     */
    public function __construct(PoolMapper $mapper, PoolShareMapper $shareMapper, IDBConnection $db, ?QuestionMediaStorage $mediaStorage = null) {
        $this->mapper = $mapper;
        $this->shareMapper = $shareMapper;
        $this->db = $db;
        $this->mediaStorage = $mediaStorage;
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    public function findSharedWithMe(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.*', 's.permission', 's.shared_with', 's.created_by as shared_by')
           ->from('learning_pools', 'p')
           ->innerJoin('p', 'learning_pool_shares', 's', 'p.id = s.pool_id')
           ->where($qb->expr()->eq('s.shared_with', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('s.share_type', $qb->createNamedParameter('user')))
           ->orderBy('p.name', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(function ($row) {
            return array_merge($this->serializePoolRow($row), [
                'permission' => $row['permission'],
                'shared_by' => $row['shared_by'],
                'is_shared' => true,
            ]);
        }, $rows);
    }

    /**
     * GitHub #69: the pools of every course the user leads, each pool once with the titles of
     * those courses. "Leads" is the rule of CourseService::findInstructedCourses() and
     * isInstructorOfCourse(): the course's owner (courses.instructor_id) or a member with role
     * 'instructor' (co-instructor). A learner of a course gets nothing here, and neither does a
     * pool of a course the user does not lead, also when the pool owner is a colleague.
     *
     * The permission per pool follows findByIdWithShareAccess(): owner, else the user's share,
     * else 'read' (course instructors may read the pools of their courses). is_shared is true
     * for every pool the user does not own, so the pool window offers Manage only to editors.
     *
     * @return list<array<string, mixed>>
     */
    public function findInMyCourses(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('p.*')
           ->selectAlias('c.id', 'lnc_course_id')
           ->selectAlias('c.title', 'lnc_course_title')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->innerJoin('cp', 'learning_pools', 'p', $expr->eq('cp.pool_id', 'p.id'))
           ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
               $expr->eq('cm.course_id', 'c.id'),
               $expr->eq('cm.user_id', $qb->createNamedParameter($userId)),
               $expr->eq('cm.role', $qb->createNamedParameter('instructor'))
           ))
           ->where($expr->orX(
               $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
               $expr->isNotNull('cm.id')
           ))
           ->orderBy('p.name', 'ASC')
           ->addOrderBy('p.id', 'ASC')
           ->addOrderBy('c.title', 'ASC');
        $result = $qb->executeQuery();
        $byPool = [];
        while ($row = $result->fetch()) {
            $poolId = (int)$row['id'];
            if (!isset($byPool[$poolId])) {
                $byPool[$poolId] = ['row' => $row, 'courses' => []];
            }
            $courseId = (int)$row['lnc_course_id'];
            $byPool[$poolId]['courses'][$courseId] = ['id' => $courseId, 'title' => (string)$row['lnc_course_title']];
        }
        $result->closeCursor();
        if ($byPool === []) {
            return [];
        }

        $shares = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id', 'permission')
           ->from('learning_pool_shares')
           ->where($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
           ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter(array_keys($byPool), IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $shares[(int)$row['pool_id']] = (string)$row['permission'];
        }
        $result->closeCursor();

        $pools = [];
        foreach ($byPool as $poolId => $entry) {
            $owned = (string)$entry['row']['user_id'] === $userId;
            $permission = $owned ? 'owner' : (($shares[$poolId] ?? '') === 'edit' ? 'edit' : 'read');
            $pools[] = array_merge($this->serializePoolRow($entry['row']), [
                'permission' => $permission,
                'is_shared' => !$owned,
                'courses' => array_values($entry['courses']),
            ]);
        }
        return $pools;
    }

    private function serializePoolRow(array $row): array {
        return [
            'id' => (int)$row['id'],
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'handbook_key' => $row['handbook_key'] ?? null,
            'handbook_title' => $row['handbook_title'] ?? null,
            'chapter_key' => $row['chapter_key'] ?? null,
            'chapter_title' => $row['chapter_title'] ?? null,
            'chapter_order' => isset($row['chapter_order']) ? (int)$row['chapter_order'] : null,
        ];
    }

    private function normalizeOptional(?string $value, int $maxLength): ?string {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Field exceeds maximum length');
        }

        return $value;
    }

    private function normalizeChapterOrder(?int $chapterOrder): ?int {
        if ($chapterOrder === null) {
            return null;
        }
        if ($chapterOrder < 1 || $chapterOrder > 9999) {
            throw new \InvalidArgumentException('Chapter order must be between 1 and 9999');
        }
        return $chapterOrder;
    }

    private function applyPoolMetadata(
        Pool $pool,
        ?string $handbookKey,
        ?string $handbookTitle,
        ?string $chapterKey,
        ?string $chapterTitle,
        ?int $chapterOrder
    ): void {
        $pool->setHandbookKey($this->normalizeOptional($handbookKey, 64));
        $pool->setHandbookTitle($this->normalizeOptional($handbookTitle, 255));
        $pool->setChapterKey($this->normalizeOptional($chapterKey, 64));
        $pool->setChapterTitle($this->normalizeOptional($chapterTitle, 255));
        $pool->setChapterOrder($this->normalizeChapterOrder($chapterOrder));
    }

    /**
     * The metadata checks of create() without writing anything: throws the same
     * \InvalidArgumentException create() would throw for these handbook and chapter fields.
     * The pool import (PoolBulkImportService) runs it before its dry-run branch, so the preview
     * refuses the same pool the real import would roll back.
     */
    public function validateForImport(
        ?string $handbookKey,
        ?string $handbookTitle,
        ?string $chapterKey,
        ?string $chapterTitle,
        ?int $chapterOrder
    ): void {
        $this->applyPoolMetadata(new Pool(), $handbookKey, $handbookTitle, $chapterKey, $chapterTitle, $chapterOrder);
    }

    private function findPoolRow(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_pools')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ?: null;
    }

    private function hasCoursePoolAccess(int $poolId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('cp.id')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
               $expr->eq('cm.course_id', 'c.id'),
               $expr->eq('cm.user_id', $qb->createNamedParameter($userId))
           ))
           ->where($expr->eq('cp.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($expr->orX(
               $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
               $expr->isNotNull('cm.id')
           ))
           ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }

    private function canEditPool(int $poolId, string $userId): bool {
        try {
            $this->mapper->find($poolId, $userId);
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
            return $share !== null && $share->getPermission() === 'edit';
        }
    }

    public function find(int $id, string $userId): Pool {
        try {
            return $this->mapper->find($id, $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Pool not found');
        }
    }

    public function findByIdWithShareAccess(int $id, string $userId): array {
        // Try own pool first
        try {
            $pool = $this->mapper->find($id, $userId);
            return array_merge($pool->jsonSerialize(), ['permission' => 'owner']);
        } catch (DoesNotExistException $e) {
            // Check if shared
            $share = $this->shareMapper->findByPoolAndUser($id, $userId);
            if ($share !== null) {
                $row = $this->findPoolRow($id);
                if ($row) {
                    return array_merge($this->serializePoolRow($row), [
                        'permission' => $share->getPermission(),
                        'is_shared' => true,
                    ]);
                }
            }

            // Course members and course instructors can read assigned pools
            if ($this->hasCoursePoolAccess($id, $userId)) {
                $row = $this->findPoolRow($id);
                if ($row) {
                    return array_merge($this->serializePoolRow($row), [
                        'permission' => 'read',
                        'is_shared' => true,
                    ]);
                }
            }

            throw new NotFoundException('Pool not found');
        }
    }

    public function create(
        string $name,
        ?string $description,
        string $userId,
        ?string $handbookKey = null,
        ?string $handbookTitle = null,
        ?string $chapterKey = null,
        ?string $chapterTitle = null,
        ?int $chapterOrder = null
    ): Pool {
        $pool = new Pool();
        $pool->setName($name);
        $pool->setDescription($description);
        $pool->setUserId($userId);
        $this->applyPoolMetadata($pool, $handbookKey, $handbookTitle, $chapterKey, $chapterTitle, $chapterOrder);
        if (!$pool->getReviewStatus()) {
            $pool->setReviewStatus('published');
        }
        return $this->mapper->createOrUpdate($pool);
    }

    public function update(
        int $id,
        string $name,
        ?string $description,
        string $userId,
        ?string $handbookKey = null,
        ?string $handbookTitle = null,
        ?string $chapterKey = null,
        ?string $chapterTitle = null,
        ?int $chapterOrder = null
    ): Pool {
        try {
            $pool = $this->mapper->find($id, $userId);
            $pool->setName($name);
            $pool->setDescription($description);
            $this->applyPoolMetadata($pool, $handbookKey, $handbookTitle, $chapterKey, $chapterTitle, $chapterOrder);
            return $this->mapper->createOrUpdate($pool);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Pool not found');
        }
    }

    public function delete(int $id, string $userId): void {
        try {
            // Verify ownership BEFORE deleting related data
            $pool = $this->mapper->find($id, $userId);

            // Clean up orphan course_pools
            $qb = $this->db->getQueryBuilder();
            $qb->delete('learning_course_pools')
               ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($id)));
            $qb->executeStatement();

            $this->mapper->delete($pool);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Pool not found');
        }
        // GitHub #66: the questions (and their media rows) went with the pool by cascade; the
        // files in the app data go here. A failure there is logged and left to the daily sweep.
        $this->mediaStorage?->deleteForPool($id);
    }

    private function normalizeReviewStatus(string $status): string {
        $allowed = ['draft', 'reviewed', 'published'];
        return in_array($status, $allowed, true) ? $status : 'draft';
    }

    public function setReviewStatus(int $id, string $reviewStatus, string $reviewerId, string $userId): Pool {
        if (!$this->canEditPool($id, $userId)) {
            throw new NotFoundException('Pool not found');
        }
        $pool = $this->mapper->findById($id);
        $pool->setReviewStatus($this->normalizeReviewStatus($reviewStatus));
        $pool->setReviewerId($reviewerId);
        $pool->setReviewedAt(time());
        return $this->mapper->createOrUpdate($pool);
    }
}
