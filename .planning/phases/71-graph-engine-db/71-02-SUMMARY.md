---
phase: 71-graph-engine-db
plan: 02
subsystem: service
tags: [campaign-engine, graph-traversal, state-bag, php, story-engine, backward-compat]

requires:
  - phase: 71-graph-engine-db
    provides: "CampaignGraphService, CampaignState entity, CampaignStateMapper, campaign_state table"
provides:
  - "StoryEngineService graph-mode delegation (startCampaign, getScene, makeChoice)"
  - "Graph campaign validation in validateCampaignStructure"
  - "transformGraphResponse mapping graph results to existing API shape"
  - "test_graph_campaign.json with 7 nodes, 9 edges, full condition/effect coverage"
affects: [72-graph-api, 73-campaign-content, StoryController, AbenteuerMode.vue]

tech-stack:
  added: []
  patterns: ["early-return delegation for feature flags (graph vs linear)", "effects-as-list iteration (array of effect objects)"]

key-files:
  created:
    - app/data/campaigns/test_graph_campaign.json
  modified:
    - app/lib/Service/StoryEngineService.php
    - app/lib/Service/CampaignGraphService.php
    - app/appinfo/info.xml

key-decisions:
  - "Graph detection via early-return guard — zero changes to linear code paths"
  - "state_bag exposed in progress response for frontend state display"
  - "Version bump to 3.1.0 to trigger campaign_state migration"

patterns-established:
  - "Graph delegation pattern: isGraphCampaign check -> early return with transformed response"
  - "Effects-as-list: campaign JSON uses array of individual effect objects, iterated in apply loop"

requirements-completed: [ENG-01, ENG-02, ENG-03]

duration: 9min
completed: 2026-03-24
---

# Phase 71 Plan 02: Graph Engine Integration Summary

**StoryEngineService graph delegation with backward-compatible detection, transformGraphResponse mapper, and test campaign validating flags/items/reputation conditions end-to-end**

## Performance

- **Duration:** 9 min
- **Started:** 2026-03-24T13:32:42Z
- **Completed:** 2026-03-24T13:42:00Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments
- StoryEngineService detects graph campaigns and delegates to CampaignGraphService via early-return guards in startCampaign, getScene, makeChoice
- validateCampaignStructure accepts graph-only campaigns (validates node IDs, edge from/to references)
- transformGraphResponse maps graph nodes+edges to existing progress/scene API shape with graph_mode flag
- test_graph_campaign.json validates all condition types (flags, items, reputation), branching paths, and endings
- Full traversal verified: good path (investigate -> isolate -> report) and bad path (alert -> confront -> escalate)

## Task Commits

Each task was committed atomically:

1. **Task 1: StoryEngineService graph delegation** - `eb94e3b` (feat)
2. **Task 2: Test campaign JSON + version bump** - `ebc7e88` (feat)

## Files Created/Modified
- `app/lib/Service/StoryEngineService.php` - Graph detection, delegation, validateGraphStructure, transformGraphResponse
- `app/lib/Service/CampaignGraphService.php` - Fixed effects iteration (list vs flat dict) and act/act_number field mismatch
- `app/data/campaigns/test_graph_campaign.json` - 7-node, 9-edge test campaign with condition gating
- `app/appinfo/info.xml` - Version bump 3.0.0 -> 3.1.0 to trigger migration

## Decisions Made
- Graph detection uses early-return guard pattern — all existing linear code paths remain completely untouched
- state_bag field included in progress response to enable frontend state display
- Version bumped to 3.1.0 to trigger campaign_state table migration (required for end-to-end testing)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Effects iteration mismatch in CampaignGraphService**
- **Found during:** Task 1 (analyzing CampaignGraphService vs test campaign JSON format)
- **Issue:** `applyEffects()` expects flat dict `{set_flag: "x"}` but campaign JSON uses array of effect objects `[{set_flag: "x"}, {add_reputation: {...}}]`. `initGraphSession` and `traverseEdge` passed the whole array as one effect.
- **Fix:** Changed both methods to iterate effects array: `foreach ($node['effects'] as $effect) { $bag = $this->applyEffects($effect, $bag); }`
- **Files modified:** app/lib/Service/CampaignGraphService.php
- **Committed in:** eb94e3b

**2. [Rule 1 - Bug] act vs act_number field name mismatch**
- **Found during:** Task 1 (analyzing traverseEdge)
- **Issue:** Campaign JSON uses `"act": 1` but `traverseEdge` checked `$targetNode['act_number']`
- **Fix:** Changed to `$targetNode['act'] ?? $targetNode['act_number'] ?? null` supporting both conventions
- **Files modified:** app/lib/Service/CampaignGraphService.php
- **Committed in:** eb94e3b

**3. [Rule 3 - Blocking] Version bump for migration**
- **Found during:** Task 2 (end-to-end testing)
- **Issue:** campaign_state table did not exist — NC requires version bump to run migrations
- **Fix:** Bumped info.xml from 3.0.0 to 3.1.0, ran `occ upgrade`
- **Files modified:** app/appinfo/info.xml
- **Committed in:** ebc7e88

---

**Total deviations:** 3 auto-fixed (2 bugs, 1 blocking)
**Impact on plan:** All fixes necessary for correctness. The effects iteration bug would have prevented any graph campaign from working. No scope creep.

## Issues Encountered
- Pre-existing NC routing error: `LernbotFilesController` ReflectionException prevents all HTTP requests (171 occurrences in log). Not caused by this plan's changes. curl-based API testing blocked; validated via PHP CLI instead.
- docker cp of full directories fails with tar EOF errors on learning-dev (known from Plan 01). Worked around by copying individual files.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- Graph campaigns fully usable through existing REST API (same StoryController endpoints)
- Phase 72 (graph-api) can add graph-specific endpoints if needed
- Phase 73 (campaign-content) can create production graph campaigns using the validated JSON format
- Pre-existing LernbotFilesController issue should be resolved before release (out of scope for this plan)

---
*Phase: 71-graph-engine-db*
*Completed: 2026-03-24*
