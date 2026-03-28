---
phase: 97-code-hygiene-settings
plan: 01
subsystem: ui
tags: [vue, settings, dead-code-removal, navigation]

requires:
  - phase: 96-ux-navigation-struktur
    provides: tab grouping structure and course-sub-nav pattern
provides:
  - Settings sub-tab toggle for instructor (Kurs-Verwaltung + Meine Einstellungen)
  - Complete Zeitreise frontend removal (HackThroughTime, chronos, characterClasses)
affects: [97-02, backend-cleanup]

tech-stack:
  added: []
  patterns: [settings sub-tab pattern reuses course-sub-nav style]

key-files:
  created: []
  modified:
    - app/src/App.vue
    - app/src/components/CourseDetail.vue
    - app/src/data/characters.js
    - app/src/main.js
  deleted:
    - app/src/components/HackThroughTime.vue
    - app/src/data/characterClasses.js

key-decisions:
  - "Settings sub-tabs use same course-sub-nav + mode-btn pattern for visual consistency"
  - "epoch-tokens.css import kept (comment removed) — CSS may be used by other components"

patterns-established:
  - "Settings sub-tab pattern: settingsSubTab data prop with admin/personal toggle for instructor role"

requirements-completed: [NAV-05, NAV-06]

duration: 2min
completed: 2026-03-28
---

# Phase 97 Plan 01: Settings Sub-Tabs + Zeitreise Dead-Code Removal Summary

**Instructor settings split into Kurs-Verwaltung/Meine Einstellungen sub-tabs; 1270+ lines of Zeitreise dead code removed**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-28T11:53:30Z
- **Completed:** 2026-03-28T11:55:36Z
- **Tasks:** 2
- **Files modified:** 6 (4 modified, 2 deleted)

## Accomplishments
- Instructors now see two sub-tabs in Settings: Kurs-Verwaltung (AdminSettings) and Meine Einstellungen (PersonalSettings)
- Students continue to see only PersonalSettings without sub-tabs
- Removed all Zeitreise frontend artifacts: HackThroughTime.vue (1195 lines), chronos character, characterClasses.js (74 lines), nav button, template block, import
- Vitest 576/576 pass, ESLint 0 errors

## Task Commits

1. **Task 1: Settings Sub-Tab-Logik + Zeitreise-Entfernung aus App.vue** - `b1a22cb` (feat)
2. **Task 2: HackThroughTime.vue loeschen und Vitest verifizieren** - `2365bea` (chore)

## Files Created/Modified
- `app/src/App.vue` - Settings sub-tab logic for instructor, Zeitreise nav/template/import removed
- `app/src/components/CourseDetail.vue` - zeitreise key removed from modeConfigKeys
- `app/src/data/characters.js` - chronos character removed (13 -> 12 characters)
- `app/src/main.js` - Hack Through Time comment removed
- `app/src/components/HackThroughTime.vue` - DELETED (1195 lines, 92KB monolith)
- `app/src/data/characterClasses.js` - DELETED (74 lines, Zeitreise-only)

## Decisions Made
- Settings sub-tabs reuse the existing `course-sub-nav` + `mode-btn` CSS classes for visual consistency
- epoch-tokens.css import kept (only comment removed) since tokens may be referenced elsewhere
- Backend Zeitreise routes/controller/service/migration explicitly deferred per user decision

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Settings split complete, ready for 97-02 (remaining hygiene tasks)
- Backend Zeitreise cleanup deferred to future phase
- All 576 Vitest tests pass, ESLint clean

---
*Phase: 97-code-hygiene-settings*
*Completed: 2026-03-28*
