<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Controller\CourseVideoController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\DocConfirmMapper;
use OCA\Learning\Db\DocOpenMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * GitHub #75: "Confirm I have read this" for the required documents of a course.
 *
 * ONE validity rule (evaluate()) feeds the exam gate (VideoProgressService), the learner's
 * status list (VideoProgressController::courseStatus) and the coverage report
 * (CertificateReportService), so the report can never show 5/5 while the exam stays locked.
 *
 * A confirmation counts when its newest row
 *   - is not older than validFrom(): the start of the current certification cycle (in a course
 *     with reconfirm_per_cycle, see PassCriteriaService::confirmationCycleStart(); in any course
 *     after a revoke, from the new cycle the instructor opened, GitHub #84) and
 *     the instructor's last "Ask everyone to confirm again" (course_documents.confirm_after);
 *   - names the version of the file that is in the material folder now. A rename keeps the
 *     version (DocumentService::fileVersion()). A confirmation taken over from before 5.14.0
 *     has no version; in a course with reconfirm_per_cycle it counts while the file has not
 *     been modified after it (mtime), in any other course it keeps counting as before the
 *     upgrade (a touched mtime from a sync client must not lock an IT course's exam again).
 *     When the file cannot be read right now, the version is not checked — the learner could
 *     not confirm anything else either. Only the migration writes a row of a document without a
 *     version: confirm() refuses a file it cannot read (DocumentUnavailableException) and
 *     DocConfirmMapper::insert() a document confirmation without version (GitHub #75, review),
 *     so such a row is always one taken over from before 5.14.0.
 *
 * Rows are append-only (learning_doc_confirms): a new cycle or a new version asks for a new
 * row; the older ones and their course.video.completed events stay as evidence. They belong to
 * the document of the course, not to the registry row (GitHub #75): a file made optional and required
 * again gets its earlier confirmations back, measured by the same rule — and so does the
 * instructor's "Ask everyone to confirm again", which is stored on the document as well. Confirming
 * while a valid confirmation exists is a no-op (no second row, no second audit event).
 * Videos are not handled here: they keep their watch state in learning_video_progress.
 *
 * GitHub #86: in a course with open_before_confirm (mandatory-training profile) confirm() first
 * asks for an open of the current file version within the current validFrom() — recorded by
 * document#download (recordOpen(), learning_doc_opens). The open is a precondition of a NEW
 * confirmation only: evaluate() does not look at it, so every confirmation that counts today
 * keeps counting, and switching the option on locks no exam that is open now.
 */
class RequiredDocumentService {
    public function __construct(
        private DocConfirmMapper $confirmMapper,
        private CourseVideoMapper $videoMapper,
        private CourseMapper $courseMapper,
        private DocumentService $documentService,
        private PassCriteriaService $passCriteriaService,
        private AuditService $auditService,
        private ITimeFactory $timeFactory,
        private IDBConnection $db,
        private CourseDocumentMapper $documentMapper,
        private ?DocOpenMapper $openMapper = null,
    ) {
    }

    public static function isDocument(CourseVideo $video): bool {
        return $video->getSourceType() === CourseVideoController::DOCUMENT_SOURCE_TYPE;
    }

    /**
     * The validity rule (pure).
     *
     * @param array{confirmed_at: int, doc_version: string|null}|null $latest newest confirmation
     * @param int $validFrom confirmations before this no longer count
     * @param array{version: string, mtime: int}|null $file the file now, null = unknown
     * @param bool $legacyByMtime a confirmation without version (from before 5.14.0) is checked
     *                            against the file's mtime; false = it counts as it did before
     * @return array{confirmed: bool, updated: bool, again: bool, confirmed_at: int|null}
     *         updated: confirmed for an older version of the file ("Updated since you confirmed it");
     *         again: confirmed before, but in an earlier cycle or before the instructor asked again
     */
    public static function evaluate(?array $latest, int $validFrom, ?array $file, bool $legacyByMtime = true): array {
        if ($latest === null) {
            return ['confirmed' => false, 'updated' => false, 'again' => false, 'confirmed_at' => null];
        }
        $at = (int)$latest['confirmed_at'];
        if ($at < $validFrom) {
            return ['confirmed' => false, 'updated' => false, 'again' => true, 'confirmed_at' => $at];
        }
        $versionOk = true;
        if ($file !== null) {
            if ($latest['doc_version'] !== null) {
                $versionOk = hash_equals($file['version'], (string)$latest['doc_version']);
            } elseif ($legacyByMtime) {
                $versionOk = (int)$file['mtime'] <= $at;
            }
        }
        return ['confirmed' => $versionOk, 'updated' => !$versionOk, 'again' => false, 'confirmed_at' => $at];
    }

    /**
     * GitHub #75 (review): whether a confirmation from before 5.14.0 (no version) is checked
     * against the file's mtime. Only in a course that asks per certification cycle; elsewhere
     * an upgrade leaves the confirmations taken over as valid as they were.
     */
    public static function legacyByMtime(Course $course): bool {
        return (bool)($course->getReconfirmPerCycle() ?? false);
    }

    /**
     * Confirmations before this moment do not count (see the class comment): the later of the
     * cycle start and the document's "Ask everyone to confirm again".
     */
    public static function validFrom(?int $confirmAfter, ?int $cycleStart): int {
        return max($cycleStart ?? 0, $confirmAfter ?? 0);
    }

    /** The course_documents id of a document row; null for a legacy path row. */
    public static function documentIdOf(CourseVideo $video): ?int {
        $id = $video->getDocumentId() ?? CourseVideoController::documentIdFromRef($video->getVideoRef());
        return $id !== null ? (int)$id : null;
    }

    /**
     * @param CourseVideo[] $documents
     * @return array<int, int|null> content id => document id
     */
    private static function documentMap(array $documents): array {
        $map = [];
        foreach ($documents as $video) {
            $map[(int)$video->getId()] = self::documentIdOf($video);
        }
        return $map;
    }

    /**
     * "Ask everyone to confirm again" of each document row, by content id (rows without are left out).
     *
     * @param array<int, int|null> $documentOf content id => document id
     * @return array<int, int>
     */
    private function confirmAfterOf(int $courseId, array $documentOf): array {
        $byDocument = $this->documentMapper->confirmAfterByIds($courseId, array_values(array_filter($documentOf, static fn(?int $d): bool => $d !== null)));
        $out = [];
        foreach ($documentOf as $contentId => $documentId) {
            if ($documentId !== null && isset($byDocument[$documentId])) {
                $out[$contentId] = $byDocument[$documentId];
            }
        }
        return $out;
    }

    /** Start of the user's current cycle (see cycleStarts()), else null. */
    public function cycleStart(Course $course, string $userId): ?int {
        return $this->cycleStarts($course, [$userId])[$userId] ?? null;
    }

    /**
     * The cycle start of each user (batched: the coverage report reads all learners at once).
     * In a course that does not ask per cycle only learners in a cycle opened after a revoke
     * have one (GitHub #84).
     *
     * @param list<string> $userIds
     * @return array<string, int|null>
     */
    public function cycleStarts(Course $course, array $userIds): array {
        if ($userIds === []) {
            return [];
        }
        $starts = ($course->getReconfirmPerCycle() ?? false)
            ? $this->passCriteriaService->confirmationCycleStarts($course, $userIds)
            : [];
        if (!($course->getCertEnabled() ?? false)) {
            return $starts; // no certificate, no revoke
        }
        // GitHub #84: after a revoke the documents are confirmed again in every course, from the
        // start of the cycle the instructor opened (PassCriteriaService::revokeCycleStarts()).
        foreach ($this->passCriteriaService->revokeCycleStarts((int)$course->getId(), $userIds) as $uid => $since) {
            $starts[$uid] = max($starts[$uid] ?? 0, $since);
        }
        return $starts;
    }

    /**
     * The current file of a document row, null when unknown.
     *
     * @return array{version: string, mtime: int}|null
     */
    public function fileInfo(Course $course, CourseVideo $video): ?array {
        $documentId = self::documentIdOf($video);
        if ($documentId === null) {
            return null;
        }
        return $this->documentService->currentFileInfo($course, $documentId);
    }

    /**
     * The state of every document row of $videos for one user.
     *
     * @param CourseVideo[] $videos
     * @return array<int, array{confirmed: bool, updated: bool, again: bool, confirmed_at: int|null}> content id => state
     */
    public function statesForUser(Course $course, array $videos, string $userId): array {
        $documents = array_values(array_filter($videos, [self::class, 'isDocument']));
        if ($documents === []) {
            return [];
        }
        $cycleStart = $this->cycleStart($course, $userId);
        $documentOf = self::documentMap($documents);
        $latest = $this->confirmMapper->latestByUsers((int)$course->getId(), [$userId], $documentOf)[$userId] ?? [];
        $confirmAfter = $this->confirmAfterOf((int)$course->getId(), $documentOf);
        $states = [];
        foreach ($documents as $video) {
            $id = (int)$video->getId();
            $states[$id] = self::evaluate($latest[$id] ?? null, self::validFrom($confirmAfter[$id] ?? null, $cycleStart), $this->fileInfo($course, $video), self::legacyByMtime($course));
        }
        return $states;
    }

    /**
     * How many document rows each user has validly confirmed (coverage report). Each file is
     * read once, not once per learner.
     *
     * @param CourseVideo[] $videos
     * @param list<string> $userIds
     * @return array<string, int>
     */
    public function countConfirmedByUsers(Course $course, array $videos, array $userIds): array {
        $documents = array_values(array_filter($videos, [self::class, 'isDocument']));
        if ($documents === [] || $userIds === []) {
            return [];
        }
        $files = [];
        foreach ($documents as $video) {
            $files[(int)$video->getId()] = $this->fileInfo($course, $video);
        }
        $documentOf = self::documentMap($documents);
        $latest = $this->confirmMapper->latestByUsers((int)$course->getId(), $userIds, $documentOf);
        $confirmAfter = $this->confirmAfterOf((int)$course->getId(), $documentOf);
        $cycleStarts = $this->cycleStarts($course, $userIds);
        $counts = [];
        foreach ($userIds as $uid) {
            $cycleStart = $cycleStarts[$uid] ?? null;
            foreach ($documents as $video) {
                $id = (int)$video->getId();
                $state = self::evaluate($latest[$uid][$id] ?? null, self::validFrom($confirmAfter[$id] ?? null, $cycleStart), $files[$id], self::legacyByMtime($course));
                if ($state['confirmed']) {
                    $counts[$uid] = ($counts[$uid] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }

    /** Whether the user's confirmation of this document row counts now (exam gate). */
    public function isConfirmed(Course $course, CourseVideo $video, string $userId): bool {
        return ($this->statesForUser($course, [$video], $userId)[(int)$video->getId()]['confirmed'] ?? false) === true;
    }

    /**
     * "Confirm I have read this". Appends a confirmation of the current file version and writes
     * course.video.completed (with the version) into the compliance chain, both or neither.
     * A no-op while a valid confirmation exists.
     *
     * @return array{confirmed: bool, updated: bool, again: bool, confirmed_at: int|null}
     * @throws DocumentUnavailableException the file of a document row cannot be read right now
     * @throws DocumentNotOpenedException the course asks for an open first (GitHub #86) and there
     *         is none of this version in the current cycle; an existing valid confirmation stays a no-op
     */
    public function confirm(CourseVideo $video, string $userId): array {
        $course = $this->courseMapper->findById((int)$video->getCourseId());
        $id = (int)$video->getId();
        $state = $this->statesForUser($course, [$video], $userId)[$id];
        if ($state['confirmed']) {
            return $state;
        }
        $file = $this->fileInfo($course, $video);
        $documentId = self::documentIdOf($video);
        // GitHub #75 (review): only a file that can be read now is confirmed, with its version.
        // A row without version would count like one taken over from before 5.14.0, which is
        // never checked against later changes in a course without reconfirm_per_cycle.
        if ($documentId !== null && $file === null) {
            throw new DocumentUnavailableException('The required document cannot be read right now');
        }
        // GitHub #86: opened before confirmed (on the server, so also through the API).
        if (self::openRequired($course) && !$this->openedFor($course, $video, $userId, $file)) {
            throw new DocumentNotOpenedException('The required document has not been opened yet');
        }
        $now = $this->timeFactory->getTime();
        $context = ['course_id' => (int)$course->getId(), 'content_id' => $id];
        if ($documentId !== null) {
            $context['document_id'] = $documentId;
        }
        if ($file !== null) {
            $context['document_version'] = $file['version'];
        }
        $this->db->beginTransaction();
        try {
            $this->confirmMapper->insert($userId, $id, (int)$course->getId(), $now, $file['version'] ?? null, $documentId);
            $this->auditService->logComplianceEvent(ComplianceEventTypes::VIDEO_COMPLETED, $userId, $context);
            $this->db->commit();
        } catch (\OCP\DB\Exception $e) {
            $this->db->rollBack();
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // A second click within the same second: the first one recorded it.
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->statesForUser($course, [$video], $userId)[$id];
    }

    /** GitHub #86: whether the course confirms a required document only after it was opened. */
    public static function openRequired(Course $course): bool {
        return (bool)($course->getOpenBeforeConfirm() ?? false);
    }

    /**
     * GitHub #86 (pure): whether the last open counts for a confirmation now — the file version
     * that is in the material folder now, opened not before validFrom().
     *
     * @param array{doc_version: string, opened_at: int}|null $open
     * @param array{version: string, mtime: int}|null $file the file now, null = unknown
     */
    public static function openCounts(?array $open, int $validFrom, ?array $file): bool {
        if ($open === null || $file === null) {
            return false;
        }
        return $open['opened_at'] >= $validFrom && hash_equals($file['version'], $open['doc_version']);
    }

    /**
     * GitHub #86: whether the user opened this document row so that a confirmation is accepted.
     * A legacy path row (no document id) has no download and so can never be opened.
     *
     * @param array{version: string, mtime: int}|null $file the current file (fileInfo())
     */
    private function openedFor(Course $course, CourseVideo $video, string $userId, ?array $file): bool {
        $documentId = self::documentIdOf($video);
        if ($documentId === null || $this->openMapper === null) {
            return false;
        }
        $open = $this->openMapper->lastOpens((int)$course->getId(), $userId, [$documentId])[$documentId] ?? null;
        $confirmAfter = $this->confirmAfterOf((int)$course->getId(), [(int)$video->getId() => $documentId]);
        $validFrom = self::validFrom($confirmAfter[(int)$video->getId()] ?? null, $this->cycleStart($course, $userId));
        return self::openCounts($open, $validFrom, $file);
    }

    /**
     * GitHub #86: per document row of $videos, whether the user opened it so that "Confirm I have
     * read this" is accepted (learner's status list). Empty in a course without open_before_confirm.
     *
     * @param CourseVideo[] $videos
     * @return array<int, bool> content id => opened
     */
    public function openedForUser(Course $course, array $videos, string $userId): array {
        $documents = array_values(array_filter($videos, [self::class, 'isDocument']));
        if ($documents === [] || !self::openRequired($course)) {
            return [];
        }
        $documentOf = self::documentMap($documents);
        $opens = $this->openMapper !== null
            ? $this->openMapper->lastOpens((int)$course->getId(), $userId, array_values(array_filter($documentOf, static fn(?int $d): bool => $d !== null)))
            : [];
        $confirmAfter = $this->confirmAfterOf((int)$course->getId(), $documentOf);
        $cycleStart = $this->cycleStart($course, $userId);
        $opened = [];
        foreach ($documents as $video) {
            $id = (int)$video->getId();
            $documentId = $documentOf[$id];
            $opened[$id] = $documentId !== null && self::openCounts(
                $opens[$documentId] ?? null,
                self::validFrom($confirmAfter[$id] ?? null, $cycleStart),
                $this->fileInfo($course, $video)
            );
        }
        return $opened;
    }

    /**
     * GitHub #86: document#download served the file of $documentId to $userId. Stored only when
     * it matters: a course with open_before_confirm, a document that is required in it, and a
     * learner (the caller leaves out the instructors of the course, as VideoProgressController
     * does for the confirmation). Not in the compliance chain.
     */
    public function recordOpen(Course $course, int $documentId, string $docVersion, string $userId): bool {
        if ($this->openMapper === null || !self::openRequired($course)) {
            return false;
        }
        if (!isset($this->videoMapper->findDocumentRowsByCourse((int)$course->getId())[$documentId])) {
            return false; // an optional file: nothing to confirm, nothing to store
        }
        $this->openMapper->record($userId, (int)$course->getId(), $documentId, $docVersion, $this->timeFactory->getTime());
        return true;
    }

    /**
     * The "Required" column (instructor, checked by the caller): adds the given new registry rows
     * and removes the given ones, and records what actually changed as
     * course.content.required_changed in the compliance chain with the instructor as actor —
     * all or nothing. A removed row takes its confirmations out of the count until the document
     * is ticked again (they belong to the document and then count again, see the class
     * comment), so who did it must be on record.
     * A row added concurrently by another request (unique violation) is skipped inside a
     * savepoint, so the outer transaction stays usable on PostgreSQL.
     *
     * @param CourseVideo[] $add new document rows (no id yet)
     * @param CourseVideo[] $remove existing document rows
     * @return list<CourseVideo> the rows this request actually removed or added (inserted rows with their id)
     */
    public function changeRequired(int $courseId, array $add, array $remove, string $instructorId): array {
        $this->db->beginTransaction();
        try {
            $changed = [];
            foreach ($remove as $video) {
                $this->videoMapper->delete($video);
                $changed[] = $video;
            }
            foreach ($add as $video) {
                $this->db->beginTransaction();
                try {
                    $changed[] = $this->videoMapper->insert($video);
                    $this->db->commit();
                } catch (\OCP\DB\Exception $e) {
                    $this->db->rollBack();
                    if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                        throw $e;
                    }
                    // added by a concurrent request: required, as asked; not this request's change
                }
            }
            if ($changed !== []) {
                $this->auditService->logComplianceEvent(ComplianceEventTypes::CONTENT_REQUIRED_CHANGED, $instructorId, [
                    'course_id' => $courseId,
                    'required' => $add !== [],
                    // a row from before 5.14 may carry the document only in video_ref (fileInfo())
                    'document_ids' => array_map(
                        static fn(CourseVideo $v): int => (int)($v->getDocumentId() ?? CourseVideoController::documentIdFromRef($v->getVideoRef())),
                        $changed
                    ),
                    'content_ids' => array_map(static fn(CourseVideo $v): int => (int)$v->getId(), $changed),
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $changed;
    }

    /**
     * "Ask everyone to confirm again" (instructor, checked by the caller): confirmations from
     * before now no longer count for this document. Stored on the document (course_documents),
     * so it survives making the file optional and required again (GitHub #75). Recorded in the
     * compliance chain with the instructor as actor.
     *
     * @return array<string, mixed> the registry row with the new confirm_after
     * @throws \InvalidArgumentException a legacy path row has no document to store it on
     */
    public function requestReconfirm(CourseVideo $video, string $instructorId): array {
        $documentId = self::documentIdOf($video);
        if ($documentId === null) {
            throw new \InvalidArgumentException('The required document has no document id');
        }
        $courseId = (int)$video->getCourseId();
        $now = $this->timeFactory->getTime();
        $this->db->beginTransaction();
        try {
            if ($this->documentMapper->setConfirmAfter($courseId, $documentId, $now) === 0) {
                throw new \InvalidArgumentException('The required document is not in this course');
            }
            $this->auditService->logComplianceEvent(ComplianceEventTypes::CONTENT_RECONFIRM_REQUESTED, $instructorId, [
                'course_id' => $courseId,
                'content_id' => (int)$video->getId(),
                'document_id' => $documentId,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $video->jsonSerialize() + ['confirm_after' => $now];
    }
}
