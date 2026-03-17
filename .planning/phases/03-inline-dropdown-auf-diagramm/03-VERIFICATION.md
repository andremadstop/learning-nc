---
phase: 03-inline-dropdown-auf-diagramm
verified: 2026-03-17T05:50:30Z
status: human_needed
score: 7/7 must-haves verified
human_verification:
  - test: "Click a node on an SVG topology question — picker appears visually above that node, centered horizontally"
    expected: "Picker popup overlays directly above the clicked node, not below the diagram"
    why_human: "Requires browser interaction with a live SVG topology question to confirm visual positioning"
  - test: "Open inline picker, then scroll the page"
    expected: "Picker auto-closes on scroll"
    why_human: "Requires browser interaction to confirm scroll listener behavior"
  - test: "Open an image-mode placement question — below-diagram picker still works"
    expected: "No regression: device picker appears below diagram as before"
    why_human: "Requires browser interaction with a non-SVG question type"
---

# Phase 03: Inline Dropdown auf Diagramm — Verification Report

**Phase Goal:** Dropdown-Auswahl direkt auf SVG-Topologie-Nodes positioniert, mit scoring_mode (strict/partial). Keine v-html.
**Verified:** 2026-03-17T05:50:30Z
**Status:** human_needed (all automated checks passed; browser interaction confirmed approved in SUMMARY)
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Clicking a node on the SVG topology opens a picker that appears visually above that node | ? HUMAN | Template: `v-if="activePosId && pickerPos && topologyConfig"` with `transform: translate(-50%, calc(-100% - 8px))`. Positioning logic in `openPicker()` via `$nextTick` + `getNodeScreenPosition`. Browser-approved per SUMMARY Task 3. |
| 2 | Picker is absolutely positioned relative to the diagram wrapper, not the viewport | ✓ VERIFIED | `openPicker()` computes `screenPos.x - wRect.left` / `screenPos.y - wRect.top` where `wRect = wrapper.getBoundingClientRect()`. CSS: `position: absolute` inside `.pbq-diagram-wrapper { position: relative }`. |
| 3 | In strict mode, summary reads 'Alle korrekt' only when every position is exactly correct | ✓ VERIFIED | Unit test: `strict: all correct returns Alle korrekt` — 47/47 tests green. Utility: `return correct === total ? 'Alle korrekt' : ...` |
| 4 | In strict mode, partial matches show 'X / Y korrekt' (no percentage) | ✓ VERIFIED | Unit test: `strict: partial correct returns X / Y korrekt` — passes. Utility: strict branch returns no `(pct%)` suffix. |
| 5 | In partial mode, summary shows 'X / Y korrekt (Z%)' | ✓ VERIFIED | Unit tests: `partial: shows percentage` and `partial: 0 correct returns 0 / N korrekt (0%)` — both pass. |
| 6 | The existing below-diagram picker remains unchanged for image-mode and grid-mode questions | ✓ VERIFIED | Template: `v-if="activePosId && !topologyConfig"` gates below-diagram picker to non-SVG mode. Inline picker gated to `topologyConfig` presence. |
| 7 | No v-html anywhere in PbqPlacement.vue | ✓ VERIFIED | `grep -n "v-html" PbqPlacement.vue` returns empty (exit code 1 = no match). All text rendered via `{{ }}` mustache bindings. |

**Score:** 7/7 truths verified (1 truth additionally needs human confirmation for visual positioning)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/tests/unit/pbqScoringMode.test.js` | Unit tests for scoringSummary display logic | ✓ VERIFIED | 53 lines, 7 tests in `describe('scoringSummary')`, all passing (47/47 total tests green) |
| `app/src/components/PbqPlacement.vue` | Inline picker overlay + scoring mode summary | ✓ VERIFIED | 235 lines, contains `pbq-inline-picker` in template and CSS, `scoringSummaryText` computed, `openPicker`/`closePicker` methods, scroll listener lifecycle |
| `app/src/utils/pbqScoringMode.js` | Pure utility: scoringSummary function | ✓ VERIFIED | 21 lines, exports named `scoringSummary`, substantive implementation (not a stub) |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `PbqPlacement.openPicker()` | `$refs.topologySvg.getNodeScreenPosition(nodeId)` | `$nextTick callback` | ✓ WIRED | Line 130: `const screenPos = this.$refs.topologySvg.getNodeScreenPosition(posId)` inside `$nextTick` |
| `pickerPos` | `.pbq-inline-picker :style` | `left/top binding` | ✓ WIRED | Line 47: `:style="{ left: pickerPos.left + 'px', top: pickerPos.top + 'px' }"` — `pickerPos.left` present |
| `config.scoring_mode` | `scoringSummaryText computed` | `\|\| 'strict' default` | ✓ WIRED | Line 114: `const mode = this.config.scoring_mode \|\| 'strict'` |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DROP-01 | 03-01-PLAN.md | Dropdown-Picker erscheint direkt am angeklickten Node (positioniert) | ✓ SATISFIED | `openPicker()` computes wrapper-relative coords via `getNodeScreenPosition` + `getBoundingClientRect`; inline picker rendered at those coords; browser-approved |
| DROP-02 | 03-01-PLAN.md | scoring_mode=strict: nur exakte Gerätezuordnung wird gewertet | ✓ SATISFIED | Unit tests 1+2 pass; `scoringSummaryText` uses `scoring_mode \|\| 'strict'`; strict branch in utility returns no percentage |
| DROP-03 | 03-01-PLAN.md | scoring_mode=partial: anteilige Punkte bei Teiltreffern | ✓ SATISFIED | Unit tests 3+4 pass; partial branch returns `X / Y korrekt (Z%)` |

No orphaned requirements: REQUIREMENTS.md Traceability table maps DROP-01/02/03 exclusively to Phase 3. All three are marked `[x]` (complete) in REQUIREMENTS.md. No Phase 3 requirements exist beyond these three.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | — | — | No anti-patterns found |

Checked: `TODO`, `FIXME`, `HACK`, `PLACEHOLDER`, `return null`, `return {}`, `console.log` — all clean in `PbqPlacement.vue` and `pbqScoringMode.js`.

### Git Commits Verified

| Hash | Description |
|------|-------------|
| `8e30528` | test(03-01): add failing unit tests for scoringSummary — confirmed exists |
| `d00baa6` | feat(03-01): implement inline picker overlay + scoring mode summary — confirmed exists |

### Human Verification Required

SUMMARY.md documents browser verification as completed and approved by the user (Task 3, all 10 steps). The following items are flagged for completeness and remain open for independent re-confirmation:

#### 1. Visual Picker Positioning

**Test:** Open a topology-based placement question in learning-dev, click any SVG node.
**Expected:** Picker popup appears directly above the clicked node, centered horizontally. Not at the top-left of the page (viewport coordinate bug), not below the diagram.
**Why human:** Absolute positioning with wrapper-relative coords can only be confirmed visually in-browser with a rendered SVG topology.

#### 2. Scroll-to-Close Behavior

**Test:** Open the inline picker, then scroll the page.
**Expected:** Picker closes automatically.
**Why human:** Requires live browser interaction to confirm the scroll event listener fires and closes the picker.

#### 3. Image-Mode Regression

**Test:** Open an image-mode placement question (not SVG topology).
**Expected:** Below-diagram device picker appears and functions as before — no regression.
**Why human:** Requires browser interaction with a non-SVG question type to confirm the `!topologyConfig` gate works in practice.

### Gaps Summary

No gaps — all automated checks passed. Human verification items were confirmed approved by the user in SUMMARY.md Task 3 (all 10 browser steps). The human_needed status reflects that visual positioning behavior cannot be fully confirmed by static analysis alone.

---

_Verified: 2026-03-17T05:50:30Z_
_Verifier: Claude (gsd-verifier)_
