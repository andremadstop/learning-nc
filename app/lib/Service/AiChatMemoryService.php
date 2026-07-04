<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
use Psr\Log\LoggerInterface;

/**
 * Manages persistent VirtuProf chat context per user.
 *
 * Design constraints (MEM-03):
 *   - Max 50 entries per user in DB.
 *   - When the cap is reached after a new saveExchange(), the oldest 10 entries
 *     are compressed into a single 'summary' row via Gemini. If Gemini fails,
 *     a static placeholder summary is stored instead.
 *   - The compression GeminiService call bypasses user rate-limits (internal call).
 */
class AiChatMemoryService {
    private const MAX_ENTRIES = 50;
    private const COMPRESS_COUNT = 10;
    private const CONTEXT_LIMIT = 10;

    private AiChatMemoryMapper $mapper;
    private GeminiService $geminiService;
    private LoggerInterface $logger;

    public function __construct(
        AiChatMemoryMapper $mapper,
        GeminiService $geminiService,
        LoggerInterface $logger
    ) {
        $this->mapper = $mapper;
        $this->geminiService = $geminiService;
        $this->logger = $logger;
    }

    /**
     * Return up to CONTEXT_LIMIT most recent entries, oldest-first.
     *
     * @return array<int, array{role: string, message: string}>
     */
    public function loadMemory(string $userId): array {
        $entries = $this->mapper->findRecentByUser($userId, self::CONTEXT_LIMIT);

        return array_map(
            static fn(AiChatMemory $e) => [
                'role' => $e->getRole(),
                'message' => $e->getMessage(),
            ],
            $entries
        );
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
     */
    public function saveExchange(string $userId, string $userMessage, string $assistantMessage): void {
        $now = time();

        $userEntry = new AiChatMemory();
        $userEntry->setUserId($userId);
        $userEntry->setRole('user');
        $userEntry->setMessage(mb_substr($userMessage, 0, 1000));
        $userEntry->setCreatedAt($now);
        $this->mapper->insert($userEntry);

        $assistantEntry = new AiChatMemory();
        $assistantEntry->setUserId($userId);
        $assistantEntry->setRole('assistant');
        $assistantEntry->setMessage(mb_substr($assistantMessage, 0, 2000));
        $assistantEntry->setCreatedAt($now + 1);
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

        $summary = $this->compressEntries($oldest, $userId);
        $oldIds = array_map(static fn(AiChatMemory $e) => $e->getId(), $oldest);

        // Delete the originals, then insert the summary
        $this->mapper->deleteByIds($oldIds);

        $summaryEntry = new AiChatMemory();
        $summaryEntry->setUserId($userId);
        $summaryEntry->setRole('summary');
        $summaryEntry->setMessage($summary);
        // Use the timestamp of the oldest entry so the summary sorts before remaining entries
        $summaryEntry->setCreatedAt($oldest[0]->getCreatedAt());
        $this->mapper->insert($summaryEntry);
    }

    /**
     * Compress a list of entries into a short summary string.
     * Tries Gemini; falls back to a static placeholder on failure.
     *
     * @param AiChatMemory[] $entries
     */
    private function compressEntries(array $entries, string $userId): string {
        $lines = [];
        foreach ($entries as $entry) {
            $prefix = $entry->getRole() === 'user' ? '[User]' : '[VirtuProf]';
            if ($entry->getRole() === 'summary') {
                $prefix = '[Earlier Summary]';
            }
            $lines[] = $prefix . ': ' . mb_substr($entry->getMessage(), 0, 200);
        }

        $conversationText = implode("\n", $lines);

        $systemPrompt = 'You are a conversation summarizer. Summarize the following chat excerpt '
            . 'into 2-3 sentences preserving the key learning topics discussed. '
            . 'Respond with only the summary text, no preamble.';
        $userPrompt = "Chat excerpt to summarize:\n\n{$conversationText}";

        try {
            // MED-09: memory compression counts against the shared AI rate limit.
            $summary = $this->geminiService->generateNote($systemPrompt, $userPrompt, $userId);
            return mb_substr(trim($summary), 0, 1000);
        } catch (\Throwable $e) {
            $this->logger->info(
                'AiChatMemoryService: Gemini compression failed, using static summary',
                ['app' => 'learning', 'error' => $e->getMessage()]
            );
            return 'Summary of earlier conversation covering various learning topics.';
        }
    }
}
