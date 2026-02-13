<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class TrainingService {
    private $db;
    private $questionMapper;
    private $answerMapper;
    private $poolMapper;
    private $shareMapper;

    public function __construct(
        IDBConnection $db,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper
    ) {
        $this->db = $db;
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
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

    private function verifySessionOwnership(int $sessionId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        $session = $result->fetch();
        $result->closeCursor();

        if (!$session) {
            throw new \Exception('Session not found');
        }
        return $session;
    }

    public function startSession(int $poolId, string $userId): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolId($poolId);

        if (empty($questions)) {
            throw new \Exception('No questions in this pool');
        }

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
        shuffle($questions);

        $questionsWithAnswers = [];
        foreach ($questions as $q) {
            $qData = $q->jsonSerialize();
            $answers = $this->answerMapper->findByQuestion($q->getId());
            $qData['answers'] = array_map(fn($a) => $a->jsonSerialize(), $answers);
            $questionsWithAnswers[] = $qData;
        }

        return [
            'session_id' => $sessionId,
            'total_questions' => count($questions),
            'questions' => $questionsWithAnswers
        ];
    }

    public function submitAnswer(int $sessionId, int $questionId, int $answerId, string $userId): array {
        $this->verifySessionOwnership($sessionId, $userId);

        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception('Answer not found for this question');
        }

        $isCorrect = (bool)$row['is_correct'];

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
        $this->verifySessionOwnership($sessionId, $userId);

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_sessions')
           ->set('completed_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->execute();

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
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
