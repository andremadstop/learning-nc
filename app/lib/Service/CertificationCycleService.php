<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * GitHub #84 / #87: "Open a new certification cycle" — the instructor's only way back for a
 * learner who is stuck. Never automatic: re-enrolling changes the membership and nothing else.
 *
 * A learner is stuck (reasonFor()) when
 *   - 'revoked'  the newest certificate was revoked and no cycle was opened since. A revoke never
 *                reissues by itself (PassCriteriaService::issuanceWindow(), SC2);
 *   - 'expired'  the newest certificate expired, its renewal window is closed and no
 *                recertification period is open (e.g. the period was cancelled);
 *   - 'attempts' the total attempt limit is used up on a pool (ExamAttemptLimitService), while the
 *                learner holds no certificate that is still valid outside its renewal window.
 * Anything else has nothing to reset (open() answers 409).
 *
 * open() starts a period (AssignmentService::reopenPeriod()): from then on only exams completed
 * in it count, the attempts count from zero, and after a revoke the required documents are
 * confirmed again in any course (RequiredDocumentService::cycleStarts()). Nothing is deleted:
 * earlier exams, confirmations, the revoked certificate and the old period stay as evidence, and
 * the reopening is a PERIOD_REOPENED event in the compliance chain. The learner is notified, and
 * the instructors' "attempts used up" notifications of the old cycle are removed.
 */
class CertificationCycleService {
    public const REASON_REVOKED = 'revoked';
    public const REASON_EXPIRED = 'expired';
    public const REASON_ATTEMPTS = 'attempts';
    private const OPEN_STATUSES = ['assigned', 'in_progress', 'overdue'];

    public function __construct(
        private readonly CourseService $courseService,
        private readonly CourseMapper $courseMapper,
        private readonly CourseMemberMapper $memberMapper,
        private readonly CertificateMapper $certificateMapper,
        private readonly AssignmentService $assignmentService,
        private readonly PassCriteriaService $passCriteria,
        private readonly ExamAttemptLimitService $attemptLimit,
        private readonly INotificationManager $notificationManager,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Why the instructor may open a new cycle for this learner, or null when nothing is stuck.
     *
     * @param array{status: string, due_date: int|null, assigned_at: int, reopen_reason: string|null}|null $state the active period
     * @param list<int> $exhaustedPools
     */
    public function reasonFor(?Certificate $newest, ?array $state, array $exhaustedPools, int $now): ?string {
        $open = $state !== null && in_array($state['status'], self::OPEN_STATUSES, true);
        if ($newest !== null && (bool)$newest->getRevoked()) {
            $revokedAt = (int)($newest->getRevokedAt() ?? 0);
            $cycleOpenSinceRevoke = $open && $state['reopen_reason'] === self::REASON_REVOKED && $state['assigned_at'] >= $revokedAt;
            if (!$cycleOpenSinceRevoke) {
                return self::REASON_REVOKED;
            }
            // In the cycle opened after the revoke the attempts can run out again; the new cycle
            // stays one after a revoke (its documents are confirmed again).
            return $exhaustedPools !== [] ? self::REASON_REVOKED : null;
        }
        $renewalOpensAt = $this->passCriteria->renewalOpensAt($newest);
        $renewalOpen = $renewalOpensAt !== null && $now >= $renewalOpensAt;
        if ($newest !== null && !$renewalOpen) {
            $expiresAt = $newest->getExpiresAt();
            $expired = $expiresAt !== null && (int)$expiresAt <= $now;
            if ($expired && !$open) {
                return self::REASON_EXPIRED;
            }
            if (!$expired && !$open) {
                // A valid certificate outside its renewal window: nothing to retake.
                return null;
            }
        }
        return $exhaustedPools !== [] ? self::REASON_ATTEMPTS : null;
    }

    /**
     * The instructor's member list: for every student member of the course, whether a new cycle
     * can be opened and why, the cycle an instructor opened if one is open, and the pools whose
     * attempts are used up.
     *
     * @return array<int, array{reason: string|null, new_cycle: array{reason: string, since: int}|null, exhausted_pool_ids: list<int>}> member id => state
     * @throws DoesNotExistException course not found
     * @throws ForbiddenException    caller is not an instructor of the course
     */
    public function statesForCourse(int $courseId, string $instructorId): array {
        $course = $this->courseService->assertInstructorOfCourse($courseId, $instructorId);
        $members = [];
        foreach ($this->memberMapper->findByCourse($courseId) as $member) {
            $uid = (string)$member->getUserId();
            if ($member->getRole() === 'student' && $uid !== '' && $uid !== (string)$course->getInstructorId()) {
                $members[(int)$member->getId()] = $uid;
            }
        }
        if ($members === []) {
            return [];
        }
        $states = $this->assignmentService->getStatesForCourseAndUsers($courseId, array_values(array_unique($members)));
        $now = $this->timeFactory->getTime();
        $out = [];
        foreach ($members as $memberId => $uid) {
            $state = $states[$uid] ?? null;
            $exhausted = $this->attemptLimit->exhaustedPools($uid, $course);
            $newest = $this->certificateMapper->findByUserAndCourse($uid, $courseId);
            $out[$memberId] = [
                'reason' => ($course->getCertEnabled() ?? false) ? $this->reasonFor($newest, $state, $exhausted, $now) : null,
                'new_cycle' => self::newCycleOf($state),
                'exhausted_pool_ids' => $exhausted,
            ];
        }
        return $out;
    }

    /**
     * Opens the new cycle for one student member of the course.
     *
     * @return array{reason: string, since: int}
     * @throws DoesNotExistException course or member not found, or the member is no student of it
     * @throws ForbiddenException    caller is not an instructor of the course
     * @throws NothingToResetException the learner is not stuck (see reasonFor())
     */
    public function open(int $courseId, int $memberId, string $instructorId): array {
        $course = $this->courseService->assertInstructorOfCourse($courseId, $instructorId);
        if (!($course->getCertEnabled() ?? false)) {
            throw new NothingToResetException();
        }
        $member = $this->memberMapper->findById($memberId);
        $uid = (string)$member->getUserId();
        if ((int)$member->getCourseId() !== $courseId || $member->getRole() !== 'student' || $uid === '' || $uid === (string)$course->getInstructorId()) {
            throw new DoesNotExistException('Member not found in this course');
        }
        $newest = $this->certificateMapper->findByUserAndCourse($uid, $courseId);
        $state = $this->assignmentService->getStatesForCourseAndUsers($courseId, [$uid])[$uid] ?? null;
        $reason = $this->reasonFor($newest, $state, $this->attemptLimit->exhaustedPools($uid, $course), $this->timeFactory->getTime());
        if ($reason === null) {
            throw new NothingToResetException();
        }
        // Before the period changes: the old cycle's notification ids depend on its start.
        $this->attemptLimit->clearNotifications($uid, $courseId);
        $verificationId = $newest !== null && $reason !== self::REASON_ATTEMPTS ? (string)$newest->getVerificationId() : null;
        $since = $this->assignmentService->reopenPeriod($courseId, $uid, $reason, $instructorId, $verificationId);
        $this->notifyLearner($uid, $course, 'certification_cycle_opened', 'certification_cycle', hash('sha1', $courseId . ':' . $uid . ':' . $since));
        return ['reason' => $reason, 'since' => $since];
    }

    /**
     * The learner learns of the revoke (GitHub #84). Best effort: the revoke is committed and
     * must never look failed because a notification backend threw.
     */
    public function notifyRevoked(Certificate $certificate): void {
        try {
            $courseId = (int)$certificate->getCourseId();
            $course = $this->courseMapper->findById($courseId);
            $this->notifyLearner((string)$certificate->getUserId(), $course, 'certificate_revoked', 'certificate_revoked', (string)$certificate->getVerificationId());
        } catch (\Throwable $e) {
            $this->logger->warning('Revoke notification failed: {msg}', ['msg' => $e->getMessage(), 'app' => 'learning']);
        }
    }

    /**
     * @param array{status: string, due_date: int|null, assigned_at: int, reopen_reason: string|null}|null $state
     * @return array{reason: string, since: int}|null
     */
    private static function newCycleOf(?array $state): ?array {
        if ($state === null || $state['reopen_reason'] === null || !in_array($state['status'], self::OPEN_STATUSES, true)) {
            return null;
        }
        return ['reason' => $state['reopen_reason'], 'since' => $state['assigned_at']];
    }

    private function notifyLearner(string $userId, Course $course, string $subject, string $objectType, string $objectId): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('learning')
                ->setUser($userId)
                ->setObject($objectType, $objectId)
                ->setSubject($subject, ['course_title' => (string)$course->getTitle(), 'course_id' => (int)$course->getId()]);
            if ($this->notificationManager->getCount($notification) === 0) {
                $notification->setDateTime($this->timeFactory->getDateTime());
                $this->notificationManager->notify($notification);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Learner notification {subject} failed: {msg}', ['subject' => $subject, 'msg' => $e->getMessage(), 'app' => 'learning']);
        }
    }
}
