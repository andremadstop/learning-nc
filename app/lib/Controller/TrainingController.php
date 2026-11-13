<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertificationTrigger;
use OCA\Learning\Service\TrainingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TrainingController extends Controller {
    private TrainingService $service;
    private CertificationTrigger $certificationTrigger;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(string $appName, IRequest $request, TrainingService $service, CertificationTrigger $certificationTrigger, ?string $userId, LoggerInterface $logger) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->certificationTrigger = $certificationTrigger;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * examKind='practice' (GitHub #35) starts a pool practice run of a course instead: it never
     * counts towards a certificate, and the course's practice rules decide length, time and pass
     * mark — limit, timeLimitSeconds and questionType are ignored. Any other examKind is refused
     * rather than silently starting a counting exam.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function start(int $poolId, ?int $limit = null, string $mode = 'training', ?int $timeLimitSeconds = null, ?string $lang = null, ?int $courseId = null, ?string $questionType = null, ?string $examKind = null): DataResponse {
        if ($examKind !== null) {
            return $this->startPoolPractice($poolId, $examKind, $courseId, $lang);
        }
        try {
            return new DataResponse($this->service->startSession($poolId, $this->userId, $limit, $mode, $timeLimitSeconds, $lang, $courseId, $questionType), 201);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            // VIDEO-03: the video/material gate is a hard 403, not a generic 400 — the client must
            // distinguish "watch the video first" from a bad request. Subclass catch MUST precede the
            // generic \Exception below (which would otherwise swallow it as a 400).
            return new DataResponse(['error' => $e->getMessage() ?: 'Video or material must be completed first', 'gate' => 'video'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\DB\Exception $e) {
            // A database error is no refusal: its driver message (table, column, SQL) stays in the log.
            $this->logger->error('Starting a session failed', ['exception' => $e, 'poolId' => $poolId, 'mode' => $mode]);
            return new DataResponse(['error' => 'Failed to start training session'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to start training session'], 400);
        }
    }

    private function startPoolPractice(int $poolId, string $examKind, ?int $courseId, ?string $lang): DataResponse {
        if ($examKind !== TrainingService::EXAM_KIND_PRACTICE) {
            return new DataResponse(['error' => 'Unknown exam kind'], Http::STATUS_BAD_REQUEST);
        }
        if ($courseId === null) {
            return new DataResponse(['error' => 'A practice run needs a course'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse($this->service->startPoolPracticeExam($poolId, $courseId, $this->userId, $lang), 201);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Video or material must be completed first', 'gate' => 'video'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\DB\Exception $e) {
            // A database error is no refusal: its driver message (table, column, SQL) stays in the log.
            $this->logger->error('Starting a pool practice run failed', ['exception' => $e, 'poolId' => $poolId, 'courseId' => $courseId]);
            return new DataResponse(['error' => 'Failed to start practice exam'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to start practice exam'], 400);
        }
    }

    /**
     * Start or resume the practice exam an instructor configured for a course (Codeberg #9).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function startPractice(int $courseId, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->startPracticeExam($courseId, $this->userId, $lang), 201);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Video or material must be completed first', 'gate' => 'video'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\DB\Exception $e) {
            $this->logger->error('Starting a practice exam failed', ['exception' => $e, 'courseId' => $courseId]);
            return new DataResponse(['error' => 'Failed to start practice exam'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to start practice exam'], 400);
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
            $response = $this->service->completeSession($sessionId, $this->userId, $lang);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to complete session'], 400);
        }

        // GitHub #35: a counting exam in a certifying course issues the certificate now, after
        // the completion is stored — not only when the learner later opens the completion tab.
        // Outside the try above on purpose: a certification problem must never turn a completed
        // exam into an error response. The trigger itself never throws; this guard is the belt
        // to its braces.
        try {
            $certification = $this->userId !== null
                ? $this->certificationTrigger->afterSessionCompleted($sessionId, $this->userId)
                : null;
        } catch (\Throwable $e) {
            $certification = null;
        }
        if ($certification !== null) {
            $response['certification'] = $certification;
        }
        return new DataResponse($response);
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
