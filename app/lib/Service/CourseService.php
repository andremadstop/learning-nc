<?php
namespace OCA\Learning\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\ForbiddenException;

class CourseService {
    private CourseMapper $courseMapper;
    private CoursePoolMapper $coursePoolMapper;
    private CourseMemberMapper $courseMemberMapper;
    private RoleService $roleService;
    private IDBConnection $db;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private XpService $xpService;
    private BadgeService $badgeService;
    private StreakService $streakService;

    public function __construct(
        CourseMapper $courseMapper,
        CoursePoolMapper $coursePoolMapper,
        CourseMemberMapper $courseMemberMapper,
        RoleService $roleService,
        IDBConnection $db,
        IGroupManager $groupManager,
        IUserManager $userManager,
        XpService $xpService,
        BadgeService $badgeService,
        StreakService $streakService
    ) {
        $this->courseMapper = $courseMapper;
        $this->coursePoolMapper = $coursePoolMapper;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->roleService = $roleService;
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->xpService = $xpService;
        $this->badgeService = $badgeService;
        $this->streakService = $streakService;
    }

    /**
     * Get pool name by ID via direct query (avoids PoolMapper userId dependency)
     */
    private function getPoolName(int $poolId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')
            ->from('learning_pools')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId)));
        $result = $qb->executeQuery();
        $name = $result->fetchOne();
        $result->closeCursor();
        return $name ?: '(deleted)';
    }

    /**
     * Check pool exists
     */
    private function poolExists(int $poolId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('learning_pools')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count > 0;
    }

    /**
     * Check if user owns the pool or has edit-level share access
     */
    private function hasPoolAccess(int $poolId, string $userId): bool {
        // Check ownership
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('learning_pools')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        if ($count > 0) {
            return true;
        }

        // Check share with edit permission
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('learning_pool_shares')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('permission', $qb->createNamedParameter('edit')));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count > 0;
    }

    /**
     * Check if user is instructor of this course (creator or co-instructor member)
     */
    private function isInstructorOfCourse(Course $course, string $userId): bool {
        if ($course->getInstructorId() === $userId) {
            return true;
        }
        try {
            $member = $this->courseMemberMapper->findByCourseAndUser($course->getId(), $userId);
            return $member->getRole() === 'instructor';
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Check if user has any access to course (instructor, co-instructor, or enrolled student)
     */
    private function hasAccess(Course $course, string $userId): bool {
        if ($this->isInstructorOfCourse($course, $userId)) {
            return true;
        }
        try {
            $this->courseMemberMapper->findByCourseAndUser($course->getId(), $userId);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * List courses: own (as instructor) + enrolled (as student/co-instructor)
     */
    public function findAll(string $userId): array {
        $own = $this->courseMapper->findByInstructor($userId);
        $memberEntries = $this->courseMemberMapper->findByUser($userId);

        $enrolled = [];
        $ownIds = array_map(fn($c) => $c->getId(), $own);

        foreach ($memberEntries as $member) {
            if (!in_array($member->getCourseId(), $ownIds)) {
                try {
                    $course = $this->courseMapper->findById($member->getCourseId());
                    $courseData = $course->jsonSerialize();
                    $courseData['member_role'] = $member->getRole();
                    $enrolled[] = $courseData;
                } catch (DoesNotExistException $e) {
                    // orphaned membership, skip
                }
            }
        }

        // FIX-LO-3: Batch-load pool counts and member counts instead of N+1
        $ownData = [];
        if (!empty($own)) {
            $courseIds = array_map(fn($c) => $c->getId(), $own);

            // Batch pool counts
            $qb = $this->db->getQueryBuilder();
            $qb->select('course_id', $qb->createFunction('COUNT(*) as cnt'))
                ->from('learning_course_pools')
                ->where($qb->expr()->in('course_id', $qb->createNamedParameter($courseIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('course_id');
            $result = $qb->executeQuery();
            $poolCounts = [];
            while ($row = $result->fetch()) {
                $poolCounts[(int)$row['course_id']] = (int)$row['cnt'];
            }
            $result->closeCursor();

            // Batch member counts
            $qb = $this->db->getQueryBuilder();
            $qb->select('course_id', $qb->createFunction('COUNT(*) as cnt'))
                ->from('learning_course_members')
                ->where($qb->expr()->in('course_id', $qb->createNamedParameter($courseIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('course_id');
            $result = $qb->executeQuery();
            $memberCounts = [];
            while ($row = $result->fetch()) {
                $memberCounts[(int)$row['course_id']] = (int)$row['cnt'];
            }
            $result->closeCursor();

            foreach ($own as $course) {
                $data = $course->jsonSerialize();
                $data['pool_count'] = $poolCounts[$course->getId()] ?? 0;
                $data['member_count'] = $memberCounts[$course->getId()] ?? 0;
                $ownData[] = $data;
            }
        }

        return ['own' => $ownData, 'enrolled' => $enrolled];
    }

    /**
     * Get course detail with pools and members
     */
    public function findById(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        // SEC-HIGH-2: Only allow access if user is member/instructor OF THIS course (no global instructor fallback)
        if (!$this->hasAccess($course, $userId)) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Course not found');
        }

        $isInstructor = $this->isInstructorOfCourse($course, $userId);
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);

        // Enrich pools with pool name and question count
        $poolsData = [];
        foreach ($coursePools as $cp) {
            $cpData = $cp->jsonSerialize();
            try {
                $cpData['pool_name'] = $this->getPoolName($cp->getPoolId());
                // Count questions via direct query
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*)'))
                    ->from('learning_questions')
                    ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($cp->getPoolId())));
                $result = $qb->executeQuery();
                $cpData['question_count'] = (int)$result->fetchOne();
                $result->closeCursor();
            } catch (DoesNotExistException $e) {
                $cpData['pool_name'] = '(deleted)';
                $cpData['question_count'] = 0;
            }
            $poolsData[] = $cpData;
        }

        $result = $course->jsonSerialize();
        $result['pools'] = $poolsData;
        $result['is_instructor'] = $isInstructor;

        // Only instructors see member list
        if ($isInstructor) {
            $result['members'] = array_map(fn($m) => $m->jsonSerialize(),
                $this->courseMemberMapper->findByCourse($courseId));
            $result['member_count'] = count($result['members']);
        }

        return $result;
    }

    /**
     * Create a new course (instructor only)
     */
    public function create(string $title, ?string $description, ?string $ncGroupId, string $userId): Course {
        if (!$this->roleService->isInstructor($userId)) {
            throw new \Exception('Only instructors can create courses');
        }

        $title = trim($title);
        // SEC-MED-3: Use mb_strlen for multibyte-safe length check
        if (mb_strlen($title) < 1 || mb_strlen($title) > 255) {
            throw new \Exception('Course title must be 1-255 characters');
        }

        // SEC-MED-3: Limit description length to prevent DB bloat
        $descTrimmed = $description ? trim($description) : null;
        if ($descTrimmed !== null && mb_strlen($descTrimmed) > 5000) {
            throw new \Exception('Course description must not exceed 5000 characters');
        }

        $now = time();
        $course = new Course();
        $course->setTitle($title);
        $course->setDescription($descTrimmed);
        $course->setInstructorId($userId);
        $course->setNcGroupId($ncGroupId ?: null);
        $course->setStatus('active');
        $course->setCreatedAt($now);
        $course->setUpdatedAt($now);

        return $this->courseMapper->insert($course);
    }

    /**
     * Update course (instructor only)
     */
    public function update(int $courseId, ?string $title, ?string $description, ?string $status, string $userId): Course {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission to update this course');
        }

        if ($title !== null) {
            $title = trim($title);
            // SEC-MED-3: Use mb_strlen for multibyte-safe length check
            if (mb_strlen($title) < 1 || mb_strlen($title) > 255) {
                throw new \Exception('Course title must be 1-255 characters');
            }
            $course->setTitle($title);
        }
        if ($description !== null) {
            $descTrimmed = trim($description);
            // SEC-MED-3: Limit description length
            if (mb_strlen($descTrimmed) > 5000) {
                throw new \Exception('Course description must not exceed 5000 characters');
            }
            $course->setDescription($descTrimmed);
        }
        if ($status !== null && in_array($status, ['active', 'archived'])) {
            $course->setStatus($status);
        }
        $course->setUpdatedAt(time());

        return $this->courseMapper->update($course);
    }

    /**
     * Delete course (creator only)
     * FIX3-HI-2: Clean up course_pools and course_members before deleting course
     */
    public function delete(int $courseId, string $userId): void {
        $course = $this->courseMapper->findById($courseId);

        if ($course->getInstructorId() !== $userId) {
            throw new \Exception('Only the course creator can delete it');
        }

        // Delete related records (no FK CASCADE exists)
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_course_pools')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)));
        $qb->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_course_members')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)));
        $qb->executeStatement();

        $this->courseMapper->delete($course);
    }

    /**
     * Add a pool to a course
     */
    public function addPool(int $courseId, int $poolId, int $sortOrder, bool $required, string $userId): CoursePool {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission');
        }

        // FIX-HI-2: Verify pool exists AND user has access (prevents IDOR)
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found');
        }

        $cp = new CoursePool();
        $cp->setCourseId($courseId);
        $cp->setPoolId($poolId);
        $cp->setSortOrder($sortOrder);
        $cp->setRequired($required);

        return $this->coursePoolMapper->insert($cp);
    }

    /**
     * Remove a pool from a course
     */
    public function removePool(int $courseId, int $poolId, string $userId): void {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission');
        }

        $cp = $this->coursePoolMapper->findByCourseAndPool($courseId, $poolId);
        $this->coursePoolMapper->delete($cp);
    }

    /**
     * Get members of a course (instructor only)
     */
    public function getMembers(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission');
        }

        return array_map(fn($m) => $m->jsonSerialize(),
            $this->courseMemberMapper->findByCourse($courseId));
    }

    /**
     * Add a member to a course (instructor only)
     */
    public function addMember(int $courseId, string $memberId, string $role, string $userId): CourseMember {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission');
        }

        if (!in_array($role, ['student', 'instructor'])) {
            $role = 'student';
        }

        // TODO: Validate that memberId is an existing Nextcloud user
        if (!$this->userManager->userExists($memberId)) {
            throw new \Exception('User does not exist');
        }

        // Check if already a member
        try {
            $existing = $this->courseMemberMapper->findByCourseAndUser($courseId, $memberId);
            // Update role if different
            if ($existing->getRole() !== $role) {
                $existing->setRole($role);
                return $this->courseMemberMapper->update($existing);
            }
            return $existing;
        } catch (DoesNotExistException $e) {
            // New member
        }

        $member = new CourseMember();
        $member->setCourseId($courseId);
        $member->setUserId($memberId);
        $member->setRole($role);
        $member->setEnrolledAt(time());

        return $this->courseMemberMapper->insert($member);
    }

    /**
     * Remove a member from a course (instructor only)
     * $memberId can be a numeric row ID or a username string
     */
    public function removeMember(int $courseId, string $memberId, string $userId): void {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission');
        }

        // Try numeric row ID first (frontend sends member.id), then fall back to username
        if (ctype_digit($memberId)) {
            $member = $this->courseMemberMapper->findById((int)$memberId);
            if ($member->getCourseId() !== $courseId) {
                throw new DoesNotExistException('Member not found in this course');
            }
        } else {
            $member = $this->courseMemberMapper->findByCourseAndUser($courseId, $memberId);
        }

        $this->courseMemberMapper->delete($member);
    }

    /**
     * Self-enroll in a course (if it has an NC group, user must be in that group)
     */
    public function enroll(int $courseId, string $userId): CourseMember {
        $course = $this->courseMapper->findById($courseId);

        // TODO: Check existing membership first — already-enrolled users should not be blocked
        try {
            return $this->courseMemberMapper->findByCourseAndUser($courseId, $userId);
        } catch (DoesNotExistException $e) {
            // proceed to new enrollment
        }

        // SEC-HIGH-1: If course has an NC group restriction, verify membership (only for new enrollments)
        $ncGroupId = $course->getNcGroupId();
        if ($ncGroupId !== null && $ncGroupId !== '') {
            if (!$this->groupManager->isInGroup($userId, $ncGroupId)) {
                throw new \Exception('You are not in the required group for this course');
            }
        }

        $member = new CourseMember();
        $member->setCourseId($courseId);
        $member->setUserId($userId);
        $member->setRole('student');
        $member->setEnrolledAt(time());

        return $this->courseMemberMapper->insert($member);
    }

    /** @var array<string, string> Request-local display name cache */
    private array $displayNameCache = [];

    /**
     * Resolve display name for a user ID (cached per request).
     */
    private function getDisplayName(string $userId): string {
        if (isset($this->displayNameCache[$userId])) {
            return $this->displayNameCache[$userId];
        }
        $user = $this->userManager->get($userId);
        $name = $user ? $user->getDisplayName() : $userId;
        $this->displayNameCache[$userId] = $name;
        return $name;
    }

    /**
     * Batch-load pool names for a set of pool IDs.
     */
    private function getPoolNames(array $poolIds): array {
        if (empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('learning_pools')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $names = [];
        while ($row = $result->fetch()) {
            $names[(int)$row['id']] = $row['name'];
        }
        $result->closeCursor();
        return $names;
    }

    /**
     * Batch-load question counts per pool.
     */
    private function getQuestionCounts(array $poolIds): array {
        if (empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id', $qb->createFunction('COUNT(*) as cnt'))
            ->from('learning_questions')
            ->where($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('pool_id');
        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(int)$row['pool_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $counts;
    }

    /**
     * Batch-load Leitner mastery (box 5 count) per user per pool.
     * Returns: [user_id => [pool_id => mastered_count]]
     */
    private function getBatchMastery(array $studentIds, array $poolIds): array {
        if (empty($studentIds) || empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'pool_id', $qb->createFunction('COUNT(*) as cnt'))
            ->from('learning_leitner_items')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter(5)))
            ->groupBy('user_id', 'pool_id');
        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[$row['user_id']][(int)$row['pool_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $data;
    }

    /**
     * Batch-load session stats (accuracy + last active) per user per pool.
     * Returns: [user_id => [pool_id => [total_q, correct, last_active]]]
     */
    private function getBatchSessionStats(array $studentIds, array $poolIds): array {
        if (empty($studentIds) || empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'user_id', 'pool_id',
                $qb->createFunction('COALESCE(SUM(total_questions), 0) as total_q'),
                $qb->createFunction('COALESCE(SUM(correct_answers), 0) as correct'),
                $qb->createFunction('MAX(completed_at) as last_active')
            )
            ->from('learning_sessions')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('completed_at'))
            ->groupBy('user_id', 'pool_id');
        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[$row['user_id']][(int)$row['pool_id']] = [
                'total_q' => (int)$row['total_q'],
                'correct' => (int)$row['correct'],
                'last_active' => $row['last_active'] ? (int)$row['last_active'] : null,
            ];
        }
        $result->closeCursor();
        return $data;
    }

    /**
     * Batch-load user stats (XP, level, streak, etc.) for a set of user IDs.
     * Returns: [user_id => [total_xp, current_level, current_streak, total_sessions, last_activity_date]]
     */
    private function getBatchUserStats(array $userIds): array {
        if (empty($userIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'total_xp', 'current_level', 'current_streak', 'total_sessions', 'total_mastered', 'last_activity_date')
            ->from('learning_user_stats')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[$row['user_id']] = [
                'total_xp' => (int)$row['total_xp'],
                'current_level' => (int)$row['current_level'],
                'current_streak' => (int)$row['current_streak'],
                'total_sessions' => (int)$row['total_sessions'],
                'total_mastered' => (int)$row['total_mastered'],
                'last_activity_date' => $row['last_activity_date'],
            ];
        }
        $result->closeCursor();
        return $data;
    }

    /**
     * Get course progress — instructors see all students with stats, students see only their own.
     * Uses batch queries to avoid N+1 problem.
     */
    public function getCourseProgress(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);
        $isInstructor = $this->isInstructorOfCourse($course, $userId);

        if (!$isInstructor && !$this->hasAccess($course, $userId)) {
            throw new \Exception('No permission');
        }

        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        // Determine which students to include
        if ($isInstructor) {
            $members = $this->courseMemberMapper->findByCourse($courseId);
            $studentMembers = array_filter($members, fn($m) => $m->getRole() === 'student');
        } else {
            try {
                $member = $this->courseMemberMapper->findByCourseAndUser($courseId, $userId);
                $studentMembers = [$member];
            } catch (DoesNotExistException $e) {
                $studentMembers = [];
            }
        }

        $studentIds = array_map(fn($m) => $m->getUserId(), $studentMembers);
        $enrolledAtMap = [];
        foreach ($studentMembers as $m) {
            $enrolledAtMap[$m->getUserId()] = $m->getEnrolledAt();
        }

        if (empty($studentIds)) {
            return ['students' => []];
        }

        // Empty pools: return students with stats but no pool progress
        if (empty($poolIds)) {
            $userStats = $this->getBatchUserStats($studentIds);
            $students = [];
            foreach ($studentIds as $sid) {
                $stats = $userStats[$sid] ?? null;
                $students[] = [
                    'user_id' => $sid,
                    'display_name' => $this->getDisplayName($sid),
                    'enrolled_at' => $enrolledAtMap[$sid] ?? null,
                    'total_xp' => $stats ? $stats['total_xp'] : 0,
                    'current_level' => $stats ? $stats['current_level'] : 1,
                    'current_streak' => $stats ? $stats['current_streak'] : 0,
                    'last_activity_date' => $stats ? $stats['last_activity_date'] : null,
                    'pools' => [],
                ];
            }
            return ['students' => $students];
        }

        // Batch queries (replaces N×4 per student per pool)
        $poolNames = $this->getPoolNames($poolIds);
        $questionCounts = $this->getQuestionCounts($poolIds);
        $masteryData = $this->getBatchMastery($studentIds, $poolIds);
        $sessionData = $this->getBatchSessionStats($studentIds, $poolIds);
        $userStats = $this->getBatchUserStats($studentIds);

        // Assemble per-student results
        $students = [];
        foreach ($studentIds as $sid) {
            $poolProgress = [];
            foreach ($poolIds as $pid) {
                $totalQ = $questionCounts[$pid] ?? 0;
                $mastered = $masteryData[$sid][$pid] ?? 0;
                $sess = $sessionData[$sid][$pid] ?? null;
                $totalAnswered = $sess ? $sess['total_q'] : 0;
                $totalCorrect = $sess ? $sess['correct'] : 0;
                $accuracy = $totalAnswered > 0 ? round($totalCorrect / $totalAnswered * 100) : 0;

                $poolProgress[] = [
                    'pool_id' => $pid,
                    'pool_name' => $poolNames[$pid] ?? '(deleted)',
                    'total_questions' => $totalQ,
                    'mastered' => $mastered,
                    'accuracy' => $accuracy,
                    'last_active' => $sess ? $sess['last_active'] : null,
                ];
            }

            $stats = $userStats[$sid] ?? null;
            $students[] = [
                'user_id' => $sid,
                'display_name' => $this->getDisplayName($sid),
                'enrolled_at' => $enrolledAtMap[$sid] ?? null,
                'total_xp' => $stats ? $stats['total_xp'] : 0,
                'current_level' => $stats ? $stats['current_level'] : 1,
                'current_streak' => $stats ? $stats['current_streak'] : 0,
                'last_activity_date' => $stats ? $stats['last_activity_date'] : null,
                'pools' => $poolProgress,
            ];
        }

        return ['students' => $students];
    }

    /**
     * Instructor dashboard: overview of all courses with aggregate stats
     */
    public function getDashboard(string $userId): array {
        if (!$this->roleService->isInstructor($userId)) {
            throw new \Exception('Not an instructor');
        }

        $courses = $this->courseMapper->findByInstructor($userId);
        $result = [];

        foreach ($courses as $course) {
            $courseId = $course->getId();
            $poolCount = count($this->coursePoolMapper->findByCourse($courseId));
            $memberCount = $this->courseMemberMapper->countStudentsByCourse($courseId);

            $data = $course->jsonSerialize();
            $data['pool_count'] = $poolCount;
            $data['student_count'] = $memberCount;
            $result[] = $data;
        }

        return ['courses' => $result];
    }

    /**
     * Get course leaderboard — sorted by XP descending.
     * Instructors see all fields; students see limited fields (privacy).
     */
    public function getLeaderboard(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->hasAccess($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $isInstructor = $this->isInstructorOfCourse($course, $userId);

        // Top 100 via SQL — avoids loading all students into PHP
        $qb = $this->db->getQueryBuilder();
        $qb->select('cm.user_id')
            ->addSelect($qb->createFunction('COALESCE(us.total_xp, 0) AS total_xp'))
            ->addSelect($qb->createFunction('COALESCE(us.current_level, 1) AS current_level'))
            ->addSelect($qb->createFunction('COALESCE(us.total_mastered, 0) AS total_mastered'))
            ->addSelect($qb->createFunction('COALESCE(us.current_streak, 0) AS current_streak'))
            ->addSelect($qb->createFunction('COALESCE(us.total_sessions, 0) AS total_sessions'))
            ->addSelect('us.last_activity_date')
            ->from('learning_course_members', 'cm')
            ->leftJoin('cm', 'learning_user_stats', 'us', $qb->expr()->eq('cm.user_id', 'us.user_id'))
            ->where($qb->expr()->eq('cm.course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('cm.role', $qb->createNamedParameter('student')))
            ->orderBy('total_xp', 'DESC')
            ->addOrderBy('current_level', 'DESC')
            ->addOrderBy('cm.user_id', 'ASC')
            ->setMaxResults(100);
        $result = $qb->executeQuery();

        $entries = [];
        $rank = 0;
        while ($row = $result->fetch()) {
            $rank++;
            $entries[] = [
                'user_id' => $row['user_id'],
                'display_name' => $this->getDisplayName($row['user_id']),
                'total_xp' => (int)$row['total_xp'],
                'current_level' => (int)$row['current_level'],
                'total_mastered' => (int)$row['total_mastered'],
                'current_streak' => (int)$row['current_streak'],
                'total_sessions' => (int)$row['total_sessions'],
                'last_activity_date' => $row['last_activity_date'],
                'rank' => $rank,
            ];
        }
        $result->closeCursor();

        // Calculate requesting user's rank (only for students, not instructors)
        $myRank = null;
        if (!$isInstructor) {
            foreach ($entries as $entry) {
                if ($entry['user_id'] === $userId) {
                    $myRank = $entry['rank'];
                    break;
                }
            }
            if ($myRank === null) {
                // User not in top 100 — count students ranked higher (XP DESC, Level DESC, user_id ASC)
                $qb2 = $this->db->getQueryBuilder();
                $qb2->select($qb2->createFunction('COUNT(*) AS cnt'))
                    ->from('learning_course_members', 'cm')
                    ->leftJoin('cm', 'learning_user_stats', 'us', $qb2->expr()->eq('cm.user_id', 'us.user_id'))
                    ->leftJoin('cm', 'learning_user_stats', 'my', $qb2->expr()->andX(
                        $qb2->expr()->eq('my.user_id', $qb2->createNamedParameter($userId))
                    ))
                    ->where($qb2->expr()->eq('cm.course_id', $qb2->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb2->expr()->eq('cm.role', $qb2->createNamedParameter('student')))
                    ->andWhere($qb2->expr()->orX(
                        // Higher XP
                        $qb2->expr()->gt(
                            $qb2->createFunction('COALESCE(us.total_xp, 0)'),
                            $qb2->createFunction('COALESCE(my.total_xp, 0)')
                        ),
                        // Same XP, higher level
                        $qb2->expr()->andX(
                            $qb2->expr()->eq(
                                $qb2->createFunction('COALESCE(us.total_xp, 0)'),
                                $qb2->createFunction('COALESCE(my.total_xp, 0)')
                            ),
                            $qb2->expr()->gt(
                                $qb2->createFunction('COALESCE(us.current_level, 1)'),
                                $qb2->createFunction('COALESCE(my.current_level, 1)')
                            )
                        ),
                        // Same XP + level, earlier user_id (stable sort)
                        $qb2->expr()->andX(
                            $qb2->expr()->eq(
                                $qb2->createFunction('COALESCE(us.total_xp, 0)'),
                                $qb2->createFunction('COALESCE(my.total_xp, 0)')
                            ),
                            $qb2->expr()->eq(
                                $qb2->createFunction('COALESCE(us.current_level, 1)'),
                                $qb2->createFunction('COALESCE(my.current_level, 1)')
                            ),
                            $qb2->expr()->lt('cm.user_id', $qb2->createNamedParameter($userId))
                        )
                    ));
                $r2 = $qb2->executeQuery();
                $above = (int)$r2->fetchOne();
                $r2->closeCursor();
                $myRank = $above + 1;
            }
        }

        // Privacy: students see limited fields (no user_id, no streak, no sessions)
        if (!$isInstructor) {
            $entries = array_map(function ($entry) use ($userId) {
                return [
                    'rank' => $entry['rank'],
                    'display_name' => $entry['display_name'],
                    'is_me' => $entry['user_id'] === $userId,
                    'total_xp' => $entry['total_xp'],
                    'current_level' => $entry['current_level'],
                    'total_mastered' => $entry['total_mastered'],
                ];
            }, $entries);
        }

        return ['leaderboard' => $entries, 'my_rank' => $myRank];
    }

    /**
     * Get detailed student view for instructors.
     * Returns XP, badges, streak, Leitner boxes per pool, recent sessions.
     */
    public function getStudentDetail(int $courseId, string $studentId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        // Access: only instructors of this course
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        // Verify student is enrolled in this course with student role
        try {
            $member = $this->courseMemberMapper->findByCourseAndUser($courseId, $studentId);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException('Student not found in this course');
        }
        if ($member->getRole() !== 'student') {
            throw new NotFoundException('Student not found in this course');
        }

        // Get course pools
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        // 1. XP + Level (global — intentional, shows full gamification profile)
        $xpData = $this->xpService->calculateXp($studentId);

        // 2. Badges (global)
        $badges = $this->badgeService->getUserBadges($studentId);
        $badgeProgress = $this->badgeService->getBadgeProgress($studentId);

        // 3. Streak (global)
        $streak = $this->streakService->getStreak($studentId);

        // 4. Leitner boxes per course pool (course-scoped)
        $poolsData = [];
        if (!empty($poolIds)) {
            $poolNames = $this->getPoolNames($poolIds);
            $questionCounts = $this->getQuestionCounts($poolIds);

            // Batch: all boxes per pool for this student
            $qb = $this->db->getQueryBuilder();
            $qb->select('pool_id', 'box', $qb->createFunction('COUNT(*) as cnt'))
                ->from('learning_leitner_items')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($studentId)))
                ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('pool_id', 'box');
            $result = $qb->executeQuery();
            $boxData = [];
            while ($row = $result->fetch()) {
                $boxData[(int)$row['pool_id']][(int)$row['box']] = (int)$row['cnt'];
            }
            $result->closeCursor();

            // Batch: accuracy per pool
            $sessionStats = $this->getBatchSessionStats([$studentId], $poolIds);
            $studentSessions = $sessionStats[$studentId] ?? [];

            foreach ($poolIds as $pid) {
                $boxes = $boxData[$pid] ?? [];
                $total = array_sum($boxes);
                $mastered = $boxes[5] ?? 0;
                $sess = $studentSessions[$pid] ?? null;
                $totalAnswered = $sess ? $sess['total_q'] : 0;
                $totalCorrect = $sess ? $sess['correct'] : 0;
                $accuracy = $totalAnswered > 0 ? (int)round($totalCorrect / $totalAnswered * 100) : 0;

                $poolsData[] = [
                    'pool_id' => $pid,
                    'pool_name' => $poolNames[$pid] ?? '(deleted)',
                    'boxes' => [
                        'box_1' => $boxes[1] ?? 0,
                        'box_2' => $boxes[2] ?? 0,
                        'box_3' => $boxes[3] ?? 0,
                        'box_4' => $boxes[4] ?? 0,
                        'box_5' => $boxes[5] ?? 0,
                    ],
                    'total' => $total,
                    'mastered' => $mastered,
                    'mastery_pct' => $total > 0 ? (int)round($mastered / $total * 100) : 0,
                    'accuracy' => $accuracy,
                ];
            }
        }

        // 5. Recent sessions (course-scoped, last 10)
        $recentSessions = [];
        if (!empty($poolIds)) {
            $poolNames = $poolNames ?? $this->getPoolNames($poolIds);
            $qb = $this->db->getQueryBuilder();
            $qb->select('pool_id', 'mode', 'total_questions', 'correct_answers', 'completed_at')
                ->from('learning_sessions')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($studentId)))
                ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->isNotNull('completed_at'))
                ->orderBy('completed_at', 'DESC')
                ->setMaxResults(10);
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $recentSessions[] = [
                    'pool_name' => $poolNames[(int)$row['pool_id']] ?? '(deleted)',
                    'mode' => $row['mode'],
                    'score' => $row['correct_answers'] . '/' . $row['total_questions'],
                    'completed_at' => (int)$row['completed_at'],
                ];
            }
            $result->closeCursor();
        }

        // Stats from denormalized table
        $statsData = $this->getBatchUserStats([$studentId]);
        $stats = $statsData[$studentId] ?? null;

        return [
            'user_id' => $studentId,
            'display_name' => $this->getDisplayName($studentId),
            'xp' => $xpData,
            'streak' => $streak,
            'badges' => $badges,
            'badge_progress' => $badgeProgress,
            'pools' => $poolsData,
            'recent_sessions' => $recentSessions,
            'stats' => [
                'total_sessions' => $stats ? $stats['total_sessions'] : 0,
                'total_mastered' => $stats ? $stats['total_mastered'] : 0,
                'enrolled_at' => $member->getEnrolledAt(),
            ],
        ];
    }
}
