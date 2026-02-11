<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\QuestionMapper;
use OCP\IDBConnection;

class TrainingService {
    private $db;
    private $questionMapper;

    public function __construct(IDBConnection $db, QuestionMapper $questionMapper) {
        $this->db = $db;
        $this->questionMapper = $questionMapper;
    }

    public function startSession(int $poolId, string $userId): array {
        $questions = $this->questionMapper->findByPool($poolId, $userId);
        
        if (empty($questions)) {
            throw new \Exception('No questions in this pool');
        }

        // Create session
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')
           ->values([
               'pool_id' => $qb->createNamedParameter($poolId),
               'user_id' => $qb->createNamedParameter($userId),
               'started_at' => $qb->createNamedParameter(time()),
               'total_questions' => $qb->createNamedParameter(count($questions)),
               'correct_answers' => $qb->createNamedParameter(0)
           ]);
        $qb->execute();
        
        $sessionId = $qb->getLastInsertId();
        
        // Shuffle questions
        shuffle($questions);
        
        return [
            'session_id' => $sessionId,
            'total_questions' => count($questions),
            'questions' => array_map(fn($q) => $q->jsonSerialize(), $questions)
        ];
    }

    public function submitAnswer(int $sessionId, int $questionId, int $answerId, string $userId): array {
        // Get correct answer
        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId)));
        $result = $qb->execute();
        $row = $result->fetch();
        $isCorrect = $row ? (bool)$row['is_correct'] : false;
        $result->closeCursor();

        // Record user answer
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_user_answers')
           ->values([
               'session_id' => $qb->createNamedParameter($sessionId),
               'question_id' => $qb->createNamedParameter($questionId),
               'answer_id' => $qb->createNamedParameter($answerId),
               'is_correct' => $qb->createNamedParameter($isCorrect, \PDO::PARAM_BOOL),
               'answered_at' => $qb->createNamedParameter(time())
           ]);
        $qb->execute();

        // Update session correct count
        if ($isCorrect) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_sessions')
               ->set('correct_answers', 'correct_answers + 1')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)));
            $qb->execute();
        }

        return ['is_correct' => $isCorrect];
    }

    public function completeSession(int $sessionId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_sessions')
           ->set('completed_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->execute();

        // Get session stats
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)));
        $result = $qb->execute();
        $session = $result->fetch();
        $result->closeCursor();

        return [
            'total_questions' => (int)$session['total_questions'],
            'correct_answers' => (int)$session['correct_answers'],
            'score_percentage' => round((int)$session['correct_answers'] / (int)$session['total_questions'] * 100)
        ];
    }
}
