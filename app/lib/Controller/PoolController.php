<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class PoolController extends Controller {
    private $service;
    private $userId;

    public function __construct(string $appName, IRequest $request, PoolService $service, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        $own = $this->service->findAll($this->userId);
        $shared = $this->service->findSharedWithMe($this->userId);
        return new DataResponse([
            'own' => $own,
            'shared' => $shared,
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            return new DataResponse($this->service->findByIdWithShareAccess($id, $this->userId));
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Pool not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(string $name, ?string $description = null): DataResponse {
        try {
            // FIX #11 MEDIUM: Validate pool name length
            if (mb_strlen($name) < 1 || mb_strlen($name) > 200) {
                return new DataResponse(['error' => 'Pool name must be 1-200 characters'], Http::STATUS_BAD_REQUEST);
            }
            if ($description !== null && mb_strlen($description) > 2000) {
                return new DataResponse(['error' => 'Description must be max 2000 characters'], Http::STATUS_BAD_REQUEST);
            }
            $pool = $this->service->create($name, $description, $this->userId);
            return new DataResponse($pool, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to create pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $id, string $name, ?string $description = null): DataResponse {
        try {
            // FIX #11 MEDIUM: Validate pool name length
            if (mb_strlen($name) < 1 || mb_strlen($name) > 200) {
                return new DataResponse(['error' => 'Pool name must be 1-200 characters'], Http::STATUS_BAD_REQUEST);
            }
            if ($description !== null && mb_strlen($description) > 2000) {
                return new DataResponse(['error' => 'Description must be max 2000 characters'], Http::STATUS_BAD_REQUEST);
            }
            $pool = $this->service->update($id, $name, $description, $this->userId);
            return new DataResponse($pool);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Pool not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to update pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            // FIX #12 LOW: 204 should not have a body
            return new DataResponse(null, Http::STATUS_NO_CONTENT);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Pool not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
