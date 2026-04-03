<?php
declare(strict_types=1);

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
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\ForbiddenException;

class CourseService {
    private const AVAILABLE_TOOL_IDS = [
        'subnet',
        'dns',
        'firewall',
        'portscan',
        'routing',
        'nat',
        'wireshark',
        'authflow',
    ];

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
    private FeedService $feedService;

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
        CurriculumScopeMapper $curriculumScopeMapper,
        FeedService $feedService
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
        $this->feedService = $feedService;
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

    private function decodeEnabledTools(?string $json): ?array {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->sanitizeEnabledTools($decoded);
    }

    private function sanitizeEnabledTools(?array $enabledTools): ?array {
        if ($enabledTools === null) {
            return null;
        }

        $allowedLookup = array_flip(self::AVAILABLE_TOOL_IDS);
        $normalized = [];
        foreach ($enabledTools as $toolId) {
            $toolId = (string)$toolId;
            if ($toolId !== '' && isset($allowedLookup[$toolId])) {
                $normalized[$toolId] = $toolId;
            }
        }

        return array_values($normalized);
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

        $questionIds = $this->getFilteredQuestionIdsForCoursePoolEntity($coursePool);

        // Apply curriculum scope filter (chapter-level, instructor-configured)
        $questionIds = $this->applyCurriculumScope($courseId, $questionIds);

        // Filter out questions paused in this course context
        if (!empty($questionIds)) {
            $paused = $this->getPausedQuestionIds($courseId);
            if (!empty($paused)) {
                $questionIds = array_values(array_diff($questionIds, $paused));
            }
        }

        return [
            'course' => $course,
            'course_pool' => $coursePool,
            'is_instructor' => $isInstructor,
            'question_ids' => $questionIds,
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
        $course->setAllowedCampaigns(json_encode(['solarwinds', 'wannacry', 'log4shell', 'phishing_friday', 'ransomware']));

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

        $inserted = $this->coursePoolMapper->insert($cp);

        // Auto-create feed item for pool addition
        $poolName = $this->getPoolName($poolId);
        $this->feedService->createAutoItem(
            $courseId,
            'pool_added',
            'Neuer Fragenpool: ' . $poolName,
            null,
            $userId,
            ['pool_id' => $poolId]
        );

        return $inserted;
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

        // Return early if already enrolled (idempotent enrollment)
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
     * Batch-load reviewed FSRS cards and critical-card counts per student.
     * Returns: [user_id => ['critical' => int, 'reviewed' => int]]
     */
    private function getBatchCriticalCardStats(array $studentIds, array $poolIds): array {
        if (empty($studentIds) || empty($poolIds)) {
            return [];
        }

        $criticalRatioThreshold = log(0.7) / log(0.9);
        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'stability', 'last_reviewed')
            ->from('learning_leitner_items')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('stability'))
            ->andWhere($qb->expr()->isNotNull('last_reviewed'));

        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $studentId = (string)$row['user_id'];
            $stability = (float)($row['stability'] ?? 0);
            $lastReviewed = (int)($row['last_reviewed'] ?? 0);

            if (!isset($data[$studentId])) {
                $data[$studentId] = [
                    'critical' => 0,
                    'reviewed' => 0,
                ];
            }

            if ($stability <= 0 || $lastReviewed <= 0) {
                continue;
            }

            $data[$studentId]['reviewed']++;
            $elapsedDays = max(0, $now - $lastReviewed) / 86400;
            $elapsedRatio = $elapsedDays / $stability;

            if ($elapsedRatio > $criticalRatioThreshold) {
                $data[$studentId]['critical']++;
            }
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
        $allowedSortKeys = ['user_id', 'current_level', 'total_xp', 'critical_cards_count', 'overall_mastery', 'last_activity_date'];
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
                    'critical_cards_count' => 0,
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
        $criticalCardData = $this->getBatchCriticalCardStats($studentIds, $poolIds);
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
            $criticalCards = $criticalCardData[$sid]['critical'] ?? 0;
            $students[] = [
                'user_id' => $sid,
                'display_name' => $this->getDisplayName($sid),
                'enrolled_at' => $enrolledAtMap[$sid] ?? null,
                'total_xp' => $stats ? $stats['total_xp'] : 0,
                'current_level' => $stats ? $stats['current_level'] : 1,
                'current_streak' => $stats ? $stats['current_streak'] : 0,
                'critical_cards_count' => $criticalCards,
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
     * Uses rule-based signals: inactivity, low accuracy, FSRS critical cards, lost streak, few sessions.
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
        $criticalCardData = !empty($poolIds) ? $this->getBatchCriticalCardStats($studentIds, $poolIds) : [];

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

            // Signal 3: FSRS critical cards, with legacy box-1 fallback.
            $criticalCards = $criticalCardData[$sid]['critical'] ?? 0;
            $reviewedFsrsCards = $criticalCardData[$sid]['reviewed'] ?? 0;
            $boxes = $boxData[$sid] ?? [];
            $totalCards = array_sum($boxes);
            $box1Count = $boxes[1] ?? 0;

            if ($reviewedFsrsCards > 0) {
                $criticalShare = $criticalCards / $reviewedFsrsCards;
                if ($criticalCards >= 10 || $criticalShare >= 0.35) {
                    $reasons[] = "{$criticalCards} kritische Karten";
                    $score += 2;
                } elseif ($criticalCards >= 3 || $criticalShare >= 0.2) {
                    $reasons[] = "{$criticalCards} kritische Karten";
                    $score += 1;
                }
            } elseif ($totalCards > 0 && ($box1Count / $totalCards) > 0.6) {
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
                    'critical_cards_count' => $criticalCards,
                ];
            }
        }

        // Sort by risk score descending, then by critical cards descending.
        usort($atRisk, static function (array $a, array $b): int {
            $scoreCmp = (int)$b['risk_score'] <=> (int)$a['risk_score'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $criticalCmp = (int)$b['critical_cards_count'] <=> (int)$a['critical_cards_count'];
            if ($criticalCmp !== 0) {
                return $criticalCmp;
            }

            return strcmp((string)($a['user_id'] ?? ''), (string)($b['user_id'] ?? ''));
        });

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

    // ─── Mode Config ─────────────────────────────────────────────────────────

    /**
     * Update the mode configuration for a course (instructor only).
     * $modeConfig keys: training, leitner, swipe, exam, duel, league (bool each)
     */
    public function updateModeConfig(int $courseId, string $userId, array $modeConfig, ?string $talkRoomToken = null, ?bool $leitnerSprint = null): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('Only instructors can update course mode config');
        }

        $allowed = ['training', 'leitner', 'swipe', 'exam', 'duel', 'gameshow', 'league', 'oldschool', 'abenteuer', 'course_summary'];
        $clean = [];
        foreach ($allowed as $key) {
            $defaultValue = $key === 'course_summary' ? false : true;
            $clean[$key] = array_key_exists($key, $modeConfig) ? (bool)$modeConfig[$key] : $defaultValue;
        }

        $course->setModeConfig(json_encode($clean));

        // Persist optional DevCloud integration fields
        if ($talkRoomToken !== null) {
            $course->setTalkRoomToken($talkRoomToken ?: null);
        }
        if ($leitnerSprint !== null) {
            $course->setLeitnerSprint($leitnerSprint);
        }

        $course->setUpdatedAt(time());
        $this->courseMapper->update($course);

        return $clean;
    }

    public function getCourseTools(int $courseId, string $userId): ?array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->hasAccess($course, $userId)) {
            throw new DoesNotExistException('Course not found');
        }

        return $this->decodeEnabledTools($course->getEnabledTools());
    }

    public function updateCourseTools(int $courseId, string $userId, ?array $enabledTools): ?array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('Only instructors can update course tools');
        }

        $clean = $this->sanitizeEnabledTools($enabledTools);
        $course->setEnabledTools($clean === null ? null : json_encode($clean));
        $course->setUpdatedAt(time());
        $this->courseMapper->update($course);

        return $clean;
    }

    /**
     * Get IDs of questions paused for a specific course.
     */
    private function getPausedQuestionIds(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('question_id')
            ->from('learning_course_question_overrides')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('paused', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['question_id'];
        }
        $result->closeCursor();
        return $ids;
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

    // ─── Instructor Course Tools ──────────────────────────────────────────────

    /**
     * Check if the given user can manage (is instructor of) a course.
     */
    public function canManageCourse(int $courseId, string $userId): bool {
        try {
            $course = $this->courseMapper->findById($courseId);
            return $this->isInstructorOfCourse($course, $userId);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get per-chapter answer stats for a course (instructor only).
     */
    public function getChapterHeatmap(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        // user_answers has no user_id; get it via sessions.
        // Join order: ua → s (user_id) first, then ua → q → cp → cm (cm references s.user_id)
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'q.chapter_key',
            'q.chapter_title',
            'q.chapter_order',
            $qb->createFunction('COUNT(ua.id) AS total_answers'),
            $qb->createFunction('SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) AS correct_answers'),
            $qb->createFunction('COUNT(DISTINCT s.user_id) AS unique_learners')
        )
        ->from('learning_user_answers', 'ua')
        ->innerJoin('ua', 'learning_sessions', 's', $qb->expr()->eq('s.id', 'ua.session_id'))
        ->innerJoin('ua', 'learning_questions', 'q', $qb->expr()->eq('ua.question_id', 'q.id'))
        ->innerJoin('q', 'learning_course_pools', 'cp', $qb->expr()->eq('cp.pool_id', 'q.pool_id'))
        ->innerJoin('cp', 'learning_course_members', 'cm', $qb->expr()->andX(
            $qb->expr()->eq('cm.course_id', 'cp.course_id'),
            $qb->expr()->eq('cm.user_id', 's.user_id')
        ))
        ->where($qb->expr()->eq('cp.course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
        ->andWhere($qb->expr()->in('cm.role', $qb->createNamedParameter(['student', 'co_instructor'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
        ->groupBy('q.chapter_key', 'q.chapter_title', 'q.chapter_order')
        ->orderBy($qb->createFunction('(SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) * 1.0 / NULLIF(COUNT(ua.id), 0))'), 'ASC');

        $result = $qb->executeQuery();
        $chapters = [];
        while ($row = $result->fetch()) {
            $chapters[] = $row;
        }
        $result->closeCursor();

        // Top worst questions (batched): use q.text (not q.question)
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select(
            'q.id',
            'q.text AS question_text',
            'q.chapter_key',
            $qb2->createFunction('COUNT(ua.id) AS total'),
            $qb2->createFunction('SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) AS correct')
        )
        ->from('learning_user_answers', 'ua')
        ->innerJoin('ua', 'learning_questions', 'q', $qb2->expr()->eq('ua.question_id', 'q.id'))
        ->innerJoin('q', 'learning_course_pools', 'cp', $qb2->expr()->eq('cp.pool_id', 'q.pool_id'))
        ->where($qb2->expr()->eq('cp.course_id', $qb2->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
        ->groupBy('q.id', 'q.text', 'q.chapter_key')
        ->having($qb2->createFunction('COUNT(ua.id) >= 5'))
        ->orderBy($qb2->createFunction('(SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) * 1.0 / NULLIF(COUNT(ua.id), 0))'), 'ASC')
        ->setMaxResults(50);

        $result2 = $qb2->executeQuery();
        $worstByChapter = [];
        while ($row = $result2->fetch()) {
            $chapterKey = $row['chapter_key'] ?? '';
            $total = (int)$row['total'];
            $correct = (int)$row['correct'];
            $wrongRate = $total > 0 ? round((1 - $correct / $total) * 100, 1) : 0.0;
            $worstByChapter[$chapterKey][] = [
                'id' => (int)$row['id'],
                'text' => mb_substr((string)$row['question_text'], 0, 120),
                'wrong_rate' => $wrongRate,
            ];
        }
        $result2->closeCursor();

        $output = [];
        foreach ($chapters as $row) {
            $total = (int)$row['total_answers'];
            $correct = (int)$row['correct_answers'];
            $successRate = $total > 0 ? round($correct / $total * 100, 1) : 0.0;
            $chapterKey = $row['chapter_key'] ?? '';
            $topWrong = array_slice($worstByChapter[$chapterKey] ?? [], 0, 5);
            if ($successRate < 50) {
                $severity = 'red';
            } elseif ($successRate < 70) {
                $severity = 'yellow';
            } else {
                $severity = 'green';
            }
            $output[] = [
                'chapter_key' => $chapterKey,
                'chapter_title' => $row['chapter_title'] ?? $chapterKey,
                'chapter_order' => isset($row['chapter_order']) ? (int)$row['chapter_order'] : null,
                'total_answers' => $total,
                'correct_answers' => $correct,
                'success_rate' => $successRate,
                'unique_learners' => (int)$row['unique_learners'],
                'severity' => $severity,
                'top_wrong_questions' => $topWrong,
            ];
        }

        return $output;
    }

    /**
     * Get weakest questions for a course (instructor only).
     */
    public function getWeakQuestions(int $courseId, string $userId, int $limit = 10): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'q.id AS question_id',
            'q.text AS question_text',
            'q.chapter_key',
            'q.chapter_title',
            $qb->createFunction('COUNT(ua.id) AS total_answers'),
            $qb->createFunction('SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) AS correct_answers')
        )
        ->from('learning_user_answers', 'ua')
        ->innerJoin('ua', 'learning_questions', 'q', $qb->expr()->eq('ua.question_id', 'q.id'))
        ->innerJoin('q', 'learning_course_pools', 'cp', $qb->expr()->eq('cp.pool_id', 'q.pool_id'))
        ->where($qb->expr()->eq('cp.course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
        ->groupBy('q.id', 'q.text', 'q.chapter_key', 'q.chapter_title')
        ->having($qb->createFunction('COUNT(ua.id) >= 5'))
        ->orderBy($qb->createFunction('(SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) * 1.0 / NULLIF(COUNT(ua.id), 0))'), 'ASC')
        ->setMaxResults(max(1, min(100, $limit)));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return [];
        }

        // Fetch override status for these questions
        $questionIds = array_map(fn($r) => (int)$r['question_id'], $rows);
        $overrides = [];
        $qbO = $this->db->getQueryBuilder();
        $qbO->select('question_id', 'paused')
            ->from('learning_course_question_overrides')
            ->where($qbO->expr()->eq('course_id', $qbO->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qbO->expr()->in('question_id', $qbO->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $resultO = $qbO->executeQuery();
        while ($row = $resultO->fetch()) {
            $overrides[(int)$row['question_id']] = (bool)$row['paused'];
        }
        $resultO->closeCursor();

        $output = [];
        foreach ($rows as $row) {
            $qid = (int)$row['question_id'];
            $total = (int)$row['total_answers'];
            $correct = (int)$row['correct_answers'];
            $wrongRate = $total > 0 ? round((1 - $correct / $total) * 100, 1) : 0.0;
            $output[] = [
                'question_id' => $qid,
                'question_text' => mb_substr((string)$row['question_text'], 0, 120),
                'chapter_key' => $row['chapter_key'] ?? null,
                'chapter_title' => $row['chapter_title'] ?? null,
                'total_answers' => $total,
                'wrong_rate' => $wrongRate,
                'is_paused' => $overrides[$qid] ?? false,
            ];
        }

        return $output;
    }

    /**
     * Set (upsert) a question override for a course.
     */
    public function setQuestionOverride(int $courseId, int $questionId, bool $paused, bool $highlight, string $userId): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $now = time();

        // Try UPDATE first
        $qbU = $this->db->getQueryBuilder();
        $qbU->update('learning_course_question_overrides')
            ->set('paused', $qbU->createNamedParameter($paused, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
            ->set('highlight', $qbU->createNamedParameter($highlight, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
            ->set('updated_at', $qbU->createNamedParameter($now))
            ->where($qbU->expr()->eq('course_id', $qbU->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qbU->expr()->eq('question_id', $qbU->createNamedParameter($questionId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $affected = $qbU->executeStatement();

        if ($affected === 0) {
            $qbI = $this->db->getQueryBuilder();
            $qbI->insert('learning_course_question_overrides')
                ->values([
                    'course_id' => $qbI->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'question_id' => $qbI->createNamedParameter($questionId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'paused' => $qbI->createNamedParameter($paused, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
                    'highlight' => $qbI->createNamedParameter($highlight, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
                    'created_at' => $qbI->createNamedParameter($now),
                    'updated_at' => $qbI->createNamedParameter($now),
                ]);
            $qbI->executeStatement();
        }

        return ['question_id' => $questionId, 'paused' => $paused, 'highlight' => $highlight];
    }

    /**
     * Get per-course instructor dashboard with activity stats.
     */
    public function getCourseDashboard(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $sevenDaysAgo = time() - 7 * 86400;
        $fourteenDaysAgo = time() - 14 * 86400;

        // Get pool IDs for this course
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        // Query 1: Active learners in last 7 days
        $activeLearners = 0;
        if (!empty($poolIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(DISTINCT user_id) AS cnt'))
                ->from('learning_sessions')
                ->where($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($sevenDaysAgo)));
            $r = $qb->executeQuery();
            $activeLearners = (int)$r->fetchOne();
            $r->closeCursor();
        }

        // Query 2: Total members (students)
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select($qb2->createFunction('COUNT(*) AS cnt'))
            ->from('learning_course_members')
            ->where($qb2->expr()->eq('course_id', $qb2->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb2->expr()->eq('role', $qb2->createNamedParameter('student')));
        $r2 = $qb2->executeQuery();
        $totalMembers = (int)$r2->fetchOne();
        $r2->closeCursor();

        // Query 3: New answers in last 7 days
        $newAnswers = 0;
        $avgSuccessRate = null;
        if (!empty($poolIds)) {
            $qb3 = $this->db->getQueryBuilder();
            $qb3->select(
                $qb3->createFunction('COUNT(ua.id) AS total'),
                $qb3->createFunction('SUM(CASE WHEN ua.is_correct THEN 1 ELSE 0 END) AS correct')
            )
            ->from('learning_user_answers', 'ua')
            ->innerJoin('ua', 'learning_questions', 'q', $qb3->expr()->eq('ua.question_id', 'q.id'))
            ->innerJoin('q', 'learning_course_pools', 'cp', $qb3->expr()->eq('cp.pool_id', 'q.pool_id'))
            ->where($qb3->expr()->eq('cp.course_id', $qb3->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb3->expr()->gte('ua.answered_at', $qb3->createNamedParameter($sevenDaysAgo)));
            $r3 = $qb3->executeQuery();
            $row3 = $r3->fetch();
            $r3->closeCursor();
            $newAnswers = (int)($row3['total'] ?? 0);
            $avgSuccessRate = $newAnswers > 0 ? round((int)($row3['correct'] ?? 0) / $newAnswers * 100, 1) : null;
        }

        // Query 4: Recent activity — last 20 sessions
        $recentActivity = [];
        if (!empty($poolIds)) {
            $qb4 = $this->db->getQueryBuilder();
            $qb4->select('pool_id', 'mode', 'user_id', 'completed_at')
                ->from('learning_sessions')
                ->where($qb4->expr()->in('pool_id', $qb4->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb4->expr()->isNotNull('completed_at'))
                ->orderBy('completed_at', 'DESC')
                ->setMaxResults(20);
            $r4 = $qb4->executeQuery();
            while ($row = $r4->fetch()) {
                $recentActivity[] = [
                    'pool_id' => (int)$row['pool_id'],
                    'mode' => $row['mode'],
                    'user_id' => $row['user_id'],
                    'completed_at' => (int)$row['completed_at'],
                ];
            }
            $r4->closeCursor();
        }

        // Warnings: members with no sessions in last 14 days
        $warnings = [];
        if ($totalMembers > 0) {
            $allMembers = $this->courseMemberMapper->findByCourse($courseId);
            $studentIds = array_map(fn($m) => $m->getUserId(), array_filter($allMembers, fn($m) => $m->getRole() === 'student'));
            if (!empty($studentIds) && !empty($poolIds)) {
                $qbW = $this->db->getQueryBuilder();
                $qbW->selectDistinct('user_id')
                    ->from('learning_sessions')
                    ->where($qbW->expr()->in('pool_id', $qbW->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                    ->andWhere($qbW->expr()->gte('started_at', $qbW->createNamedParameter($fourteenDaysAgo)))
                    ->andWhere($qbW->expr()->in('user_id', $qbW->createNamedParameter($studentIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
                $rW = $qbW->executeQuery();
                $activeUserIds = array_column($rW->fetchAll(), 'user_id');
                $rW->closeCursor();
                $inactiveIds = array_diff($studentIds, $activeUserIds);
                if (!empty($inactiveIds)) {
                    $warnings[] = ['type' => 'inactive_14d', 'count' => count($inactiveIds), 'user_ids' => array_values($inactiveIds)];
                }
            }
        }

        return [
            'course_id' => $courseId,
            'active_learners_7d' => $activeLearners,
            'total_members' => $totalMembers,
            'new_answers_7d' => $newAnswers,
            'avg_success_rate_7d' => $avgSuccessRate,
            'recent_activity' => $recentActivity,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get active (non-expired) announcements for a course.
     */
    public function getAnnouncements(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->hasAccess($course, $userId)) {
            throw new ForbiddenException('No access');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_course_announcements')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('expires_at'),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter(time()))
            ))
            ->orderBy('created_at', 'DESC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($r) => [
            'id' => (int)$r['id'],
            'course_id' => (int)$r['course_id'],
            'instructor_id' => $r['instructor_id'],
            'title' => $r['title'],
            'body' => $r['body'],
            'created_at' => (int)$r['created_at'],
            'expires_at' => $r['expires_at'] !== null ? (int)$r['expires_at'] : null,
        ], $rows);
    }

    /**
     * Create a course announcement (instructor only).
     */
    public function createAnnouncement(int $courseId, string $userId, string $title, string $body, ?int $expiresAt = null): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_announcements')
            ->values([
                'course_id' => $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'instructor_id' => $qb->createNamedParameter($userId),
                'title' => $qb->createNamedParameter(mb_substr($title, 0, 255)),
                'body' => $qb->createNamedParameter($body),
                'created_at' => $qb->createNamedParameter($now),
                'expires_at' => $qb->createNamedParameter($expiresAt),
            ]);
        $qb->executeStatement();
        $newId = $this->db->lastInsertId('oc_learning_course_announcements');

        // Auto-create feed item for the announcement
        $this->feedService->createAutoItem($courseId, 'announcement', $title, $body, $userId);

        return [
            'id' => (int)$newId,
            'course_id' => $courseId,
            'instructor_id' => $userId,
            'title' => $title,
            'body' => $body,
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Delete a course announcement (instructor only).
     */
    public function deleteAnnouncement(int $courseId, int $announcementId, string $userId): void {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_course_announcements')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($announcementId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Get the active exam slot for a course (if any).
     */
    public function getActiveExamSlot(int $courseId, string $userId): ?array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->hasAccess($course, $userId)) {
            throw new ForbiddenException('No access');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_course_exam_slots')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->andWhere($qb->expr()->gt('ends_at', $qb->createNamedParameter(time())))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'course_id' => (int)$row['course_id'],
            'instructor_id' => $row['instructor_id'],
            'started_at' => (int)$row['started_at'],
            'duration_minutes' => (int)$row['duration_minutes'],
            'ends_at' => (int)$row['ends_at'],
            'scope_mode' => $row['scope_mode'],
            'status' => $row['status'],
        ];
    }

    /**
     * Create a new exam slot for a course (instructor only), closing any existing active slot.
     */
    public function createExamSlot(int $courseId, string $userId, int $durationMinutes = 90, string $scopeMode = 'all'): array {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $now = time();
        $endsAt = $now + ($durationMinutes * 60);

        // Close any existing active slots
        $qbClose = $this->db->getQueryBuilder();
        $qbClose->update('learning_course_exam_slots')
            ->set('status', $qbClose->createNamedParameter('closed'))
            ->where($qbClose->expr()->eq('course_id', $qbClose->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qbClose->expr()->eq('status', $qbClose->createNamedParameter('active')));
        $qbClose->executeStatement();

        // Build question_ids_json
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $questionIds = [];
        foreach ($coursePools as $cp) {
            $ids = $this->getFilteredQuestionIdsForCoursePoolEntity($cp);
            $questionIds = array_merge($questionIds, $ids);
        }
        if ($scopeMode === 'curriculum') {
            $questionIds = $this->applyCurriculumScope($courseId, $questionIds);
        }
        $questionIds = array_values(array_unique($questionIds));
        $questionIdsJson = json_encode($questionIds);

        // Insert new slot
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_exam_slots')
            ->values([
                'course_id' => $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                'instructor_id' => $qb->createNamedParameter($userId),
                'started_at' => $qb->createNamedParameter($now),
                'duration_minutes' => $qb->createNamedParameter($durationMinutes),
                'ends_at' => $qb->createNamedParameter($endsAt),
                'scope_mode' => $qb->createNamedParameter($scopeMode),
                'question_ids_json' => $qb->createNamedParameter($questionIdsJson),
                'status' => $qb->createNamedParameter('active'),
            ]);
        $qb->executeStatement();
        $newId = $this->db->lastInsertId('oc_learning_course_exam_slots');

        return [
            'id' => (int)$newId,
            'course_id' => $courseId,
            'instructor_id' => $userId,
            'started_at' => $now,
            'duration_minutes' => $durationMinutes,
            'ends_at' => $endsAt,
            'scope_mode' => $scopeMode,
            'question_count' => count($questionIds),
            'status' => 'active',
        ];
    }

    /**
     * Close the active exam slot for a course (instructor only).
     */
    public function closeExamSlot(int $courseId, string $userId): void {
        $course = $this->courseMapper->findById($courseId);
        if (!$this->isInstructorOfCourse($course, $userId)) {
            throw new ForbiddenException('No permission');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_course_exam_slots')
            ->set('status', $qb->createNamedParameter('closed'))
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));
        $qb->executeStatement();
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
