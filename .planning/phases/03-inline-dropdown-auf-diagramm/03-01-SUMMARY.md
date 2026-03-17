---
phase: 03-inline-dropdown-auf-diagramm
plan: 01
subsystem: PBQ Placement / SVG Topology Picker
tags: [vue, pbq, topology, inline-picker, scoring, tdd]
dependency_graph:
  requires: [02-svg-topology-renderer]
  provides: [inline-picker-overlay, scoring-mode-summary]
  affects: [PbqPlacement.vue, pbqScoringMode.js]
tech_stack:
  added: [pbqScoringMode.js utility]
  patterns: [absolute positioning overlay, scroll-to-close, $nextTick + getNodeScreenPosition]
key_files:
  created:
    - app/src/utils/pbqScoringMode.js
    - app/tests/unit/pbqScoringMode.test.js
  modified:
    - app/src/components/PbqPlacement.vue
decisions:
  - overflow:visible added inline to .pbq-diagram-wrapper rule (not separate rule) to avoid specificity conflict
  - closePicker() extracted as named method (not inline) to allow addEventListener/removeEventListener symmetry
  - Below-diagram picker Cancel button updated from activePosId=null to closePicker() for consistency
requirements_completed: [DROP-01, DROP-02, DROP-03]
metrics:
  duration: ~5min
  completed: 2026-03-17
  tasks_completed: 3
  tasks_total: 3
  files_created: 2
  files_modified: 1
---

# Phase 03 Plan 01: Inline Dropdown Picker + Scoring Mode Summary

**One-liner:** Absolute-positioned picker overlay on SVG topology nodes via getNodeScreenPosition + $nextTick, with strict/partial scoring summary computed from pbqScoringMode.js utility.

## Tasks Completed

| Task | Status | Commit | Description |
|------|--------|--------|-------------|
| 1 - RED: Failing unit tests | Done | 8e30528 | pbqScoringMode.test.js, 7 tests, Cannot find module RED |
| 2 - GREEN: Implementation | Done | d00baa6 | pbqScoringMode.js + PbqPlacement.vue full implementation |
| 3 - Browser verify | Done | approved | All 10 browser verification steps passed — user approved |

## What Was Built

### pbqScoringMode.js

Pure ES utility `scoringSummary(positions, value, mode='strict')`:
- strict: all correct → `'Alle korrekt'`, partial → `'X / Y korrekt'`
- partial: always → `'X / Y korrekt (Z%)'` with rounded percentage
- Positions without `correct` field cannot be counted as correct
- Empty positions array returns `''`

### PbqPlacement.vue Changes

**Script additions:**
- Import `scoringSummary` from `../utils/pbqScoringMode.js`
- `data()`: added `pickerPos: null`
- `openPicker(posId)`: extended with `$nextTick` → `$refs.topologySvg.getNodeScreenPosition(posId)` → wrapper-relative px coords
- `closePicker()`: sets both `activePosId` and `pickerPos` to null
- `assignDevice()`: now calls `closePicker()` instead of direct assignment
- `mounted()` / `beforeDestroy()`: scroll listener lifecycle
- `scoringSummaryText` computed: reads `config.scoring_mode || 'strict'`

**Template additions:**
- `.pbq-inline-picker` div: `v-if="activePosId && pickerPos && topologyConfig"`, absolute positioned, `@click.stop`
- Below-diagram picker: `v-if` gated to `!topologyConfig`
- Summary: per-position correct/wrong CSS classes + `pbq-scoring-summary` div

**CSS additions:**
- `overflow: visible` on `.pbq-diagram-wrapper`
- `.pbq-inline-picker`: absolute, `transform: translate(-50%, calc(-100% - 8px))`, z-index 100
- `.pbq-summary-value--correct` / `--wrong` / `.pbq-scoring-summary`

## Verification Results

### Unit Tests

```
Test Files  4 passed (4)
Tests       47 passed (47)
Duration    308ms
```

All 7 new pbqScoringMode tests GREEN. All 40 pre-existing tests still pass.

### Static Analysis

- `grep -n "v-html" PbqPlacement.vue` → no results (GOOD)
- `ls app/src/utils/pbqScoringMode.js` → file exists
- `grep "pbq-inline-picker"` → present in template and CSS
- `grep "scoring_mode"` → present in computed property

### Deploy

- rsync + `npm run build` (webpack 5, 70s) + docker cp complete on learning-dev
- Build compiled with 2 warnings (pre-existing bundle size warnings, not new)

### Browser Verification (Task 3 — Approved)

All 10 steps verified and approved by user:
1. Clicking a node opens picker ABOVE the node (not below diagram)
2. Picker centered horizontally on the node
3. Picker does not appear at viewport top-left (wrapper-relative coords confirmed)
4. Select device → picker closes, summary updates
5. Clicking different node → picker moves to that node
6. Cancel → picker closes without assignment
7. Scroll → picker auto-closes
8. All positions correct + submit → summary shows 'Alle korrekt' (strict mode)
9. Partial correct with scoring_mode=partial → 'X / Y korrekt (Z%)'
10. IMAGE-mode placement questions → below-diagram picker still works (no regression)

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- [x] pbqScoringMode.js created at `app/src/utils/pbqScoringMode.js`
- [x] pbqScoringMode.test.js created at `app/tests/unit/pbqScoringMode.test.js`
- [x] PbqPlacement.vue modified with all required features
- [x] Commits 8e30528 (RED) and d00baa6 (GREEN) exist
- [x] 47/47 tests pass
- [x] No v-html

## Self-Check: PASSED
