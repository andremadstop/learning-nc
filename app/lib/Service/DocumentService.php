<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\Course;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;
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
     * @return string The folder as stored (normalized, see normalizePath())
     * @throws NotFoundException If folder does not exist in user's NC home
     * @throws \RuntimeException If user is not the course instructor
     * @throws \InvalidArgumentException If the folder is the home folder itself
     */
    public function setMaterialFolder(int $courseId, string $folderPath, string $userId): string {
        $course = $this->courseMapper->findById($courseId);

        if ($course->getInstructorId() !== $userId) {
            throw new \RuntimeException('Only the course instructor can set the material folder');
        }

        // GitHub #35: one spelling per folder. The file picker hands out '/Kurs', the text field
        // took 'Kurs' or 'Kurs/'; rows are compared in this form (isInMaterialFolder). Every file
        // of the material folder can be opened by course members, so the whole home is refused.
        $folderPath = self::normalizePath($folderPath);
        if ($folderPath === '/') {
            throw new \InvalidArgumentException('The home folder cannot be the material folder');
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

        return $folderPath;
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

        // Already registered for THIS course (the path is relative to the instructor's home;
        // another course may have the same folder name). Compared normalized: a folder typed as
        // 'Kurs' and picked again as '/Kurs' is the same folder, its files are no new rows.
        $registered = [];
        foreach ($this->documentMapper->findByCourseId($courseId) as $existingDoc) {
            $registered[self::normalizePath((string)$existingDoc->getFilePath())] = true;
        }
        $normalizedFolder = self::normalizePath($materialFolder);

        foreach ($nodes as $node) {
            if ($node->getType() !== Node::TYPE_FILE) {
                continue;
            }

            $name = $node->getName();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!isset(self::FILE_TYPES[$ext])) {
                continue;
            }

            $relativePath = self::materialFilePath($normalizedFolder, $name);
            if (isset($registered[$relativePath])) {
                continue;
            }
            $registered[$relativePath] = true;

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

        // Without pdftotext every PDF would only be marked as failed: leave them as they are
        // (GitHub #35). The single-document extract still says why it cannot work.
        $pdfAvailable = $this->isPdfTextExtractionAvailable();
        foreach ($documents as $doc) {
            if ($doc->getStatus() !== 'uploaded') {
                continue;
            }
            if ($doc->getFileType() === 'pdf' && !$pdfAvailable) {
                continue;
            }
            $this->extractText($doc->getId(), $userId);
        }

        // Return fresh list with updated statuses
        return $this->documentMapper->findByCourseId($courseId);
    }

    /**
     * List all documents for a course, as the viewer may see them (see projectDocuments()).
     *
     * @return list<array<string, mixed>>
     */
    public function listDocuments(int $courseId, bool $forInstructor = false): array {
        return $this->projectDocuments($courseId, $this->documentMapper->findByCourseId($courseId), $forInstructor);
    }

    /**
     * The document rows as the API hands them out (GitHub #35).
     *
     * The list used to be the raw entity, extracted text included, for every course member:
     * a student received the full text of every extracted PDF and the extraction errors. The
     * extracted text is never part of a list now (nothing in the browser reads it; it is
     * VirtuProf's context). Learners get what they need to open a file; the processing details
     * (status, error, path) stay with the instructors of the course. The folder name itself is
     * not hidden by this: course members still receive material_folder with the course.
     *
     * "downloadable" says whether the download endpoint would serve the row: a row whose path no
     * longer lies in the current material folder (the folder was changed, scan keeps old rows)
     * is shown, but not as a link that can only fail.
     *
     * @param CourseDocument[] $documents
     * @return list<array<string, mixed>>
     */
    public function projectDocuments(int $courseId, array $documents, bool $forInstructor): array {
        $folder = null;
        try {
            $folder = $this->courseMapper->findById($courseId)->getMaterialFolder();
        } catch (\Throwable $e) {
            // A course that cannot be read serves no file either; the list itself still shows.
            $folder = null;
        }

        $rows = [];
        foreach ($documents as $doc) {
            $row = [
                'id' => $doc->getId(),
                'file_name' => $doc->getFileName(),
                'file_type' => $doc->getFileType(),
                'file_size' => $doc->getFileSize(),
                'created_at' => $doc->getCreatedAt(),
                'updated_at' => $doc->getUpdatedAt(),
                'downloadable' => self::isInMaterialFolder($doc, $folder),
            ];
            if ($forInstructor) {
                $row['course_id'] = $doc->getCourseId();
                $row['file_path'] = $doc->getFilePath();
                $row['status'] = $doc->getStatus();
                $row['error_message'] = $doc->getErrorMessage();
                $row['chunking_status'] = $doc->getChunkingStatus();
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The file behind a document row, for a course member to read (GitHub #35).
     *
     * The bytes live in the course owner's home. The row only names the file: it is resolved as
     * a direct child of the course's CURRENT material folder, by its plain file name, and only
     * when the stored path is the one a scan of that folder would store today. A row from an
     * earlier folder, a name with a path separator or a dot segment, a type the scan does not
     * register, or anything that is not a file is "not found". The caller has checked that the
     * user belongs to the course.
     *
     * @return array{0: CourseDocument, 1: File}
     * @throws NotFoundException
     */
    public function resolveMaterialFile(Course $course, int $documentId): array {
        try {
            $doc = $this->documentMapper->findById($documentId);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException('Document not found');
        }
        // IDOR: the course in the URL is the one the membership was checked for.
        if ($doc->getCourseId() !== $course->getId()) {
            throw new NotFoundException('Document not found');
        }

        $folderPath = $course->getMaterialFolder();
        if (!self::isInMaterialFolder($doc, $folderPath)) {
            throw new NotFoundException('Document is not in the material folder');
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($course->getInstructorId());
            $folder = $userFolder->get((string)$folderPath);
        } catch (NotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // e.g. the course owner no longer exists: there is no folder to serve from
            throw new NotFoundException('Material folder not available');
        }
        if (!($folder instanceof Folder)) {
            throw new NotFoundException('Material path is not a folder');
        }

        $node = $folder->get($doc->getFileName());
        if (!($node instanceof File)) {
            throw new NotFoundException('Not a file');
        }
        if (!self::mayBeDownloaded($node)) {
            throw new NotFoundException('File may not be downloaded');
        }
        return [$doc, $node];
    }

    /**
     * Whether the owner may hand out the bytes of this file (GitHub #35).
     *
     * The file is read through the course owner's view. Nextcloud enforces a share's
     * "download disabled" (view only) attribute in WebDAV and in Files_Sharing's ViewOnly, not
     * in the storage, so reading here would pass it by: the owner could not download such a file
     * themselves, and neither may the course members. Mirrors
     * OCA\Files_Sharing\ViewOnly::checkFileInfo (NC 33) through the OCP interface: a file inside
     * a shared folder lives on that share's storage, so the file's own storage decides.
     */
    private static function mayBeDownloaded(File $file): bool {
        try {
            if (!$file->isReadable()) {
                return false;
            }
            $storage = $file->getStorage();
            if (!$storage->instanceOfStorage(ISharedStorage::class)) {
                return true;
            }
            /** @var ISharedStorage $storage */
            $attributes = $storage->getShare()->getAttributes();
            return $attributes?->getAttribute('permissions', 'download') !== false;
        } catch (\Throwable $e) {
            // Unknown is no permission to hand out somebody else's file.
            return false;
        }
    }

    /**
     * One spelling of a path relative to a user's home: leading slash, no trailing slash, no
     * doubled slashes ('Kurs/', '/Kurs' and 'Kurs//' are all '/Kurs'; the home itself is '/').
     */
    public static function normalizePath(string $path): string {
        $collapsed = preg_replace('#/+#', '/', $path) ?? $path;
        return '/' . trim($collapsed, '/');
    }

    /**
     * The path a scan stores for a file of the material folder (pass the normalized folder).
     * scanFolder() and the download check both use it, so a row is servable exactly when a scan
     * of the current folder would have produced it.
     */
    public static function materialFilePath(string $materialFolder, string $fileName): string {
        return $materialFolder . '/' . $fileName;
    }

    /**
     * Whether the row names a direct child of $folderPath that the scan registers. String checks
     * only, no filesystem access.
     */
    public static function isInMaterialFolder(CourseDocument $doc, ?string $folderPath): bool {
        if ($folderPath === null || $folderPath === '') {
            return false;
        }
        $name = (string)$doc->getFileName();
        if ($name === '' || $name === '.' || $name === '..'
            || strpbrk($name, "/\\\0") !== false) {
            return false;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::FILE_TYPES[$ext])) {
            return false;
        }
        $folder = self::normalizePath($folderPath);
        if ($folder === '/') {
            // the whole home is no material folder (setMaterialFolder refuses it; older rows)
            return false;
        }
        return self::normalizePath((string)$doc->getFilePath()) === self::materialFilePath($folder, $name);
    }

    /**
     * Whether this server can extract text from PDFs (pdftotext reachable through exec()).
     * Overridable for tests; the result of the probe is cached per process.
     */
    public function isPdfTextExtractionAvailable(): bool {
        return self::detectPdfTextExtraction();
    }

    private static ?bool $pdfTextExtraction = null;

    public static function detectPdfTextExtraction(): bool {
        if (self::$pdfTextExtraction !== null) {
            return self::$pdfTextExtraction;
        }
        $available = false;
        if (function_exists('exec')) {
            $out = [];
            $code = 1;
            try {
                @exec('command -v pdftotext 2>/dev/null', $out, $code);
                $available = $code === 0 && $out !== [];
            } catch (\Throwable $e) {
                $available = false;
            }
        }
        self::$pdfTextExtraction = $available;
        return $available;
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
        if (!$this->isPdfTextExtractionAvailable()) {
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
