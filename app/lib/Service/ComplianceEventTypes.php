<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * String constants for compliance-critical audit event types.
 *
 * These are the event types written via AuditService::logComplianceEvent()
 * and therefore part of the tamper-evident hash chain. Non-compliance events
 * (game sessions, moderation actions, etc.) use AuditService::logEvent() and
 * are NOT in this class.
 *
 * Phase 162 (VideoProgressService) emits VIDEO_COMPLETED — the constant is
 * defined here so Phase 162 can reference it without schema changes.
 */
final class ComplianceEventTypes {
    public const COURSE_PASSED   = 'course.passed';
    public const CERT_ISSUED     = 'cert.issued';
    public const CERT_REVOKED    = 'cert.revoked';
    // Phase 164 (AssignmentService::closePeriod emits this event)
    public const PERIOD_CLOSED   = 'cert.period.closed';
    // Defined here for Phase 162 (VideoProgressService emits this event)
    public const VIDEO_COMPLETED = 'course.video.completed';
    // GitHub #75: an instructor asked every learner to confirm a required document again
    // (RequiredDocumentService::requestReconfirm); context {course_id, content_id}
    public const CONTENT_RECONFIRM_REQUESTED = 'course.content.reconfirm_requested';
    // GitHub #75: an instructor made material documents required or optional
    // (RequiredDocumentService::changeRequired); context {course_id, required, document_ids, content_ids}.
    // Making a document optional ends its confirmations: ticking it again starts a new row.
    public const CONTENT_REQUIRED_CHANGED = 'course.content.required_changed';

    private function __construct() {} // static-only class
}
