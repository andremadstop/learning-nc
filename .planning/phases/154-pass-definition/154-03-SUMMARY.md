---
phase: 154-pass-definition
plan: 03
subsystem: api
tags: [php, phpunit, phpstan, certification, pass-criteria, audit, tdd]

# Dependency graph
requires:
  - phase: 154-02
    provides: cert_enabled / cert_pass_percent / cert_required_pool_ids / cert_validity_days columns on learning_courses + Course entity accessors
  - phase: 154-01
    provides: PassResult DTO + PassCriteriaService skeleton + CourseSummaryService + test Fake harness
provides:
  - CourseSummaryService::getExamScore() — best objective exam score (0-100) across completed exam sessions
  - CourseSummaryService::getMasteryStats() promoted private -> public for Gate 2 reuse
  - PassCriteriaService::evaluate() — two-gate pass evaluation (exam score + pool mastery)
  - Idempotent course.passed audit emission (PASS-07) with lazy GET-triggered design
  - 11 GREEN PHPUnit tests proving PASS-02/03/04/05/07 gate logic
affects: [154-04, 155-cert-artifact]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Two-gate evaluator: Gate 1 (exam score) AND Gate 2 (pool mastery) both >= cert_pass_percent"
    - "Idempotent audit emission via SELECT-then-INSERT dedup on decoded context_json (no LIKE, no UNIQUE constraint)"
    - "Structural guess exclusion: exam-mode sessions have no Guessed button; getExamScore reads objective correct_answers"
    - "TDD RED->GREEN with non-stateful Fake DB harness: builder queue simulates per-call SELECT results"

key-files:
  created: []
  modified:
    - app/lib/Service/CourseSummaryService.php
    - app/lib/Service/PassCriteriaService.php
    - app/tests/Unit/Service/PassCriteriaServiceTest.php
    - app/tests/Unit/Service/CourseSummaryServiceTest.php

key-decisions:
  - "mastery_rate compared directly (>= threshold) — getMasteryStats() already returns a 0-100 percentage; no x100 multiplier"
  - "PASS-07 audit trigger is lazy: fires inside evaluate() called by GET /pass-status, not at exam completion"
  - "Lucky-guess exclusion is structural (no is_guessed column anywhere); verified against codebase before documenting"
  - "Aggregate pool mastery: single rate over UNION of required pools, single threshold (intentional)"
  - "Refactored emit/getPassedAt to share a findPassEvent() helper (DRY; preserves 2-builders-per-passing-eval count)"

patterns-established:
  - "Pattern: file_get_contents structural assertions guard against forbidden references (ReadinessService, validity_days)"
  - "Pattern: passing evaluate() consumes exactly 2 $db query builders (dedup SELECT + getPassedAt SELECT); non-passing consumes 0"

# PASS-05 + PASS-07 are the requirements whose REQUIREMENTS.md status is Complete.
# PASS-02/03 gate LOGIC is proven here, but their instructor-facing capability (set
# threshold / designate pools via UI) stays Pending until 154-05 — see body + 154-02 note.
requirements-completed: [PASS-05, PASS-07]

# Metrics
duration: ~30min
completed: 2026-06-26
---

# Phase 154 Plan 03: Pass Criteria Service Layer Summary

**Two-gate pass evaluator (objective exam score + pool mastery) with idempotent course.passed audit emission, plus CourseSummaryService::getExamScore(); 11/11 PHPUnit GREEN, PHPStan L5 clean.**

## Performance

- **Duration:** ~30 min
- **Completed:** 2026-06-26
- **Tasks:** 2 (RED test phase, GREEN implementation phase)
- **Files modified:** 4

## Accomplishments
- `CourseSummaryService::getExamScore()` — computes best exam score in a PHP loop (`(int) round(correct_answers * 100 / total_questions)`), avoiding SQL integer-division truncation; filters `mode='exam'` AND `completed_at IS NOT NULL`.
- `CourseSummaryService::getMasteryStats()` visibility widened private -> public so Gate 2 reuses the existing aggregate without duplication.
- `PassCriteriaService::evaluate()` — full two-gate logic replacing the NotImplemented stub; returns `PassResult::notApplicable()` when `cert_enabled=false`.
- `emitPassEventIfFirst()` + `findPassEvent()` — idempotent `course.passed` audit emission; second qualifying evaluate() does not duplicate the row.
- 11 PHPUnit tests un-skipped and driven to GREEN (8 PassCriteria + 3 CourseSummary), proving PASS-02/03/04/05/07.

## Task Commits

1. **RED: un-skip + author failing tests** - `e9a37fb` (test) — 9 errors (getExamScore undefined, evaluate NotImplemented), 2 structural guards already green
2. **GREEN: implement services** - `7999456` (feat) — getExamScore + getMasteryStats public + two-gate evaluate() + idempotent audit

_REFACTOR: emit/getPassedAt deduplicated into `findPassEvent()` helper during GREEN (folded into the feat commit; tests stayed GREEN, builder count preserved)._

## Verification Output (verbatim)

**PHPUnit** (`tests/Unit/Service/PassCriteriaServiceTest.php tests/Unit/Service/CourseSummaryServiceTest.php --no-coverage`):
```
PHPUnit 10.5.63 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.22
Configuration: /var/www/html/custom_apps/learning/phpunit.xml

...........                                                       11 / 11 (100%)

Time: 00:00.031, Memory: 10.00 MB

OK (11 tests, 37 assertions)
```

**PHPStan L5** (`lib/Service/CourseSummaryService.php lib/Service/PassCriteriaService.php lib/Service/PassResult.php --no-progress`):
```
Note: Using configuration file /var/www/html/custom_apps/learning/phpstan.neon.

 [OK] No errors
```

**Grep verifications:**
- `grep -i ReadinessService app/lib/Service/PassCriteriaService.php` → no output (structural isolation holds)
- `grep "function getMasteryStats" app/lib/Service/CourseSummaryService.php` → `public function getMasteryStats(...)`
- `grep -E "mastery_rate.*\* 100" app/lib/Service/PassCriteriaService.php` → no output (direct comparison; comment reworded to avoid false positive)
- `grep "function getExamScore" app/lib/Service/CourseSummaryService.php` → present
- `grep "emitPassEventIfFirst" app/lib/Service/PassCriteriaService.php` → present
- `grep "validity_days" app/lib/Service/PassCriteriaService.php` → no output (PASS-04: not evaluated in Phase 154)

## Decisions Made

- **Mastery formula deviation (confirmed correct):** `getMasteryStats()` returns `mastery_rate = round($mastered / $total * 100, 1)` — already a percentage (0-100.0). Gate 2 compares `mastery_rate >= cert_pass_percent` directly. Research Pattern 2's proposed `mastery_rate * 100 >= threshold` assumed a fraction and is WRONG per the codebase. Direct comparison is used.
- **Lazy PASS-07 trigger:** the `course.passed` audit event fires inside `evaluate()`, which is invoked by `GET /api/courses/{id}/pass-status` (154-04) — not at exam completion. The idempotency guard makes repeated GETs safe.
- **Lucky-guess exclusion is structural — verified, not assumed:** Before documenting, confirmed there is **no `is_guessed` column anywhere in `app/lib/`**, and the only "Guessed" self-rating button lives in `LeitnerMode.vue` (the Leitner/FSRS spaced-repetition UI). Exam-mode sessions score `correct_answers` objectively via the batch-submit path; a guess cannot inflate an exam score. Gate 1 reads `getExamScore()` (`mode='exam'`), so guessed Leitner answers never reach it. This honors the known-issue / `feedback_fsrs_guess_policy` requirement that guessed answers MUST be excluded from a pass.
  - **Caveat for reviewers:** the gate tests **mock** `getExamScore()`/`getMasteryStats()`, so the 11 GREEN tests prove the gate *logic*, not the guess exclusion itself. The exclusion is a structural property of the data source, asserted indirectly in `CourseSummaryServiceTest` (the `mode='exam'` + `completed_at IS NOT NULL` filters) and documented here. It is **not** directly unit-tested.

## Deviations from Plan

### Minor refactor (within plan scope)

**1. [Refactor] Extracted shared `findPassEvent()` helper**
- **Found during:** GREEN implementation of `emitPassEventIfFirst()` and `getPassedAt()`
- **Issue:** Both methods performed the identical SELECT-decode-match-on-course_id scan over `learning_audit_events`.
- **Fix:** Factored the scan into a private `findPassEvent(): ?array` returning the decoded context. `emitPassEventIfFirst()` checks `!== null`; `getPassedAt()` reads `passed_at`. Behavior and the 2-builders-per-passing-evaluate() count are unchanged.
- **Files modified:** app/lib/Service/PassCriteriaService.php
- **Verification:** 11/11 PHPUnit GREEN, PHPStan clean
- **Committed in:** `7999456`

**2. [Cosmetic] Reworded a code comment to avoid a grep false positive**
- The Gate 2 comment originally contained the literal `mastery_rate * 100`, which matched the plan's verification grep #5. Reworded to describe the x100 multiplier without the literal pattern. No logic change.

---

**Total deviations:** 1 in-scope refactor + 1 cosmetic comment reword.
**Impact on plan:** No scope creep. Service layer only — no controller or Vue changes.

## Issues Encountered

- **Skeleton `@phpstan-ignore-next-line` removed:** the 154-01 skeleton suppressed unused-param warnings. The full constructor now uses promoted readonly properties that are all referenced, so the stale ignore was dropped (a leftover ignore on used deps can itself trip PHPStan L5). PHPStan confirms clean.
- **Race condition (documented, accepted):** `emitPassEventIfFirst()` is SELECT-then-INSERT, not atomic. Two simultaneous qualifying GET requests could both insert a `course.passed` row. Probability is low; the audit table is append-only; worst case is a minor data-quality issue, not functional/security. Proper fix (a `course_id` column + `UNIQUE(event_key, user_id, course_id)` index) is deferred to a future migration. No duplicate was observed in testing (idempotency test passes).

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- **Schema verified:** `oc_learning_sessions` has a nullable `course_id` (bigint) column with a supporting index `learn_sess_course_pool_idx (course_id, pool_id, user_id)`. `getExamScore()`'s `course_id` filter is valid and indexed — confirmed via live DB introspection (the Fake test harness has no schema, so this was checked directly, not via the green tests).
- Service layer is complete and proven. **154-04** can wire `GET /api/courses/{id}/pass-status` directly to `PassCriteriaService::evaluate()` — note the lazy PASS-07 audit fires on the first qualifying GET (intentional).
- PASS-01..04 remain Pending in REQUIREMENTS.md until the controller (154-04) + UI (154-05) expose the capability end-to-end; PASS-02/03/05/07 gate logic is now proven at the service layer.

## Self-Check: PASSED

- FOUND: app/lib/Service/CourseSummaryService.php (getExamScore + public getMasteryStats)
- FOUND: app/lib/Service/PassCriteriaService.php (two-gate evaluate + emitPassEventIfFirst)
- FOUND: commit e9a37fb (test RED)
- FOUND: commit 7999456 (feat GREEN)
- VERIFIED: 11/11 PHPUnit GREEN, PHPStan L5 [OK] No errors

---
*Phase: 154-pass-definition*
*Completed: 2026-06-26*
