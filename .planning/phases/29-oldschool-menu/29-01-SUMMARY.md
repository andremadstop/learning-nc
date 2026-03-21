---
phase: 29-oldschool-menu
plan: 01
subsystem: ui
tags: [vue2, coursedetail, tabs, oldschool, selector]

# Dependency graph
requires:
  - phase: 15-arena
    provides: ArenaSelector.vue pattern (grid of cards, emits select-mode)
provides:
  - OldschoolSelector.vue component with Lernwürfel and Wissensturm cards
  - 'oldschool' tab in CourseDetail visibleTabs (both instructor and student)
  - oldschoolSubMode data property and routing logic for Phase 30/31 game modes
affects: [30-lernwuerfel, 31-wissensturm]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Selector pattern: grid of mode cards emitting 'select-mode', identical to ArenaSelector"
    - "Sub-mode routing: parent holds subMode null/string, selector shown when null, placeholder/game shown otherwise"

key-files:
  created:
    - app/src/components/OldschoolSelector.vue
  modified:
    - app/src/components/CourseDetail.vue

key-decisions:
  - "Copied ArenaSelector pattern exactly: 2-column grid, scoped CSS, computed cards array, emits select-mode"
  - "Placeholder divs for LernwuerfelMode and WissensturmMode so routing is wired before Phase 30/31 implement the real components"
  - "Tab visible to both instructor and student (no mode_config guard — oldschool is always available like arena)"

patterns-established:
  - "OldschoolSelector: same structure as ArenaSelector, just 2 cards instead of 3, 2-column grid"

requirements-completed: [OLD-01, OLD-02]

# Metrics
duration: 8min
completed: 2026-03-21
---

# Phase 29 Plan 01: Oldschool-Menü Summary

**OldschoolSelector.vue with Lernwürfel/Wissensturm cards wired into a new Oldschool tab in CourseDetail for both instructors and students**

## Performance

- **Duration:** 8 min
- **Started:** 2026-03-21T10:00:00Z
- **Completed:** 2026-03-21T10:08:00Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- Created OldschoolSelector.vue with two game cards: Lernwürfel (🎲, 2-4 Spieler) and Wissensturm (🏰, 5 Kategorien)
- Added 'oldschool' tab to both instructor tab list and student tab list in visibleTabs computed
- Wired sub-mode routing: oldschoolSubMode data property, onOldschoolSelectMode() method, selectTab() resets sub-mode on tab switch
- Placeholder divs for Phase 30 (LernwuerfelMode) and Phase 31 (WissensturmMode) with back button

## Task Commits

Each task was committed atomically:

1. **Task 1+2: OldschoolSelector + CourseDetail integration** - `8d034a2` (feat)

**Plan metadata:** TBD (docs commit)

## Files Created/Modified

- `app/src/components/OldschoolSelector.vue` - 2-card selector grid, emits 'select-mode' with 'lernwuerfel' or 'wissensturm'
- `app/src/components/CourseDetail.vue` - import + component registration, oldschoolSubMode data, visibleTabs additions (both roles), template section, onOldschoolSelectMode(), selectTab() reset

## Decisions Made

- Copied ArenaSelector pattern exactly as specified — 2-column grid instead of 3-column since there are only 2 game modes
- Placeholder divs used for Phase 30/31 game modes (LernwuerfelMode, WissensturmMode) so routing is fully wired now and Phase 30/31 only need to swap in real components
- No mode_config guard on oldschool tab — always visible, same as arena

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Oldschool tab is live in CourseDetail — both instructor and student see it
- OldschoolSelector renders correctly with the two game cards
- Phase 30 (Lernwürfel): replace the 'lernwuerfel' placeholder div with LernwuerfelMode.vue component
- Phase 31 (Wissensturm): replace the 'wissensturm' placeholder div with WissensturmMode.vue component
- No blockers

## Self-Check

- [x] `app/src/components/OldschoolSelector.vue` created
- [x] `app/src/components/CourseDetail.vue` modified
- [x] commit `8d034a2` exists

## Self-Check: PASSED

---
*Phase: 29-oldschool-menu*
*Completed: 2026-03-21*
