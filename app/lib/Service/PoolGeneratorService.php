<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Pool;
use Psr\Log\LoggerInterface;

class PoolGeneratorService {
    private LlmService $llmService;
    private PoolService $poolService;
    private QuestionService $questionService;
    private LoggerInterface $logger;
    private \OCP\Files\IRootFolder $rootFolder;

    private const MAX_CHUNK_WORDS = 3000;

    public function __construct(
        LlmService $llmService,
        PoolService $poolService,
        QuestionService $questionService,
        LoggerInterface $logger,
        \OCP\Files\IRootFolder $rootFolder
    ) {
        $this->llmService = $llmService;
        $this->poolService = $poolService;
        $this->questionService = $questionService;
        $this->logger = $logger;
        $this->rootFolder = $rootFolder;
    }

    /**
     * Get the active provider info for the frontend.
     *
     * @return array{provider: string, available: bool}
     */
    public function getProviderInfo(): array {
        return [
            'provider' => $this->llmService->getProviderId(),
            'available' => $this->llmService->isAvailable(),
        ];
    }

    /**
     * Generate draft questions from plain text.
     *
     * @return array<int, array<string, mixed>> Draft questions
     */
    public function generateFromText(string $text, string $userId, int $questionCount = 20): array {
        if (!$this->llmService->isAvailable()) {
            throw new \RuntimeException('No AI provider configured');
        }

        $questionCount = max(1, min(30, $questionCount));
        $text = trim($text);

        if (mb_strlen($text) < 50) {
            throw new \RuntimeException('Text too short (minimum 50 characters)');
        }

        $chunks = $this->chunkText($text);
        $questionsPerChunk = (int)ceil($questionCount / count($chunks));
        $allDrafts = [];

        foreach ($chunks as $index => $chunk) {
            $remaining = $questionCount - count($allDrafts);
            if ($remaining <= 0) {
                break;
            }

            $count = min($questionsPerChunk, $remaining);
            $prompt = $this->buildPrompt($chunk, $count);

            try {
                $output = $this->llmService->generateText($prompt, [
                    'system_prompt' => 'You are an expert exam question writer. Output ONLY valid JSON arrays. No markdown, no explanation, no backticks.',
                    'temperature' => 0.5,
                    'max_output_tokens' => 3200,
                ]);

                $parsed = $this->parseOutput($output);
                // Attach source chunk reference
                foreach ($parsed as &$q) {
                    $q['source_chunk'] = mb_substr($chunk, 0, 300) . (mb_strlen($chunk) > 300 ? '...' : '');
                }
                unset($q);
                $allDrafts = array_merge($allDrafts, $parsed);
            } catch (\Throwable $e) {
                $this->logger->warning('PoolGeneratorService: chunk {idx} failed: {msg}', [
                    'idx' => $index,
                    'msg' => $e->getMessage(),
                    'app' => 'learning',
                ]);
                // Continue with remaining chunks
            }
        }

        return array_slice($allDrafts, 0, $questionCount);
    }

    /**
     * Generate draft questions from a Nextcloud file path.
     *
     * @return array<int, array<string, mixed>> Draft questions
     */
    public function generateFromFile(string $filePath, string $userId, int $questionCount = 20): array {
        $userFolder = $this->rootFolder->getUserFolder($userId);

        try {
            /** @var \OCP\Files\File $file */
            $file = $userFolder->get($filePath);
        } catch (\OCP\Files\NotFoundException $e) {
            throw new \RuntimeException('File not found: ' . $filePath);
        }

        $content = $file->getContent();
        $ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            $text = $this->extractPdfText($content);
        } elseif (in_array($ext, ['md', 'markdown', 'txt'], true)) {
            // Strip YAML frontmatter for markdown
            $text = preg_replace('/\A---\s*\n.*?\n---\s*\n/s', '', $content) ?? $content;
        } else {
            throw new \RuntimeException('Unsupported file type: ' . $ext . '. Supported: pdf, md, txt');
        }

        return $this->generateFromText($text, $userId, $questionCount);
    }

    /**
     * Create a pool from accepted draft questions.
     *
     * @param array<int, array<string, mixed>> $questions Accepted draft questions
     * @return array{pool_id: int, imported: int}
     */
    public function createPool(string $title, array $questions, string $userId): array {
        if (empty($title) || mb_strlen($title) > 200) {
            throw new \RuntimeException('Pool title must be 1-200 characters');
        }

        if (empty($questions)) {
            throw new \RuntimeException('At least one question is required');
        }

        if (count($questions) > 100) {
            throw new \RuntimeException('Maximum 100 questions per pool');
        }

        $pool = $this->poolService->create($title, 'Generated by AI Pool Generator', $userId);
        $poolId = $pool->getId();
        $imported = 0;

        foreach ($questions as $q) {
            if (empty($q['text']) || !isset($q['answers']) || !is_array($q['answers'])) {
                continue;
            }

            $answers = [];
            foreach ($q['answers'] as $a) {
                if (empty($a['text'])) {
                    continue;
                }
                $answers[] = [
                    'text' => (string)$a['text'],
                    'is_correct' => !empty($a['is_correct']),
                ];
            }

            if (count($answers) < 2) {
                continue;
            }

            try {
                $this->questionService->create(
                    $poolId,
                    $userId,
                    (string)$q['text'],
                    $q['explanation'] ?? null,
                    $q['difficulty'] ?? 'medium',
                    $answers,
                    'single'
                );
                $imported++;
            } catch (\Throwable $e) {
                $this->logger->warning('PoolGeneratorService: failed to import question: {msg}', [
                    'msg' => $e->getMessage(),
                    'app' => 'learning',
                ]);
            }
        }

        return ['pool_id' => $poolId, 'imported' => $imported];
    }

    /**
     * Split text into chunks of max MAX_CHUNK_WORDS words.
     *
     * @return string[]
     */
    private function chunkText(string $text): array {
        $words = preg_split('/\s+/', $text);
        if ($words === false) {
            return [$text];
        }

        if (count($words) <= self::MAX_CHUNK_WORDS) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        while ($offset < count($words)) {
            $slice = array_slice($words, $offset, self::MAX_CHUNK_WORDS);
            $chunks[] = implode(' ', $slice);
            $offset += self::MAX_CHUNK_WORDS;
        }

        return $chunks;
    }

    private function buildPrompt(string $chunk, int $count): string {
        return "You are an expert exam question writer. Create exactly {$count} multiple-choice questions from this material.

Rules:
- Each question must be scenario-based (\"You are a network admin and...\", \"A user reports that...\", \"During a routine check you notice...\")
- Exactly 4 answer options, exactly 1 correct
- Wrong answers must be plausible, not obviously wrong
- Include a brief explanation referencing the source material
- Difficulty should vary: 30% easy, 50% medium, 20% hard
- Output ONLY a valid JSON array (no markdown, no backticks, no extra text)

JSON format:
[
  {
    \"text\": \"Question text here?\",
    \"answers\": [
      {\"text\": \"Answer A\", \"is_correct\": false},
      {\"text\": \"Answer B\", \"is_correct\": true},
      {\"text\": \"Answer C\", \"is_correct\": false},
      {\"text\": \"Answer D\", \"is_correct\": false}
    ],
    \"explanation\": \"Brief explanation\",
    \"difficulty\": \"easy\"
  }
]

Material:
{$chunk}";
    }

    /**
     * Parse LLM output into validated question array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseOutput(string $output): array {
        // Strip markdown code fences if present
        $output = trim($output);
        $output = preg_replace('/^```(?:json)?\s*/i', '', $output);
        $output = preg_replace('/\s*```$/', '', $output);
        $output = trim($output);

        $data = json_decode($output, true);
        if (!is_array($data)) {
            $this->logger->warning('PoolGeneratorService: output is not valid JSON', [
                'app' => 'learning',
                'output' => substr($output, 0, 500),
            ]);
            return [];
        }

        $questions = [];
        foreach ($data as $item) {
            if (!is_array($item) || empty($item['text']) || !isset($item['answers']) || !is_array($item['answers'])) {
                continue;
            }

            if (count($item['answers']) !== 4) {
                continue;
            }

            $correctCount = 0;
            $validAnswers = [];
            foreach ($item['answers'] as $answer) {
                if (!is_array($answer) || empty($answer['text'])) {
                    continue 2;
                }
                $isCorrect = !empty($answer['is_correct']);
                if ($isCorrect) {
                    $correctCount++;
                }
                $validAnswers[] = [
                    'text' => (string)$answer['text'],
                    'is_correct' => $isCorrect,
                ];
            }

            if ($correctCount !== 1 || count($validAnswers) !== 4) {
                continue;
            }

            $difficulty = in_array($item['difficulty'] ?? '', ['easy', 'medium', 'hard'], true)
                ? $item['difficulty']
                : 'medium';

            $questions[] = [
                'text' => (string)$item['text'],
                'answers' => $validAnswers,
                'explanation' => (string)($item['explanation'] ?? ''),
                'difficulty' => $difficulty,
                'source_chunk' => '',
            ];
        }

        return $questions;
    }

    /**
     * Extract text from PDF content using pdftotext.
     *
     * @throws \RuntimeException If pdftotext is not available or extraction fails
     */
    private function extractPdfText(string $pdfContent): string {
        $whichResult = [];
        $whichCode = 0;
        exec('which pdftotext 2>/dev/null', $whichResult, $whichCode);
        if ($whichCode !== 0) {
            throw new \RuntimeException('pdftotext is not installed');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'learning_pg_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        try {
            file_put_contents($tmpFile, $pdfContent);
            $output = [];
            $returnCode = 0;
            exec('pdftotext ' . escapeshellarg($tmpFile) . ' - 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException('pdftotext failed: ' . implode("\n", $output));
            }

            $text = implode("\n", $output);
            if (trim($text) === '') {
                throw new \RuntimeException('PDF returned empty text (may be image-only)');
            }

            return $text;
        } finally {
            @unlink($tmpFile);
        }
    }
}
