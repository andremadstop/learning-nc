---
phase: 16-session-robustheit
plan: "01"
subsystem: frontend
tags: [multiplayer, ux, resilience, localStorage, disconnect]
dependency_graph:
  requires: []
  provides: [session-robustheit-ux]
  affects: [DuelMode.vue, GameshowMode.vue]
tech_stack:
  added: []
  patterns: [localStorage session recovery, consecutive-poll-error disconnect detection]
key_files:
  created: []
  modified:
    - app/src/components/DuelMode.vue
    - app/src/components/GameshowMode.vue
decisions:
  - "Abort button uses type=tertiary (not error) to reduce alarm UI for intentional exits"
  - "Disconnect threshold is 60 poll failures (60 * 500ms = 30s) matching backend TIMEOUT_SECONDS"
  - "localStorage recovery skips history fetch when recovering a live session to minimize delay"
metrics:
  duration: "~20 min"
  completed: "2026-03-21"
  tasks_completed: 2
  files_modified: 2
---

# Phase 16 Plan 01: Session Robustheit — UX Summary

**One-liner:** Frontend session resilience for DuelMode and GameshowMode: abort button, 30s disconnect detection, and localStorage-based page-reload recovery.

## What Was Built

Four session robustness features added to both multiplayer components:

### ROBUST-01: Abort Button in Question Phase
- Both `DuelMode.vue` and `GameshowMode.vue` now show an "Abbrechen" button (type=tertiary) below the answer grid during the question phase
- Clicking calls `cancelDuel()` / `cancelGame()` which stops polling, resets state, emits `back`

### ROBUST-02: End-Screen Navigation
- Already satisfied: DuelMode has Rematch + Zurück; GameshowMode has Neue Runde + Zurueck
- No changes required

### ROBUST-03: Disconnect Detection Overlay
- Added `consecutivePollErrors` counter and `disconnectDetected` boolean to data() in both components
- `pollState()` increments counter on catch, resets to 0 on success
- After 60 failed polls (30 seconds), `disconnectDetected = true`
- A `NcNoteCard type="warning"` overlay appears in the question phase with a "Zurueck" button
- Both fields reset to 0/false in `resetRoundState()`

### ROBUST-04: localStorage Session Recovery on Page Reload
- **DuelMode:** On mount (when no `presetDuelCode`), reads `learning_duel_session` from localStorage and calls `loadExistingDuel(code)` to restore. Saves key in `createDuel()`, `joinDuel()`, and `loadExistingDuel()` (only for non-finished/expired sessions). Clears in `cancelDuel()` and in `applyStateTransitions()` when status is finished/expired.
- **GameshowMode:** On mount, reads `learning_gameshow_session` and calls new `recoverSession(code)` method. `recoverSession()` checks server state: if still waiting/active, restores phase+state+polling; otherwise clears localStorage and falls through to `fetchHistory()`. Saves key in `createGame()` and `joinGame()`. Clears in `cancelGame()` and in `applyStateTransitions()` when status is finished/expired.

## Verification

```
grep -n "question-abort-area" DuelMode.vue GameshowMode.vue     → 4 matches (2 template + 2 CSS)
localStorage refs across both files                              → 17 matches
consecutivePollErrors + disconnectDetected refs                  → 18 matches
npm run build                                                    → compiled with 2 warnings (size, pre-existing)
```

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

- SUMMARY.md: FOUND at `.planning/phases/16-session-robustheit/16-01-SUMMARY.md`
- Commit fe52b60: feat(16-01): DuelMode changes — FOUND
- Commit 4e16ed2: feat(16-01): GameshowMode changes — FOUND
