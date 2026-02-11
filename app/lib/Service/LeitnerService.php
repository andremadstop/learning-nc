<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\IDBConnection;

class LeitnerService {
    private $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function getDueQuestions(int $poolId, string $userId, int $limit = 10): array {
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
        
        return $items;
    }

    public function answerQuestion(int $itemId, bool $correct, string $userId): array {
        // Get current item
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
        
        // Calculate next review date
        $intervals = [
            1 => 0,      // Immediate review
            2 => 86400,  // 1 day
            3 => 259200, // 3 days
            4 => 604800, // 7 days
            5 => 1209600 // 14 days
        ];
        $nextReview = time() + $intervals[$newBox];

        // Update item
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_leitner_items')
           ->set('box', $qb->createNamedParameter($newBox))
           ->set('next_review', $qb->createNamedParameter($nextReview))
           ->set('last_reviewed', $qb->createNamedParameter(time()))
           ->set('correct_count', $correct ? 'correct_count + 1' : 'correct_count')
           ->set('incorrect_count', $correct ? 'incorrect_count' : 'incorrect_count + 1')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId)));
        $qb->execute();

        return [
            'old_box' => $currentBox,
            'new_box' => $newBox,
            'next_review' => $nextReview
        ];
    }

    public function initializePool(int $poolId, string $userId): int {
        // Get all questions not yet in Leitner
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
                   'next_review' => $qb->createNamedParameter(time())
               ]);
            $qb->execute();
            $count++;
        }

        return $count;
    }

    public function getStats(int $poolId, string $userId): array {
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

        return $stats;
    }
}
