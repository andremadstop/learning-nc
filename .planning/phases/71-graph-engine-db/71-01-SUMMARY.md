---
phase: 71-graph-engine-db
plan: 01
subsystem: database
tags: [campaign-engine, graph-traversal, state-bag, php, postgresql, nextcloud]

requires:
  - phase: 48-hack-through-time
    provides: "Migration pattern (Version004500), EpochProgress entity pattern"
provides:
  - "learning_campaign_state DB table (migration 004600)"
  - "CampaignState entity with state-bag helpers"
  - "CampaignStateMapper with user+campaign queries"
  - "CampaignGraphService with graph traversal, condition evaluation, effect application"
affects: [71-02-PLAN, 72-graph-api, 73-campaign-content, StoryEngineService]

tech-stack:
  added: []
  patterns: ["immutable state-bag (deep-copy via json_decode/json_encode)", "graph node/edge traversal with condition gating", "AND-logic condition evaluation"]

key-files:
  created:
    - app/lib/Migration/Version004600Date20260324000000.php
    - app/lib/Db/CampaignState.php
    - app/lib/Db/CampaignStateMapper.php
    - app/lib/Service/CampaignGraphService.php
  modified: []

key-decisions:
  - "Used BIGINT for timestamps (consistent with Version004500 pattern)"
  - "Immutable state-bag via json_decode(json_encode()) deep-copy"
  - "Unknown effects logged as warnings, not thrown (graceful degradation)"

patterns-established:
  - "State-bag pattern: {flags: {}, items: [], reputation: {}} as JSON TEXT column"
  - "Graph condition types: requires_flag, requires_item, min_reputation, max_reputation"
  - "Graph effect types: set_flag, remove_flag, add_item, remove_item, add_reputation"

requirements-completed: [ENG-01, ENG-02, ENG-03, DB-01]

duration: 7min
completed: 2026-03-24
---

# Phase 71 Plan 01: Graph Engine DB Summary

**Campaign graph engine with directed-graph traversal, state-bag condition/effect system, and campaign_state persistence layer**

## Performance

- **Duration:** 7 min
- **Started:** 2026-03-24T13:23:23Z
- **Completed:** 2026-03-24T13:30:05Z
- **Tasks:** 2
- **Files created:** 4

## Accomplishments
- DB migration creating learning_campaign_state table with graph_position, state_bag JSON, unique user+campaign index
- CampaignState entity with decode/encode helpers for state-bag and choices JSON
- CampaignStateMapper with findByUserAndCampaign (null-safe), findAllByUser, findInProgressByUser
- CampaignGraphService (290+ lines) with 9 public methods: graph detection, traversal, condition evaluation, effect application, session init/load

## Task Commits

Each task was committed atomically:

1. **Task 1: DB migration + CampaignState entity and mapper** - `56f3d14` (feat)
2. **Task 2: CampaignGraphService** - `eb1300f` (feat)

## Files Created/Modified
- `app/lib/Migration/Version004600Date20260324000000.php` - Creates learning_campaign_state table with all columns and indexes
- `app/lib/Db/CampaignState.php` - ORM entity with state-bag decode/encode helpers
- `app/lib/Db/CampaignStateMapper.php` - QBMapper with user+campaign query methods
- `app/lib/Service/CampaignGraphService.php` - Graph traversal engine with condition evaluation and effect application

## Decisions Made
- Used BIGINT for created_at/updated_at (consistent with Version004500 epoch pattern, not INTEGER as plan specified)
- Immutable state-bag operations via json_decode(json_encode()) deep-copy to prevent mutation bugs
- Unknown effect keys are logged as warnings rather than throwing exceptions (graceful degradation for forward compatibility)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan findEntity return type**
- **Found during:** Task 1 (CampaignStateMapper)
- **Issue:** PHPStan level 5 reports findEntity() returns Entity, not CampaignState
- **Fix:** Added `/** @var CampaignState */` inline annotation before return
- **Files modified:** app/lib/Db/CampaignStateMapper.php
- **Committed in:** 56f3d14

**2. [Rule 1 - Bug] PHPStan unused logger property**
- **Found during:** Task 2 (CampaignGraphService)
- **Issue:** Logger injected but never read, PHPStan reports dead property
- **Fix:** Added logging of unknown effect keys in applyEffects() (useful for debugging anyway)
- **Files modified:** app/lib/Service/CampaignGraphService.php
- **Committed in:** eb1300f

---

**Total deviations:** 2 auto-fixed (2 PHPStan compliance fixes)
**Impact on plan:** Minimal. Both fixes improve code quality without scope creep.

## Issues Encountered
- docker cp of full lib/ directory fails with tar EOF errors on learning-dev. Workaround: copy individual files instead of directory tree.
- Migration cannot be run until info.xml version is bumped (NC requires version change to trigger migrations). Table creation deferred to deploy/release phase.

## Next Phase Readiness
- CampaignGraphService is standalone (no deps on StoryEngineService or GeminiService)
- Plan 71-02 can integrate CampaignGraphService into StoryEngineService and StoryController
- Migration will run on next app version bump

---
*Phase: 71-graph-engine-db*
*Completed: 2026-03-24*
