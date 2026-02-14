<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class LeitnerService {
    private $db;
    private $poolMapper;
    private $shareMapper;

    public function __construct(IDBConnection $db, PoolMapper $poolMapper, PoolShareMapper $shareMapper) {
        $this->db = $db;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
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

    public function getDueQuestions(int $poolId, string $userId, int $limit = 10): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        // FIX #9: Cap limit
        $limit = max(1, min($limit, 100));

        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*', 'q.text', 'q.explanation', 'q.difficulty')
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

        foreach ($items as &$item) {
            $aqb = $this->db->getQueryBuilder();
            // FIX: Don't select is_correct — never leak answer key to client
            $aqb->select('id', 'text', 'position')
               ->from('learning_answers')
               ->where($aqb->expr()->eq('question_id', $aqb->createNamedParameter($item['question_id'])))
               ->orderBy('position', 'ASC');
            $aResult = $aqb->execute();
            $item['answers'] = $aResult->fetchAll();
            $aResult->closeCursor();
        }

        return $items;
    }

    public function answerQuestion(int $itemId, bool $correct, string $userId): array {
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

        $currentBox = (int)$item['box'];
        $newBox = $correct ? min(5, $currentBox + 1) : 1;

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

        return [
            'old_box' => $currentBox,
            'new_box' => $newBox,
            'next_review' => $nextReview,
            'correct' => $correct
        ];
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
