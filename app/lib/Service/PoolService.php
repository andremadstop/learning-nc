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
            return [
                'id' => (int)$row['id'],
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'permission' => $row['permission'],
                'shared_by' => $row['shared_by'],
                'is_shared' => true,
            ];
        }, $rows);
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
                $qb = $this->db->getQueryBuilder();
                $qb->select('*')
                   ->from('learning_pools')
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
                $result = $qb->execute();
                $row = $result->fetch();
                $result->closeCursor();
                if ($row) {
                    return [
                        'id' => (int)$row['id'],
                        'user_id' => $row['user_id'],
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                                'permission' => $share->getPermission(),
                        'is_shared' => true,
                    ];
                }
            }
            throw new NotFoundException('Pool not found');
        }
    }

    public function create(string $name, ?string $description, string $userId): Pool {
        $pool = new Pool();
        $pool->setName($name);
        $pool->setDescription($description);
        $pool->setUserId($userId);
        return $this->mapper->createOrUpdate($pool);
    }

    public function update(int $id, string $name, ?string $description, string $userId): Pool {
        try {
            $pool = $this->mapper->find($id, $userId);
            $pool->setName($name);
            $pool->setDescription($description);
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
}

class NotFoundException extends Exception {}
