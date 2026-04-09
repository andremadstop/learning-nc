<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\FsrsService;
use OCA\Learning\Service\TranslationService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

class MaintenanceService {
    private IDBConnection $db;
    private CourseMapper $courseMapper;
    private FsrsService $fsrsService;
    private TranslationService $translationService;
    private IConfig $config;

    public function __construct(
        IDBConnection $db,
        CourseMapper $courseMapper,
        FsrsService $fsrsService,
        TranslationService $translationService,
        IConfig $config
    ) {
        $this->db = $db;
        $this->courseMapper = $courseMapper;
        $this->fsrsService = $fsrsService;
        $this->translationService = $translationService;
        $this->config = $config;
    }

    /**
     * Toggle maintenance mode for a course. Only the instructor may do this.
     */
    public function toggleMaintenance(int $courseId, bool $enabled, string $userId): bool {
        $course = $this->courseMapper->findById($courseId);
        if ($course->getInstructorId() !== $userId) {
            // Check co-instructor
            $qb = $this->db->getQueryBuilder();
            $qb->select('role')
                ->from('learning_course_members')
                ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            if (!$row || $row['role'] !== 'co-instructor') {
                throw new ForbiddenException('Only instructors can toggle maintenance mode');
            }
        }

        $course->setMaintenanceMode($enabled);
        $course->setUpdatedAt(time());
        $this->courseMapper->update($course);

        return $enabled;
    }

    /**
     * Get maintenance queue: due cards from ALL courses in maintenance mode,
     * sorted by lowest FSRS retrievability (most forgotten first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMaintenanceQueue(string $userId, int $limit = 10, ?string $lang = null): array {
        $limit = max(1, min($limit, 50));
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        // 1) Find course IDs where maintenance_mode = true AND user is a member
        $courseIds = $this->getMaintenanceCourseIds($userId);
        if ($courseIds === []) {
            return [];
        }

        // 2) Find pool IDs belonging to those courses
        $poolIds = $this->getCoursePoolIds($courseIds);
        if ($poolIds === []) {
            return [];
        }

        // 3) Get due leitner items from those pools
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*', 'q.text', 'q.explanation', 'q.difficulty AS q_difficulty',
                     'q.question_type', 'q.pbq_subtype', 'q.pbq_config', 'p.name AS pool_name')
            ->from('learning_leitner_items', 'l')
            ->innerJoin('l', 'learning_questions', 'q', 'l.question_id = q.id')
            ->innerJoin('l', 'learning_pools', 'p', 'l.pool_id = p.id')
            ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('l.pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->lte('l.next_review', $qb->createNamedParameter($now)))
            ->orderBy('l.next_review', 'ASC');

        $result = $qb->executeQuery();
        $items = $result->fetchAll();
        $result->closeCursor();

        // 4) Attach retrievability and sort by lowest first
        $this->attachRetrievability($items);
        usort($items, static function (array $a, array $b): int {
            $cmp = ($a['retrievability'] ?? 1.0) <=> ($b['retrievability'] ?? 1.0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int) ($a['next_review'] ?? 0)) <=> ((int) ($b['next_review'] ?? 0));
        });

        $items = array_slice($items, 0, $limit);
        $this->hydrateAnswers($items);

        return $this->translationService->translateQuestions($items, $contentLanguage);
    }

    /**
     * Stats for the dashboard widget.
     *
     * @return array{courses: int, due_cards: int, estimated_minutes: int}
     */
    public function getMaintenanceStats(string $userId): array {
        $courseIds = $this->getMaintenanceCourseIds($userId);
        if ($courseIds === []) {
            return ['courses' => 0, 'due_cards' => 0, 'estimated_minutes' => 0];
        }

        $poolIds = $this->getCoursePoolIds($courseIds);
        if ($poolIds === []) {
            return ['courses' => count($courseIds), 'due_cards' => 0, 'estimated_minutes' => 0];
        }

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
            ->from('learning_leitner_items')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->lte('next_review', $qb->createNamedParameter($now)));

        $result = $qb->executeQuery();
        $dueCards = (int) ($result->fetch()['cnt'] ?? 0);
        $result->closeCursor();

        // ~30 seconds per card
        $estimatedMinutes = max(1, (int) ceil($dueCards * 0.5));

        return [
            'courses' => count($courseIds),
            'due_cards' => $dueCards,
            'estimated_minutes' => $estimatedMinutes,
        ];
    }

    /**
     * Get IDs of courses in maintenance mode where this user is enrolled.
     *
     * @return int[]
     */
    private function getMaintenanceCourseIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id')
            ->from('learning_courses', 'c')
            ->innerJoin('c', 'learning_course_members', 'cm', $qb->expr()->eq('c.id', 'cm.course_id'))
            ->where($qb->expr()->eq('cm.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('c.maintenance_mode', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int) $row['id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Get pool IDs belonging to the given course IDs.
     *
     * @param int[] $courseIds
     * @return int[]
     */
    private function getCoursePoolIds(array $courseIds): array {
        if ($courseIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id')
            ->from('learning_course_pools')
            ->where($qb->expr()->in('course_id', $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int) $row['pool_id'];
        }
        $result->closeCursor();

        return array_values(array_unique($ids));
    }

    private function attachRetrievability(array &$items): void {
        foreach ($items as &$item) {
            $stability = isset($item['stability']) ? (float) $item['stability'] : null;
            if ($stability === null || $stability <= 0.0) {
                $item['retrievability'] = ((int) ($item['next_review'] ?? 0) <= time()) ? 0.0 : 1.0;
                continue;
            }

            $lastReviewed = isset($item['last_reviewed']) ? (int) $item['last_reviewed'] : null;
            if ($lastReviewed === null || $lastReviewed <= 0) {
                $item['retrievability'] = ((int) ($item['next_review'] ?? 0) <= time()) ? 0.0 : 1.0;
                continue;
            }

            $elapsedDays = max(0.0, (time() - $lastReviewed) / 86400);
            $item['retrievability'] = round(
                $this->fsrsService->calculateRetrievability($stability, $elapsedDays),
                4
            );
        }
    }

    /**
     * Hydrate items with answer options.
     */
    private function hydrateAnswers(array &$items): void {
        if ($items === []) {
            return;
        }

        $questionIds = array_unique(array_map(static fn(array $item): int => (int) $item['question_id'], $items));
        if ($questionIds === []) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_answers')
            ->where($qb->expr()->in('question_id', $qb->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $answersByQuestion = [];
        while ($row = $result->fetch()) {
            $qid = (int) $row['question_id'];
            $answersByQuestion[$qid][] = [
                'id' => (int) $row['id'],
                'text' => $row['text'],
                'is_correct' => (bool) $row['is_correct'],
            ];
        }
        $result->closeCursor();

        foreach ($items as &$item) {
            $item['answers'] = $answersByQuestion[(int) $item['question_id']] ?? [];
        }
    }

    private function resolveContentLanguage(?string $lang, string $userId): ?string {
        $requestedLang = $this->translationService->normalizeLang($lang);
        if ($requestedLang !== null) {
            return $requestedLang;
        }

        return $this->translationService->normalizeLang(
            $this->config->getUserValue($userId, 'learning', 'content_language', '')
        );
    }
}
