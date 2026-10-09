<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
use Psr\Log\LoggerInterface;

/**
 * Manages persistent VirtuProf chat context per user.
 *
 * Design constraints (MEM-03):
 *   - Max 50 entries per user in DB.
 *   - When the cap is reached after a new saveExchange(), the oldest 10 entries
 *     are compressed into a single 'summary' row via Gemini. If that cannot run
 *     (no consent, consent revoked meanwhile, budget, provider error, empty answer),
 *     compression is skipped and the entries stay; the next exchange tries again.
 *   - The compression GeminiService call bypasses user rate-limits (internal call).
 *
 * Swarm memory (spec 3.6, 5.7.0): every answer and summary records whether its prompt carried
 * another learner's contribution (swarm_sourced 1), did not (0), or is unknown (NULL: stored
 * before 5.7.0, when no filter existed). The learner's own messages are always clean.
 *   - loadMemory() gives an external recipient — and a caller without a session — only clean
 *     answers and summaries; a local recipient gets everything, unknown marked as swarm-sourced.
 *   - Compression summarises only the learner's own messages and clean answers and summaries,
 *     whatever the recipient; the summary it stores is clean.
 */
class AiChatMemoryService {
    private const MAX_ENTRIES = 50;
    private const COMPRESS_COUNT = 10;
    private const CONTEXT_LIMIT = 10;

    private AiChatMemoryMapper $mapper;
    private GeminiService $geminiService;
    private LoggerInterface $logger;

    private TelosService $telosService;

    public function __construct(
        AiChatMemoryMapper $mapper,
        GeminiService $geminiService,
        LoggerInterface $logger,
        TelosService $telosService
    ) {
        $this->mapper = $mapper;
        $this->geminiService = $geminiService;
        $this->logger = $logger;
        $this->telosService = $telosService;
    }

    /**
     * Return up to CONTEXT_LIMIT most recent entries, oldest-first, as far as the session's
     * recipient may receive them. `swarm` is true when the entry may carry another learner's
     * contribution (swarm-sourced or unknown); an answer built on it inherits that mark.
     *
     * @param LlmSession|null $session the session the memory will be sent with; without one the
     *   memory is loaded as for an external recipient (fail closed)
     * @return list<array{role: string, message: string, swarm: bool}>
     */
    public function loadMemory(string $userId, ?LlmSession $session = null): array {
        $entries = $this->mapper->findRecentByUser($userId, self::CONTEXT_LIMIT);
        $local = $session !== null && $session->recipient()->isLocal;

        $out = [];
        foreach ($entries as $e) {
            $swarm = self::maySpreadSwarm($e);
            if ($swarm && !$local) {
                continue;
            }
            $out[] = [
                'role' => $e->getRole(),
                'message' => $e->getMessage(),
                'swarm' => $swarm,
            ];
        }
        return $out;
    }

    /**
     * True when the entry may carry another learner's contribution: an answer or summary that is
     * swarm-sourced or of unknown origin. The learner's own messages never do.
     */
    private static function maySpreadSwarm(AiChatMemory $e): bool {
        return $e->getRole() !== 'user' && $e->getSwarmSourced() !== 0;
    }

    /**
     * Return up to $limit most recent entries as raw entities (for getChatHistory endpoint).
     *
     * @return AiChatMemory[]
     */
    public function loadRecentEntries(string $userId, int $limit = 20): array {
        return $this->mapper->findRecentByUser($userId, $limit);
    }

    /**
     * Save a user message and the bot's reply. Then enforce the 50-entry cap.
     *
     * @param bool $swarmSourced the reply's prompt carried another learner's contribution (RAG
     *   chunk or a remembered swarm-sourced answer); deliberately without a default
     */
    public function saveExchange(string $userId, string $userMessage, string $assistantMessage, bool $swarmSourced): void {
        $now = time();

        $userEntry = new AiChatMemory();
        $userEntry->setUserId($userId);
        $userEntry->setRole('user');
        $userEntry->setMessage(mb_substr($userMessage, 0, 1000));
        $userEntry->setCreatedAt($now);
        $userEntry->setSwarmSourced(0);
        $this->mapper->insert($userEntry);

        $assistantEntry = new AiChatMemory();
        $assistantEntry->setUserId($userId);
        $assistantEntry->setRole('assistant');
        $assistantEntry->setMessage(mb_substr($assistantMessage, 0, 2000));
        $assistantEntry->setCreatedAt($now + 1);
        $assistantEntry->setSwarmSourced($swarmSourced ? 1 : 0);
        $this->mapper->insert($assistantEntry);

        // Enforce cap: if now over MAX_ENTRIES, compress oldest COMPRESS_COUNT
        $this->enforceCapIfNeeded($userId);
    }

    /**
     * Delete all memory entries for this user (privacy / MEM-04).
     */
    public function clearMemory(string $userId): void {
        $this->mapper->deleteAllByUser($userId);
    }

    /**
     * If user has more than MAX_ENTRIES, compress the oldest COMPRESS_COUNT into a summary.
     */
    private function enforceCapIfNeeded(string $userId): void {
        $count = $this->mapper->countByUser($userId);
        if ($count <= self::MAX_ENTRIES) {
            return;
        }

        // Fetch the oldest entries for compression
        $oldest = $this->mapper->findOldestByUser($userId, self::COMPRESS_COUNT);
        if (empty($oldest)) {
            return;
        }

        $oldIds = array_map(static fn(AiChatMemory $e) => $e->getId(), $oldest);
        // spec 3.6: a summary is built only from the learner's own messages and clean answers
        $clean = array_values(array_filter($oldest, static fn(AiChatMemory $e): bool => !self::maySpreadSwarm($e)));
        if ($clean === []) {
            // nothing that may be summarised: the swarm-sourced entries go without a summary
            $this->mapper->deleteByIds($oldIds);
            return;
        }

        $summary = $this->compressEntries($clean, $userId);
        if ($summary === null) {
            // 5.7.0 (Task B5): skip without data loss — never replace the originals by a placeholder
            return;
        }

        // Delete the originals, then insert the summary
        $this->mapper->deleteByIds($oldIds);

        $summaryEntry = new AiChatMemory();
        $summaryEntry->setUserId($userId);
        $summaryEntry->setRole('summary');
        $summaryEntry->setMessage($summary);
        // Use the timestamp of the oldest entry so the summary sorts before remaining entries
        $summaryEntry->setCreatedAt($oldest[0]->getCreatedAt());
        $summaryEntry->setSwarmSourced(0);
        $this->mapper->insert($summaryEntry);
    }

    /**
     * Compress a list of entries into a short summary string.
     *
     * @param AiChatMemory[] $entries
     * @return string|null null when compression could not run; the caller then keeps the entries
     */
    private function compressEntries(array $entries, string $userId): ?string {
        $lines = [];
        foreach ($entries as $entry) {
            $prefix = $entry->getRole() === 'user' ? '[User]' : '[VirtuProf]';
            if ($entry->getRole() === 'summary') {
                $prefix = '[Earlier Summary]';
            }
            $lines[] = $prefix . ': ' . mb_substr($entry->getMessage(), 0, 200);
        }

        $conversationText = implode("\n", $lines);

        // 5.5.2: compression is a second request after the chat answer — check consent again right
        // before sending instead of relying on the controller's check for the first request.
        if (!$this->telosService->hasAiConsent($userId)) {
            return null;
        }

        $systemPrompt = 'You are a conversation summarizer. Summarize the following chat excerpt '
            . 'into 2-3 sentences preserving the key learning topics discussed. '
            . 'Respond with only the summary text, no preamble.';
        $userPrompt = "Chat excerpt to summarize:\n\n{$conversationText}";

        try {
            // MED-09: memory compression counts against the shared AI rate limit.
            $summary = mb_substr(trim($this->geminiService->generateNote($systemPrompt, $userPrompt, $userId, LlmPurpose::MemoryCompress)), 0, 1000);
            return $summary !== '' ? $summary : null;
        } catch (\Throwable $e) {
            // consent, budget, provider: the entries stay and the next exchange tries again
            $this->logger->info(
                'AiChatMemoryService: compression skipped ({error})',
                ['app' => 'learning', 'error' => $e instanceof LlmProviderException ? $e->category : get_class($e)]
            );
            return null;
        }
    }
}
