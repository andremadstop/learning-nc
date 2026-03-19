<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
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
    #[UserRateLimit(limit: 60, period: 60)]
    public function create(
        int $poolId,
        string $text,
        ?string $explanation,
        ?string $difficulty,
        array $answers,
        ?string $questionType = null,
        ?string $pbqSubtype = null,
        ?array $pbqConfig = null,
        ?string $instructorNote = null,
        bool $noteVisible = false,
        ?string $handbookKey = null,
        ?string $handbookTitle = null,
        ?string $examKey = null,
        ?string $chapterKey = null,
        ?string $chapterTitle = null,
        ?int $chapterOrder = null
    ): DataResponse {
        try {
            $question = $this->service->create(
                $poolId,
                $this->userId,
                $text,
                $explanation,
                $difficulty,
                $answers,
                $questionType,
                pbqSubtype: $pbqSubtype,
                pbqConfig: $pbqConfig ? json_encode($pbqConfig) : null,
                instructorNote: $instructorNote,
                noteVisible: $noteVisible,
                handbookKey: $handbookKey,
                handbookTitle: $handbookTitle,
                examKey: $examKey,
                chapterKey: $chapterKey,
                chapterTitle: $chapterTitle,
                chapterOrder: $chapterOrder
            );
            return new DataResponse($question, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to create question'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(
        int $id,
        string $text,
        ?string $explanation,
        ?string $difficulty,
        array $answers,
        ?string $questionType = null,
        ?string $pbqSubtype = null,
        ?array $pbqConfig = null,
        ?string $instructorNote = null,
        bool $noteVisible = false,
        ?string $handbookKey = null,
        ?string $handbookTitle = null,
        ?string $examKey = null,
        ?string $chapterKey = null,
        ?string $chapterTitle = null,
        ?int $chapterOrder = null
    ): DataResponse {
        try {
            return new DataResponse($this->service->update(
                $id,
                $this->userId,
                $text,
                $explanation,
                $difficulty,
                $answers,
                $questionType,
                pbqSubtype: $pbqSubtype,
                pbqConfig: $pbqConfig ? json_encode($pbqConfig) : null,
                instructorNote: $instructorNote,
                noteVisible: $noteVisible,
                handbookKey: $handbookKey,
                handbookTitle: $handbookTitle,
                examKey: $examKey,
                chapterKey: $chapterKey,
                chapterTitle: $chapterTitle,
                chapterOrder: $chapterOrder
            ));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update question'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function review(int $id, string $reviewStatus, ?string $reviewerId = null): DataResponse {
        try {
            $reviewer = $reviewerId ?: (string)$this->userId;
            return new DataResponse($this->service->setReviewStatus($id, $reviewStatus, $reviewer, (string)$this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update review status'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
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
