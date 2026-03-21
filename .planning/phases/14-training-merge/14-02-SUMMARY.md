---
phase: 14-training-merge
plan: 02
subsystem: frontend
tags: [vue, refactor, swipemode, trainingmode, technical-debt]
dependency_graph:
  requires: [14-01]
  provides: [SwipeMode-removed, all-wf-entry-points-unified]
  affects: [App.vue, CourseDetail.vue, PoolList.vue, TrainingMode.vue]
tech_stack:
  added: []
  patterns: [wfMode-prop-pattern, localWfMode-internal-toggle]
key_files:
  created: []
  modified:
    - app/src/components/TrainingMode.vue
    - app/src/App.vue
    - app/src/components/CourseDetail.vue
    - app/src/components/PoolList.vue
  deleted:
    - app/src/components/SwipeMode.vue
decisions:
  - "wfMode added as Boolean prop to TrainingMode (default false); localWfMode as toggleable internal copy initialized from prop"
  - "Standalone swipe entry point (PoolList button) now keeps user on pools view — TrainingMode requires a poolId so no standalone picker was added"
  - "openSwipeMode() method kept by name in App.vue (only method name, not component); wired to @openTrainingWf event from PoolList"
metrics:
  duration_minutes: 15
  completed_date: "2026-03-21"
  tasks_completed: 2
  files_changed: 5
---

# Phase 14 Plan 02: SwipeMode Removal — Summary

**One-liner:** SwipeMode.vue deleted and all three entry points (App.vue pool-detail tab, CourseDetail swipe tab, PoolList button) redirected to TrainingMode with :wfMode="true" prop.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add wfMode prop to TrainingMode, delete SwipeMode.vue | 817812b | TrainingMode.vue, SwipeMode.vue (deleted) |
| 2 | Redirect App.vue, CourseDetail.vue, PoolList.vue | 2aa18c9 | App.vue, CourseDetail.vue, PoolList.vue |

## What Was Built

TrainingMode.vue now accepts a `wfMode` Boolean prop (default: `false`). Internally it uses `localWfMode` — a data property initialized from the prop — so the user can still toggle between MC and Wahr/Falsch on the start screen even when the prop forces `wfMode=true`. A prop watcher syncs `localWfMode` when the prop changes externally and resets card animation state.

SwipeMode.vue (725 lines) was deleted. All three entry points now render TrainingMode:

- **App.vue pool-detail** (`mode === 'swipe'`): `<TrainingMode :wfMode="true">` replaces `<SwipeMode>`
- **CourseDetail.vue** (`activeLearningMode === 'swipe'`): `<TrainingMode :wfMode="true">` replaces `<SwipeMode>`
- **PoolList.vue button**: emits `openTrainingWf` (was `openSwipeMode`)

The standalone "Wahr/Falsch" path from the PoolList quick-access button now keeps the user on the pools view (pool selection required before using Wahr/Falsch via pool-detail tab). The old standalone SwipeMode had its own pool picker — this functionality is deferred.

## Deviations from Plan

### Auto-fixed Issues

None.

### Scope Notes

- The method `openSwipeMode()` in App.vue was kept by name (not renamed to `openTrainingWf`) to minimize diff — it is now wired via `@openTrainingWf` event and simply keeps `currentView = 'pools'`.
- The standalone pool-picker UX that SwipeMode provided is not replicated. The plan acknowledged this with `poolId=0` fallback but that would show "No questions available". Keeping the user on the pools view is cleaner.

## Verification

- `test ! -f app/src/components/SwipeMode.vue` — PASS
- `grep -r "import SwipeMode" app/src/` — 0 results
- `grep -r "<SwipeMode" app/src/` — 0 results
- `npm run build` — compiled with 2 warnings (size only), 0 errors

## Self-Check: PASSED

- TrainingMode.vue has wfMode prop — FOUND at line 244
- SwipeMode.vue deleted — CONFIRMED
- App.vue: no SwipeMode import/component — CONFIRMED
- CourseDetail.vue: activeLearningMode='swipe' renders TrainingMode with :wfMode="true" — CONFIRMED
- PoolList.vue: emits openTrainingWf — CONFIRMED at line 36
- Build passes — CONFIRMED (webpack compiled, 0 errors)
