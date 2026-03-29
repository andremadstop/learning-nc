<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\RagChunkMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;


/**
 * Aggregates course-end summary data for students and instructors.
 *
 * Students see their own summary. Instructors see a class overview.
 */
class CourseSummaryService {

    private IDBConnection $db;
    private CourseMemberMapper $courseMemberMapper;
    private BadgeService $badgeService;
    private StreakService $streakService;
    private RagChunkMapper $ragChunkMapper;

    public function __construct(
        IDBConnection $db,
        CourseMemberMapper $courseMemberMapper,
        BadgeService $badgeService,
        StreakService $streakService,
        RagChunkMapper $ragChunkMapper
    ) {
        $this->db = $db;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->badgeService = $badgeService;
        $this->streakService = $streakService;
        $this->ragChunkMapper = $ragChunkMapper;
    }

    /**
     * Get course summary for a single student.
     *
     * @return array{mastery: array, sessions: array, xp: array, badges: array, streak: array, swarm: array, duels: array, trouble_spots: array}
     */
    public function getStudentSummary(int $courseId, string $userId): array {
        $poolIds = $this->getCoursePoolIds($courseId);

        return [
            'mastery' => $this->getMasteryStats($userId, $poolIds),
            'sessions' => $this->getSessionStats($userId, $poolIds),
            'xp' => $this->getUserStats($userId),
            'badges' => $this->badgeService->getUserBadges($userId),
            'streak' => $this->streakService->getStreak($userId),
            'swarm' => $this->getSwarmStats($userId, $courseId),
            'duels' => $this->getDuelStats($userId),
            'trouble_spots' => $this->getTroubleSpots($userId, $poolIds),
        ];
    }

    /**
     * Get class summary for an instructor.
     *
     * @return array{students: array, class_stats: array}
     */
    public function getClassSummary(int $courseId): array {
        $members = $this->courseMemberMapper->findByCourse($courseId);
        $students = array_filter($members, fn($m) => $m->getRole() === 'student');
        $studentIds = array_map(fn($m) => $m->getUserId(), $students);
        $poolIds = $this->getCoursePoolIds($courseId);

        $summaries = [];
        $totalXp = 0;
        $totalAccuracy = 0;
        $totalMastered = 0;
        $count = count($studentIds);

        foreach ($studentIds as $sid) {
            $summary = $this->getStudentSummary($courseId, $sid);
            $summaries[$sid] = $summary;
            $totalXp += $summary['xp']['total_xp'] ?? 0;
            $totalAccuracy += $summary['sessions']['overall_accuracy'] ?? 0;
            $totalMastered += $summary['mastery']['total_mastered'] ?? 0;
        }

        return [
            'students' => $summaries,
            'class_stats' => [
                'student_count' => $count,
                'avg_xp' => $count > 0 ? (int)round($totalXp / $count) : 0,
                'avg_accuracy' => $count > 0 ? round($totalAccuracy / $count, 1) : 0,
                'avg_mastered' => $count > 0 ? (int)round($totalMastered / $count) : 0,
            ],
        ];
    }

    /**
     * Create a snapshot of the student's course summary for permanent storage.
     */
    public function createSnapshot(int $courseId, string $userId): int {
        $summary = $this->getStudentSummary($courseId, $userId);
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_snapshots')
            ->values([
                'user_id' => $qb->createNamedParameter($userId),
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'snapshot_data' => $qb->createNamedParameter(json_encode($summary)),
                'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    /**
     * Get an existing snapshot.
     */
    public function getSnapshot(int $courseId, string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_course_snapshots')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'snapshot_data' => json_decode($row['snapshot_data'], true),
            'created_at' => (int)$row['created_at'],
        ];
    }

    // --- Private aggregation methods ---

    private function getCoursePoolIds(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id')
            ->from('learning_course_pools')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['pool_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    private function getMasteryStats(string $userId, array $poolIds): array {
        if (empty($poolIds)) {
            return ['total_mastered' => 0, 'total_questions' => 0, 'mastery_rate' => 0];
        }
        $qb = $this->db->getQueryBuilder();
        // Mastered (box 5)
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
            ->from('learning_leitner_items')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter(5)));
        $mastered = (int)$qb->executeQuery()->fetchOne();

        // Total in Leitner system
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select($qb2->createFunction('COUNT(*) as cnt'))
            ->from('learning_leitner_items')
            ->where($qb2->expr()->eq('user_id', $qb2->createNamedParameter($userId)))
            ->andWhere($qb2->expr()->in('pool_id', $qb2->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $total = (int)$qb2->executeQuery()->fetchOne();

        return [
            'total_mastered' => $mastered,
            'total_questions' => $total,
            'mastery_rate' => $total > 0 ? round($mastered / $total * 100, 1) : 0,
        ];
    }

    private function getSessionStats(string $userId, array $poolIds): array {
        if (empty($poolIds)) {
            return ['total_sessions' => 0, 'total_questions' => 0, 'correct_answers' => 0, 'overall_accuracy' => 0];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                $qb->createFunction('COUNT(*) as session_count'),
                $qb->createFunction('COALESCE(SUM(total_questions), 0) as total_q'),
                $qb->createFunction('COALESCE(SUM(correct_answers), 0) as correct')
            )
            ->from('learning_sessions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('completed_at'));
        $row = $qb->executeQuery()->fetch();

        $totalQ = (int)($row['total_q'] ?? 0);
        $correct = (int)($row['correct'] ?? 0);

        return [
            'total_sessions' => (int)($row['session_count'] ?? 0),
            'total_questions' => $totalQ,
            'correct_answers' => $correct,
            'overall_accuracy' => $totalQ > 0 ? round($correct / $totalQ * 100, 1) : 0,
        ];
    }

    private function getUserStats(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('total_xp', 'current_level', 'current_streak', 'total_sessions', 'total_mastered')
            ->from('learning_user_stats')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $row = $qb->executeQuery()->fetch();

        if (!$row) {
            return ['total_xp' => 0, 'current_level' => 1, 'current_streak' => 0, 'total_sessions' => 0, 'total_mastered' => 0];
        }

        return [
            'total_xp' => (int)$row['total_xp'],
            'current_level' => (int)$row['current_level'],
            'current_streak' => (int)$row['current_streak'],
            'total_sessions' => (int)$row['total_sessions'],
            'total_mastered' => (int)$row['total_mastered'],
        ];
    }

    private function getSwarmStats(string $userId, int $courseId): array {
        $count = $this->ragChunkMapper->countByUserIdAndCourseId($userId, $courseId);
        $approved = 0;
        $chunks = $this->ragChunkMapper->findByUserIdAndCourseId($userId, $courseId);
        foreach ($chunks as $chunk) {
            if ($chunk->getStatus() === 'approved') {
                $approved++;
            }
        }
        return [
            'total_contributions' => $count,
            'approved_contributions' => $approved,
        ];
    }

    private function getDuelStats(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        // Total duels participated
        $qb->select($qb->createFunction('COUNT(*) as total'))
            ->from('learning_duel_sessions')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('creator_uid', $qb->createNamedParameter($userId)),
                    $qb->expr()->eq('opponent_uid', $qb->createNamedParameter($userId))
                )
            );
        $total = (int)$qb->executeQuery()->fetchOne();

        // Wins
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select($qb2->createFunction('COUNT(*) as wins'))
            ->from('learning_duel_sessions')
            ->where($qb2->expr()->eq('winner_uid', $qb2->createNamedParameter($userId)));
        $wins = (int)$qb2->executeQuery()->fetchOne();

        return [
            'total_duels' => $total,
            'wins' => $wins,
            'win_rate' => $total > 0 ? round($wins / $total * 100, 1) : 0,
        ];
    }

    private function getTroubleSpots(string $userId, array $poolIds): array {
        if (empty($poolIds)) {
            return [];
        }
        // Questions with 3+ wrong and <30% accuracy
        $qb = $this->db->getQueryBuilder();
        $qb->select('ua.question_id',
                $qb->createFunction('COUNT(*) as attempts'),
                $qb->createFunction('SUM(CASE WHEN ua.is_correct = 1 THEN 1 ELSE 0 END) as correct'))
            ->from('learning_user_answers', 'ua')
            ->innerJoin('ua', 'learning_questions', 'q', $qb->expr()->eq('ua.question_id', 'q.id'))
            ->where($qb->expr()->eq('ua.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('q.pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('ua.question_id')
            ->having('COUNT(*) >= 3');
        $result = $qb->executeQuery();
        $spots = [];
        while ($row = $result->fetch()) {
            $attempts = (int)$row['attempts'];
            $correct = (int)$row['correct'];
            $accuracy = $attempts > 0 ? round($correct / $attempts * 100, 1) : 0;
            if ($accuracy < 30) {
                $spots[] = [
                    'question_id' => (int)$row['question_id'],
                    'attempts' => $attempts,
                    'accuracy' => $accuracy,
                ];
            }
        }
        $result->closeCursor();
        return $spots;
    }
}
