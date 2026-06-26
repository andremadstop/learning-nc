<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CourseController extends Controller {
    private CourseMapper $courseMapper;
    private CourseMemberMapper $courseMemberMapper;
    private CourseArchiveService $archiveService;
    private CourseService $courseService;
    private RoleService $roleService;
    private ScheduleService $scheduleService;
    private PassCriteriaService $passCriteriaService;
    private LoggerInterface $logger;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        CourseMapper $courseMapper,
        CourseMemberMapper $courseMemberMapper,
        CourseArchiveService $archiveService,
        CourseService $courseService,
        RoleService $roleService,
        ScheduleService $scheduleService,
        PassCriteriaService $passCriteriaService,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->courseMapper = $courseMapper;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->archiveService = $archiveService;
        $this->courseService = $courseService;
        $this->roleService = $roleService;
        $this->scheduleService = $scheduleService;
        $this->passCriteriaService = $passCriteriaService;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function role(): DataResponse {
        return new DataResponse([
            'role' => $this->roleService->getRole($this->userId),
            'group' => $this->roleService->getInstructorGroup(),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            return new DataResponse($this->courseService->findAll($this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to list courses'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $courseId): DataResponse {
        try {
            return new DataResponse($this->courseService->findById($courseId, $this->userId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('Failed to load course: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to load course'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(string $title, ?string $description = null, ?string $ncGroupId = null): DataResponse {
        try {
            $course = $this->courseService->create($title, $description, $ncGroupId, $this->userId);
            return new DataResponse($course, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to create course'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function update(int $courseId, ?string $title = null, ?string $description = null, ?string $status = null): DataResponse {
        try {
            $course = $this->courseService->update($courseId, $title, $description, $status, $this->userId);
            return new DataResponse($course);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update course'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function destroy(int $courseId): DataResponse {
        try {
            $this->courseService->delete($courseId, $this->userId);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to delete course'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function listPools(int $courseId): DataResponse {
        try {
            $data = $this->courseService->findById($courseId, $this->userId);
            return new DataResponse($data['pools'] ?? []);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to list pools'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function addPool(int $courseId, int $poolId, int $sortOrder = 0, bool $required = true): DataResponse {
        try {
            $cp = $this->courseService->addPool($courseId, $poolId, $sortOrder, $required, $this->userId);
            return new DataResponse($cp, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to add pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updatePool(
        int $courseId,
        int $poolId,
        bool $required = true,
        bool $requiredEnforced = false,
        ?string $filterExamKey = null,
        ?string $filterChapterKey = null,
        ?array $filterQuestionIds = null,
        ?bool $examRelevant = null
    ): DataResponse {
        try {
            $cp = $this->courseService->updatePoolRules(
                $courseId,
                $poolId,
                $required,
                $requiredEnforced,
                $filterExamKey,
                $filterChapterKey,
                $filterQuestionIds,
                $this->userId,
                $examRelevant
            );
            return new DataResponse($cp);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to update pool rules'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function removePool(int $courseId, int $poolId): DataResponse {
        try {
            $this->courseService->removePool($courseId, $poolId, $this->userId);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to remove pool'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function listMembers(int $courseId): DataResponse {
        try {
            return new DataResponse($this->courseService->getMembers($courseId, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No permission to view members'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function addMember(int $courseId, string $userId, string $role = 'student'): DataResponse {
        try {
            $member = $this->courseService->addMember($courseId, $userId, $role, $this->userId);
            return new DataResponse($member, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to add member'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function removeMember(int $courseId, string $memberId): DataResponse {
        try {
            $this->courseService->removeMember($courseId, $memberId, $this->userId);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to remove member'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function enroll(int $courseId): DataResponse {
        try {
            $member = $this->courseService->enroll($courseId, $this->userId);
            return new DataResponse($member, Http::STATUS_CREATED);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            // FIX-LO-1: Return 403 for auth/group errors, 400 for others
            $msg = $e->getMessage();
            if (strpos($msg, 'not in the required group') !== false) {
                return new DataResponse(['error' => 'Not authorized to enroll'], Http::STATUS_FORBIDDEN);
            }
            return new DataResponse(['error' => 'Failed to enroll in course'], Http::STATUS_BAD_REQUEST);
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
    ): DataResponse {
        try {
            return new DataResponse($this->courseService->getCourseProgress(
                $courseId,
                $this->userId,
                $limit,
                $offset,
                $sortKey,
                $sortDir
            ));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No permission to view progress'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function myProgress(int $courseId): DataResponse {
        try {
            return new DataResponse($this->courseService->getMyCourseProgress($courseId, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No permission to view progress'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function dashboard(): DataResponse {
        try {
            return new DataResponse($this->courseService->getDashboard($this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Not authorized for dashboard'], Http::STATUS_FORBIDDEN);
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
    ): DataResponse {
        try {
            return new DataResponse($this->courseService->getLeaderboard(
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
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('leaderboard error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function atRisk(int $courseId): DataResponse {
        try {
            return new DataResponse($this->courseService->getAtRiskStudents($courseId, $this->userId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('atRisk error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportAtRiskCsv(int $courseId): Http\Response {
        try {
            $students = $this->courseService->getAtRiskStudents($courseId, $this->userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Name', 'Risk Level', 'Critical Cards', 'Risk Reasons', 'Accuracy (%)', 'Last Active']);
        foreach (($students['at_risk'] ?? []) as $s) {
            fputcsv($handle, [
                $s['display_name'] ?? $s['user_id'] ?? '',
                $s['risk_level'] ?? '',
                $s['critical_cards_count'] ?? 0,
                implode('; ', $s['risk_reasons'] ?? []),
                $s['accuracy'] ?? '',
                $s['last_active'] ?? '',
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new DataDownloadResponse($csv, 'at-risk-course-' . $courseId . '.csv', 'text/csv');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function studentDetail(int $courseId, string $studentId): DataResponse {
        try {
            return new DataResponse($this->courseService->getStudentDetail($courseId, $studentId, $this->userId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\NotFoundException $e) {
            return new DataResponse(['error' => 'Student not found in this course'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('studentDetail error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function chapterHeatmap(int $courseId): DataResponse {
        try {
            return new DataResponse($this->courseService->getChapterHeatmap($courseId, $this->userId));
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('chapterHeatmap error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function weakQuestions(int $courseId, int $limit = 10): DataResponse {
        try {
            return new DataResponse($this->courseService->getWeakQuestions($courseId, $this->userId, $limit));
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('weakQuestions error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function setQuestionOverride(int $courseId, int $questionId, bool $paused = false, bool $highlight = false): DataResponse {
        try {
            return new DataResponse($this->courseService->setQuestionOverride($courseId, $questionId, $paused, $highlight, $this->userId));
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('setQuestionOverride error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function courseDashboard(int $courseId): DataResponse {
        try {
            return new DataResponse($this->courseService->getCourseDashboard($courseId, $this->userId));
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('courseDashboard error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getAnnouncements(int $courseId): DataResponse {
        try {
            return new DataResponse(['announcements' => $this->courseService->getAnnouncements($courseId, $this->userId)]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('getAnnouncements error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function createAnnouncement(int $courseId, string $title = '', string $body = '', ?int $expiresAt = null): DataResponse {
        try {
            $announcement = $this->courseService->createAnnouncement($courseId, $this->userId, $title, $body, $expiresAt);
            return new DataResponse(['announcement' => $announcement], Http::STATUS_CREATED);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('createAnnouncement error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function deleteAnnouncement(int $courseId, int $announcementId): DataResponse {
        try {
            $this->courseService->deleteAnnouncement($courseId, $announcementId, $this->userId);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('deleteAnnouncement error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getActiveExamSlot(int $courseId): DataResponse {
        try {
            return new DataResponse(['exam_slot' => $this->courseService->getActiveExamSlot($courseId, $this->userId)]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('getActiveExamSlot error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function createExamSlot(int $courseId, int $durationMinutes = 90, string $scopeMode = 'all'): DataResponse {
        try {
            $slot = $this->courseService->createExamSlot($courseId, $this->userId, $durationMinutes, $scopeMode);
            return new DataResponse(['exam_slot' => $slot], Http::STATUS_CREATED);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('createExamSlot error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function closeExamSlot(int $courseId): DataResponse {
        try {
            $this->courseService->closeExamSlot($courseId, $this->userId);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('closeExamSlot error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateModeConfig(int $courseId, array $modeConfig = [], ?string $talkRoomToken = null, ?bool $leitnerSprint = null): DataResponse {
        try {
            // Sanitize talkRoomToken: trim, max 255 chars, alphanumeric only (NC Talk tokens)
            if ($talkRoomToken !== null) {
                $talkRoomToken = substr(trim($talkRoomToken), 0, 255);
                if ($talkRoomToken !== '' && !preg_match('/^[a-zA-Z0-9]+$/', $talkRoomToken)) {
                    return new DataResponse(['error' => 'Invalid talk room token format'], Http::STATUS_BAD_REQUEST);
                }
                if ($talkRoomToken === '') {
                    $talkRoomToken = null;
                }
            }
            $config = $this->courseService->updateModeConfig($courseId, $this->userId, $modeConfig, $talkRoomToken, $leitnerSprint);
            return new DataResponse(['mode_config' => $config]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('updateModeConfig error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateExamDate(int $courseId, ?string $examDate = null): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }

            $course = $this->courseMapper->findById($courseId);
            if (!$this->canManageCourse($course, $this->userId)) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }

            $course->setExamDate($this->normalizeExamDate($examDate));
            $course->setUpdatedAt(time());
            $this->courseMapper->update($course);

            return new DataResponse([
                'exam_date' => $course->getExamDate(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('updateExamDate error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the certification configuration for a course (instructor-only).
     *
     * Each field is optional; only provided fields are updated. Pool IDs are normalized
     * (positive ints, deduped) and validated against the course's own pool list.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateCertConfig(
        int $courseId,
        ?bool $certEnabled = null,
        ?int $certPassPercent = null,
        ?array $certRequiredPoolIds = null,
        ?int $certValidityDays = null,
    ): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }
            $course = $this->courseMapper->findById($courseId);
            if (!$this->canManageCourse($course, $this->userId)) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }

            if ($certEnabled !== null) {
                $course->setCertEnabled($certEnabled);
            }
            if ($certPassPercent !== null) {
                if ($certPassPercent < 1 || $certPassPercent > 100) {
                    return new DataResponse(['error' => 'cert_pass_percent must be 1–100'], Http::STATUS_BAD_REQUEST);
                }
                $course->setCertPassPercent($certPassPercent);
            }
            if ($certRequiredPoolIds !== null) {
                // Normalize: cast to positive integers, dedup
                $normalizedIds = [];
                foreach ($certRequiredPoolIds as $rawId) {
                    $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        return new DataResponse(
                            ['error' => 'certRequiredPoolIds must contain positive integers'],
                            Http::STATUS_BAD_REQUEST
                        );
                    }
                    $normalizedIds[$id] = $id;
                }
                $certRequiredPoolIds = array_values($normalizedIds); // deduped, reset keys

                // Validate each ID belongs to a pool assigned to this course
                if (!empty($certRequiredPoolIds)) {
                    $courseData = $this->courseService->findById($courseId, $this->userId);
                    $validPoolIds = array_column($courseData['pools'] ?? [], 'id');
                    foreach ($certRequiredPoolIds as $poolId) {
                        if (!in_array($poolId, $validPoolIds, true)) {
                            return new DataResponse(
                                ['error' => "Pool {$poolId} does not belong to this course"],
                                Http::STATUS_BAD_REQUEST
                            );
                        }
                    }
                }

                // Store as JSON text; empty array → null (no required pools)
                $course->setCertRequiredPoolIds(
                    empty($certRequiredPoolIds) ? null : json_encode(array_values($certRequiredPoolIds))
                );
            }
            if ($certValidityDays !== null) {
                if ($certValidityDays < 0) {
                    return new DataResponse(['error' => 'cert_validity_days must be >= 0'], Http::STATUS_BAD_REQUEST);
                }
                $course->setCertValidityDays($certValidityDays);
            }

            $course->setUpdatedAt(time());
            $this->courseMapper->update($course);

            return new DataResponse([
                'certEnabled'         => $course->getCertEnabled() ?? false,
                'certPassPercent'     => $course->getCertPassPercent() ?? 80,
                'certRequiredPoolIds' => $course->getCertRequiredPoolIds() !== null
                    ? json_decode($course->getCertRequiredPoolIds(), true) ?? []
                    : [],
                'certValidityDays'    => $course->getCertValidityDays() ?? 0,
            ]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('updateCertConfig error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Returns the pass status of the current user for the given course.
     *
     * IDOR guard: verifies the requesting user is enrolled in or owns the course
     * before delegating to PassCriteriaService::evaluate(). Non-enrolled users → 403.
     * Uses canAccessCourse() which accepts any enrollment role (not just instructor).
     *
     * PASS-07 lazy trigger: PassCriteriaService::evaluate() emits a course.passed audit event
     * the first time this endpoint is called for a qualifying student. Subsequent calls are safe
     * (idempotent guard inside PassCriteriaService::emitPassEventIfFirst()).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function getPassStatus(int $courseId): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }
            $course = $this->courseMapper->findById($courseId);
            if (!$this->canAccessCourse($course, $this->userId)) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }
            $result = $this->passCriteriaService->evaluate($this->userId, $courseId);
            return new DataResponse($result->toArray());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('getPassStatus error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getTools(int $courseId): DataResponse {
        try {
            return new DataResponse([
                'enabled_tools' => $this->courseService->getCourseTools($courseId, $this->userId),
            ]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('getTools error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateTools(int $courseId, ?array $enabledTools = null): DataResponse {
        try {
            return new DataResponse([
                'enabled_tools' => $this->courseService->updateCourseTools($courseId, $this->userId, $enabledTools),
            ]);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('updateTools error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getCurriculumScope(int $courseId): DataResponse {
        try {
            return new DataResponse($this->courseService->getCurriculumScope($courseId, $this->userId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('getCurriculumScope error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateCurriculumScope(
        int $courseId,
        bool $enabled = false,
        array $chapterKeys = [],
        ?string $handbookKey = null,
        ?string $handbookTitle = null
    ): DataResponse {
        try {
            return new DataResponse(
                $this->courseService->saveCurriculumScope($courseId, $this->userId, $enabled, $chapterKeys, $handbookKey, $handbookTitle)
            );
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('updateCurriculumScope error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update allowed campaigns for a course.
     * NULL means all campaigns available, empty array means none.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateCampaignSelection(int $courseId, ?array $campaignIds = null): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }

            $course = $this->courseMapper->findById($courseId);
            if (!$this->canManageCourse($course, $this->userId)) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }

            if ($campaignIds !== null) {
                // Sanitize: only allow non-empty string IDs, max 100
                $campaignIds = array_values(array_filter(
                    array_slice($campaignIds, 0, 100),
                    fn($id) => is_string($id) && trim($id) !== ''
                ));
                $course->setAllowedCampaigns(json_encode($campaignIds));
            } else {
                $course->setAllowedCampaigns(null);
            }
            $course->setUpdatedAt(time());
            $this->courseMapper->update($course);

            return new DataResponse([
                'allowed_campaigns' => $campaignIds,
            ]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('updateCampaignSelection error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getSchedule(int $courseId): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }
            return new DataResponse($this->scheduleService->getSchedule($courseId, $this->userId));
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('getSchedule error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function setSchedule(int $courseId, array $entries = []): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }
            return new DataResponse($this->scheduleService->setSchedule($courseId, $entries, $this->userId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('setSchedule error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function deleteSchedule(int $courseId): DataResponse {
        try {
            if ($this->userId === null) {
                return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
            }
            $this->scheduleService->deleteSchedule($courseId, $this->userId);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('deleteSchedule error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function canManageCourse(Course $course, string $userId): bool {
        if ($course->getInstructorId() === $userId) {
            return true;
        }

        try {
            $member = $this->courseMemberMapper->findByCourseAndUser($course->getId(), $userId);
            return $member->getRole() === 'instructor';
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Returns true if $userId owns the course OR is enrolled as any role (student, instructor).
     * Used for endpoints accessible to all course participants (e.g., getPassStatus).
     *
     * Use canManageCourse() instead for instructor-only write endpoints (updateCertConfig, etc.).
     *
     * Note: getPassStatus() returns an explicit 403 (not the usual 404-for-both obscurity) when
     * this returns false. This is intentional — the pass-status endpoint is student-facing and
     * clear error semantics are correct here. See 154-CONTEXT.md IDOR guard requirement.
     *
     * Implementation mirrors CourseService::hasAccess() (private — not reachable from controller).
     */
    private function canAccessCourse(Course $course, string $userId): bool {
        if ($course->getInstructorId() === $userId) {
            return true;
        }
        try {
            $this->courseMemberMapper->findByCourseAndUser($course->getId(), $userId);
            return true; // any enrollment role (student or instructor) grants access
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return false;
        }
    }

    private function normalizeExamDate(?string $examDate): ?string {
        if ($examDate === null) {
            return null;
        }

        $examDate = trim($examDate);
        if ($examDate === '') {
            return null;
        }

        // Accept "YYYY-MM-DDTHH:MM" (datetime-local) or legacy "YYYY-MM-DD"
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $examDate)) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $examDate);
            $errors = \DateTimeImmutable::getLastErrors();
            $hasWarnings = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($parsed === false || $hasWarnings) {
                throw new \InvalidArgumentException('Invalid exam date');
            }
            return $examDate;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) {
            throw new \InvalidArgumentException('Invalid exam date format');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $examDate);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasWarnings = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        if ($parsed === false || $hasWarnings || $parsed->format('Y-m-d') !== $examDate) {
            throw new \InvalidArgumentException('Invalid exam date');
        }

        return $examDate;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function archiveCourse(int $courseId): DataResponse {
        try {
            $result = $this->archiveService->archiveCourse($courseId, $this->userId);
            return new DataResponse($result);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Archive failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to archive course'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function listSnapshots(int $courseId): DataResponse {
        try {
            $snapshots = $this->archiveService->listSnapshots($courseId, $this->userId);
            return new DataResponse($snapshots);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('List snapshots failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to list snapshots'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function showSnapshot(int $courseId, int $snapshotId): DataResponse {
        try {
            $snapshot = $this->archiveService->getSnapshot($snapshotId, $this->userId);
            if ($snapshot === null) {
                return new DataResponse(['error' => 'Snapshot not found'], Http::STATUS_NOT_FOUND);
            }
            if ((int) $snapshot['course_id'] !== $courseId) {
                return new DataResponse(['error' => 'Snapshot does not belong to this course'], Http::STATUS_BAD_REQUEST);
            }
            return new DataResponse($snapshot);
        } catch (\OCA\Learning\Service\ForbiddenException $e) {
            return new DataResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('Get snapshot failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to get snapshot'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
