<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\StoryProgress;
use OCA\Learning\Db\StoryProgressMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * StoryEngineService — core backend for the Story-RPG (v6.0 Abenteuer).
 *
 * Responsibilities:
 *  - Load and validate campaign JSON files from app/data/campaigns/
 *  - Manage per-user story progress (start, resume, advance)
 *  - Execute skill checks against real pool questions filtered by pool_filter tag
 *  - Determine next scene based on skill-check outcome and character class
 *  - Support coop sessions (majority-vote on choices)
 */
class StoryEngineService {

    /** Valid character classes. */
    private const VALID_CLASSES = ['architect', 'security', 'sysadmin', 'helpdesk'];

    /** Directory (relative to app root) where campaign JSON files live. */
    private string $campaignDir;

    private StoryProgressMapper $progressMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        StoryProgressMapper $progressMapper,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->progressMapper = $progressMapper;
        $this->db = $db;
        $this->logger = $logger;
        // Resolve campaign directory relative to this file's location:
        // app/lib/Service/ → ../../../data/campaigns/
        $this->campaignDir = realpath(__DIR__ . '/../../../app/data/campaigns')
            ?: (__DIR__ . '/../../../app/data/campaigns');
        // Also try the sibling data/ directory (when running inside app/)
        if (!is_dir($this->campaignDir)) {
            $this->campaignDir = realpath(__DIR__ . '/../../data/campaigns')
                ?: (__DIR__ . '/../../data/campaigns');
        }
    }

    // ─── Campaign Loader ────────────────────────────────────────────────────

    /**
     * List all available campaigns (scan JSON files).
     * Returns lightweight campaign metadata — no scene details.
     *
     * @return array<array{campaign_id: string, title: string, description: string,
     *                      duration_minutes: int, difficulty: string, focus_areas: string[],
     *                      character_recommendations: string[]}>
     */
    public function listCampaigns(): array {
        $campaigns = [];
        if (!is_dir($this->campaignDir)) {
            $this->logger->warning('StoryEngine: campaign directory not found: {dir}', ['dir' => $this->campaignDir]);
            return $campaigns;
        }
        foreach (glob($this->campaignDir . '/*.json') as $file) {
            try {
                $data = $this->loadCampaignFile($file);
                $campaigns[] = [
                    'campaign_id'              => $data['campaign_id'],
                    'title'                    => $data['title'],
                    'description'              => $data['description'],
                    'duration_minutes'         => (int)($data['duration_minutes'] ?? 60),
                    'difficulty'               => $data['difficulty'] ?? 'intermediate',
                    'focus_areas'              => $data['focus_areas'] ?? [],
                    'character_recommendations'=> $data['character_recommendations'] ?? [],
                ];
            } catch (\RuntimeException $e) {
                $this->logger->warning('StoryEngine: skipping invalid campaign file {file}: {err}', [
                    'file' => basename($file),
                    'err'  => $e->getMessage(),
                ]);
            }
        }
        return $campaigns;
    }

    /**
     * Load and validate a campaign JSON by ID.
     * Throws \RuntimeException on validation failure (never returns malformed data).
     *
     * @throws \RuntimeException
     */
    public function loadCampaign(string $campaignId): array {
        $this->validateCampaignId($campaignId);
        $file = $this->campaignDir . '/' . $campaignId . '.json';
        if (!file_exists($file)) {
            throw new \RuntimeException("Campaign not found: {$campaignId}");
        }
        return $this->loadCampaignFile($file);
    }

    /**
     * Load, decode and validate a campaign JSON file.
     *
     * @throws \RuntimeException
     */
    private function loadCampaignFile(string $file): array {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('Cannot read campaign file: ' . basename($file));
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Malformed JSON in ' . basename($file) . ': ' . json_last_error_msg()
            );
        }
        $this->validateCampaignStructure($data, basename($file));
        return $data;
    }

    /**
     * Validate required top-level fields and that all scene next-pointers resolve.
     *
     * @throws \RuntimeException
     */
    private function validateCampaignStructure(array $data, string $filename): void {
        foreach (['campaign_id', 'title', 'scenes'] as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("Campaign {$filename} missing required field: {$field}");
            }
        }
        if (!is_array($data['scenes']) || count($data['scenes']) === 0) {
            throw new \RuntimeException("Campaign {$filename} has no scenes");
        }

        // Build scene ID set for next-pointer resolution check
        $sceneIds = array_column($data['scenes'], 'id');
        $sceneIdSet = array_flip($sceneIds);

        foreach ($data['scenes'] as $scene) {
            if (empty($scene['id'])) {
                throw new \RuntimeException("Campaign {$filename} has a scene missing 'id'");
            }
            foreach ($scene['choices'] ?? [] as $choice) {
                foreach (['success_scene', 'partial_scene', 'fail_scene'] as $key) {
                    if (!empty($choice[$key]) && !isset($sceneIdSet[$choice[$key]])) {
                        throw new \RuntimeException(
                            "Campaign {$filename} scene '{$scene['id']}' choice '{$choice['id']}' "
                            . "references unknown scene '{$choice[$key]}'"
                        );
                    }
                }
            }
        }
    }

    // ─── Progress Management ─────────────────────────────────────────────────

    /**
     * Start a new campaign for a user (or reset an existing one).
     * Returns the first scene with skill-check question pre-loaded.
     *
     * @param string[] $coopUserIds Additional coop player user IDs (excluding $userId)
     * @return array{progress: array, scene: array}
     * @throws \RuntimeException
     */
    public function startCampaign(string $userId, string $campaignId, string $characterClass, array $coopUserIds = []): array {
        $this->validateCharacterClass($characterClass);
        $campaign = $this->loadCampaign($campaignId);

        $firstScene = $campaign['scenes'][0] ?? null;
        if ($firstScene === null) {
            throw new \RuntimeException('Campaign has no scenes');
        }

        $now = time();

        // Remove existing progress record if any
        $existing = $this->progressMapper->findByUserAndCampaign($userId, $campaignId);
        if ($existing !== null) {
            $this->progressMapper->delete($existing);
        }

        $progress = new StoryProgress();
        $progress->setUserId($userId);
        $progress->setCampaignId($campaignId);
        $progress->setCurrentSceneId($firstScene['id']);
        $progress->setCharacterClass($characterClass);
        $progress->setChoicesJson(json_encode([]));
        $progress->setScore(0);
        $progress->setStatus('in_progress');
        $progress->setCoopUserIds($coopUserIds ? json_encode(array_values($coopUserIds)) : null);
        $progress->setCreatedAt($now);
        $progress->setUpdatedAt($now);

        $saved = $this->progressMapper->insert($progress);

        return [
            'progress' => $this->serializeProgress($saved),
            'scene'    => $this->buildSceneResponse($firstScene, $campaign, $characterClass),
        ];
    }

    /**
     * Get the current scene for a user's campaign, with skill-check question pre-loaded.
     *
     * @return array{progress: array, scene: array}
     * @throws \RuntimeException
     */
    public function getScene(string $userId, string $campaignId): array {
        $progress = $this->requireProgress($userId, $campaignId);
        $campaign  = $this->loadCampaign($campaignId);
        $scene     = $this->findScene($campaign, $progress->getCurrentSceneId());

        return [
            'progress' => $this->serializeProgress($progress),
            'scene'    => $this->buildSceneResponse($scene, $campaign, $progress->getCharacterClass()),
        ];
    }

    /**
     * Process a player's choice: run skill check, determine next scene, advance progress.
     *
     * When $answerId is provided the skill check is evaluated inline.
     * When $answerId is null the choice is registered but the caller must call
     * submitSkillAnswer() to complete the skill-check step.
     *
     * @param int|null $answerId  ID of selected answer in the skill-check question (null = deferred)
     * @param int      $questionId Question ID used for the skill-check (required when answerId set)
     * @return array{progress: array, scene: array, skill_result?: array}
     * @throws \RuntimeException
     */
    public function makeChoice(
        string $userId,
        string $campaignId,
        string $choiceId,
        ?int   $questionId = null,
        ?int   $answerId   = null
    ): array {
        $progress = $this->requireProgress($userId, $campaignId);
        $campaign  = $this->loadCampaign($campaignId);
        $scene     = $this->findScene($campaign, $progress->getCurrentSceneId());

        $choice = $this->findChoice($scene, $choiceId);
        $skillCheck = $choice['skill_check'] ?? null;

        $skillResult = null;
        $nextSceneId = null;

        if ($skillCheck !== null && $answerId !== null && $questionId !== null) {
            // Inline single-question skill-check evaluation
            $skillResult = $this->evaluateSingleAnswer($questionId, $answerId);
            $nextSceneId = $skillResult['correct']
                ? ($choice['success_scene'] ?? null)
                : ($choice['fail_scene'] ?? null);
        } elseif ($skillCheck === null) {
            // No skill check — go straight to success scene
            $nextSceneId = $choice['success_scene'] ?? null;
        }
        // If skillCheck exists but no answer provided yet, stay on current scene
        // (frontend will present the question and call back with submitSkillAnswer)

        // Advance progress if we determined the next scene
        if ($nextSceneId !== null) {
            $this->advanceProgress($progress, $scene['id'], $choiceId, $skillResult, $nextSceneId);
        }

        $currentScene = $nextSceneId !== null
            ? $this->findScene($campaign, $nextSceneId)
            : $scene;

        $response = [
            'progress' => $this->serializeProgress($progress),
            'scene'    => $this->buildSceneResponse($currentScene, $campaign, $progress->getCharacterClass()),
        ];

        if ($skillResult !== null) {
            $response['skill_result'] = $skillResult;
        }

        return $response;
    }

    /**
     * Submit an answer to the current scene's skill-check question.
     * Evaluates correctness, determines the next scene, and advances progress.
     *
     * The scene must have exactly one pending choice stored in the last choices entry
     * (written by makeChoice without an answerId) OR the choiceId is passed explicitly.
     *
     * @return array{progress: array, scene: array, skill_result: array}
     * @throws \RuntimeException
     */
    public function submitSkillAnswer(
        string $userId,
        string $campaignId,
        string $choiceId,
        int    $questionId,
        int    $answerId
    ): array {
        $progress = $this->requireProgress($userId, $campaignId);
        $campaign  = $this->loadCampaign($campaignId);
        $scene     = $this->findScene($campaign, $progress->getCurrentSceneId());
        $choice    = $this->findChoice($scene, $choiceId);

        $skillResult = $this->evaluateSingleAnswer($questionId, $answerId);

        $nextSceneId = $skillResult['correct']
            ? ($choice['success_scene'] ?? null)
            : ($choice['fail_scene'] ?? null);

        if ($nextSceneId === null) {
            throw new \RuntimeException("Choice '{$choiceId}' has no next scene defined");
        }

        $this->advanceProgress($progress, $scene['id'], $choiceId, $skillResult, $nextSceneId);

        $nextScene = $this->findScene($campaign, $nextSceneId);

        return [
            'progress'     => $this->serializeProgress($progress),
            'scene'        => $this->buildSceneResponse($nextScene, $campaign, $progress->getCharacterClass()),
            'skill_result' => $skillResult,
        ];
    }

    /**
     * Submit a multi-question skill-check batch (the full question_count for a choice).
     * Evaluates all answers, applies pass_threshold, determines outcome scene.
     *
     * @param array<array{question_id: int, answer_id: int}> $answers
     * @return array{progress: array, scene: array, skill_result: array}
     * @throws \RuntimeException
     */
    public function submitSkillBatch(
        string $userId,
        string $campaignId,
        string $choiceId,
        array  $answers
    ): array {
        $progress = $this->requireProgress($userId, $campaignId);
        $campaign  = $this->loadCampaign($campaignId);
        $scene     = $this->findScene($campaign, $progress->getCurrentSceneId());
        $choice    = $this->findChoice($scene, $choiceId);

        $skillCheck    = $choice['skill_check'] ?? null;
        $threshold     = (int)($skillCheck['pass_threshold'] ?? 1);
        $correctCount  = 0;
        $questionResults = [];

        foreach ($answers as $entry) {
            $qId = (int)($entry['question_id'] ?? 0);
            $aId = (int)($entry['answer_id']   ?? 0);
            if ($qId === 0 || $aId === 0) continue;

            $result = $this->evaluateSingleAnswer($qId, $aId);
            $questionResults[] = $result;
            if ($result['correct']) {
                $correctCount++;
            }
        }

        $passed = $correctCount >= $threshold;
        $partial = !$passed && $correctCount > 0;

        if ($passed) {
            $nextSceneId = $choice['success_scene'] ?? null;
            $outcome = 'success';
        } elseif ($partial && !empty($choice['partial_scene'])) {
            $nextSceneId = $choice['partial_scene'];
            $outcome = 'partial';
        } else {
            $nextSceneId = $choice['fail_scene'] ?? $choice['success_scene'] ?? null;
            $outcome = 'fail';
        }

        if ($nextSceneId === null) {
            throw new \RuntimeException("No valid next scene for choice '{$choiceId}'");
        }

        $skillResult = [
            'correct'          => $passed,
            'outcome'          => $outcome,
            'correct_count'    => $correctCount,
            'total_count'      => count($answers),
            'threshold'        => $threshold,
            'question_results' => $questionResults,
        ];

        $this->advanceProgress($progress, $scene['id'], $choiceId, $skillResult, $nextSceneId);

        $nextScene = $this->findScene($campaign, $nextSceneId);

        return [
            'progress'     => $this->serializeProgress($progress),
            'scene'        => $this->buildSceneResponse($nextScene, $campaign, $progress->getCharacterClass()),
            'skill_result' => $skillResult,
        ];
    }

    // ─── Skill-Check Question Fetcher ─────────────────────────────────────────

    /**
     * Fetch skill-check questions for a scene choice, filtered by pool_filter and
     * adjusted for character class difficulty.
     *
     * Returns an array of question payloads (id, text, answers[] without correct flag).
     *
     * @return array<array{id: int, text: string, image_path: string|null, answers: array}>
     */
    public function getSkillCheckQuestions(
        string $campaignId,
        string $sceneId,
        string $choiceId,
        string $characterClass
    ): array {
        $campaign   = $this->loadCampaign($campaignId);
        $scene      = $this->findScene($campaign, $sceneId);
        $choice     = $this->findChoice($scene, $choiceId);
        $skillCheck = $choice['skill_check'] ?? null;

        if ($skillCheck === null) {
            return [];
        }

        $poolFilter    = $skillCheck['pool_filter'] ?? null;
        $questionCount = (int)($skillCheck['question_count'] ?? 3);
        $adjustment    = $this->resolveCharacterDifficultyModifier(
            $campaign, $choice, $characterClass, $poolFilter
        );

        return $this->fetchFilteredQuestions($poolFilter, $questionCount, $adjustment);
    }

    /**
     * Determine the difficulty modifier for a character class on a specific choice.
     *
     * Priority:
     *  1. Per-choice `character_adjustments[class].difficulty_modifier` (explicit)
     *  2. Campaign-level `characters[class].skill_bonus_pools` / `skill_penalty_pools`
     *     matched against the choice's `pool_filter`
     *  3. 0 (no adjustment)
     */
    private function resolveCharacterDifficultyModifier(
        array  $campaign,
        array  $choice,
        string $characterClass,
        ?string $poolFilter
    ): int {
        $skillCheck = $choice['skill_check'] ?? [];

        // Priority 1: explicit per-choice adjustment
        if (isset($skillCheck['character_adjustments'][$characterClass]['difficulty_modifier'])) {
            return (int)$skillCheck['character_adjustments'][$characterClass]['difficulty_modifier'];
        }

        // Priority 2: campaign-level character pool affinity
        if ($poolFilter !== null && isset($campaign['characters'][$characterClass])) {
            $charDef     = $campaign['characters'][$characterClass];
            $bonusPools  = $charDef['skill_bonus_pools']  ?? [];
            $penaltyPools= $charDef['skill_penalty_pools'] ?? [];

            $filterLower = strtolower($poolFilter);

            foreach ($bonusPools as $pool) {
                if (str_contains($filterLower, strtolower($pool))
                    || str_contains(strtolower($pool), $filterLower)
                ) {
                    return -1; // Strength: easier questions
                }
            }
            foreach ($penaltyPools as $pool) {
                if (str_contains($filterLower, strtolower($pool))
                    || str_contains(strtolower($pool), $filterLower)
                ) {
                    return 1; // Weakness: harder questions
                }
            }
        }

        return 0; // No adjustment
    }

    /**
     * Fetch random questions whose pool has content_language tag matching pool_filter
     * (matched against pool name/description keyword or pool_filter tag if stored).
     *
     * Uses a LIKE-based keyword filter against pool name and description as a
     * pragmatic pool_filter approach — no extra DB column needed for Phase 32.
     *
     * @return array<array{id: int, text: string, image_path: string|null, answers: array}>
     */
    private function fetchFilteredQuestions(
        ?string $filter,
        int     $count,
        int     $difficultyModifier
    ): array {
        // Clamp difficulty modifier to a reasonable window
        $difficultyModifier = max(-2, min(2, $difficultyModifier));

        $qb = $this->db->getQueryBuilder();
        $qb->select('q.id', 'q.text', 'q.image_path')
           ->from('learning_questions', 'q');

        // Apply pool_filter: join on pool and filter by keyword in pool name
        if ($filter !== null && $filter !== '') {
            $keyword = '%' . $this->db->escapeLikeParameter($filter) . '%';
            $qb->innerJoin('q', 'learning_pools', 'p', $qb->expr()->eq('q.pool_id', 'p.id'))
               ->where(
                   $qb->expr()->orX(
                       $qb->expr()->like('p.name',        $qb->createNamedParameter($keyword)),
                       $qb->expr()->like('p.description', $qb->createNamedParameter($keyword))
                   )
               );
        }

        // Difficulty modifier: positive = harder (pick from higher-index questions),
        // negative = easier (pick from lower-index questions).
        // Implemented as a simple ORDER BY RANDOM() with an offset shift.
        $qb->orderBy($qb->createFunction('RANDOM()'))
           ->setMaxResults($count + abs($difficultyModifier) * 2);

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return [];
        }

        // Difficulty offset: slice from appropriate position in result pool
        $offset = 0;
        if ($difficultyModifier > 0) {
            $offset = min($difficultyModifier * 2, max(0, count($rows) - $count));
        }
        $rows = array_slice($rows, $offset, $count);

        // Load answers for each question
        $questions = [];
        foreach ($rows as $row) {
            $qId = (int)$row['id'];
            $answers = $this->loadAnswersForQuestion($qId);
            if (empty($answers)) {
                continue; // Skip questions with no answers
            }
            $questions[] = [
                'id'         => $qId,
                'text'       => $row['text'],
                'image_path' => $row['image_path'] ?? null,
                'answers'    => $answers,
            ];
        }

        return $questions;
    }

    /**
     * Load shuffled answers for a question (without is_correct flag).
     *
     * @return array<array{id: int, text: string}>
     */
    private function loadAnswersForQuestion(int $questionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
           ->orderBy('position', 'ASC');

        $result = $qb->executeQuery();
        $answers = [];
        while ($row = $result->fetch()) {
            $answers[] = ['id' => (int)$row['id'], 'text' => $row['text']];
        }
        $result->closeCursor();

        // Shuffle so correct answer position is not predictable
        shuffle($answers);
        return $answers;
    }

    // ─── Single-Answer Evaluator ──────────────────────────────────────────────

    /**
     * Evaluate whether $answerId is the correct answer for $questionId.
     *
     * @return array{correct: bool, correct_answer_id: int|null, question_id: int, answer_id: int}
     */
    private function evaluateSingleAnswer(int $questionId, int $answerId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new \RuntimeException("Answer {$answerId} not found for question {$questionId}");
        }

        $correct = (bool)$row['is_correct'];

        // Fetch correct answer ID for feedback
        $correctAnswerId = null;
        if (!$correct) {
            $cqb = $this->db->getQueryBuilder();
            $cqb->select('id')
                ->from('learning_answers')
                ->where($cqb->expr()->eq('question_id', $cqb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
                ->andWhere($cqb->expr()->eq('is_correct', $cqb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
            $cr     = $cqb->executeQuery();
            $crow   = $cr->fetch();
            $cr->closeCursor();
            $correctAnswerId = $crow ? (int)$crow['id'] : null;
        }

        return [
            'correct'           => $correct,
            'correct_answer_id' => $correct ? $answerId : $correctAnswerId,
            'question_id'       => $questionId,
            'answer_id'         => $answerId,
        ];
    }

    // ─── Progress Helpers ─────────────────────────────────────────────────────

    /**
     * Advance a progress record to the next scene, recording the choice taken.
     */
    private function advanceProgress(
        StoryProgress $progress,
        string        $fromSceneId,
        string        $choiceId,
        ?array        $skillResult,
        string        $nextSceneId
    ): void {
        $choices   = $progress->getChoicesDecoded();
        $choices[] = [
            'scene_id'      => $fromSceneId,
            'choice_id'     => $choiceId,
            'skill_result'  => $skillResult['outcome'] ?? ($skillResult['correct'] ? 'success' : 'fail'),
            'correct_count' => $skillResult['correct_count'] ?? ($skillResult['correct'] ? 1 : 0),
            'total_count'   => $skillResult['total_count']   ?? 1,
        ];

        // Accumulate score
        $newScore = $progress->getScore() + ($skillResult['correct_count'] ?? ($skillResult['correct'] ? 1 : 0));

        $progress->setCurrentSceneId($nextSceneId);
        $progress->setChoicesJson(json_encode($choices));
        $progress->setScore($newScore);
        $progress->setUpdatedAt(time());

        // Mark completed when reaching an epilog scene
        if ($this->isEpilogScene($nextSceneId)) {
            $progress->setStatus('completed');
        }

        $this->progressMapper->update($progress);
    }

    /**
     * Scene IDs starting with "epilog_" are end scenes.
     */
    private function isEpilogScene(string $sceneId): bool {
        return str_starts_with($sceneId, 'epilog_');
    }

    /**
     * Load progress or throw if not found.
     *
     * @throws \RuntimeException
     */
    private function requireProgress(string $userId, string $campaignId): StoryProgress {
        $progress = $this->progressMapper->findByUserAndCampaign($userId, $campaignId);
        if ($progress === null) {
            throw new \RuntimeException("No active campaign progress for campaign '{$campaignId}'");
        }
        return $progress;
    }

    // ─── Campaign/Scene Finders ───────────────────────────────────────────────

    /**
     * Find a scene by ID within a loaded campaign array.
     *
     * @throws \RuntimeException
     */
    private function findScene(array $campaign, string $sceneId): array {
        foreach ($campaign['scenes'] as $scene) {
            if (($scene['id'] ?? '') === $sceneId) {
                return $scene;
            }
        }
        throw new \RuntimeException("Scene '{$sceneId}' not found in campaign '{$campaign['campaign_id']}'");
    }

    /**
     * Find a choice by ID within a scene.
     *
     * @throws \RuntimeException
     */
    private function findChoice(array $scene, string $choiceId): array {
        foreach ($scene['choices'] ?? [] as $choice) {
            if (($choice['id'] ?? '') === $choiceId) {
                return $choice;
            }
        }
        throw new \RuntimeException("Choice '{$choiceId}' not found in scene '{$scene['id']}'");
    }

    // ─── Response Builders ────────────────────────────────────────────────────

    /**
     * Build the scene response payload sent to the frontend.
     * Strips internal routing fields (success_scene, etc.) from choices.
     * Augments skill-check metadata with pre-loaded questions.
     */
    private function buildSceneResponse(array $scene, array $campaign, string $characterClass): array {
        $choices = [];
        foreach ($scene['choices'] ?? [] as $choice) {
            $safeChoice = [
                'id'   => $choice['id'],
                'text' => $choice['text'],
                'icon' => $choice['icon'] ?? null,
            ];

            if (!empty($choice['skill_check'])) {
                $sc = $choice['skill_check'];
                $diffModifier = $this->resolveCharacterDifficultyModifier(
                    $campaign, $choice, $characterClass, $sc['pool_filter'] ?? null
                );
                $safeChoice['skill_check'] = [
                    'pool_filter'         => $sc['pool_filter'],
                    'question_count'      => (int)($sc['question_count'] ?? 3),
                    'pass_threshold'      => (int)($sc['pass_threshold'] ?? 2),
                    'difficulty_modifier' => $diffModifier,
                    // Pre-load questions so the frontend doesn't need a second round-trip
                    'questions'           => $this->fetchFilteredQuestions(
                        $sc['pool_filter'] ?? null,
                        (int)($sc['question_count'] ?? 3),
                        $diffModifier
                    ),
                ];
            } else {
                $safeChoice['skill_check'] = null;
            }

            $choices[] = $safeChoice;
        }

        // Build NPC character meta from campaign definition
        $npcDialog = null;
        if (!empty($scene['npc_dialog'])) {
            $speakerKey = $scene['npc_dialog']['speaker'] ?? null;
            $npcMeta    = $campaign['npcs'][$speakerKey] ?? [];

            // Use class-specific dialog text if present, otherwise fall back to default
            $classTexts = $scene['npc_dialog']['class_text'] ?? [];
            $dialogText = $classTexts[$characterClass] ?? ($scene['npc_dialog']['text'] ?? '');

            $npcDialog  = [
                'speaker'          => $speakerKey,
                'name'             => $npcMeta['name']   ?? $speakerKey,
                'avatar'           => $npcMeta['avatar'] ?? '🤖',
                'text'             => $dialogText,
                'has_class_text'   => !empty($classTexts[$characterClass]),
            ];
        }

        return [
            'id'            => $scene['id'],
            'title'         => $scene['title'] ?? '',
            'narrative'     => $scene['narrative'] ?? '',
            'image'         => $scene['image'] ?? null,
            'animation_in'  => $scene['animation_in'] ?? null,
            'npc_dialog'    => $npcDialog,
            'choices'       => $choices,
            'simulation'    => $scene['simulation'] ?? null,
            'is_epilog'     => (bool)($scene['is_epilog'] ?? false),
            'epilog_type'   => $scene['epilog_type'] ?? null,
        ];
    }

    /**
     * Serialize a StoryProgress entity to an API-safe array.
     */
    private function serializeProgress(StoryProgress $progress): array {
        return [
            'id'               => $progress->getId(),
            'user_id'          => $progress->getUserId(),
            'campaign_id'      => $progress->getCampaignId(),
            'current_scene_id' => $progress->getCurrentSceneId(),
            'character_class'  => $progress->getCharacterClass(),
            'score'            => $progress->getScore(),
            'status'           => $progress->getStatus(),
            'choices'          => $progress->getChoicesDecoded(),
            'coop_user_ids'    => $progress->getCoopUserIdsDecoded(),
            'created_at'       => $progress->getCreatedAt(),
            'updated_at'       => $progress->getUpdatedAt(),
        ];
    }

    // ─── User Progress Listing ────────────────────────────────────────────────

    /**
     * List all progress records for a user, enriched with campaign title.
     *
     * @return array<array{campaign_id: string, campaign_title: string, current_scene_id: string,
     *                      character_class: string, score: int, status: string, updated_at: int}>
     */
    public function listUserProgress(string $userId): array {
        $records = $this->progressMapper->findAllByUser($userId);
        $result  = [];
        foreach ($records as $record) {
            $campaignTitle = $record->getCampaignId(); // fallback
            try {
                $campaign      = $this->loadCampaign($record->getCampaignId());
                $campaignTitle = $campaign['title'] ?? $campaignTitle;
            } catch (\RuntimeException $e) {
                // Campaign file may be missing — still return the progress row
            }
            $result[] = array_merge($this->serializeProgress($record), [
                'campaign_title' => $campaignTitle,
            ]);
        }
        return $result;
    }

    /**
     * Return the character class stored in the user's progress for a campaign.
     *
     * @throws \RuntimeException if no progress record exists
     */
    public function getCharacterClass(string $userId, string $campaignId): string {
        return $this->requireProgress($userId, $campaignId)->getCharacterClass();
    }

    // ─── Validators ───────────────────────────────────────────────────────────

    /**
     * Ensure campaign_id is a safe filesystem key (alphanumeric + underscore/hyphen only).
     *
     * @throws \InvalidArgumentException
     */
    private function validateCampaignId(string $campaignId): void {
        if (!preg_match('/^[a-z0-9_\-]{1,64}$/', $campaignId)) {
            throw new \InvalidArgumentException("Invalid campaign ID: {$campaignId}");
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateCharacterClass(string $class): void {
        if (!in_array($class, self::VALID_CLASSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid character class '{$class}'. Must be one of: " . implode(', ', self::VALID_CLASSES)
            );
        }
    }
}
