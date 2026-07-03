---
phase: 164-rezertifizierung-retention-i18n
plan: 04
subsystem: certification
tags: [cert-issuance, recertification, expiry, cas, union-guard, phpunit, vitest]

requires:
  - phase: 164-rezertifizierung-retention-i18n
    plan: 02
    provides: "IssueResult skeleton + RED locking tests (testRevokeNoAutoReissue, testReCertPeriodGuard, testConcurrentPassSingleEventAndCert, testClosedPeriodReadsExpired, testValidityDstCrossing)"
  - phase: 164-rezertifizierung-retention-i18n
    plan: 01
    provides: "Version009600 migration (cert_validity_months column + learning_recert_reminders table)"

provides:
  - "RECERT-05 union guard: unfiltered Branch A (hasEverIssuedCertificate) OR allow-listed open-period Branch B"
  - "CAS-gated single-emit: exactly one COURSE_PASSED + markPassed on active_idem_key UNIQUE INSERT win"
  - "DST-safe computeExpiry via DateTimeImmutable::modify('+N months') replacing +validityDays*86400"
  - "closePeriod 3-write sequence: expires old cert (expires_at=past, active_idem_key=NULL) + nulls assignment.active_period_key + inserts fresh period row; idempotent via UNIQUE catch"
  - "ComplianceEventTypes::PERIOD_CLOSED constant"
  - "Course::certValidityMonths entity property wired to Version009600 cert_validity_months column"

affects:
  - "164-05 (wave merge + PHPStan gate + full PHPUnit)"
  - "RecertPeriodCloseJob (uses AssignmentService::closePeriod)"
  - "PassCriteriaService callers (CourseController)"

tech-stack:
  added: []
  patterns:
    - "CAS via DB UNIQUE insert (active_idem_key): loser catches REASON_UNIQUE_CONSTRAINT_VIOLATION → wasCreated=false → no duplicate emit"
    - "DST-safe expiry: DateTimeImmutable('@ts')->setTimezone->modify('+N months') — never +N*86400"
    - "closePeriod idempotency: second call NULLs already-NULL rows (no-op), hits UNIQUE on re-INSERT → caught → return"

key-files:
  created: []
  modified:
    - app/lib/Service/PassCriteriaService.php
    - app/lib/Service/IssuanceService.php
    - app/lib/Service/AssignmentService.php
    - app/lib/Service/ComplianceEventTypes.php
    - app/lib/Db/Course.php
    - app/tests/Unit/Service/IssuanceServiceTest.php
    - app/tests/Unit/Service/CertificateVerifyServiceTest.php

key-decisions:
  - "Branch A uses UNFILTERED findByUserAndCourse() — a revoked or expired cert row still counts as 'ever issued', blocking auto-reissue after punitive revoke (Codex GO gate confirmed)"
  - "Branch B status allow-list: ['assigned','in_progress','overdue'] NOT != 'passed' — terminal states are non-issuing by positive enumeration"
  - "closePeriod uses raw IDBConnection (3 SQL writes) not CertificateMapper — avoids extra SELECT round-trip; 3 FakeQueryBuilders in test"
  - "computeExpiry takes Course entity not courseId — avoids double-fetch since course is already in scope at all call sites"
  - "No CertificateMapper injected into AssignmentService — closePeriod targets cert by active_idem_key via raw UPDATE, no fetch needed"

requirements-completed: [RECERT-01, RECERT-02, RECERT-05, RECERT-07]

duration: ~90min (across 2 sessions)
completed: 2026-07-03
---

# Phase 164 Plan 04: RECERT-05 Union Guard + DST-safe Expiry + closePeriod Surgery Summary

**Corrected RECERT-05 union guard (unfiltered Branch A + allow-listed Branch B) wired with CAS-gated single emit, DST-safe DateTimeImmutable expiry, and 3-write closePeriod that expires the old cert without revoking it**

## Performance

- **Duration:** ~90 min (split across 2 sessions due to context limit)
- **Started:** 2026-07-02
- **Completed:** 2026-07-03
- **Tasks:** 3 (Task 1: 3-KI review — pre-complete; Task 2: union guard + markPassed wiring; Task 3: DST expiry + closePeriod)
- **Files modified:** 7

## Accomplishments

- RECERT-05 union guard implemented: Branch A checks `hasEverIssuedCertificate()` (UNFILTERED — revoked cert still counts), Branch B checks active per-user period with allow-listed status; prevents punitive-revoke-auto-reissue bug confirmed by Codex gate
- CAS-gated emit: `issueIfPassedResult()` returns `IssueResult.wasCreated`; `COURSE_PASSED` and `markPassed()` only fire on the `active_idem_key` UNIQUE INSERT winner — concurrent losers silently dedupe
- `computeExpiry()` uses `DateTimeImmutable::modify('+N months')` (DST-safe); replaces `+validityDays*86400` in both `issueIfPassed()` and `issueIfPassedResult()`; months = override ?? course.cert_validity_months ?? 12; months <= 0 → null expiry
- `closePeriod()` 3-write sequence: (1+2) UPDATE certificates SET expires_at=now-1, active_idem_key=NULL; (3) UPDATE assignments SET active_period_key=NULL; (4) INSERT fresh assignment row (UNIQUE catch → idempotent); audit via `logComplianceEvent(PERIOD_CLOSED)`; never touches `revoked`/`revoked_at` (SC2 preserved)
- `Course::certValidityMonths` entity property + `addType('certValidityMonths','integer')` added (Version009600 migration column was missing from entity)
- `ComplianceEventTypes::PERIOD_CLOSED` constant added
- 7 locking tests flipped GREEN: `testRevokeNoAutoReissue`, `testReCertPeriodGuard`, `testReCertNewRowOldUrlResolves`, `testConcurrentPassSingleEventAndCert`, `testValidityDstCrossing`, `testClosedPeriodReadsExpired`, `testGroupPeriodIsPerUser`

## Task Commits

1. **Task 1: 3-KI security review (pre-complete)** - `5a6d2d3` (test/docs — Codex pass 3 recorded GO)
2. **Task 2: RECERT-05 union guard + CAS emit + markPassed wiring** - `42efec1` (feat)
3. **Task 3: DST-safe computeExpiry + closePeriod write-set** - `e32edf8` (feat)

**Plan metadata:** (this commit)

## Files Created/Modified

- `app/lib/Service/PassCriteriaService.php` — union guard (`mayIssue`, `hasEverIssuedCertificate`), CAS-gated COURSE_PASSED emit + markPassed, injected CertificateMapper + AssignmentService
- `app/lib/Service/IssuanceService.php` — `computeExpiry()` implemented (DST-safe months); wired into both `issueIfPassed()` and `issueIfPassedResult()`; `issueIfPassedResult()` returns `IssueResult.wasCreated`
- `app/lib/Service/AssignmentService.php` — `closePeriod()` full 3-write implementation; PHPDoc corrected (active_idem_key on learning_CERTIFICATES not assignments)
- `app/lib/Service/ComplianceEventTypes.php` — added `PERIOD_CLOSED = 'cert.period.closed'`
- `app/lib/Db/Course.php` — added `$certValidityMonths` property, `addType('certValidityMonths','integer')`, `@method` annotations
- `app/tests/Unit/Service/IssuanceServiceTest.php` — `makeCourse()` accepts `?int $validityMonths`; `testCredentialIsSelfContainedWithValidUntilWhenExpiring` updated to `validityMonths:12` + DST-safe assertion; `testValidUntilOmittedWhenNoExpiry` updated to `validityMonths:0`
- `app/tests/Unit/Service/CertificateVerifyServiceTest.php` — `testClosedPeriodReadsExpired` hardened: `FakeDbConnection([3 builders])` replaces `createMock(IDBConnection::class)`; SC2 assertions now reachable

## Decisions Made

- **No CertificateMapper in AssignmentService constructor**: `closePeriod()` targets the cert via raw `UPDATE WHERE active_idem_key=idemKey` — no SELECT needed; 3 builders in FakeDbConnection matches 3 SQL writes
- **Branch A UNFILTERED**: `findByUserAndCourse()` returns the NEWEST cert regardless of `revoked`/`expires_at`/`active_idem_key` — this correctly blocks auto-reissue after punitive revoke (cert row exists, no open period)
- **Branch B allow-list over deny-list**: `['assigned','in_progress','overdue']` explicitly; using `!= 'passed'` would let unexpected terminal states (cancelled, withdrawn) trigger issuance
- **computeExpiry signature**: takes `Course $course` not `int $courseId` — course entity already in scope at all call sites, avoiding a DB round-trip

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Course::certValidityMonths entity property missing**
- **Found during:** Task 3 (IssuanceService computeExpiry wiring)
- **Issue:** Version009600 migration adds `cert_validity_months` column to `learning_courses`, but `Course.php` entity had no property, no `addType`, and no `@method` annotations — `getCertValidityMonths()` would return `null` silently even with DB value present
- **Fix:** Added `protected $certValidityMonths;`, `$this->addType('certValidityMonths', 'integer');`, and `@method int|null getCertValidityMonths()` / `@method void setCertValidityMonths(?int $certValidityMonths)` to Course.php
- **Files modified:** app/lib/Db/Course.php
- **Committed in:** e32edf8 (Task 3 commit)

---

**Total deviations:** 1 auto-fixed (Rule 2 — missing entity property for Version009600 column)
**Impact on plan:** Essential for correctness — without the entity property, `computeExpiry()` would always fall through to the 12-month default regardless of DB value.

## Issues Encountered

- Context window exhausted mid-Task 3 (between `use OCA\Learning\Db\Course;` import and `computeExpiry()` body). Resumed cleanly from SUMMARY context — no work lost, no duplication.
- Pre-existing i18n test failures (50 tests in `RecertL10n.test.js` — `recert_retention_years_label` missing from `ar.json` and other language files) confirmed pre-existing before this plan's changes; not introduced by 164-04.

## Next Phase Readiness

- All 7 locking tests are now GREEN (pending PHPStan L5 confirmation at orchestrator wave merge)
- Wave 3 (164-04) complete; 164-05 (wave merge + PHPStan + full PHPUnit + deploy) is next
- Outstanding: i18n parity for `recert_retention_years_label` in ar/fr/ru/de — likely 164-03 scope, should be picked up before release

---
*Phase: 164-rezertifizierung-retention-i18n*
*Completed: 2026-07-03*
