<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

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
        'first_session' => ['name' => 'First Steps', 'emoji' => "\u{2B50}", 'description' => 'Complete your first session', 'category' => 'sessions', 'legacy' => true],
        'ten_sessions' => ['name' => 'Dedicated Learner', 'emoji' => "\u{1F4DA}", 'description' => 'Complete 10 sessions', 'category' => 'sessions', 'legacy' => true],
        'fifty_sessions' => ['name' => 'Unstoppable', 'emoji' => "\u{1F3C6}", 'description' => 'Complete 50 sessions', 'category' => 'sessions', 'legacy' => true],
        'perfect_training' => ['name' => 'Perfect Score', 'emoji' => "\u{1F4AF}", 'description' => '100% in training (min 5 questions)', 'category' => 'performance', 'legacy' => true],
        'perfect_exam' => ['name' => 'Exam Ace', 'emoji' => "\u{1F393}", 'description' => '90%+ in exam (min 10 questions)', 'category' => 'performance', 'legacy' => true],
        'mastermind_10' => ['name' => 'Rising Star', 'emoji' => "\u{2B06}\u{FE0F}", 'description' => '10 cards mastered (Box 5)', 'category' => 'mastery', 'legacy' => true],
        'mastermind_100' => ['name' => 'The Mastermind', 'emoji' => "\u{1F9E0}", 'description' => '100 cards mastered (Box 5)', 'category' => 'mastery', 'legacy' => true],
        'streak_7' => [
            'name' => 'Week Hero',
            'name_key' => 'badge_streak_7_name',
            'emoji' => "\u{1F525}",
            'description' => 'Build a 7-day learning streak',
            'description_key' => 'badge_streak_7_desc',
            'trigger' => 'Reach a 7-day streak',
            'trigger_key' => 'badge_streak_7_trigger',
            'category' => 'streak',
            'legacy' => false,
            'threshold' => 7,
        ],
        'streak_30' => ['name' => 'Month Master', 'emoji' => "\u{1F31F}", 'description' => '30-day learning streak', 'category' => 'streak', 'legacy' => true],
        'streak_100' => ['name' => 'Legend', 'emoji' => "\u{1F451}", 'description' => '100-day learning streak', 'category' => 'streak', 'legacy' => true],
        'speed_demon' => ['name' => 'Speed Demon', 'emoji' => "\u{26A1}", 'description' => 'Exam in <50% time with 80%+ score', 'category' => 'performance', 'legacy' => true],
        'sharing_caring' => ['name' => 'Sharing is Caring', 'emoji' => "\u{1F91D}", 'description' => 'Share your first pool', 'category' => 'social', 'legacy' => true],
        'night_owl' => ['name' => 'Night Owl', 'emoji' => "\u{1F989}", 'description' => 'Complete a session between 23:00-05:00', 'category' => 'fun', 'legacy' => true],
        'early_bird' => ['name' => 'Early Bird', 'emoji' => "\u{1F426}", 'description' => 'Complete a session between 05:00-07:00', 'category' => 'fun', 'legacy' => true],
        'sessions_bronze' => ['name' => 'Session Tier Bronze', 'emoji' => "\u{1F949}", 'description' => 'Complete 5 sessions', 'category' => 'sessions', 'legacy' => true],
        'sessions_silver' => ['name' => 'Session Tier Silver', 'emoji' => "\u{1F948}", 'description' => 'Complete 25 sessions', 'category' => 'sessions', 'legacy' => true],
        'sessions_gold' => ['name' => 'Session Tier Gold', 'emoji' => "\u{1F947}", 'description' => 'Complete 75 sessions', 'category' => 'sessions', 'legacy' => true],
        'sessions_platinum' => ['name' => 'Session Tier Platinum', 'emoji' => "\u{1F48E}", 'description' => 'Complete 150 sessions', 'category' => 'sessions', 'legacy' => true],
        'mastery_bronze' => ['name' => 'Mastery Tier Bronze', 'emoji' => "\u{1F949}", 'description' => 'Master 25 cards (Box 5)', 'category' => 'mastery', 'legacy' => true],
        'mastery_silver' => ['name' => 'Mastery Tier Silver', 'emoji' => "\u{1F948}", 'description' => 'Master 100 cards (Box 5)', 'category' => 'mastery', 'legacy' => true],
        'mastery_gold' => ['name' => 'Mastery Tier Gold', 'emoji' => "\u{1F947}", 'description' => 'Master 250 cards (Box 5)', 'category' => 'mastery', 'legacy' => true],
        'mastery_platinum' => ['name' => 'Mastery Tier Platinum', 'emoji' => "\u{1F48E}", 'description' => 'Master 500 cards (Box 5)', 'category' => 'mastery', 'legacy' => true],
        'streak_bronze' => ['name' => 'Streak Tier Bronze', 'emoji' => "\u{1F949}", 'description' => 'Reach a 3-day streak', 'category' => 'streak', 'legacy' => true],
        'streak_silver' => ['name' => 'Streak Tier Silver', 'emoji' => "\u{1F948}", 'description' => 'Reach a 14-day streak', 'category' => 'streak', 'legacy' => true],
        'streak_gold' => ['name' => 'Streak Tier Gold', 'emoji' => "\u{1F947}", 'description' => 'Reach a 45-day streak', 'category' => 'streak', 'legacy' => true],
        'streak_platinum' => ['name' => 'Streak Tier Platinum', 'emoji' => "\u{1F48E}", 'description' => 'Reach a 120-day streak', 'category' => 'streak', 'legacy' => true],
        'pioneer' => [
            'name' => 'First Step',
            'name_key' => 'badge_pioneer_name',
            'emoji' => "\u{1F680}",
            'description' => 'Answer 50 questions',
            'description_key' => 'badge_pioneer_desc',
            'trigger' => 'Reach 50 answered questions',
            'trigger_key' => 'badge_pioneer_trigger',
            'category' => 'onboarding',
            'legacy' => false,
            'threshold' => 50,
        ],
        'streak_14' => [
            'name' => 'Two-Week Warrior',
            'name_key' => 'badge_streak_14_name',
            'emoji' => "\u{26A1}",
            'description' => 'Stay active for 14 days in a row',
            'description_key' => 'badge_streak_14_desc',
            'trigger' => 'Reach a 14-day streak',
            'trigger_key' => 'badge_streak_14_trigger',
            'category' => 'streak',
            'legacy' => false,
            'threshold' => 14,
        ],
        'mastermind' => [
            'name' => 'Mastermind',
            'name_key' => 'badge_mastermind_name',
            'emoji' => "\u{1F9E0}",
            'description' => 'Master 50 cards in Box 5',
            'description_key' => 'badge_mastermind_desc',
            'trigger' => 'Bring 50 cards to Box 5',
            'trigger_key' => 'badge_mastermind_trigger',
            'category' => 'mastery',
            'legacy' => false,
            'threshold' => 50,
        ],
        'exam_ready' => [
            'name' => 'Exam Ready',
            'name_key' => 'badge_exam_ready_name',
            'emoji' => "\u{1F393}",
            'description' => 'Score above 85% in exam mode three times',
            'description_key' => 'badge_exam_ready_desc',
            'trigger' => 'Complete 3 exam sessions above 85%',
            'trigger_key' => 'badge_exam_ready_trigger',
            'category' => 'performance',
            'legacy' => false,
            'threshold' => 3,
        ],
        'simulator' => [
            'name' => 'Practitioner',
            'name_key' => 'badge_simulator_name',
            'emoji' => "\u{1F6E0}",
            'description' => 'Complete 3 simulator sessions',
            'description_key' => 'badge_simulator_desc',
            'trigger' => 'Complete 3 simulator sessions',
            'trigger_key' => 'badge_simulator_trigger',
            'category' => 'practice',
            'legacy' => false,
            'threshold' => 3,
        ],
        'weekend' => [
            'name' => 'Weekend Warrior',
            'name_key' => 'badge_weekend_name',
            'emoji' => "\u{1F6E1}",
            'description' => 'Study on Saturday and Sunday',
            'description_key' => 'badge_weekend_desc',
            'trigger' => 'Learn on both weekend days',
            'trigger_key' => 'badge_weekend_trigger',
            'category' => 'commitment',
            'legacy' => false,
            'threshold' => 1,
        ],
        'swarm' => [
            'name' => 'Knowledge Source',
            'name_key' => 'badge_swarm_name',
            'emoji' => "\u{1F91D}",
            'description' => 'Contribute 5 swarm entries',
            'description_key' => 'badge_swarm_desc',
            'trigger' => 'Create 5 swarm contributions',
            'trigger_key' => 'badge_swarm_trigger',
            'category' => 'social',
            'legacy' => false,
            'threshold' => 5,
        ],
        'trouble_fixer' => [
            'name' => 'Trouble Fixer',
            'name_key' => 'badge_trouble_fixer_name',
            'emoji' => "\u{1FA79}",
            'description' => 'Fix 20 trouble spots',
            'description_key' => 'badge_trouble_fixer_desc',
            'trigger' => 'Correctly repeat 20 trouble spots',
            'trigger_key' => 'badge_trouble_fixer_trigger',
            'category' => 'focus',
            'legacy' => false,
            'threshold' => 20,
        ],
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

        $mode = $data['mode'] ?? 'training';
        $totalQ = (int)($data['total_questions'] ?? 0);
        $correctA = (int)($data['correct_answers'] ?? 0);
        $scorePct = $totalQ > 0 ? round($correctA / $totalQ * 100) : 0;

        if ($mode === 'exam' && $scorePct > 85) {
            $examReadyRuns = $this->getSuccessfulExamRunCount($userId);
            if ($examReadyRuns >= self::BADGES['exam_ready']['threshold']) {
                $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'exam_ready', $notify));
            }
        }

        return $newBadges;
    }

    private function checkMasteryBadges(string $userId, bool $notify = true): array {
        $newBadges = [];
        $box5Count = $this->getBox5Count($userId);

        if ($box5Count >= self::BADGES['mastermind']['threshold']) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'mastermind', $notify));
        }

        return $newBadges;
    }

    private function checkShareBadges(string $userId, bool $notify = true): array {
        return [];
    }

    private function checkStreakBadges(string $userId, array $data, bool $notify = true): array {
        $newBadges = [];
        $streak = (int)($data['current_streak'] ?? 0);

        if ($streak >= self::BADGES['streak_7']['threshold']) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_7', $notify));
        }
        if ($streak >= self::BADGES['streak_14']['threshold']) {
            $newBadges = array_merge($newBadges, $this->awardIfNew($userId, 'streak_14', $notify));
        }

        return $newBadges;
    }

    private function awardIfNew(string $userId, string $badgeId, bool $notify = true): array {
        $def = self::BADGES[$badgeId] ?? null;
        if ($def === null || $def['legacy'] === true) {
            return [];
        }

        $earnedAt = time();

        // Atomic insert — UNIQUE(user_id, badge_id) prevents duplicates
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_user_badges')
               ->values([
                   'user_id' => $qb->createNamedParameter($userId),
                   'badge_id' => $qb->createNamedParameter($badgeId),
                   'earned_at' => $qb->createNamedParameter($earnedAt),
               ]);
            $qb->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return []; // Already earned — race condition safe
            }
            throw $e;
        }

        $badgeData = $this->buildBadgeData($badgeId, null);
        $badgeData['earned'] = true;
        $badgeData['earned_at'] = $earnedAt;

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

    private function getAnsweredQuestionCount(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COALESCE(SUM(total_questions), 0) as cnt'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'));
        $result = $qb->executeQuery();
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
        $result = $qb->executeQuery();
        $count = (int)$result->fetch()['cnt'];
        $result->closeCursor();
        return $count;
    }

    private function getSuccessfulExamRunCount(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gt('total_questions', $qb->createNamedParameter(0)))
           ->andWhere(
               $qb->expr()->gt(
                   $qb->createFunction('correct_answers * 100'),
                   $qb->createFunction('total_questions * 85')
               )
           );
        $result = $qb->executeQuery();
        $count = (int)$result->fetch()['cnt'];
        $result->closeCursor();
        return $count;
    }

    private function getCurrentStreak(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('current_streak')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->setMaxResults(1);
        $result = $qb->executeQuery();
        $streakRow = $result->fetch();
        $result->closeCursor();
        return (int)($streakRow['current_streak'] ?? 0);
    }

    private function buildBadgeData(string $badgeId, ?array $earnedRow = null): array {
        $def = self::BADGES[$badgeId];

        return [
            'badge_id' => $badgeId,
            'name' => $def['name'],
            'name_key' => $def['name_key'] ?? null,
            'emoji' => $def['emoji'],
            'description' => $def['description'],
            'description_key' => $def['description_key'] ?? null,
            'trigger' => $def['trigger'] ?? null,
            'trigger_key' => $def['trigger_key'] ?? null,
            'category' => $def['category'],
            'legacy' => $def['legacy'],
            'threshold' => $def['threshold'] ?? null,
            'earned' => $earnedRow !== null,
            'earned_at' => $earnedRow['earned_at'] ?? null,
        ];
    }

    public function getUserBadges(string $userId): array {
        if ($this->config->getAppValue('learning', 'gamification_enabled', 'yes') !== 'yes') {
            $badges = [];
            foreach (self::BADGES as $badgeId => $def) {
                $badges[] = $this->buildBadgeData($badgeId, null);
            }
            return $badges;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('badge_id', 'earned_at', 'pool_id')
           ->from('learning_user_badges')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('earned_at', 'DESC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $earned = [];
        foreach ($rows as $row) {
            $earned[$row['badge_id']] = $row;
        }

        $badges = [];
        foreach (self::BADGES as $badgeId => $def) {
            $badges[] = $this->buildBadgeData($badgeId, $earned[$badgeId] ?? null);
        }

        return $badges;
    }

    public function getBadgeProgress(string $userId): array {
        if ($this->config->getAppValue('learning', 'gamification_enabled', 'yes') !== 'yes') {
            return [];
        }

        $answeredQuestionCount = $this->getAnsweredQuestionCount($userId);
        $box5Count = $this->getBox5Count($userId);
        $currentStreak = $this->getCurrentStreak($userId);
        $successfulExamRunCount = $this->getSuccessfulExamRunCount($userId);

        $progress = [];

        $thresholds = [
            'pioneer' => ['current' => min($answeredQuestionCount, self::BADGES['pioneer']['threshold']), 'target' => self::BADGES['pioneer']['threshold']],
            'streak_7' => ['current' => min($currentStreak, self::BADGES['streak_7']['threshold']), 'target' => self::BADGES['streak_7']['threshold']],
            'streak_14' => ['current' => min($currentStreak, self::BADGES['streak_14']['threshold']), 'target' => self::BADGES['streak_14']['threshold']],
            'mastermind' => ['current' => min($box5Count, self::BADGES['mastermind']['threshold']), 'target' => self::BADGES['mastermind']['threshold']],
            'exam_ready' => ['current' => min($successfulExamRunCount, self::BADGES['exam_ready']['threshold']), 'target' => self::BADGES['exam_ready']['threshold']],
        ];

        // Check which are already earned
        $earned = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('badge_id')
           ->from('learning_user_badges')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
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
                'name_key' => $def['name_key'],
                'emoji' => $def['emoji'],
                'description' => $def['description'],
                'description_key' => $def['description_key'],
                'trigger' => $def['trigger'],
                'trigger_key' => $def['trigger_key'],
                'legacy' => $def['legacy'],
                'current' => $data['current'],
                'target' => $data['target'],
                'percentage' => min(100, (int) round($data['current'] / $data['target'] * 100)),
            ];
        }

        return $progress;
    }

}
