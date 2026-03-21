---
phase: 15-arena
plan: "01"
subsystem: frontend
tags: [arena, ux, tab-consolidation, vue, gameshow, duel]
dependency_graph:
  requires: []
  provides: [arena-tab, arena-selector]
  affects: [CourseDetail.vue, App.vue]
tech_stack:
  added: []
  patterns: [Vue 2.7 SFC, emit-pattern, arenaSubMode state]
key_files:
  created:
    - app/src/components/ArenaSelector.vue
  modified:
    - app/src/components/CourseDetail.vue
    - app/src/App.vue
    - app/js/learning.js
    - app/js/934.js
key_decisions:
  - ArenaSelector uses computed cards array for i18n compatibility
  - arenaSubMode null = show selector, string = show sub-component
  - selectTab() resets arenaSubMode to null on any tab switch
  - Standalone App.vue gameshow kept as-is (no courseId context for ArenaSelector)
metrics:
  duration: ~15 min
  completed: "2026-03-21"
  tasks: 3
  files: 5
requirements:
  - ARENA-01
  - ARENA-02
  - ARENA-03
  - ARENA-04
---

# Phase 15 Plan 01: Arena Tab Consolidation Summary

Duell und Gameshow unter einem gemeinsamen "Arena"-Tab vereint. ArenaSelector.vue zeigt drei Wettkampfkarten (Duell 1v1, Sprint 2-5, Elimination 2-5) und delegiert direkt in den jeweiligen Lobby-Flow.

## What Was Built

### ArenaSelector.vue (new)

Three-card selection component for competition modes. Each card is a `<button>` that emits `select-mode` with value `'duel'`/`'sprint'`/`'elimination'`. Responsive grid (3 cols desktop, 1 col mobile), `prefers-reduced-motion` compliant, Nextcloud CSS variables used throughout.

### CourseDetail.vue changes

- `visibleTabs()` instructor branch: removed `{ id: 'duel' }` and `{ id: 'gameshow' }`, replaced with `{ id: 'arena', label: t('learning', 'Arena') }`
- `visibleTabs()` student branch: removed `if (enabled('duel')) duel push` and `gameshow push`, replaced with unconditional `arena push`
- Tab content: replaced `duel-section` + `gameshow-section` divs with single `arena-section` div containing ArenaSelector + conditional DuelMode/GameshowMode
- `arenaSubMode: null` added to `data()`
- `onArenaSelectMode(mode)` method added
- `selectTab()` now resets `arenaSubMode = null` on tab switch
- `presetDuelCode` watcher: `currentTab = 'duel'` → `currentTab = 'arena'; arenaSubMode = 'duel'`
- VirtuProf context: `course-duel`/`course-gameshow` → `course-arena` / template literal `course-${arenaSubMode}`

### App.vue changes

- Standalone pool view gameshow label updated from `'Gameshow'` to `'Arena — Gameshow'`
- modeDescription for gameshow updated to reflect Sprint/Elimination framing

### Emit flow

```
ArenaSelector --[select-mode 'duel'/'sprint'/'elimination']--> CourseDetail.onArenaSelectMode()
  --> arenaSubMode = mode
  --> v-else-if renders DuelMode or GameshowMode with correct :gameMode prop
  DuelMode/@back --> arenaSubMode = null (back to ArenaSelector)
  GameshowMode/@back --> arenaSubMode = null (back to ArenaSelector)
```

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed escaped backtick in template literal**
- **Found during:** Task 3 (build)
- **Issue:** Python string replacement introduced `\`` instead of `` ` `` in template literal on CourseDetail.vue line 1383
- **Fix:** Replaced `\`course-${this.arenaSubMode}\`` with proper unescaped backticks
- **Files modified:** app/src/components/CourseDetail.vue
- **Commit:** 09edd55

## Self-Check: PASSED

- ArenaSelector.vue: FOUND
- CourseDetail.vue: FOUND (arena tab integrated)
- Commits: a086554, 0f879fb, 09edd55 — all present
- Build: compiled with 0 errors (2 warnings)
