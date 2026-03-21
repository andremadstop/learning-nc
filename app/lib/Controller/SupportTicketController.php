<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\SupportTicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

class SupportTicketController extends Controller {
    private SupportTicketService $service;
    private CourseService $courseService;
    private IGroupManager $groupManager;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, SupportTicketService $service, CourseService $courseService, IGroupManager $groupManager, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->courseService = $courseService;
        $this->groupManager = $groupManager;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function create(?string $subject = null, string $message = '', array $context = [], ?string $category = null): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $ticket = $this->service->create($this->userId, $subject, $message, $context, $category);
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
        return new DataResponse(['tickets' => $this->service->listAdmin($limit)]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function answer(int $id, string $answerText = ''): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $isAdmin = $this->groupManager->isAdmin($this->userId);
            // For instructor routing: resolve which course this ticket belongs to for permission check
            $managedCourseId = $isAdmin ? null : $this->service->getManagedCourseIdForTicket($id, $this->userId);
            return new DataResponse(['ticket' => $this->service->answer($id, $answerText, $this->userId, $isAdmin, $managedCourseId)]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @AdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function approveDraft(int $id): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse(['ticket' => $this->service->approveDraft($id, $this->userId)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function instructorList(int $courseId, int $limit = 50): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->courseService->canManageCourse($courseId, $this->userId)) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        return new DataResponse(['tickets' => $this->service->listInstructorForCourse($courseId, $limit)]);
    }
}
