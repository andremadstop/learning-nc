<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\AlreadyMemberException;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\MemberNotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Enrolling course members (GitHub #35): one account, several accounts and groups at once, and the
 * search behind the member picker. Every endpoint is limited to instructors of the course; a
 * student gets 403 before any search runs, so the picker cannot be used to list accounts.
 */
class CourseMemberController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CourseEnrollmentService $enrollment,
        private IL10N $l,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /api/courses/{courseId}/members — body {userId, role}.
     * 201 enrolled · 200 role changed · 404 no such account · 409 already a member with that role.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function add(int $courseId, string $userId = '', string $role = 'student'): DataResponse {
        try {
            $result = $this->enrollment->addMember($courseId, $userId, $role, (string)$this->userId);
            return new DataResponse($result['member'], $result['created'] ? Http::STATUS_CREATED : Http::STATUS_OK);
        } catch (MemberNotFoundException $e) {
            return $this->error('user_not_found', $this->l->t('User "%s" not found.', [trim($userId)]), Http::STATUS_NOT_FOUND);
        } catch (AlreadyMemberException $e) {
            return $this->error('already_member', $this->l->t('User "%s" is already a member of this course.', [trim($userId)]), Http::STATUS_CONFLICT);
        } catch (DoesNotExistException $e) {
            return $this->error('course_not_found', $this->l->t('Course not found.'), Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return $this->error('forbidden', $this->l->t('Only instructors of this course can manage its members.'), Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('Adding a course member failed: ' . $e->getMessage(), ['app' => 'learning']);
            return $this->error('failed', $this->l->t('The member could not be added.'), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/courses/{courseId}/members/bulk — body {userIds: string[], groupIds: string[], role}.
     * Groups are enrolled as they are now. The body lists every account: added, already a member,
     * already a member with another role that was kept (roleUnchanged), not found, groups not
     * available, failed. Any failed insert makes the status 500, with the
     * same body, so a partial enrolment is never reported as success.
     *
     * @NoAdminRequired
     * @param mixed $userIds
     * @param mixed $groupIds
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function bulk(int $courseId, $userIds = [], $groupIds = [], string $role = 'student'): DataResponse {
        if (!is_array($userIds) || !is_array($groupIds)) {
            return $this->error('invalid_request', $this->l->t('Choose at least one person or group.'), Http::STATUS_BAD_REQUEST);
        }
        try {
            $result = $this->enrollment->bulkAdd($courseId, array_values($userIds), array_values($groupIds), $role, (string)$this->userId);
            return new DataResponse($result, $result['failed'] === [] ? Http::STATUS_OK : Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (DoesNotExistException $e) {
            return $this->error('course_not_found', $this->l->t('Course not found.'), Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return $this->error('forbidden', $this->l->t('Only instructors of this course can manage its members.'), Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            // Each limit names its own number: a selection of 201 people must not be told "at most 1000".
            $message = match ($e->getMessage()) {
                'too_many_users' => $this->l->t('Too many people selected: choose at most %s people in one step.', [(string)CourseEnrollmentService::MAX_USER_IDS]),
                'too_many_groups' => $this->l->t('Too many groups selected: choose at most %s groups in one step.', [(string)CourseEnrollmentService::MAX_GROUP_IDS]),
                'too_many' => $this->l->t('Too many people at once: enrol at most %s people in one step.', [(string)CourseEnrollmentService::MAX_ENROLMENTS]),
                'invalid_role' => $this->l->t('Unknown role.'),
                default => $this->l->t('Choose at least one person or group.'),
            };
            return $this->error($e->getMessage(), $message, Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Bulk enrolment failed: ' . $e->getMessage(), ['app' => 'learning']);
            return $this->error('failed', $this->l->t('The members could not be added.'), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/courses/{courseId}/member-search?search= — accounts and groups for the picker, at
     * most 25 each, through Nextcloud's collaborator search.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function search(int $courseId, string $search = ''): DataResponse {
        try {
            return new DataResponse($this->enrollment->search($courseId, $search, (string)$this->userId));
        } catch (DoesNotExistException $e) {
            return $this->error('course_not_found', $this->l->t('Course not found.'), Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return $this->error('forbidden', $this->l->t('Only instructors of this course can manage its members.'), Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('Member search failed: ' . $e->getMessage(), ['app' => 'learning']);
            return $this->error('failed', $this->l->t('The search failed.'), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /** @param Http::STATUS_* $status */
    private function error(string $code, string $message, int $status): DataResponse {
        return new DataResponse(['error' => $message, 'code' => $code], $status);
    }
}
