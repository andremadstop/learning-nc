<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use Exception;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\PoolMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;

class QuestionService {
    private $questionMapper;
    private $answerMapper;
    private $shareMapper;
    private $poolMapper;
    private $db;

    public function __construct(
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolShareMapper $shareMapper,
        PoolMapper $poolMapper,
        IDBConnection $db
    ) {
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->shareMapper = $shareMapper;
        $this->poolMapper = $poolMapper;
        $this->db = $db;
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

    private function canEditPool(int $poolId, string $userId): bool {
        try {
            $this->poolMapper->find($poolId, $userId);
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
            return $share !== null && $share->getPermission() === 'edit';
        }
    }

    // FIX #11 MEDIUM: Input validation for question text and answers
    private function validateQuestionInput(string $text, array $answers): void {
        if (mb_strlen($text) < 1 || mb_strlen($text) > 5000) {
            throw new \InvalidArgumentException('Question text must be 1-5000 characters');
        }
        if (count($answers) < 2 || count($answers) > 8) {
            throw new \InvalidArgumentException('Must have 2-8 answers');
        }
        $correctCount = 0;
        foreach ($answers as $a) {
            if (!isset($a['text']) || !is_string($a['text']) || trim($a['text']) === '') {
                throw new \InvalidArgumentException('Each answer needs non-empty text');
            }
            if (mb_strlen($a['text']) > 2000) {
                throw new \InvalidArgumentException('Answer text must be max 2000 characters');
            }
            if (!empty($a['is_correct'])) {
                $correctCount++;
            }
        }
        if ($correctCount !== 1) {
            throw new \InvalidArgumentException('Exactly one correct answer required');
        }
    }

    public function findByPool(int $poolId, string $userId): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolId($poolId);

        // FIX3-ME-1: Batch-load all answers instead of N+1
        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        $result = [];
        foreach ($questions as $question) {
            $questionData = $question->jsonSerialize();
            $answers = $answersGrouped[$question->getId()] ?? [];
            $questionData['answers'] = array_map(fn($a) => $a->jsonSerialize(), $answers);
            $result[] = $questionData;
        }

        return $result;
    }

    // FIX #10: Paginated version for large pools
    public function findByPoolPaged(int $poolId, string $userId, int $limit = 50, int $offset = 0): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolIdPaged($poolId, $limit, $offset);
        $total = $this->questionMapper->countByPoolId($poolId);

        // FIX3-ME-1: Batch-load answers
        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        $result = [];
        foreach ($questions as $question) {
            $questionData = $question->jsonSerialize();
            $answers = $answersGrouped[$question->getId()] ?? [];
            $questionData['answers'] = array_map(fn($a) => $a->jsonSerialize(), $answers);
            $result[] = $questionData;
        }

        return ['questions' => $result, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function find(int $id, string $userId): array {
        try {
            $question = $this->questionMapper->findById($id);
            if (!$this->hasPoolAccess($question->getPoolId(), $userId)) {
                throw new Exception('No access');
            }
            $answers = $this->answerMapper->findByQuestion($question->getId());

            $questionData = $question->jsonSerialize();
            $questionData['answers'] = array_map(fn($a) => $a->jsonSerialize(), $answers);

            return $questionData;
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function findEntity(int $id, string $userId): Question {
        $question = $this->questionMapper->findById($id);
        if (!$this->hasPoolAccess($question->getPoolId(), $userId)) {
            throw new Exception('No access');
        }
        return $question;
    }

    /**
     * SEC-MED-2: Public wrapper for canEditPool, used by TranslationController
     */
    public function verifyEditAccess(int $poolId, string $userId): void {
        if (!$this->canEditPool($poolId, $userId)) {
            throw new Exception('No edit access to this pool');
        }
    }

    public function setImagePath(int $questionId, ?string $imagePath, string $userId): Question {
        // FIX-ME-2: Use findById + canEditPool so shared-pool editors can set images
        $question = $this->questionMapper->findById($questionId);
        if (!$this->canEditPool($question->getPoolId(), $userId)) {
            throw new Exception('No edit access to this pool');
        }
        $question->setImagePath($imagePath);
        $question->setUpdatedAt(time());
        return $this->questionMapper->update($question);
    }

    public function create(int $poolId, string $userId, string $text, ?string $explanation,
                           ?string $difficulty, array $answers): array {
        if (!$this->canEditPool($poolId, $userId)) {
            throw new Exception('No edit access to this pool');
        }

        // FIX #11: Validate input
        $this->validateQuestionInput($text, $answers);

        // FIX #4 HIGH: Wrap in transaction
        $this->db->beginTransaction();
        try {
            $question = new Question();
            $question->setPoolId($poolId);
            $question->setUserId($userId);
            $question->setText($text);
            $question->setExplanation($explanation);
            $question->setDifficulty($difficulty);

            $question = $this->questionMapper->createOrUpdate($question);

            $savedAnswers = [];
            foreach ($answers as $index => $answerData) {
                $answer = new Answer();
                $answer->setQuestionId($question->getId());
                $answer->setText($answerData['text']);
                $answer->setIsCorrect($answerData['is_correct'] ?? false);
                $answer->setPosition($index);
                $savedAnswers[] = $this->answerMapper->createOrUpdate($answer);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $result = $question->jsonSerialize();
        $result['answers'] = array_map(fn($a) => $a->jsonSerialize(), $savedAnswers);

        return $result;
    }

    public function update(int $id, string $userId, string $text, ?string $explanation,
                          ?string $difficulty, array $answers): array {
        try {
            $question = $this->questionMapper->findById($id);
            if (!$this->canEditPool($question->getPoolId(), $userId)) {
                throw new Exception('No edit access to this pool');
            }

            // FIX #11: Validate input
            $this->validateQuestionInput($text, $answers);

            // FIX #4 HIGH: Wrap in transaction
            $this->db->beginTransaction();
            try {
                $question->setText($text);
                $question->setExplanation($explanation);
                $question->setDifficulty($difficulty);

                $question = $this->questionMapper->createOrUpdate($question);

                $this->answerMapper->deleteByQuestion($question->getId());

                $savedAnswers = [];
                foreach ($answers as $index => $answerData) {
                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($answerData['text']);
                    $answer->setIsCorrect($answerData['is_correct'] ?? false);
                    $answer->setPosition($index);
                    $savedAnswers[] = $this->answerMapper->createOrUpdate($answer);
                }

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $result = $question->jsonSerialize();
            $result['answers'] = array_map(fn($a) => $a->jsonSerialize(), $savedAnswers);

            return $result;
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function delete(int $id, string $userId): void {
        $question = $this->questionMapper->findById($id);
        if (!$this->canEditPool($question->getPoolId(), $userId)) {
            throw new Exception('No edit access to this pool');
        }
        // FIX #4: Transaction for delete (answers + question)
        $this->db->beginTransaction();
        try {
            $this->answerMapper->deleteByQuestion($id);
            $this->questionMapper->delete($question);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function search(string $query, string $userId, int $limit = 50): array {
        // FIX #11: Cap search limit
        $limit = max(1, min($limit, 100));
        return $this->questionMapper->searchByText($query, $userId, $limit);
    }
}
