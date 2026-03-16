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
            if ($share !== null) {
                return true;
            }
            return $this->hasCoursePoolAccess($poolId, $userId);
        }
    }

    private function hasCoursePoolAccess(int $poolId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('cp.id')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
               $expr->eq('cm.course_id', 'c.id'),
               $expr->eq('cm.user_id', $qb->createNamedParameter($userId))
           ))
           ->where($expr->eq('cp.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($expr->orX(
               $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
               $expr->isNotNull('cm.id')
           ))
           ->setMaxResults(1);

        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false;
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

    /**
     * Check if user has an active (uncompleted) exam session on a given pool.
     * Used to suppress is_correct in question read APIs to prevent exam oracle attacks.
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

    public static function isOpenAnswerCorrect(string $userAnswer, string $modelAnswer): bool {
        $user = mb_strtolower(trim($userAnswer));
        $model = mb_strtolower(trim($modelAnswer));
        if ($user === $model) return true;
        if ($user === '' || $model === '') return false;

        // Guard: reject unreasonably long input before expensive comparison
        if (mb_strlen($user) > 2000) return false;

        $len = mb_strlen($model);
        // Dynamic threshold: 0 for very short (≤4), 1 for short (≤8), ~20% for medium
        $maxDist = ($len <= 4) ? 0 : ($len <= 8 ? 1 : (int)floor($len * 0.2));

        // levenshtein() is byte-based and returns -1 for strings >255 bytes.
        // Use safe wrapper that checks byte length and return value.
        if ($len <= 255) {
            $dist = self::safeLevenshtein($user, $model);
            return $dist !== -1 && $dist <= $maxDist;
        }

        // Long text (>255 chars): length-ratio check + levenshtein on short prefix
        $lenUser = mb_strlen($user);
        if ($lenUser < $len * 0.7 || $lenUser > $len * 1.3) return false;
        $prefixLen = 100; // Short enough to stay under 255 bytes even with multibyte chars
        $dist = self::safeLevenshtein(mb_substr($user, 0, $prefixLen), mb_substr($model, 0, $prefixLen));
        return $dist !== -1 && $dist <= (int)floor($prefixLen * 0.2);
    }

    private static function safeLevenshtein(string $a, string $b): int {
        // levenshtein() operates on bytes and returns -1 if either string exceeds 255 bytes
        if (strlen($a) > 255 || strlen($b) > 255) {
            return -1;
        }
        return levenshtein($a, $b);
    }

    private function validateQuestionInput(string $text, array $answers, string $questionType = 'single'): void {
        if (mb_strlen($text) < 1 || mb_strlen($text) > 5000) {
            throw new \InvalidArgumentException('Question text must be 1-5000 characters');
        }

        if ($questionType === 'pbq') {
            // PBQ questions only need valid text; answers and config are managed separately
            return;
        }

        if ($questionType === 'open') {
            if (count($answers) !== 1) {
                throw new \InvalidArgumentException('Open questions need exactly 1 model answer');
            }
            $a = $answers[0];
            if (!isset($a['text']) || !is_string($a['text']) || trim($a['text']) === '') {
                throw new \InvalidArgumentException('Model answer text required');
            }
            if (mb_strlen($a['text']) > 2000) {
                throw new \InvalidArgumentException('Model answer max 2000 characters');
            }
            return;
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
            if (filter_var($a['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $correctCount++;
            }
        }
        if ($questionType === 'multi') {
            if ($correctCount < 1) {
                throw new \InvalidArgumentException('At least one correct answer required');
            }
        } else {
            if ($correctCount !== 1) {
                throw new \InvalidArgumentException('Exactly one correct answer required');
            }
        }
    }

    public function findByPool(int $poolId, string $userId): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolId($poolId);

        // TODO: Batch-load all answers instead of N+1
        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        // SECURITY: Strip is_correct during active exam to prevent oracle attack
        $stripCorrect = $this->hasActiveExamOnPool($poolId, $userId);

        $result = [];
        foreach ($questions as $question) {
            $questionData = $question->jsonSerialize();
            if ($stripCorrect) {
                unset($questionData['explanation']);
            }
            $answers = $answersGrouped[$question->getId()] ?? [];
            $questionData['answers'] = array_map(function ($a) use ($stripCorrect) {
                $row = $a->jsonSerialize();
                if ($stripCorrect) {
                    unset($row['is_correct']);
                }
                return $row;
            }, $answers);
            $result[] = $questionData;
        }

        return $result;
    }

    public function findByPoolPaged(int $poolId, string $userId, int $limit = 50, int $offset = 0): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolIdPaged($poolId, $limit, $offset);
        $total = $this->questionMapper->countByPoolId($poolId);

        // TODO: Batch-load answers
        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        // SECURITY: Strip is_correct during active exam to prevent oracle attack
        $stripCorrect = $this->hasActiveExamOnPool($poolId, $userId);

        $result = [];
        foreach ($questions as $question) {
            $questionData = $question->jsonSerialize();
            if ($stripCorrect) {
                unset($questionData['explanation']);
            }
            $answers = $answersGrouped[$question->getId()] ?? [];
            $questionData['answers'] = array_map(function ($a) use ($stripCorrect) {
                $row = $a->jsonSerialize();
                if ($stripCorrect) {
                    unset($row['is_correct']);
                }
                return $row;
            }, $answers);
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

            // SECURITY: Strip is_correct during active exam to prevent oracle attack
            $stripCorrect = $this->hasActiveExamOnPool($question->getPoolId(), $userId);

            $questionData = $question->jsonSerialize();
            if ($stripCorrect) {
                unset($questionData['explanation']);
            }
            $questionData['answers'] = array_map(function ($a) use ($stripCorrect) {
                $row = $a->jsonSerialize();
                if ($stripCorrect) {
                    unset($row['is_correct']);
                }
                return $row;
            }, $answers);

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
        // TODO: Use findById + canEditPool so shared-pool editors can set images
        $question = $this->questionMapper->findById($questionId);
        if (!$this->canEditPool($question->getPoolId(), $userId)) {
            throw new Exception('No edit access to this pool');
        }
        $question->setImagePath($imagePath);
        $question->setUpdatedAt(time());
        return $this->questionMapper->update($question);
    }

    public function create(int $poolId, string $userId, string $text, ?string $explanation,
                           ?string $difficulty, array $answers, ?string $questionType = null,
                           ?string $pbqSubtype = null, ?string $pbqConfig = null): array {
        if (!$this->canEditPool($poolId, $userId)) {
            throw new Exception('No edit access to this pool');
        }

        $questionType = $questionType ?? 'single';

        $this->validateQuestionInput($text, $answers, $questionType);

        $this->db->beginTransaction();
        try {
            $question = new Question();
            $question->setPoolId($poolId);
            $question->setUserId($userId);
            $question->setText($text);
            $question->setExplanation($explanation);
            $question->setDifficulty($difficulty);
            $question->setQuestionType($questionType);
            if ($pbqSubtype !== null) { $question->setPbqSubtype($pbqSubtype); }
            if ($pbqConfig !== null) { $question->setPbqConfig($pbqConfig); }
            if (!$question->getReviewStatus()) {
                $question->setReviewStatus('published');
            }

            $question = $this->questionMapper->createOrUpdate($question);

            $savedAnswers = [];
            foreach ($answers as $index => $answerData) {
                $answer = new Answer();
                $answer->setQuestionId($question->getId());
                $answer->setText($answerData['text']);
                $answer->setIsCorrect(filter_var($answerData['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN));
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
                          ?string $difficulty, array $answers, ?string $questionType = null,
                          ?string $pbqSubtype = null, ?string $pbqConfig = null): array {
        try {
            $question = $this->questionMapper->findById($id);
            if (!$this->canEditPool($question->getPoolId(), $userId)) {
                throw new Exception('No edit access to this pool');
            }

            $questionType = $questionType ?? ($question->getQuestionType() ?? 'single');

            $this->validateQuestionInput($text, $answers, $questionType);

            $this->db->beginTransaction();
            try {
                $question->setText($text);
                $question->setExplanation($explanation);
                $question->setDifficulty($difficulty);
                $question->setQuestionType($questionType);
                if ($pbqSubtype !== null) { $question->setPbqSubtype($pbqSubtype); }
                if ($pbqConfig !== null) { $question->setPbqConfig($pbqConfig); }

                $question = $this->questionMapper->createOrUpdate($question);

                $this->answerMapper->deleteByQuestion($question->getId());

                $savedAnswers = [];
                foreach ($answers as $index => $answerData) {
                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($answerData['text']);
                    $answer->setIsCorrect(filter_var($answerData['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN));
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

    private function normalizeReviewStatus(string $status): string {
        $allowed = ['draft', 'reviewed', 'published'];
        return in_array($status, $allowed, true) ? $status : 'draft';
    }

    public function setReviewStatus(int $id, string $reviewStatus, string $reviewerId, string $userId): array {
        $question = $this->questionMapper->findById($id);
        if (!$this->canEditPool($question->getPoolId(), $userId)) {
            throw new Exception('No edit access to this pool');
        }
        $status = $this->normalizeReviewStatus($reviewStatus);
        $question->setReviewStatus($status);
        $question->setReviewerId($reviewerId);
        $question->setReviewedAt(time());
        return $this->questionMapper->createOrUpdate($question)->jsonSerialize();
    }

    public function delete(int $id, string $userId): void {
        $question = $this->questionMapper->findById($id);
        if (!$this->canEditPool($question->getPoolId(), $userId)) {
            throw new Exception('No edit access to this pool');
        }
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
        $limit = max(1, min($limit, 100));
        return $this->questionMapper->searchByText($query, $userId, $limit);
    }
}
