---
phase: 96-ux-navigation-struktur
plan: 02
subsystem: ui
tags: [vue, arena, mode-config, navigation, gating]

requires:
  - phase: 96-01
    provides: "ArenaSelector with 4 cards, tab structure with groups"
provides:
  - "ArenaSelector filters cards by modeConfig prop"
  - "Arena tab conditional on hasEnabledArenaModes for students"
  - "visibleTabs watcher for safe tab fallback"
  - "Oldschool path verified end-to-end"
affects: [97, 99]

tech-stack:
  added: []
  patterns: ["configKey mapping on arena cards for mode_config gating"]

key-files:
  created: []
  modified:
    - app/src/components/ArenaSelector.vue
    - app/src/components/CourseDetail.vue

key-decisions:
  - "Sprint and Elimination share 'gameshow' config key (less config overhead for Dozent)"
  - "Added visibleTabs watcher as safety guard for tab disappearing mid-session"

patterns-established:
  - "configKey on card objects: internal property mapping arena modes to mode_config keys"
  - "visibleTabs watcher: generic fallback when current tab removed from visible set"

requirements-completed: [NAV-03, NAV-04]

duration: 2min
completed: 2026-03-28
---

# Phase 96 Plan 02: Arena-Submode-Gating + Oldschool-Pfad Summary

**ArenaSelector filters arena cards by mode_config with gameshow key sharing, plus visibleTabs safety watcher and verified Oldschool path**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-28T11:22:21Z
- **Completed:** 2026-03-28T11:23:54Z
- **Tasks:** 2 (1 implementation, 1 verification-only)
- **Files modified:** 2

## Accomplishments
- ArenaSelector accepts modeConfig prop and filters cards using configKey mapping (duel, gameshow, oldschool)
- Arena tab hidden for students when all submodes (duel, gameshow, oldschool) set to false
- Added visibleTabs watcher that falls back to training/pools if current tab disappears
- Oldschool path traced end-to-end: ArenaSelector -> OldschoolSelector -> LernwuerfelMode/WissensturmMode -- all imports, registrations, emits, and back handlers confirmed

## Task Commits

Each task was committed atomically:

1. **Task 1: Arena-Submode-Gating per mode_config** - `3793636` (feat)
2. **Task 2: Oldschool-Pfad verifizieren** - verification-only, no code changes

## Files Created/Modified
- `app/src/components/ArenaSelector.vue` - Added modeConfig prop, configKey on cards, filter logic
- `app/src/components/CourseDetail.vue` - Pass modeConfig to ArenaSelector, hasEnabledArenaModes computed, conditional Arena tab, visibleTabs watcher

## Decisions Made
- Sprint and Elimination share 'gameshow' config key -- simpler for Dozent, one toggle controls both
- Added visibleTabs watcher as safety net (plan step 6) since no existing fallback existed for dynamically removed tabs

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Added visibleTabs watcher for tab fallback**
- **Found during:** Task 1 (step 6 verification)
- **Issue:** Plan mentioned a watcher at "line ~1482" that should handle invalid tabs, but no such watcher existed
- **Fix:** Added visibleTabs watcher that resets currentTab to default when current tab no longer in visible set
- **Files modified:** app/src/components/CourseDetail.vue
- **Verification:** ESLint passes, logic confirmed correct
- **Committed in:** 3793636 (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** Essential for correctness -- without this guard, students could be stuck on a non-existent arena tab if Dozent disables all modes mid-session.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- mode_config now fully wired to Arena submode visibility
- Tab fallback safety in place for any future dynamic tab changes
- Ready for Phase 97 (Code-Hygiene & Settings)

---
*Phase: 96-ux-navigation-struktur*
*Completed: 2026-03-28*

## Self-Check: PASSED
