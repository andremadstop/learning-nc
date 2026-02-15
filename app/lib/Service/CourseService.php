<?php
namespace OCA\Learning\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class CourseService {
    private CourseMapper $courseMapper;
    private CoursePoolMapper $coursePoolMapper;
    private CourseMemberMapper $courseMemberMapper;
    private RoleService $roleService;
    private IDBConnection $db;

    public function __construct(
        CourseMapper $courseMapper,
        CoursePoolMapper $coursePoolMapper,
        CourseMemberMapper $courseMemberMapper,
        RoleService $roleService,
        IDBConnection $db
    ) {
        $this->courseMapper = $courseMapper;
        $this->coursePoolMapper = $coursePoolMapper;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->roleService = $roleService;
        $this->db = $db;
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

        // Add counts to own courses
        $ownData = [];
        foreach ($own as $course) {
            $data = $course->jsonSerialize();
            $data['pool_count'] = count($this->coursePoolMapper->findByCourse($course->getId()));
            $data['member_count'] = $this->courseMemberMapper->countByCourse($course->getId());
            $ownData[] = $data;
        }

        return ['own' => $ownData, 'enrolled' => $enrolled];
    }

    /**
     * Get course detail with pools and members
     */
    public function findById(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->hasAccess($course, $userId) && !$this->roleService->isInstructor($userId)) {
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
        if (strlen($title) < 1 || strlen($title) > 255) {
            throw new \Exception('Course title must be 1-255 characters');
        }

        $now = time();
        $course = new Course();
        $course->setTitle($title);
        $course->setDescription($description ? trim($description) : null);
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
            if (strlen($title) < 1 || strlen($title) > 255) {
                throw new \Exception('Course title must be 1-255 characters');
            }
            $course->setTitle($title);
        }
        if ($description !== null) {
            $course->setDescription(trim($description));
        }
        if ($status !== null && in_array($status, ['active', 'archived'])) {
            $course->setStatus($status);
        }
        $course->setUpdatedAt(time());

        return $this->courseMapper->update($course);
    }

    /**
     * Delete course (creator only)
     */
    public function delete(int $courseId, string $userId): void {
        $course = $this->courseMapper->findById($courseId);

        if ($course->getInstructorId() !== $userId) {
            throw new \Exception('Only the course creator can delete it');
        }

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

        // Verify pool exists
        if (!$this->poolExists($poolId)) {
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
     * Self-enroll in a course (if it has an NC group and user is in that group)
     */
    public function enroll(int $courseId, string $userId): CourseMember {
        $course = $this->courseMapper->findById($courseId);

        // Check if already enrolled
        try {
            return $this->courseMemberMapper->findByCourseAndUser($courseId, $userId);
        } catch (DoesNotExistException $e) {
            // proceed
        }

        $member = new CourseMember();
        $member->setCourseId($courseId);
        $member->setUserId($userId);
        $member->setRole('student');
        $member->setEnrolledAt(time());

        return $this->courseMemberMapper->insert($member);
    }

    /**
     * Get course progress for all students (instructor only)
     */
    public function getCourseProgress(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission');
        }

        $members = $this->courseMemberMapper->findByCourse($courseId);
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        $students = [];
        foreach ($members as $member) {
            if ($member->getRole() !== 'student') continue;

            $studentId = $member->getUserId();
            $poolProgress = [];

            foreach ($poolIds as $poolId) {
                // Get question count
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*)'))
                    ->from('learning_questions')
                    ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
                $result = $qb->executeQuery();
                $totalQuestions = (int)$result->fetchOne();
                $result->closeCursor();

                // Get Leitner mastery (box 5 = mastered)
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*)'))
                    ->from('learning_leitner_items')
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($studentId)))
                    ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
                    ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter(5)));
                $result = $qb->executeQuery();
                $mastered = (int)$result->fetchOne();
                $result->closeCursor();

                // Get accuracy from sessions
                $qb = $this->db->getQueryBuilder();
                $qb->select(
                        $qb->createFunction('COALESCE(SUM(total_questions), 0)'),
                        $qb->createFunction('COALESCE(SUM(correct_answers), 0)')
                    )
                    ->from('learning_sessions')
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($studentId)))
                    ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
                    ->andWhere($qb->expr()->isNotNull('completed_at'));
                $result = $qb->executeQuery();
                $row = $result->fetch();
                $result->closeCursor();
                $totalAnswered = (int)($row[0] ?? 0);
                $totalCorrect = (int)($row[1] ?? 0);
                $accuracy = $totalAnswered > 0 ? round($totalCorrect / $totalAnswered * 100) : 0;

                // Get last activity
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('MAX(completed_at)'))
                    ->from('learning_sessions')
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($studentId)))
                    ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
                $result = $qb->executeQuery();
                $lastActive = $result->fetchOne();
                $result->closeCursor();

                $poolName = $this->getPoolName($poolId);

                $poolProgress[] = [
                    'pool_id' => $poolId,
                    'pool_name' => $poolName,
                    'total_questions' => $totalQuestions,
                    'mastered' => $mastered,
                    'accuracy' => $accuracy,
                    'last_active' => $lastActive ? (int)$lastActive : null,
                ];
            }

            $students[] = [
                'user_id' => $studentId,
                'enrolled_at' => $member->getEnrolledAt(),
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
            $memberCount = $this->courseMemberMapper->countByCourse($courseId);

            $data = $course->jsonSerialize();
            $data['pool_count'] = $poolCount;
            $data['student_count'] = $memberCount;
            $result[] = $data;
        }

        return ['courses' => $result];
    }
}
