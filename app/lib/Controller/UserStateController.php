<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\MissionService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;

class UserStateController extends Controller {
    private const CACHE_NAMESPACE = 'learning';
    private const USER_STATE_CACHE_PREFIX = 'user_state_';

    private BadgeService $badgeService;
    private StreakService $streakService;
    private XpService $xpService;
    private MissionService $missionService;
    private IDBConnection $db;
    private ICacheFactory $cacheFactory;
    private IConfig $config;
    private ?string $userId;

    public function __construct(
        $appName,
        IRequest $request,
        BadgeService $badgeService,
        StreakService $streakService,
        XpService $xpService,
        MissionService $missionService,
        IDBConnection $db,
        ICacheFactory $cacheFactory,
        IConfig $config,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->badgeService = $badgeService;
        $this->streakService = $streakService;
        $this->xpService = $xpService;
        $this->missionService = $missionService;
        $this->db = $db;
        $this->cacheFactory = $cacheFactory;
        $this->config = $config;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function updateSettings(int $daily_goal): DataResponse {
        $daily_goal = max(5, min(200, $daily_goal));

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('daily_goal', $qb->createNamedParameter($daily_goal))
           ->set('updated_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        $affected = $qb->executeStatement();

        if ($affected === 0) {
            // No stats row yet — create one with defaults
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('learning_user_stats')
                   ->values([
                       'user_id' => $qb->createNamedParameter($this->userId),
                       'total_xp' => $qb->createNamedParameter(0),
                       'current_level' => $qb->createNamedParameter(1),
                       'current_streak' => $qb->createNamedParameter(0),
                       'longest_streak' => $qb->createNamedParameter(0),
                       'total_sessions' => $qb->createNamedParameter(0),
                       'total_mastered' => $qb->createNamedParameter(0),
                       'daily_goal' => $qb->createNamedParameter($daily_goal),
                       'updated_at' => $qb->createNamedParameter(time()),
                   ]);
                $qb->executeStatement();
            } catch (\OCP\DB\Exception $e) {
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('learning_user_stats')
                       ->set('daily_goal', $qb->createNamedParameter($daily_goal))
                       ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
                    $qb->executeStatement();
                } else {
                    throw $e;
                }
            }
        }

        // Invalidate cache
        $this->invalidateUserStateCache();

        return new DataResponse(['daily_goal' => $daily_goal]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function dailyChallenge(): DataResponse {
        $globalEnabled = $this->config->getAppValue('learning', 'daily_challenge_enabled', 'yes');
        $userEnabled = $this->config->getUserValue((string)$this->userId, 'learning', 'daily_challenge', 'yes');
        if ($globalEnabled !== 'yes' || $userEnabled !== 'yes') {
            return new DataResponse([
                'available' => false,
                'reason' => 'disabled',
            ]);
        }

        $today = gmdate('Y-m-d');

        // Check if already completed today
        $qb = $this->db->getQueryBuilder();
        $qb->select('last_challenge_date', 'last_challenge_correct')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        $result = $qb->executeQuery();
        $statsRow = $result->fetch();
        $result->closeCursor();

        $completed = $statsRow && ($statsRow['last_challenge_date'] ?? null) === $today;
        $wasCorrect = $completed ? (bool)$statsRow['last_challenge_correct'] : null;

        // Get total questions the user has in their Leitner system (deterministic order)
        $qb = $this->db->getQueryBuilder();
        $qb->select('l.id', 'l.question_id', 'l.pool_id')
           ->from('learning_leitner_items', 'l')
           ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($this->userId)))
           ->orderBy('l.question_id', 'ASC')
           ->addOrderBy('l.id', 'ASC');
        $result = $qb->executeQuery();
        $allItems = $result->fetchAll();
        $result->closeCursor();

        if (empty($allItems)) {
            return new DataResponse([
                'available' => false,
                'reason' => 'no_leitner_items',
            ]);
        }

        // Deterministic question selection: hash(userId + date) % count
        $hash = crc32($this->userId . $today);
        $index = abs($hash) % count($allItems);
        $selectedItem = $allItems[$index];
        $questionId = (int)$selectedItem['question_id'];
        $poolId = (int)$selectedItem['pool_id'];

        // Load question + answers + pool name
        $qb = $this->db->getQueryBuilder();
        $qb->select('q.id', 'q.text', 'q.explanation', 'q.difficulty', 'q.question_type', 'p.name AS pool_name')
           ->from('learning_questions', 'q')
           ->innerJoin('q', 'learning_pools', 'p', 'q.pool_id = p.id')
           ->where($qb->expr()->eq('q.id', $qb->createNamedParameter($questionId)));
        $result = $qb->executeQuery();
        $question = $result->fetch();
        $result->closeCursor();

        if (!$question) {
            return new DataResponse(['available' => false, 'reason' => 'question_deleted']);
        }

        // Load answers (without is_correct for uncompleted challenges)
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text', 'position', 'is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
           ->orderBy('position', 'ASC');
        $result = $qb->executeQuery();
        $answers = $result->fetchAll();
        $result->closeCursor();

        // Strip is_correct if not completed; strip open-question answers if not completed
        $questionType = $question['question_type'] ?? 'single';
        $responseAnswers = array_map(function ($a) use ($completed, $questionType) {
            $ans = ['id' => (int)$a['id'], 'text' => $a['text'], 'position' => (int)$a['position']];
            // Hide model answer for open questions until completed
            if ($questionType === 'open' && !$completed) {
                $ans['text'] = '';
            }
            if ($completed) {
                $ans['is_correct'] = (bool)$a['is_correct'];
            }
            return $ans;
        }, $answers);

        return new DataResponse([
            'available' => true,
            'question' => [
                'id' => (int)$question['id'],
                'text' => $question['text'],
                'explanation' => $completed ? $question['explanation'] : null,
                'difficulty' => $question['difficulty'],
                'question_type' => $question['question_type'],
                'answers' => $responseAnswers,
            ],
            'pool_name' => $question['pool_name'],
            'completed' => $completed,
            'was_correct' => $wasCorrect,
            'xp_reward' => $this->xpService->isGamificationEnabled() ? 15 : 0,
        ]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function answerChallenge(?array $answer_ids = null, ?string $answer_text = null): DataResponse {
        $globalEnabled = $this->config->getAppValue('learning', 'daily_challenge_enabled', 'yes');
        $userEnabled = $this->config->getUserValue((string)$this->userId, 'learning', 'daily_challenge', 'yes');
        if ($globalEnabled !== 'yes' || $userEnabled !== 'yes') {
            return new DataResponse(['error' => 'Daily challenge disabled'], 400);
        }

        $today = gmdate('Y-m-d');

        // Length limit for open answer text
        if ($answer_text !== null && mb_strlen($answer_text) > 2000) {
            return new DataResponse(['error' => 'Answer text too long (max 2000 chars)'], 400);
        }

        // Reproduce the same question selection (deterministic order)
        $qb = $this->db->getQueryBuilder();
        $qb->select('l.question_id')
           ->from('learning_leitner_items', 'l')
           ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($this->userId)))
           ->orderBy('l.question_id', 'ASC')
           ->addOrderBy('l.id', 'ASC');
        $result = $qb->executeQuery();
        $allItems = $result->fetchAll();
        $result->closeCursor();

        if (empty($allItems)) {
            return new DataResponse(['error' => 'No questions available'], 400);
        }

        $hash = crc32($this->userId . $today);
        $index = abs($hash) % count($allItems);
        $questionId = (int)$allItems[$index]['question_id'];

        // Determine question type for strict type-gating
        $qb = $this->db->getQueryBuilder();
        $qb->select('question_type')->from('learning_questions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)));
        $result = $qb->executeQuery();
        $qtRow = $result->fetch();
        $result->closeCursor();
        $challengeQType = $qtRow ? ($qtRow['question_type'] ?? 'single') : 'single';

        // Strict type-gating: open requires answer_text only, MC requires answer_ids only
        $hasText = $answer_text !== null && trim($answer_text) !== '';
        $hasIds = !empty($answer_ids);

        $correctIds = [];
        if ($challengeQType === 'open') {
            if (!$hasText) {
                return new DataResponse(['error' => 'Open questions require answer_text'], 400);
            }
            if ($hasIds) {
                return new DataResponse(['error' => 'Open questions do not accept answer_ids'], 400);
            }
            // Open-question: fuzzy-match against model answer
            $qb = $this->db->getQueryBuilder();
            $qb->select('text')
               ->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
            $result = $qb->executeQuery();
            $modelRow = $result->fetch();
            $result->closeCursor();
            $modelText = $modelRow ? $modelRow['text'] : '';
            $correct = \OCA\Learning\Service\QuestionService::isOpenAnswerCorrect($answer_text, $modelText);
        } else {
            // MC-question: must have answer_ids only
            if (!$hasIds) {
                return new DataResponse(['error' => 'No answer IDs provided'], 400);
            }
            if ($hasText) {
                return new DataResponse(['error' => 'MC questions do not accept answer_text'], 400);
            }
            if (count($answer_ids) > 8) {
                return new DataResponse(['error' => 'Invalid answer payload'], 400);
            }
            $intIds = array_values(array_unique(array_map('intval', $answer_ids)));
            if (count($intIds) !== count($answer_ids)) {
                return new DataResponse(['error' => 'Duplicate answers not allowed'], 400);
            }
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as cnt'))
               ->from('learning_answers')
               ->where($qb->expr()->in('id', $qb->createNamedParameter($intIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
            $result = $qb->executeQuery();
            $validCount = (int)$result->fetch()['cnt'];
            $result->closeCursor();

            if ($validCount !== count($intIds)) {
                return new DataResponse(['error' => 'Invalid answers'], 400);
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
               ->orderBy('id', 'ASC');
            $result = $qb->executeQuery();
            $correctRows = $result->fetchAll();
            $result->closeCursor();

            $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
            sort($correctIds);
            sort($intIds);
            $correct = ($intIds === $correctIds);
        }

        // Atomic claim: UPDATE only if not already completed today
        // This prevents race conditions where parallel requests both pass the check
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('last_challenge_date', $qb->createNamedParameter($today))
           ->set('last_challenge_correct', $qb->createNamedParameter($correct, \PDO::PARAM_BOOL))
           ->set('updated_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
           ->andWhere($qb->expr()->orX(
               $qb->expr()->isNull('last_challenge_date'),
               $qb->expr()->neq('last_challenge_date', $qb->createNamedParameter($today))
           ));
        $claimed = $qb->executeStatement() > 0;

        if (!$claimed) {
            // Either already completed today (race) or no stats row
            // Check if stats row exists
            $qb = $this->db->getQueryBuilder();
            $qb->select('last_challenge_date')
               ->from('learning_user_stats')
               ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
            $result = $qb->executeQuery();
            $statsRow = $result->fetch();
            $result->closeCursor();

            if ($statsRow) {
                // Row exists but claim failed → already completed today
                return new DataResponse(['error' => 'Already completed today'], 400);
            }

            // No stats row yet — create one with atomic INSERT
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('learning_user_stats')
                   ->values([
                       'user_id' => $qb->createNamedParameter($this->userId),
                       'total_xp' => $qb->createNamedParameter(0),
                       'current_level' => $qb->createNamedParameter(1),
                       'current_streak' => $qb->createNamedParameter(0),
                       'longest_streak' => $qb->createNamedParameter(0),
                       'total_sessions' => $qb->createNamedParameter(0),
                       'total_mastered' => $qb->createNamedParameter(0),
                       'last_challenge_date' => $qb->createNamedParameter($today),
                       'last_challenge_correct' => $qb->createNamedParameter($correct, \PDO::PARAM_BOOL),
                       'updated_at' => $qb->createNamedParameter(time()),
                   ]);
                $qb->executeStatement();
            } catch (\OCP\DB\Exception $e) {
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    // Race: row was just created, retry atomic claim
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('learning_user_stats')
                       ->set('last_challenge_date', $qb->createNamedParameter($today))
                       ->set('last_challenge_correct', $qb->createNamedParameter($correct, \PDO::PARAM_BOOL))
                       ->set('updated_at', $qb->createNamedParameter(time()))
                       ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
                       ->andWhere($qb->expr()->orX(
                           $qb->expr()->isNull('last_challenge_date'),
                           $qb->expr()->neq('last_challenge_date', $qb->createNamedParameter($today))
                       ));
                    $retried = $qb->executeStatement() > 0;
                    if (!$retried) {
                        return new DataResponse(['error' => 'Already completed today'], 400);
                    }
                } else {
                    throw $e;
                }
            }
        }

        // Award XP if correct (only reached after successful claim, with streak multiplier)
        $xpEarned = 0;
        if ($correct) {
            $streak = $this->streakService->getStreak($this->userId);
            $xpEarned = $this->xpService->applyMultiplier(15, $streak['current_streak']);
            $this->xpService->incrementLeitnerXp($this->userId, $xpEarned);
        }

        // Invalidate cache
        $this->invalidateUserStateCache();

        // Get explanation
        $qb = $this->db->getQueryBuilder();
        $qb->select('explanation')
           ->from('learning_questions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)));
        $result = $qb->executeQuery();
        $qRow = $result->fetch();
        $result->closeCursor();

        return new DataResponse([
            'correct' => $correct,
            'xp_earned' => $xpEarned,
            'explanation' => $qRow ? $qRow['explanation'] : null,
            'correct_answer_ids' => $correctIds,
        ]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function state(): DataResponse {
        $cache = $this->cacheFactory->createDistributed(self::CACHE_NAMESPACE);
        $cacheKey = $this->userStateCacheKey();

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return new DataResponse($cached);
        }

        $xp = $this->xpService->calculateXp($this->userId);
        $streak = $this->streakService->getStreak($this->userId);
        $xpMultiplier = $this->xpService->getStreakMultiplier($streak['current_streak']);
        $badges = $this->badgeService->getUserBadges($this->userId);
        $progress = $this->badgeService->getBadgeProgress($this->userId);
        $missions = $this->missionService->getMissions((string)$this->userId);

        // Stats from denormalized table
        $qb = $this->db->getQueryBuilder();
        $qb->select('total_sessions', 'total_mastered', 'daily_goal')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        $result = $qb->executeQuery();
        $statsRow = $result->fetch();
        $result->closeCursor();

        $dailyGoal = (int)($statsRow['daily_goal'] ?? 20);

        // Cards reviewed today (leitner items updated today)
        $todayStart = strtotime('today midnight');
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
           ->andWhere($qb->expr()->gte('last_reviewed', $qb->createNamedParameter($todayStart)));
        $result = $qb->executeQuery();
        $cardsToday = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        // Sessions today
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($todayStart)));
        $result = $qb->executeQuery();
        $sessionsToday = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        $data = [
            'xp' => $xp,
            'streak' => $streak,
            'xp_multiplier' => $xpMultiplier,
            'badges' => $badges,
            'progress' => $progress,
            'missions' => $missions,
            'stats' => [
                'total_sessions' => (int)($statsRow['total_sessions'] ?? 0),
                'total_mastered' => (int)($statsRow['total_mastered'] ?? 0),
            ],
            'daily_progress' => [
                'cards_reviewed_today' => $cardsToday,
                'daily_goal' => $dailyGoal,
                'goal_reached' => $cardsToday >= $dailyGoal,
                'sessions_today' => $sessionsToday,
            ],
        ];

        $cache->set($cacheKey, $data, 30);

        return new DataResponse($data);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function missions(): DataResponse {
        try {
            return new DataResponse($this->missionService->getMissions((string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Failed to load missions'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function claimMission(string $missionKey): DataResponse {
        try {
            $result = $this->missionService->claimMission((string)$this->userId, $missionKey);
            $this->invalidateUserStateCache();
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Failed to claim mission'], 400);
        }
    }

    private function userStateCacheKey(): string {
        return self::USER_STATE_CACHE_PREFIX . (string)$this->userId;
    }

    private function invalidateUserStateCache(): void {
        $this->cacheFactory->createDistributed(self::CACHE_NAMESPACE)->remove($this->userStateCacheKey());
    }
}
