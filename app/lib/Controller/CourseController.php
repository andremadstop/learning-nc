<?php
namespace OCA\Learning\Controller;

use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CourseController extends Controller {
    private CourseService $courseService;
    private RoleService $roleService;
    private LoggerInterface $logger;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        CourseService $courseService,
        RoleService $roleService,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->courseService = $courseService;
        $this->roleService = $roleService;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function role(): JSONResponse {
        return new JSONResponse([
            'role' => $this->roleService->getRole($this->userId),
            'group' => $this->roleService->getInstructorGroup(),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): JSONResponse {
        try {
            return new JSONResponse($this->courseService->findAll($this->userId));
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to list courses'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $courseId): JSONResponse {
        try {
            return new JSONResponse($this->courseService->findById($courseId, $this->userId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to load course'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(string $title, ?string $description = null, ?string $ncGroupId = null): JSONResponse {
        try {
            $course = $this->courseService->create($title, $description, $ncGroupId, $this->userId);
            return new JSONResponse($course, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to create course'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(int $courseId, ?string $title = null, ?string $description = null, ?string $status = null): JSONResponse {
        try {
            $course = $this->courseService->update($courseId, $title, $description, $status, $this->userId);
            return new JSONResponse($course);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to update course'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $courseId): JSONResponse {
        try {
            $this->courseService->delete($courseId, $this->userId);
            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to delete course'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function listPools(int $courseId): JSONResponse {
        try {
            $data = $this->courseService->findById($courseId, $this->userId);
            return new JSONResponse($data['pools'] ?? []);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to list pools'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function addPool(int $courseId, int $poolId, int $sortOrder = 0, bool $required = true): JSONResponse {
        try {
            $cp = $this->courseService->addPool($courseId, $poolId, $sortOrder, $required, $this->userId);
            return new JSONResponse($cp, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to add pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function removePool(int $courseId, int $poolId): JSONResponse {
        try {
            $this->courseService->removePool($courseId, $poolId, $this->userId);
            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to remove pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function listMembers(int $courseId): JSONResponse {
        try {
            return new JSONResponse($this->courseService->getMembers($courseId, $this->userId));
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'No permission to view members'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function addMember(int $courseId, string $userId, string $role = 'student'): JSONResponse {
        try {
            $member = $this->courseService->addMember($courseId, $userId, $role, $this->userId);
            return new JSONResponse($member, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to add member'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function removeMember(int $courseId, string $memberId): JSONResponse {
        try {
            $this->courseService->removeMember($courseId, $memberId, $this->userId);
            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to remove member'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function enroll(int $courseId): JSONResponse {
        try {
            $member = $this->courseService->enroll($courseId, $this->userId);
            return new JSONResponse($member, Http::STATUS_CREATED);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            // FIX-LO-1: Return 403 for auth/group errors, 400 for others
            $msg = $e->getMessage();
            if (strpos($msg, 'not in the required group') !== false) {
                return new JSONResponse(['error' => 'Not authorized to enroll'], Http::STATUS_FORBIDDEN);
            }
            return new JSONResponse(['error' => 'Failed to enroll in course'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function progress(
        int $courseId,
        int $limit = 25,
        int $offset = 0,
        ?string $sortKey = null,
        ?string $sortDir = null
    ): JSONResponse {
        try {
            return new JSONResponse($this->courseService->getCourseProgress(
                $courseId,
                $this->userId,
                $limit,
                $offset,
                $sortKey,
                $sortDir
            ));
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'No permission to view progress'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function myProgress(int $courseId): JSONResponse {
        try {
            return new JSONResponse($this->courseService->getMyCourseProgress($courseId, $this->userId));
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'No permission to view progress'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function dashboard(): JSONResponse {
        try {
            return new JSONResponse($this->courseService->getDashboard($this->userId));
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Not authorized for dashboard'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function leaderboard(
        int $courseId,
        int $limit = 25,
        int $offset = 0,
        ?string $sortKey = null,
        ?string $sortDir = null,
        bool $activeOnly = false,
        int $activeWithinDays = 30
    ): JSONResponse {
        try {
            return new JSONResponse($this->courseService->getLeaderboard(
                $courseId,
                $this->userId,
                $limit,
                $offset,
                $sortKey,
                $sortDir,
                $activeOnly,
                $activeWithinDays
            ));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('leaderboard error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function atRisk(int $courseId): JSONResponse {
        try {
            return new JSONResponse($this->courseService->getAtRiskStudents($courseId, $this->userId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('atRisk error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function studentDetail(int $courseId, string $studentId): JSONResponse {
        try {
            return new JSONResponse($this->courseService->getStudentDetail($courseId, $studentId, $this->userId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\NotFoundException $e) {
            return new JSONResponse(['error' => 'Student not found in this course'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('studentDetail error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
