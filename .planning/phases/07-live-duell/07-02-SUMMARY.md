---
phase: 07-live-duell
plan: "02"
subsystem: ui
tags: [vue, vue2, polling, duel, real-time, nextcloud]

requires:
  - phase: 07-01
    provides: DuelController + DuelService API (create/join/ready/state/answer/rematch), DB schema

provides:
  - DuelMode.vue: complete duel UI covering join, lobby, question, feedback, finished, expired phases
  - 500ms short polling via setInterval/clearInterval with proper destroyed() cleanup
  - Score display via my_role resolution (creator vs opponent)
  - lastPoints delta computed before/after POST answer
  - Rematch flow: POST rematch -> new code -> lobby reset

affects:
  - 07-03 (App.vue integration — DuelMode is now importable and ready to wire in)

tech-stack:
  added: []
  patterns:
    - "Short polling pattern: setInterval 500ms in startPolling(), clearInterval in stopPolling(), called in destroyed()"
    - "Score role resolution: myScore/opponentScore computed from my_role === 'creator' branch"
    - "lastPoints delta: capture scoreBeforeAnswer immediately before POST, subtract from myScore after response"
    - "Phase state machine: join -> lobby -> question -> feedback -> question (loop) -> finished/expired"
    - "applyStateTransitions() called from both pollState() and setReady() to unify transition logic"

key-files:
  created:
    - app/src/components/DuelMode.vue
  modified: []

key-decisions:
  - "applyStateTransitions() extracted as shared helper called from both pollState() and direct API responses — avoids duplicating phase-switch logic"
  - "hasAnswered=true after onAnswer() shows waiting overlay on question card — no separate waiting phase"
  - "feedback phase auto-advances after 1500ms via setTimeout — no user interaction needed"
  - "Polling does not restart during feedback phase; the 1500ms timeout resets hasAnswered then phase='question', and polling continues"
  - "readyClicked boolean disables ready button after first click to prevent double-submit; reset on cancelDuel/rematch"
  - "DuelMode.vue uses only already-imported NC Vue components (NcButton, NcNoteCard, NcProgressBar) — no new dependencies"

patterns-established:
  - "Polling pattern: startPolling/stopPolling wrapping setInterval, called from create/join/rematch and cleanup in destroyed()"
  - "Phase machine via single `phase` data string, computed properties read from duelState"

requirements-completed: [DUEL-02, DUEL-03, DUEL-04]

duration: 4min
completed: 2026-03-18
---

# Phase 07 Plan 02: DuelMode.vue Summary

**Vue 2.7 SFC for Live Duell with 6 UI phases (join/lobby/question/feedback/finished/expired), 500ms short polling, my_role-based score resolution, and lastPoints delta feedback**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-18T11:09:32Z
- **Completed:** 2026-03-18T11:12:58Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments

- Created DuelMode.vue (975 lines) implementing the complete duel UI state machine
- 500ms polling loop with startPolling/stopPolling and proper cleanup in destroyed()
- Score display correctly resolves my_role to show personal vs opponent scores
- lastPoints computed as myScore delta (captured before POST, read after response)
- Rematch flow creates new duel session and transitions back to lobby
- npm run build completes without errors (build verified on learning-dev)
- JS bundle deployed to Docker container on learning-dev

## Task Commits

1. **Task 1: DuelMode.vue — all UI phases** - `e293914` (feat)

## Files Created/Modified

- `app/src/components/DuelMode.vue` - Complete duel UI component with all 6 phases, polling, scoring

## Decisions Made

- `applyStateTransitions()` extracted as a shared helper to unify phase-switch logic called from both polling and direct API responses — avoids code duplication and ensures consistent behavior
- `hasAnswered=true` after clicking Wahr/Falsch shows a waiting overlay on the question card rather than switching to a separate waiting phase — simpler state machine
- Feedback phase uses a 1500ms setTimeout to auto-advance — no user click needed, the poll loop continues running throughout
- `readyClicked` boolean tracks ready-button state to prevent double-submit; reset on cancel/rematch
- No new npm dependencies — uses NcButton, NcNoteCard, NcProgressBar already imported in SwipeMode

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- DuelMode.vue is complete and importable
- Plan 03 can now wire DuelMode into App.vue (import + route registration + mode button in PoolList)
- No blockers

---
*Phase: 07-live-duell*
*Completed: 2026-03-18*
