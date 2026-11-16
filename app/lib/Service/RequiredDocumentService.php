<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Controller\CourseVideoController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\DocConfirmMapper;
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
 *   - is not older than validFrom(): the start of the current certification cycle (only in a
 *     course with reconfirm_per_cycle, see PassCriteriaService::confirmationCycleStart()) and
 *     the instructor's last "Ask everyone to confirm again" (course_videos.confirm_after);
 *   - names the version of the file that is in the material folder now. A rename keeps the
 *     version (DocumentService::fileVersion()). A confirmation taken over from before 5.14.0
 *     has no version; it counts while the file has not been modified after it (mtime).
 *     When the file cannot be read right now, the version is not checked — the learner could
 *     not confirm anything else either.
 *
 * Rows are append-only (learning_doc_confirms): a new cycle or a new version asks for a new
 * row; the older ones and their course.video.completed events stay as evidence. Confirming
 * while a valid confirmation exists is a no-op (no second row, no second audit event).
 * Videos are not handled here: they keep their watch state in learning_video_progress.
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
     * @return array{confirmed: bool, updated: bool, again: bool, confirmed_at: int|null}
     *         updated: confirmed for an older version of the file ("Updated since you confirmed it");
     *         again: confirmed before, but in an earlier cycle or before the instructor asked again
     */
    public static function evaluate(?array $latest, int $validFrom, ?array $file): array {
        if ($latest === null) {
            return ['confirmed' => false, 'updated' => false, 'again' => false, 'confirmed_at' => null];
        }
        $at = (int)$latest['confirmed_at'];
        if ($at < $validFrom) {
            return ['confirmed' => false, 'updated' => false, 'again' => true, 'confirmed_at' => $at];
        }
        $versionOk = true;
        if ($file !== null) {
            $versionOk = $latest['doc_version'] !== null
                ? hash_equals($file['version'], (string)$latest['doc_version'])
                : (int)$file['mtime'] <= $at;
        }
        return ['confirmed' => $versionOk, 'updated' => !$versionOk, 'again' => false, 'confirmed_at' => $at];
    }

    /** Confirmations before this moment do not count for $video (see the class comment). */
    public static function validFrom(CourseVideo $video, ?int $cycleStart): int {
        return max($cycleStart ?? 0, (int)($video->getConfirmAfter() ?? 0));
    }

    /** Start of the user's current cycle in a course that asks per cycle, else null. */
    public function cycleStart(Course $course, string $userId): ?int {
        return $this->cycleStarts($course, [$userId])[$userId] ?? null;
    }

    /**
     * The cycle start of each user (batched: the coverage report reads all learners at once).
     * Empty in a course that does not ask per cycle.
     *
     * @param list<string> $userIds
     * @return array<string, int|null>
     */
    public function cycleStarts(Course $course, array $userIds): array {
        if (!($course->getReconfirmPerCycle() ?? false) || $userIds === []) {
            return [];
        }
        return $this->passCriteriaService->confirmationCycleStarts($course, $userIds);
    }

    /**
     * The current file of a document row, null when unknown.
     *
     * @return array{version: string, mtime: int}|null
     */
    public function fileInfo(Course $course, CourseVideo $video): ?array {
        $documentId = $video->getDocumentId() ?? CourseVideoController::documentIdFromRef($video->getVideoRef());
        if ($documentId === null) {
            return null;
        }
        return $this->documentService->currentFileInfo($course, (int)$documentId);
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
        $ids = array_map(static fn(CourseVideo $v): int => (int)$v->getId(), $documents);
        $latest = $this->confirmMapper->latestByUsers([$userId], $ids)[$userId] ?? [];
        $states = [];
        foreach ($documents as $video) {
            $id = (int)$video->getId();
            $states[$id] = self::evaluate($latest[$id] ?? null, self::validFrom($video, $cycleStart), $this->fileInfo($course, $video));
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
        $latest = $this->confirmMapper->latestByUsers($userIds, array_keys($files));
        $cycleStarts = $this->cycleStarts($course, $userIds);
        $counts = [];
        foreach ($userIds as $uid) {
            $cycleStart = $cycleStarts[$uid] ?? null;
            foreach ($documents as $video) {
                $id = (int)$video->getId();
                $state = self::evaluate($latest[$uid][$id] ?? null, self::validFrom($video, $cycleStart), $files[$id]);
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
     */
    public function confirm(CourseVideo $video, string $userId): array {
        $course = $this->courseMapper->findById((int)$video->getCourseId());
        $id = (int)$video->getId();
        $state = $this->statesForUser($course, [$video], $userId)[$id];
        if ($state['confirmed']) {
            return $state;
        }
        $file = $this->fileInfo($course, $video);
        $now = $this->timeFactory->getTime();
        $context = ['course_id' => (int)$course->getId(), 'content_id' => $id];
        if ($file !== null) {
            $context['document_version'] = $file['version'];
        }
        $this->db->beginTransaction();
        try {
            $this->confirmMapper->insert($userId, $id, (int)$course->getId(), $now, $file['version'] ?? null);
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

    /**
     * "Ask everyone to confirm again" (instructor, checked by the caller): confirmations from
     * before now no longer count for this document. Recorded in the compliance chain with the
     * instructor as actor.
     */
    public function requestReconfirm(CourseVideo $video, string $instructorId): CourseVideo {
        $now = $this->timeFactory->getTime();
        $this->db->beginTransaction();
        try {
            $video->setConfirmAfter($now);
            $video = $this->videoMapper->update($video);
            $this->auditService->logComplianceEvent(ComplianceEventTypes::CONTENT_RECONFIRM_REQUESTED, $instructorId, [
                'course_id' => (int)$video->getCourseId(),
                'content_id' => (int)$video->getId(),
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $video;
    }
}
