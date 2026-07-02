---
phase: 164-rezertifizierung-retention-i18n
plan: 02
subsystem: service-layer
tags: [tdd, recertification, guard, dst, period-close, locking-tests, red-tests]

# Dependency graph
requires:
  - phase: 164-01
    provides: "Version009600 migration (cert_validity_months, anonymized_at, recert_reminders), ConfigDefaults"

provides:
  - "mayIssue() private stub in PassCriteriaService — union-guard seam (not yet wired)"
  - "computeExpiry() private stub in IssuanceService — DST-safe date-math seam (not yet wired)"
  - "closePeriod() public stub in AssignmentService — SC2-documented period-close signature"
  - "deriveLifecycleState() public stub in CertificateVerifyService — derived-state seam (returns 'valid')"
  - "5 RED locking tests: 3 in PassCriteriaServiceTest + 1 in IssuanceServiceTest + 1 in CertificateVerifyServiceTest"

affects:
  - 164-03-period-close-job
  - 164-04-recert-guard-impl (mandatory Codex review gated on these tests)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "observed-RED discipline — stubs freeze signatures; tests define contract; impl wave (164-04) flips GREEN"
    - "private stubs (mayIssue, computeExpiry) at PHPStan L5 — unused-private NOT reported (no dead-code extension)"
    - "IssuanceServiceTest::makeService gains optional issuedAt param for clock-pinning"
    - "DST test uses date_default_timezone_set + try/finally restore; explicit DateTimeZone('Europe/Berlin') on all DateTimeImmutable ops"

key-files:
  modified:
    - app/lib/Service/PassCriteriaService.php
    - app/lib/Service/IssuanceService.php
    - app/lib/Service/AssignmentService.php
    - app/lib/Service/CertificateVerifyService.php
    - app/tests/Unit/Service/PassCriteriaServiceTest.php
    - app/tests/Unit/Service/IssuanceServiceTest.php
    - app/tests/Unit/Service/CertificateVerifyServiceTest.php

key-decisions:
  - "mayIssue/computeExpiry are private stubs — PHPStan L5 does NOT flag unused private (no dead-code extension in phpstan.neon); safe to leave unwired until 164-04"
  - "closePeriod throws LogicException — testClosedPeriodReadsExpired lets exception propagate (no expectException); wrapping would invert RED→GREEN"
  - "testReCertPeriodGuard uses expects(exactly(2)) — discriminates on audit re-emit count across two periods; current dedup yields 1, expected 2"
  - "testRevokeNoAutoReissue uses issuance.expects(never()) — current evaluate() always calls issueIfPassed when passed=true; guard will short-circuit in 164-04"
  - "testValidityDstCrossing discriminates via 2026-03-29 spring-forward (Europe/Berlin): modify('+12 months') vs +365*86400 differ by 3600 s"

# Metrics
duration: ~35min
completed: 2026-07-02
---

# Phase 164 Plan 02: RECERT-05 Signature Stubs + RED Locking Tests Summary

**4 stub signatures frozen across 4 service files; 5 RED locking tests define the union-guard, DST-safety, and period-close contracts that Codex reviews before Wave 4 implementation (164-04)**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-07-02
- **Tasks:** 3
- **Files modified:** 7

## Accomplishments

- Froze all 4 RECERT-05 blast-radius signatures as explicit stubs with full PHPDoc (SC2 contract, DST anti-pattern, union-guard union-condition). Existing code paths are fully intact — no regression to current pass/issue/verify behavior.
- Wrote 3 RED tests locking the union-guard contract (PassCriteriaServiceTest): period-aware re-emit, punitive-revoke safety, RECERT-07 new-row trigger.
- Wrote 1 RED test locking the DST-safe expiry contract (IssuanceServiceTest): TZ=Europe/Berlin spring-forward discriminator.
- Wrote 1 RED test locking the SC2 period-close contract (CertificateVerifyServiceTest): closePeriod stub throws → test ERRORS → RED; SC2 assertions (expired not withdrawn) run only after 164-04.

## Task Commits

1. **Task 1: Freeze signatures** — `d5846f6` (feat)
2. **Task 2: RED tests RECERT-05 union guard** — `eef3856` (test)
3. **Task 3: RED tests DST + closed-period-reads-expired** — `3707f98` (test)

## Files Modified

- `app/lib/Service/PassCriteriaService.php` — added `private function mayIssue(string $userId, int $courseId): bool` (throws LogicException, not wired)
- `app/lib/Service/IssuanceService.php` — added `private function computeExpiry(int $issuedAt, int $courseId, ?int $assignmentOverrideMonths): ?int` (throws LogicException, not wired)
- `app/lib/Service/AssignmentService.php` — added `public function closePeriod(string $subjectType, string $subjectId, int $courseId): void` (throws LogicException, SC2-documented)
- `app/lib/Service/CertificateVerifyService.php` — added `public function deriveLifecycleState(Certificate $cert, int $now): string` (returns 'valid', not wired)
- `app/tests/Unit/Service/PassCriteriaServiceTest.php` — added Certificate import + 3 RED tests
- `app/tests/Unit/Service/IssuanceServiceTest.php` — added optional `issuedAt` param to makeService + testValidityDstCrossing
- `app/tests/Unit/Service/CertificateVerifyServiceTest.php` — added AssignmentService/AuditService/IDBConnection/IGroupManager imports + testClosedPeriodReadsExpired

## Expected-RED Tests (Wave 2 — confirmed RED at orchestrator wave merge)

| Test | File | RED mechanism | GREEN trigger |
|------|------|---------------|---------------|
| `testReCertPeriodGuard` | PassCriteriaServiceTest | `audit.expects(exactly(2))` — current dedup fires COURSE_PASSED only once (not twice across 2 periods) | 164-04: period-aware mayIssue() fires COURSE_PASSED per period |
| `testRevokeNoAutoReissue` | PassCriteriaServiceTest | `issuance.expects(never())` — current evaluate() calls issueIfPassed unconditionally when passed=true | 164-04: mayIssue() returns false (revoked+no open period) → evaluate() skips issuance |
| `testReCertNewRowOldUrlResolves` | PassCriteriaServiceTest | `audit.expects(once())` COURSE_PASSED — dedup blocks re-emit (0 actual calls) | 164-04: period-aware guard fires COURSE_PASSED in new period |
| `testValidityDstCrossing` | IssuanceServiceTest | `assertSame(expectedExpiry, cert->getExpiresAt())` — current code uses cert_validity_days=0 → expiresAt=null | 164-04: computeExpiry() wired with cert_validity_months=12 + modify('+12 months') |
| `testClosedPeriodReadsExpired` | CertificateVerifyServiceTest | `closePeriod()` throws LogicException → test ERRORS | 164-04: closePeriod() implemented; cert reads 'expired' (revoked=false, expires_at<now) |

## Decisions Made

- **PHPStan L5 unused-private safety confirmed.** Checked `app/phpstan.neon`: level 5 without `phpstan-dead-code` extension — unused private methods are NOT reported. The two private stubs (mayIssue, computeExpiry) are safe.
- **closePeriod SC2 invariant documented in PHPDoc.** The contract "MUST NOT set revoked=true" is in the PHPDoc, not just in test comments — it will survive code review.
- **IssuanceServiceTest::makeService extended non-breakingly.** Optional `int $issuedAt = self::ISSUED_AT` param added; all 12 existing tests continue to use the default value.

## Deviations from Plan

None — plan executed exactly as written.

## Deferred Verifications (central gate — NOT run here)

Per PROJECT_SPECIFIC_OVERRIDES (no local PHP binary):

- **PHPStan L5** — orchestrator runs at wave merge (confirms stubs are level-clean)
- **PHPUnit RED confirmation** — orchestrator runs `vendor/bin/phpunit` inside devcloud container; expects 5 new FAILUREs/ERRORs for the locking tests
- **`occ upgrade`** — not needed for Wave 2 (no migration in this plan)
- **Gate 2 (test-api.sh)** — not applicable (test-only wave)

## Next Phase Readiness

Wave 2 stubs + RED tests are the Codex review target. Wave 3 (164-03) is the period-close background job; Wave 4 (164-04) is the implementation that flips all 5 tests GREEN.

Downstream waves must NOT implement mayIssue()/computeExpiry() bodies until Codex reviews the full union-guard contract via:
- The 3 PassCriteriaServiceTest RED tests (union-guard spec)
- testValidityDstCrossing (DST-safe date-math spec)
- testClosedPeriodReadsExpired (SC2 period-close spec)

---
*Phase: 164-rezertifizierung-retention-i18n*
*Completed: 2026-07-02*
