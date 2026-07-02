<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgress;
use OCA\Learning\Db\VideoProgressMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * VideoProgressService — the pure server-side watch-completion engine (Phase 162, VIDEO-02/06/07).
 *
 * SECURITY MODEL: completion is decided ONLY by server-merged watch intervals reaching 95% of the
 * registry duration. Client "I finished" flags are never trusted. Heartbeats less than 5s apart are
 * silently discarded (a scripted flood cannot advance coverage). At completion the transient segments
 * (intervals_json, covered_pct, last_ping_ts) are cleared — only (user_id, content_id, completed_at)
 * persist long-term (DSGVO Art.5 data minimisation). The course.video.completed compliance event
 * carries ONLY {course_id, content_id} and is emitted EXACTLY once.
 *
 * CONCURRENCY: NC's IQueryBuilder has NO forUpdate() (see Version009300 / AuditService). One user's
 * two concurrent tabs are therefore serialized via CAS on last_ping_ts — an
 * UPDATE ... WHERE last_ping_ts <=> :expected that affects 0 rows when a sibling tab raced ahead,
 * triggering a re-read+retry (mirrors AuditService's last_seq CAS). The first-ping INSERT race
 * (no row to lock yet) is caught as a unique-constraint violation and retried. The completion emit
 * is guarded by an atomic `... WHERE completed_at IS NULL` UPDATE so it fires exactly once.
 */
class VideoProgressService {
    private const THRESHOLD = 0.95;
    private const MIN_PING_GAP_SECONDS = 5;
    private const MAX_RETRIES = 3;
    private const VIDEO_SOURCE_TYPES = ['nc-files', 'vimeo', 'youtube-nocookie'];
    // Anti-fraud rate cap (Codex BLOCKER): a single heartbeat may credit at most the real wall-clock
    // elapsed since the last accepted ping, plus a small tolerance for jitter/ping-batching. The very
    // first ping has no baseline, so it may only seed a small window (and can never complete alone).
    private const CREDIT_TOLERANCE_SECONDS = 1;
    private const FIRST_PING_CREDIT_CAP_SECONDS = 15;

    public function __construct(
        private VideoProgressMapper $progressMapper,
        private CourseVideoMapper $videoMapper,
        private ITimeFactory $timeFactory,
        private AuditService $auditService,
        private IDBConnection $db,
    ) {
    }

    // ─── Pure decision methods (unit-tested, no DB) ──────────────────────────

    /**
     * Merge a new [start, end] interval into a stored set: normalize, sort, and coalesce
     * overlapping OR touching intervals. A seek gap that leaves a hole is preserved.
     *
     * @param array<int, array{0: int|float, 1: int|float}> $stored
     * @param array{0: int|float, 1: int|float}             $newInterval
     * @return array<int, array{0: int|float, 1: int|float}>
     */
    public function mergeIntervals(array $stored, array $newInterval): array {
        $all = $stored;
        $all[] = $newInterval;

        $norm = [];
        foreach ($all as $iv) {
            if (!is_array($iv) || !isset($iv[0], $iv[1])) {
                continue;
            }
            $s = (float)$iv[0];
            $e = (float)$iv[1];
            if ($e < $s) {
                [$s, $e] = [$e, $s];
            }
            if ($e <= $s) {
                continue; // drop zero-length / invalid
            }
            $norm[] = [$s, $e];
        }

        usort($norm, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($norm as $iv) {
            $count = count($merged);
            if ($count === 0) {
                $merged[] = $iv;
                continue;
            }
            if ($iv[0] <= $merged[$count - 1][1]) { // overlap or touch
                if ($iv[1] > $merged[$count - 1][1]) {
                    $merged[$count - 1][1] = $iv[1];
                }
            } else {
                $merged[] = $iv;
            }
        }

        return $merged;
    }

    /**
     * Fraction of the video duration genuinely covered by the merged intervals.
     * Returns null when duration is null/<=0 (uncomputable — a video setup error).
     *
     * @param array<int, array{0: int|float, 1: int|float}> $intervals
     */
    public function coveredPct(array $intervals, ?int $durationSeconds): ?float {
        if ($durationSeconds === null || $durationSeconds <= 0) {
            return null;
        }
        $covered = 0.0;
        foreach ($intervals as $iv) {
            if (!isset($iv[0], $iv[1])) {
                continue;
            }
            $covered += max(0.0, (float)$iv[1] - (float)$iv[0]);
        }
        return $covered / $durationSeconds;
    }

    /**
     * The 95% completion decision. Null coverage (uncomputable) is never complete.
     */
    public function decideComplete(?float $coveredPct): bool {
        return $coveredPct !== null && $coveredPct >= self::THRESHOLD;
    }

    /**
     * A ping is plausible if it is the first one (no prior ping) or at least 5s after the last.
     * M1: last_ping_ts null (no prior ping) is ALWAYS plausible.
     */
    public function isPlausiblePing(?int $lastPingTs, int $nowTs): bool {
        if ($lastPingTs === null) {
            return true;
        }
        return ($nowTs - $lastPingTs) >= self::MIN_PING_GAP_SECONDS;
    }

    /**
     * The transient-cleanup state written at completion (VIDEO-06 / DSGVO Art.5): only completed_at
     * survives; intervals_json, covered_pct and last_ping_ts are nulled.
     *
     * @return array{completed_at: int, intervals_json: null, covered_pct: null, last_ping_ts: null}
     */
    public function computeCompletionState(int $now): array {
        return [
            'completed_at' => $now,
            'intervals_json' => null,
            'covered_pct' => null,
            'last_ping_ts' => null,
        ];
    }

    /**
     * W1 duration integrity: a VIDEO source (nc-files/vimeo/youtube-nocookie) with null/0 duration
     * is a misconfigured gate. A document source is EXEMPT — documents legitimately have no duration.
     */
    public function isDurationValidForGate(string $sourceType, ?int $durationSeconds): bool {
        if (!in_array($sourceType, self::VIDEO_SOURCE_TYPES, true)) {
            return true; // documents (and any non-timed source) are exempt
        }
        return $durationSeconds !== null && $durationSeconds > 0;
    }

    /**
     * Pure heartbeat state transition — the decision core recordHeartbeat runs inside its CAS loop.
     * Returns null when the ping is implausible (<5s since last) → caller discards without touching
     * stored state. Otherwise returns the new persisted state plus the completion decision.
     *
     * @param array<int, array{0: int|float, 1: int|float}> $storedIntervals
     * @param array{0: int|float, 1: int|float}             $newInterval
     * @return array{intervals: array<int, array{0: int|float, 1: int|float}>, intervals_json: string, covered_pct: float|null, last_ping_ts: int, complete: bool}|null
     */
    public function computeHeartbeatTransition(
        array $storedIntervals,
        ?int $lastPingTs,
        array $newInterval,
        int $nowTs,
        ?int $durationSeconds
    ): ?array {
        if (!$this->isPlausiblePing($lastPingTs, $nowTs)) {
            return null;
        }
        // ANTI-FRAUD (Codex BLOCKER): the naive path let one ping `[0, 999999999]` complete any video.
        // Two caps on the newly-reported interval defeat that:
        //  (a) clamp to [0, duration] — cannot claim watch time beyond the video (coveredPct <= 1.0);
        //  (b) cap interval LENGTH to real wall-clock elapsed since the last accepted ping (+ tolerance).
        //      Because pings are >=5s apart (isPlausiblePing), total credited coverage can never outrun
        //      real elapsed time — a 600s video needs ~600s of genuine presence.
        // DELIBERATE: this enforces wall-clock ~= video duration; faster-than-1x playback is intentionally
        // UNDER-credited (no speed-skip past mandatory training). Do NOT "optimise" this away.
        // The FIRST ping (lastPingTs === null) has no elapsed baseline: it may seed only a small window
        // and can NEVER complete on its own — completion always needs >=2 pings spanning real time.
        $isFirstPing = $lastPingTs === null;
        $maxCredit = ($isFirstPing ? self::FIRST_PING_CREDIT_CAP_SECONDS : max(0, $nowTs - $lastPingTs))
            + self::CREDIT_TOLERANCE_SECONDS;
        $capped = $this->capInterval($newInterval, $durationSeconds, (float)$maxCredit);

        $merged = $capped === null ? $storedIntervals : $this->mergeIntervals($storedIntervals, $capped);
        $pct = $this->coveredPct($merged, $durationSeconds);
        return [
            'intervals' => $merged,
            'intervals_json' => json_encode($merged, JSON_THROW_ON_ERROR),
            'covered_pct' => $pct,
            'last_ping_ts' => $nowTs,
            'complete' => !$isFirstPing && $this->decideComplete($pct),
        ];
    }

    /**
     * Clamp a client [start,end] interval to [0,duration] and cap its length to $maxCreditSeconds
     * (the anti-fraud rate limit). Returns null when nothing plausible remains to credit.
     *
     * @param array{0?: int|float, 1?: int|float} $interval
     * @return array{0: float, 1: float}|null
     */
    private function capInterval(array $interval, ?int $durationSeconds, float $maxCreditSeconds): ?array {
        if (!isset($interval[0], $interval[1]) || !is_numeric($interval[0]) || !is_numeric($interval[1])) {
            return null;
        }
        $s = max(0.0, (float)$interval[0]);
        $e = (float)$interval[1];
        if ($durationSeconds !== null && $durationSeconds > 0) {
            $e = min($e, (float)$durationSeconds); // (a) cannot exceed the video length
        }
        if ($e <= $s || $maxCreditSeconds <= 0.0) {
            return null;
        }
        if (($e - $s) > $maxCreditSeconds) {
            $e = $s + $maxCreditSeconds; // (b) cannot credit more than real elapsed time
        }
        return [$s, $e];
    }

    // ─── DB orchestration ────────────────────────────────────────────────────

    /**
     * Record a watch heartbeat atomically. One user's concurrent tabs are serialized via CAS on
     * last_ping_ts (NC has no forUpdate()); the first-ping INSERT race surfaces as a unique-constraint
     * violation and is retried. Implausible (<5s) pings are discarded silently.
     *
     * @param array{0: int|float, 1: int|float} $newInterval [start, end] in seconds
     * @throws DoesNotExistException if the content_id is not a registered course video
     */
    public function recordHeartbeat(string $userId, int $contentId, array $newInterval): void {
        $video = $this->videoMapper->findById($contentId);
        $courseId = (int)$video->getCourseId();
        $duration = $video->getDurationSeconds();
        $sanitized = $this->sanitizeInterval($newInterval);
        if ($sanitized === null) {
            return; // nothing usable to record
        }
        $now = $this->timeFactory->getTime();

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $row = $this->progressMapper->findByUserAndContent($userId, $contentId);

            if ($row === null) {
                // First ping — no row to lock yet. INSERT; a racing sibling INSERT loses on the
                // (user_id, content_id) unique index → retry (re-read as an existing row).
                $transition = $this->computeHeartbeatTransition([], null, $sanitized, $now, $duration);
                // First-ping transition is never null (null last_ping_ts is always plausible).
                if ($transition === null) {
                    return;
                }
                $entity = new VideoProgress();
                $entity->setUserId($userId);
                $entity->setContentId($contentId);
                $entity->setIntervalsJson($transition['intervals_json']);
                $entity->setCoveredPct($transition['covered_pct']);
                $entity->setLastPingTs($now);
                try {
                    $this->progressMapper->insert($entity);
                } catch (\OCP\DB\Exception $e) {
                    if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                        continue; // sibling tab created the row first — retry as update path
                    }
                    throw $e;
                }
                if ($transition['complete']) {
                    $this->markComplete($userId, $contentId, $courseId);
                }
                return;
            }

            if ($row->getCompletedAt() !== null) {
                return; // already complete — idempotent no-op
            }

            $lastPing = $row->getLastPingTs();
            $transition = $this->computeHeartbeatTransition(
                $this->decodeIntervals($row->getIntervalsJson()),
                $lastPing,
                $sanitized,
                $now,
                $duration
            );
            if ($transition === null) {
                return; // implausible <5s ping — discard silently
            }

            $affected = $this->casUpdateProgress($userId, $contentId, $lastPing, $transition);
            if ($affected === 0) {
                continue; // a sibling tab advanced last_ping_ts — re-read and retry
            }
            if ($transition['complete']) {
                $this->markComplete($userId, $contentId, $courseId);
            }
            return;
        }
        // Retries exhausted under heavy same-user contention — drop this ping (next one recovers).
    }

    /**
     * Mark (user, content) complete: null the transient segments and emit course.video.completed
     * EXACTLY once. The emit is guarded by an atomic `WHERE completed_at IS NULL` UPDATE — a racing
     * second crossing of 95% affects 0 rows and does not emit. Context is facts-only:
     * {course_id, content_id} — never covered_pct, intervals, or timestamps (audit minimalism).
     */
    public function markComplete(string $userId, int $contentId, int $courseId): void {
        $now = $this->timeFactory->getTime();
        $qb = $this->db->getQueryBuilder();
        $affected = (int)$qb->update('learning_video_progress')
            ->set('completed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('intervals_json', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('covered_pct', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->set('last_ping_ts', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('content_id', $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('completed_at'))
            ->executeStatement();

        if ($affected === 1) {
            $this->emitVideoCompleted($userId, $contentId, $courseId);
        }
    }

    /**
     * Record a document "Gelesen" completion (VIDEO-07): server-side proof with no intervals and no
     * duration requirement. INSERT the completion row; if it already exists, promote it to completed
     * via the same atomic `completed_at IS NULL` guard so the emit fires exactly once (double-click safe).
     */
    public function markDocumentRead(string $userId, int $contentId, int $courseId): void {
        $now = $this->timeFactory->getTime();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_video_progress')
                ->values([
                    'user_id' => $qb->createNamedParameter($userId),
                    'content_id' => $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT),
                    'completed_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ])
                ->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
            // Row exists (prior heartbeat progress or an earlier read) — promote to completed once.
            $qb = $this->db->getQueryBuilder();
            $affected = (int)$qb->update('learning_video_progress')
                ->set('completed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->set('intervals_json', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                ->set('covered_pct', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                ->set('last_ping_ts', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('content_id', $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->isNull('completed_at'))
                ->executeStatement();
            if ($affected !== 1) {
                return; // already completed by a concurrent writer — do not re-emit
            }
        }
        $this->emitVideoCompleted($userId, $contentId, $courseId);
    }

    /**
     * Gate assertion consumed by TrainingService (162-03): every required course video/document must
     * be completed by the user. W1 scope: a VIDEO source with null/0 duration is a misconfigured gate
     * and blocks (documents are exempt from the duration check).
     *
     * @throws ForbiddenException if the gate is misconfigured or any required content is incomplete
     */
    public function assertCourseVideosComplete(int $courseId, string $userId): void {
        $videos = $this->videoMapper->findByCourse($courseId);
        foreach ($videos as $video) {
            $vid = (int)$video->getId();
            if (!$this->isDurationValidForGate($video->getSourceType(), $video->getDurationSeconds())) {
                throw new ForbiddenException('Video gate misconfigured: content ' . $vid . ' has no duration');
            }
            $row = $this->progressMapper->findByUserAndContent($userId, $vid);
            if ($row === null || $row->getCompletedAt() === null) {
                throw new ForbiddenException('Required video/material not completed: content ' . $vid);
            }
        }
    }

    // ─── internals ───────────────────────────────────────────────────────────

    private function emitVideoCompleted(string $userId, int $contentId, int $courseId): void {
        // Facts-only, minimal payload — NEVER covered_pct/intervals/timestamps. Do NOT swallow the
        // ComplianceAuditException (Phase 160/161 fail-closed contract).
        $this->auditService->logComplianceEvent(
            ComplianceEventTypes::VIDEO_COMPLETED,
            $userId,
            ['course_id' => $courseId, 'content_id' => $contentId]
        );
    }

    /**
     * CAS update of the transient watch state, serialized on the expected last_ping_ts (and only
     * while not yet completed). Returns affected rows: 0 means a sibling tab raced ahead → retry.
     *
     * @param array{intervals: array<int, mixed>, intervals_json: string, covered_pct: float|null, last_ping_ts: int, complete: bool} $transition
     */
    private function casUpdateProgress(string $userId, int $contentId, ?int $expectedLastPing, array $transition): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_video_progress')
            ->set('intervals_json', $qb->createNamedParameter($transition['intervals_json']))
            ->set('covered_pct', $qb->createNamedParameter($transition['covered_pct']))
            ->set('last_ping_ts', $qb->createNamedParameter($transition['last_ping_ts'], IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('content_id', $qb->createNamedParameter($contentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('completed_at'));

        if ($expectedLastPing === null) {
            $qb->andWhere($qb->expr()->isNull('last_ping_ts'));
        } else {
            $qb->andWhere($qb->expr()->eq('last_ping_ts', $qb->createNamedParameter($expectedLastPing, IQueryBuilder::PARAM_INT)));
        }

        return (int)$qb->executeStatement();
    }

    /**
     * Decode a stored intervals_json string into a list of [start, end] pairs (defensive: any
     * malformed value degrades to an empty set rather than throwing).
     *
     * @return array<int, array{0: int|float, 1: int|float}>
     */
    private function decodeIntervals(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $iv) {
            if (is_array($iv) && isset($iv[0], $iv[1]) && is_numeric($iv[0]) && is_numeric($iv[1])) {
                $out[] = [$iv[0] + 0, $iv[1] + 0];
            }
        }
        return $out;
    }

    /**
     * Validate/normalize a client-supplied interval to a numeric [start, end] with start < end and
     * start >= 0. Returns null when unusable (the ping is then ignored — server is source of truth).
     *
     * @param array{0?: int|float, 1?: int|float} $interval
     * @return array{0: int|float, 1: int|float}|null
     */
    private function sanitizeInterval(array $interval): ?array {
        if (!isset($interval[0], $interval[1]) || !is_numeric($interval[0]) || !is_numeric($interval[1])) {
            return null;
        }
        $start = max(0.0, (float)$interval[0]);
        $end = (float)$interval[1];
        if ($end <= $start) {
            return null;
        }
        return [$start, $end];
    }
}
