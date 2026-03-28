---
phase: 95-skill-map
plan: 01
subsystem: api, ui
tags: [d3, force-graph, skill-map, lernprofil, php, vitest]

# Dependency graph
requires:
  - phase: 22-lernprofil
    provides: LernprofilService with aggregateProfile() and pool stats
provides:
  - GET /api/profile/skill-map endpoint with course-grouped pool data
  - skillMapEngine.js pure-function graph data transformer
  - skillMapRenderer.js D3 force-directed graph renderer
  - skill-map.css visual styles
affects: [95-02-skill-map-vue-integration]

# Tech tracking
tech-stack:
  added: []
  patterns: [skill-map engine/renderer split following questMap pattern]

key-files:
  created:
    - app/src/utils/skillMapEngine.js
    - app/src/utils/skillMapRenderer.js
    - app/src/css/skill-map.css
    - app/tests/unit/skillMapEngine.test.js
  modified:
    - app/lib/Controller/LernprofilController.php
    - app/lib/Service/LernprofilService.php
    - app/appinfo/routes.php

key-decisions:
  - "Chain topology for intra-course links (avoid visual clutter vs all-pairs)"
  - "Course-based x-band clustering via forceX for visual grouping"
  - "enrichPoolsWithCourseData as separate service method for reusability"

patterns-established:
  - "skillMap engine/renderer split: pure-function engine (testable) + D3 renderer (no Vue), matching questMap pattern"

requirements-completed: [SKILL-01, SKILL-02]

# Metrics
duration: 4min
completed: 2026-03-28
---

# Phase 95 Plan 01: Skill-Map Backend + Engine + Renderer Summary

**Force-directed skill-map with D3.js: API endpoint for course-grouped pool data, TDD-tested graph engine, and renderer with zoom/pan/clustering**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-28T07:58:38Z
- **Completed:** 2026-03-28T08:02:26Z
- **Tasks:** 2
- **Files modified:** 7

## Accomplishments
- Backend endpoint GET /api/profile/skill-map returns pools enriched with course_id/course_name for graph rendering
- Pure-function skillMapEngine.js transforms API data into D3 nodes/links with color (error-rate thresholds), radius (question count), and trend indicators
- D3 force-directed renderer with course-based clustering, zoom/pan, hover effects, and click handlers
- 19 unit tests covering all engine functions with boundary values

## Task Commits

Each task was committed atomically:

1. **Task 1: Backend endpoint + skillMapEngine.js with TDD** - `e75e958` (feat)
2. **Task 2: skillMapRenderer.js + skill-map.css** - `81e3f3e` (feat)

## Files Created/Modified
- `app/src/utils/skillMapEngine.js` - Pure-function graph data transformer (buildGraphData, getNodeColor, getNodeRadius, getTrendIndicator)
- `app/src/utils/skillMapRenderer.js` - D3 force-directed renderer (createSkillMap, createForceSimulation, setupZoom, renderNodes, renderLinks, updateSimulation)
- `app/src/css/skill-map.css` - Visual styles for nodes, links, trends, sidebar, responsive breakpoints
- `app/tests/unit/skillMapEngine.test.js` - 19 unit tests for engine logic
- `app/lib/Controller/LernprofilController.php` - Added skillMap() endpoint
- `app/lib/Service/LernprofilService.php` - Added enrichPoolsWithCourseData() method
- `app/appinfo/routes.php` - Added skill-map route

## Decisions Made
- Chain topology for intra-course links: connects pool[0]-pool[1], pool[1]-pool[2] etc. to show grouping without all-pairs clutter
- Course-based x-band clustering via forceX: nodes in the same course are attracted to the same horizontal band
- enrichPoolsWithCourseData as a separate public method on LernprofilService for potential reuse

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Engine and renderer are ready for Vue component integration (Plan 02)
- API endpoint ready for deployment after PHPStan verification on dev server
- CSS imported via standard pattern (import in component or main.js)

---
*Phase: 95-skill-map*
*Completed: 2026-03-28*
