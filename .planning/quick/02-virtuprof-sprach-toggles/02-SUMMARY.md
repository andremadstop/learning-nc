---
phase: quick-02
plan: 01
subsystem: virtuprof
tags: [cleanup, dead-code, i18n, ux]
dependency_graph:
  requires: []
  provides: [clean-virtuprof-language-detection]
  affects: [VirtuProfBubble, VirtuProf, virtuprof-i18n]
tech_stack:
  added: []
  patterns: [nc-system-language-detection]
key_files:
  created: []
  modified:
    - app/src/utils/virtuprof-i18n.js
    - app/src/components/VirtuProf.vue
    - app/src/components/VirtuProfBubble.vue
decisions:
  - Language always auto-detected from NC system language (document.documentElement.lang), no manual override
  - Backend VirtuProfController.php left untouched (separate cleanup if desired)
  - Translation dictionaries (virtuprof-strings.js) preserved intact
metrics:
  duration: 5m 28s
  completed: 2026-03-28
---

# Quick Task 02: VirtuProf Sprach-Toggles Cleanup Summary

Removed manual DE/EN/RU/AR language toggle dead code from VirtuProf; language now always auto-detected from NC system language.

## What Was Done

### Task 1: Remove language toggle infrastructure from virtuprof-i18n.js
**Commit:** 3c865a2

Removed 36 lines of dead code:
- `STORAGE_PREFIX` constant
- `virtuProfLanguageStorageKey()` -- localStorage key builder
- `loadVirtuProfLanguagePreference()` -- localStorage reader
- `persistVirtuProfLanguagePreference(lang)` -- localStorage writer
- `VIRTUPROF_LANGUAGE_OPTIONS` export array

Simplified `detectVirtuProfLanguage()` to only read `document.documentElement.lang` without localStorage lookup.

Preserved: `normalizeVirtuProfLanguage()`, `detectVirtuProfLanguage()`, `translateVirtuProf()`.

### Task 2: Remove language toggle wiring from VirtuProf.vue and VirtuProfBubble.vue
**Commit:** a564c4a

**VirtuProf.vue:**
- Removed `normalizeVirtuProfLanguage` and `persistVirtuProfLanguagePreference` imports
- Removed `setLanguage()` async method (API call to `/api/virtuprof/language`)
- Removed `@language-change="setLanguage"` event binding
- Simplified `applyVirtuProfState()` to always use `detectVirtuProfLanguage()`

**VirtuProfBubble.vue:**
- Removed `VIRTUPROF_LANGUAGE_OPTIONS` import
- Removed `languageOptions()` computed property
- Kept `language` prop, `effectiveLanguage`, `textDirection` (still used for RTL support)

## Verification

- ESLint: 0 errors on all 3 modified files
- Vitest: 624/624 tests passing (41 suites)
- Grep: No references to `VIRTUPROF_LANGUAGE_OPTIONS` or `persistVirtuProfLanguagePreference` in src/
- virtuprof-strings.js: untouched
- VirtuProfController.php: untouched

## Deviations from Plan

None -- plan executed exactly as written.
