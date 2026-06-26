<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMapper;
use OCP\IDBConnection;

/**
 * Evaluates whether a student has met the pass criteria for a certifying course.
 *
 * MUST NOT call the FSRS readiness service — it throws without exam_date and
 * pass evaluation is orthogonal to FSRS readiness. See 154-CONTEXT.md locked decisions.
 *
 * Implementation: see 154-03-PLAN.md (this skeleton is replaced in Wave 3).
 */
class PassCriteriaService {
    /**
     * Dependencies are accepted (DI autowires by type) but not stored yet —
     * the skeleton evaluate() throws before using them. PHPStan level 5 flags
     * the unused params; suppressed here only for the skeleton. The real
     * promoted-property constructor lands in 154-03 when evaluate() reads them.
     */
    // @phpstan-ignore-next-line
    public function __construct(
        CourseMapper $courseMapper,
        CourseSummaryService $courseSummaryService,
        AuditService $auditService,
        IDBConnection $db,
    ) {}

    /**
     * Evaluate pass criteria for $userId in $courseId.
     * Returns PassResult::notApplicable() when cert_enabled=false.
     * On first qualification, emits a course.passed audit event (idempotent).
     *
     * NOTE: This skeleton throws NotImplemented — see 154-03 for full body.
     *
     * @throws \RuntimeException when called before 154-03 implementation
     */
    public function evaluate(string $userId, int $courseId): PassResult {
        throw new \RuntimeException('PassCriteriaService::evaluate() — NotImplemented. See 154-03-PLAN.md.');
    }
}
