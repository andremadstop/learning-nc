---
phase: 154-pass-definition
plan: 04
subsystem: api
tags: [php, controller, routes, vue-service, i18n, phpstan, vitest, certification, pass-status, idor]

# Dependency graph
requires:
  - phase: 154-03
    provides: PassCriteriaService::evaluate() two-gate evaluation + PassResult::toArray()
  - phase: 154-02
    provides: cert_* columns + Course entity accessors (setCertEnabled/PassPercent/RequiredPoolIds/ValidityDays)
provides:
  - "PATCH /api/courses/{courseId}/cert-config — instructor-only cert config (enable/threshold/required pools/validity)"
  - "GET /api/courses/{courseId}/pass-status — IDOR-guarded pass status; lazy PASS-07 audit trigger"
  - "CourseController::canAccessCourse() — enrollment-aware access helper (any role)"
  - "app/src/services/CourseService.js — updateCertConfig() + getPassStatus() JS API client (new module)"
  - "9 cert/pass i18n keys across DE/EN/FR/RU/AR"
affects: [154-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Instructor-write endpoint validates pool IDs against the course's own pool list (FILTER_VALIDATE_INT + array_column)"
    - "Student-facing endpoint returns explicit 403 (not 404-obscurity) via canAccessCourse() IDOR guard"
    - "New JS service module (app/src/services/) — first of its kind; components previously called axios inline"

key-files:
  created:
    - app/src/services/CourseService.js
    - app/tests/unit/CourseService.test.js
  modified:
    - app/lib/Controller/CourseController.php
    - app/appinfo/routes.php
    - app/l10n/de.json
    - app/l10n/en.json
    - app/l10n/fr.json
    - app/l10n/ru.json
    - app/l10n/ar.json
    - app/l10n/de.js
    - app/l10n/en.js
    - app/l10n/fr.js
    - app/l10n/ru.js
    - app/l10n/ar.js
    - scripts/test-api.sh

key-decisions:
  - "Did NOT mark PASS-01..04/06 complete — honoring 154-02/03 documented deferral to 154-05 (UI delivers end-to-end capability)"
  - "PassCriteriaService autowired (all DI params are services/interfaces) — no Application.php registration needed"
  - "Non-promoted property style for the new constructor param to match existing CourseController"
  - "i18n added to all 5 langs (not just DE/EN) — check-i18n-parity.sh requires key-set parity"

# Requirements: plan frontmatter lists PASS-01/02/03/04/06, but their instructor/student-facing
# capability is only complete once 154-05 ships the UI. Endpoints are wired here; mark complete at 154-05.
requirements-completed: []

# Metrics
duration: ~50min
completed: 2026-06-26
---

# Phase 154 Plan 04: Pass-Status Controller + Service Wiring Summary

**Exposed the 154-03 pass logic over HTTP: instructor-only `PATCH cert-config` (pool-ID validated) + IDOR-guarded `GET pass-status` (lazy PASS-07 audit), a new `CourseService.js` API client, 9 cert i18n keys across 5 languages, and API + Vitest tests. PHPStan L5 clean, 1091/1091 Vitest green, routes resolve live.**

## Performance

- **Duration:** ~50 min
- **Completed:** 2026-06-26
- **Tasks:** 3
- **Files:** 2 created, 13 modified

## Accomplishments

- `CourseController::updateCertConfig()` — instructor-only (canManageCourse); validates `certPassPercent` 1–100, `certValidityDays` >= 0, and each `certRequiredPoolIds` entry via `FILTER_VALIDATE_INT` + membership in the course's own pool list; stores pool IDs as JSON text (empty → null).
- `CourseController::getPassStatus()` — IDOR-guarded by new `canAccessCourse()` (owner OR any enrolled role); delegates to `PassCriteriaService::evaluate()`; returns `PassResult::toArray()`. Non-enrolled → explicit 403.
- `CourseController::canAccessCourse()` — enrollment-aware helper mirroring `CourseService::hasAccess()`.
- Routes `course#updateCertConfig` (PATCH) + `course#getPassStatus` (GET) registered.
- `app/src/services/CourseService.js` — **new module** (no `services/` dir existed before): `updateCertConfig()` + `getPassStatus()`.
- 9 cert/pass i18n keys added to DE/EN/FR/RU/AR (real translations, not identity); `.js` regenerated from `.json`.
- `scripts/test-api.sh` — cert-config (200 + 400×4 + 403) and pass-status (200 owner + 403 IDOR) assertions.
- `app/tests/unit/CourseService.test.js` — 3 Vitest tests (getPassStatus + updateCertConfig).

## Task Commits

1. **feat: controller endpoints + routes** — `d073dba`
2. **feat: CourseService.js + i18n (5 langs)** — `ef9b20e`
3. **test: API + Vitest tests** — `ce26f1a`
4. **fix: validate certRequiredPoolIds against `pool_id`** — `2767662` (post-review correctness fix)

## Verification Output (verbatim)

**occ router:list learning** (`routes:list` does not exist on this NC 33 — correct command is `router:list`):
```
| learning.course.getpassstatus    | GET   | /apps/learning/api/courses/{courseId}/pass-status |
| learning.course.updatecertconfig | PATCH | /apps/learning/api/courses/{courseId}/cert-config |
```

**PHPStan L5** (`lib/Controller/CourseController.php --no-progress`):
```
Note: Using configuration file /var/www/html/custom_apps/learning/phpstan.neon.

 [OK] No errors
```

**ESLint** (`src/services/CourseService.js`): exit 0, no output (0 errors).

**Vitest** (`tests/unit/CourseService.test.js`):
```
 Test Files  1 passed (1)
      Tests  3 passed (3)
```
Full suite: **1091 passed (80 files)**.

**i18n guards:**
```
i18n .js<->.json value-sync OK across DE/EN/FR/RU/AR
i18n key-parity OK across DE/EN/FR/RU/AR
```
Key count per lang (json translations): **2202 → 2211 (+9)** for each of DE/EN/FR/RU/AR.

**Live runtime smoke (unauthenticated — proves routing/verbs/bootstrap, not auth path):**
```
GET   /courses/1/cert-config  -> HTTP 405   (route exists; PATCH-only verb correctly enforced)
GET   /courses/1/pass-status  -> HTTP 401   (route exists; auth middleware active)
PATCH /courses/1/cert-config  -> HTTP 401   (route exists; auth middleware active)
```
No 500 — app bootstraps cleanly, confirming PassCriteriaService DI resolves.

**Confirmations:**
- `canAccessCourse()` added (3 occurrences in CourseController.php). ✓
- `FILTER_VALIDATE_INT` used for pool-ID validation (1 occurrence). ✓
- en.js regenerated and deployed (server `en.js` contains "Enable certification"). ✓

## Decisions Made

- **Requirements NOT marked complete.** Plan frontmatter lists PASS-01/02/03/04/06, but the 154-02 and 154-03 state notes explicitly defer PASS-01..04 to 154-05 ("Mark complete at 154-05"), and PASS-06 (student sees status) needs the CourseSummary.vue UI. This plan only wires the HTTP layer. Marking them now would overstate delivery. Deferred consciously.
- **PassCriteriaService autowired** — its constructor takes only CourseMapper/CourseSummaryService/AuditService/IDBConnection, all DI-resolvable; no Application.php change.
- **Non-promoted constructor property** to match the rest of CourseController (the 154-01 "unused promoted readonly" PHPStan issue does not apply — the param is used in getPassStatus).

## Deviations from Plan

### Blocking issues auto-fixed (Rule 3)

**1. [Rule 3] routes.php path** — Plan/frontmatter referenced `appinfo/routes.php`; the real file is `app/appinfo/routes.php` (project convention: code lives under `app/`). Edited the correct file.

**2. [Rule 3] CourseService.js did not exist** — There was no `app/src/services/` directory; components call axios inline. The plan (and frontmatter) treat `CourseService.js` as a file to populate. Created it as a new module — the intended consumer is 154-05.

**3. [Rule 3] i18n parity** — Plan specified only de.json + en.json, but `scripts/check-i18n-parity.sh` (Gate 1) requires all 5 langs share the same key-set. Added real FR/RU/AR translations too, else the parity gate would fail.

### Plan-bug fixes (Rule 1)

**4. [Rule 1] occ command name** — Plan's verify used `occ routes:list`, which does not exist on this NC 33 instance ("no commands defined in the routes namespace"). Used `occ router:list learning` instead.

**5. [Rule 1 — Bug] pool-ID validation used the wrong column (post-review fix, commit `2767662`)** — The plan's interface comment said `pools[].id` is the pool id. It is not: `getPoolSnapshot()` adds no `id`, so each pool entry's `'id'` is the **course-pool mapping row id** (`CoursePool::jsonSerialize`); the actual pool id is `'pool_id'`. The original `array_column(..., 'id')` would 400 a valid pool → fails the "Own pool ID accepted → 200" must_have (the one happy-path with no live coverage). Fixed to `array_map('intval', array_column(..., 'pool_id'))`, which also hardens the strict `in_array()` against string-typed DB columns. Caught in advisor review; verified against the entity source. PHPStan clean, redeployed.

### Adjustments

**5. test-api.sh insertion point** — Plan said insert "before the training/leitner tests" (~line 480). At that point POOL_ID is NOT yet attached to the course (attachment happens at ~line 643), so `certRequiredPoolIds:[POOL_ID]→200` would have failed with 400. Moved the block to immediately after the "Add pool to course works" + enroll step.

**6. TDD task 3 RED phase degenerate** — Tasks 1–2 already shipped the implementation, so the Vitest tests were GREEN on first run (no meaningful RED). Tests authored and verified green; committed as `test(...)`.

## Issues Encountered

- **Authenticated live API suite not run** — `scripts/test-api.sh` needs `ADMIN_PASS`/`SECOND_PASS` (per-user vault passwords) and targets `https://devcloud.andrestiebitz.de`. The documented vault credentials file (`Projekte/Learning-NC/DevCloud-Zugangsdaten.md`) does not exist in the mounted vault, and no credentials are exported in this environment. The happy-path 200/400/403 assertions therefore could not execute here. Mitigation: (a) the pass-evaluation logic is unit-proven by 154-03's 11 PHPUnit tests; (b) routes + verb semantics confirmed live (405/401, no 500); (c) the new assertions are bash-syntax-valid (`bash -n` OK) and will run under Gate 2 once credentials are exported (CI / user-run with `ADMIN_PASS`/`SECOND_PASS`).

  **Coverage caveat (communicate to user):** the new controller branches (cert-config validation, IDOR guard, pool-ID membership check) have **zero executed test coverage** in this run — Vitest only exercises the axios wrapper, and 154-03's PHPUnit mocks the gate inputs. This is inherent to the plan choosing `test-api.sh` (not PHPUnit) as the controller-test vehicle. The pool_id fix (deviation 5) was found by review/static analysis, not by a failing test. Run `test-api.sh` with credentials to get real coverage before relying on these paths in production.

## User Setup Required

To run the new API assertions end-to-end:
```fish
ssh relais 'docker exec -u www-data devcloud-app php occ security:bruteforce:reset 172.18.0.1'
env BASE_URL=https://devcloud.andrestiebitz.de ADMIN_USER=admin ADMIN_PASS=… SECOND_USER=… SECOND_PASS=… bash scripts/test-api.sh
```

## Next Phase Readiness

- **154-05 (UI)** can now consume `CourseService.js`: `updateCertConfig()` in the instructor management tab (CourseTabVerwaltung.vue) and `getPassStatus()` in CourseSummary.vue. The 9 i18n keys are in place. PASS-01..04/06 should be marked complete in REQUIREMENTS.md at the end of 154-05 once the UI closes the loop.
- First call to `GET /pass-status` for a qualifying student fires the lazy PASS-07 `course.passed` audit (idempotent).

## Self-Check: PASSED

- FOUND: app/lib/Controller/CourseController.php (updateCertConfig + getPassStatus + canAccessCourse)
- FOUND: app/appinfo/routes.php (cert-config PATCH + pass-status GET)
- FOUND: app/src/services/CourseService.js (updateCertConfig + getPassStatus)
- FOUND: app/tests/unit/CourseService.test.js (3 tests green)
- FOUND: commit d073dba (feat controller+routes)
- FOUND: commit ef9b20e (feat service+i18n)
- FOUND: commit ce26f1a (test)
- VERIFIED: PHPStan L5 [OK] No errors; Vitest 1091 green; routes resolve live (405/401)

---
*Phase: 154-pass-definition*
*Completed: 2026-06-26*
</content>
</invoke>
