<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * LernbotFileService — Phase 23: NC Files Integration
 *
 * Creates and manages Obsidian-compatible Markdown notes in the user's
 * Nextcloud home directory under /Learning/.
 *
 * FILES-01: ensureLearningFolder — creates /Learning/ + subfolders on first access
 * FILES-02: writeNote / listNotes — create/update Markdown files
 * FILES-03: Folder structure /Learning/Zusammenfassungen/ + /Schwachstellen/
 * FILES-04: buildFrontmatter — YAML frontmatter (created, source, topic, status, chapter)
 * FILES-05: Obsidian-compatible: Wiki-Links [[...]] and Tags #tag preserved in content
 */
class LernbotFileService {
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    /** Top-level folder name in the user's NC home */
    private const LEARNING_FOLDER = 'Learning';

    /** Required subfolders inside /Learning/ */
    private const SUBFOLDERS = ['Zusammenfassungen', 'Schwachstellen'];

    /** Allowed frontmatter keys and their order */
    private const FRONTMATTER_KEYS = ['created', 'updated', 'source', 'topic', 'status', 'chapter', 'tags'];

    public function __construct(IRootFolder $rootFolder, LoggerInterface $logger) {
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Ensure /Learning/ and its required subfolders exist in the user's NC home.
     *
     * Idempotent — safe to call on every bot action.
     *
     * FILES-01, FILES-03
     *
     * @throws \OCP\Files\NotPermittedException
     */
    public function ensureLearningFolder(string $userId): Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);

        // Create /Learning/ if needed
        if (!$userFolder->nodeExists(self::LEARNING_FOLDER)) {
            $userFolder->newFolder(self::LEARNING_FOLDER);
        }

        /** @var Folder $learningFolder */
        $learningFolder = $userFolder->get(self::LEARNING_FOLDER);

        // Create required subfolders
        foreach (self::SUBFOLDERS as $sub) {
            if (!$learningFolder->nodeExists($sub)) {
                $learningFolder->newFolder($sub);
            }
        }

        return $learningFolder;
    }

    /**
     * Build an Obsidian-compatible YAML frontmatter block.
     *
     * FILES-04 — fields: created, updated, source, topic, status, chapter, tags
     *
     * @param array<string, mixed> $meta  Associative array of frontmatter values.
     *                                    Unknown keys are included as-is.
     *                                    Missing standard keys are omitted.
     * @return string  YAML block including surrounding --- delimiters, trailing newline.
     */
    public function buildFrontmatter(array $meta): string {
        // Ensure 'created' defaults to now if not provided
        if (!isset($meta['created'])) {
            $meta['created'] = date('Y-m-d\TH:i:sP');
        }
        if (!isset($meta['updated'])) {
            $meta['updated'] = date('Y-m-d\TH:i:sP');
        }

        $lines = ['---'];

        // Output standard keys in defined order first
        foreach (self::FRONTMATTER_KEYS as $key) {
            if (array_key_exists($key, $meta)) {
                $lines[] = $key . ': ' . $this->yamlScalar($meta[$key]);
                unset($meta[$key]);
            }
        }

        // Output any remaining custom keys
        foreach ($meta as $key => $value) {
            $lines[] = $key . ': ' . $this->yamlScalar($value);
        }

        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Create or update a Markdown note in the user's /Learning/ folder.
     *
     * FILES-02, FILES-04, FILES-05
     *
     * @param string $userId        NC user ID
     * @param string $subfolder     Subfolder inside /Learning/ (e.g. 'Zusammenfassungen').
     *                              Empty string = directly in /Learning/.
     * @param string $filename      Filename without or with .md extension.
     * @param string $bodyMarkdown  The note body. Wiki-Links [[...]] and Tags #tag are preserved.
     * @param array  $meta          YAML frontmatter fields (see buildFrontmatter).
     * @throws \InvalidArgumentException  On invalid filename
     * @throws \OCP\Files\NotPermittedException
     */
    public function writeNote(
        string $userId,
        string $subfolder,
        string $filename,
        string $bodyMarkdown,
        array $meta = []
    ): void {
        $filename = $this->sanitizeFilename($filename);

        $learningFolder = $this->ensureLearningFolder($userId);

        // Resolve target folder
        if ($subfolder !== '') {
            $subfolder = trim($subfolder, '/');
            if (!$learningFolder->nodeExists($subfolder)) {
                $learningFolder->newFolder($subfolder);
            }
            /** @var Folder $targetFolder */
            $targetFolder = $learningFolder->get($subfolder);
        } else {
            $targetFolder = $learningFolder;
        }

        // Update 'updated' timestamp
        $meta['updated'] = date('Y-m-d\TH:i:sP');

        $fullContent = $this->buildFrontmatter($meta) . $bodyMarkdown;

        if ($targetFolder->nodeExists($filename)) {
            /** @var \OCP\Files\File $file */
            $file = $targetFolder->get($filename);
            $file->putContent($fullContent);
            $this->logger->debug('LernbotFileService: updated note {path}', [
                'path' => '/Learning/' . ($subfolder !== '' ? $subfolder . '/' : '') . $filename,
                'app' => 'learning',
            ]);
        } else {
            $file = $targetFolder->newFile($filename);
            $file->putContent($fullContent);
            $this->logger->debug('LernbotFileService: created note {path}', [
                'path' => '/Learning/' . ($subfolder !== '' ? $subfolder . '/' : '') . $filename,
                'app' => 'learning',
            ]);
        }
    }

    /**
     * List .md files in /Learning/ or a subfolder.
     *
     * FILES-02
     *
     * @param string $userId     NC user ID
     * @param string $subfolder  Optional subfolder inside /Learning/ ('' = list /Learning/ itself)
     * @return array<int, array{name: string, path: string, modified: int}>
     */
    public function listNotes(string $userId, string $subfolder = ''): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            if (!$userFolder->nodeExists(self::LEARNING_FOLDER)) {
                return [];
            }
            /** @var Folder $learningFolder */
            $learningFolder = $userFolder->get(self::LEARNING_FOLDER);

            $targetFolder = $learningFolder;
            if ($subfolder !== '') {
                $subfolder = trim($subfolder, '/');
                if (!$learningFolder->nodeExists($subfolder)) {
                    return [];
                }
                /** @var Folder $targetFolder */
                $targetFolder = $learningFolder->get($subfolder);
            }

            $notes = [];
            foreach ($targetFolder->getDirectoryListing() as $node) {
                if ($node->getType() !== Node::TYPE_FILE) {
                    continue;
                }
                if (!str_ends_with(strtolower($node->getName()), '.md')) {
                    continue;
                }
                $notes[] = [
                    'name' => $node->getName(),
                    'path' => '/Learning/' . ($subfolder !== '' ? $subfolder . '/' : '') . $node->getName(),
                    'modified' => $node->getMTime(),
                ];
            }

            // Sort by modified DESC (most recently changed first)
            usort($notes, fn($a, $b) => $b['modified'] <=> $a['modified']);

            return $notes;
        } catch (\Throwable $e) {
            $this->logger->warning('LernbotFileService: listNotes failed: ' . $e->getMessage(), [
                'app' => 'learning',
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Enforce .md extension and reject path-traversal attempts.
     *
     * @throws \InvalidArgumentException
     */
    private function sanitizeFilename(string $filename): string {
        $filename = trim($filename);

        if ($filename === '') {
            throw new \InvalidArgumentException('Filename must not be empty');
        }

        // Reject path separators and traversal
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            throw new \InvalidArgumentException('Filename must not contain path separators or ".."');
        }

        // Ensure .md extension
        if (!str_ends_with(strtolower($filename), '.md')) {
            $filename .= '.md';
        }

        return $filename;
    }

    /**
     * Render a PHP value as a YAML scalar (inline, single-line).
     * Strings with special characters are single-quoted.
     */
    private function yamlScalar(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            // Inline YAML sequence for simple string arrays (tags etc.)
            $items = array_map(fn($v) => $this->yamlScalar($v), $value);
            return '[' . implode(', ', $items) . ']';
        }

        $str = (string)$value;

        // Strings that need quoting: contain special YAML chars, or look numeric
        $needsQuoting = $str === ''
            || preg_match('/[:#\[\]{}|>&*!,\'"%@`]/', $str)
            || preg_match('/^[\-\?]/', $str)
            || is_numeric($str);

        if ($needsQuoting) {
            // Single-quote the value; escape inner single quotes by doubling
            return "'" . str_replace("'", "''", $str) . "'";
        }

        return $str;
    }
}
