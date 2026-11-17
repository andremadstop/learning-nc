<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class QuestionController extends Controller {
    private QuestionService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, QuestionService $service, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    #[NoAdminRequired]
    public function index(int $poolId, int $limit = 0, int $offset = 0): DataResponse {
        // A deleted, unshared or foreign pool is a 404 like in every other action here, not a
        // 500 with a logged stack trace (audit #64).
        try {
            // If no limit specified, return all (backwards compatible)
            if ($limit <= 0) {
                return new DataResponse($this->service->findByPool($poolId, $this->userId));
            }
            $limit = max(1, min($limit, 200));
            $offset = max(0, $offset);
            return new DataResponse($this->service->findByPoolPaged($poolId, $this->userId, $limit, $offset));
        } catch (\OCP\DB\Exception $e) {
            // A database failure is no missing pool: keep it a 500 and in the log.
            throw $e;
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse {
        try {
            return new DataResponse($this->service->find($id, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Question not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Grades one answer for the bot modes of Duel and Gameshow (learners no longer receive
     * answers[].is_correct). 404 without read access, 403 during a graded exam on the pool,
     * 400 for an answer of another question. Rate-limited: it reveals the correct answer.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function check(int $id, int $answerId): DataResponse {
        try {
            return new DataResponse($this->service->checkAnswer($id, $answerId, (string)$this->userId));
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Question not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'Not available during active exam'], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'Invalid answer'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Rich feedback fields of the request (GitHub #66): hint, backgroundFact, keyTakeaway (text) and
     * optionFeedback ([{position, why, misconception}]). Only the keys the request carries, so an
     * API client that predates the fields keeps what is stored; null or '' clears a field.
     *
     * @return array<string, mixed>
     */
    private function richFeedbackFromRequest(): array {
        $params = $this->request->getParams();
        $rich = [];
        foreach (['hint' => 'hint', 'backgroundFact' => 'background_fact', 'keyTakeaway' => 'key_takeaway', 'optionFeedback' => 'option_feedback'] as $param => $field) {
            if (array_key_exists($param, $params)) {
                $rich[$field] = $params[$param];
            }
        }
        return $rich;
    }

    #[NoAdminRequired]
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
        ?int $chapterOrder = null,
        ?string $scenario = null
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
                chapterOrder: $chapterOrder,
                scenario: $scenario,
                richFeedback: $this->richFeedbackFromRequest()
            );
            return new DataResponse($question, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to create question'], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
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
        ?int $chapterOrder = null,
        ?string $scenario = null
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
                chapterOrder: $chapterOrder,
                // Absent key keeps the stored scenario; an explicit null or '' clears it.
                scenario: array_key_exists('scenario', $this->request->getParams()) ? ($scenario ?? '') : null,
                richFeedback: $this->richFeedbackFromRequest()
            ));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update question'], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function review(int $id, string $reviewStatus, ?string $reviewerId = null): DataResponse {
        try {
            // AUDIT LOW-01: never trust a client-supplied reviewer identity (attestation spoofing);
            // always attribute the review to the authenticated user. $reviewerId is ignored.
            $reviewer = (string)$this->userId;
            return new DataResponse($this->service->setReviewStatus($id, $reviewStatus, $reviewer, (string)$this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update review status'], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(null, Http::STATUS_NO_CONTENT);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Question not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function search(string $query, ?int $poolId = null): DataResponse {
        if (strlen($query) < 2) {
            return new DataResponse(['error' => 'Query must be at least 2 characters'], Http::STATUS_BAD_REQUEST);
        }
        $poolId = $poolId !== null && $poolId > 0 ? $poolId : null;
        return new DataResponse($this->service->search($query, $this->userId, 50, $poolId));
    }
}
