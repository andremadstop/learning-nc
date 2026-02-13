<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\PoolShare;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\PoolMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class ShareService {
    private PoolShareMapper $shareMapper;
    private PoolMapper $poolMapper;

    public function __construct(PoolShareMapper $shareMapper, PoolMapper $poolMapper) {
        $this->shareMapper = $shareMapper;
        $this->poolMapper = $poolMapper;
    }

    public function getSharesForPool(int $poolId, string $userId): array {
        $this->poolMapper->find($poolId, $userId);
        return $this->shareMapper->findByPool($poolId);
    }

    public function getSharedWithMe(string $userId): array {
        return $this->shareMapper->findSharedWithUser($userId);
    }

    public function sharePool(int $poolId, string $sharedWith, string $shareType, string $permission, string $userId): PoolShare {
        $this->poolMapper->find($poolId, $userId);

        if (!in_array($shareType, ['user', 'group'])) {
            throw new \InvalidArgumentException('share_type must be user or group');
        }
        if (!in_array($permission, ['read', 'edit'])) {
            throw new \InvalidArgumentException('permission must be read or edit');
        }

        $existing = $this->shareMapper->findByPoolAndUser($poolId, $sharedWith);
        if ($existing !== null) {
            $existing->setPermission($permission);
            return $this->shareMapper->update($existing);
        }

        $share = new PoolShare();
        $share->setPoolId($poolId);
        $share->setSharedWith($sharedWith);
        $share->setShareType($shareType);
        $share->setPermission($permission);
        $share->setCreatedBy($userId);
        return $this->shareMapper->insert($share);
    }

    public function updatePermission(int $poolId, string $sharedWith, string $permission, string $userId): PoolShare {
        $this->poolMapper->find($poolId, $userId);

        if (!in_array($permission, ['read', 'edit'])) {
            throw new \InvalidArgumentException('permission must be read or edit');
        }

        $share = $this->shareMapper->findByPoolAndUser($poolId, $sharedWith);
        if ($share === null) {
            throw new NotFoundException('Share not found');
        }
        $share->setPermission($permission);
        return $this->shareMapper->update($share);
    }

    public function unsharePool(int $poolId, string $sharedWith, string $userId): void {
        $this->poolMapper->find($poolId, $userId);
        $this->shareMapper->deleteByPoolAndUser($poolId, $sharedWith);
    }

    public function hasAccess(int $poolId, string $userId): bool {
        $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
        return $share !== null;
    }

    public function canEdit(int $poolId, string $userId): bool {
        $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
        return $share !== null && $share->getPermission() === 'edit';
    }
}
