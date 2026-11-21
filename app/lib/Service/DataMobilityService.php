<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\AnswerTranslationMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTranslationMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Service for exporting pool and course data.
 * Used by OCC commands and ExportController.
 * All methods are user-context-free (admin/CLI).
 */
class DataMobilityService {
    private PoolMapper $poolMapper;
    private QuestionMapper $questionMapper;
    private AnswerMapper $answerMapper;
    private CourseMapper $courseMapper;
    private CoursePoolMapper $coursePoolMapper;
    private CourseMemberMapper $courseMemberMapper;
    private IDBConnection $db;
    private IUserManager $userManager;

    public function __construct(
        PoolMapper $poolMapper,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        CourseMapper $courseMapper,
        CoursePoolMapper $coursePoolMapper,
        CourseMemberMapper $courseMemberMapper,
        IDBConnection $db,
        IUserManager $userManager,
        private ?QuestionTranslationMapper $questionTranslationMapper = null,
        private ?AnswerTranslationMapper $answerTranslationMapper = null,
    ) {
        $this->poolMapper = $poolMapper;
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->courseMapper = $courseMapper;
        $this->coursePoolMapper = $coursePoolMapper;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->db = $db;
        $this->userManager = $userManager;
    }

    /**
     * A pool as one pool JSON document, format PoolJsonFormat::EXPORT_FORMAT (learning-pool/2):
     * {"_meta": {"format"}, "name", "description", handbook/chapter fields of the pool,
     * "questions": exportPoolJson()}. What `occ learning:export-pool` and the export in the
     * interface write; both importers read it back (docs/import-pool-workflow.md, "Round trip").
     * Empty pool fields are left out.
     *
     * @return array<string, mixed>
     */
    public function exportPoolDocument(int $poolId): array {
        $pool = $this->poolMapper->findById($poolId);
        $document = ['_meta' => ['format' => PoolJsonFormat::EXPORT_FORMAT], 'name' => (string)$pool->getName()];
        $fields = [
            'description' => $pool->getDescription(),
            'handbook_key' => $pool->getHandbookKey(),
            'handbook_title' => $pool->getHandbookTitle(),
            'chapter_key' => $pool->getChapterKey(),
            'chapter_title' => $pool->getChapterTitle(),
        ];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $document[$key] = $value;
            }
        }
        if ($pool->getChapterOrder() !== null) {
            $document['chapter_order'] = (int)$pool->getChapterOrder();
        }
        $document['questions'] = $this->exportPoolJson($poolId);
        return $document;
    }

    /**
     * Export a pool with all questions and answers as structured array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportPoolJson(int $poolId): array {
        $pool = $this->poolMapper->findById($poolId);
        $questions = $this->questionMapper->findByPoolId($poolId);

        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);
        [$questionTranslations, $answerTranslations] = $this->translationsOf($questionIds, $answersGrouped);

        $export = [];
        foreach ($questions as $question) {
            $type = $question->getQuestionType() ?? 'single';
            $item = [
                'text' => $question->getText(),
                'type' => $type,
            ];
            // A PBQ is its subtype plus its configuration; without them the importers refuse it
            // (interface) or drop it (occ). Field names as both importers read them.
            if ($type === 'pbq') {
                $item['subtype'] = $question->getPbqSubtype();
                $config = json_decode((string)$question->getPbqConfig(), true);
                $item['pbq_config'] = is_array($config) ? $config : [];
            }
            // Optional fields, left out when empty, in the field names of the import format
            // (docs/import-pool-workflow.md), so the export comes back without losses.
            $optional = [
                'scenario' => $question->getScenario(),
                'explanation' => $question->getExplanation(),
                'difficulty' => $question->getDifficulty(),
                'handbook_key' => $question->getHandbookKey(),
                'handbook_title' => $question->getHandbookTitle(),
                'exam_key' => $question->getExamKey(),
                'chapter_key' => $question->getChapterKey(),
                'chapter_title' => $question->getChapterTitle(),
                'instructor_note' => $question->getInstructorNote(),
            ];
            foreach ($optional as $key => $value) {
                if ($value !== null && $value !== '') {
                    $item[$key] = $value;
                }
            }
            if ($question->getChapterOrder() !== null) {
                $item['chapter_order'] = (int)$question->getChapterOrder();
            }
            // Only when set: the importers store a missing note_visible as false.
            if ($question->getNoteVisible()) {
                $item['note_visible'] = true;
            }
            $answers = $answersGrouped[$question->getId()] ?? [];
            $item['answers'] = array_map(function ($a) use ($answerTranslations) {
                $answer = [
                    'text' => $a->getText(),
                    'is_correct' => (bool)$a->getIsCorrect(),
                ];
                if (isset($answerTranslations[$a->getId()])) {
                    $answer['translations'] = $answerTranslations[$a->getId()];
                }
                return $answer;
            }, $answers);
            // GitHub #66: hint, background_fact, key_takeaway, feedback_per_option (option_index =
            // index in "answers" above), in the import format's names (RichFeedbackFormat).
            $item += RichFeedbackFormat::toExport(
                $question->getHint(),
                $question->getBackgroundFact(),
                $question->getKeyTakeaway(),
                $question->getOptionFeedback(),
                array_map(static fn ($a): array => [
                    'position' => $a->getPosition() !== null ? (int)$a->getPosition() : null,
                    'is_correct' => (bool)$a->getIsCorrect(),
                ], array_values($answers))
            );
            $translations = $this->exportQuestionTranslations($questionTranslations[$question->getId()] ?? [], $answers);
            if ($translations !== []) {
                $item['translations'] = $translations;
            }
            $export[] = $item;
        }

        return $export;
    }

    /**
     * Translations of the questions (by question id, then language) and of their answers (by
     * answer id: language → text). Empty without the translation mappers.
     *
     * @param list<int> $questionIds
     * @param array<int, list<\OCA\Learning\Db\Answer>> $answersGrouped
     * @return array{0: array<int, list<\OCA\Learning\Db\QuestionTranslation>>, 1: array<int, array<string, string>>}
     */
    private function translationsOf(array $questionIds, array $answersGrouped): array {
        $byQuestion = [];
        if ($this->questionTranslationMapper !== null && $questionIds !== []) {
            foreach ($this->questionTranslationMapper->findByQuestions($questionIds) as $t) {
                $byQuestion[(int)$t->getQuestionId()][] = $t;
            }
        }
        $byAnswer = [];
        $answerIds = [];
        foreach ($answersGrouped as $answers) {
            foreach ($answers as $a) {
                $answerIds[] = (int)$a->getId();
            }
        }
        if ($this->answerTranslationMapper !== null && $answerIds !== []) {
            foreach ($this->answerTranslationMapper->findByAnswers($answerIds) as $t) {
                $byAnswer[(int)$t->getAnswerId()][(string)$t->getLang()] = (string)$t->getText();
            }
            foreach ($byAnswer as &$langs) {
                ksort($langs);
            }
            unset($langs);
        }
        return [$byQuestion, $byAnswer];
    }

    /**
     * One question's translations as {lang: {text, explanation?, scenario?, hint?,
     * background_fact?, key_takeaway?, feedback_per_option?}}, empty fields left out, the
     * option feedback with option_index into the exported "answers" (RichFeedbackFormat::toExport()).
     *
     * @param list<\OCA\Learning\Db\QuestionTranslation> $translations
     * @param list<\OCA\Learning\Db\Answer> $answers in export order
     * @return array<string, array<string, mixed>>
     */
    private function exportQuestionTranslations(array $translations, array $answers): array {
        $answerKeys = array_map(static fn ($a): array => [
            'position' => $a->getPosition() !== null ? (int)$a->getPosition() : null,
            'is_correct' => (bool)$a->getIsCorrect(),
        ], array_values($answers));
        $out = [];
        foreach ($translations as $t) {
            $entry = ['text' => (string)$t->getText()];
            foreach (['explanation' => $t->getExplanation(), 'scenario' => $t->getScenario()] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $entry[$key] = $value;
                }
            }
            $entry += RichFeedbackFormat::toExport($t->getHint(), $t->getBackgroundFact(), $t->getKeyTakeaway(), $t->getOptionFeedback(), $answerKeys);
            $out[(string)$t->getLang()] = $entry;
        }
        ksort($out);
        return $out;
    }

    /**
     * Export a pool as CSV string.
     */
    public function exportPoolCsv(int $poolId): string {
        $pool = $this->poolMapper->findById($poolId);
        $questions = $this->questionMapper->findByPoolId($poolId);

        $questionIds = array_map(fn($q) => $q->getId(), $questions);
        $answersGrouped = $this->answerMapper->findByQuestions($questionIds);

        $lines = [];
        foreach ($questions as $question) {
            $type = $question->getQuestionType() ?? 'single';
            $answers = $answersGrouped[$question->getId()] ?? [];

            if ($type === 'open') {
                $modelAnswer = !empty($answers) ? $answers[0]->getText() : '';
                $lines[] = CsvFormulaGuard::line([$question->getText(), $modelAnswer, 'open']);
            } else {
                $fields = [$question->getText()];
                $correctIndex = null;
                foreach ($answers as $i => $a) {
                    $fields[] = $a->getText();
                    if ((bool)$a->getIsCorrect()) {
                        $correctIndex = $i + 1;
                    }
                }
                $fields[] = (string)($correctIndex ?? 1);
                $explanation = $question->getExplanation();
                if ($explanation !== null && $explanation !== '') {
                    $fields[] = $explanation;
                }
                $lines[] = CsvFormulaGuard::line($fields);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Export a full course with pools, members, and stats.
     *
     * @return array<string, mixed>
     */
    public function exportCourse(int $courseId): array {
        $course = $this->courseMapper->findById($courseId);
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $members = $this->courseMemberMapper->findByCourse($courseId);

        // Export pools with questions
        $poolsData = [];
        foreach ($coursePools as $cp) {
            $poolId = $cp->getPoolId();
            try {
                $pool = $this->poolMapper->findById($poolId);
                $poolsData[] = [
                    'pool_id' => $poolId,
                    'pool_name' => $pool->getName(),
                    'sort_order' => $cp->getSortOrder(),
                    'questions' => $this->exportPoolJson($poolId),
                ];
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $poolsData[] = [
                    'pool_id' => $poolId,
                    'pool_name' => '(deleted)',
                    'sort_order' => $cp->getSortOrder(),
                    'questions' => [],
                ];
            }
        }

        // Export members with display names
        $membersData = [];
        foreach ($members as $member) {
            $userId = $member->getUserId();
            $user = $this->userManager->get($userId);
            $membersData[] = [
                'user_id' => $userId,
                'display_name' => $user ? $user->getDisplayName() : $userId,
                'role' => $member->getRole(),
                'enrolled_at' => $member->getEnrolledAt(),
            ];
        }

        // Export student stats
        $studentIds = array_map(
            fn($m) => $m->getUserId(),
            array_filter($members, fn($m) => $m->getRole() === 'student')
        );
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);
        $stats = $this->getStudentStats($studentIds, $poolIds);

        return [
            'course' => $course->jsonSerialize(),
            'pools' => $poolsData,
            'members' => $membersData,
            'student_stats' => $stats,
            'exported_at' => time(),
        ];
    }

    /**
     * Export course statistics as CSV (one row per student).
     */
    public function exportCourseStatsCsv(int $courseId): string {
        $course = $this->courseMapper->findById($courseId);
        $coursePools = $this->coursePoolMapper->findByCourse($courseId);
        $members = $this->courseMemberMapper->findByCourse($courseId);

        $studentMembers = array_filter($members, fn($m) => $m->getRole() === 'student');
        $studentIds = array_map(fn($m) => $m->getUserId(), $studentMembers);
        $poolIds = array_map(fn($cp) => $cp->getPoolId(), $coursePools);

        $userStats = $this->getBatchUserStats($studentIds);
        $sessionStats = $this->getBatchSessionStats($studentIds, $poolIds);
        $masteryStats = $this->getBatchMastery($studentIds, $poolIds);

        // Header
        $lines = [CsvFormulaGuard::line([
            'user_id', 'display_name', 'xp', 'level', 'streak',
            'total_sessions', 'total_mastered', 'total_questions_answered',
            'total_correct', 'accuracy_percent', 'last_activity',
        ])];

        foreach ($studentMembers as $member) {
            $userId = $member->getUserId();
            $user = $this->userManager->get($userId);
            $displayName = $user ? $user->getDisplayName() : $userId;

            $us = $userStats[$userId] ?? [];
            $totalXp = (int)($us['total_xp'] ?? 0);
            $level = (int)($us['current_level'] ?? 1);
            $streak = (int)($us['current_streak'] ?? 0);
            $totalSessions = (int)($us['total_sessions'] ?? 0);
            // Mastered cards in this course's pools, like the other per-course columns; the
            // global learning_user_stats.total_mastered also counted other courses and private
            // pools, and the course query result was thrown away (audit #158).
            $totalMastered = array_sum($masteryStats[$userId] ?? []);
            $lastActivity = (string)($us['last_activity_date'] ?? '');

            // Aggregate session stats across all pools
            $totalQ = 0;
            $totalCorrect = 0;
            $userPoolStats = $sessionStats[$userId] ?? [];
            foreach ($userPoolStats as $poolStat) {
                $totalQ += (int)($poolStat['total_q'] ?? 0);
                $totalCorrect += (int)($poolStat['correct'] ?? 0);
            }
            $accuracy = $totalQ > 0 ? round(($totalCorrect / $totalQ) * 100, 1) : 0;

            $lines[] = CsvFormulaGuard::line([
                $userId, $displayName, (string)$totalXp, (string)$level, (string)$streak,
                (string)$totalSessions, (string)$totalMastered, (string)$totalQ,
                (string)$totalCorrect, (string)$accuracy, $lastActivity,
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Get aggregated student stats for course export JSON.
     *
     * @param string[] $studentIds
     * @param int[] $poolIds
     * @return array<string, array<string, mixed>>
     */
    private function getStudentStats(array $studentIds, array $poolIds): array {
        if (empty($studentIds)) {
            return [];
        }

        $userStats = $this->getBatchUserStats($studentIds);
        $sessionStats = $this->getBatchSessionStats($studentIds, $poolIds);

        $result = [];
        foreach ($studentIds as $userId) {
            $us = $userStats[$userId] ?? [];
            $userPoolStats = $sessionStats[$userId] ?? [];

            $totalQ = 0;
            $totalCorrect = 0;
            $lastActive = null;
            foreach ($userPoolStats as $poolStat) {
                $totalQ += (int)($poolStat['total_q'] ?? 0);
                $totalCorrect += (int)($poolStat['correct'] ?? 0);
                $poolLastActive = $poolStat['last_active'] ?? null;
                if ($poolLastActive !== null && ($lastActive === null || $poolLastActive > $lastActive)) {
                    $lastActive = $poolLastActive;
                }
            }

            $result[$userId] = [
                'total_xp' => (int)($us['total_xp'] ?? 0),
                'current_level' => (int)($us['current_level'] ?? 1),
                'current_streak' => (int)($us['current_streak'] ?? 0),
                'total_sessions' => (int)($us['total_sessions'] ?? 0),
                'total_mastered' => (int)($us['total_mastered'] ?? 0),
                'total_questions_answered' => $totalQ,
                'total_correct' => $totalCorrect,
                'accuracy_percent' => $totalQ > 0 ? round(($totalCorrect / $totalQ) * 100, 1) : 0,
                'last_active' => $lastActive,
            ];
        }

        return $result;
    }

    /**
     * @param string[] $userIds
     * @return array<string, array<string, mixed>>
     */
    private function getBatchUserStats(array $userIds): array {
        if (empty($userIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'total_xp', 'current_level', 'current_streak', 'total_sessions', 'total_mastered', 'last_activity_date')
            ->from('learning_user_stats')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[$row['user_id']] = [
                'total_xp' => (int)$row['total_xp'],
                'current_level' => (int)$row['current_level'],
                'current_streak' => (int)$row['current_streak'],
                'total_sessions' => (int)$row['total_sessions'],
                'total_mastered' => (int)$row['total_mastered'],
                'last_activity_date' => $row['last_activity_date'],
            ];
        }
        $result->closeCursor();
        return $data;
    }

    /**
     * @param string[] $studentIds
     * @param int[] $poolIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function getBatchSessionStats(array $studentIds, array $poolIds): array {
        if (empty($studentIds) || empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'user_id', 'pool_id',
                $qb->createFunction('COALESCE(SUM(total_questions), 0) as total_q'),
                $qb->createFunction('COALESCE(SUM(correct_answers), 0) as correct'),
                $qb->createFunction('MAX(completed_at) as last_active')
            )
            ->from('learning_sessions')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('completed_at'))
            ->groupBy('user_id', 'pool_id');
        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[$row['user_id']][(int)$row['pool_id']] = [
                'total_q' => (int)$row['total_q'],
                'correct' => (int)$row['correct'],
                'last_active' => $row['last_active'] ? (int)$row['last_active'] : null,
            ];
        }
        $result->closeCursor();
        return $data;
    }

    /**
     * @param string[] $studentIds
     * @param int[] $poolIds
     * @return array<string, array<int, int>>
     */
    private function getBatchMastery(array $studentIds, array $poolIds): array {
        if (empty($studentIds) || empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'pool_id', $qb->createFunction('COUNT(*) as cnt'))
            ->from('learning_leitner_items')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter($studentIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter(5)))
            ->groupBy('user_id', 'pool_id');
        $result = $qb->executeQuery();
        $data = [];
        while ($row = $result->fetch()) {
            $data[$row['user_id']][(int)$row['pool_id']] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $data;
    }
}
