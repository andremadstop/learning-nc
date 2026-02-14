<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\NotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class QuestionController extends Controller {
    private $service;
    private $userId;

    public function __construct($appName, IRequest $request, QuestionService $service, $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function index(int $poolId, int $limit = 0, int $offset = 0): DataResponse {
        // If no limit specified, return all (backwards compatible)
        if ($limit <= 0) {
            return new DataResponse($this->service->findByPool($poolId, $this->userId));
        }
        // FIX #10: Paginated response for large pools
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);
        return new DataResponse($this->service->findByPoolPaged($poolId, $this->userId, $limit, $offset));
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            return new DataResponse($this->service->find($id, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Question not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(int $poolId, string $text, ?string $explanation, ?string $difficulty, array $answers): DataResponse {
        try {
            $question = $this->service->create($poolId, $this->userId, $text, $explanation, $difficulty, $answers);
            return new DataResponse($question, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to create question'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $id, string $text, ?string $explanation, ?string $difficulty, array $answers): DataResponse {
        try {
            return new DataResponse($this->service->update($id, $this->userId, $text, $explanation, $difficulty, $answers));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to update question'], Http::STATUS_BAD_REQUEST);
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
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Question not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function search(string $query): DataResponse {
        if (strlen($query) < 2) {
            return new DataResponse(['error' => 'Query must be at least 2 characters'], Http::STATUS_BAD_REQUEST);
        }
        return new DataResponse($this->service->search($query, $this->userId));
    }
}
