<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;

class BadgeService {
    private IDBConnection $db;
    private INotificationManager $notificationManager;
    private IConfig $config;
    private IActivityManager $activityManager;

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
        'sessions_bronze' => ['name' => 'Session Tier Bronze', 'emoji' => "\u{1F949}", 'description' => 'Complete 5 sessions', 'category' => 'sessions'],
        'sessions_silver' => ['name' => 'Session Tier Silver', 'emoji' => "\u{1F948}", 'description' => 'Complete 25 sessions', 'category' => 'sessions'],
        'sessions_gold' => ['name' => 'Session Tier Gold', 'emoji' => "\u{1F947}", 'description' => 'Complete 75 sessions', 'category' => 'sessions'],
        'sessions_platinum' => ['name' => 'Session Tier Platinum', 'emoji' => "\u{1F48E}", 'description' => 'Complete 150 sessions', 'category' => 'sessions'],
        'mastery_bronze' => ['name' => 'Mastery Tier Bronze', 'emoji' => "\u{1F949}", 'description' => 'Master 25 cards (Box 5)', 'category' => 'mastery'],
        'mastery_silver' => ['name' => 'Mastery Tier Silver', 'emoji' => "\u{1F948}", 'description' => 'Master 100 cards (Box 5)', 'category' => 'mastery'],
        'mastery_gold' => ['name' => 'Mastery Tier Gold', 'emoji' => "\u{1F947}", 'description' => 'Master 250 cards (Box 5)', 'category' => 'mastery'],
        'mastery_platinum' => ['name' => 'Mastery Tier Platinum', 'emoji' => "\u{1F48E}", 'description' => 'Master 500 cards (Box 5)', 'category' => 'mastery'],
        'streak_bronze' => ['name' => 'Streak Tier Bronze', 'emoji' => "\u{1F949}", 'description' => 'Reach a 3-day streak', 'category' => 'streak'],
        'streak_silver' => ['name' => 'Streak Tier Silver', 'emoji' => "\u{1F948}", 'description' => 'Reach a 14-day streak', 'category' => 'streak'],
        'streak_gold' => ['name' => 'Streak Tier Gold', 'emoji' => "\u{1F947}", 'description' => 'Reach a 45-day streak', 'category' => 'streak'],
        'streak_platinum' => ['name' => 'Streak Tier Platinum', 'emoji' => "\u{1F48E}", 'description' => 'Reach a 120-day streak', 'category' => 'streak'],
    ];

    public function __construct(IDBConnection $db, INotificationManager $notificationManager, IConfig $config, IActivityManager $activityManager) {
        $this->db = $db;
        $this->notificationManager = $notificationManager;
        $this->config = $config;
        $this->activityManager = $activityManager;
    }

    /**
     * @param bool $notify When false, only DB insert — no notifications/activity (for use inside transactions)
     */
    public function checkAndAward(string $userId, string $context, array $data, bool $notify = true): array {
        if ($this->config->getAppValue('learning', 'gamification_enabled', 'yes') !== 'yes') {
            return [];
        }

        $newBadges = [];

        switch ($context) {
            case 'session_complete':
                $newBadges = array_merge($newBadges, $this->checkSessionBadges($userId, $data, $notify));
                break;
            case 'leitner_mastery':
                $newBadges = array_merge($newBadges, $this->checkMasteryBadges($userId, $notify));
                break;
            case 'share_created':
                $newBadges = array_merge($newBadges, $this->checkShareBadges($userId, $notify));
                break;
            case 'streak_update':
                $newBadges = array_merge($newBadges, $this->checkStreakBadges($userId, $data, $notify));
                break;
        }

        return $newBadges;
    }

    /**
     * Dispatch notifications/activity for badges that were inserted inside a transaction.
     * Call this AFTER commit.
     */
    public function dispatchNotifications(string $userId, array $badges): void {
        if ($this->config->getAppValue('learning', 'gamification_enabled', 'yes') !== 'yes') {
            return;
        }

        foreach ($badges as $badge) {
            $badgeId = $badge['badge_id'];
            $def = self::BADGES[$badgeId] ?? null;
            if (!$def) {
                continue;
            }

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

            $event = $this->activityManager->generateEvent();
            $event->setApp('learning')
                ->setType('learning_badge_earned')
                ->setAffectedUser($userId)
                ->setAuthor($userId)
                ->setTimestamp(time())
                ->setSubject('badge_earned', [
                    'badge_name' => $def['name'],
                    'badge_emoji' => $def['emoji'],
                ])
                ->setObject('badge', (int)0, $badgeId);
            $this->activityManager->publish($event);
        }
    }

    private function checkSessionBadges(string $userId, array $data, bool $notify = true): array {
        $newBadges = [];

        // Count completed sessions
        $sessionCount = $this->getCompletedSessionCount($userId);

        if ($sessionCount >= 1) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'first_session', $notify));
        }
        if ($sessionCount >= 10) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'ten_sessions', $notify));
        }
        if ($sessionCount >= 50) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'fifty_sessions', $notify));
        }
        if ($sessionCount >= 5) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'sessions_bronze', $notify));
        }
        if ($sessionCount >= 25) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'sessions_silver', $notify));
        }
        if ($sessionCount >= 75) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'sessions_gold', $notify));
        }
        if ($sessionCount >= 150) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'sessions_platinum', $notify));
        }

        // Perfect training (100%, min 5 questions)
        $mode = $data['mode'] ?? 'training';
        $totalQ = (int)($data['total_questions'] ?? 0);
        $correctA = (int)($data['correct_answers'] ?? 0);
        $scorePct = $totalQ > 0 ? round($correctA / $totalQ * 100) : 0;

        if ($mode === 'training' && $scorePct === 100 && $totalQ >= 5) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'perfect_training', $notify));
        }

        // Exam ace (90%+, min 10 questions)
        if ($mode === 'exam' && $scorePct >= 90 && $totalQ >= 10) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'perfect_exam', $notify));
        }

        // Speed demon: exam in <50% of time limit with 80%+ score
        if ($mode === 'exam' && $scorePct >= 80) {
            $startedAt = (int)($data['started_at'] ?? 0);
            $completedAt = (int)($data['completed_at'] ?? 0);
            $timeLimit = (int)($data['time_limit'] ?? 0);
            if ($startedAt > 0 && $completedAt > 0 && $timeLimit > 0) {
                $timeTaken = $completedAt - $startedAt;
                if ($timeTaken < ($timeLimit * 0.5)) {
                    $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'speed_demon', $notify));
                }
            }
        }

        // Time-based badges (timezone-aware)
        $tz = $this->config->getUserValue($userId, 'core', 'timezone', 'UTC');
        $dt = new \DateTime('@' . ($data['completed_at'] ?? time()));
        try {
            $dt->setTimezone(new \DateTimeZone($tz));
        } catch (\Exception $e) {
            $dt->setTimezone(new \DateTimeZone('UTC'));
        }
        $hour = (int)$dt->format('G');
        if ($hour >= 23 || $hour < 5) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'night_owl', $notify));
        }
        if ($hour >= 5 && $hour < 7) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'early_bird', $notify));
        }

        return $newBadges;
    }

    private function checkMasteryBadges(string $userId, bool $notify = true): array {
        $newBadges = [];
        $box5Count = $this->getBox5Count($userId);

        if ($box5Count >= 10) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastermind_10', $notify));
        }
        if ($box5Count >= 100) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastermind_100', $notify));
        }
        if ($box5Count >= 25) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastery_bronze', $notify));
        }
        if ($box5Count >= 100) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastery_silver', $notify));
        }
        if ($box5Count >= 250) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastery_gold', $notify));
        }
        if ($box5Count >= 500) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastery_platinum', $notify));
        }

        return $newBadges;
    }

    private function checkShareBadges(string $userId, bool $notify = true): array {
        return $this->awardIfNew($userId, 'sharing_caring', $notify);
    }

    private function checkStreakBadges(string $userId, array $data, bool $notify = true): array {
        $newBadges = [];
        $streak = (int)($data['current_streak'] ?? 0);

        if ($streak >= 7) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_7', $notify));
        }
        if ($streak >= 30) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_30', $notify));
        }
        if ($streak >= 100) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_100', $notify));
        }
        if ($streak >= 3) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_bronze', $notify));
        }
        if ($streak >= 14) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_silver', $notify));
        }
        if ($streak >= 45) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_gold', $notify));
        }
        if ($streak >= 120) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_platinum', $notify));
        }

        return $newBadges;
    }

    private function awardIfNew(string $userId, string $badgeId, bool $notify = true): array {
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
        $badgeData = [
            'badge_id' => $badgeId,
            'name' => $def['name'],
            'emoji' => $def['emoji'],
            'description' => $def['description'],
        ];

        // When called inside a transaction, skip side-effects — caller dispatches after commit
        if ($notify) {
            $this->dispatchSingleNotification($userId, $badgeId, $def);
        }

        return [$badgeData];
    }

    private function dispatchSingleNotification(string $userId, string $badgeId, array $def): void {
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

        $event = $this->activityManager->generateEvent();
        $event->setApp('learning')
            ->setType('learning_badge_earned')
            ->setAffectedUser($userId)
            ->setAuthor($userId)
            ->setTimestamp(time())
            ->setSubject('badge_earned', [
                'badge_name' => $def['name'],
                'badge_emoji' => $def['emoji'],
            ])
            ->setObject('badge', (int)0, $badgeId);
        $this->activityManager->publish($event);
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
        if ($this->config->getAppValue('learning', 'gamification_enabled', 'yes') !== 'yes') {
            $badges = [];
            foreach (self::BADGES as $badgeId => $def) {
                $badges[] = [
                    'badge_id' => $badgeId,
                    'name' => $def['name'],
                    'emoji' => $def['emoji'],
                    'description' => $def['description'],
                    'category' => $def['category'],
                    'earned' => false,
                    'earned_at' => null,
                ];
            }
            return $badges;
        }

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
        if ($this->config->getAppValue('learning', 'gamification_enabled', 'yes') !== 'yes') {
            return [];
        }

        $sessionCount = $this->getCompletedSessionCount($userId);
        $box5Count = $this->getBox5Count($userId);
        $currentStreak = 0;
        $qb = $this->db->getQueryBuilder();
        $qb->select('current_streak')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->setMaxResults(1);
        $result = $qb->execute();
        $streakRow = $result->fetch();
        $result->closeCursor();
        $currentStreak = (int)($streakRow['current_streak'] ?? 0);

        $progress = [];

        $thresholds = [
            'first_session' => ['current' => $sessionCount, 'target' => 1],
            'ten_sessions' => ['current' => min($sessionCount, 10), 'target' => 10],
            'fifty_sessions' => ['current' => min($sessionCount, 50), 'target' => 50],
            'sessions_bronze' => ['current' => min($sessionCount, 5), 'target' => 5],
            'sessions_silver' => ['current' => min($sessionCount, 25), 'target' => 25],
            'sessions_gold' => ['current' => min($sessionCount, 75), 'target' => 75],
            'sessions_platinum' => ['current' => min($sessionCount, 150), 'target' => 150],
            'mastermind_10' => ['current' => min($box5Count, 10), 'target' => 10],
            'mastermind_100' => ['current' => min($box5Count, 100), 'target' => 100],
            'mastery_bronze' => ['current' => min($box5Count, 25), 'target' => 25],
            'mastery_silver' => ['current' => min($box5Count, 100), 'target' => 100],
            'mastery_gold' => ['current' => min($box5Count, 250), 'target' => 250],
            'mastery_platinum' => ['current' => min($box5Count, 500), 'target' => 500],
            'streak_bronze' => ['current' => min($currentStreak, 3), 'target' => 3],
            'streak_silver' => ['current' => min($currentStreak, 14), 'target' => 14],
            'streak_gold' => ['current' => min($currentStreak, 45), 'target' => 45],
            'streak_platinum' => ['current' => min($currentStreak, 120), 'target' => 120],
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

}
