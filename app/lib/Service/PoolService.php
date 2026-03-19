<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use Exception;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;

class PoolService {
    private $mapper;
    private $shareMapper;
    private $db;

    public function __construct(PoolMapper $mapper, PoolShareMapper $shareMapper, IDBConnection $db) {
        $this->mapper = $mapper;
        $this->shareMapper = $shareMapper;
        $this->db = $db;
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

        $result = $qb->execute();
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

    private function findPoolRow(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_pools')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $result = $qb->execute();
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
        $result = $qb->execute();
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
            $qb->execute();

            $this->mapper->delete($pool);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Pool not found');
        }
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

class NotFoundException extends Exception {}
