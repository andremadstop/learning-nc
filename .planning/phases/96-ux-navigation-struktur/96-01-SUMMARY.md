---
phase: 96-ux-navigation-struktur
plan: 01
subsystem: ui
tags: [vue, tabs, navigation, ux, coursedetail]

requires: []
provides:
  - "Grouped instructor tabs with 5 logical groups and visual separators"
  - "Abenteuer as standalone tab outside Arena for both instructor and student"
  - "ArenaSelector with 4 cards (duel, sprint, elimination, oldschool)"
affects: [96-02, 97, 99, 100]

tech-stack:
  added: []
  patterns: ["tab-group-separator pattern for visual grouping in tab-selector"]

key-files:
  created: []
  modified:
    - app/src/components/CourseDetail.vue
    - app/src/components/ArenaSelector.vue

key-decisions:
  - "Tab group order: Lernraum > Teilnehmer > Kommunikation > Wettbewerb > Verwaltung"
  - "Student tabs do not get group separators (fewer tabs, no chaos)"
  - "Abenteuer @back navigates to training tab (not arenaSubMode) since standalone"

patterns-established:
  - "tab-group-separator: span element between groups detected via group property on tab objects"

requirements-completed: [NAV-01, NAV-02]

duration: 3min
completed: 2026-03-28
---

# Phase 96 Plan 01: Tab-Gruppierung + Abenteuer-Eigenstaendigkeit Summary

**Instructor tabs organized into 5 logical groups with visual separators; Abenteuer extracted from Arena into standalone tab**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-28T11:16:52Z
- **Completed:** 2026-03-28T11:19:26Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- 17 instructor tabs reorganized into 5 groups (Lernraum, Teilnehmer, Kommunikation, Wettbewerb, Verwaltung) with CSS separator lines
- Abenteuer moved from Arena sub-mode to standalone tab in both instructor and student views
- ArenaSelector reduced from 5 to 4 cards (abenteuer card removed)
- Student Abenteuer tab gated by mode_config.abenteuer

## Task Commits

Each task was committed atomically:

1. **Task 1: Dozent-Tab-Gruppierung mit Separatoren** - `41fea1e` (feat)
2. **Task 2: Abenteuer als eigenstaendiger Tab + aus Arena entfernen** - `c461d04` (feat)

## Files Created/Modified
- `app/src/components/CourseDetail.vue` - Grouped tabs with separator template, standalone Abenteuer section
- `app/src/components/ArenaSelector.vue` - Removed abenteuer card (4 cards remain)

## Decisions Made
- Tab group order follows workflow logic: content first (Lernraum), then people (Teilnehmer), communication, competition, admin
- Student branch keeps flat tabs without groups -- fewer tabs make separators unnecessary
- Abenteuer @back handler navigates to 'training' instead of resetting arenaSubMode since it is no longer inside Arena

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Tab structure is in place for Phase 96-02 (student tab refinement or further navigation changes)
- ArenaSelector clean with 4 cards, ready for any Arena-specific improvements
- VirtuProf guide entries for abenteuer tab already exist (line ~1624), no update needed

---
*Phase: 96-ux-navigation-struktur*
*Completed: 2026-03-28*

## Self-Check: PASSED
