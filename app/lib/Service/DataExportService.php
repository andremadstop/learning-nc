<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\UserTelosMapper;
use OCP\IDBConnection;

class DataExportService {
    private IDBConnection $db;
    private UserTelosMapper $userTelosMapper;
    private XpService $xpService;
    private StreakService $streakService;
    private BadgeService $badgeService;
    private CertificateMapper $certificateMapper;

    public function __construct(
        IDBConnection $db,
        UserTelosMapper $userTelosMapper,
        XpService $xpService,
        StreakService $streakService,
        BadgeService $badgeService,
        CertificateMapper $certificateMapper
    ) {
        $this->db = $db;
        $this->userTelosMapper = $userTelosMapper;
        $this->xpService = $xpService;
        $this->streakService = $streakService;
        $this->badgeService = $badgeService;
        $this->certificateMapper = $certificateMapper;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForUser(string $userId): array {
        $telos = $this->userTelosMapper->findByUserIdOrNull($userId);
        $xp = $this->xpService->calculateXp($userId);
        $streak = $this->streakService->getStreak($userId);

        return [
            'user_stats' => [
                'xp' => (int)($xp['total_xp'] ?? 0),
                'level' => (int)($xp['level'] ?? 1),
                'streak' => (int)($streak['current_streak'] ?? 0),
            ],
            'telos_profile' => [
                'help_offer' => $telos ? $telos->getHelpOfferList() : [],
                'help_wanted' => $telos ? $telos->getHelpWantedList() : [],
                'bio' => $telos ? (string)($telos->getBio() ?? '') : '',
                'visibility' => $telos ? $telos->getVisibility() : 'private',
                'telos' => $telos ? $telos->getTelosData() : [],
            ],
            'badges' => array_values(array_filter(
                $this->badgeService->getUserBadges($userId),
                static fn(array $badge): bool => ($badge['earned'] ?? false) === true
            )),
            'leitner_items' => $this->exportLeitnerItems($userId),
            'sessions' => $this->exportSessions($userId),
            'answers' => $this->exportAnswers($userId),
            'pools' => $this->exportPools($userId),
            'courses' => $this->exportCourses($userId),
            'campaign_progress' => $this->exportCampaignProgress($userId),
            'kudos_received' => $this->exportKudos($userId, 'to_user'),
            'kudos_given' => $this->exportKudos($userId, 'from_user'),
            // Art.20 DSGVO — certificates block. Populated in 163-04; skeleton [] at Wave 0.
            'certificates' => [],
            'exported_at' => time(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportLeitnerItems(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'question_id',
            'pool_id',
            'box',
            'stability',
            'difficulty',
            'last_reviewed',
            'next_review',
            'correct_count',
            'incorrect_count'
        )
            ->from('learning_leitner_items')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static function (array $row): array {
            return [
                'question_id' => (int)$row['question_id'],
                'pool_id' => (int)$row['pool_id'],
                'box' => (int)$row['box'],
                'stability' => $row['stability'] !== null ? (float)$row['stability'] : null,
                'difficulty' => $row['difficulty'] !== null ? (float)$row['difficulty'] : null,
                'last_review' => $row['last_reviewed'] !== null ? (int)$row['last_reviewed'] : null,
                'next_review' => $row['next_review'] !== null ? (int)$row['next_review'] : null,
                'correct_count' => (int)$row['correct_count'],
                'incorrect_count' => (int)$row['incorrect_count'],
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportSessions(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'pool_id', 'mode', 'total_questions', 'correct_answers', 'started_at', 'completed_at')
            ->from('learning_sessions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('started_at', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'pool_id' => (int)$row['pool_id'],
                'type' => (string)$row['mode'],
                'score' => (int)$row['correct_answers'],
                'total_questions' => (int)$row['total_questions'],
                'started_at' => (int)$row['started_at'],
                'completed_at' => $row['completed_at'] !== null ? (int)$row['completed_at'] : null,
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportAnswers(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('ua.question_id', 'ua.answer_id', 'ua.is_correct', 'ua.answered_at', 's.id AS session_id')
            ->from('learning_user_answers', 'ua')
            ->innerJoin('ua', 'learning_sessions', 's', $qb->expr()->eq('ua.session_id', 's.id'))
            ->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('ua.answered_at', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static function (array $row): array {
            return [
                'session_id' => (int)$row['session_id'],
                'question_id' => (int)$row['question_id'],
                'answer_id' => (int)$row['answer_id'],
                'correct' => (bool)$row['is_correct'],
                'created_at' => (int)$row['answered_at'],
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportPools(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.id', 'p.name', 'p.description')
            ->addSelect($qb->createFunction('COUNT(q.id) AS question_count'))
            ->from('learning_pools', 'p')
            ->leftJoin('p', 'learning_questions', 'q', $qb->expr()->eq('q.pool_id', 'p.id'))
            ->where($qb->expr()->eq('p.user_id', $qb->createNamedParameter($userId)))
            ->groupBy('p.id', 'p.name', 'p.description')
            ->orderBy('p.updated_at', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'title' => (string)$row['name'],
                'description' => (string)($row['description'] ?? ''),
                'questions_count' => (int)$row['question_count'],
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportCourses(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.title', 'c.status', 'c.exam_date', 'cm.role', 'cm.enrolled_at')
            ->from('learning_course_members', 'cm')
            ->innerJoin('cm', 'learning_courses', 'c', $qb->expr()->eq('cm.course_id', 'c.id'))
            ->where($qb->expr()->eq('cm.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('cm.enrolled_at', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'role' => (string)$row['role'],
                'status' => (string)($row['status'] ?? 'active'),
                'exam_date' => $row['exam_date'],
                'enrolled_at' => (int)$row['enrolled_at'],
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportCampaignProgress(string $userId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('campaign_id', 'graph_position', 'state_bag', 'act_number', 'status', 'character_class', 'choices_json', 'score', 'updated_at')
                ->from('learning_campaign_state')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->orderBy('updated_at', 'DESC');

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            return array_map(static function (array $row): array {
                return [
                    'campaign_id' => (string)$row['campaign_id'],
                    'graph_position' => (string)($row['graph_position'] ?? ''),
                    'act_number' => (int)($row['act_number'] ?? 0),
                    'status' => (string)($row['status'] ?? ''),
                    'character_class' => (string)($row['character_class'] ?? ''),
                    'score' => (int)($row['score'] ?? 0),
                    'state_bag' => json_decode((string)($row['state_bag'] ?? '{}'), true) ?? [],
                    'choices' => json_decode((string)($row['choices_json'] ?? '[]'), true) ?? [],
                    'updated_at' => (int)($row['updated_at'] ?? 0),
                ];
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportKudos(string $userId, string $column): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'course_id', 'from_user', 'to_user', 'message', 'created_at')
                ->from('learning_kudos')
                ->where($qb->expr()->eq($column, $qb->createNamedParameter($userId)))
                ->orderBy('created_at', 'DESC');

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            return array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'course_id' => (int)$row['course_id'],
                    'from_user' => (string)$row['from_user'],
                    'to_user' => (string)$row['to_user'],
                    'message' => (string)($row['message'] ?? ''),
                    'created_at' => (int)$row['created_at'],
                ];
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
