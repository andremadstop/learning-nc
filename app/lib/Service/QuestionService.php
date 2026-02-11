<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use Exception;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class QuestionService {
    private $questionMapper;
    private $answerMapper;

    public function __construct(QuestionMapper $questionMapper, AnswerMapper $answerMapper) {
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
    }

    public function findByPool(int $poolId, string $userId): array {
        $questions = $this->questionMapper->findByPool($poolId, $userId);
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
            $question = $this->questionMapper->find($id, $userId);
            $answers = $this->answerMapper->findByQuestion($question->getId());
            
            $questionData = $question->jsonSerialize();
            $questionData['answers'] = array_map(fn($a) => $a->jsonSerialize(), $answers);
            
            return $questionData;
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function create(int $poolId, string $userId, string $text, ?string $explanation, 
                           ?string $difficulty, array $answers): array {
        // Create question
        $question = new Question();
        $question->setPoolId($poolId);
        $question->setUserId($userId);
        $question->setText($text);
        $question->setExplanation($explanation);
        $question->setDifficulty($difficulty);
        
        $question = $this->questionMapper->createOrUpdate($question);
        
        // Create answers
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
            $question = $this->questionMapper->find($id, $userId);
            $question->setText($text);
            $question->setExplanation($explanation);
            $question->setDifficulty($difficulty);
            
            $question = $this->questionMapper->createOrUpdate($question);
            
            // Delete old answers and create new ones
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
        // Delete answers first
        $this->answerMapper->deleteByQuestion($id);
        // Then delete question
        $this->questionMapper->deleteById($id, $userId);
    }
}
