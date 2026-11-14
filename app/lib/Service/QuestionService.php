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
use OCA\Learning\Service\TranslationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class QuestionService {
    public const SCENARIO_MAX_LENGTH = 10000;

    /**
     * The question types the app knows: the three of the question form plus pbq. The only list —
     * the pool import, the question import and the import preview (importPreview.js
     * QUESTION_TYPES) check against it. Anything else is refused (GitHub #34).
     */
    public const QUESTION_TYPES = ['single', 'multi', 'open', 'pbq'];

    /**
     * Historical type names the imports read as a valid type (lower-case keys): true_false was
     * removed in v3.7.0, multiple was repaired to multi by migration Version007800. Mirrored in
     * importPreview.js TYPE_ALIASES.
     */
    public const TYPE_ALIASES = ['true_false' => 'single', 'multiple' => 'multi'];

    /**
     * Type names the imports read as "a choice question" without saying single or multi
     * (lower-case): single with one correct answer, multi with several — the rule for a question
     * without a type, except that `_meta.default_question_type` never applies to it.
     * "multiple_choice" is what app/examples/pools/subnetting-ip-adressierung-n10-009-de.json and many files from elsewhere
     * write. Mirrored in importPreview.js CHOICE_TYPE_NAMES.
     */
    public const CHOICE_TYPE_NAMES = ['multiple_choice'];

    private $questionMapper;
    private $answerMapper;
    private $shareMapper;
    private $poolMapper;
    private $db;
    private TranslationService $translationService;
    private LoggerInterface $logger;

    public function __construct(
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolShareMapper $shareMapper,
        PoolMapper $poolMapper,
        IDBConnection $db,
        TranslationService $translationService,
        LoggerInterface $logger
    ) {
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->shareMapper = $shareMapper;
        $this->poolMapper = $poolMapper;
        $this->db = $db;
        $this->translationService = $translationService;
        $this->logger = $logger;
    }

    /**
     * Read access to a pool: owned, shared with the user, or reachable through a course they are
     * in. Public because DuelService and GameshowService need exactly this check and had none —
     * see the note on their create paths. Duplicating it a sixth time was the alternative.
     */
    public function hasPoolAccess(int $poolId, string $userId): bool {
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
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
            return $share !== null && $share->getPermission() === 'edit';
        }
    }

    /**
     * Whether the question read APIs must withhold is_correct, explanation and the PBQ solution.
     * Only the pool's editors see them: the owner, an edit share and a course instructor of a course
     * carrying the pool (who can export the answer key anyway, verifyExportAccess()). Everyone else —
     * course members, read shares — gets the questions without the solution, with or without an exam.
     * Until the 2026-11 audit the solution was withheld only during a graded exam, so a learner read
     * the whole answer key before starting it (high finding, answer key).
     *
     * The editors must keep it: the question editor loads through these paths and saves pbq_config,
     * answers and explanation back as received — a stripped copy would erase the solution on save
     * (audit 2026-11, D1). Learners practise through Training/Leitner and grade through their own
     * endpoints (and checkAnswer() for the bot modes), which never need the key on the client.
     */
    private function mustWithholdSolution(int $poolId, string $userId): bool {
        return !$this->canEditPool($poolId, $userId) && !$this->isCourseInstructorForPool($poolId, $userId);
    }

    /**
     * Check if user has an active (uncompleted) exam session on a given pool.
     * Used to suppress is_correct, explanation and the PBQ solution in question read APIs to
     * prevent exam oracle attacks.
     */
    private function hasActiveExamOnPool(int $poolId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           // Practice exams never count and must not lock a pool (an untimed one could stay open).
           ->andWhere($qb->expr()->isNull('exam_kind'))
           ->andWhere($qb->expr()->isNull('completed_at'))
           ->setMaxResults(1);
        $result = $qb->executeQuery();
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

    /**
     * @param bool $checkType false only for update() of a question that keeps a type stored before
     *        unknown types were refused: it stays editable and is checked as before (as MCQ).
     */
    private function validateQuestionInput(string $text, array $answers, string $questionType = 'single', bool $checkType = true): void {
        if ($checkType && !in_array($questionType, self::QUESTION_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown question type');
        }
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

    private function normalizeOptional(?string $value, int $maxLength): ?string {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Question metadata field exceeds maximum length');
        }
        return $value;
    }

    /**
     * The input checks of create() without writing anything: throws the same
     * \InvalidArgumentException create() would throw for this text, these answers, this type,
     * this scenario and these metadata fields. The pool import (PoolBulkImportService) runs it in
     * its dry run and before each insert, so the preview never counts a question the real import
     * then refuses.
     *
     * @param array<array{text?: mixed, is_correct?: mixed}> $answers
     */
    public function validateForImport(string $text, array $answers, ?string $questionType, ?string $scenario,
                                      ?string $handbookKey = null, ?string $handbookTitle = null,
                                      ?string $examKey = null, ?string $chapterKey = null,
                                      ?string $chapterTitle = null, ?int $chapterOrder = null): void {
        $this->validateQuestionInput($text, $answers, $questionType ?? 'single');
        $this->normalizeScenario($scenario);
        $this->applyQuestionMetadata(new Question(), $handbookKey, $handbookTitle, $examKey, $chapterKey, $chapterTitle, $chapterOrder);
    }

    /**
     * Scenario / info text shown before the question (Codeberg #10). Line breaks are kept;
     * only surrounding whitespace is trimmed, and an empty text is stored as null.
     */
    private function normalizeScenario(?string $scenario): ?string {
        $scenario = trim((string)$scenario);
        if ($scenario === '') {
            return null;
        }
        if (mb_strlen($scenario) > self::SCENARIO_MAX_LENGTH) {
            throw new \InvalidArgumentException('Scenario must be max ' . self::SCENARIO_MAX_LENGTH . ' characters');
        }
        return $scenario;
    }

    private function normalizeChapterOrder(?int $chapterOrder): ?int {
        if ($chapterOrder === null) {
            return null;
        }
        if ($chapterOrder < 1 || $chapterOrder > 9999) {
            throw new \InvalidArgumentException('Chapter order must be between 1 and 9999');
        }
        return $chapterOrder;
    }

    private function applyQuestionMetadata(
        Question $question,
        ?string $handbookKey,
        ?string $handbookTitle,
        ?string $examKey,
        ?string $chapterKey,
        ?string $chapterTitle,
        ?int $chapterOrder
    ): void {
        $question->setHandbookKey($this->normalizeOptional($handbookKey, 64));
        $question->setHandbookTitle($this->normalizeOptional($handbookTitle, 255));
        $question->setExamKey($this->normalizeOptional($examKey, 64));
        $question->setChapterKey($this->normalizeOptional($chapterKey, 64));
        $question->setChapterTitle($this->normalizeOptional($chapterTitle, 255));
        $question->setChapterOrder($this->normalizeChapterOrder($chapterOrder));
    }

    public function findByPool(int $poolId, string $userId): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new Exception('Pool not found or no access');
        }

        $questions = $this->questionMapper->findByPoolId($poolId);

        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        // SECURITY: only the pool's editors receive the answer key (mustWithholdSolution)
        $stripCorrect = $this->mustWithholdSolution($poolId, $userId);

        $result = [];
        foreach ($questions as $question) {
            $questionData = $question->jsonSerialize();
            if ($stripCorrect) {
                unset($questionData['explanation']);
                $questionData['pbq_config'] = PbqSolutionStripper::strip($questionData['pbq_config'] ?? null);
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

        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        // SECURITY: only the pool's editors receive the answer key (mustWithholdSolution)
        $stripCorrect = $this->mustWithholdSolution($poolId, $userId);

        $result = [];
        foreach ($questions as $question) {
            $questionData = $question->jsonSerialize();
            if ($stripCorrect) {
                unset($questionData['explanation']);
                $questionData['pbq_config'] = PbqSolutionStripper::strip($questionData['pbq_config'] ?? null);
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

            // SECURITY: only the pool's editors receive the answer key (mustWithholdSolution)
            $stripCorrect = $this->mustWithholdSolution($question->getPoolId(), $userId);

            $questionData = $question->jsonSerialize();
            if ($stripCorrect) {
                unset($questionData['explanation']);
                $questionData['pbq_config'] = PbqSolutionStripper::strip($questionData['pbq_config'] ?? null);
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

    /**
     * find() with the answer key, for server-side code that grades or explains and never sends the
     * key on (AiController::explain builds its prompt from the correct answers). Still checks read
     * access. Never return its result to the client unfiltered — that is what find() is for.
     */
    public function findWithSolution(int $id, string $userId): array {
        try {
            $question = $this->questionMapper->findById($id);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new Exception($e->getMessage());
        }
        if (!$this->hasPoolAccess($question->getPoolId(), $userId)) {
            throw new Exception('No access');
        }
        $questionData = $question->jsonSerialize();
        $questionData['answers'] = array_map(
            static fn ($a) => $a->jsonSerialize(),
            $this->answerMapper->findByQuestion($question->getId())
        );
        return $questionData;
    }

    /**
     * Grades one answer of one question for the bot modes of Duel and Gameshow, which graded on the
     * client with answers[].is_correct until learners stopped receiving it (2026-11 audit).
     * Refused during a graded exam on the pool — it would otherwise be an exam oracle.
     *
     * @return array{correct: bool, correct_answer_id: ?int}
     * @throws NotFoundException question missing or no read access
     * @throws ForbiddenException graded exam open on the question's pool
     * @throws \InvalidArgumentException the answer does not belong to the question
     */
    public function checkAnswer(int $questionId, int $answerId, string $userId): array {
        try {
            $question = $this->questionMapper->findById($questionId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Question not found');
        }
        $poolId = $question->getPoolId();
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new NotFoundException('Question not found');
        }
        if ($this->hasActiveExamOnPool($poolId, $userId)) {
            throw new ForbiddenException('Not available during active exam');
        }

        // Same lookup as DuelService::submitAnswer: the answer must belong to this question.
        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if ($row === false) {
            throw new \InvalidArgumentException('Invalid answer');
        }

        return [
            'correct' => (bool)$row['is_correct'],
            'correct_answer_id' => $this->getCorrectAnswerIdForGame($questionId),
        ];
    }

    public function findEntity(int $id, string $userId): Question {
        $question = $this->questionMapper->findById($id);
        if (!$this->hasPoolAccess($question->getPoolId(), $userId)) {
            throw new Exception('No access');
        }
        return $question;
    }

    /**
     * AUDIT MED-06: public exam-oracle check for callers (e.g. TranslationController) that
     * emit question-derived data through paths other than find()/findByPool(). Returns true
     * when the user has an active, incomplete exam on the question's pool — in which case
     * answer keys and explanations (including translated ones) must be withheld.
     */
    public function isExamActiveForQuestion(int $questionId, string $userId): bool {
        try {
            $question = $this->questionMapper->findById($questionId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            return false;
        }
        return $this->hasActiveExamOnPool($question->getPoolId(), $userId);
    }

    /**
     * Question-level mustWithholdSolution() for the translation endpoints: withhold translated
     * explanations from everyone but the pool's editors — TranslationDialog saves the loaded
     * explanations back, so a withheld copy would overwrite them with NULL (audit 2026-11).
     */
    public function mustWithholdSolutionForQuestion(int $questionId, string $userId): bool {
        try {
            $question = $this->questionMapper->findById($questionId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            return false;
        }
        return $this->mustWithholdSolution($question->getPoolId(), $userId);
    }

    /**
     * AUDIT MED-10: public pool-level exam-oracle check for the VirtuProf/RAG path, which must
     * withhold pool answer keys while the user has an active exam on that pool.
     */
    public function isExamActiveOnPool(int $poolId, string $userId): bool {
        return $this->hasActiveExamOnPool($poolId, $userId);
    }

    /**
     * SEC-MED-2: Public wrapper for canEditPool, used by TranslationController
     */
    public function verifyEditAccess(int $poolId, string $userId): void {
        if (!$this->canEditPool($poolId, $userId)) {
            throw new Exception('No edit access to this pool');
        }
    }

    /**
     * AUDIT v5.2.1 (pre-live review, finding H): export access is broader than edit but narrower
     * than read. The HIGH-01 fix switched pool export from read-access to verifyEditAccess(), which
     * only allows the pool owner / an edit-share — that locked out course instructors who manage a
     * course's pool without owning it. Export must therefore also allow the instructor of a course
     * this pool is attached to, but must still NOT allow enrolled students or plain read-shares
     * (they would otherwise pull answer keys via CSV/JSON).
     */
    private function isCourseInstructorForPool(int $poolId, string $userId): bool {
        // Mirror CourseService::isInstructorOfCourse: a course instructor is either the course
        // creator (learning_courses.instructor_id) OR a member with role 'instructor' (co-instructor).
        // AUDIT v5.2.1 (pre-live review R2, finding #3): the first version only checked instructor_id,
        // which locked co-instructors out of answer-key export.
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('cp.id')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
               $expr->eq('cm.course_id', 'c.id'),
               $expr->eq('cm.user_id', $qb->createNamedParameter($userId)),
               $expr->eq('cm.role', $qb->createNamedParameter('instructor'))
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

    public function verifyExportAccess(int $poolId, string $userId): void {
        if (!$this->canEditPool($poolId, $userId) && !$this->isCourseInstructorForPool($poolId, $userId)) {
            throw new Exception('No export access to this pool');
        }
    }

    public function setImagePath(int $questionId, ?string $imagePath, string $userId): Question {
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
                           ?string $pbqSubtype = null, ?string $pbqConfig = null,
                           ?string $instructorNote = null, bool $noteVisible = false,
                           ?string $handbookKey = null, ?string $handbookTitle = null,
                           ?string $examKey = null, ?string $chapterKey = null,
                           ?string $chapterTitle = null, ?int $chapterOrder = null,
                           ?string $scenario = null): array {
        if (!$this->canEditPool($poolId, $userId)) {
            throw new Exception('No edit access to this pool');
        }

        $questionType = $questionType ?? 'single';

        $this->validateQuestionInput($text, $answers, $questionType);
        $scenario = $this->normalizeScenario($scenario);

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
            $question->setInstructorNote($instructorNote);
            $question->setNoteVisible($noteVisible);
            $this->applyQuestionMetadata($question, $handbookKey, $handbookTitle, $examKey, $chapterKey, $chapterTitle, $chapterOrder);
            $question->setScenario($scenario);
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
                          ?string $pbqSubtype = null, ?string $pbqConfig = null,
                          ?string $instructorNote = null, bool $noteVisible = false,
                          ?string $handbookKey = null, ?string $handbookTitle = null,
                          ?string $examKey = null, ?string $chapterKey = null,
                          ?string $chapterTitle = null, ?int $chapterOrder = null,
                          ?string $scenario = null): array {
        try {
            $question = $this->questionMapper->findById($id);
            if (!$this->canEditPool($question->getPoolId(), $userId)) {
                throw new Exception('No edit access to this pool');
            }

            $storedType = $question->getQuestionType();
            $questionType = $questionType ?? ($storedType ?? 'single');

            // A type stored before unknown types were refused (GitHub #34) may be saved again
            // unchanged, so such a question stays editable; any change must be to a known type.
            $this->validateQuestionInput($text, $answers, $questionType, $questionType !== $storedType);
            $keepScenario = $scenario === null;
            $scenario = $this->normalizeScenario($scenario);

            $this->db->beginTransaction();
            try {
                $question->setText($text);
                $question->setExplanation($explanation);
                $question->setDifficulty($difficulty);
                $question->setQuestionType($questionType);
                if ($pbqSubtype !== null) { $question->setPbqSubtype($pbqSubtype); }
                if ($pbqConfig !== null) { $question->setPbqConfig($pbqConfig); }
                $question->setInstructorNote($instructorNote);
                $question->setNoteVisible($noteVisible);
                $this->applyQuestionMetadata($question, $handbookKey, $handbookTitle, $examKey, $chapterKey, $chapterTitle, $chapterOrder);
                // null = caller did not send the field: keep what is stored.
                if (!$keepScenario) {
                    $question->setScenario($scenario);
                }

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

    /**
     * @param int|null $poolId search only this pool. The pool's question list used to run the
     *                         search over all of the user's pools (50 hits) and filter afterwards,
     *                         so hits of the open pool could be cut off; each hit then also carries
     *                         'position', its 0-based place in the pool's paging order, so the list
     *                         can open the page it is on (2026-11 audit #108).
     */
    public function search(string $query, string $userId, int $limit = 50, ?int $poolId = null): array {
        $limit = max(1, min($limit, 100));
        $rows = $this->questionMapper->searchByText($query, $userId, $limit, $poolId);

        if ($poolId !== null && $rows !== []) {
            // The search joins already limited the rows to pools the user can see.
            $positions = array_flip($this->questionMapper->findIdsInPoolOrder($poolId));
            foreach ($rows as $i => $row) {
                $rows[$i]['position'] = $positions[(int)($row['id'] ?? 0)] ?? null;
            }
        }

        // The rows are learning_questions.* (no answers): withhold explanation and the PBQ solution
        // of every pool the user cannot edit, like find() does (2026-11 audit, answer key).
        $withhold = [];
        foreach ($rows as $i => $row) {
            $poolId = (int)($row['pool_id'] ?? 0);
            $withhold[$poolId] ??= $this->mustWithholdSolution($poolId, $userId);
            if (!$withhold[$poolId]) {
                continue;
            }
            unset($row['explanation']);
            if (isset($row['pbq_config']) && is_string($row['pbq_config']) && $row['pbq_config'] !== '') {
                $config = json_decode($row['pbq_config'], true);
                $row['pbq_config'] = is_array($config)
                    ? (string)json_encode(PbqSolutionStripper::strip($config))
                    : null;
            }
            $rows[$i] = $row;
        }
        return $rows;
    }

    /**
     * Load a question with its answers for multiplayer game modes (duel, gameshow).
     * Answers are returned without `is_correct` to prevent client-side cheating.
     * Applies optional translation if $lang is provided.
     *
     * Used by DuelService and GameshowService to avoid code duplication.
     */
    public function loadQuestionForGame(int $questionId, ?string $lang = null): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'text', 'image_path', 'scenario')
               ->from('learning_questions')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return null;
            }

            $aqb = $this->db->getQueryBuilder();
            $aqb->select('id', 'text', 'position')
                ->from('learning_answers')
                ->where($aqb->expr()->eq('question_id', $aqb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
                ->orderBy('position', 'ASC');
            $aResult = $aqb->executeQuery();
            $answers = [];
            while ($aRow = $aResult->fetch()) {
                $answers[] = ['id' => (int)$aRow['id'], 'text' => $aRow['text']];
            }
            $aResult->closeCursor();

            $question = [
                'id' => (int)$row['id'],
                'text' => $row['text'],
                'image_path' => $row['image_path'] ?? null,
                'scenario' => $row['scenario'] ?? null,
                'answers' => $answers,
            ];

            return $this->translationService->translateQuestion($question, (string)$lang);
        } catch (\Exception $e) {
            $this->logger->warning('QuestionService: Failed to load question {id} for game: {err}', [
                'id' => $questionId,
                'err' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Return the ID of the correct answer for a question.
     * Used by DuelService and GameshowService for post-answer feedback.
     */
    public function getCorrectAnswerIdForGame(int $questionId): ?int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? (int)$row['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
