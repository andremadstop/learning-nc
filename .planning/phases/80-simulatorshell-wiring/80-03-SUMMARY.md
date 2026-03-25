---
phase: 80-simulatorshell-wiring
plan: 03
subsystem: ui
tags: [vue2, graph-mode, campaign-engine, simulator, tdd, stateBag, reactivity]

requires:
  - phase: 80-01
    provides: beforeDestroy hooks on all 7 simulator components
  - phase: 80-02
    provides: SimulatorShell.vue wrapper with unified @complete event
provides:
  - "AbenteuerMode.vue graph-mode wiring: isGraphMode, stateBag, graph-start/traverse"
  - "SimulatorShell integration in campaign simulation phase"
  - "Graph edge choice UI in scene phase"
  - "abenteuerModeGraph.test.js with 3 stateBag reactivity tests"
affects: [81-quest-map, 82-hud-timer-daubot, 83-campaign-content]

tech-stack:
  added: []
  patterns: [stateBag-new-reference, graph-traverse-handler, graph-mode-branch-in-startCampaign]

key-files:
  created:
    - app/tests/unit/abenteuerModeGraph.test.js
  modified:
    - app/src/components/AbenteuerMode.vue

key-decisions:
  - "Split simulation template into graph-mode (SimulatorShell) and linear-mode (PbqRenderer) via v-if/v-else-if"
  - "Graph-mode scene phase renders edge choices from graphAvailableEdges instead of currentScene.choices"
  - "onSimulatorComplete picks first matching edge based on pass status and requires_flag"
  - "Added test_graph_campaign to STATIC_CAMPAIGNS for dev testing"

patterns-established:
  - "stateBag always replaced as new reference via spread: this.stateBag = { ...resp.data.state?.stateBag }"
  - "Graph-mode methods (onSimulatorComplete, makeGraphChoice) share response-handling logic pattern"
  - "retrySimulator uses _retryKey timestamp to force SimulatorShell remount via :key"

requirements-completed: [SIM-01, SIM-02]

duration: 4min
completed: 2026-03-25
---

# Phase 80 Plan 03: AbenteuerMode Graph-Mode Wiring Summary

**Graph-mode campaign engine in AbenteuerMode.vue with SimulatorShell embedding, graph-start/traverse API calls, and Vue 2 reactive stateBag handling**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-25T10:39:27Z
- **Completed:** 2026-03-25T10:43:30Z
- **Tasks:** 3 (2 auto + 1 checkpoint auto-approved)
- **Files modified:** 2

## Accomplishments
- AbenteuerMode.vue extended with full graph-mode support: isGraphMode, stateBag, currentGraphNode, graphAvailableEdges, currentGraphSimulator
- startCampaign() branches to /graph-start for is_graph campaigns, falls back to linear mode on error
- onSimulatorComplete sends graph-traverse with simulator_passed/score/result, always sets stateBag as new reference (Pitfall H1)
- SimulatorShell embedded in simulation phase template for graph-mode, PbqRenderer preserved for linear mode
- Graph edge choices rendered in scene phase when isGraphMode is true
- 3 TDD tests verify stateBag reactivity contract
- Full test suite: 321 tests all green (was 318, +3 new)

## Task Commits

Each task was committed atomically:

1. **Task 1: data(), imports, Graph-Mode-Start** - `936cfec` (feat)
2. **Task 2 RED: failing tests for onSimulatorComplete** - `4950d10` (test)
3. **Task 2 GREEN: onSimulatorComplete + makeGraphChoice + retrySimulator** - `00d2a84` (feat)

## Files Created/Modified
- `app/src/components/AbenteuerMode.vue` - Graph-mode data fields, SimulatorShell import, startCampaign graph branch, onSimulatorComplete, makeGraphChoice, retrySimulator, split simulation template, edge choice UI
- `app/tests/unit/abenteuerModeGraph.test.js` - 3 tests: stateBag new reference, graph-traverse POST params, epilog on empty edges

## Decisions Made
- Split simulation template into two v-if blocks (graph-mode with SimulatorShell, linear with PbqRenderer) rather than a single block with conditional rendering inside -- cleaner separation
- Added test_graph_campaign to STATIC_CAMPAIGNS array for dev testing (will be replaced with real content in Phase 83)
- Set grosser_ausfall campaign as is_graph: true since it will be the main graph campaign

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added SimulatorShell template usage in Task 1**
- **Found during:** Task 1 (ESLint verification)
- **Issue:** ESLint vue/no-unused-components error because SimulatorShell was registered but not used in template (template usage was planned for Task 2)
- **Fix:** Moved simulation template split (graph-mode SimulatorShell + linear PbqRenderer) into Task 1 to satisfy ESLint
- **Files modified:** app/src/components/AbenteuerMode.vue
- **Verification:** ESLint 0 errors
- **Committed in:** 936cfec (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Reordered template change from Task 2 to Task 1 to pass ESLint. No scope creep.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Phase 80 complete: all 4 plans done (lifecycle hooks, SimulatorShell, graph-mode wiring, + Plan 80-04 if any)
- AbenteuerMode ready for Quest-Map overlay (Phase 81) and HUD integration (Phase 82)
- Graph campaigns playable end-to-end once campaign content JSON exists (Phase 83)

---
*Phase: 80-simulatorshell-wiring*
*Completed: 2026-03-25*
