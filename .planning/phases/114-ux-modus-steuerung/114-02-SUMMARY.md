---
phase: 114-ux-modus-steuerung
plan: 02
subsystem: ui
tags: [vue2, lernraum, smart-queue, leitner, vitest, tdd]

# Dependency graph
requires:
  - phase: 114-01
    provides: "Training tab visibility toggle (modeEnabled, mode_config) + CourseTabLernraum test infrastructure"
provides:
  - "Smart Queue hero card in Lernraum student view"
  - "fetchQueueCount() method on CourseTabLernraum — async, non-fatal, mirrors StudentDashboard pattern"
  - "queueCount + loadingQueueCount data properties"
  - "Tests H/I/J: hero card render condition, endpoint call, instructor exclusion"
affects: [115, 116, 117, CourseTabLernraum, App.vue openSmartQueue handler chain]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "fetchQueueCount mirrors StudentDashboard.vue:181 — axios.get(generateUrl), try/finally, non-fatal"
    - "Hero card uses v-if=!selectedLearningPool inside isStudentLearningTab branch — disappears on pool open"
    - "mounted() lifecycle hook gates fetchQueueCount behind !isInstructor to avoid unnecessary API calls"

key-files:
  created: []
  modified:
    - app/src/components/CourseTabLernraum.vue
    - app/tests/unit/CourseTabLernraum.test.js

key-decisions:
  - "Hero card placement: above pool list, inside student branch, with v-if=!selectedLearningPool — matches PoolList.vue pattern"
  - "fetchQueueCount is non-fatal: count stays 0 on error, consistent with StudentDashboard.vue behavior"
  - "Label 'fällig — alle Kurse' makes cross-course scope explicit (matches existing PoolList.vue + StudentDashboard.vue wording)"

patterns-established:
  - "TDD RED→GREEN: tests added before implementation, verified fail before fix"

requirements-completed:
  - UX-04

# Metrics
duration: 6min
completed: 2026-03-30
---

# Phase 114 Plan 02: Smart Queue Hero Card Summary

**Smart Queue hero card in Lernraum student view fetches cross-course due-card count via GET /leitner/queue/count and emits openSmartQueue on click**

## Performance

- **Duration:** 6 min
- **Started:** 2026-03-30T08:17:23Z
- **Completed:** 2026-03-30T08:23:24Z
- **Tasks:** 2 auto-tasks completed; Task 3 is checkpoint:human-verify (awaiting)
- **Files modified:** 2

## Accomplishments
- Added `queueCount` data property and `fetchQueueCount()` async method to CourseTabLernraum — modeled exactly on StudentDashboard.vue:181
- Inserted Smart Queue hero card above pool list in the student branch; card hides when a pool is selected
- Tests H/I/J all green; full suite 734/734 passing; ESLint 0 errors; PHPStan Level 5 clean; JS deployed

## Task Commits

Each task was committed atomically:

1. **Task 1: Add fetchQueueCount + Smart Queue hero card** - `ff7c2fc` (feat)
2. **Task 2: Gate 1 full check + deploy** - no separate commit (verification only, no files changed)

## Files Created/Modified
- `app/src/components/CourseTabLernraum.vue` - Added queueCount/loadingQueueCount data, fetchQueueCount() method, mounted() hook, smart-queue-hero card template
- `app/tests/unit/CourseTabLernraum.test.js` - Added describe block "Smart Queue hero card (UX-04)" with tests H, I, J

## Decisions Made
- Hero card placed above pool list inside the existing student branch (`isStudentLearningTab`) with `v-if="!selectedLearningPool"` — card disappears once a pool is opened, consistent with expected UX flow
- Non-fatal error handling: queueCount stays 0 on API failure — consistent with StudentDashboard.vue and PoolList.vue patterns

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

API smoke test returned pre-existing 401/429 errors on unrelated endpoints (bruteforce / auth). The queue count endpoint itself confirmed reachable and returning proper JSON (auth required). Not caused by Plan 02 changes.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Both Plan 01 (Training tab visibility) and Plan 02 (Smart Queue hero card) are implemented and deployed
- Human verification checkpoint (Task 3) required before phase 114 can be marked complete
- Phase 115 (Wahr/Falsch-Migration) can start independently after checkpoint approval

---
*Phase: 114-ux-modus-steuerung*
*Completed: 2026-03-30*
