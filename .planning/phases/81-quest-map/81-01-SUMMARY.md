---
phase: 81-quest-map
plan: 01
subsystem: ui
tags: [quest-map, campaign-engine, graph, vitest, pure-js]

requires:
  - phase: 80-simulator-shell
    provides: Graph traversal wiring in AbenteuerMode + CampaignGraphService

provides:
  - questMapEngine.js pure JS engine with 5 exported functions for node/edge state computation
  - full_graph key in buildGraphResponse for complete graph topology
  - _visited_nodes tracking in stateBag for visited node detection

affects: [81-quest-map, 82-hud-timer]

tech-stack:
  added: []
  patterns: [pure-js-engine-with-vitest, stateBag-metadata-tracking]

key-files:
  created:
    - app/src/utils/questMapEngine.js
    - app/tests/unit/questMapEngine.test.js
  modified:
    - app/lib/Service/CampaignGraphService.php

key-decisions:
  - "Node state priority: current > reachable > visited > locked"
  - "conditionToText produces German tooltip text with pipe-separated conditions"
  - "_visited_nodes tracked as array in stateBag, initialized on session start"

patterns-established:
  - "Pure JS engine pattern: business logic in src/utils/*.js, fully testable without DOM/Vue"
  - "StateBag metadata: underscore-prefixed keys (_visited_nodes) for engine metadata vs game state"

requirements-completed: [MAP-01, MAP-02, MAP-03, MAP-04, MAP-05]

duration: 3min
completed: 2026-03-25
---

# Phase 81 Plan 01: Quest-Map Engine + Backend Graph Data Summary

**Pure JS quest-map engine with 5 functions (node states, edge states, condition text, navigation lookup) plus backend full_graph endpoint and _visited_nodes tracking**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-25T11:56:05Z
- **Completed:** 2026-03-25T11:59:31Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- questMapEngine.js: pure ES module with computeNodeStates, deriveVisitedNodes, conditionToText, findEdgeForNavigation, computeEdgeStates
- 29 Vitest unit tests covering all functions including edge cases (empty graph, null stateBag, multiple conditions)
- CampaignGraphService.buildGraphResponse now includes full_graph with sanitized node/edge data
- traverseEdge tracks _visited_nodes in stateBag on each navigation
- initGraphSession initializes _visited_nodes with start node ID

## Task Commits

Each task was committed atomically:

1. **Task 1: questMapEngine.js -- pure JS engine + tests** - `6fd012d` (feat+test, TDD)
2. **Task 2: Backend -- full_graph + _visited_nodes tracking** - `2bfc152` (feat)

## Files Created/Modified
- `app/src/utils/questMapEngine.js` - Pure JS engine for Quest-Map node/edge state computation (5 exports)
- `app/tests/unit/questMapEngine.test.js` - 29 unit tests covering all engine functions
- `app/lib/Service/CampaignGraphService.php` - Added full_graph to response, _visited_nodes tracking in traverseEdge + initGraphSession

## Decisions Made
- Node state priority order: current > reachable > visited > locked (current always wins even if node is in visited list)
- conditionToText uses German text patterns: "Braucht:", "Item noetig:", "X Reputation >= Y"
- _visited_nodes stored as simple string array in stateBag, deduplication on insert

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- questMapEngine.js ready for import by QuestMap.vue component (Phase 81 Plan 02+)
- full_graph data available in every graph response for D3 rendering
- _visited_nodes available in stateBag for node state computation

---
*Phase: 81-quest-map*
*Completed: 2026-03-25*
