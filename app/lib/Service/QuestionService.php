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

class QuestionService {
    private $questionMapper;
    private $answerMapper;
    private $shareMapper;
    private $poolMapper;

    public function __construct(
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolShareMapper $shareMapper,
        PoolMapper $poolMapper
    ) {
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->shareMapper = $shareMapper;
        $this->poolMapper = $poolMapper;
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

    public function findByPool(int $poolId, string $userId): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolId($poolId);
        $result = [];

        foreach ($questions as $question) {
            $answers = $this->answerMapper->findByQuestion($question->getId());
            $questionData = $question->jsonSerialize();
            $questionData['answers'] = array_map(fn($a) => $a->jsonSerialize(), $answers);
            $result[] = $questionData;
        }

        return $result;
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

    public function setImagePath(int $questionId, ?string $imagePath, string $userId): Question {
        $question = $this->questionMapper->find($questionId, $userId);
        $question->setImagePath($imagePath);
        $question->setUpdatedAt(time());
        return $this->questionMapper->update($question);
    }

    public function create(int $poolId, string $userId, string $text, ?string $explanation,
                           ?string $difficulty, array $answers): array {
        if (!$this->canEditPool($poolId, $userId)) {
            throw new Exception('No edit access to this pool');
        }

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
        $this->answerMapper->deleteByQuestion($id);
        $this->questionMapper->delete($question);
    }
}
