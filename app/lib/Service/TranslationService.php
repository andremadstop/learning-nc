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

    private const ALLOWED_LANGS = ['de', 'en', 'ru', 'ar'];

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

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false;
    }

    private function canEditPool(int $poolId, string $userId): bool {
        try {
            $this->poolMapper->find($poolId, $userId);
            return true; // Owner always has edit access
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
            return $share !== null && $share->getPermission() === 'edit';
        }
    }

    /**
     * SEC-MED-2: Verify user has edit access to the pool containing this answer
     */
    public function verifyAnswerEditAccess(int $answerId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.question_id', 'q.pool_id')
           ->from('learning_answers', 'a')
           ->innerJoin('a', 'learning_questions', 'q', 'a.question_id = q.id')
           ->where($qb->expr()->eq('a.id', $qb->createNamedParameter($answerId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception('Answer not found');
        }

        if (!$this->canEditPool((int)$row['pool_id'], $userId)) {
            throw new \Exception('No edit access to this answer');
        }
    }

    public function verifyAnswerAccess(int $answerId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.question_id', 'q.pool_id')
           ->from('learning_answers', 'a')
           ->innerJoin('a', 'learning_questions', 'q', 'a.question_id = q.id')
           ->where($qb->expr()->eq('a.id', $qb->createNamedParameter($answerId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception('Answer not found');
        }

        if (!$this->hasPoolAccess((int)$row['pool_id'], $userId)) {
            throw new \Exception('No access to this answer');
        }
    }

    /**
     * @param bool $suppressExplanation AUDIT MED-06: withhold translated explanations during an
     *   active exam (the base find() strips the source explanation; the translation table must
     *   not re-leak it). The caller passes QuestionService::isExamActiveForQuestion().
     */
    public function getQuestionTranslations(int $questionId, bool $suppressExplanation = false): array {
        $rows = $this->questionTransMapper->findByQuestion($questionId);
        if (!$suppressExplanation) {
            return $rows;
        }
        return array_map(static function ($t): array {
            $data = $t->jsonSerialize();
            unset($data['explanation']);
            return $data;
        }, $rows);
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

    public function normalizeLang(?string $lang): ?string {
        $lang = trim((string)$lang);
        if ($lang === '') {
            return null;
        }
        return in_array($lang, self::ALLOWED_LANGS, true) ? $lang : null;
    }

    public function translateQuestion(array $questionData, string $lang): array {
        $translated = $this->translateQuestions([$questionData], $lang);
        return $translated[0] ?? $questionData;
    }

    public function translateQuestions(array $questions, ?string $lang): array {
        $lang = $this->normalizeLang($lang);
        if ($lang === null || empty($questions)) {
            return $questions;
        }

        $questionIds = [];
        $answerIds = [];
        foreach ($questions as $questionData) {
            if (!$this->isTranslatableQuestion($questionData)) {
                continue;
            }
            $questionId = $this->extractQuestionId($questionData);
            if ($questionId > 0) {
                $questionIds[] = $questionId;
            }
            foreach ($questionData['answers'] ?? [] as $answerData) {
                $answerId = (int)($answerData['id'] ?? 0);
                if ($answerId > 0) {
                    $answerIds[] = $answerId;
                }
            }
        }

        $questionTranslations = $this->getQuestionTranslationMap(array_values(array_unique($questionIds)), $lang);
        $answerTranslations = $this->getAnswerTranslationMap(array_values(array_unique($answerIds)), $lang);

        return array_map(function (array $questionData) use ($questionTranslations, $answerTranslations) {
            return $this->applyTranslationsToQuestion($questionData, $questionTranslations, $answerTranslations);
        }, $questions);
    }

    public function translateAnswerRows(array $answerRows, ?string $lang): array {
        $lang = $this->normalizeLang($lang);
        if ($lang === null || empty($answerRows)) {
            return $answerRows;
        }

        $answerIds = [];
        foreach ($answerRows as $answerRow) {
            $answerId = (int)($answerRow['id'] ?? 0);
            if ($answerId > 0) {
                $answerIds[] = $answerId;
            }
        }

        $answerTranslations = $this->getAnswerTranslationMap(array_values(array_unique($answerIds)), $lang);

        return array_map(function (array $answerRow) use ($answerTranslations) {
            $answerId = (int)($answerRow['id'] ?? 0);
            if ($answerId > 0 && isset($answerTranslations[$answerId]) && $answerTranslations[$answerId] !== '') {
                $answerRow['text'] = $answerTranslations[$answerId];
            }
            return $answerRow;
        }, $answerRows);
    }

    public function translateReviewEntries(array $reviewEntries, ?string $lang): array {
        $lang = $this->normalizeLang($lang);
        if ($lang === null || empty($reviewEntries)) {
            return $reviewEntries;
        }

        $questionIds = [];
        $answerIds = [];
        foreach ($reviewEntries as $entry) {
            $questionId = (int)($entry['questionId'] ?? 0);
            if ($questionId > 0 && (($entry['questionType'] ?? '') !== 'pbq')) {
                $questionIds[] = $questionId;
            }
            foreach (($entry['correct_answer_ids'] ?? []) as $answerId) {
                $answerId = (int)$answerId;
                if ($answerId > 0) {
                    $answerIds[] = $answerId;
                }
            }
            foreach (($entry['answers'] ?? []) as $answerRow) {
                $answerId = (int)($answerRow['id'] ?? 0);
                if ($answerId > 0) {
                    $answerIds[] = $answerId;
                }
            }
        }

        $questionTranslations = $this->getQuestionTranslationMap(array_values(array_unique($questionIds)), $lang);
        $answerTranslations = $this->getAnswerTranslationMap(array_values(array_unique($answerIds)), $lang);

        return array_map(function (array $entry) use ($questionTranslations, $answerTranslations) {
            $questionId = (int)($entry['questionId'] ?? 0);
            $isPbq = ($entry['questionType'] ?? '') === 'pbq';

            if (!$isPbq && $questionId > 0 && isset($questionTranslations[$questionId])) {
                $translation = $questionTranslations[$questionId];
                if (($translation['text'] ?? '') !== '') {
                    $entry['questionText'] = $translation['text'];
                }
            }

            if (!empty($entry['answers']) && is_array($entry['answers'])) {
                $entry['answers'] = array_map(function (array $answerRow) use ($answerTranslations) {
                    $answerId = (int)($answerRow['id'] ?? 0);
                    if ($answerId > 0 && isset($answerTranslations[$answerId]) && $answerTranslations[$answerId] !== '') {
                        $answerRow['text'] = $answerTranslations[$answerId];
                    }
                    return $answerRow;
                }, $entry['answers']);
            }

            if (!empty($entry['correct_answer_ids']) && is_array($entry['correct_answer_ids'])) {
                $translatedTexts = [];
                foreach ($entry['correct_answer_ids'] as $index => $answerId) {
                    $answerId = (int)$answerId;
                    if ($answerId > 0 && isset($answerTranslations[$answerId]) && $answerTranslations[$answerId] !== '') {
                        $translatedTexts[] = $answerTranslations[$answerId];
                    } elseif (isset($entry['correct_answer_texts'][$index])) {
                        $translatedTexts[] = $entry['correct_answer_texts'][$index];
                    }
                }
                if (!empty($translatedTexts)) {
                    $entry['correct_answer_texts'] = $translatedTexts;
                }
            }

            return $entry;
        }, $reviewEntries);
    }

    private function validateLang(string $lang): void {
        if (!in_array($lang, self::ALLOWED_LANGS)) {
            throw new \InvalidArgumentException('Language must be one of: ' . implode(', ', self::ALLOWED_LANGS));
        }
    }

    private function extractQuestionId(array $questionData): int {
        return (int)($questionData['question_id'] ?? $questionData['id'] ?? 0);
    }

    private function isTranslatableQuestion(array $questionData): bool {
        if (($questionData['question_type'] ?? '') === 'pbq') {
            return false;
        }

        $pbqSubtype = $questionData['pbq_subtype'] ?? null;
        return $pbqSubtype === null || $pbqSubtype === '';
    }

    private function getQuestionTranslationMap(array $questionIds, string $lang): array {
        $translations = $this->questionTransMapper->findByQuestionsAndLang($questionIds, $lang);
        $map = [];
        foreach ($translations as $translation) {
            $map[(int)$translation->getQuestionId()] = [
                'text' => (string)$translation->getText(),
                'explanation' => $translation->getExplanation(),
            ];
        }
        return $map;
    }

    private function getAnswerTranslationMap(array $answerIds, string $lang): array {
        $translations = $this->answerTransMapper->findByAnswersAndLang($answerIds, $lang);
        $map = [];
        foreach ($translations as $translation) {
            $map[(int)$translation->getAnswerId()] = (string)$translation->getText();
        }
        return $map;
    }

    private function applyTranslationsToQuestion(array $questionData, array $questionTranslations, array $answerTranslations): array {
        if (!$this->isTranslatableQuestion($questionData)) {
            return $questionData;
        }

        $questionId = $this->extractQuestionId($questionData);
        if ($questionId > 0 && isset($questionTranslations[$questionId])) {
            $translation = $questionTranslations[$questionId];
            if (($translation['text'] ?? '') !== '') {
                $questionData['text'] = $translation['text'];
            }
            if (($translation['explanation'] ?? null) !== null && $translation['explanation'] !== '') {
                $questionData['explanation'] = $translation['explanation'];
            }
        }

        if (!empty($questionData['answers']) && is_array($questionData['answers'])) {
            $questionData['answers'] = array_map(function (array $answerData) use ($answerTranslations) {
                $answerId = (int)($answerData['id'] ?? 0);
                if ($answerId > 0 && isset($answerTranslations[$answerId]) && $answerTranslations[$answerId] !== '') {
                    $answerData['text'] = $answerTranslations[$answerId];
                }
                return $answerData;
            }, $questionData['answers']);
        }

        return $questionData;
    }
}
