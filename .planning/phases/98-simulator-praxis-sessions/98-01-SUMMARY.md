---
phase: 98-simulator-praxis-sessions
plan: 01
subsystem: ui
tags: [practicum, simulator, state-machine, localStorage, json-data, spaced-repetition]

requires:
  - phase: none
    provides: "Existing simulator scenario JSON files (firewall, dns, routing, nat, portscan, wireshark, authflow)"
provides:
  - "PracticumEngine class with step progression, persistence, restore"
  - "PRACTICUM_SESSIONS registry mapping 7 simulator types to session arrays"
  - "loadSessionsForSimulator() utility function"
  - "11 practicum sessions across 7 simulators with 42 total steps"
affects: [98-02-PLAN, simulator-ui, practicum-view]

tech-stack:
  added: []
  patterns: [session-state-machine, localStorage-persistence, scenario-cross-reference]

key-files:
  created:
    - app/src/utils/practicumEngine.js
    - app/data/practicum/firewall-sessions.json
    - app/data/practicum/dns-sessions.json
    - app/data/practicum/routing-sessions.json
    - app/data/practicum/nat-sessions.json
    - app/data/practicum/portscan-sessions.json
    - app/data/practicum/wireshark-sessions.json
    - app/data/practicum/authflow-sessions.json
    - app/tests/unit/practicumEngine.test.js
  modified: []

key-decisions:
  - "Engine uses localStorage with lnc-practicum- prefix for session persistence"
  - "Session steps reference existing scenarioIds from *_scenarios.json for zero data duplication"
  - "Score tracked as X/Y string for display-ready format"

patterns-established:
  - "Practicum session JSON schema: id, simulatorType, title, description, steps[] with stepId/context/explanation/scenarioId"
  - "Engine pattern: constructor + restore static for resumable sessions"

requirements-completed: [SIM-01, SIM-03]

duration: 4min
completed: 2026-03-28
---

# Phase 98 Plan 01: Practicum Engine + Session Data Summary

**PracticumEngine state machine with localStorage persistence and 11 guided practice sessions (42 steps) across all 7 simulators, referencing existing scenario data**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-28T12:23:41Z
- **Completed:** 2026-03-28T12:27:56Z
- **Tasks:** 2
- **Files modified:** 9

## Accomplishments
- PracticumEngine class with full lifecycle: step progression, result recording, summary, persist/restore/reset
- 15 unit tests covering engine state machine, localStorage round-trip, edge cases
- 11 practicum sessions authored in German with real-world scenario titles and explanatory context
- All scenarioIds cross-validated against source scenario files (zero broken references)

## Task Commits

Each task was committed atomically:

1. **Task 1: Practicum Engine with localStorage persistence (TDD)** - `cc7749c` (feat)
2. **Task 2: Author Practicum Session JSON data for all 7 simulators** - `3a5658e` (feat)

## Files Created/Modified
- `app/src/utils/practicumEngine.js` - Session state machine with persist/restore/reset
- `app/tests/unit/practicumEngine.test.js` - 15 unit tests for engine lifecycle
- `app/data/practicum/firewall-sessions.json` - 2 sessions, 8 steps (Webserver absichern, DMZ aufbauen)
- `app/data/practicum/dns-sessions.json` - 2 sessions, 8 steps (Namensaufloesung, Troubleshooting)
- `app/data/practicum/routing-sessions.json` - 2 sessions, 8 steps (Netzwerk-Pfade, Multi-Hop)
- `app/data/practicum/nat-sessions.json` - 1 session, 5 steps (Buero-Netzwerk NAT-Typen)
- `app/data/practicum/portscan-sessions.json` - 1 session, 4 steps (Server-Sicherheit pruefen)
- `app/data/practicum/wireshark-sessions.json` - 1 session, 5 steps (Traffic analysieren)
- `app/data/practicum/authflow-sessions.json` - 1 session, 4 steps (802.1X Authentifizierung)

## Decisions Made
- Engine uses `lnc-practicum-` localStorage prefix to avoid collisions with other app state
- Session steps reference existing scenarioIds from source scenario files (zero data duplication)
- Score stored as "X/Y" string for direct display in UI without formatting
- Stub JSON files created before engine implementation to allow TDD import resolution

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Engine and data ready for Plan 02 (UI integration)
- PRACTICUM_SESSIONS registry exports all 7 types for direct use in components
- loadSessionsForSimulator() provides the primary data access API

## Self-Check: PASSED

All 9 files verified on disk. Both task commits (cc7749c, 3a5658e) confirmed in git log.

---
*Phase: 98-simulator-praxis-sessions*
*Completed: 2026-03-28*
