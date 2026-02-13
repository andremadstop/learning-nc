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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(string $name, ?string $description = null): DataResponse {
        try {
            $pool = $this->service->create($name, $description, $this->userId);
            return new DataResponse($pool, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $id, string $name, ?string $description = null): DataResponse {
        try {
            $pool = $this->service->update($id, $name, $description, $this->userId);
            return new DataResponse($pool);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }
}
