<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * GitHub #87: a total limit on the counting exam attempts of a course (course.exam_attempt_limit).
 *
 * Counted like the admin's 24-hour limit (TrainingService::enforceExamStartPolicy()): per pool in
 * the course context, counting exams only (mode='exam', exam_kind IS NULL), every start — an
 * aborted or timed-out attempt is a used attempt (audit #31). Only in a course with
 * certification: without a pass mark an exam cannot be failed, so a limit would only lock
 * learners out. NULL or 0 = unlimited, as before.
 *
 * The count starts with the current certification cycle (PassCriteriaService::attemptCycleStart()):
 * an instructor's new cycle (CertificationCycleService), a period the recertification job opened,
 * or the open renewal window — and never before the limit was switched on
 * (course.exam_attempt_limit_since, countStart()). Earlier attempts stay in learning_sessions as
 * evidence, they just do not count against the new cycle.
 *
 * Instructors of the course (owner, co-instructors) are exempt: in the student view they try the
 * exam as a preview, and evaluate() never certifies them there anyway.
 *
 * When a learner has used up the attempts on a pool without having met the requirement, the
 * course's instructors get one notification per learner, pool and cycle (exam_attempts_exhausted).
 * Sent after the last attempt is completed, and — for an attempt that never reached the complete
 * call (abandoned, timed out) — when the next start is refused. Deduplicated, so both paths are safe.
 * Each of them also gets an activity (5.17.0, stream and by default email, ExamAttemptsSetting)
 * together with the notification, so exactly as often.
 */
class ExamAttemptLimitService {
    public const MAX_LIMIT = 50;
    /** Course profile "mandatory training": three attempts, then the instructor decides (GitHub #87). */
    public const MANDATORY_PROFILE_DEFAULT = 3;
    public const NOTIFICATION_OBJECT = 'exam_attempts';
    public const NOTIFICATION_SUBJECT = 'exam_attempts_exhausted';

    public function __construct(
        private readonly IDBConnection $db,
        private readonly CourseMapper $courseMapper,
        private readonly CourseMemberMapper $memberMapper,
        private readonly PassCriteriaService $passCriteria,
        private readonly CourseSummaryService $courseSummary,
        private readonly INotificationManager $notificationManager,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
        private readonly IConfig $config,
        private readonly ActivityPublisher $activities,
    ) {}

    /** The limit that applies in this course, null = unlimited. */
    public static function limitFor(Course $course): ?int {
        if (!($course->getCertEnabled() ?? false)) {
            return null;
        }
        $limit = $course->getExamAttemptLimit();
        return $limit !== null && (int)$limit > 0 ? (int)$limit : null;
    }

    /**
     * Sets the course's limit (null = unlimited) and, on the step from unlimited to a number, when
     * it was switched on (GitHub #87 review): attempts from before do not count against it.
     * Changing one number to another keeps that moment; switching it off clears it.
     */
    public static function applyLimit(Course $course, ?int $limit, int $now): void {
        $before = $course->getExamAttemptLimit();
        $wasLimited = $before !== null && (int)$before > 0;
        $isLimited = $limit !== null && $limit > 0;
        $course->setExamAttemptLimit($isLimited ? $limit : null);
        if (!$isLimited) {
            $course->setExamAttemptLimitSince(null);
        } elseif (!$wasLimited) {
            $course->setExamAttemptLimitSince($now);
        }
    }

    /**
     * Since when the learner's attempts count against the limit: the later of the start of the
     * certification cycle ($cycleStart, PassCriteriaService::attemptCycleStart(), null = no cycle
     * yet) and the moment the limit was switched on. null = every attempt counts. Every count,
     * notification id and clearing goes through here, so they always agree.
     */
    public static function countStart(Course $course, ?int $cycleStart): ?int {
        $limitSince = $course->getExamAttemptLimitSince();
        $starts = array_values(array_filter([$cycleStart, $limitSince !== null ? (int)$limitSince : null], static fn(?int $s): bool => $s !== null));
        return $starts === [] ? null : max($starts);
    }

    /** countStart() for one learner of the course. */
    public function attemptCountStart(string $userId, Course $course): ?int {
        return self::countStart($course, $this->passCriteria->attemptCycleStart($userId, (int)$course->getId()));
    }

    /**
     * Counting exam starts per pool of this course since $since (null = all).
     *
     * @return array<int, int> pool id => attempts
     */
    public function usedByPool(string $userId, int $courseId, ?int $since): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'n')
            ->from('learning_sessions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
            ->andWhere($qb->expr()->isNull('exam_kind'))
            ->groupBy('pool_id');
        if ($since !== null) {
            $qb->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        }
        $result = $qb->executeQuery();
        $out = [];
        while (($row = $result->fetch()) !== false) {
            if (!is_array($row)) {
                break;
            }
            $out[(int)$row['pool_id']] = (int)$row['n'];
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * What the learner sees (pass status, exam result): the limit and the attempts used per pool
     * in the current cycle. null when no limit applies to this learner.
     *
     * @return array{total_limit: int, used_by_pool: array<int, int>, cycle_since: int|null}|null
     */
    public function stateFor(string $userId, Course $course): ?array {
        $limit = self::limitFor($course);
        $courseId = (int)$course->getId();
        if ($limit === null || $this->isInstructorOfCourse($course, $userId)) {
            return null;
        }
        $since = $this->attemptCountStart($userId, $course);
        return [
            'total_limit' => $limit,
            'used_by_pool' => $this->usedByPool($userId, $courseId, $since),
            'cycle_since' => $since,
        ];
    }

    /**
     * The fields the learner's attempt_policy gets in addition to ExamAttemptPolicy::toArray()
     * (pass status, exam result): total_limit (null = unlimited) and the attempts used per pool
     * in the current cycle.
     *
     * @return array{total_limit: int|null, used_by_pool: array<int, int>}
     */
    public function policyFor(string $userId, Course $course): array {
        $state = $this->stateFor($userId, $course);
        return [
            'total_limit' => $state['total_limit'] ?? null,
            'used_by_pool' => $state['used_by_pool'] ?? [],
        ];
    }

    /**
     * Server-side gate for the start of a counting exam in a course (TrainingService). The
     * resume of a running exam never comes here.
     *
     * @throws ExamAttemptLimitException all attempts of the cycle are used up on this pool
     */
    public function assertCanStart(string $userId, int $courseId, int $poolId): void {
        try {
            $course = $this->courseMapper->findById($courseId);
        } catch (DoesNotExistException $e) {
            return; // the start itself refuses an unknown course
        }
        $limit = self::limitFor($course);
        if ($limit === null || $this->isInstructorOfCourse($course, $userId)) {
            return;
        }
        $since = $this->attemptCountStart($userId, $course);
        $used = $this->usedByPool($userId, $courseId, $since)[$poolId] ?? 0;
        if ($used < $limit) {
            return;
        }
        // Fallback for an attempt that never reached the complete call (abandoned, timed out).
        $this->notifyIfExhausted($userId, $course, $poolId, $since, $used, $limit);
        throw new ExamAttemptLimitException();
    }

    /**
     * After a counting exam was completed (CertificationTrigger): tell the instructors once the
     * learner has used up the attempts on this pool without meeting the requirement. Never throws.
     */
    public function afterExamCompleted(string $userId, int $courseId, int $poolId): void {
        try {
            $course = $this->courseMapper->findById($courseId);
            $limit = self::limitFor($course);
            if ($limit === null || $this->isInstructorOfCourse($course, $userId)) {
                return;
            }
            $since = $this->attemptCountStart($userId, $course);
            $used = $this->usedByPool($userId, $courseId, $since)[$poolId] ?? 0;
            if ($used >= $limit) {
                $this->notifyIfExhausted($userId, $course, $poolId, $since, $used, $limit);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Exam attempt limit check after completion failed: {msg}', ['msg' => $e->getMessage(), 'app' => 'learning']);
        }
    }

    /**
     * The pools on which the learner has used up the attempts of the current cycle and still has
     * not met the requirement (see blockingPools()). Empty without a limit.
     *
     * @return list<int>
     */
    public function exhaustedPools(string $userId, Course $course): array {
        $state = $this->stateFor($userId, $course);
        if ($state === null) {
            return [];
        }
        return $this->blockingPools($course, [$userId => self::atLimit($state['used_by_pool'], $state['total_limit'])])[$userId] ?? [];
    }

    /**
     * exhaustedPools() for all learners of the instructor's member list at once, from the rows
     * the caller already read: one query for the attempts of all of them, the pass rule only for
     * those at the limit. $learners must be students of the course (no instructors — the caller
     * filters by member role, as stateFor() would).
     *
     * @param array<string, array{certificate: Certificate|null, state: array{status: string, due_date: int|null, assigned_at: int, reopen_reason: string|null}|null}> $learners uid => newest certificate (revoked included) and active period
     * @return array<string, list<int>> uid => pools (only learners with any)
     */
    public function exhaustedPoolsForLearners(Course $course, array $learners): array {
        $limit = self::limitFor($course);
        if ($limit === null || $learners === []) {
            return [];
        }
        $since = [];
        foreach ($learners as $uid => $rows) {
            $since[(string)$uid] = self::countStart($course, $this->passCriteria->attemptCycleStartOf($rows['certificate'], $rows['state']));
        }
        $used = $this->usedByPoolForUsers((int)$course->getId(), $since);
        $candidates = [];
        foreach ($used as $uid => $byPool) {
            $pools = self::atLimit($byPool, $limit);
            if ($pools !== []) {
                $candidates[$uid] = $pools;
            }
        }
        return $this->blockingPools($course, $candidates);
    }

    /**
     * usedByPool() for several learners, each counted from their own cycle start.
     *
     * @param array<string, int|null> $since uid => cycle start (null = all attempts count)
     * @return array<string, array<int, int>> uid => pool id => attempts
     */
    private function usedByPoolForUsers(int $courseId, array $since): array {
        $bounds = array_filter($since, static fn(?int $s): bool => $s !== null);
        $lowest = count($bounds) === count($since) && $bounds !== [] ? min($bounds) : null;
        $out = [];
        foreach (array_chunk(array_map('strval', array_keys($since)), 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id', 'pool_id', 'started_at')
                ->from('learning_sessions')
                ->where($qb->expr()->in('user_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
                ->andWhere($qb->expr()->isNull('exam_kind'));
            if ($lowest !== null) {
                $qb->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($lowest, IQueryBuilder::PARAM_INT)));
            }
            $result = $qb->executeQuery();
            while (($row = $result->fetch()) !== false) {
                if (!is_array($row)) {
                    break;
                }
                $uid = (string)$row['user_id'];
                if (!array_key_exists($uid, $since)) {
                    continue;
                }
                $start = $since[$uid];
                if ($start !== null && (int)$row['started_at'] < $start) {
                    continue;
                }
                $poolId = (int)$row['pool_id'];
                $out[$uid][$poolId] = ($out[$uid][$poolId] ?? 0) + 1;
            }
            $result->closeCursor();
        }
        return $out;
    }

    /**
     * @param array<int, int> $usedByPool
     * @return list<int>
     */
    private static function atLimit(array $usedByPool, int $limit): array {
        $out = [];
        foreach ($usedByPool as $poolId => $used) {
            if ($used >= $limit) {
                $out[] = (int)$poolId;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Of the pools at the limit, those that still block the certificate — one rule for the
     * member list (CertificationCycleService) and the instructors' notification. A pool does not
     * block when the learner has met the requirement, has passed that pool, or the course
     * requires other pools and this one counts for nothing. Without that, a learner who passed a
     * pool on the last attempt was listed as stuck, and "open a new cycle" would have thrown the
     * pass away (after a revoke also the document confirmations).
     *
     * @param array<string, list<int>> $candidates uid => pools at the limit
     * @return array<string, list<int>> uid => blocking pools (only learners with any)
     */
    private function blockingPools(Course $course, array $candidates): array {
        $candidates = array_filter($candidates, static fn(array $pools): bool => $pools !== []);
        if ($candidates === []) {
            return [];
        }
        $standing = $this->passCriteria->examStandings((int)$course->getId(), array_map('strval', array_keys($candidates)));
        $required = array_map('intval', $standing['required_pool_ids'] ?? []);
        $out = [];
        foreach ($candidates as $uid => $pools) {
            $user = $standing['users'][(string)$uid] ?? null;
            if ($user !== null && $user['passed']) {
                continue;
            }
            $byPool = $user['pools'] ?? [];
            $blocking = array_values(array_filter($pools, static fn(int $poolId): bool =>
                !($byPool[$poolId]['passed'] ?? false)
                && ($required === [] || in_array($poolId, $required, true))));
            if ($blocking !== []) {
                $out[(string)$uid] = $blocking;
            }
        }
        return $out;
    }

    /**
     * Removes the instructors' "attempts used up" notifications of the cycle that started at
     * $since (attemptCountStart(), read before the new cycle opened) and the
     * learner's "already notified" markers — called once a new cycle started the count again
     * (CertificationCycleService).
     */
    public function clearNotifications(string $userId, int $courseId, ?int $since): void {
        try {
            foreach ($this->courseSummary->getCoursePoolIds($courseId) as $poolId) {
                $objectId = self::objectId($courseId, (int)$poolId, $userId, $since);
                $notification = $this->notificationManager->createNotification();
                $notification->setApp('learning')
                    ->setObject(self::NOTIFICATION_OBJECT, $objectId);
                $this->notificationManager->markProcessed($notification);
                $this->config->deleteUserValue($userId, 'learning', self::markerKey($objectId));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Clearing exam attempt notifications failed: {msg}', ['msg' => $e->getMessage(), 'app' => 'learning']);
        }
    }

    /**
     * The learner's preference that says the instructors were told about this pool and cycle, so
     * a dismissed notification is not sent again on every refused start. A preference of the
     * learner: it goes with the account.
     */
    private static function markerKey(string $objectId): string {
        return 'exam_attempts_notified_' . substr($objectId, 0, 40);
    }

    /**
     * One notification object per learner, pool and cycle. Hashed: the learner's uid must not
     * travel in the object id (and NC limits it to 64 characters).
     */
    public static function objectId(int $courseId, int $poolId, string $userId, ?int $since): string {
        return hash('sha1', 'learning:exam_attempts:v1:' . $courseId . ':' . $poolId . ':' . $userId . ':' . ($since ?? 0));
    }

    private function notifyIfExhausted(string $userId, Course $course, int $poolId, ?int $since, int $used, int $limit): void {
        try {
            $courseId = (int)$course->getId();
            // Requirement met, pool passed or not required: nobody needs to act (blockingPools()).
            if ($this->blockingPools($course, [$userId => [$poolId]]) === []) {
                return;
            }
            $recipients = [(string)$course->getInstructorId()];
            foreach ($this->memberMapper->findByCourse($courseId) as $member) {
                if ($member->getRole() === 'instructor') {
                    $recipients[] = (string)$member->getUserId();
                }
            }
            $recipients = array_values(array_unique(array_filter($recipients, static fn(string $r): bool => $r !== '' && $r !== $userId)));
            $objectId = self::objectId($courseId, $poolId, $userId, $since);
            if ($this->config->getUserValue($userId, 'learning', self::markerKey($objectId), '') !== '') {
                return; // told once for this pool and cycle, also if they dismissed it since
            }
            $poolTitle = $this->courseSummary->getPoolTitles([$poolId])[$poolId] ?? '';
            // The member row, never the uid (Notifier resolves the name): the row goes with the
            // account (UserDeletedListener), and an account created later under the same uid gets
            // a new row, so a notification left behind names nobody.
            $memberId = $this->memberIdOf($courseId, $userId);
            foreach ($recipients as $recipient) {
                try {
                    $notification = $this->notificationManager->createNotification();
                    $notification->setApp('learning')
                        ->setUser($recipient)
                        ->setObject(self::NOTIFICATION_OBJECT, $objectId)
                        ->setSubject(self::NOTIFICATION_SUBJECT, [
                            'course_id' => $courseId,
                            'course_title' => (string)$course->getTitle(),
                            'pool_title' => $poolTitle,
                            'member_id' => $memberId,
                            'attempts' => $used,
                            'limit' => $limit,
                        ]);
                    if ($this->notificationManager->getCount($notification) === 0) {
                        $notification->setDateTime($this->timeFactory->getDateTime());
                        $this->notificationManager->notify($notification);
                        // GitHub #87: the same event as an activity, once with the bell. Ids and
                        // titles only, the learner as member row (Provider reads the name).
                        $this->activities->toUser(ActivityPublisher::SUBJECT_EXAM_ATTEMPTS_EXHAUSTED, $recipient, $courseId, (string)$course->getTitle(), [
                            'pool_id' => $poolId,
                            'pool_title' => $poolTitle,
                            'member_id' => $memberId,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Exam attempt notification failed for one recipient: {msg}', ['msg' => $e->getMessage(), 'app' => 'learning']);
                }
            }
            $this->config->setUserValue($userId, 'learning', self::markerKey($objectId), (string)$this->timeFactory->getTime());
        } catch (\Throwable $e) {
            $this->logger->warning('Exam attempt notification failed: {msg}', ['msg' => $e->getMessage(), 'app' => 'learning']);
        }
    }

    private function memberIdOf(int $courseId, string $userId): int {
        try {
            return (int)$this->memberMapper->findByCourseAndUser($courseId, $userId)->getId();
        } catch (DoesNotExistException $e) {
            return 0;
        }
    }

    private function isInstructorOfCourse(Course $course, string $userId): bool {
        if ($course->getInstructorId() === $userId) {
            return true;
        }
        try {
            return $this->memberMapper->findByCourseAndUser((int)$course->getId(), $userId)->getRole() === 'instructor';
        } catch (DoesNotExistException $e) {
            return false;
        }
    }
}
