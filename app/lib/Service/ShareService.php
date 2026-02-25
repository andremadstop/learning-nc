<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\PoolShare;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Service\BadgeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICacheFactory;
use OCP\IUserManager;

class ShareService {
    private PoolShareMapper $shareMapper;
    private PoolMapper $poolMapper;
    private IUserManager $userManager;
    private BadgeService $badgeService;
    private ICacheFactory $cacheFactory;

    public function __construct(PoolShareMapper $shareMapper, PoolMapper $poolMapper, IUserManager $userManager, BadgeService $badgeService, ICacheFactory $cacheFactory) {
        $this->shareMapper = $shareMapper;
        $this->poolMapper = $poolMapper;
        $this->userManager = $userManager;
        $this->badgeService = $badgeService;
        $this->cacheFactory = $cacheFactory;
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

        // Only 'user' shares are enforced in access checks; reject 'group' until implemented
        if ($shareType !== 'user') {
            throw new \InvalidArgumentException('Only user shares are currently supported');
        }
        if (!in_array($permission, ['read', 'edit'])) {
            throw new \InvalidArgumentException('permission must be read or edit');
        }

        // S4: Validate that the target user actually exists
        if (!$this->userManager->userExists($sharedWith)) {
            throw new \InvalidArgumentException('User does not exist');
        }

        // Prevent sharing with yourself
        if ($sharedWith === $userId) {
            throw new \InvalidArgumentException('Cannot share with yourself');
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
        $result = $this->shareMapper->insert($share);

        // Badge check for sharing + cache invalidation
        $this->badgeService->checkAndAward($userId, 'share_created', []);
        $this->cacheFactory->createDistributed('learning')->remove('user_state_' . $userId);

        return $result;
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
