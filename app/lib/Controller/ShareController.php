<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\ShareService;
use OCA\Learning\Service\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ShareController extends Controller {
    private ShareService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, ShareService $service, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function index(int $poolId): DataResponse {
        try {
            return new DataResponse($this->service->getSharesForPool($poolId, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function sharedWithMe(): DataResponse {
        return new DataResponse($this->service->getSharedWithMe($this->userId));
    }

    /**
     * @NoAdminRequired
     */
    public function create(int $poolId, string $sharedWith, string $shareType = 'user', string $permission = 'read'): DataResponse {
        try {
            $share = $this->service->sharePool($poolId, $sharedWith, $shareType, $permission, $this->userId);
            return new DataResponse($share, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'Invalid share parameters'], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $poolId, string $sharedWith, string $permission): DataResponse {
        try {
            $share = $this->service->updatePermission($poolId, $sharedWith, $permission, $this->userId);
            return new DataResponse($share);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Share not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'Invalid permission value'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $poolId, string $sharedWith): DataResponse {
        try {
            $this->service->unsharePool($poolId, $sharedWith, $this->userId);
            return new DataResponse([], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Share not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }
}
