---
phase: 80-simulatorshell-wiring
plan: 02
subsystem: ui
tags: [vue2, dynamic-component, simulator, campaign-engine, tdd]

requires:
  - phase: none
    provides: 7 existing simulator components + scenario JSON files
provides:
  - SimulatorShell.vue wrapper component for all 7 network simulators
  - simulatorShellLogic.js with SIMULATOR_MAP, normalizeResult, resolveScenario
  - Uniform @complete(passed, score, rawResult) event interface
affects: [80-03-abenteuer-wiring, 81-quest-map, 83-campaign-content]

tech-stack:
  added: []
  patterns: [extract-logic-for-testability, dynamic-component-map, result-normalization]

key-files:
  created:
    - app/src/components/SimulatorShell.vue
    - app/src/utils/simulatorShellLogic.js
    - app/tests/unit/SimulatorShell.test.js
  modified: []

key-decisions:
  - "Extracted logic into simulatorShellLogic.js for testability (vitest has no vue plugin)"
  - "normalizeResult returns structured object instead of direct $emit for pure-function testing"

patterns-established:
  - "Extract Vue component logic into pure JS utils for Vitest testing"
  - "SIMULATOR_MAP pattern: type-string to async-import-factory mapping"
  - "Result normalization: divergent event shapes to uniform (passed, score, rawResult)"

requirements-completed: [SIM-04, SIM-05]

duration: 3min
completed: 2026-03-25
---

# Phase 80 Plan 02: SimulatorShell Summary

**Thin Vue 2.7 wrapper mapping 7 simulator types to async components with unified @complete event normalization**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-25T10:27:54Z
- **Completed:** 2026-03-25T10:31:00Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- SIMULATOR_MAP with 7 async component factories (firewall, dns, routing, nat, portscan, wireshark, authflow)
- Result normalization: all divergent @result shapes (correct/passed/kind) unified to @complete(passed, score, rawResult)
- DNS kind='lookup' events correctly ignored (no @complete emitted)
- Scenario resolution with override precedence over ID lookup
- 13 unit tests covering all map keys, normalization cases, and scenario resolution

## Task Commits

Each task was committed atomically:

1. **Task 1: SimulatorShell.test.js (RED)** - `0dbc2ec` (test)
2. **Task 2: SimulatorShell.vue implementation (GREEN)** - `4a2b65f` (feat)

## Files Created/Modified
- `app/src/components/SimulatorShell.vue` - Thin wrapper with dynamic component loading via SIMULATOR_MAP
- `app/src/utils/simulatorShellLogic.js` - Extracted pure logic: SIMULATOR_MAP, SCENARIOS, normalizeResult, resolveScenario
- `app/tests/unit/SimulatorShell.test.js` - 13 unit tests for map, normalization, scenario resolution

## Decisions Made
- Extracted all testable logic into `simulatorShellLogic.js` because vitest has no vue plugin configured -- testing .vue files directly fails with parse errors. This keeps the component thin (delegates to pure functions) and tests fast.
- `normalizeResult` returns a structured object `{ passed, score, rawResult }` or null (for ignored events) instead of directly calling `$emit`, enabling pure-function testing without Vue mocks.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Extracted logic to JS module for testability**
- **Found during:** Task 1 / Task 2 transition
- **Issue:** Vitest cannot parse .vue SFC files (no @vitejs/plugin-vue installed). Tests importing SimulatorShell.vue directly fail.
- **Fix:** Created `simulatorShellLogic.js` with all testable logic (SIMULATOR_MAP, normalizeResult, resolveScenario). SimulatorShell.vue imports and delegates to these. Tests import the JS module directly.
- **Files modified:** app/src/utils/simulatorShellLogic.js (new), app/src/components/SimulatorShell.vue (refactored), app/tests/unit/SimulatorShell.test.js (updated imports)
- **Verification:** 13/13 tests green, ESLint 0 errors
- **Committed in:** 4a2b65f (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Cleaner architecture -- logic extraction is a net improvement. No scope creep.

## Issues Encountered
None beyond the deviation above.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- SimulatorShell ready for integration in AbenteuerMode.vue (Plan 80-03)
- All 7 simulator types mapped and tested
- `@complete(passed, score, rawResult)` contract established for graph-traverse wiring

---
*Phase: 80-simulatorshell-wiring*
*Completed: 2026-03-25*
