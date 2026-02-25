<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICacheFactory;
use OCP\IDBConnection;

class LeitnerService {
    private $db;
    private $poolMapper;
    private $shareMapper;
    private $badgeService;
    private $xpService;
    private $cacheFactory;

    public function __construct(IDBConnection $db, PoolMapper $poolMapper, PoolShareMapper $shareMapper, BadgeService $badgeService, XpService $xpService, ICacheFactory $cacheFactory) {
        $this->db = $db;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
        $this->badgeService = $badgeService;
        $this->xpService = $xpService;
        $this->cacheFactory = $cacheFactory;
    }

    private function hasPoolAccess(int $poolId, string $userId): bool {
        try {
            $this->poolMapper->find($poolId, $userId);
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
            return $share !== null;
        }
    }

    /**
     * Check if user has an active (uncompleted) exam session on a given pool.
     * Used to suppress correct answers in Leitner responses to prevent exam oracle attacks.
     */
    private function hasActiveExamOnPool(int $poolId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           ->andWhere($qb->expr()->isNull('completed_at'))
           ->setMaxResults(1);
        $result = $qb->execute();
        $hasExam = $result->fetch();
        $result->closeCursor();
        return $hasExam !== false;
    }

    public function getDueQuestions(int $poolId, string $userId, int $limit = 10): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $limit = max(1, min($limit, 100));

        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*', 'q.text', 'q.explanation', 'q.difficulty', 'q.question_type')
           ->from('learning_leitner_items', 'l')
           ->innerJoin('l', 'learning_questions', 'q', 'l.question_id = q.id')
           ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('l.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->lte('l.next_review', $qb->createNamedParameter($now)))
           ->orderBy('l.next_review', 'ASC')
           ->setMaxResults($limit);

        $result = $qb->execute();
        $items = $result->fetchAll();
        $result->closeCursor();

        // FIX-LO-2: Batch-load answers for all questions at once instead of N+1
        if (!empty($items)) {
            $questionIds = array_unique(array_column($items, 'question_id'));
            $aqb = $this->db->getQueryBuilder();
            $aqb->select('id', 'question_id', 'text', 'position')
               ->from('learning_answers')
               ->where($aqb->expr()->in('question_id', $aqb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
               ->orderBy('position', 'ASC');
            $aResult = $aqb->execute();
            $allAnswers = $aResult->fetchAll();
            $aResult->closeCursor();

            // Group answers by question_id
            $answersByQuestion = [];
            foreach ($allAnswers as $answer) {
                $answersByQuestion[$answer['question_id']][] = $answer;
            }

            foreach ($items as &$item) {
                $item['answers'] = $answersByQuestion[$item['question_id']] ?? [];
            }
        }

        return $items;
    }

    public function answerQuestion(int $itemId, ?int $answerId, string $userId, ?array $answerIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        $item = $result->fetch();
        $result->closeCursor();

        if (!$item) {
            throw new \Exception('Item not found');
        }

        $questionId = (int)$item['question_id'];

        // Multi-select path
        if (is_array($answerIds) && count($answerIds) > 0) {
            // Validate all answer IDs belong to this question
            foreach ($answerIds as $aid) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('id')
                   ->from('learning_answers')
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$aid)))
                   ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
                $vResult = $qb->execute();
                $vRow = $vResult->fetch();
                $vResult->closeCursor();
                if (!$vRow) {
                    throw new \Exception('Answer not found for this question');
                }
            }

            // Get correct answer IDs
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'text')
               ->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
               ->orderBy('position', 'ASC');
            $cResult = $qb->execute();
            $correctRows = $cResult->fetchAll();
            $cResult->closeCursor();

            $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
            $userIds = array_map('intval', $answerIds);
            sort($correctIds);
            sort($userIds);
            $correct = ($userIds === $correctIds);
        } else {
            // Single-select path
            $qb = $this->db->getQueryBuilder();
            $qb->select('is_correct')
               ->from('learning_answers')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId)))
               ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
            $ansResult = $qb->execute();
            $ansRow = $ansResult->fetch();
            $ansResult->closeCursor();

            if (!$ansRow) {
                throw new \Exception('Answer not found for this question');
            }

            $correct = filter_var($ansRow['is_correct'], FILTER_VALIDATE_BOOLEAN);
        }

        $currentBox = (int)$item['box'];
        $newBox = $correct ? min(5, $currentBox + 1) : 1;

        // Handle Box-5 demotion: decrement total_mastered when card falls out of Box 5
        if ($currentBox === 5 && $newBox < 5) {
            $dqb = $this->db->getQueryBuilder();
            $dqb->update('learning_user_stats')
                ->set('total_mastered', $dqb->createFunction('CASE WHEN total_mastered > 0 THEN total_mastered - 1 ELSE 0 END'))
                ->set('updated_at', $dqb->createNamedParameter(time()))
                ->where($dqb->expr()->eq('user_id', $dqb->createNamedParameter($userId)));
            $demoted = $dqb->execute();

            // If no stats row exists (Leitner-only user), create one from source of truth
            if ($demoted === 0) {
                $this->xpService->updateUserStats($userId);
            }
        }

        $intervals = [
            1 => 0,
            2 => 86400,
            3 => 259200,
            4 => 604800,
            5 => 1209600
        ];
        $nextReview = time() + $intervals[$newBox];

        $correctCount = (int)$item['correct_count'] + ($correct ? 1 : 0);
        $incorrectCount = (int)$item['incorrect_count'] + ($correct ? 0 : 1);

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_leitner_items')
           ->set('box', $qb->createNamedParameter($newBox))
           ->set('next_review', $qb->createNamedParameter($nextReview))
           ->set('last_reviewed', $qb->createNamedParameter(time()))
           ->set('correct_count', $qb->createNamedParameter($correctCount))
           ->set('incorrect_count', $qb->createNamedParameter($incorrectCount))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId)));
        $qb->execute();

        $response = [
            'old_box' => $currentBox,
            'new_box' => $newBox,
            'next_review' => $nextReview,
            'correct' => $correct,
            'newly_earned_badges' => [],
        ];

        // Award Leitner XP: 5 XP per correct answer
        if ($correct) {
            $leitnerXp = 5;

            // Check mastery badges and update stats when card reaches Box 5
            if ($newBox === 5) {
                $leitnerXp += 25; // Box-5 mastery bonus
                $response['newly_earned_badges'] = $this->badgeService->checkAndAward($userId, 'leitner_mastery', []);

                // Increment denormalized mastered count
                // If no stats row exists, incrementLeitnerXp will create one via updateUserStats()
                $mqb = $this->db->getQueryBuilder();
                $mqb->update('learning_user_stats')
                    ->set('total_mastered', $mqb->createFunction('total_mastered + 1'))
                    ->set('updated_at', $mqb->createNamedParameter(time()))
                    ->where($mqb->expr()->eq('user_id', $mqb->createNamedParameter($userId)));
                $mqb->execute();
            }

            // Sync Leitner XP to denormalized stats
            $this->xpService->incrementLeitnerXp($userId, $leitnerXp);
        } elseif ($newBox === 5) {
            // Edge case: shouldn't happen (wrong answer can't promote to box 5), but defensive
            $response['newly_earned_badges'] = $this->badgeService->checkAndAward($userId, 'leitner_mastery', []);
        }

        // Invalidate cache on every answer (box/progress changes affect user state)
        $this->cacheFactory->createDistributed('learning')->remove('user_state_' . $userId);

        // SECURITY: Suppress correct answer details during active exam to prevent oracle attack
        $poolId = (int)$item['pool_id'];
        if ($this->hasActiveExamOnPool($poolId, $userId)) {
            return $response;
        }

        // Return all correct answers for frontend display (only when no active exam)
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
           ->orderBy('position', 'ASC');
        $correctResult = $qb->execute();
        $allCorrectRows = $correctResult->fetchAll();
        $correctResult->closeCursor();

        $allCorrectIds = array_map(fn($r) => (int)$r['id'], $allCorrectRows);
        $allCorrectTexts = array_map(fn($r) => $r['text'], $allCorrectRows);

        $response['correct_answer_id'] = !empty($allCorrectIds) ? $allCorrectIds[0] : null;
        $response['correct_answer_text'] = !empty($allCorrectTexts) ? $allCorrectTexts[0] : '';
        $response['correct_answer_ids'] = $allCorrectIds;
        $response['correct_answer_texts'] = $allCorrectTexts;

        return $response;
    }

    public function initializePool(int $poolId, string $userId): int {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('q.id')
           ->from('learning_questions', 'q')
           ->leftJoin('q', 'learning_leitner_items', 'l',
                      $qb->expr()->andX(
                          $qb->expr()->eq('l.question_id', 'q.id'),
                          $qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId))
                      ))
           ->where($qb->expr()->eq('q.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->isNull('l.id'));

        $result = $qb->execute();
        $questions = $result->fetchAll();
        $result->closeCursor();

        $count = 0;
        foreach ($questions as $question) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_leitner_items')
               ->values([
                   'user_id' => $qb->createNamedParameter($userId),
                   'pool_id' => $qb->createNamedParameter($poolId),
                   'question_id' => $qb->createNamedParameter($question['id']),
                   'box' => $qb->createNamedParameter(1),
                   'next_review' => $qb->createNamedParameter(time()),
                   'correct_count' => $qb->createNamedParameter(0),
                   'incorrect_count' => $qb->createNamedParameter(0)
               ]);
            $qb->execute();
            $count++;
        }

        return $count;
    }

    public function getStats(int $poolId, string $userId): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('box', $qb->createFunction('COUNT(*) as count'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->groupBy('box');

        $result = $qb->execute();
        $boxes = $result->fetchAll();
        $result->closeCursor();

        $stats = ['box_1' => 0, 'box_2' => 0, 'box_3' => 0, 'box_4' => 0, 'box_5' => 0];
        foreach ($boxes as $box) {
            $stats['box_' . $box['box']] = (int)$box['count'];
        }

        $total = array_sum($stats);
        $stats['total'] = $total;
        $stats['mastered'] = $stats['box_5'];
        $stats['mastery_percentage'] = $total > 0 ? round($stats['box_5'] / $total * 100) : 0;

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as due_count'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->lte('next_review', $qb->createNamedParameter($now)));
        $result = $qb->execute();
        $stats['due_count'] = (int)$result->fetch()['due_count'];
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select(
               $qb->createFunction('COALESCE(SUM(correct_count), 0) as total_correct'),
               $qb->createFunction('COALESCE(SUM(correct_count + incorrect_count), 0) as total_answered')
           )
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
        $result = $qb->execute();
        $accRow = $result->fetch();
        $result->closeCursor();
        $stats['total_correct'] = (int)$accRow['total_correct'];
        $stats['total_answered'] = (int)$accRow['total_answered'];
        $stats['accuracy'] = $stats['total_answered'] > 0
            ? round($stats['total_correct'] / $stats['total_answered'] * 100)
            : 0;

        return $stats;
    }
}
