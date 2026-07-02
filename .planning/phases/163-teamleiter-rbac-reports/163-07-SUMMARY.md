---
phase: 163-teamleiter-rbac-reports
plan: "07"
subsystem: rbac-frontend
tags: [rbac, team-lead, vue3, options-api, vitest, i18n, group-report, reminder, tdd]

requires:
  - phase: 163-05
    provides: "myTeamLeadScopes GET + group-report GET endpoints; row DTO: displayName, uid, status, due_date?, expires_at?"
  - phase: 163-06
    provides: "remindMember POST endpoint; 2nd-IDOR guard; generic-403 body"

provides:
  - "TeamLeadDashboard.vue: Options-API Vue 3 component, self-hides on empty scopes, renders overdue/missing + expirations + reminder button"
  - "16 Vitest tests GREEN: scope-gate, report rows, reminder POST payload, 403 generic error"
  - "17 DE/EN i18n keys (Du-form German source, English translations)"
  - "App.vue: TeamLeadDashboard mounted in courses view, v-if=!selectedCourse && !selectedStudent"

affects: []

tech-stack:
  added: []
  patterns:
    - "self-gating component: hasScopes computed from myTeamLeadScopes → v-if hides entire component for non-leads"
    - "Vue 3 reactivity for reminderStates: object spread {...states, [uid]: value} instead of Vue 2 $set"
    - "no-mount test pattern: component definition tested via createInstance() merging data + computed getters + bound methods"
    - "generic-403: reminderError set to fixed string, never echoes server body (no membership oracle)"
    - "no email field: DTO carries displayName + uid only; test asserts row has no email property"

key-files:
  created:
    - app/src/components/TeamLeadDashboard.vue
    - app/tests/unit/TeamLeadDashboard.test.js
  modified:
    - app/l10n/de.json
    - app/l10n/en.json
    - app/src/App.vue

key-decisions:
  - "test file placed in app/tests/unit/ (vitest.config.js glob: tests/unit/**/*.test.js) — plan listed src/components/ which would never execute"
  - "App.vue integration: TeamLeadDashboard rendered outside the v-else-if chain with v-if=!selectedCourse && !selectedStudent — component self-gates via hasScopes, safe to always render in courses view"
  - "Vue 3 reactivity: object spread for reminderStates mutation; $set removed (Vue 2-only)"
  - "globalThis.t set in test file header (not @nextcloud/l10n mock) — consistent with CourseTabTeilnehmer.test.js, CourseDetail.test.js patterns"

requirements-completed: [RBAC-04]

duration: ~20min
completed: 2026-07-02T11:57:00Z
---

# Phase 163 Plan 07: RBAC-04 Team-Lead Dashboard Summary

**Options-API TeamLeadDashboard.vue self-gated by myTeamLeadScopes, rendering overdue/missing members + cert expirations + mandatory reminder button wired to the 163-06 backend — 16 Vitest tests GREEN, ESLint 0 errors**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-07-02T11:36:43Z
- **Completed:** 2026-07-02T11:57:00Z
- **Tasks:** 1/1 auto (Task 2 is human-verify checkpoint)
- **Files modified:** 5

## Accomplishments

### Task 1: TeamLeadDashboard.vue + Vitest (TDD)

**Component behavior:**
- On mount: GET `/api/my-team-lead-scopes` → `hasScopes` computed gates visibility; component renders nothing if empty (no empty card)
- With scopes: scope selector (multi-group) or inline label (single-group); fetches `/api/courses/{courseId}/group-report?group_id=..&expiring_days=30`
- Overdue/missing panel: `overdueOrMissing` computed filters `status === 'overdue' || 'missing'`; each row shows displayName + status badge + due_date + "Erinnerung senden" button
- Upcoming expirations panel: `upcomingExpirations` computed = `status === 'passed' && !!expires_at`
- Reminder button: POST `/api/courses/{courseId}/group-report/remind` with `{group_id, target_user_id: row.uid}`; 200 → `reminderStates[uid]='sent'`; 403 → generic `reminderError` string (never echoes server body)

**Security properties:**
- No email field: DTO carries `displayName` + `uid` only; test asserts `row` has no `email` property
- Generic-403: `reminderError` set to fixed translation string; server-body content never used
- `hasScopes` computed is UX affordance — server enforces all authz independently

**Tests (16 passing):**
- Component definition: no setup(), name, data.scopes=[], mounted hook
- hasScopes: false on empty scopes, true on non-empty
- fetchScopes: populates scopes, leaves empty on empty response, sets error on failure
- fetchReport: populates reportRows, overdueOrMissing filter, upcomingExpirations filter, no-email assertion
- sendReminder: POSTs correct URL+payload, 200→sent state, 403→generic error no leak

**i18n:** 17 keys added to de.json (identity) and en.json (German→English); sorted alphabetically

**App.vue wiring:**
- Import + components registration of TeamLeadDashboard
- `<TeamLeadDashboard v-if="!selectedCourse && !selectedStudent" />` placed after CourseDetail in courses template — outside v-else-if chain; self-hides for non-leads

## Task Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1    | `87baa39` | TeamLeadDashboard.vue + Vitest + DE/EN i18n + App.vue wiring |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] $set replaced with Vue 3 object spread**
- **Found during:** Task 1 (first GREEN run attempt)
- **Issue:** `this.$set(this.reminderStates, uid, value)` is Vue 2 instance method — not available in the no-mount test environment (or Vue 3 runtime without compatibility mode)
- **Fix:** `this.reminderStates = { ...this.reminderStates, [uid]: value }` — Vue 3 reactivity handles plain object reassignment
- **Files modified:** app/src/components/TeamLeadDashboard.vue
- **Committed in:** `87baa39` (merged into Task 1 commit)

**2. [Rule 3 - Blocking] Test file path corrected to tests/unit/ + globalThis.t added**
- **Found during:** Pre-implementation advisor review
- **Issue 1:** Plan listed `app/src/components/TeamLeadDashboard.test.js` but `vitest.config.js` glob is `tests/unit/**/*.test.js` — file in src/components/ would never execute
- **Issue 2:** Component uses global `t()` (Nextcloud runtime injection); `@nextcloud/l10n` mock only covers the imported `translate` symbol; bare `t()` in methods caused `ReferenceError: t is not defined`
- **Fix 1:** Test placed at `app/tests/unit/TeamLeadDashboard.test.js`
- **Fix 2:** Added `globalThis.t = (app, text) => text` (consistent with CourseTabTeilnehmer.test.js, CourseDetail.test.js)
- **Files modified:** app/tests/unit/TeamLeadDashboard.test.js
- **Committed in:** `87baa39`

**3. [Rule 2 - Missing] App.vue wiring added (plan omitted parent file)**
- **Found during:** Advisor review (Task 2 would fail: no parent mounts the component)
- **Issue:** Plan `files_modified` listed only `TeamLeadDashboard.vue` — without wiring it in App.vue, the checkpoint's "confirm dashboard section appears" step would always fail
- **Fix:** Import + components registration + `<TeamLeadDashboard v-if="!selectedCourse && !selectedStudent" />` in courses template
- **Files modified:** app/src/App.vue
- **Committed in:** `87baa39`

---

**Total deviations:** 3 auto-fixed (1 Rule 1 bug, 1 Rule 3 blocking, 1 Rule 2 missing critical)
**Impact on plan:** All fixes required for correctness. No scope creep.

## Self-Check

- [x] app/src/components/TeamLeadDashboard.vue — FOUND (Vue 3 Options API, self-hides on empty scopes)
- [x] app/tests/unit/TeamLeadDashboard.test.js — FOUND (16 tests in tests/unit/)
- [x] app/l10n/de.json — FOUND (17 new keys added)
- [x] app/l10n/en.json — FOUND (17 new keys added)
- [x] app/src/App.vue — FOUND (TeamLeadDashboard imported + registered + mounted in courses view)
- [x] Commit 87baa39 — FOUND (feat(163-07): TeamLeadDashboard.vue + Vitest + DE/EN i18n + App.vue wiring)
- [x] Vitest 16/16 GREEN — verified (`npm run test -- TeamLeadDashboard`)
- [x] ESLint 0 errors — verified (src/components/TeamLeadDashboard.vue, src/App.vue, tests/unit/TeamLeadDashboard.test.js)
- [x] No email field in DTO — test asserts `row` has no `email` property; component template never binds email

## Self-Check: PASSED
