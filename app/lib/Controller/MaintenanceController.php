<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\MaintenanceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class MaintenanceController extends Controller {
    private MaintenanceService $service;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        MaintenanceService $service,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * Toggle maintenance mode for a course.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function toggle(int $courseId, bool $enabled = true): DataResponse {
        try {
            $result = $this->service->toggleMaintenance($courseId, $enabled, $this->userId);
            return new DataResponse(['maintenance_mode' => $result]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to toggle maintenance mode'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get the maintenance review queue.
     *
     * @NoAdminRequired
     */
    public function queue(int $limit = 10, ?string $lang = null): DataResponse {
        try {
            $items = $this->service->getMaintenanceQueue($this->userId, $limit, $lang);
            return new DataResponse($items);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load maintenance queue'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get maintenance stats for the dashboard widget.
     *
     * @NoAdminRequired
     */
    public function stats(): DataResponse {
        try {
            return new DataResponse($this->service->getMaintenanceStats($this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load maintenance stats'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
