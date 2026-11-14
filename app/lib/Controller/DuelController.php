<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\DuelService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class DuelController extends Controller {
    private DuelService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, DuelService $service, ?string $userId, private LoggerInterface $logger) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(int $poolId, int $numQuestions = 10, ?int $courseId = null): DataResponse {
        try {
            $numQuestions = max(5, min(50, $numQuestions));
            return new DataResponse($this->service->createDuel($poolId, $this->userId, $numQuestions, $courseId), Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to create duel', 'create');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function join(string $code): DataResponse {
        try {
            return new DataResponse($this->service->joinDuel($code, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to join duel', 'join');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function ready(string $code, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->setReady($code, $this->userId, $lang));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to set ready', 'ready');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function state(string $code, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->getState($code, $this->userId, $lang));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to get duel state', 'state');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function answer(string $code, int $answerId, int $answeredAt, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->submitAnswer($code, $this->userId, $answerId, $answeredAt, $lang));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to submit answer', 'answer');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function rematch(string $code): DataResponse {
        try {
            return new DataResponse($this->service->rematch($code, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to create rematch', 'rematch');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function invite(?int $poolId = null, string $inviteeUid = '', int $numQuestions = 10, int $courseId = 0): DataResponse {
        try {
            $numQuestions = max(5, min(50, $numQuestions));
            if ($courseId <= 0) {
                throw new \RuntimeException('Course context is required for duel invitations');
            }
            return new DataResponse(
                $this->service->createInvite($poolId, $this->userId, $inviteeUid, $numQuestions, $courseId),
                Http::STATUS_CREATED
            );
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to create duel invite', 'invite');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function invites(): DataResponse {
        try {
            return new DataResponse($this->service->listInvites($this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to load duel invites', 'invites');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function acceptInvite(int $id): DataResponse {
        try {
            return new DataResponse($this->service->acceptInvite($id, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to accept duel invite', 'acceptInvite');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function declineInvite(int $id): DataResponse {
        try {
            return new DataResponse($this->service->declineInvite($id, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to decline duel invite', 'declineInvite');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function cancelInvite(int $id): DataResponse {
        try {
            return new DataResponse($this->service->cancelInvite($id, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to cancel duel invite', 'cancelInvite');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function opponents(int $courseId): DataResponse {
        try {
            return new DataResponse($this->service->getInviteCandidates($courseId, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to load duel opponents', 'opponents');
        }
    }
}
