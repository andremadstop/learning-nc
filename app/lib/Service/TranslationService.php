<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\QuestionTranslation;
use OCA\Learning\Db\QuestionTranslationMapper;
use OCA\Learning\Db\AnswerTranslation;
use OCA\Learning\Db\AnswerTranslationMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class TranslationService {
    private QuestionTranslationMapper $questionTransMapper;
    private AnswerTranslationMapper $answerTransMapper;
    private QuestionMapper $questionMapper;
    private PoolMapper $poolMapper;
    private PoolShareMapper $shareMapper;
    private IDBConnection $db;

    private const ALLOWED_LANGS = ['de', 'en', 'ru'];

    public function __construct(
        QuestionTranslationMapper $questionTransMapper,
        AnswerTranslationMapper $answerTransMapper,
        QuestionMapper $questionMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper,
        IDBConnection $db
    ) {
        $this->questionTransMapper = $questionTransMapper;
        $this->answerTransMapper = $answerTransMapper;
        $this->questionMapper = $questionMapper;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
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

    public function verifyAnswerAccess(int $answerId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.question_id', 'q.pool_id')
           ->from('learning_answers', 'a')
           ->innerJoin('a', 'learning_questions', 'q', 'a.question_id = q.id')
           ->where($qb->expr()->eq('a.id', $qb->createNamedParameter($answerId)));
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception('Answer not found');
        }

        if (!$this->hasPoolAccess((int)$row['pool_id'], $userId)) {
            throw new \Exception('No access to this answer');
        }
    }

    public function getQuestionTranslations(int $questionId): array {
        return $this->questionTransMapper->findByQuestion($questionId);
    }

    public function setQuestionTranslation(int $questionId, string $lang, string $text, ?string $explanation = null): QuestionTranslation {
        $this->validateLang($lang);

        $existing = $this->questionTransMapper->findByQuestionAndLang($questionId, $lang);
        if ($existing !== null) {
            $existing->setText($text);
            $existing->setExplanation($explanation);
            return $this->questionTransMapper->update($existing);
        }

        $trans = new QuestionTranslation();
        $trans->setQuestionId($questionId);
        $trans->setLang($lang);
        $trans->setText($text);
        $trans->setExplanation($explanation);
        $trans->setCreatedAt(time());
        return $this->questionTransMapper->insert($trans);
    }

    public function deleteQuestionTranslation(int $questionId, string $lang): void {
        $existing = $this->questionTransMapper->findByQuestionAndLang($questionId, $lang);
        if ($existing !== null) {
            $this->questionTransMapper->delete($existing);
        }
    }

    public function getAnswerTranslations(int $answerId): array {
        return $this->answerTransMapper->findByAnswer($answerId);
    }

    public function setAnswerTranslation(int $answerId, string $lang, string $text): AnswerTranslation {
        $this->validateLang($lang);

        $existing = $this->answerTransMapper->findByAnswerAndLang($answerId, $lang);
        if ($existing !== null) {
            $existing->setText($text);
            return $this->answerTransMapper->update($existing);
        }

        $trans = new AnswerTranslation();
        $trans->setAnswerId($answerId);
        $trans->setLang($lang);
        $trans->setText($text);
        $trans->setCreatedAt(time());
        return $this->answerTransMapper->insert($trans);
    }

    public function deleteAnswerTranslation(int $answerId, string $lang): void {
        $existing = $this->answerTransMapper->findByAnswerAndLang($answerId, $lang);
        if ($existing !== null) {
            $this->answerTransMapper->delete($existing);
        }
    }

    private function validateLang(string $lang): void {
        if (!in_array($lang, self::ALLOWED_LANGS)) {
            throw new \InvalidArgumentException('Language must be one of: ' . implode(', ', self::ALLOWED_LANGS));
        }
    }
}
