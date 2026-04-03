<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StarterPoolService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class StarterPoolController extends Controller {
    private ?string $userId;
    private RoleService $roleService;
    private StarterPoolService $starterPoolService;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        RoleService $roleService,
        StarterPoolService $starterPoolService
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->roleService = $roleService;
        $this->starterPoolService = $starterPoolService;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function index(): DataResponse {
        if (!$this->canManageStarterPools()) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }

        return new DataResponse([
            'pools' => $this->starterPoolService->listAvailablePools(),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function import(string $id): DataResponse {
        if (!$this->canManageStarterPools()) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }

        try {
            return new DataResponse($this->starterPoolService->importStarterPool($id, (string)$this->userId), Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Starter pool import failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function canManageStarterPools(): bool {
        return $this->userId !== null && $this->roleService->isInstructor((string)$this->userId);
    }
}
