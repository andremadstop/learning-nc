---
phase: 114-ux-modus-steuerung
plan: "01"
subsystem: frontend
tags: [ux, mode-config, training, vue, vitest, tdd]
dependency_graph:
  requires: []
  provides: [training-mode-toggle]
  affects: [CourseTabLernraum, CourseTabVerwaltung]
tech_stack:
  added: []
  patterns: [modeEnabled-guard, conditional-tab-push]
key_files:
  created: []
  modified:
    - app/src/components/CourseTabLernraum.vue
    - app/src/components/CourseTabVerwaltung.vue
    - app/tests/unit/CourseTabLernraum.test.js
    - app/tests/unit/CourseTabVerwaltung.test.js
decisions:
  - "defaultSubTab fallback changed to '' (empty string) not 'training' — safe when all modes disabled"
  - "Test G payload key is modeConfig (camelCase) not mode_config — matches saveModeConfig axios.put call"
metrics:
  duration: "~5 min"
  completed: "2026-03-30"
  tasks_completed: 3
  files_modified: 4
requirements_met: [UX-01, UX-05]
---

# Phase 114 Plan 01: Training-Modus als echten mode_config-Toggle umsetzen

**One-liner:** Training tab guarded by `modeEnabled('training')` so students only see it when enabled, with instructor toggle in Verwaltung now functional.

## What Was Built

Made Training-Modus a real `mode_config` toggle consistent with Leitner and Exam modes:

1. **CourseTabLernraum.vue** — `visibleSubTabs` student branch now starts with `const tabs = []` and conditionally pushes the training tab via `modeEnabled('training')`. `defaultSubTab()` falls back to `''` instead of `'training'` to handle the case where all tabs are disabled.

2. **CourseTabVerwaltung.vue** — Three changes:
   - Removed `:disabled="mode.key === 'training'"` from the mode checkbox template
   - Updated `modeConfigKeys` label from `'Training (immer aktiv)'` to `'Training'`
   - Removed the sentence "Training ist immer aktiv." from the hint paragraph

3. **Tests** — 7 new Vitest tests added (4 in CourseTabLernraum + 3 in CourseTabVerwaltung), all passing.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Guard training tab with modeEnabled + fix defaultSubTab | b3f0308 | CourseTabLernraum.vue, CourseTabLernraum.test.js |
| 2 | Enable training checkbox + update hint text | 69ef64c | CourseTabVerwaltung.vue, CourseTabVerwaltung.test.js |
| 3 | Gate 1 full check + deploy | — | (no code changes) |

## Verification Results

- ESLint: 0 errors (14 warnings, pre-existing)
- Vitest: 731/731 tests pass (7 new tests added)
- PHPStan Level 5: No errors
- Deploy: JS bundle deployed to learning-dev successfully

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test G payload key corrected**
- **Found during:** Task 2 RED phase
- **Issue:** Plan specified `payload.mode_config.training` but `saveModeConfig` sends `{ modeConfig: ... }` (camelCase) to the API
- **Fix:** Updated Test G assertion to `payload.modeConfig.training` matching the actual axios.put call
- **Files modified:** app/tests/unit/CourseTabVerwaltung.test.js
- **Commit:** 69ef64c

## Decisions Made

1. `defaultSubTab` fallback changed to `''` (empty string) — when all student modes are disabled, the student sees no active sub-tab, which is the correct behavior per the plan's open question resolution.
2. Test G verifies `modeConfig` (camelCase) not `mode_config` — this matches the real API payload format used by `saveModeConfig`.

## Self-Check: PASSED

- app/src/components/CourseTabLernraum.vue — FOUND
- app/src/components/CourseTabVerwaltung.vue — FOUND
- app/tests/unit/CourseTabLernraum.test.js — FOUND
- app/tests/unit/CourseTabVerwaltung.test.js — FOUND
- Commit b3f0308 — FOUND
- Commit 69ef64c — FOUND
