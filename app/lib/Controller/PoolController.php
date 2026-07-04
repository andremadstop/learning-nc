<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class PoolController extends Controller {
    private PoolService $service;
    private ?string $userId;

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
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        string $name,
        ?string $description = null,
        ?string $handbookKey = null,
        ?string $handbookTitle = null,
        ?string $chapterKey = null,
        ?string $chapterTitle = null,
        ?int $chapterOrder = null
    ): DataResponse {
        try {
            if (mb_strlen($name) < 1 || mb_strlen($name) > 200) {
                return new DataResponse(['error' => 'Pool name must be 1-200 characters'], Http::STATUS_BAD_REQUEST);
            }
            if ($description !== null && mb_strlen($description) > 2000) {
                return new DataResponse(['error' => 'Description must be max 2000 characters'], Http::STATUS_BAD_REQUEST);
            }
            $pool = $this->service->create(
                $name,
                $description,
                $this->userId,
                $handbookKey,
                $handbookTitle,
                $chapterKey,
                $chapterTitle,
                $chapterOrder
            );
            return new DataResponse($pool, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to create pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        string $name,
        ?string $description = null,
        ?string $handbookKey = null,
        ?string $handbookTitle = null,
        ?string $chapterKey = null,
        ?string $chapterTitle = null,
        ?int $chapterOrder = null
    ): DataResponse {
        try {
            if (mb_strlen($name) < 1 || mb_strlen($name) > 200) {
                return new DataResponse(['error' => 'Pool name must be 1-200 characters'], Http::STATUS_BAD_REQUEST);
            }
            if ($description !== null && mb_strlen($description) > 2000) {
                return new DataResponse(['error' => 'Description must be max 2000 characters'], Http::STATUS_BAD_REQUEST);
            }
            $pool = $this->service->update(
                $id,
                $name,
                $description,
                $this->userId,
                $handbookKey,
                $handbookTitle,
                $chapterKey,
                $chapterTitle,
                $chapterOrder
            );
            return new DataResponse($pool);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Pool not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function review(int $id, string $reviewStatus, ?string $reviewerId = null): DataResponse {
        try {
            // AUDIT LOW-01: never trust a client-supplied reviewer identity (attestation spoofing);
            // the review is always attributed to the authenticated user. $reviewerId is ignored.
            $reviewer = (string)$this->userId;
            $pool = $this->service->setReviewStatus($id, $reviewStatus, $reviewer, (string)$this->userId);
            return new DataResponse($pool);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update pool review status'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(null, Http::STATUS_NO_CONTENT);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Pool not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
