---
phase: 154-pass-definition
plan: 01
subsystem: api
tags: [phpunit, phpstan, dto, value-object, certification, pass-criteria]

# Dependency graph
requires:
  - phase: 154-pass-definition (research/context)
    provides: Locked decisions (no ReadinessService, exam-only guess exclusion, validity-days deferred to 155)
provides:
  - PassResult immutable value-object DTO (the contract evaluate() returns)
  - PassCriteriaService skeleton with constructor + evaluate() signature
  - 8 skipped PHPUnit stubs for PassCriteriaService (PASS-01..07 + validity-days guard)
  - 3 skipped PHPUnit stubs for CourseSummaryService::getExamScore()
affects: [154-02, 154-03, 154-04, 154-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Skeleton-first wave: type contracts + markTestSkipped stubs land before implementation so the PHPUnit/PHPStan pre-push gate stays green"
    - "Immutable PassResult value object with static notApplicable() factory + toArray() serializer"

key-files:
  created:
    - app/lib/Service/PassResult.php
    - app/lib/Service/PassCriteriaService.php
    - app/tests/Unit/Service/PassCriteriaServiceTest.php
    - app/tests/Unit/Service/CourseSummaryServiceTest.php
  modified: []

key-decisions:
  - "Skeleton constructor uses plain (non-promoted) params + a scoped @phpstan-ignore-next-line to satisfy PHPStan L5 while deps are unused until 154-03"
  - "PassResult.applicable defaults true; notApplicable() returns the cert-disabled sentinel"

patterns-established:
  - "Wave-1 contract plan: real class signatures + skipped test stubs prevent fatal autoload errors and let downstream plans compile against concrete types"

requirements-completed: [PASS-05, PASS-07]

# Metrics
duration: ~15min
completed: 2026-06-26
---

# Phase 154 Plan 01: Pass-Criteria Interface Contracts Summary

**PassResult value-object DTO + PassCriteriaService skeleton + 11 skipped PHPUnit stubs establishing the type contracts all subsequent Phase 154 plans compile against.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-06-26T13:30:00Z (approx)
- **Completed:** 2026-06-26T13:44:30Z
- **Tasks:** 2
- **Files created:** 4

## Accomplishments
- `PassResult.php` — immutable value object: `notApplicable()` factory, 6 getters, `toArray()` serializer
- `PassCriteriaService.php` — skeleton with the constructor signature (CourseMapper, CourseSummaryService, AuditService, IDBConnection) and `evaluate(string, int): PassResult` returning a NotImplemented throw; NO ReadinessService import or call
- `PassCriteriaServiceTest.php` — 8 `markTestSkipped` stubs covering PASS-01..07 + PASS-04 validity-days not-evaluated guard
- `CourseSummaryServiceTest.php` — 3 `markTestSkipped` stubs for `getExamScore()` contract
- Pre-push gate stays green: PHPStan L5 clean on skeletons, PHPUnit 11 skipped / 0 errors / 0 failures

## Task Commits

Each task was committed atomically:

1. **Task 1: PassResult DTO + PassCriteriaService skeleton** - `c6ce17e` (feat)
2. **Task 2: PHPUnit skipped test stubs** - `42cbbbd` (test)

## Files Created/Modified
- `app/lib/Service/PassResult.php` - Immutable DTO returned by `PassCriteriaService::evaluate()`
- `app/lib/Service/PassCriteriaService.php` - Skeleton; `evaluate()` throws NotImplemented (real body in 154-03)
- `app/tests/Unit/Service/PassCriteriaServiceTest.php` - 8 skipped behavioral-contract stubs
- `app/tests/Unit/Service/CourseSummaryServiceTest.php` - 3 skipped `getExamScore()` stubs

## Interface Contracts Declared

**PassResult** — constructor `(bool $passed, ?int $score, int $threshold, bool $poolsMastered, ?int $passedAt, bool $applicable = true)`; getters `isPassed/getScore/getThreshold/isPoolsMastered/getPassedAt/isApplicable`; `toArray()`; static `notApplicable()`.

**PassCriteriaService** — constructor accepts `CourseMapper, CourseSummaryService, AuditService, IDBConnection`; `evaluate(string $userId, int $courseId): PassResult` (skeleton throws `\RuntimeException('...NotImplemented. See 154-03-PLAN.md.')`).

**Confirmation:** No `ReadinessService` import, call, or literal token in `PassCriteriaService.php`. The guard comment was reworded ("MUST NOT call the FSRS readiness service") so the literal `ReadinessService` string does not appear anywhere in the source file — satisfying verification #5 (`grep -i ReadinessService` → empty) and preventing a future 154-03 content-grep assertion from tripping on the comment.

## Verbatim Tool Output

PHPStan (skeleton files):
```
Note: Using configuration file /var/www/html/custom_apps/learning/phpstan.neon.

 [OK] No errors
```

PHPUnit (both stub files):
```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.22
Configuration: /var/www/html/custom_apps/learning/phpunit.xml

SSSSSSSSSSS                                                       11 / 11 (100%)

Time: 00:00.014, Memory: 8.00 MB

OK, but some tests were skipped!
Tests: 11, Assertions: 0, Skipped: 11.
```

## Decisions Made
- Skeleton constructor declared with plain (non-promoted) typed params instead of promoted `readonly` properties, with a single scoped `@phpstan-ignore-next-line`. Reason: PHPStan L5 flags both unused promoted properties (`property.onlyWritten`) and unused plain params (`constructor.unusedParameter`); the deps are genuinely unused until 154-03 replaces the body. 154-03 overwrites this file with the real promoted-property constructor.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan L5 rejected the skeleton constructor's unused dependencies**
- **Found during:** Task 1 (PassCriteriaService skeleton)
- **Issue:** The plan assumed PHPStan would pass, but L5 fails the skeleton: promoted `readonly` deps trigger `property.onlyWritten` (4 errors) and, after switching to plain params, `constructor.unusedParameter` (4 errors), because `evaluate()` throws before using any dependency.
- **Fix:** Kept plain typed params (preserving the DI/type contract; only `evaluate()` is a tracked export) and added one `// @phpstan-ignore-next-line` above the constructor. PHPStan reports `[OK] No errors`.
- **Files modified:** app/lib/Service/PassCriteriaService.php
- **Verification:** `phpstan analyse lib/Service/PassResult.php lib/Service/PassCriteriaService.php` → No errors
- **Committed in:** `c6ce17e` (Task 1 commit)

**2. [Rule 1 - Bug] Plan example comment contained the literal `ReadinessService`, defeating its own verification #5**
- **Found during:** Post-execution review (Task 1 source)
- **Issue:** The plan's skeleton example put `MUST NOT call ReadinessService` in a docblock, but the plan's own verification #5 asserts `grep -i ReadinessService` returns nothing — a plan-internal contradiction. It would also break a 154-03 content-grep assertion when that stub is un-skipped.
- **Fix:** Reworded the comment to `MUST NOT call the FSRS readiness service` (no literal token), preserving the warning. Comment-only change (no PHPStan re-run needed; full-lib PHPStan re-run anyway → No errors).
- **Files modified:** app/lib/Service/PassCriteriaService.php
- **Verification:** `grep -i ReadinessService app/lib/Service/PassCriteriaService.php` → empty
- **Committed in:** follow-up commit (post-task review)

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug)
**Impact on plan:** Necessary to keep the pre-push PHPStan gate green. The constructor's external signature (accepted dependency types) is unchanged, so downstream plans are unaffected. No scope creep — change is file-local and is overwritten by 154-03.

## Issues Encountered
- The plan's Task 2 automated `verify` expression is buggy: its first `grep -E "OK|skipped"` discards the summary line ("Tests: 11, ... Skipped: 11"), so the chained count grep cannot match and the expression reports failure despite a correct run. Verified the actual result directly instead: `Tests: 11, Assertions: 0, Skipped: 11` with no Error/Fatal/Failure lines. Done criteria met.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Type contracts ready: 154-02..05 can compile against `PassResult` and `PassCriteriaService::evaluate()`.
- 154-03 will: make `CourseSummaryService::getMasteryStats()` public, add `getExamScore()`, replace the `evaluate()` body, and un-skip all 11 stubs to GREEN.

---
*Phase: 154-pass-definition*
*Completed: 2026-06-26*

## Self-Check: PASSED

- All 4 created source/test files present on disk + SUMMARY.md
- Both task commits present in git history (c6ce17e, 42cbbbd)
