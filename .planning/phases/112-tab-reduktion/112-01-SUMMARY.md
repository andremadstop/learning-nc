---
phase: 112-tab-reduktion
plan: 01
subsystem: ui
tags: [vue, component-extraction, mega-tabs, sub-nav-pills]

requires:
  - phase: 110-course-lernraum
    provides: CourseTabLernraum.vue PoC pattern for mega-tab extraction
provides:
  - CourseTabKommunikation.vue mega-tab component
  - CourseTabVerwaltung.vue mega-tab component
  - kommunikationLeafTabs/verwaltungLeafTabs computed in CourseDetail.vue
affects: [112-02, 112-03, CourseDetail.vue]

tech-stack:
  added: []
  patterns: [mega-tab extraction with sub-nav pills, lazy-load via activeTab watcher]

key-files:
  created:
    - app/src/components/CourseTabKommunikation.vue
    - app/src/components/CourseTabVerwaltung.vue
    - app/tests/unit/CourseTabKommunikation.test.js
    - app/tests/unit/CourseTabVerwaltung.test.js
  modified:
    - app/src/components/CourseDetail.vue

key-decisions:
  - "Mega-tabs kommunikation/verwaltung in visibleTabs replace individual leaf tabs; leaf IDs preserved internally"
  - "Verwaltung syncs leitnerSprint and talkRoomToken from course prop watcher instead of parent fetchCourseDetail"

patterns-established:
  - "Mega-tab extraction pattern: sub-nav pills, syncFromActiveTab, lazyLoad, selectSubTab emitting tab-change"

requirements-completed: [UX-01]

duration: 8min
completed: 2026-03-29
---

# Phase 112 Plan 01: Tab Reduction (Kommunikation + Verwaltung) Summary

**Extracted Kommunikation (327 LOC) and Verwaltung (496 LOC) mega-tab components from CourseDetail.vue, reducing it by 463 lines with all 693 tests passing**

## Performance

- **Duration:** 8 min
- **Started:** 2026-03-29T20:15:21Z
- **Completed:** 2026-03-29T20:24:00Z
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments
- CourseTabKommunikation.vue: announcements, feed, requests (instructor); feed, buddies, schwarm (student) with sub-nav pills
- CourseTabVerwaltung.vue: mode-config (modes + tools + sprint + talk-token), exam-slot with sub-nav pills
- CourseDetail.vue reduced from 3160 to 2697 lines (-463 lines)
- 10 new unit tests added (5 per component), all 693 tests green

## Task Commits

Each task was committed atomically:

1. **Task 1: Extract CourseTabKommunikation.vue + CourseTabVerwaltung.vue** - `5b43b18` (feat)
2. **Task 2: Add unit tests for extracted components** - `85b0427` (test)

## Files Created/Modified
- `app/src/components/CourseTabKommunikation.vue` - Kommunikation mega-tab with role-based sub-nav
- `app/src/components/CourseTabVerwaltung.vue` - Verwaltung mega-tab with mode/tool config and exam slot
- `app/src/components/CourseDetail.vue` - Removed extracted blocks, added mega-tab integration
- `app/tests/unit/CourseTabKommunikation.test.js` - 5 unit tests
- `app/tests/unit/CourseTabVerwaltung.test.js` - 5 unit tests

## Decisions Made
- Mega-tab entries (`kommunikation`, `verwaltung`) replace individual leaf tabs in `visibleTabs()` computed; leaf IDs preserved inside components for App.vue view-key compatibility
- Verwaltung initializes `leitnerSprint` and `talkRoomToken` from a `course` prop watcher rather than from parent `fetchCourseDetail`, keeping state ownership clean

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- CourseDetail.vue ready for further extraction (112-02: Teilnehmer, 112-03: Wettbewerb)
- Pattern established and proven across 3 mega-tab components (Lernraum, Kommunikation, Verwaltung)

---
*Phase: 112-tab-reduktion*
*Completed: 2026-03-29*
