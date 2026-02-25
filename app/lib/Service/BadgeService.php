<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;

class BadgeService {
    private IDBConnection $db;
    private INotificationManager $notificationManager;

    private const BADGES = [
        'first_session' => ['name' => 'First Steps', 'emoji' => "\u{2B50}", 'description' => 'Complete your first session', 'category' => 'sessions'],
        'ten_sessions' => ['name' => 'Dedicated Learner', 'emoji' => "\u{1F4DA}", 'description' => 'Complete 10 sessions', 'category' => 'sessions'],
        'fifty_sessions' => ['name' => 'Unstoppable', 'emoji' => "\u{1F3C6}", 'description' => 'Complete 50 sessions', 'category' => 'sessions'],
        'perfect_training' => ['name' => 'Perfect Score', 'emoji' => "\u{1F4AF}", 'description' => '100% in training (min 5 questions)', 'category' => 'performance'],
        'perfect_exam' => ['name' => 'Exam Ace', 'emoji' => "\u{1F393}", 'description' => '90%+ in exam (min 10 questions)', 'category' => 'performance'],
        'mastermind_10' => ['name' => 'Rising Star', 'emoji' => "\u{2B06}\u{FE0F}", 'description' => '10 cards mastered (Box 5)', 'category' => 'mastery'],
        'mastermind_100' => ['name' => 'The Mastermind', 'emoji' => "\u{1F9E0}", 'description' => '100 cards mastered (Box 5)', 'category' => 'mastery'],
        'streak_7' => ['name' => 'Week Warrior', 'emoji' => "\u{1F525}", 'description' => '7-day learning streak', 'category' => 'streak'],
        'streak_30' => ['name' => 'Month Master', 'emoji' => "\u{1F31F}", 'description' => '30-day learning streak', 'category' => 'streak'],
        'streak_100' => ['name' => 'Legend', 'emoji' => "\u{1F451}", 'description' => '100-day learning streak', 'category' => 'streak'],
        'speed_demon' => ['name' => 'Speed Demon', 'emoji' => "\u{26A1}", 'description' => 'Exam in <50% time with 80%+ score', 'category' => 'performance'],
        'sharing_caring' => ['name' => 'Sharing is Caring', 'emoji' => "\u{1F91D}", 'description' => 'Share your first pool', 'category' => 'social'],
        'night_owl' => ['name' => 'Night Owl', 'emoji' => "\u{1F989}", 'description' => 'Complete a session between 23:00-05:00', 'category' => 'fun'],
        'early_bird' => ['name' => 'Early Bird', 'emoji' => "\u{1F426}", 'description' => 'Complete a session between 05:00-07:00', 'category' => 'fun'],
    ];

    public function __construct(IDBConnection $db, INotificationManager $notificationManager) {
        $this->db = $db;
        $this->notificationManager = $notificationManager;
    }

    public function checkAndAward(string $userId, string $context, array $data): array {
        $newBadges = [];

        switch ($context) {
            case 'session_complete':
                $newBadges = array_merge($newBadges, $this->checkSessionBadges($userId, $data));
                break;
            case 'leitner_mastery':
                $newBadges = array_merge($newBadges, $this->checkMasteryBadges($userId));
                break;
            case 'share_created':
                $newBadges = array_merge($newBadges, $this->checkShareBadges($userId));
                break;
            case 'streak_update':
                $newBadges = array_merge($newBadges, $this->checkStreakBadges($userId, $data));
                break;
        }

        return $newBadges;
    }

    private function checkSessionBadges(string $userId, array $data): array {
        $newBadges = [];

        // Count completed sessions
        $sessionCount = $this->getCompletedSessionCount($userId);

        if ($sessionCount >= 1) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'first_session'));
        }
        if ($sessionCount >= 10) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'ten_sessions'));
        }
        if ($sessionCount >= 50) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'fifty_sessions'));
        }

        // Perfect training (100%, min 5 questions)
        $mode = $data['mode'] ?? 'training';
        $totalQ = (int)($data['total_questions'] ?? 0);
        $correctA = (int)($data['correct_answers'] ?? 0);
        $scorePct = $totalQ > 0 ? round($correctA / $totalQ * 100) : 0;

        if ($mode === 'training' && $scorePct === 100 && $totalQ >= 5) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'perfect_training'));
        }

        // Exam ace (90%+, min 10 questions)
        if ($mode === 'exam' && $scorePct >= 90 && $totalQ >= 10) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'perfect_exam'));
        }

        // Speed demon: exam in <50% of time limit with 80%+ score
        if ($mode === 'exam' && $scorePct >= 80) {
            $startedAt = (int)($data['started_at'] ?? 0);
            $completedAt = (int)($data['completed_at'] ?? 0);
            $timeLimit = (int)($data['time_limit'] ?? 0);
            if ($startedAt > 0 && $completedAt > 0 && $timeLimit > 0) {
                $timeTaken = $completedAt - $startedAt;
                if ($timeTaken < ($timeLimit * 0.5)) {
                    $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'speed_demon'));
                }
            }
        }

        // Time-based badges
        $hour = (int)gmdate('G', $data['completed_at'] ?? time());
        if ($hour >= 23 || $hour < 5) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'night_owl'));
        }
        if ($hour >= 5 && $hour < 7) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'early_bird'));
        }

        return $newBadges;
    }

    private function checkMasteryBadges(string $userId): array {
        $newBadges = [];
        $box5Count = $this->getBox5Count($userId);

        if ($box5Count >= 10) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastermind_10'));
        }
        if ($box5Count >= 100) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastermind_100'));
        }

        return $newBadges;
    }

    private function checkShareBadges(string $userId): array {
        return $this->awardIfNew($userId, 'sharing_caring');
    }

    private function checkStreakBadges(string $userId, array $data): array {
        $newBadges = [];
        $streak = (int)($data['current_streak'] ?? 0);

        if ($streak >= 7) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_7'));
        }
        if ($streak >= 30) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_30'));
        }
        if ($streak >= 100) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_100'));
        }

        return $newBadges;
    }

    private function awardIfNew(string $userId, string $badgeId): array {
        // Atomic insert — UNIQUE(user_id, badge_id) prevents duplicates
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_user_badges')
               ->values([
                   'user_id' => $qb->createNamedParameter($userId),
                   'badge_id' => $qb->createNamedParameter($badgeId),
                   'earned_at' => $qb->createNamedParameter(time()),
               ]);
            $qb->execute();
        } catch (UniqueConstraintViolationException $e) {
            return []; // Already earned — race condition safe
        }

        $def = self::BADGES[$badgeId];

        // Send NC notification
        $notification = $this->notificationManager->createNotification();
        $notification->setApp('learning')
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('badge', $badgeId)
            ->setSubject('badge_earned', [
                'badge_name' => $def['name'],
                'badge_emoji' => $def['emoji'],
            ]);
        $this->notificationManager->notify($notification);

        return [[
            'badge_id' => $badgeId,
            'name' => $def['name'],
            'emoji' => $def['emoji'],
            'description' => $def['description'],
        ]];
    }

    private function getCompletedSessionCount(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'));
        $result = $qb->execute();
        $count = (int)$result->fetch()['cnt'];
        $result->closeCursor();
        return $count;
    }

    private function getBox5Count(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter(5)));
        $result = $qb->execute();
        $count = (int)$result->fetch()['cnt'];
        $result->closeCursor();
        return $count;
    }

    public function getUserBadges(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('badge_id', 'earned_at', 'pool_id')
           ->from('learning_user_badges')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('earned_at', 'DESC');
        $result = $qb->execute();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $earned = [];
        foreach ($rows as $row) {
            $earned[$row['badge_id']] = $row;
        }

        $badges = [];
        foreach (self::BADGES as $badgeId => $def) {
            $badge = [
                'badge_id' => $badgeId,
                'name' => $def['name'],
                'emoji' => $def['emoji'],
                'description' => $def['description'],
                'category' => $def['category'],
                'earned' => isset($earned[$badgeId]),
                'earned_at' => $earned[$badgeId]['earned_at'] ?? null,
            ];
            $badges[] = $badge;
        }

        return $badges;
    }

    public function getBadgeProgress(string $userId): array {
        $sessionCount = $this->getCompletedSessionCount($userId);
        $box5Count = $this->getBox5Count($userId);

        $progress = [];

        $thresholds = [
            'first_session' => ['current' => $sessionCount, 'target' => 1],
            'ten_sessions' => ['current' => min($sessionCount, 10), 'target' => 10],
            'fifty_sessions' => ['current' => min($sessionCount, 50), 'target' => 50],
            'mastermind_10' => ['current' => min($box5Count, 10), 'target' => 10],
            'mastermind_100' => ['current' => min($box5Count, 100), 'target' => 100],
        ];

        // Check which are already earned
        $earned = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('badge_id')
           ->from('learning_user_badges')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        while ($row = $result->fetch()) {
            $earned[] = $row['badge_id'];
        }
        $result->closeCursor();

        foreach ($thresholds as $badgeId => $data) {
            if (in_array($badgeId, $earned, true)) {
                continue;
            }
            $def = self::BADGES[$badgeId];
            $progress[] = [
                'badge_id' => $badgeId,
                'name' => $def['name'],
                'emoji' => $def['emoji'],
                'current' => $data['current'],
                'target' => $data['target'],
                'percentage' => $data['target'] > 0 ? min(100, (int)round($data['current'] / $data['target'] * 100)) : 0,
            ];
        }

        return $progress;
    }

    public function calculateXp(string $userId): array {
        // XP from completed sessions — computed as SQL aggregate for O(1) performance
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction(
            'COALESCE(SUM(' .
                'CASE WHEN total_questions <= 0 THEN 0 ELSE ' .
                    'ROUND((CASE WHEN mode = \'exam\' THEN 20 + (correct_answers * 10) ELSE 10 + (correct_answers * 5) END)' .
                    ' * CASE' .
                        ' WHEN (correct_answers * 1.0) / total_questions >= 1.0 THEN 1.5' .
                        ' WHEN (correct_answers * 1.0) / total_questions >= 0.8 THEN 1.2' .
                        ' ELSE 1.0 END)' .
                ' END' .
            '), 0) as session_xp'
        ))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'));
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        $totalXp = (int)round((float)($row['session_xp'] ?? 0));

        // XP from Leitner reviews
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            $qb->createFunction('COALESCE(SUM(correct_count), 0) as total_correct'),
            $qb->createFunction('SUM(CASE WHEN box = 5 THEN 1 ELSE 0 END) as box5_count')
        )
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        $leitnerRow = $result->fetch();
        $result->closeCursor();

        $leitnerCorrect = (int)($leitnerRow['total_correct'] ?? 0);
        $box5 = (int)($leitnerRow['box5_count'] ?? 0);
        $totalXp += $leitnerCorrect * 5;
        $totalXp += $box5 * 25;

        // Streak multiplier
        $streakData = $this->getStreakForXp($userId);
        $streakDays = min((int)($streakData['current_streak'] ?? 0), 30);
        $multiplier = 1.0 + ($streakDays * 0.01);
        $totalXp = (int)round($totalXp * $multiplier);

        // Calculate level: threshold(n) = round(50 * n^1.5)
        $level = 1;
        $xpForNextLevel = 100; // level 2 threshold
        $cumulativeXp = 0;
        while (true) {
            $nextLevel = $level + 1;
            $threshold = (int)round(50 * pow($nextLevel, 1.5));
            if ($totalXp < $threshold) {
                $prevThreshold = $level <= 1 ? 0 : (int)round(50 * pow($level, 1.5));
                $xpInLevel = $totalXp - $prevThreshold;
                $xpToNext = $threshold - $prevThreshold;
                $levelPct = $xpToNext > 0 ? min(100, (int)round($xpInLevel / $xpToNext * 100)) : 0;
                return [
                    'total_xp' => $totalXp,
                    'level' => $level,
                    'xp_in_level' => max(0, $xpInLevel),
                    'xp_to_next_level' => $xpToNext,
                    'level_progress_pct' => $levelPct,
                ];
            }
            $level++;
            if ($level > 999) break; // safety
        }

        return [
            'total_xp' => $totalXp,
            'level' => $level,
            'xp_in_level' => 0,
            'xp_to_next_level' => 0,
            'level_progress_pct' => 100,
        ];
    }

    public function calculateSessionXp(array $sessionData, int $streakDays = 0): int {
        $totalQ = (int)($sessionData['total_questions'] ?? 0);
        if ($totalQ <= 0) {
            return 0;
        }

        $mode = $sessionData['mode'] ?? 'training';
        $correct = (int)($sessionData['correct_answers'] ?? 0);
        $accuracy = $correct / $totalQ;

        if ($mode === 'exam') {
            $base = 20 + ($correct * 10);
        } else {
            $base = 10 + ($correct * 5);
        }

        if ($accuracy >= 1.0) {
            $base = (int)round($base * 1.5);
        } elseif ($accuracy >= 0.8) {
            $base = (int)round($base * 1.2);
        }

        $multiplier = 1.0 + (min($streakDays, 30) * 0.01);
        return (int)round($base * $multiplier);
    }

    private function getStreakForXp(string $userId): array {
        // Lightweight streak check — just need current_streak
        $qb = $this->db->getQueryBuilder();
        $platform = $this->db->getDatabasePlatform()->getName();
        $dateFn = $platform === 'postgresql'
            ? "TO_CHAR(TO_TIMESTAMP(completed_at), 'YYYY-MM-DD')"
            : "FROM_UNIXTIME(completed_at, '%Y-%m-%d')";
        $qb->selectDistinct($qb->createFunction($dateFn . ' as activity_date'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->orderBy('activity_date', 'DESC')
           ->setMaxResults(100);
        $result = $qb->execute();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return ['current_streak' => 0];
        }

        $dates = array_map(fn($r) => $r['activity_date'], $rows);
        $today = gmdate('Y-m-d');

        if ($dates[0] !== $today && $dates[0] !== gmdate('Y-m-d', strtotime('-1 day', strtotime($today)))) {
            return ['current_streak' => 0];
        }

        $currentStreak = 0;
        $checkDate = $dates[0];
        foreach ($dates as $date) {
            if ($date === $checkDate) {
                $currentStreak++;
                $checkDate = gmdate('Y-m-d', strtotime('-1 day', strtotime($checkDate)));
            } else {
                break;
            }
        }

        return ['current_streak' => $currentStreak];
    }
}
