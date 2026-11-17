<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\SupportTicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
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

    #[NoAdminRequired]
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

    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function mine(int $limit = 20): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse(['tickets' => $this->service->listMine($this->userId, $limit)]);
    }

    /**
     * The admin inbox: technical tickets without a course. GitHub #59: archived tickets are listed
     * apart; open_count feeds the count at the heading, also while the archive is shown.
     *
     * Admin-only: the route carries no #[NoAdminRequired], and the method checks again.
     *
     * @param int $limit at most this many tickets (1 to 200)
     * @param bool $archived true lists only the archived tickets
     * @return DataResponse<Http::STATUS_OK, array{tickets: list<array<string, mixed>>, open_count: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function adminList(int $limit = 100, bool $archived = false): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        return new DataResponse([
            'tickets' => array_values($this->service->listAdmin($limit, $archived)),
            'open_count' => $this->service->countOpenAdmin(),
        ]);
    }

    /**
     * GitHub #59: an administrator hides a technical ticket from the admin inbox.
     * open_count is the new inbox count, so the page needs no extra list request (whose rate
     * limit is lower than this one's) to keep the heading right.
     *
     * @param int $id the ticket
     * @return DataResponse<Http::STATUS_OK, array{ticket: array<string, mixed>, open_count: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function archiveAdminTicket(int $id): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        try {
            $ticket = $this->service->setAdminTicketArchived($id, true);
            return new DataResponse(['ticket' => $ticket, 'open_count' => $this->service->countOpenAdmin()]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * GitHub #59: an administrator brings an archived technical ticket back to the inbox.
     *
     * @param int $id the ticket
     * @return DataResponse<Http::STATUS_OK, array{ticket: array<string, mixed>, open_count: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function restoreAdminTicket(int $id): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        try {
            $ticket = $this->service->setAdminTicketArchived($id, false);
            return new DataResponse(['ticket' => $ticket, 'open_count' => $this->service->countOpenAdmin()]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * GitHub #59: an administrator deletes a technical ticket for good.
     *
     * @param int $id the ticket
     * @return DataResponse<Http::STATUS_OK, array{deleted: bool, open_count: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function deleteAdminTicket(int $id): DataResponse {
        if (!$this->isAdmin() || $this->userId === null) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        try {
            $this->service->deleteAdminTicket($id, $this->userId);
            return new DataResponse(['deleted' => true, 'open_count' => $this->service->countOpenAdmin()]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }
    }

    private function isAdmin(): bool {
        return $this->userId !== null && $this->userId !== '' && $this->groupManager->isAdmin($this->userId);
    }

    #[NoAdminRequired]
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
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // AUDIT LOW-06: unknown ticket id is a 404, not a 500 (mirrors approveDraft).
            return new DataResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

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
     * GitHub #59: a learner asks the instructors of a course from its communication tab, with or
     * without VirtuProf. Only a learner of the course; the question reaches "Questions from
     * learners" of that course.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function askCourse(int $courseId, ?string $subject = null, string $message = ''): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse(['question' => $this->service->askCourseQuestion($courseId, $this->userId, $subject, $message)], Http::STATUS_CREATED);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * GitHub #59: the caller's own questions to the instructors of a course, with the answers.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function myCourseQuestions(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($this->service->listMyCourseQuestions($courseId, $this->userId));
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function instructorList(int $courseId, int $limit = 50, bool $archived = false): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->courseService->canManageCourse($courseId, $this->userId)) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        // GitHub #59: archived questions are listed apart; open_count feeds the tab's badge
        return new DataResponse([
            'tickets' => $this->service->listInstructorForCourse($courseId, $limit, $archived),
            'open_count' => $this->service->countOpenForCourse($courseId),
        ]);
    }

    /**
     * GitHub #59: how many questions of the course wait for an answer (badge at the tab).
     */
    #[NoAdminRequired]
    public function openCount(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->courseService->canManageCourse($courseId, $this->userId)) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        return new DataResponse(['open_count' => $this->service->countOpenForCourse($courseId)]);
    }

    /**
     * GitHub #59: an instructor of the course hides a question from the inbox.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function archiveCourseTicket(int $courseId, int $ticketId): DataResponse {
        return $this->manageCourseTicket(fn (string $uid) => ['ticket' => $this->service->setCourseTicketArchived($courseId, $ticketId, $uid, true)]);
    }

    /**
     * GitHub #59: an instructor of the course brings an archived question back.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function restoreCourseTicket(int $courseId, int $ticketId): DataResponse {
        return $this->manageCourseTicket(fn (string $uid) => ['ticket' => $this->service->setCourseTicketArchived($courseId, $ticketId, $uid, false)]);
    }

    /**
     * GitHub #59: an instructor of the course deletes a question for good.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function deleteCourseTicket(int $courseId, int $ticketId): DataResponse {
        return $this->manageCourseTicket(function (string $uid) use ($courseId, $ticketId): array {
            $this->service->deleteCourseTicket($courseId, $ticketId, $uid);
            return ['deleted' => true];
        });
    }

    /**
     * GitHub #59: a learner withdraws their own question while it has no answer.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function withdrawCourseQuestion(int $courseId, int $ticketId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $this->service->withdrawCourseQuestion($courseId, $ticketId, $this->userId);
            return new DataResponse(['deleted' => true]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Question not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }

    /** @param callable(string): array<string, mixed> $action */
    private function manageCourseTicket(callable $action): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($action($this->userId));
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Ticket not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
