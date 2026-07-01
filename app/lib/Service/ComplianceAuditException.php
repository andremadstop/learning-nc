<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Raised by AuditService::logComplianceEvent() when a compliance-critical audit append
 * cannot be persisted (DB failure, chain-state missing, chain-slot exhaustion).
 *
 * Purpose (Phase 160 security hardening, FIX-3): give callers a TYPED signal so a genuine
 * compliance-audit failure can be told apart from an ordinary provisioning error.
 *
 * A compliance-audit failure MUST fail closed — it has to surface (HTTP 500), never be
 * logged-and-swallowed. Extends \RuntimeException so it still propagates through any
 * `catch (\RuntimeException)` boundary while remaining distinguishable via `instanceof`.
 */
class ComplianceAuditException extends \RuntimeException {
}
