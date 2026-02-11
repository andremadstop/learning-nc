<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\NotFoundException;
use OCP\AppFramework\Controller;
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
    public function index(int $poolId): DataResponse {
        return new DataResponse($this->service->findByPool($poolId, $this->userId));
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            return new DataResponse($this->service->find($id, $this->userId));
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Question not found'], 404);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(int $poolId, string $text, ?string $explanation, ?string $difficulty, array $answers): DataResponse {
        try {
            $question = $this->service->create($poolId, $this->userId, $text, $explanation, $difficulty, $answers);
            return new DataResponse($question, 201);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $id, string $text, ?string $explanation, ?string $difficulty, array $answers): DataResponse {
        try {
            return new DataResponse($this->service->update($id, $this->userId, $text, $explanation, $difficulty, $answers));
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Question not found'], 404);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse([], 204);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Question not found'], 404);
        }
    }
}
