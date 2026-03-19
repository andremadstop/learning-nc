<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\SupportTicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class SupportTicketController extends Controller {
    private SupportTicketService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, SupportTicketService $service, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function create(?string $subject = null, string $message = '', array $context = []): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $ticket = $this->service->create($this->userId, $subject, $message, $context);
            return new DataResponse(['ticket' => $ticket->jsonSerialize()], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function mine(int $limit = 20): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse(['tickets' => $this->service->listMine($this->userId, $limit)]);
    }

    /**
     * @AdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function adminList(int $limit = 100): DataResponse {
        return new DataResponse(['tickets' => $this->service->listRecent($limit)]);
    }

    /**
     * @AdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function answer(int $id, string $answerText = ''): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse(['ticket' => $this->service->answer($id, $answerText, $this->userId)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
