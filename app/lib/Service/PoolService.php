<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use Exception;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class PoolService {
    private $mapper;

    public function __construct(PoolMapper $mapper) {
        $this->mapper = $mapper;
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    public function find(int $id, string $userId): Pool {
        try {
            return $this->mapper->find($id, $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
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
            $this->mapper->deleteById($id, $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Pool not found');
        }
    }
}

class NotFoundException extends Exception {}
