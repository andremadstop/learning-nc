---
phase: 16-session-robustheit
plan: "02"
subsystem: api
tags: [php, session-management, duel, gameshow, timeout]

# Dependency graph
requires:
  - phase: 16-session-robustheit
    provides: Plan 01 context (session robustness phase setup)
provides:
  - Stale-session auto-expiry (5 min all-player inactivity) in DuelService
  - Stale-session auto-expiry (5 min all-player inactivity) in GameshowService
affects:
  - arena-feature phases
  - session monitoring
  - any phase touching DuelService or GameshowService

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "STALE_SESSION_TIMEOUT constant pattern: distinct from TIMEOUT_SECONDS (one-sided forfeit) vs STALE (both inactive, silent expiry)"
    - "checkStaleSession() called before last_poll update to see old values for both players"

key-files:
  created: []
  modified:
    - app/lib/Service/DuelService.php
    - app/lib/Service/GameshowService.php

key-decisions:
  - "Stale check fires BEFORE updating current user's last_poll so both old values are visible"
  - "Silent expiry for stale sessions: no forfeit bonus since we cannot determine who left first"
  - "Brand-new GameshowSessions protected: anyPolled guard prevents expiring sessions where no one has polled yet"
  - "GameshowService: only 'active' sessions checked (not 'waiting' lobby sessions)"
  - "DuelService: 'ready' and 'active' sessions both checked (consistent with existing TIMEOUT_SECONDS scope)"

patterns-established:
  - "STALE_SESSION_TIMEOUT = 300 constant for all-inactive session cleanup"
  - "checkStaleSession() returns early if session.status not in target set"
  - "anyPolled guard before expiring to avoid false positives on brand-new sessions"

requirements-completed:
  - ROBUST-05

# Metrics
duration: 4min
completed: 2026-03-21
---

# Phase 16 Plan 02: Session-Robustheit — Stale Session Cleanup Summary

**STALE_SESSION_TIMEOUT = 300s constant and checkStaleSession() added to DuelService and GameshowService: both-players-inactive sessions auto-expire after 5 minutes without affecting existing one-sided forfeit logic**

## Performance

- **Duration:** ~4 min
- **Started:** 2026-03-21T02:44:00Z
- **Completed:** 2026-03-21T02:46:35Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- DuelService: `STALE_SESSION_TIMEOUT = 300` constant and `checkStaleSession()` private method; called in `getState()` before last_poll update
- GameshowService: same constant and method pattern; guards brand-new sessions with `anyPolled` check; re-fetches players on stale-expire path
- PHP syntax valid on both files (verified in Docker container)
- Existing 30s one-sided forfeit logic (TIMEOUT_SECONDS) untouched in both services

## Task Commits

1. **Task 1: DuelService — Stale Session Cleanup** - `999fcc3` (feat)
2. **Task 2: GameshowService — Stale Session Cleanup** - `efac041` (feat)

**Plan metadata:** _(final metadata commit follows)_

## Files Created/Modified
- `app/lib/Service/DuelService.php` — Added STALE_SESSION_TIMEOUT, checkStaleSession(), call in getState()
- `app/lib/Service/GameshowService.php` — Added STALE_SESSION_TIMEOUT, checkStaleSession(), call in getState()

## Decisions Made
- Stale check fires BEFORE updating current user's last_poll so all players' previous timestamps are visible
- Silent expiry (no score bonus) for stale sessions: we don't know who left first, no forfeit awarded
- GameshowService needs `anyPolled` guard: creator's last_poll is set at session creation (line 103), but all others join later — without the guard a session with only the creator who never polled again could be falsely expired

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Both services now handle the all-inactive edge case
- Session status 'expired' is the canonical end state for abandoned sessions
- Ready for any further Arena/session-robustheit work in phase 16

---
*Phase: 16-session-robustheit*
*Completed: 2026-03-21*
