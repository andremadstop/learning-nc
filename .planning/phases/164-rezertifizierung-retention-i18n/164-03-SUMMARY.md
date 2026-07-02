---
phase: 164-rezertifizierung-retention-i18n
plan: 03
subsystem: background-jobs
tags: [tdd, recertification, retention, i18n, dsgvo, background-job, red-tests, skeleton]

# Dependency graph
requires:
  - phase: 164-01
    provides: "Version009600 migration (anonymized_at, learning_recert_reminders UNIQUE, cert_validity_months), ConfigDefaults"
  - phase: 164-02
    provides: "AssignmentService::closePeriod() stub (LogicException 164-05), stable PHPUnitStubs"

provides:
  - "RecertReminder entity + RecertReminderMapper (insertOnce/findByCertAndThreshold stubs)"
  - "RecertReminderService::sendRecertReminders(): int stub (throws 164-06)"
  - "RetentionService::anonymizeExpired(): int stub (throws 164-07)"
  - "RecertPeriodCloseJob: daily TimedJob skeleton (setInterval 86400, no-op run)"
  - "RetentionJob: daily TimedJob skeleton (setInterval 86400, no-op run)"
  - "Application::boot(): both jobs registered with has()/add() guards — 164-05/06/07 must NOT re-touch Application.php"
  - "RecertPeriodCloseJobTest::testDoubleRunSingleRow RED locking test"
  - "RecertReminderServiceTest::testOncePerThreshold RED locking test"
  - "RetentionServiceTest::testAnonymizeKeepsChain RED locking test"
  - "RecertL10n.test.js: canonical 10-key list, 5-lang parity gate (confirmed RED locally)"

affects:
  - 164-05-period-close-impl
  - 164-06-reminder-impl
  - 164-07-retention-i18n-impl

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TimedJob skeleton: no-op run() with try/catch+log; impl wave flips GREEN — mirrors AuditCheckpointJob"
    - "observed-RED discipline: stubs freeze signatures; tests define contract; impl waves flip GREEN (see 164-02)"
    - "Application::boot() owns ALL job registration — never split across plans to avoid merge conflict"
    - "insertOnce CAS pattern: UNIQUE(cert_id,threshold_days) catch REASON_UNIQUE_CONSTRAINT_VIOLATION = already sent"
    - "retention tombstone: anonymized_at IS NOT NULL = erased (crypto-erasure; breaks sig verifiability — accepted)"
    - "RecertL10n.test.js: Vitest parity gate in Gate 1 pre-push hook (mirrors BadgeL10n.test.js, all 5 langs)"

key-files:
  created:
    - app/lib/BackgroundJob/RecertPeriodCloseJob.php
    - app/lib/BackgroundJob/RetentionJob.php
    - app/lib/Db/RecertReminder.php
    - app/lib/Db/RecertReminderMapper.php
    - app/lib/Service/RecertReminderService.php
    - app/lib/Service/RetentionService.php
    - app/tests/Unit/BackgroundJob/RecertPeriodCloseJobTest.php
    - app/tests/Unit/Service/RecertReminderServiceTest.php
    - app/tests/Unit/Service/RetentionServiceTest.php
    - app/tests/unit/RecertL10n.test.js
  modified:
    - app/lib/AppInfo/Application.php

key-decisions:
  - "testDoubleRunSingleRow uses expects(once()) on closePeriod: first run closes the period, second run finds nothing open → 0 more calls → total 1. RED: no-op body → 0 calls → FAILS. Avoids false GREEN from expects(atMostOnce)."
  - "testOncePerThreshold does NOT wrap in expectException: sendRecertReminders() throws → test ERRORS → RED for free. Wrapping would flip RED→GREEN (same trap as closePeriod in 164-02)."
  - "testAnonymizeKeepsChain captures cert via update() callback spy; asserts userId=null + credentialJson=empty. getAnonymizedAt() assertion commented out — Certificate.php does not have anonymizedAt entity property yet (164-07 adds it alongside impl)."
  - "RecertL10n.test.js checks all 5 langs (de/en/fr/ru/ar) — NOT just de+en like BadgeL10n.test.js (DSGVO-05 requires 5-lang parity). 10 canonical keys enumerated as single source of truth for 164-07."
  - "Application.php registers BOTH RecertPeriodCloseJob + RetentionJob now — 164-05/06/07 are forbidden from touching Application.php (merge-conflict prevention)."

patterns-established:
  - "Job skeleton: constructor(ITimeFactory, $svc, LoggerInterface) + setInterval(86400) + empty try/catch body with impl-wave comment"
  - "RED test via LogicException stub: call method without expectException → ERRORS → RED. Do NOT use expectException (flips to GREEN)."
  - "RED test via positive expects() on no-op: expects(once())->method('closePeriod') fails when no-op body calls nothing."

requirements-completed: [RECERT-04, RECERT-06, RECERT-07, DSGVO-03, DSGVO-05]

# Metrics
duration: ~30min
completed: 2026-07-02
---

# Phase 164 Plan 03: Lifecycle Skeletons + RED Tests Summary

**6 frozen skeleton classes (RecertPeriodCloseJob, RetentionJob, RecertReminderService/Mapper/Entity, RetentionService) + Application::boot() job registration + 4 RED locking tests (3 PHP, 1 JS 5-lang parity) that impl waves 164-05/06/07 flip GREEN**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-07-02
- **Completed:** 2026-07-02
- **Tasks:** 3
- **Files created/modified:** 11

## Accomplishments

- Froze all lifecycle class signatures (mapper, services, TimedJobs) so 164-05/06/07 implement bodies without reshaping the public API
- Registered both RecertPeriodCloseJob and RetentionJob in Application::boot() — the sole ownership ensures no merge conflicts when impl waves run
- Authored 3 PHP RED locking tests with documented RED mechanism and GREEN trigger per test; 1 JS parity test confirmed RED locally (50 failures = 10 keys × 5 langs all absent)

## Task Commits

1. **Task 1: Skeletons + boot() registration** — `673f99b` (feat)
2. **Task 2: RED tests — period-close + reminder-once** — `fd060e4` (test)
3. **Task 3: RED tests — retention chain + RecertL10n parity** — `d787822` (test)

## Files Created/Modified

- `app/lib/BackgroundJob/RecertPeriodCloseJob.php` — daily TimedJob skeleton (RECERT-04); no-op run(); impl 164-05
- `app/lib/BackgroundJob/RetentionJob.php` — daily TimedJob skeleton (DSGVO-03); no-op run(); impl 164-07
- `app/lib/Db/RecertReminder.php` — Entity: certId, thresholdDays, sentAt + addType
- `app/lib/Db/RecertReminderMapper.php` — QBMapper: insertOnce() + findByCertAndThreshold() stubs (throw 164-06)
- `app/lib/Service/RecertReminderService.php` — sendRecertReminders(): int stub (throws 164-06); PHPDoc T-30/T-7 idempotency contract
- `app/lib/Service/RetentionService.php` — anonymizeExpired(): int stub (throws 164-07); PHPDoc crypto-erasure + chain-immutable invariant
- `app/lib/AppInfo/Application.php` — RecertPeriodCloseJob + RetentionJob registered with has()/add() guards
- `app/tests/Unit/BackgroundJob/RecertPeriodCloseJobTest.php` — testDoubleRunSingleRow (RED)
- `app/tests/Unit/Service/RecertReminderServiceTest.php` — testOncePerThreshold (RED)
- `app/tests/Unit/Service/RetentionServiceTest.php` — testAnonymizeKeepsChain (RED)
- `app/tests/unit/RecertL10n.test.js` — 10-key canonical list × 5 langs parity gate (RED, 50 failures confirmed)

## Expected-RED Tests (confirmed RED against stubs — impl waves flip GREEN)

| Test | File | RED mechanism | GREEN trigger |
|------|------|---------------|---------------|
| `testDoubleRunSingleRow` | RecertPeriodCloseJobTest | `closePeriod.expects(once())` — no-op body → 0 calls → FAILS | 164-05: run() queries + closes expired period; 2nd run finds nothing → total 1 call |
| `testOncePerThreshold` | RecertReminderServiceTest | `sendRecertReminders()` throws LogicException → ERRORS; notify `expects(once())` unsatisfied | 164-06: impl; insertOnce UNIQUE guard prevents 2nd notification |
| `testAnonymizeKeepsChain` | RetentionServiceTest | `anonymizeExpired()` throws LogicException → ERRORS | 164-07: impl; cert.userId=null + credentialJson=empty + update() called |
| `RecertL10n parity` | RecertL10n.test.js | 50 FAILURES: 10 keys × 5 langs all absent | 164-07: all 10 keys added to de/en/fr/ru/ar .json + l10n_js_sync.py run |

## Decisions Made

- **testDoubleRunSingleRow assertion is `once()` not `exactly(2)`:** First run closes the expired period (active_period_key set → no longer open). Second run finds no expired-open periods → closePeriod not called. Total: 1 call. `exactly(2)` would pass even in a non-idempotent impl if the job always calls it.
- **No `expectException` in reminder/retention RED tests:** The LogicException propagates → test ERRORS. Wrapping in `expectException` would flip RED→GREEN (established pattern from 164-02 `closePeriod` decision).
- **Certificate::getAnonymizedAt() commented out in test:** Certificate.php does not have `$anonymizedAt` property yet — added by migration only (Version009600 column). PHPStan L5 would flag a call to an undeclared `@method`. 164-07 must add the entity property alongside the impl. The comment documents the requirement explicitly.
- **RecertL10n checks all 5 langs:** DSGVO-05 mandates 5-lang parity. BadgeL10n.test.js only checks de+en — this test does not mirror that limitation.
- **PhpUnitStubs: no changes needed.** 164-01 confirmed all OCP surface (TimedJob, IJobList, ITimeFactory, IConfig, IManager) already present from phases 160/161.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None. All three tasks executed cleanly. RecertL10n.test.js confirmed RED locally (Vitest: 50 FAILURES, 1 PASSED) before commit as required by PROJECT_SPECIFIC_OVERRIDES #1.

## Deferred Items

- **Certificate::getAnonymizedAt() entity property** — needed for the commented-out `testAnonymizeKeepsChain` assertion. Must be added in 164-07 alongside RetentionService impl. The assertion is already in the test, commented with clear instructions.
- **AWO Betriebsvereinbarung (BetrVG §87 Abs.1 Nr.6)** — documented in RetentionService PHPDoc. Non-code item; required before production rollout of RetentionJob.
- **RETENTION_YEARS_DEFAULT = '3' AWO confirmation** — flagged since 164-01; RetentionJob is now registered but the impl (164-07) must confirm the default with AWO/DSGVO context.

## Next Phase Readiness

Wave 2 skeleton contracts are frozen. Impl waves can proceed:
- **164-05** (period-close impl): Run body queries expired assignments and calls `$svc->closePeriod()` — must flip `testDoubleRunSingleRow` GREEN
- **164-06** (reminder impl): `sendRecertReminders()` + `RecertReminderMapper::insertOnce()` impl — must flip `testOncePerThreshold` GREEN; add `recert_reminder` Notifier case
- **164-07** (retention + i18n): `RetentionService::anonymizeExpired()` + all 10 recert l10n keys in 5 langs — must flip `testAnonymizeKeepsChain` and all 50 RecertL10n assertions GREEN; add Certificate::getAnonymizedAt() entity property

Application.php is LOCKED — no impl wave touches it.

---
*Phase: 164-rezertifizierung-retention-i18n*
*Completed: 2026-07-02*
