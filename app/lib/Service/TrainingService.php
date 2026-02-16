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

    public function startSession(int $poolId, string $userId, ?int $limit = null): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolId($poolId);

        if (empty($questions)) {
            throw new \Exception('No questions in this pool');
        }

        shuffle($questions);

        // Apply question limit (for exam mode)
        if ($limit !== null && $limit > 0 && $limit < count($questions)) {
            $questions = array_slice($questions, 0, $limit);
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

        $questionsWithAnswers = [];
        foreach ($questions as $q) {
            $qData = $q->jsonSerialize();
            $answers = $this->answerMapper->findByQuestion($q->getId());
            // FIX #1 CRITICAL: Strip is_correct from answers — never leak answer key to client
            $qData['answers'] = array_map(static function ($a) {
                $row = $a->jsonSerialize();
                unset($row['is_correct']);
                return $row;
            }, $answers);
            $questionsWithAnswers[] = $qData;
        }

        return [
            'session_id' => $sessionId,
            'total_questions' => count($questions),
            'questions' => $questionsWithAnswers
        ];
    }

    public function submitAnswer(int $sessionId, int $questionId, int $answerId, string $userId): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);

        // FIX #2 HIGH: Validate questionId belongs to this session's pool (IDOR prevention)
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_questions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter((int)$session['pool_id'])));
        $checkResult = $qb->execute();
        $questionRow = $checkResult->fetch();
        $checkResult->closeCursor();
        if (!$questionRow) {
            throw new \Exception('Question not in this session pool');
        }

        // FIX #3 HIGH: Prevent duplicate answer submissions for same session/question
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_user_answers')
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
        $dupResult = $qb->execute();
        $dupRow = $dupResult->fetch();
        $dupResult->closeCursor();
        if ($dupRow) {
            throw new \Exception('Question already answered in this session');
        }

        // Validate answer belongs to question
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

        // Return correct answer info so frontend doesn't need is_correct in question data
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
        $correctResult = $qb->execute();
        $correctRow = $correctResult->fetch();
        $correctResult->closeCursor();

        return [
            'is_correct' => $isCorrect,
            'correct_answer_id' => $correctRow ? (int)$correctRow['id'] : null,
            'correct_answer_text' => $correctRow ? $correctRow['text'] : '',
        ];
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
