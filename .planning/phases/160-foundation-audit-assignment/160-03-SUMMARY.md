---
phase: 160-foundation-audit-assignment
plan: "03"
subsystem: audit
tags: [audit, compliance, cert, hash-chain, AUDIT-03]
dependency_graph:
  requires: [160-02]
  provides: [AUDIT-03 compliance writes for course.passed / cert.issued / cert.revoked]
  affects:
    - app/lib/Service/PassCriteriaService.php
    - app/lib/Service/IssuanceService.php
    - app/lib/Controller/CertificateController.php
tech_stack:
  added: []
  patterns:
    - logComplianceEvent() for all 3 compliance-critical event types
    - $isFirstRevoke capture pattern for idempotent write guards
key_files:
  created: []
  modified:
    - app/lib/Service/PassCriteriaService.php
    - app/lib/Service/IssuanceService.php
    - app/lib/Controller/CertificateController.php
    - app/tests/Unit/Service/PassCriteriaServiceTest.php
    - app/tests/Unit/Service/IssuanceServiceTest.php
    - app/tests/Unit/Controller/CertificateControllerTest.php
    - app/tests/Unit/Controller/CertificateRevokeTest.php
decisions:
  - CERT_ISSUED placed after try/catch block (not inside try): unique-constraint loser returns early in catch, so only the insert winner reaches the compliance write
  - No use imports added to PassCriteriaService/IssuanceService — both are in OCA\Learning\Service, same namespace as AuditService and ComplianceEventTypes
  - CertificateController uses $this->userId ?? '' for PHPStan ?string safety on logComplianceEvent userId param
metrics:
  duration: ~20 minutes
  completed: 2026-07-01
  tasks_completed: 2
  tasks_total: 2
  files_modified: 7
---

# Phase 160 Plan 03: Track A — Migrate 3 Compliance Callers to logComplianceEvent + AUDIT-03 Tests Summary

Migrated the three compliance-critical callers from `logEvent()` to `logComplianceEvent()` with `ComplianceEventTypes` constants, wired `AuditService` into `IssuanceService` and `CertificateController`, and added AUDIT-03 behavioral tests — the hash chain now has entries for every course pass, cert issuance, and cert revocation.

## What Was Built

### Task 1 — PassCriteriaService + IssuanceService (commit `c4d317d`)

**PassCriteriaService::emitPassEventIfFirst():**
Replaced `logEvent('course.passed', ...)` with `logComplianceEvent(ComplianceEventTypes::COURSE_PASSED, ...)`. The idempotency dedup (`findPassEvent` querying `event_key='course.passed'`) continues to work because `COURSE_PASSED === 'course.passed'`. Exception now propagates through `emitPassEventIfFirst()` → `evaluate()` — no `try/catch` added.

**IssuanceService:**
Added `AuditService $auditService` as the 11th constructor parameter (NC autowires via its standard DI container). Placed `logComplianceEvent(CERT_ISSUED)` **after the try/catch block**, before `$this->notify()`. This is the correct placement:
- Happy path: insert succeeds → falls through try/catch → fires CERT_ISSUED
- Unique-constraint loser: `catch` returns `$winner` early → never reaches CERT_ISSUED
- Other DB exception: `catch` rethrows → never reaches CERT_ISSUED

**PassCriteriaServiceTest:**
- Flipped all 6 existing `logEvent` assertions to `logComplianceEvent` (3 `once()` + 3 `never()`)
- Updated the `with(...)` on the passing-score test to use `ComplianceEventTypes::COURSE_PASSED`
- Added `testEmitsComplianceEvent()` (AUDIT-03 dedicated test — calls `evaluate()` on happy path, asserts `logComplianceEvent` called once with `COURSE_PASSED` and `course_id` in context)

**IssuanceServiceTest:**
- Added `AuditService` + `ComplianceEventTypes` imports
- Extended `makeService()` with optional `?AuditService $audit = null` param; defaults to a permissive mock; passed as 11th arg to `IssuanceService` constructor
- Updated `testDedupesOnUniqueConstraintViolation()` to inject an audit mock with `expects($this->never())->method('logComplianceEvent')` — directly verifies the loser path does not write a chain entry
- Added `testCertIssuedEventFiredOnce()` — wires a `once()` audit mock on happy path, asserts CERT_ISSUED fires with `course_id` and `verification_id` in context

### Task 2 — CertificateController (commit `2abecb1`)

**CertificateController:**
- Added `use OCA\Learning\Service\AuditService` and `use OCA\Learning\Service\ComplianceEventTypes` imports (controller is in a different namespace from the services)
- Added `private AuditService $auditService` property + constructor param + assignment
- In `revoke()`: captured `$isFirstRevoke = $cert->getRevokedAt() === null` **before** the idempotency guard that sets `revokedAt`. After `certificateMapper->update()`, fires `logComplianceEvent(CERT_REVOKED)` only when `$isFirstRevoke === true` — a repeat revoke call produces no duplicate chain entry. Uses `$this->userId ?? ''` to satisfy PHPStan on the `?string` property.

**CertificateControllerTest:**
Updated `makeController()` to pass a plain `AuditService` mock as the 7th constructor arg — prevents all existing tests from erroring on argument count mismatch.

**CertificateRevokeTest:**
- Added `AuditService` and `ComplianceEventTypes` imports
- Extended `makeController()` with `?AuditService $audit = null` optional param
- `testRevokeSetsTombstoneFields`: injects a mock with `expects($this->once())->method('logComplianceEvent')` asserting CERT_REVOKED + userId `alice` + context has `course_id` and `verification_id` — direct AUDIT-03 coverage for first revoke
- `testRevokeIdempotentKeepsFirstDate`: injects a mock with `expects($this->never())->method('logComplianceEvent')` — proves repeat revoke never writes a duplicate chain entry

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] No `use` imports added to same-namespace files**
- **Found during:** Task 1 (advisor review)
- **Issue:** The plan instructed adding `use OCA\Learning\Service\ComplianceEventTypes;` to PassCriteriaService and IssuanceService — both are already in `OCA\Learning\Service`, the same namespace. Same-namespace references don't need `use` declarations; adding them would be non-idiomatic and against codebase convention (PassCriteriaService already references `AuditService` without a `use`).
- **Fix:** Omitted the same-namespace `use` imports; added them only in `CertificateController` (namespace `OCA\Learning\Controller`).
- **Files modified:** PassCriteriaService.php, IssuanceService.php (no `use` added), CertificateController.php (imports added)

**2. [Rule 1 - Bug] Existing PassCriteriaServiceTest assertions referenced old `logEvent` method**
- **Found during:** Task 1
- **Issue:** 6 existing test methods asserted `method('logEvent')`. After the production migration to `logComplianceEvent`, the 3 `once()` assertions would fail (logEvent never called); the 3 `never()` assertions would trivially pass but assert the wrong thing.
- **Fix:** Flipped all 6 to `method('logComplianceEvent')`; updated `with(...)` args to use `ComplianceEventTypes::COURSE_PASSED`.
- **Files modified:** PassCriteriaServiceTest.php

**3. [Rule 3 - Blocking] IssuanceServiceTest and CertificateRevokeTest/CertificateControllerTest constructors would fail on argument count**
- **Found during:** Tasks 1 + 2
- **Issue:** `makeService()` in IssuanceServiceTest and `makeController()` in both controller tests passed the exact number of original constructor args. Adding AuditService to the constructors would cause all existing tests to error on instantiation.
- **Fix:** Extended factory helpers with optional `?AuditService` params and updated inline test constructions (`testDedupesOnUniqueConstraintViolation`).

## Known Limitation (pre-existing, out of scope — document only)

`CERT_ISSUED` exceptions **do not propagate to HTTP 500**, contrary to what the plan objective implies for all three callers. `PassCriteriaService::evaluate()` wraps `issueIfPassed()` in a `try/catch` (lines 88–95) that swallows and logs all exceptions. So a failed `CERT_ISSUED` compliance write is logged but not propagated — and the cert row is already committed by then.

`COURSE_PASSED` does propagate (called directly at line 76, unwrapped); `CERT_REVOKED` propagates (called directly in controller). Only CERT_ISSUED is in the swallowed path.

Fixing this requires distinguishing a compliance-write failure from the intentionally-swallowed "no signing key yet" case — an architectural question (Rule 4). Deferred to a follow-up phase. The chain entry semantics are correct in all other respects; this limitation affects only the error-surface contract for the CERT_ISSUED path.

## Container Verification (deferred to orchestrator)

PHPStan L5 and PHPUnit runs for all 5 affected test classes are deferred to the orchestrator's Gate 1 central deploy + verify step. No local PHP binary available on the workstation. This is expected behavior per the Track A/B parallel execution contract.

Expected commands (orchestrator):
```bash
ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpstan analyse lib/Service/PassCriteriaService.php lib/Service/IssuanceService.php lib/Controller/CertificateController.php --no-progress 2>&1'
ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpunit --filter PassCriteriaServiceTest 2>&1' | tail -5
ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpunit --filter IssuanceServiceTest 2>&1' | tail -5
ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpunit --filter CertificateRevokeTest 2>&1' | tail -5
```

## Self-Check: PASSED

Files exist:
- `app/lib/Service/PassCriteriaService.php` — FOUND
- `app/lib/Service/IssuanceService.php` — FOUND
- `app/lib/Controller/CertificateController.php` — FOUND
- `app/tests/Unit/Service/PassCriteriaServiceTest.php` — FOUND
- `app/tests/Unit/Service/IssuanceServiceTest.php` — FOUND
- `app/tests/Unit/Controller/CertificateControllerTest.php` — FOUND
- `app/tests/Unit/Controller/CertificateRevokeTest.php` — FOUND

Commits:
- `c4d317d` — feat(160-03): migrate PassCriteriaService + IssuanceService to logComplianceEvent
- `2abecb1` — feat(160-03): inject AuditService into CertificateController, wire CERT_REVOKED

grep validation (all 3 callers, 1 match each):
- `PassCriteriaService.php:122`: `logComplianceEvent(ComplianceEventTypes::COURSE_PASSED, …)`
- `IssuanceService.php:146`: `logComplianceEvent(ComplianceEventTypes::CERT_ISSUED, …)`
- `CertificateController.php:202`: `logComplianceEvent(…CERT_REVOKED…)`

New test methods:
- `PassCriteriaServiceTest::testEmitsComplianceEvent` — FOUND (line 204)
- `IssuanceServiceTest::testCertIssuedEventFiredOnce` — FOUND (line 284)
