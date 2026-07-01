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
    // Defined here for Phase 162 (VideoProgressService emits this event)
    public const VIDEO_COMPLETED = 'course.video.completed';

    private function __construct() {} // static-only class
}
