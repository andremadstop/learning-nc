---
phase: 161-audit-hardening
plan: 05
subsystem: ui
tags: [audit, liveness, admin-settings, vue, phpunit-stubs, vitest, ed25519-checkpoint]

# Dependency graph
requires:
  - phase: 161-01
    provides: AuditCheckpointService::getLivenessStatus() (5-key liveness array)
  - phase: 161-02
    provides: forgejo_anchor_enabled / last_anchor_status appconfig keys consumed by getLivenessStatus()
provides:
  - SettingsController::getAdmin() now merges 5 audit_* liveness keys (try/catch fresh-install-safe)
  - AdminSettings.vue "Audit-Trail — Liveness" widget (last checkpoint, events-since, anchor status, overdue banner)
  - Vitest coverage for the overdue-flag mapping (true/false/omitted-keys)
affects: [162-video-gating, 163-teamleiter-reports, admin-settings-ui]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Defensive REST merge: getLivenessStatus() wrapped in try/catch → neutral state so a pre-migration install never 500s the admin page"
    - "Vue liveness widget consumes existing getAdmin() payload (no new route) — one @AdminRequired surface"
    - "Vitest createInstance convention (no @vue/test-utils) — assert load() payload→state mapping that drives the v-if banner"

key-files:
  created:
    - app/tests/unit/AdminSettingsLiveness.test.js
  modified:
    - app/lib/Controller/SettingsController.php
    - app/src/components/AdminSettings.vue

key-decisions:
  - "Vitest asserts the reactive auditIsOverdue flag (sole driver of the .audit-overdue-warning v-if) instead of DOM-mounting — @vue/test-utils is not installed and the repo convention (GlobalFeed/StudentDashboard) forbids it"
  - "AuditCheckpointService injected as 6th ctor arg before ?string \\$userId; no existing PHPUnit test instantiates SettingsController, so no test-constructor fix and no PhpUnitStubs additions were needed"

patterns-established:
  - "Liveness widget: server computes is_overdue (8-day threshold in AuditCheckpointService), frontend only renders the flag"

requirements-completed: [AUDIT-08]

# Metrics
duration: 18min
completed: 2026-07-01
---

# Phase 161 Plan 05: Audit-Liveness Admin Widget Summary

**Admin settings now surface tamper-evidence health at a glance — last checkpoint, events-since-checkpoint, Forgejo-anchor status, and a red overdue banner when the checkpoint is > 8 days stale — fed by a fresh-install-safe getAdmin() merge of AuditCheckpointService::getLivenessStatus().**

## Performance

- **Duration:** ~18 min
- **Started:** 2026-07-01T18:30:00Z
- **Completed:** 2026-07-01T18:40:00Z
- **Tasks:** 2
- **Files modified:** 3 (2 modified, 1 created)

## Accomplishments
- `SettingsController::getAdmin()` injects `AuditCheckpointService` and merges 5 `audit_*` keys, wrapped in a `try/catch` neutral fallback so a pre-migration fresh install (no `learning_audit_events` table) returns 200, never 500.
- `AdminSettings.vue` renders a new "Audit-Trail — Liveness" section: last-checkpoint date (or "Noch kein Checkpoint"), events-since count, tri-state Forgejo-anchor row, and an `is_overdue`-driven warning banner.
- `AdminSettingsLiveness.test.js` proves the AUDIT-08 truth: overdue payload → `auditIsOverdue=true` (banner shown) + events count mapped; fresh payload → false (hidden); omitted keys → neutral `?? ` fallbacks.

## Task Commits

Each task was committed atomically:

1. **Task 1: Merge liveness into SettingsController::getAdmin()** - `47e7584` (feat)
2. **Task 2: Audit-Liveness widget in AdminSettings.vue + Vitest** - `64f4f82` (feat)

## Files Created/Modified
- `app/lib/Controller/SettingsController.php` - inject AuditCheckpointService; getAdmin() merges getLivenessStatus() (5 audit_* keys) behind a try/catch neutral fallback
- `app/src/components/AdminSettings.vue` - liveness data() state, load() key mapping, "Audit-Trail — Liveness" template section + overdue banner + scoped styles
- `app/tests/unit/AdminSettingsLiveness.test.js` - Vitest (createInstance convention): overdue true/false/omitted-keys assertions

## Decisions Made
- **Test idiom:** Assert the reactive `auditIsOverdue` flag (the sole driver of the `.audit-overdue-warning` `v-if`) via the repo's `createInstance` pattern rather than the plan's `wrapper.find(...)` DOM idiom — `@vue/test-utils` is not installed and every sibling component test (GlobalFeed, StudentDashboard) uses definition-only testing. Authorized by the plan/override "matching repo's vitest conventions". Advisor confirmed.
- **Constructor placement:** `AuditCheckpointService` inserted before the trailing `?string $userId` (NC DI resolves positionally by type for services and injects `userId` last). Verified no existing PHPUnit test constructs `SettingsController` (`grep "new SettingsController" app/tests/` → none), so the plan's "no PhpUnitStubs / no test-ctor fix" note holds.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Wrapped liveness `<tr>` rows in `<tbody>`**
- **Found during:** Task 2 (widget template)
- **Issue:** Bare `<tr>` directly under `<table>` triggered a `vite:vue` compiler warning ("`<tr>` cannot be child of `<table>` ... can cause hydration errors").
- **Fix:** Wrapped the three liveness rows in a `<tbody>` (matching the existing `.audit-table` structure in the same component).
- **Files modified:** app/src/components/AdminSettings.vue
- **Verification:** `npx vitest run tests/unit/AdminSettingsLiveness.test.js` — warning gone, 4/4 pass.
- **Committed in:** `64f4f82` (part of Task 2 commit)

**2. [Test idiom adaptation] createInstance instead of DOM-mount** — see Decisions Made. Not a defect fix; a convention-mandated substitution for the plan's `wrapper.find()` example.

---

**Total deviations:** 1 auto-fixed (1 bug) + 1 convention-mandated test-idiom substitution
**Impact on plan:** No scope change; all `must_haves` truths + artifacts satisfied. No new dependencies.

## Issues Encountered
- Full-suite Vitest shows **1 pre-existing unrelated failing suite**: `tests/unit/CourseTabTeilnehmer.test.js` fails to load with `TypeError: Unknown file extension ".css"` (`@nextcloud/vue` NcDialog CSS) — an environment/import-chain issue in a file this plan does not touch. Out of scope per SCOPE BOUNDARY; not fixed. All 1123 individual tests pass; my two files are green.

## Verification Status (local, JS-only)
- ✅ Vitest `AdminSettingsLiveness.test.js` — 4/4 pass, no `<tr>` warning
- ✅ ESLint `AdminSettings.vue` + test — 0 errors
- ✅ Full Vitest suite — 1123 tests pass (1 unrelated CSS-import suite fails, pre-existing)
- ⏳ **Deferred to central wave gate (no local PHP):** PHPStan L5 on `SettingsController.php`, PHPUnit, and the live admin-settings check. Orchestrator runs these.

## Next Phase Readiness
- AUDIT-08 complete; audit liveness is now admin-visible without SSH.
- Concern for the central gate: PHPStan L5 must confirm the 7-arg `getAdmin` constructor + `$liveness` array typing; the 5-key shape matches `getLivenessStatus()`'s documented return.

## Self-Check: PASSED

- All 3 code/test files present on disk.
- Both task commits present: `47e7584`, `64f4f82`.

---
*Phase: 161-audit-hardening*
*Completed: 2026-07-01*
