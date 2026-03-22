<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

class DocumentService {
    private CourseDocumentMapper $documentMapper;
    private CourseMapper $courseMapper;
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    /** Supported file extensions and their types */
    private const FILE_TYPES = [
        'pdf' => 'pdf',
        'md' => 'markdown',
        'markdown' => 'markdown',
    ];

    public function __construct(
        CourseDocumentMapper $documentMapper,
        CourseMapper $courseMapper,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        $this->documentMapper = $documentMapper;
        $this->courseMapper = $courseMapper;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    /**
     * Set the material folder for a course.
     *
     * @throws NotFoundException If folder does not exist in user's NC home
     * @throws \RuntimeException If user is not the course instructor
     */
    public function setMaterialFolder(int $courseId, string $folderPath, string $userId): void {
        $course = $this->courseMapper->findById($courseId);

        if ($course->getInstructorId() !== $userId) {
            throw new \RuntimeException('Only the course instructor can set the material folder');
        }

        // Verify folder exists in user's NC home
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $node = $userFolder->get($folderPath);
        if ($node->getType() !== Node::TYPE_FOLDER) {
            throw new NotFoundException('Path is not a folder');
        }

        $course->setMaterialFolder($folderPath);
        $course->setUpdatedAt(time());
        $this->courseMapper->update($course);

        $this->logger->info('DocumentService: material folder set for course {courseId}: {folder}', [
            'courseId' => $courseId,
            'folder' => $folderPath,
            'app' => 'learning',
        ]);
    }

    /**
     * Get the material folder path for a course.
     */
    public function getMaterialFolder(int $courseId): ?string {
        $course = $this->courseMapper->findById($courseId);
        return $course->getMaterialFolder();
    }

    /**
     * Scan the material folder and register new files in the DB.
     *
     * @return CourseDocument[]
     * @throws \RuntimeException If no material folder is set or user is not instructor
     */
    public function scanFolder(int $courseId, string $userId): array {
        $course = $this->courseMapper->findById($courseId);

        if ($course->getInstructorId() !== $userId) {
            throw new \RuntimeException('Only the course instructor can scan the material folder');
        }

        $materialFolder = $course->getMaterialFolder();
        if ($materialFolder === null || $materialFolder === '') {
            throw new \RuntimeException('No material folder set for this course');
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);

        try {
            $folder = $userFolder->get($materialFolder);
        } catch (NotFoundException $e) {
            throw new \RuntimeException('Material folder not found: ' . $materialFolder);
        }

        if ($folder->getType() !== Node::TYPE_FOLDER) {
            throw new \RuntimeException('Material path is not a folder');
        }

        /** @var \OCP\Files\Folder $folder */
        $nodes = $folder->getDirectoryListing();
        $now = time();

        foreach ($nodes as $node) {
            if ($node->getType() !== Node::TYPE_FILE) {
                continue;
            }

            $name = $node->getName();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!isset(self::FILE_TYPES[$ext])) {
                continue;
            }

            $relativePath = $materialFolder . '/' . $name;

            // Check if already registered
            $existing = $this->documentMapper->findByPath($relativePath);
            if ($existing !== null) {
                continue;
            }

            $doc = new CourseDocument();
            $doc->setCourseId($courseId);
            $doc->setFilePath($relativePath);
            $doc->setFileName($name);
            $doc->setFileType(self::FILE_TYPES[$ext]);
            $doc->setStatus('uploaded');
            $doc->setFileSize($node->getSize());
            $doc->setCreatedAt($now);
            $doc->setUpdatedAt($now);

            $this->documentMapper->insert($doc);
        }

        return $this->documentMapper->findByCourseId($courseId);
    }

    /**
     * Extract text from a single document.
     *
     * @return CourseDocument The updated document entity
     * @throws \RuntimeException If user is not instructor or document not found
     */
    public function extractText(int $documentId, string $userId): CourseDocument {
        $doc = $this->documentMapper->findById($documentId);
        $course = $this->courseMapper->findById($doc->getCourseId());

        if ($course->getInstructorId() !== $userId) {
            throw new \RuntimeException('Only the course instructor can extract document text');
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);

        try {
            /** @var \OCP\Files\File $file */
            $file = $userFolder->get($doc->getFilePath());
            $content = $file->getContent();
        } catch (NotFoundException $e) {
            $doc->setStatus('error');
            $doc->setErrorMessage('File not found: ' . $doc->getFilePath());
            $doc->setUpdatedAt(time());
            $this->documentMapper->update($doc);
            return $doc;
        }

        try {
            if ($doc->getFileType() === 'pdf') {
                $text = $this->extractPdfText($content);
            } else {
                $text = $this->extractMarkdownText($content);
            }

            $doc->setExtractedText($text);
            $doc->setStatus('extracted');
            $doc->setChunkingStatus('pending');
            $doc->setErrorMessage(null);
        } catch (\RuntimeException $e) {
            $doc->setStatus('error');
            $doc->setErrorMessage($e->getMessage());
            $this->logger->warning('DocumentService: extraction failed for doc {id}: {msg}', [
                'id' => $documentId,
                'msg' => $e->getMessage(),
                'app' => 'learning',
            ]);
        }

        $doc->setUpdatedAt(time());
        $this->documentMapper->update($doc);

        return $doc;
    }

    /**
     * Scan folder and extract all documents with status 'uploaded'.
     *
     * @return CourseDocument[]
     */
    public function extractAll(int $courseId, string $userId): array {
        // Scan first to pick up new files
        $this->scanFolder($courseId, $userId);

        $documents = $this->documentMapper->findByCourseId($courseId);

        foreach ($documents as $doc) {
            if ($doc->getStatus() === 'uploaded') {
                $this->extractText($doc->getId(), $userId);
            }
        }

        // Return fresh list with updated statuses
        return $this->documentMapper->findByCourseId($courseId);
    }

    /**
     * List all documents for a course.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDocuments(int $courseId): array {
        $documents = $this->documentMapper->findByCourseId($courseId);
        return array_map(function (CourseDocument $doc) {
            return $doc->jsonSerialize();
        }, $documents);
    }

    // -------------------------------------------------------------------------
    // Private extraction helpers
    // -------------------------------------------------------------------------

    /**
     * Extract text from PDF content using pdftotext.
     *
     * @throws \RuntimeException If pdftotext is not available or extraction fails
     */
    private function extractPdfText(string $pdfContent): string {
        // Check pdftotext availability
        $whichResult = [];
        $whichCode = 0;
        exec('which pdftotext 2>/dev/null', $whichResult, $whichCode);
        if ($whichCode !== 0) {
            throw new \RuntimeException('pdftotext is not installed or not in PATH');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'learning_pdf_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temporary file for PDF extraction');
        }

        try {
            file_put_contents($tmpFile, $pdfContent);

            $output = [];
            $returnCode = 0;
            exec('pdftotext ' . escapeshellarg($tmpFile) . ' - 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException('pdftotext failed with code ' . $returnCode . ': ' . implode("\n", $output));
            }

            $text = implode("\n", $output);

            if (trim($text) === '') {
                throw new \RuntimeException('pdftotext returned empty text (PDF may be image-only)');
            }

            return $text;
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Extract text from Markdown content, stripping YAML frontmatter if present.
     */
    private function extractMarkdownText(string $content): string {
        // Strip YAML frontmatter (--- ... ---)
        $stripped = preg_replace('/\A---\s*\n.*?\n---\s*\n/s', '', $content);
        if ($stripped === null) {
            // Regex error — use content as-is
            return $content;
        }
        return $stripped;
    }
}
