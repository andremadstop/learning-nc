<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;
use OCP\IDBConnection;
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

    /**
     * GitHub #90: why an entry of the learning materials cannot be opened (projectDocuments(),
     * "unavailable_reason"). The first three mean the file is not in the linked material folder:
     * "Scan folder" and the instructor's × take such an entry out of the list.
     */
    public const REASON_OUTSIDE_FOLDER = 'outside_folder';
    public const REASON_MISSING = 'missing';
    public const REASON_FOLDER_MISSING = 'folder_missing';
    /** The file is in the folder, but its owner may not hand it out (view-only share, unreadable). */
    public const REASON_NO_DOWNLOAD = 'no_download';
    public const STALE_REASONS = [self::REASON_OUTSIDE_FOLDER, self::REASON_MISSING, self::REASON_FOLDER_MISSING];

    public function __construct(
        CourseDocumentMapper $documentMapper,
        CourseMapper $courseMapper,
        IRootFolder $rootFolder,
        LoggerInterface $logger,
        private ?CourseVideoMapper $videoMapper = null,
        private ?AuditService $auditService = null,
        private ?IDBConnection $db = null,
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
        // GitHub #75: also by Nextcloud file id, so a renamed file keeps its row — and with it
        // its required-content link and the read confirmations — instead of becoming a new one.
        /** @var array<string, CourseDocument> $registered */
        $registered = [];
        /** @var array<int, CourseDocument> $byFileId */
        $byFileId = [];
        foreach ($this->documentMapper->findByCourseId($courseId) as $existingDoc) {
            $registered[self::normalizePath((string)$existingDoc->getFilePath())] = $existingDoc;
            if ($existingDoc->getFileId() !== null) {
                $byFileId[(int)$existingDoc->getFileId()] = $existingDoc;
            }
        }
        $normalizedFolder = self::normalizePath($materialFolder);

        /** @var list<array{node: Node, name: string, ext: string, fileId: ?int}> $files */
        $files = [];
        /** @var array<int, true> $listedIds */
        $listedIds = [];
        foreach ($nodes as $node) {
            if ($node->getType() !== Node::TYPE_FILE) {
                continue;
            }
            $name = $node->getName();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(self::FILE_TYPES[$ext])) {
                continue;
            }
            $fileId = self::nodeFileId($node);
            $files[] = ['node' => $node, 'name' => $name, 'ext' => $ext, 'fileId' => $fileId];
            if ($fileId !== null) {
                $listedIds[$fileId] = true;
            }
        }

        foreach ($files as ['node' => $node, 'name' => $name, 'ext' => $ext, 'fileId' => $fileId]) {
            $relativePath = self::materialFilePath($normalizedFolder, $name);
            $known = $registered[$relativePath] ?? null;
            if ($known !== null) {
                $knownId = $known->getFileId();
                // GitHub #75 (review): the row's own file is still in the folder under another
                // name (renamed, and a new file uploaded under the old name). The file id wins, in
                // whatever order Nextcloud lists the files: the row follows its file below, and
                // this path holds another file — a new row. Stamping the new id on the row here
                // made the row swap between the two files on every scan.
                $ownFileElsewhere = $fileId !== null && $knownId !== null && $knownId !== $fileId && isset($listedIds[$knownId]);
                // GitHub #75 (review): the file under this name belongs to another row: "b.pdf"
                // was deleted and "a.pdf" renamed to "b.pdf". That row follows its file below;
                // this one is left like the row of a deleted file (and no longer resolves, see
                // resolveMaterialFile()), instead of taking over the other row's file.
                $fileOfAnotherRow = $fileId !== null && isset($byFileId[$fileId]) && $byFileId[$fileId] !== $known;
                if (!$ownFileElsewhere && !$fileOfAnotherRow) {
                    // Stamp the file id on a row from before 5.14.0 (or after a replaced file).
                    if ($fileId !== null && $knownId !== $fileId && !isset($byFileId[$fileId])) {
                        if ($knownId !== null) {
                            unset($byFileId[$knownId]);
                        }
                        $known->setFileId($fileId);
                        $this->documentMapper->update($known);
                        $byFileId[$fileId] = $known;
                    }
                    continue;
                }
            }
            $renamed = $fileId !== null ? ($byFileId[$fileId] ?? null) : null;
            if ($renamed !== null) {
                // The same file (one file id is one file) under a new name: the row follows it.
                $oldPath = self::normalizePath((string)$renamed->getFilePath());
                if ($oldPath === $relativePath) {
                    $registered[$relativePath] = $renamed;
                    continue; // followed on an earlier scan, a left-behind row shares the path
                }
                if (($registered[$oldPath] ?? null) === $renamed) {
                    unset($registered[$oldPath]);
                }
                $renamed->setFilePath($relativePath);
                $renamed->setFileName($name);
                $renamed->setFileType(self::FILE_TYPES[$ext]);
                $renamed->setUpdatedAt($now);
                $this->documentMapper->update($renamed);
                $registered[$relativePath] = $renamed;
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
            $doc->setFileId($fileId);

            $this->documentMapper->insert($doc);
            $registered[$relativePath] = $doc;
            if ($fileId !== null) {
                $byFileId[$fileId] = $doc;
            }
        }

        // GitHub #90: the list shows what is in the folder now. An entry whose file is gone or no
        // longer in this folder (also after linking another folder) leaves the list; one whose
        // file is back returns, with its confirmations. Rows are only marked, never deleted.
        $listing = [];
        foreach ($nodes as $node) {
            if ($node->getType() === Node::TYPE_FILE) {
                $listing[$node->getName()] = $node;
            }
        }
        $lookup = static fn(string $name): ?Node => $listing[$name] ?? null;
        $rows = $this->documentMapper->findByCourseId($courseId);
        $owners = self::fileIdOwners($rows);
        $remove = [];
        $restore = [];
        foreach ($rows as $row) {
            $stale = in_array(self::reasonFor($row, $materialFolder, $lookup, $owners, false), self::STALE_REASONS, true);
            if ($stale && $row->getRemovedAt() === null) {
                $remove[] = $row;
            } elseif (!$stale && $row->getRemovedAt() !== null) {
                $restore[] = $row;
            }
        }
        $this->setListed($course, $remove, $restore, $userId);

        return $this->documentMapper->findByCourseId($courseId);
    }

    /**
     * GitHub #90: the instructor's × on an entry whose file is not in the material folder (gone,
     * from an earlier folder, or the folder is gone). An entry whose file is still there would
     * come back with the next scan, so it is refused; such a file leaves the list when it leaves
     * the folder. The caller checked that the user teaches the course.
     *
     * @throws DoesNotExistException the document is not one of this course's listed entries
     * @throws \DomainException the file is still in the material folder
     */
    public function removeEntry(Course $course, int $documentId, string $userId): void {
        $doc = $this->documentMapper->findById($documentId);
        if ((int)$doc->getCourseId() !== (int)$course->getId() || $doc->getRemovedAt() !== null) {
            throw new DoesNotExistException('Document not found');
        }
        $reason = $this->availability($course, [$doc], false)[(int)$doc->getId()];
        if (!in_array($reason, self::STALE_REASONS, true)) {
            throw new \DomainException('The file is still in the material folder');
        }
        $this->setListed($course, [$doc], [], $userId);
    }

    /**
     * GitHub #90: take entries out of the list and bring others back, in one transaction. The
     * required-content rows of these documents stay (CourseVideoMapper::findByCourse() leaves them
     * out while their document is out of the list), so whether the exam waits for them changes:
     * that is recorded as course.content.required_changed, with the instructor who scanned or
     * clicked × as actor and the reason. The confirmations are not touched.
     *
     * @param CourseDocument[] $remove
     * @param CourseDocument[] $restore
     */
    private function setListed(Course $course, array $remove, array $restore, string $userId): void {
        if ($remove === [] && $restore === []) {
            return;
        }
        $courseId = (int)$course->getId();
        $required = $this->videoMapper !== null ? $this->videoMapper->findDocumentRowsByCourse($courseId, true) : [];
        $now = time();
        $this->db?->beginTransaction();
        try {
            foreach ($remove as $doc) {
                $doc->setRemovedAt($now);
                $doc->setUpdatedAt($now);
                $this->documentMapper->update($doc);
            }
            foreach ($restore as $doc) {
                $doc->setRemovedAt(null);
                $doc->setUpdatedAt($now);
                $this->documentMapper->update($doc);
            }
            $this->logRequiredChange($courseId, $remove, $required, false, 'document_removed', $userId);
            $this->logRequiredChange($courseId, $restore, $required, true, 'document_back', $userId);
            $this->db?->commit();
        } catch (\Throwable $e) {
            if ($this->db !== null && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        $this->logger->info('DocumentService: {removed} material entries left the list of course {courseId}, {restored} came back', [
            'removed' => count($remove),
            'restored' => count($restore),
            'courseId' => $courseId,
            'app' => 'learning',
        ]);
    }

    /**
     * @param CourseDocument[] $documents
     * @param array<int, CourseVideo> $required document id => required-content row (dormant ones included)
     */
    private function logRequiredChange(int $courseId, array $documents, array $required, bool $nowRequired, string $reason, string $userId): void {
        $rows = [];
        foreach ($documents as $doc) {
            if (isset($required[(int)$doc->getId()])) {
                $rows[(int)$doc->getId()] = $required[(int)$doc->getId()];
            }
        }
        if ($rows === [] || $this->auditService === null) {
            return;
        }
        $this->auditService->logComplianceEvent(ComplianceEventTypes::CONTENT_REQUIRED_CHANGED, $userId, [
            'course_id' => $courseId,
            'required' => $nowRequired,
            'reason' => $reason,
            'document_ids' => array_keys($rows),
            'content_ids' => array_values(array_map(static fn(CourseVideo $v): int => (int)$v->getId(), $rows)),
        ]);
    }

    /**
     * GitHub #75 rule of resolveMaterialFile(): a file belongs to the first row (by id) that carries
     * its file id, in whatever state that row is.
     *
     * @param CourseDocument[] $rows
     * @return array<int, int> file id => document id
     */
    private static function fileIdOwners(array $rows): array {
        $owners = [];
        foreach ($rows as $row) {
            $fileId = $row->getFileId();
            if ($fileId === null) {
                continue;
            }
            if (!isset($owners[(int)$fileId]) || (int)$row->getId() < $owners[(int)$fileId]) {
                $owners[(int)$fileId] = (int)$row->getId();
            }
        }
        return $owners;
    }

    /**
     * GitHub #90: why a row cannot be opened now, null when document#download would serve it. The
     * same decisions as resolveMaterialFile(), against one listing of the folder.
     *
     * @param (callable(string): ?Node)|null $lookup a child of the material folder by file name
     *                                             (null = none), null = the folder is not reachable
     * @param array<int, int> $owners fileIdOwners()
     * @param bool $checkDownload also ask whether the owner may hand the file out (needs a File)
     */
    private static function reasonFor(CourseDocument $doc, ?string $folderPath, ?callable $lookup, array $owners, bool $checkDownload): ?string {
        if (!self::isInMaterialFolder($doc, $folderPath)) {
            return self::REASON_OUTSIDE_FOLDER;
        }
        if ($lookup === null) {
            return self::REASON_FOLDER_MISSING;
        }
        $node = $lookup((string)$doc->getFileName());
        if ($node === null || !($node instanceof File || $node->getType() === Node::TYPE_FILE)) {
            return self::REASON_MISSING;
        }
        $nodeId = self::nodeFileId($node);
        if ($nodeId !== null && $doc->getFileId() !== null && $nodeId !== (int)$doc->getFileId()
            && isset($owners[$nodeId]) && $owners[$nodeId] !== (int)$doc->getId()) {
            // the row's own file is gone, another row's file carries its name now
            return self::REASON_MISSING;
        }
        if ($checkDownload && (!($node instanceof File) || !self::mayBeDownloaded($node))) {
            return self::REASON_NO_DOWNLOAD;
        }
        return null;
    }

    /**
     * GitHub #90: for each row, why it cannot be opened now (null = it can). Resolves the course
     * owner's material folder once and each file by its plain name in it, as resolveMaterialFile()
     * does (a cached lookup per file, no directory listing).
     *
     * @param CourseDocument[] $documents
     * @return array<int, string|null> document id => reason
     */
    public function availability(Course $course, array $documents, bool $checkDownload = true): array {
        if ($documents === []) {
            return [];
        }
        $folderPath = $course->getMaterialFolder();
        $lookup = null;
        if ($folderPath !== null && $folderPath !== '' && self::normalizePath($folderPath) !== '/') {
            try {
                $folder = $this->rootFolder->getUserFolder((string)$course->getInstructorId())->get($folderPath);
                if ($folder instanceof Folder) {
                    $lookup = static function (string $name) use ($folder): ?Node {
                        try {
                            return $folder->get($name);
                        } catch (\Throwable $e) {
                            return null;
                        }
                    };
                }
            } catch (\Throwable $e) {
                // gone, or the course owner no longer exists: nothing can be served from it
                $lookup = null;
            }
        }
        $owners = self::fileIdOwners($this->documentMapper->findByCourseId((int)$course->getId()));
        $reasons = [];
        foreach ($documents as $doc) {
            $reasons[(int)$doc->getId()] = self::reasonFor($doc, $folderPath, $lookup, $owners, $checkDownload);
        }
        return $reasons;
    }

    /** The Nextcloud file id of a node, or null when the storage has none. */
    private static function nodeFileId(Node $node): ?int {
        try {
            $id = $node->getId();
        } catch (\Throwable $e) {
            return null;
        }
        return $id > 0 ? (int)$id : null;
    }

    /**
     * GitHub #75: the version of a material file a read confirmation refers to — sha256 of the
     * Nextcloud checksum when the storage keeps one, else of mtime and size. A rename changes
     * neither (the content and the file stay the same); new content changes the checksum, or
     * the mtime when there is none. Fixed length, whatever algorithms the checksum lists.
     */
    public static function fileVersion(File $file): string {
        $checksum = '';
        try {
            $checksum = trim((string)$file->getChecksum());
        } catch (\Throwable $e) {
            $checksum = '';
        }
        $basis = $checksum !== ''
            ? 'checksum:' . $checksum
            : 'mtime:' . (int)$file->getMTime() . ':size:' . (int)$file->getSize();
        return hash('sha256', $basis);
    }

    /**
     * GitHub #75: version and mtime of the file behind a document row, or null when it cannot be
     * served (not in the material folder, gone, not readable).
     *
     * @return array{version: string, mtime: int}|null
     */
    public function currentFileInfo(Course $course, int $documentId): ?array {
        try {
            [, $file] = $this->resolveMaterialFile($course, $documentId);
            return ['version' => self::fileVersion($file), 'mtime' => (int)$file->getMTime()];
        } catch (\Throwable $e) {
            return null;
        }
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
            if ($doc->getStatus() !== 'uploaded' || $doc->getRemovedAt() !== null) {
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
     * "downloadable" says whether the download endpoint would serve the row, and
     * "unavailable_reason" why not (GitHub #90, availability()): the file is not in the current
     * material folder (gone, from an earlier folder, the folder is gone) or its owner may not hand
     * it out. Instructors see such a row until a scan or their × takes it out of the list;
     * learners only see the ones whose file is still in the folder.
     *
     * @param CourseDocument[] $documents
     * @return list<array<string, mixed>>
     */
    public function projectDocuments(int $courseId, array $documents, bool $forInstructor): array {
        // GitHub #90: entries a scan or the instructor took out of the list are in no list.
        $documents = array_values(array_filter($documents, static fn(CourseDocument $doc): bool => $doc->getRemovedAt() === null));
        try {
            $reasons = $this->availability($this->courseMapper->findById($courseId), $documents);
        } catch (\Throwable $e) {
            // A course that cannot be read serves no file either; the list itself still shows.
            $reasons = array_fill_keys(array_map(static fn(CourseDocument $doc): int => (int)$doc->getId(), $documents), self::REASON_FOLDER_MISSING);
        }

        $rows = [];
        foreach ($documents as $doc) {
            // not `??`: null is the answer "it can be opened"
            $reason = array_key_exists((int)$doc->getId(), $reasons) ? $reasons[(int)$doc->getId()] : self::REASON_MISSING;
            // GitHub #90: learners see what is in the folder; a file that is gone or from an
            // earlier folder is nothing they can act on (the instructor sees it until a scan).
            if (!$forInstructor && in_array($reason, self::STALE_REASONS, true)) {
                continue;
            }
            $row = [
                'id' => $doc->getId(),
                'file_name' => $doc->getFileName(),
                'file_type' => $doc->getFileType(),
                'file_size' => $doc->getFileSize(),
                'created_at' => $doc->getCreatedAt(),
                'updated_at' => $doc->getUpdatedAt(),
                // whether document#download serves it now (it checks the same, GitHub #90: also
                // that the file exists and may be handed out), and if not, why
                'downloadable' => $reason === null,
                'unavailable_reason' => $reason,
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
        // GitHub #90: out of the list until a scan finds its file again
        if ($doc->getRemovedAt() !== null) {
            throw new NotFoundException('Document is not listed');
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
        // GitHub #75 (review): the file under this name belongs to another row of the course (the
        // row's own file was deleted and another one renamed to its name, scanFolder()); this row
        // is left behind like the row of a deleted file. A file id no row carries (a replaced
        // file before the next scan) still resolves by name. Only a row with a file id of its own
        // is left behind: a row without one (from before 5.14.0) is resolved by name as before.
        // Before 5.10.0 a folder typed as 'Kurs' and picked as '/Kurs' gave one file two rows;
        // the scan stamps the id on one of them, and the other one must keep serving the file.
        $nodeId = self::nodeFileId($node);
        if ($nodeId !== null && $doc->getFileId() !== null && $nodeId !== $doc->getFileId()) {
            $owner = $this->documentMapper->findByCourseAndFileId((int)$course->getId(), $nodeId);
            if ($owner !== null && $owner->getId() !== $doc->getId()) {
                throw new NotFoundException('The file belongs to another document');
            }
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
     *
     * Also used by CertLayoutService: a certificate stamp or signature is handed out the same way.
     */
    public static function mayBeDownloaded(File $file): bool {
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

        // stderr goes to its own file: pdftotext prints "Syntax Warning/Error (...)" there for many
        // imperfect PDFs and still exits 0, and with 2>&1 those lines became part of the extracted
        // text, the RAG chunks and the tutor's answers (2026-11 audit #72).
        $errFile = tempnam(sys_get_temp_dir(), 'learning_pdf_err_');
        if ($errFile === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Failed to create temporary file for PDF extraction');
        }

        try {
            file_put_contents($tmpFile, $pdfContent);

            $output = [];
            $returnCode = 0;
            exec('pdftotext ' . escapeshellarg($tmpFile) . ' - 2>' . escapeshellarg($errFile), $output, $returnCode);

            if ($returnCode !== 0) {
                $stderr = trim((string)@file_get_contents($errFile));
                throw new \RuntimeException('pdftotext failed with code ' . $returnCode . ': ' . mb_substr($stderr, 0, 1000));
            }

            $text = implode("\n", $output);

            if (trim($text) === '') {
                throw new \RuntimeException('pdftotext returned empty text (PDF may be image-only)');
            }

            return $text;
        } finally {
            @unlink($tmpFile);
            @unlink($errFile);
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
