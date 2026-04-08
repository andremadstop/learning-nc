<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\QuestionMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Service for exporting pool and course data.
 * Used by OCC commands and ExportController.
 * All methods are user-context-free (admin/CLI).
 */
class DataMobilityService {
    private PoolMapper $poolMapper;
    private QuestionMapper $questionMapper;
    private AnswerMapper $answerMapper;
    private CourseMapper $courseMapper;
    private CoursePoolMapper $coursePoolMapper;
    private CourseMemberMapper $courseMemberMapper;
    private IDBConnection $db;
    private IUserManager $userManager;

    public function __construct(
        PoolMapper $poolMapper,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        CourseMapper $courseMapper,
        CoursePoolMapper $coursePoolMapper,
        CourseMemberMapper $courseMemberMapper,
        IDBConnection $db,
        IUserManager $userManager
    ) {
        $this->poolMapper = $poolMapper;
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->courseMapper = $courseMapper;
        $this->coursePoolMapper = $coursePoolMapper;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->db = $db;
        $this->userManager = $userManager;
    }

    /**
     * Export a pool with all questions and answers as structured array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportPoolJson(int $poolId): array {
        $pool = $this->poolMapper->findById($poolId);
        $questions = $this->questionMapper->findByPoolId($poolId);

        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        $export = [];
        foreach ($questions as $question) {
            $item = [
                'text' => $question->getText(),
                'type' => $question->getQuestionType() ?? 'single',
            ];
            if ($question->getExplanation() !== null && $question->getExplanation() !== '') {
                $item['explanation'] = $question->getExplanation();
            }
            $answers = $answersGrouped[$question->getId()] ?? [];
            $item['answers'] = array_map(function ($a) {
                return [
                    'text' => $a->getText(),
                    'is_correct' => (bool)$a->getIsCorrect(),
                ];
            }, $answers);
            $export[] = $item;
        }

        return $export;
    }

    /**
     * Export a pool as CSV string.
     */
    public function exportPoolCsv(int $poolId): string {
        $pool = $this->poolMapper->findById($poolId);
        $questions = $this->questionMapper->findByPoolId($poolId);

        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        $lines = [];
        foreach ($questions as $question) {
            $type = $question->getQuestionType() ?? 'single';
            $answers = $answersGrouped[$question->getId()] ?? [];

            if ($type === 'open') {
                $modelAnswer = !empty($answers) ? $answers[0]->getText() : '';
                $lines[] = $this->csvLine([$question->getText(), $modelAnswer, 'open']);
            } else {
                $fields = [$question->getText()];
                $correctIndex = null;
                foreach ($answers as $i => $a) {
                    $fields[] = $a->getText();
                    if ((bool)$a->getIsCorrect()) {
                        $correctIndex = $i + 1;
                    }
                }
                $fields[] = (string)($correctIndex ?? 1);
                $explanation = $question->getExplanation();
                if ($explanation !== null && $explanation !== '') {
                    $fields[] = $explanation;
                }
                $lines[] = $this->csvLine($fields);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Export a full course with pools, members, and stats.
     *
     * @return array<string, mixed>
     */
    public function exportCourse(int $courseId): array {
        $course = $this->courseMapper->findById($courseId);
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $members = $this->courseMemberMapper->findByCourse($courseId);

        // Export pools with questions
        $poolsData = [];
        foreach ($coursePools as $cp) {
            $poolId = $cp->getPoolId();
            try {
                $pool = $this->poolMapper->findById($poolId);
                $poolsData[] = [
                    'pool_id' => $poolId,
                    'pool_name' => $pool->getName(),
                    'sort_order' => $cp->getSortOrder(),
                    'questions' => $this->exportPoolJson($poolId),
                ];
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $poolsData[] = [
                    'pool_id' => $poolId,
                    'pool_name' => '(deleted)',
                    'sort_order' => $cp->getSortOrder(),
                    'questions' => [],
                ];
            }
        }

        // Export members with display names
        $membersData = [];
        foreach ($members as $member) {
            $userId = $member->getUserId();
            $user = $this->userManager->get($userId);
            $membersData[] = [
                'user_id' => $userId,
                'display_name' => $user ? $user->getDisplayName() : $userId,
                'role' => $member->getRole(),
                'enrolled_at' => $member->getEnrolledAt(),
            ];
        }

        // Export student stats
        $studentIds = array_map(
            fn($m) => $m->getUserId(),
            array_filter($members, fn($m) => $m->getRole() === 'student')
        );
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);
        $stats = $this->getStudentStats($studentIds, $poolIds);

        return [
            'course' => $course->jsonSerialize(),
            'pools' => $poolsData,
            'members' => $membersData,
            'student_stats' => $stats,
            'exported_at' => time(),
        ];
    }

    /**
     * Export course statistics as CSV (one row per student).
     */
    public function exportCourseStatsCsv(int $courseId): string {
        $course = $this->courseMapper->findById($courseId);
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $members = $this->courseMemberMapper->findByCourse($courseId);

        $studentMembers = array_filter($members, fn($m) => $m->getRole() === 'student');
        $studentIds = array_map(fn($m) => $m->getUserId(), $studentMembers);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        $userStats = $this->getBatchUserStats($studentIds);
        $sessionStats = $this->getBatchSessionStats($studentIds, $poolIds);
        $masteryStats = $this->getBatchMastery($studentIds, $poolIds);

        // Header
        $lines = [$this->csvLine([
            'user_id', 'display_name', 'xp', 'level', 'streak',
            'total_sessions', 'total_mastered', 'total_questions_answered',
            'total_correct', 'accuracy_percent', 'last_activity',
        ])];

        foreach ($studentMembers as $member) {
            $userId = $member->getUserId();
            $user = $this->userManager->get($userId);
            $displayName = $user ? $user->getDisplayName() : $userId;

            $us = $userStats[$userId] ?? [];
            $totalXp = (int)($us['total_xp'] ?? 0);
            $level = (int)($us['current_level'] ?? 1);
            $streak = (int)($us['current_streak'] ?? 0);
            $totalSessions = (int)($us['total_sessions'] ?? 0);
            $totalMastered = (int)($us['total_mastered'] ?? 0);
            $lastActivity = (string)($us['last_activity_date'] ?? '');

            // Aggregate session stats across all pools
            $totalQ = 0;
            $totalCorrect = 0;
            $userPoolStats = $sessionStats[$userId] ?? [];
            foreach ($userPoolStats as $poolStat) {
                $totalQ += (int)($poolStat['total_q'] ?? 0);
                $totalCorrect += (int)($poolStat['correct'] ?? 0);
            }
            $accuracy = $totalQ > 0 ? round(($totalCorrect / $totalQ) * 100, 1) : 0;

            $lines[] = $this->csvLine([
                $userId, $displayName, (string)$totalXp, (string)$level, (string)$streak,
                (string)$totalSessions, (string)$totalMastered, (string)$totalQ,
                (string)$totalCorrect, (string)$accuracy, $lastActivity,
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Get aggregated student stats for course export JSON.
     *
     * @param string[] $studentIds
     * @param int[] $poolIds
     * @return array<string, array<string, mixed>>
     */
    private function getStudentStats(array $studentIds, array $poolIds): array {
        if (empty($studentIds)) {
            return [];
        }

        $userStats = $this->getBatchUserStats($studentIds);
        $sessionStats = $this->getBatchSessionStats($studentIds, $poolIds);

        $result = [];
        foreach ($studentIds as $userId) {
            $us = $userStats[$userId] ?? [];
            $userPoolStats = $sessionStats[$userId] ?? [];

            $totalQ = 0;
            $totalCorrect = 0;
            $lastActive = null;
            foreach ($userPoolStats as $poolStat) {
                $totalQ += (int)($poolStat['total_q'] ?? 0);
                $totalCorrect += (int)($poolStat['correct'] ?? 0);
                $poolLastActive = $poolStat['last_active'] ?? null;
                if ($poolLastActive !== null && ($lastActive === null || $poolLastActive > $lastActive)) {
                    $lastActive = $poolLastActive;
                }
            }

            $result[$userId] = [
                'total_xp' => (int)($us['total_xp'] ?? 0),
                'current_level' => (int)($us['current_level'] ?? 1),
                'current_streak' => (int)($us['current_streak'] ?? 0),
                'total_sessions' => (int)($us['total_sessions'] ?? 0),
                'total_mastered' => (int)($us['total_mastered'] ?? 0),
                'total_questions_answered' => $totalQ,
                'total_correct' => $totalCorrect,
                'accuracy_percent' => $totalQ > 0 ? round(($totalCorrect / $totalQ) * 100, 1) : 0,
                'last_active' => $lastActive,
            ];
        }

        return $result;
    }

    /**
     * @param string[] $userIds
     * @return array<string, array<string, mixed>>
     */
    private function getBatchUserStats(array $userIds): array {
        if (empty($userIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'total_xp', 'current_level', 'current_streak', 'total_sessions', 'total_mastered', 'last_activity_date')
            ->from('learning_user_stats')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
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
     * @param string[] $studentIds
     * @param int[] $poolIds
     * @return array<string, array<int, array<string, mixed>>>
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
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
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
     * @param string[] $studentIds
     * @param int[] $poolIds
     * @return array<string, array<int, int>>
     */
    private function getBatchMastery(array $studentIds, array $poolIds): array {
        if (empty($studentIds) || empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'pool_id', $qb->createFunction('COUNT(*) as cnt'))
            ->from('learning_leitner_items')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
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
     * Encode a row as CSV with formula-injection protection.
     *
     * @param array<int, string> $fields
     */
    private function csvLine(array $fields): string {
        $fields = array_map(function ($f) {
            if (is_string($f) && $f !== '' && in_array($f[0], ['=', '+', '-', '@'], true)) {
                return "\t" . $f;
            }
            return $f;
        }, $fields);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, $fields);
        rewind($handle);
        $line = rtrim((string)stream_get_contents($handle), "\n");
        fclose($handle);
        return $line;
    }
}
