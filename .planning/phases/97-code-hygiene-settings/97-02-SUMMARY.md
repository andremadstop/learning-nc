---
phase: 97-code-hygiene-settings
plan: 02
subsystem: ui
tags: [vue, i18n, l10n, de-json, nextcloud-translate]

requires:
  - phase: 97-code-hygiene-settings
    provides: Settings sub-tab structure and Zeitreise cleanup from plan 01
provides:
  - All visible UI labels go through t() for consistent German translation
  - ModeIdentityBanner MODE_MAP labels translatable via t()
  - de.json keys for Abenteuer, Gameshow, Leitner-System
affects: [future-i18n, multi-language-support]

tech-stack:
  added: []
  patterns: [MODE_MAP raw keys resolved via this.t() in computed property]

key-files:
  created: []
  modified:
    - app/src/components/ModeIdentityBanner.vue
    - app/l10n/de.json

key-decisions:
  - "MODE_MAP labels stay as const keys, resolved via this.t() in modeConfig computed (least invasive)"
  - "CourseDetail visibleTabs already correct - Members/Progress/Exam keys exist in de.json with German values"

patterns-established:
  - "ModeIdentityBanner pattern: static MODE_MAP with label keys, t() applied in computed for Vue prototype access"

requirements-completed: [NAV-07]

duration: 4min
completed: 2026-03-28
---

# Phase 97 Plan 02: DE/EN Label-Mix Bereinigung Summary

**ModeIdentityBanner labels routed through t() with 3 new de.json keys; CourseDetail tabs verified already translated**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-28T11:57:27Z
- **Completed:** 2026-03-28T12:01:36Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- ModeIdentityBanner MODE_MAP labels (Training, Leitner-System, Prüfung, Duell, Gameshow, Abenteuer) now go through this.t() in computed property
- Added 3 missing de.json keys: "Abenteuer", "Gameshow", "Leitner-System"
- Verified CourseDetail visibleTabs already use t() with correct German de.json values (Members->Mitglieder, Progress->Fortschritt, Exam->Prüfung)
- Vitest 576/576 pass, ESLint 0 errors

## Task Commits

1. **Task 1: CourseDetail + ModeIdentityBanner Labels auf t() umstellen** - `9b82cee` (feat)
2. **Task 2: de.json Keys aktualisieren + Vitest verifizieren** - `b75a8fc` (feat)

## Files Created/Modified
- `app/src/components/ModeIdentityBanner.vue` - modeConfig computed wraps label via this.t('learning', key)
- `app/l10n/de.json` - Added keys: Abenteuer, Gameshow, Leitner-System

## Decisions Made
- MODE_MAP labels kept as static const keys (not moved into computed) -- this.t() applied in modeConfig computed for minimal invasiveness
- CourseDetail visibleTabs required no changes -- all labels already wrapped in t() with correct de.json German values
- Exam tab label confirmed: t('learning', 'Exam') resolves to "Prüfung" via existing de.json key

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- All visible UI labels now go through t() -- ready for future multi-language support
- Phase 97 complete, ready for Phase 98 (Simulator-Praxis-Sessions)
- All 576 Vitest tests pass, ESLint clean

---
*Phase: 97-code-hygiene-settings*
*Completed: 2026-03-28*
