<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\TrainingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class TrainingController extends Controller {
    private TrainingService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, TrainingService $service, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function start(int $poolId, ?int $limit = null, string $mode = 'training', ?int $timeLimitSeconds = null, ?string $lang = null, ?int $courseId = null, ?string $questionType = null): DataResponse {
        try {
            return new DataResponse($this->service->startSession($poolId, $this->userId, $limit, $mode, $timeLimitSeconds, $lang, $courseId, $questionType), 201);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            // VIDEO-03: the video/material gate is a hard 403, not a generic 400 — the client must
            // distinguish "watch the video first" from a bad request. Subclass catch MUST precede the
            // generic \Exception below (which would otherwise swallow it as a 400).
            return new DataResponse(['error' => $e->getMessage() ?: 'Video or material must be completed first', 'gate' => 'video'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to start training session'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function answer(int $sessionId, int $questionId, ?int $answerId = null, ?array $answerIds = null, ?string $answerText = null, ?array $pbqAnswers = null, ?string $lang = null): DataResponse {
        if ($answerText !== null && mb_strlen($answerText) > 2000) {
            return new DataResponse(['error' => 'Answer text too long (max 2000 chars)'], 400);
        }
        try {
            return new DataResponse($this->service->submitAnswer($sessionId, $questionId, $answerId, $this->userId, $answerIds, $answerText, $pbqAnswers, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to submit answer'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function submitBatch(int $sessionId, array $answers, ?string $lang = null): DataResponse {
        // S3: Hard cap on batch size
        if (count($answers) > 200) {
            return new DataResponse(['error' => 'Batch too large (max 200)'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse($this->service->submitBatch($sessionId, $answers, $this->userId, $lang));
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Exam timed out') {
                return new DataResponse(['error' => 'Exam timed out', 'timed_out' => true], Http::STATUS_CONFLICT);
            }
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to submit answers'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function complete(int $sessionId, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->completeSession($sessionId, $this->userId, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to complete session'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function status(int $sessionId, ?string $lang = null, bool $includeQuestions = false): DataResponse {
        try {
            return new DataResponse($this->service->getSessionStatus($sessionId, $this->userId, $lang, $includeQuestions));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load session status'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function abort(int $sessionId): DataResponse {
        try {
            $this->service->abortSession($sessionId, $this->userId);
            return new DataResponse(['ok' => true]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to abort session'], 400);
        }
    }
}
