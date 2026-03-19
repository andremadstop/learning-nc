<?php
namespace OCA\Learning\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CurriculumScope;
use OCA\Learning\Db\CurriculumScopeMapper;
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
    private CurriculumScopeMapper $curriculumScopeMapper;

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
        StreakService $streakService,
        CurriculumScopeMapper $curriculumScopeMapper
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
        $this->curriculumScopeMapper = $curriculumScopeMapper;
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

    private function getPoolSnapshot(int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'name',
            'handbook_key',
            'handbook_title',
            'chapter_key',
            'chapter_title',
            'chapter_order'
        )
            ->from('learning_pools')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new DoesNotExistException('Pool not found');
        }

        return [
            'pool_name' => $row['name'] ?: '(deleted)',
            'handbook_key' => $row['handbook_key'] ?? null,
            'handbook_title' => $row['handbook_title'] ?? null,
            'chapter_key' => $row['chapter_key'] ?? null,
            'chapter_title' => $row['chapter_title'] ?? null,
            'chapter_order' => isset($row['chapter_order']) ? (int)$row['chapter_order'] : null,
        ];
    }

    private function getAvailableContentLanguagesByPoolIds(array $poolIds): array {
        $poolIds = array_values(array_unique(array_map('intval', $poolIds)));
        if ($poolIds === []) {
            return [];
        }

        $languageOrder = ['de', 'en', 'ru', 'ar'];
        $available = [];
        foreach ($poolIds as $poolId) {
            $totalQuestions = $this->countQuestionsInPool($poolId);
            $langs = ['de'];
            foreach (['en', 'ru', 'ar'] as $lang) {
                if ($this->hasPoolQuestionTranslations($poolId, $lang, $totalQuestions)) {
                    $langs[] = $lang;
                }
            }
            usort($langs, static fn(string $a, string $b): int => array_search($a, $languageOrder, true) <=> array_search($b, $languageOrder, true));
            $available[$poolId] = $langs;
        }

        return $available;
    }

    private function hasPoolQuestionTranslations(int $poolId, string $lang, int $totalQuestions): bool {
        if ($totalQuestions <= 0) {
            return false;
        }

        $inner = method_exists($this->db, 'getInner') ? $this->db->getInner() : $this->db;
        $prefix = method_exists($inner, 'getPrefix') ? $inner->getPrefix() : 'oc_';
        $result = $this->db->executeQuery(
            "SELECT COUNT(DISTINCT qt.question_id) AS translated_questions
             FROM {$prefix}learning_qst_translations qt
             INNER JOIN {$prefix}learning_questions q ON qt.question_id = q.id
             WHERE q.pool_id = ? AND qt.lang = ?",
            [$poolId, $lang]
        );
        $translatedQuestions = (int)$result->fetchOne();
        $result->closeCursor();

        $requiredForVisibility = max(1, (int)ceil($totalQuestions * 0.95));
        return $translatedQuestions >= $requiredForVisibility;
    }

    private function normalizeCoursePoolFilterValue(?string $value, int $maxLength): ?string {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Course pool filter exceeds maximum length');
        }
        return $value;
    }

    private function normalizeCoursePoolQuestionIds(?array $questionIds, int $poolId): ?string {
        if ($questionIds === null) {
            return null;
        }

        $normalized = [];
        foreach ($questionIds as $questionId) {
            $questionId = (int)$questionId;
            if ($questionId > 0) {
                $normalized[] = $questionId;
            }
        }
        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('learning_questions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($normalized, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $validIds = array_map('intval', array_column($result->fetchAll(), 'id'));
        $result->closeCursor();
        sort($validIds);

        if (count($validIds) !== count($normalized)) {
            throw new \InvalidArgumentException('Question filter contains IDs outside this pool');
        }

        return json_encode($validIds);
    }

    private function decodeCoursePoolQuestionIds(?string $json): array {
        if (!$json) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $decoded), static fn(int $id): bool => $id > 0));
    }

    private function getFilteredQuestionIdsForCoursePoolEntity(CoursePool $coursePool): array {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('id')
            ->from('learning_questions')
            ->where($expr->eq('pool_id', $qb->createNamedParameter($coursePool->getPoolId())));

        $filterExamKey = $coursePool->getFilterExamKey();
        if ($filterExamKey !== null && $filterExamKey !== '') {
            $qb->andWhere($expr->eq('exam_key', $qb->createNamedParameter($filterExamKey)));
        }

        $filterChapterKey = $coursePool->getFilterChapterKey();
        if ($filterChapterKey !== null && $filterChapterKey !== '') {
            $qb->andWhere($expr->eq('chapter_key', $qb->createNamedParameter($filterChapterKey)));
        }

        $questionIds = $this->decodeCoursePoolQuestionIds($coursePool->getFilterQuestionIds());
        if ($questionIds !== []) {
            $qb->andWhere($expr->in('id', $qb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $qb->orderBy('created_at', 'DESC');
        $result = $qb->executeQuery();
        $ids = array_map('intval', array_column($result->fetchAll(), 'id'));
        $result->closeCursor();
        return $ids;
    }

    private function getFilterOptionsForPool(int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('exam_key')
            ->from('learning_questions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->isNotNull('exam_key'))
            ->orderBy('exam_key', 'ASC');
        $result = $qb->executeQuery();
        $examKeys = array_values(array_filter(array_column($result->fetchAll(), 'exam_key')));
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select('chapter_key', 'chapter_title', 'chapter_order')
            ->from('learning_questions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->isNotNull('chapter_key'))
            ->groupBy('chapter_key')
            ->addGroupBy('chapter_title')
            ->addGroupBy('chapter_order')
            ->orderBy('chapter_order', 'ASC')
            ->addOrderBy('chapter_title', 'ASC');
        $result = $qb->executeQuery();
        $chapters = [];
        while ($row = $result->fetch()) {
            $chapterKey = trim((string)($row['chapter_key'] ?? ''));
            if ($chapterKey === '') {
                continue;
            }
            $chapters[] = [
                'key' => $chapterKey,
                'title' => $row['chapter_title'] ?: $chapterKey,
                'order' => isset($row['chapter_order']) ? (int)$row['chapter_order'] : null,
            ];
        }
        $result->closeCursor();

        return [
            'exam_keys' => $examKeys,
            'chapters' => $chapters,
        ];
    }

    private function getTouchedQuestionIdsForUser(array $questionIds, string $userId): array {
        if ($questionIds === []) {
            return [];
        }

        $touched = [];

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('question_id')
            ->from('learning_user_answers', 'ua')
            ->innerJoin('ua', 'learning_sessions', 's', $qb->expr()->eq('ua.session_id', 's.id'))
            ->where($qb->expr()->in('question_id', $qb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->andWhere($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        foreach ($result->fetchAll() as $row) {
            $touched[(int)$row['question_id']] = true;
        }
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('question_id')
            ->from('learning_leitner_items')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('question_id', $qb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->gt('correct_count', $qb->createNamedParameter(0)),
                $qb->expr()->gt('incorrect_count', $qb->createNamedParameter(0))
            ));
        $result = $qb->executeQuery();
        foreach ($result->fetchAll() as $row) {
            $touched[(int)$row['question_id']] = true;
        }
        $result->closeCursor();

        return array_keys($touched);
    }

    private function buildRequiredProgressMap(array $coursePools, string $userId): array {
        $progressMap = [];
        foreach ($coursePools as $coursePool) {
            $questionIds = $this->getFilteredQuestionIdsForCoursePoolEntity($coursePool);
            $touchedCount = count($this->getTouchedQuestionIdsForUser($questionIds, $userId));
            $totalCount = count($questionIds);
            $completed = $totalCount === 0 ? true : $touchedCount >= $totalCount;
            $progressMap[$coursePool->getPoolId()] = [
                'filtered_question_count' => $totalCount,
                'required_progress_count' => $touchedCount,
                'required_progress_percent' => $totalCount === 0 ? 100 : (int)floor(($touchedCount / max(1, $totalCount)) * 100),
                'required_completed' => $completed,
            ];
        }
        return $progressMap;
    }

    private function getOutstandingRequiredPools(array $coursePools, string $userId): array {
        $progressMap = $this->buildRequiredProgressMap($coursePools, $userId);
        $outstanding = [];
        foreach ($coursePools as $coursePool) {
            if (!$coursePool->getRequired() || !$coursePool->getRequiredEnforced()) {
                continue;
            }
            $poolProgress = $progressMap[$coursePool->getPoolId()] ?? null;
            if ($poolProgress !== null && !$poolProgress['required_completed']) {
                $outstanding[$coursePool->getPoolId()] = $poolProgress;
            }
        }
        return [$progressMap, $outstanding];
    }

    public function resolveCoursePoolContext(int $courseId, int $poolId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->hasAccess($course, $userId)) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Course not found');
        }

        $coursePool = $this->coursePoolMapper->findByCourseAndPool($courseId, $poolId);
        $isInstructor = $this->isInstructorOfCourse($course, $userId);

        if (!$isInstructor) {
            [$progressMap, $outstanding] = $this->getOutstandingRequiredPools([$coursePool, ...array_filter(
                $this->coursePoolMapper->findByCourse($courseId),
                static fn(CoursePool $cp): bool => $cp->getPoolId() !== $poolId
            )], $userId);
            $blockers = array_keys($outstanding);
            if ($blockers !== [] && !in_array($poolId, $blockers, true)) {
                $blockerNames = [];
                foreach ($outstanding as $blockerPoolId => $_meta) {
                    $blockerNames[] = $this->getPoolName($blockerPoolId);
                }
                throw new \Exception('Required pools must be completed first: ' . implode(', ', $blockerNames));
            }
            unset($progressMap);
        }

        return [
            'course' => $course,
            'course_pool' => $coursePool,
            'is_instructor' => $isInstructor,
            'question_ids' => $this->getFilteredQuestionIdsForCoursePoolEntity($coursePool),
        ];
    }

    public function updatePoolRules(
        int $courseId,
        int $poolId,
        bool $required,
        bool $requiredEnforced,
        ?string $filterExamKey,
        ?string $filterChapterKey,
        ?array $filterQuestionIds,
        string $userId
    ): CoursePool {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new \Exception('No permission');
        }

        $coursePool = $this->coursePoolMapper->findByCourseAndPool($courseId, $poolId);
        $coursePool->setRequired($required);
        $coursePool->setRequiredEnforced($required && $requiredEnforced);
        $coursePool->setFilterExamKey($this->normalizeCoursePoolFilterValue($filterExamKey, 64));
        $coursePool->setFilterChapterKey($this->normalizeCoursePoolFilterValue($filterChapterKey, 64));
        $coursePool->setFilterQuestionIds($this->normalizeCoursePoolQuestionIds($filterQuestionIds, $poolId));

        return $this->coursePoolMapper->update($coursePool);
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

    private function countQuestionsInPool(int $poolId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('learning_questions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
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

        $enrolledCourses = [];
        foreach ($memberEntries as $member) {
            if (!in_array($member->getCourseId(), $ownIds)) {
                try {
                    $course = $this->courseMapper->findById($member->getCourseId());
                    $courseData = $course->jsonSerialize();
                    $courseData['member_role'] = $member->getRole();
                    $enrolledCourses[] = $courseData;
                } catch (DoesNotExistException $e) {
                    // orphaned membership, skip
                }
            }
        }

        // Batch-load pool counts for enrolled courses
        if (!empty($enrolledCourses)) {
            $enrolledIds = array_column($enrolledCourses, 'id');
            $qb = $this->db->getQueryBuilder();
            $qb->select('course_id', $qb->createFunction('COUNT(*) as cnt'))
                ->from('learning_course_pools')
                ->where($qb->expr()->in('course_id', $qb->createNamedParameter($enrolledIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('course_id');
            $result = $qb->executeQuery();
            $enrolledPoolCounts = [];
            while ($row = $result->fetch()) {
                $enrolledPoolCounts[(int)$row['course_id']] = (int)$row['cnt'];
            }
            $result->closeCursor();

            foreach ($enrolledCourses as &$courseData) {
                $courseData['pool_count'] = $enrolledPoolCounts[$courseData['id']] ?? 0;
            }
            unset($courseData);
        }

        $enrolled = $enrolledCourses;

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
        $availableContentLanguages = $this->getAvailableContentLanguagesByPoolIds(array_map(static fn(CoursePool $cp): int => $cp->getPoolId(), $coursePools));
        $requiredProgressMap = $isInstructor ? [] : $this->buildRequiredProgressMap($coursePools, $userId);
        $outstandingRequiredPoolIds = [];
        if (!$isInstructor) {
            foreach ($coursePools as $coursePool) {
                if (!$coursePool->getRequired() || !$coursePool->getRequiredEnforced()) {
                    continue;
                }
                $poolProgress = $requiredProgressMap[$coursePool->getPoolId()] ?? null;
                if ($poolProgress !== null && !$poolProgress['required_completed']) {
                    $outstandingRequiredPoolIds[] = $coursePool->getPoolId();
                }
            }
        }

        // Enrich pools with pool name and question count
        $poolsData = [];
        foreach ($coursePools as $cp) {
            $cpData = $cp->jsonSerialize();
            try {
                $cpData = array_merge($cpData, $this->getPoolSnapshot($cp->getPoolId()));
                $filteredQuestionIds = $this->getFilteredQuestionIdsForCoursePoolEntity($cp);
                $cpData['question_count'] = count($filteredQuestionIds);
                $cpData['total_question_count'] = $this->countQuestionsInPool($cp->getPoolId());
                $cpData['available_filters'] = $this->getFilterOptionsForPool($cp->getPoolId());
                $cpData['available_content_languages'] = $availableContentLanguages[$cp->getPoolId()] ?? ['de'];
            } catch (DoesNotExistException $e) {
                $cpData['pool_name'] = '(deleted)';
                $cpData['question_count'] = 0;
                $cpData['total_question_count'] = 0;
                $cpData['handbook_key'] = null;
                $cpData['handbook_title'] = null;
                $cpData['chapter_key'] = null;
                $cpData['chapter_title'] = null;
                $cpData['chapter_order'] = null;
                $cpData['available_filters'] = ['exam_keys' => [], 'chapters' => []];
                $cpData['available_content_languages'] = ['de'];
            }

            if (!$isInstructor) {
                $poolProgress = $requiredProgressMap[$cp->getPoolId()] ?? [
                    'filtered_question_count' => $cpData['question_count'],
                    'required_progress_count' => 0,
                    'required_progress_percent' => 0,
                    'required_completed' => false,
                ];
                $cpData = array_merge($cpData, $poolProgress);
                $cpData['locked_for_student'] = $outstandingRequiredPoolIds !== [] && !in_array($cp->getPoolId(), $outstandingRequiredPoolIds, true);
                $cpData['locked_by_required_pools'] = $cpData['locked_for_student'] ? $outstandingRequiredPoolIds : [];
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
        $cp->setRequiredEnforced(false);
        $cp->setFilterExamKey(null);
        $cp->setFilterChapterKey(null);
        $cp->setFilterQuestionIds(null);

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
    public function getCourseProgress(
        int $courseId,
        string $userId,
        int $limit = 25,
        int $offset = 0,
        ?string $sortKey = null,
        ?string $sortDir = null
    ): array {
        $course = $this->courseMapper->findById($courseId);
        $isInstructor = $this->isInstructorOfCourse($course, $userId);

        if (!$isInstructor && !$this->hasAccess($course, $userId)) {
            throw new \Exception('No permission');
        }

        // Normalize pagination/sorting inputs.
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $allowedSortKeys = ['user_id', 'current_level', 'total_xp', 'overall_mastery', 'last_activity_date'];
        $sortKey = in_array($sortKey, $allowedSortKeys, true) ? $sortKey : 'total_xp';
        $sortDir = strtolower((string)$sortDir) === 'asc' ? 'asc' : 'desc';

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
            return [
                'students' => [],
                'meta' => [
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'sort_key' => $sortKey,
                    'sort_dir' => $sortDir,
                ],
            ];
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
                    'overall_mastery' => null,
                    'pools' => [],
                ];
            }
            $students = $this->sortCourseProgressStudents($students, $sortKey, $sortDir);
            $total = count($students);
            $students = array_slice($students, $offset, $limit);
            return [
                'students' => $students,
                'meta' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'sort_key' => $sortKey,
                    'sort_dir' => $sortDir,
                ],
            ];
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
            $totalQuestions = 0;
            $totalMastered = 0;
            foreach ($poolIds as $pid) {
                $totalQ = $questionCounts[$pid] ?? 0;
                $mastered = $masteryData[$sid][$pid] ?? 0;
                $sess = $sessionData[$sid][$pid] ?? null;
                $totalAnswered = $sess ? $sess['total_q'] : 0;
                $totalCorrect = $sess ? $sess['correct'] : 0;
                $accuracy = $totalAnswered > 0 ? round($totalCorrect / $totalAnswered * 100) : 0;

                $totalQuestions += $totalQ;
                $totalMastered += $mastered;

                $poolProgress[] = [
                    'pool_id' => $pid,
                    'pool_name' => $poolNames[$pid] ?? '(deleted)',
                    'total_questions' => $totalQ,
                    'mastered' => $mastered,
                    'answered' => $totalAnswered,
                    'accuracy' => $accuracy,
                    'last_active' => $sess ? $sess['last_active'] : null,
                ];
            }

            $overallMastery = $totalQuestions > 0
                ? (int)round($totalMastered / $totalQuestions * 100)
                : null;
            $stats = $userStats[$sid] ?? null;
            $students[] = [
                'user_id' => $sid,
                'display_name' => $this->getDisplayName($sid),
                'enrolled_at' => $enrolledAtMap[$sid] ?? null,
                'total_xp' => $stats ? $stats['total_xp'] : 0,
                'current_level' => $stats ? $stats['current_level'] : 1,
                'current_streak' => $stats ? $stats['current_streak'] : 0,
                'last_activity_date' => $stats ? $stats['last_activity_date'] : null,
                'overall_mastery' => $overallMastery,
                'pools' => $poolProgress,
            ];
        }

        $students = $this->sortCourseProgressStudents($students, $sortKey, $sortDir);
        $total = count($students);
        $students = array_slice($students, $offset, $limit);

        return [
            'students' => $students,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'sort_key' => $sortKey,
                'sort_dir' => $sortDir,
            ],
        ];
    }

    /**
     * Sort course progress students by supported columns.
     */
    private function sortCourseProgressStudents(array $students, string $sortKey, string $sortDir): array {
        $isAsc = $sortDir === 'asc';

        usort($students, function (array $a, array $b) use ($sortKey, $isAsc): int {
            switch ($sortKey) {
                case 'user_id':
                    // UX: "Student" column sorts by visible display name first.
                    $va = mb_strtolower((string)($a['display_name'] ?? $a['user_id'] ?? ''));
                    $vb = mb_strtolower((string)($b['display_name'] ?? $b['user_id'] ?? ''));
                    $cmp = strcmp($va, $vb);
                    break;
                case 'last_activity_date':
                    $va = (string)($a['last_activity_date'] ?? '');
                    $vb = (string)($b['last_activity_date'] ?? '');
                    $cmp = strcmp($va, $vb);
                    break;
                default:
                    $va = (int)($a[$sortKey] ?? 0);
                    $vb = (int)($b[$sortKey] ?? 0);
                    $cmp = $va <=> $vb;
                    break;
            }

            if ($cmp === 0) {
                // Stable-ish fallback to deterministic order.
                $cmp = strcmp((string)($a['user_id'] ?? ''), (string)($b['user_id'] ?? ''));
            }

            return $isAsc ? $cmp : -$cmp;
        });

        return $students;
    }

    /**
     * Get progress for the current user in a course (role-independent).
     * Used for "My Progress" card in the course view.
     */
    public function getMyCourseProgress(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->hasAccess($course, $userId)) {
            throw new \Exception('No permission');
        }

        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        if (empty($poolIds)) {
            return ['pools' => []];
        }

        $poolNames = $this->getPoolNames($poolIds);
        $questionCounts = $this->getQuestionCounts($poolIds);
        $masteryData = $this->getBatchMastery([$userId], $poolIds);
        $sessionData = $this->getBatchSessionStats([$userId], $poolIds);

        $pools = [];
        foreach ($poolIds as $pid) {
            $totalQ = $questionCounts[$pid] ?? 0;
            $mastered = $masteryData[$userId][$pid] ?? 0;
            $sess = $sessionData[$userId][$pid] ?? null;
            $totalAnswered = $sess ? $sess['total_q'] : 0;
            $totalCorrect = $sess ? $sess['correct'] : 0;
            $accuracy = $totalAnswered > 0 ? round($totalCorrect / $totalAnswered * 100) : 0;

            $pools[] = [
                'pool_id' => $pid,
                'pool_name' => $poolNames[$pid] ?? '(deleted)',
                'total_questions' => $totalQ,
                'mastered' => $mastered,
                'answered' => $totalAnswered,
                'accuracy' => $accuracy,
                'last_active' => $sess ? $sess['last_active'] : null,
            ];
        }

        return ['pools' => $pools];
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

        $uniqueStudents = $this->courseMemberMapper->countUniqueStudentsByInstructor($userId);
        return ['courses' => $result, 'unique_student_count' => $uniqueStudents];
    }

    /**
     * Build ORDER BY for leaderboard based on allowed sort keys.
     */
    private function applyLeaderboardSorting($qb, string $sortKey, string $sortDir): void {
        $dir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        switch ($sortKey) {
            case 'user_id':
                $qb->orderBy('cm.user_id', $dir);
                break;
            case 'current_level':
                $qb->orderBy('current_level', $dir)->addOrderBy('total_xp', 'DESC')->addOrderBy('cm.user_id', 'ASC');
                break;
            case 'total_mastered':
                $qb->orderBy('total_mastered', $dir)->addOrderBy('total_xp', 'DESC')->addOrderBy('cm.user_id', 'ASC');
                break;
            case 'current_streak':
                $qb->orderBy('current_streak', $dir)->addOrderBy('total_xp', 'DESC')->addOrderBy('cm.user_id', 'ASC');
                break;
            case 'total_sessions':
                $qb->orderBy('total_sessions', $dir)->addOrderBy('total_xp', 'DESC')->addOrderBy('cm.user_id', 'ASC');
                break;
            case 'last_activity_date':
                $qb->orderBy('us.last_activity_date', $dir)->addOrderBy('total_xp', 'DESC')->addOrderBy('cm.user_id', 'ASC');
                break;
            case 'total_xp':
            default:
                $qb->orderBy('total_xp', $dir)->addOrderBy('current_level', 'DESC')->addOrderBy('cm.user_id', 'ASC');
                break;
        }
    }

    /**
     * Get course leaderboard — paged and server-side sorted.
     * Instructors see all fields; students see limited fields (privacy).
     */
    public function getLeaderboard(
        int $courseId,
        string $userId,
        int $limit = 25,
        int $offset = 0,
        ?string $sortKey = null,
        ?string $sortDir = null,
        bool $activeOnly = false,
        int $activeWithinDays = 30
    ): array {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->hasAccess($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $isInstructor = $this->isInstructorOfCourse($course, $userId);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $activeWithinDays = max(1, min(365, $activeWithinDays));
        $allowedSortKeys = ['user_id', 'total_xp', 'current_level', 'total_mastered', 'current_streak', 'total_sessions', 'last_activity_date'];
        $sortKey = in_array($sortKey, $allowedSortKeys, true) ? $sortKey : 'total_xp';
        $sortDir = strtolower((string)$sortDir) === 'asc' ? 'asc' : 'desc';
        $activeSince = gmdate('Y-m-d H:i:s', time() - ($activeWithinDays * 86400));

        // Count total with the same filter.
        $qbCount = $this->db->getQueryBuilder();
        $qbCount->select($qbCount->createFunction('COUNT(*) AS cnt'))
            ->from('learning_course_members', 'cm')
            ->leftJoin('cm', 'learning_user_stats', 'us', $qbCount->expr()->eq('cm.user_id', 'us.user_id'))
            ->where($qbCount->expr()->eq('cm.course_id', $qbCount->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qbCount->expr()->eq('cm.role', $qbCount->createNamedParameter('student')));
        if ($activeOnly) {
            $qbCount->andWhere($qbCount->expr()->isNotNull('us.last_activity_date'))
                ->andWhere($qbCount->expr()->gte('us.last_activity_date', $qbCount->createNamedParameter($activeSince)));
        }
        $countResult = $qbCount->executeQuery();
        $total = (int)$countResult->fetchOne();
        $countResult->closeCursor();

        // Paged rows via SQL — avoids loading all students into PHP.
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
            ->andWhere($qb->expr()->eq('cm.role', $qb->createNamedParameter('student')));
        if ($activeOnly) {
            $qb->andWhere($qb->expr()->isNotNull('us.last_activity_date'))
                ->andWhere($qb->expr()->gte('us.last_activity_date', $qb->createNamedParameter($activeSince)));
        }
        $this->applyLeaderboardSorting($qb, $sortKey, $sortDir);
        $qb->setFirstResult($offset)->setMaxResults($limit);
        $result = $qb->executeQuery();

        $entries = [];
        $rank = $offset;
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

        // Calculate requesting user's rank only for default leaderboard mode.
        $myRank = null;
        if (
            !$isInstructor
            && !$activeOnly
            && $sortKey === 'total_xp'
            && $sortDir === 'desc'
        ) {
            foreach ($entries as $entry) {
                if ($entry['user_id'] === $userId) {
                    $myRank = $entry['rank'];
                    break;
                }
            }
            if ($myRank === null) {
                // User not in current page — count students ranked higher (XP DESC, Level DESC, user_id ASC)
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

        return [
            'leaderboard' => $entries,
            'my_rank' => $myRank,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'sort_key' => $sortKey,
                'sort_dir' => $sortDir,
                'active_only' => $activeOnly,
                'active_within_days' => $activeWithinDays,
            ],
        ];
    }

    /**
     * Get at-risk students for a course (instructor only).
     * Uses rule-based signals: inactivity, low accuracy, box-1 stall, lost streak, few sessions.
     */
    public function getAtRiskStudents(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $members = $this->courseMemberMapper->findByCourse($courseId);
        $studentMembers = array_filter($members, fn($m) => $m->getRole() === 'student');
        $studentIds = array_map(fn($m) => $m->getUserId(), $studentMembers);

        if (empty($studentIds)) {
            return ['at_risk' => []];
        }

        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        // Batch data
        $userStats = $this->getBatchUserStats($studentIds);
        $sessionStats = !empty($poolIds) ? $this->getBatchSessionStats($studentIds, $poolIds) : [];

        // Batch: box distribution per student across course pools
        $boxData = [];
        if (!empty($poolIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id', 'box', $qb->createFunction('COUNT(*) as cnt'))
                ->from('learning_leitner_items')
                ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('user_id', 'box');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $boxData[$row['user_id']][(int)$row['box']] = (int)$row['cnt'];
            }
            $result->closeCursor();
        }

        // Batch: session count last 14 days per student
        $recentSessionCounts = [];
        $fourteenDaysAgo = time() - (14 * 86400);
        if (!empty($poolIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id', $qb->createFunction('COUNT(*) as cnt'))
                ->from('learning_sessions')
                ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->isNotNull('completed_at'))
                ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($fourteenDaysAgo)))
                ->groupBy('user_id');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $recentSessionCounts[$row['user_id']] = (int)$row['cnt'];
            }
            $result->closeCursor();
        }

        $atRisk = [];
        $today = new \DateTime('today', new \DateTimeZone('UTC'));

        foreach ($studentIds as $sid) {
            $stats = $userStats[$sid] ?? null;
            $reasons = [];
            $score = 0;

            // Signal 1: Inactive >7 days (HIGH)
            $lastActive = $stats ? ($stats['last_activity_date'] ?? null) : null;
            $daysSinceActive = null;
            if ($lastActive) {
                try {
                    $lastDate = new \DateTime($lastActive, new \DateTimeZone('UTC'));
                    $daysSinceActive = (int)$today->diff($lastDate)->days;
                } catch (\Exception $e) {
                    $daysSinceActive = null;
                }
            }
            if ($lastActive === null || ($daysSinceActive !== null && $daysSinceActive > 7)) {
                if ($daysSinceActive !== null) {
                    $reasons[] = "Inaktiv seit {$daysSinceActive} Tagen";
                } else {
                    $reasons[] = "Noch nie aktiv gewesen";
                }
                $score += 2;
            }

            // Signal 2: Low accuracy <50% (HIGH)
            $totalQ = 0;
            $totalCorrect = 0;
            if (isset($sessionStats[$sid])) {
                foreach ($sessionStats[$sid] as $poolStat) {
                    $totalQ += $poolStat['total_q'];
                    $totalCorrect += $poolStat['correct'];
                }
            }
            $accuracy = $totalQ > 0 ? round($totalCorrect / $totalQ * 100) : null;
            if ($accuracy !== null && $accuracy < 50) {
                $reasons[] = "Accuracy {$accuracy}%";
                $score += 2;
            }

            // Signal 3: Box-1 stall >60% (MEDIUM)
            $boxes = $boxData[$sid] ?? [];
            $totalCards = array_sum($boxes);
            $box1Count = $boxes[1] ?? 0;
            if ($totalCards > 0 && ($box1Count / $totalCards) > 0.6) {
                $pct = round($box1Count / $totalCards * 100);
                $reasons[] = "{$pct}% der Karten in Box 1";
                $score += 1;
            }

            // Signal 4: Lost streak (was >7, now 0) (MEDIUM)
            if ($stats && $stats['current_streak'] === 0) {
                // Check longest_streak from DB for this signal
                $qb = $this->db->getQueryBuilder();
                $qb->select('longest_streak')
                    ->from('learning_user_stats')
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($sid)));
                $result = $qb->executeQuery();
                $row = $result->fetch();
                $result->closeCursor();
                $longestStreak = $row ? (int)$row['longest_streak'] : 0;
                if ($longestStreak > 7) {
                    $reasons[] = "Streak verloren (war {$longestStreak})";
                    $score += 1;
                }
            }

            // Signal 5: Few sessions last 14 days (LOW)
            $recentSessions = $recentSessionCounts[$sid] ?? 0;
            if ($recentSessions < 3) {
                $reasons[] = "Nur {$recentSessions} Sessions in 14 Tagen";
                $score += 1;
            }

            if ($score >= 3) {
                $riskLevel = $score >= 5 ? 'high' : ($score >= 3 ? 'medium' : 'low');
                $atRisk[] = [
                    'user_id' => $sid,
                    'display_name' => $this->getDisplayName($sid),
                    'risk_level' => $riskLevel,
                    'risk_score' => $score,
                    'risk_reasons' => $reasons,
                    'last_active' => $lastActive,
                    'accuracy' => $accuracy,
                ];
            }
        }

        // Sort by risk score descending
        usort($atRisk, fn($a, $b) => $b['risk_score'] - $a['risk_score']);

        return ['at_risk' => $atRisk];
    }

    /**
     * Get detailed student view for instructors.
     * Returns XP, badges, streak, Leitner boxes per pool, recent sessions.
     */
    public function getStudentDetail(int $courseId, string $studentId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        // Access: instructors of this course, or the student viewing their own detail
        if (!$this->isInstructorOfCourse($course, $userId) && $userId !== $studentId) {
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

    // ─── Curriculum Scope ────────────────────────────────────────────────────

    /**
     * Return the curriculum scope for a course together with available chapters.
     */
    public function getCurriculumScope(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->hasAccess($course, $userId)) {
            throw new DoesNotExistException('Course not found');
        }

        $scope = $this->curriculumScopeMapper->findByCourse($courseId);
        $available = $this->getAvailableCurriculumChapters($courseId);

        return [
            'enabled'          => $scope ? $scope->getEnabled() : false,
            'handbook_key'     => $scope ? $scope->getHandbookKey() : null,
            'handbook_title'   => $scope ? $scope->getHandbookTitle() : null,
            'selected_chapter_keys' => $scope ? $scope->getChapterKeys() : [],
            'available_chapters'    => $available,
        ];
    }

    /**
     * Save curriculum scope (instructor only).
     */
    public function saveCurriculumScope(
        int $courseId,
        string $userId,
        bool $enabled,
        array $chapterKeys,
        ?string $handbookKey = null,
        ?string $handbookTitle = null
    ): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('Only instructors can update the curriculum scope');
        }

        $scope = $this->curriculumScopeMapper->findByCourse($courseId);
        $now = time();

        if ($scope === null) {
            $scope = new CurriculumScope();
            $scope->setCourseId($courseId);
            $scope->setCreatedAt($now);
        }

        $scope->setEnabled($enabled);
        $scope->setChapterKeys($chapterKeys);
        $scope->setHandbookKey($handbookKey);
        $scope->setHandbookTitle($handbookTitle);
        $scope->setUpdatedAt($now);

        if ($scope->getId() === null) {
            $scope = $this->curriculumScopeMapper->insert($scope);
        } else {
            $scope = $this->curriculumScopeMapper->update($scope);
        }

        return $this->getCurriculumScope($courseId, $userId);
    }

    /**
     * Aggregate all distinct chapters from questions in all pools of this course.
     * Groups by handbook_key.
     */
    public function getAvailableCurriculumChapters(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(['q.chapter_key', 'q.chapter_title', 'q.chapter_order', 'q.handbook_key', 'q.handbook_title'])
           ->from('learning_questions', 'q')
           ->innerJoin('q', 'learning_course_pools', 'cp', $qb->expr()->eq('cp.pool_id', 'q.pool_id'))
           ->where($qb->expr()->eq('cp.course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->isNotNull('q.chapter_key'))
           ->andWhere($qb->expr()->neq('q.chapter_key', $qb->createNamedParameter('')))
           ->orderBy('q.handbook_key')
           ->addOrderBy('q.chapter_order')
           ->addOrderBy('q.chapter_key');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // Deduplicate and group by handbook
        $seen = [];
        $chapters = [];
        foreach ($rows as $row) {
            $key = $row['chapter_key'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $chapters[] = [
                'chapter_key'    => $row['chapter_key'],
                'chapter_title'  => $row['chapter_title'] ?? $row['chapter_key'],
                'chapter_order'  => (int)($row['chapter_order'] ?? 0),
                'handbook_key'   => $row['handbook_key'] ?? null,
                'handbook_title' => $row['handbook_title'] ?? null,
            ];
        }

        return $chapters;
    }

    /**
     * Filter question IDs by the active curriculum scope of a course.
     * Returns the original array unchanged if scope is disabled or not set.
     */
    public function applyCurriculumScope(int $courseId, array $questionIds): array {
        if (empty($questionIds)) {
            return $questionIds;
        }

        $scope = $this->curriculumScopeMapper->findByCourse($courseId);
        if ($scope === null || !$scope->getEnabled()) {
            return $questionIds;
        }

        $activeKeys = $scope->getChapterKeys();
        if (empty($activeKeys)) {
            return $questionIds;
        }

        // Keep only questions whose chapter_key is in the active set
        $filtered = [];
        foreach (array_chunk($questionIds, 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('learning_questions')
               ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($qb->expr()->in('chapter_key', $qb->createNamedParameter($activeKeys, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $filtered[] = (int)$row['id'];
            }
            $res->closeCursor();
        }

        return $filtered;
    }
}
