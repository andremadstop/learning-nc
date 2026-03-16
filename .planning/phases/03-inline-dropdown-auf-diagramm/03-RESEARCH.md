# Phase 3: Inline-Dropdown auf Diagramm — Research

**Researched:** 2026-03-16
**Domain:** Vue 2.7 absolutely-positioned overlay on SVG, scoring_mode branching, PHP partial scoring
**Confidence:** HIGH (all findings from direct codebase analysis; no external dependencies needed)

---

## Summary

Phase 3 adds an inline dropdown picker that appears **directly on top of** a clicked SVG node,
using absolute CSS positioning driven by `getNodeScreenPosition()` already built in Phase 2.
The picker lists `config.device_options` (same array PbqPlacement already iterates for its
below-diagram picker). The user selects a device, the selection is stored via the existing
`@update` / `onUpdate` / `$emit('update', posId, device)` contract — no changes to PbqRenderer
or the submission/scoring backend.

The only **new behavior** is `scoring_mode` on the config object:
- `strict` (default if absent): every position must be exactly right — same as current placement scoring.
- `partial`: proportional points per correctly assigned node — already how the PHP backend works today.

Critically, `scorePbqAnswer()` in `TrainingService.php` already computes `points / maxPoints` for
the `placement` subtype (line 1190–1196). The `>=0.5` threshold for `is_correct` is already there.
**No PHP changes are needed for scoring_mode.** The frontend reads `scoring_mode` from `config`
to choose what to show the user as feedback; the backend always does proportional scoring.

The entire Phase 3 delta is:
1. Replace the `<div class="pbq-device-picker">` panel (below-diagram) with a `<div>` positioned
   absolutely at the node's screen coordinates.
2. Add `scoring_mode` awareness to the feedback/summary display in `PbqPlacement`.
3. Unit test the pure scoring logic (`scoringModeLabel`, threshold logic) in a JS utility.

**Primary recommendation:** Implement the inline picker as an absolutely-positioned `<div>` inside
`.pbq-diagram-wrapper` (which already has `position: relative`). Call `getNodeScreenPosition()`
via `this.$refs.topologySvg.getNodeScreenPosition(nodeId)` in `PbqPlacement.openPicker()` to get
left/top values. Use `transform: translate(-50%, -100%)` to center the picker above the node.

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| DROP-01 | Dropdown-Picker erscheint direkt am angeklickten Node (positioniert) | `getNodeScreenPosition()` is already implemented and exposed via `$refs.topologySvg` in PbqPlacement; `.pbq-diagram-wrapper` already has `position:relative` — absolute child positioning works out of the box |
| DROP-02 | scoring_mode=strict: nur exakte Gerätezuordnung wird gewertet | `scorePbqAnswer()` already scores placement proportionally; for strict mode the frontend shows a binary correct/wrong summary. Backend behavior unchanged. |
| DROP-03 | scoring_mode=partial: anteilige Punkte bei Teiltreffern | Already the backend default (`points / maxPoints`). Frontend displays the ratio. The `scoring_mode` field in `pbq_config` is purely a frontend display hint. |
</phase_requirements>

---

## Standard Stack

### Core (no new dependencies)

| Library | Version | Purpose | Notes |
|---------|---------|---------|-------|
| Vue 2.7 | existing | `data()` for picker position + absolute CSS overlay | Already in use |
| CSS `position: absolute` | — | Picker overlay positioned relative to `.pbq-diagram-wrapper` | `.pbq-diagram-wrapper` already has `position: relative` |
| `getNodeScreenPosition()` | Phase 2 | Returns `{x, y}` screen-space coords of a node | Exposed on `$refs.topologySvg` |

No new npm packages. No PHP changes. No new API endpoints. No DB migrations.

### Installation

```bash
# No new packages needed
```

---

## Architecture Patterns

### Component Structure (delta from Phase 2)

```
app/src/components/
├── PbqPlacement.vue    MODIFIED: openPicker() stores {left,top}, picker div repositioned
└── (nothing else changes)
```

No new files. PbqRenderer, TrainingService, and all other components are untouched.

### Pattern 1: Absolute-Positioned Inline Picker

**What:** The picker `<div>` moves from below the diagram into `.pbq-diagram-wrapper` as an
absolutely-positioned overlay at the node's computed screen position.

**Key insight:** `.pbq-diagram-wrapper` already has `position: relative` (line 97 in PbqPlacement.vue).
An absolutely-positioned child will be offset relative to this wrapper. `getNodeScreenPosition()`
returns viewport-space coordinates — to get wrapper-relative coordinates, subtract the wrapper's
`getBoundingClientRect().left` and `.top`.

**Template (diff):**
```html
<!-- PbqPlacement.vue — inside .pbq-diagram-wrapper, after <NetworkTopologySvg> -->
<div
  v-if="activePosId && pickerPos"
  class="pbq-inline-picker"
  :style="{ left: pickerPos.left + 'px', top: pickerPos.top + 'px' }"
>
  <p class="pbq-picker-title"><strong>{{ labelFor(activePosId) }}</strong></p>
  <button
    v-for="device in config.device_options"
    :key="device"
    class="pbq-device-btn"
    :class="{ 'pbq-device-btn--selected': value[activePosId] === device }"
    @click="assignDevice(activePosId, device)"
  >{{ device }}</button>
  <button class="pbq-device-btn pbq-device-btn--cancel" @click="closePicker">Cancel</button>
</div>
```

**Script changes:**
```javascript
// data() additions
pickerPos: null,   // { left: Number, top: Number } — wrapper-relative px

// openPicker — now fetches position
openPicker(nodeId) {
  this.activePosId = nodeId
  this.$nextTick(() => {
    if (!this.$refs.topologySvg) {
      this.pickerPos = null
      return
    }
    const screenPos = this.$refs.topologySvg.getNodeScreenPosition(nodeId)
    if (!screenPos) {
      this.pickerPos = null
      return
    }
    const wrapper = this.$el.querySelector('.pbq-diagram-wrapper')
    const wRect = wrapper ? wrapper.getBoundingClientRect() : { left: 0, top: 0 }
    this.pickerPos = {
      left: screenPos.x - wRect.left,
      top:  screenPos.y - wRect.top,
    }
  })
},

closePicker() {
  this.activePosId = null
  this.pickerPos = null
},
```

**CSS for picker:**
```css
.pbq-inline-picker {
  position: absolute;
  transform: translate(-50%, calc(-100% - 8px));  /* center above node */
  background: var(--color-main-background);
  border: 1px solid var(--color-border-dark);
  border-radius: 8px;
  box-shadow: 0 4px 16px rgba(0,0,0,.18);
  padding: 10px 12px;
  z-index: 100;
  min-width: 160px;
  max-width: 240px;
}
```

**Why `transform: translate(-50%, calc(-100% - 8px))`:** Centers the picker horizontally on the
node and positions it 8px above. This is the standard tooltip positioning pattern. No JS geometry
calculation beyond the single `left`/`top` from `getNodeScreenPosition`.

### Pattern 2: scoring_mode Display Logic

**What:** `pbq_config.scoring_mode` (string: `"strict"` | `"partial"`, default `"strict"`) is
read by `PbqPlacement` only for summary display. The backend always scores proportionally.

**Where scoring_mode lives:** In `pbq_config` alongside `positions` and `device_options`:
```json
{
  "positions": [...],
  "device_options": ["Router", "Switch", "Firewall", "Server"],
  "scoring_mode": "partial"
}
```

**Frontend use:**
- `scoring_mode=strict` (or absent): summary shows "correct" only when ALL positions match.
  The backend `is_correct` flag (>= 50% correct) drives XP, but the summary label to the user
  says "All correct" vs "Incorrect".
- `scoring_mode=partial`: summary shows "X / Y correct (Z%)". The user sees partial credit.

**No PHP change needed** because the backend already computes `points` and `max_points` and
stores them in `answer_ids` JSON for review. The frontend reads `scoring_mode` from the question's
`pbq_config` when rendering feedback.

**Summary computed property example:**
```javascript
scoringSummary() {
  const mode = this.config.scoring_mode || 'strict'
  const positions = this.config.positions || []
  const correct = positions.filter(p => this.value[p.id] === p.correct).length
  const total = positions.length
  if (mode === 'partial') {
    return `${correct} / ${total} korrekt`
  }
  return correct === total ? 'Alle korrekt' : 'Nicht vollständig korrekt'
}
```

Note: `p.correct` (the correct device for that position) is read from `pbq_config.positions[]`.
This is already the case in `scorePbqAnswer()` PHP-side (line 1192). The frontend needs access to
`positions[].correct` — it already has the full `config` prop.

### Pattern 3: Fallback for Image Mode and Grid Mode

The inline picker should only activate in SVG topology mode (`topologyConfig` present). In image
mode or grid mode, the existing below-diagram picker (`pbq-device-picker`) remains unchanged.

**Approach:** Keep both picker variants in the template; gate on `topologyConfig`:
- `topologyConfig` present: use inline picker (`pickerPos`-driven)
- image mode / grid mode: keep existing `.pbq-device-picker` below diagram

This preserves backwards compatibility for existing placement questions that use image background.

### Anti-Patterns to Avoid

- **Calculating picker position synchronously in openPicker():** The SVG may not be laid out
  at click time if a re-render is pending. Always wrap in `$nextTick`.
- **Using `position: fixed` for the picker:** Fixed positioning is relative to the viewport,
  not the wrapper. Absolute within the wrapper is the correct approach for Nextcloud's
  widget-based rendering (the app may be inside an iframe or scrollable panel).
- **Putting scoring logic in the frontend:** Never compute `is_correct` or award XP in JS.
  The PHP `scorePbqAnswer()` is authoritative.
- **Reading `positions[].correct` as a hint in the review UI before submission:** Only show
  the correct answer AFTER submission (when `disabled=true`). This is the same contract as
  existing MCQ feedback.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Coordinate transform from SVG to screen | Custom CSS geometry | `getNodeScreenPosition()` in NetworkTopologySvg | Already implemented in Phase 2, handles getScreenCTM() |
| Partial scoring | Custom PHP scoring function | `scorePbqAnswer()` already does `points/maxPoints` | No changes needed |
| Dropdown widget | Custom floating select element | Plain absolutely-positioned `<div>` with buttons | Consistent with existing `.pbq-device-btn` styling; simpler than a true `<select>` for few options (< 10) |
| Z-index management | JS z-index orchestration | Single `z-index: 100` on picker | Nextcloud's NC apps use `--color-*` tokens and simple z-index; no stacking context issues at this level |

---

## Common Pitfalls

### Pitfall 1: getNodeScreenPosition() Returns Viewport Coords, Not Wrapper Coords

**What goes wrong:** `getNodeScreenPosition()` returns `{x, y}` in **screen (viewport) pixel space**.
If you use these directly as `left`/`top` on the absolutely-positioned picker inside `.pbq-diagram-wrapper`,
the picker appears at the wrong position (viewport-level offset instead of wrapper-relative).

**Why it happens:** `matrixTransform(getScreenCTM())` produces viewport coordinates by definition.

**How to avoid:** Subtract the wrapper element's `getBoundingClientRect().left` and `.top`:
```javascript
const wRect = this.$el.querySelector('.pbq-diagram-wrapper').getBoundingClientRect()
this.pickerPos = {
  left: screenPos.x - wRect.left,
  top:  screenPos.y - wRect.top,
}
```

**Warning signs:** Picker appears in the upper-left of the page regardless of which node is clicked.

### Pitfall 2: Stale Position After Scroll or Resize

**What goes wrong:** The picker position is computed once on click. If the user scrolls the page
after the picker is open, the picker stays at the CSS `left`/`top` values but the node has moved.

**Why it happens:** `pickerPos` is a one-shot computation, not reactive to scroll/resize.

**How to avoid:** Close the picker on scroll (`window.addEventListener('scroll', this.closePicker)`
in `mounted`, remove in `beforeDestroy`). For resize: close on window resize or recompute via
ResizeObserver. For Phase 3, closing on scroll is sufficient (same pattern as most tooltip libs).

**Warning signs:** Picker appears detached from its node when user scrolls.

### Pitfall 3: Picker Overflows SVG Wrapper Bounds

**What goes wrong:** For nodes at the top edge of the topology, `translate(-50%, -100% - 8px)`
positions the picker above the wrapper boundary. The wrapper has no `overflow: visible` guarantee
inside NC's layout.

**Why it happens:** The transform moves the picker UP, but if the wrapper has a small top margin,
the picker is clipped.

**How to avoid:** Add `overflow: visible` to `.pbq-diagram-wrapper` (it currently has
`display: inline-block; max-width: 100%` — no overflow set). Alternatively, detect if node is in
the top third and flip to `translate(-50%, 8px)` (below the node). For Phase 3, `overflow: visible`
is the simpler fix.

**Warning signs:** Picker appears cut off at the top when clicking nodes near the top of the diagram.

### Pitfall 4: scoring_mode and positions[].correct Mismatch

**What goes wrong:** The frontend summary computes `value[pos.id] === pos.correct` but `pos.correct`
is not present in the `config.positions` array if the question was authored without a `correct` field.

**Why it happens:** `pbq_config.positions` schema is not strictly validated — a position might
only have `{id, label, x, y}` without `correct`.

**How to avoid:** Guard with `pos.correct !== undefined` before comparing. If `correct` is missing
for any position, the summary is undefined (fallback: "—"). This is an authoring data quality issue,
not a code bug. The PHP scoring already guards: `$userAnswers[$pos['id']] === $pos['correct']` only
adds a point if both sides are truthy.

**Warning signs:** Summary always shows "0 / N correct" even when assignments look right.

### Pitfall 5: Click Event Propagation Closes Picker Immediately

**What goes wrong:** If there's a global click listener that closes the picker (e.g. clicking
outside to dismiss), clicking a device button inside the picker triggers both the button action
AND the outside-click handler — closing the picker before the assignment is processed.

**Why it happens:** Event bubbling propagates the button click to document-level listeners.

**How to avoid:** Use `@click.stop` on the picker container:
```html
<div class="pbq-inline-picker" @click.stop>
```
This prevents the picker's internal clicks from bubbling to any parent close-handler.

---

## Code Examples

### openPicker() with Position Computation

```javascript
// Source: PbqPlacement.vue — MODIFIED openPicker
openPicker(nodeId) {
  this.activePosId = nodeId
  this.pickerPos = null  // reset first to avoid stale position flash

  if (!this.topologyConfig || !this.$refs.topologySvg) {
    // Image mode or grid mode: fall through to existing below-picker
    return
  }

  this.$nextTick(() => {
    const screenPos = this.$refs.topologySvg.getNodeScreenPosition(nodeId)
    if (!screenPos) return

    const wrapper = this.$el.querySelector('.pbq-diagram-wrapper')
    if (!wrapper) return

    const wRect = wrapper.getBoundingClientRect()
    this.pickerPos = {
      left: screenPos.x - wRect.left,
      top:  screenPos.y - wRect.top,
    }
  })
},
```

### scoring_mode-Aware Summary

```javascript
// Source: PbqPlacement.vue computed properties — NEW
scoringSummary() {
  const mode = this.config.scoring_mode || 'strict'
  const positions = this.config.positions || []
  if (!positions.length) return ''
  const correct = positions.filter(p => p.correct !== undefined && this.value[p.id] === p.correct).length
  const total = positions.length
  if (mode === 'partial') {
    const pct = Math.round((correct / total) * 100)
    return `${correct} / ${total} korrekt (${pct}%)`
  }
  return correct === total ? 'Alle korrekt' : `${correct} / ${total} korrekt`
},
```

### Unit-Testable Scoring Logic (pure JS utility)

The scoring mode display logic is simple enough to test inline without extracting to a utility.
However, the threshold logic can be unit-tested:

```javascript
// tests/unit/pbqScoringMode.test.js
import { describe, it, expect } from 'vitest'

function scoringSummary(positions, value, mode = 'strict') {
  if (!positions.length) return ''
  const correct = positions.filter(p => p.correct !== undefined && value[p.id] === p.correct).length
  const total = positions.length
  if (mode === 'partial') {
    const pct = Math.round((correct / total) * 100)
    return `${correct} / ${total} korrekt (${pct}%)`
  }
  return correct === total ? 'Alle korrekt' : `${correct} / ${total} korrekt`
}

describe('scoringSummary', () => {
  const positions = [
    { id: 'n1', correct: 'Router' },
    { id: 'n2', correct: 'Switch' },
    { id: 'n3', correct: 'Firewall' },
  ]

  it('strict mode: all correct', () => {
    expect(scoringSummary(positions, { n1: 'Router', n2: 'Switch', n3: 'Firewall' }, 'strict'))
      .toBe('Alle korrekt')
  })

  it('strict mode: partial correct shows count', () => {
    expect(scoringSummary(positions, { n1: 'Router', n2: 'Switch', n3: 'Server' }, 'strict'))
      .toBe('2 / 3 korrekt')
  })

  it('partial mode shows percentage', () => {
    expect(scoringSummary(positions, { n1: 'Router', n2: 'Switch', n3: 'Server' }, 'partial'))
      .toBe('2 / 3 korrekt (67%)')
  })

  it('empty positions returns empty string', () => {
    expect(scoringSummary([], {}, 'strict')).toBe('')
  })

  it('missing correct field is excluded from count', () => {
    const posNoCorrect = [{ id: 'n1' }, { id: 'n2', correct: 'Router' }]
    expect(scoringSummary(posNoCorrect, { n1: 'Router', n2: 'Router' }, 'partial'))
      .toBe('1 / 2 korrekt (50%)')
  })
})
```

---

## State of the Art

| Old Approach | Current Approach | Impact |
|--------------|------------------|--------|
| Below-diagram picker panel | Inline picker anchored to node | Spatial context — user sees which node they're configuring |
| No scoring_mode — always binary | scoring_mode=partial shows ratio | Fairer feedback for partial knowledge |
| getNodeScreenPosition() not consumed | Phase 3 first consumer of Phase 2's getNodeScreenPosition() | Phase 2 API proven in production |

**Deprecated/outdated:**
- The `.pbq-device-picker` below-diagram panel is deprecated for SVG topology mode only. It
  remains for image mode and grid mode (backwards compatibility).

---

## Open Questions

1. **Should the inline picker have a close-on-outside-click behavior?**
   - What we know: Current below-picker only closes on Cancel button or assignDevice.
   - What's unclear: Is a document-level click-outside handler needed for the inline picker?
   - Recommendation: Add `@click.stop` on the picker div, and a close handler for `Escape` key.
     A full click-outside handler (document-level) is optional for Phase 3 — the Cancel button
     is sufficient for a prototype.

2. **Should scoring_mode affect backend scoring or only frontend display?**
   - What we know: PHP `scorePbqAnswer()` always uses proportional scoring (points/maxPoints, >=50% is_correct).
   - What's unclear: Should `strict` mode require 100% for `is_correct`?
   - Recommendation: Do NOT change PHP scoring for Phase 3. The `scoring_mode` field affects only
     the user-visible summary. Backend scoring is already reasonable (>=50% threshold). Phase 5
     (Author Tool) can expose scoring_mode as a config option; if exact semantics need to change,
     a dedicated PHP flag can be added then.

3. **Does the summary need to show which specific nodes are wrong?**
   - What we know: The below-diagram summary currently shows `pos.label: value` for all positions.
   - What's unclear: After submission (disabled=true), should wrong assignments be highlighted?
   - Recommendation: In `disabled` mode, compare `value[pos.id]` vs `pos.correct` and add a CSS
     class `.pbq-summary-value--wrong` (red) / `.pbq-summary-value--correct` (green). This is a
     small addition to the existing summary, not a new component.

---

## Validation Architecture

No `.planning/config.json` found — treat `nyquist_validation` as enabled.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Vitest (existing, from Phase 1/2) |
| Config file | `app/vitest.config.js` |
| Quick run command | `npm run test` |
| Full suite command | `npm run test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DROP-01 | Picker positioned at node screen coordinates | manual | Deploy + browser click-verify | N/A — DOM-coupled |
| DROP-02 | strict mode summary: shows count, not "Alle korrekt" for partial match | unit | `npm run test` | ❌ Wave 0 |
| DROP-03 | partial mode summary: shows "X / Y korrekt (Z%)" | unit | `npm run test` | ❌ Wave 0 |

**Automated coverage:** DROP-02 and DROP-03 are unit-testable as pure JS (`scoringSummary` function).
DROP-01 requires browser DOM and is verified manually on `learning-dev` after deploy.

### Sampling Rate

- **Per task commit:** `npm run test` (~1s)
- **Per wave merge:** `npm run test` + manual browser verification on learning-dev
- **Phase gate:** Unit tests green + manual click-test on topology question showing picker at node position

### Wave 0 Gaps

- [ ] `tests/unit/pbqScoringMode.test.js` — covers DROP-02 and DROP-03 (scoring summary display logic)

*(No framework changes needed — vitest config already covers `tests/unit/**/*.test.js`)*

---

## Sources

### Primary (HIGH confidence — direct codebase analysis)

- `/home/andre/Workspace/Code/learning-nc/app/src/components/PbqPlacement.vue` — existing data(), openPicker(), assignDevice(), template structure; `.pbq-diagram-wrapper` already has `position:relative`
- `/home/andre/Workspace/Code/learning-nc/app/src/components/NetworkTopologySvg.vue` — `getNodeScreenPosition()` implementation, returns `{x, y}` viewport coords; exposed via `$refs.topologySvg`
- `/home/andre/Workspace/Code/learning-nc/app/lib/Service/TrainingService.php` — `scorePbqAnswer()` at line 1177; placement scoring already proportional (lines 1189–1196); `pbqIsCorrect` at line 1002 (`>= 0.5`)
- `/home/andre/Workspace/Code/learning-nc/app/src/components/PbqRenderer.vue` — confirms `topologyConfig` prop wire; no changes needed here
- `/home/andre/Workspace/Code/learning-nc/.planning/STATE.md` — Phase 2 decisions: `openPicker signature unchanged`, `node-click passes node.id`, `ref="topologySvg"` pattern
- `/home/andre/Workspace/Code/learning-nc/.planning/REQUIREMENTS.md` — DROP-01, DROP-02, DROP-03 definitions
- `/home/andre/Workspace/Code/learning-nc/app/tests/unit/` — existing test patterns (vitest, pure JS utility modules)

### Secondary (MEDIUM confidence)

- CSS `position:absolute` + `transform:translate(-50%, calc(-100% - 8px))` tooltip positioning:
  Standard CSS tooltip pattern. Works reliably when parent has `position:relative` and
  `overflow:visible`. Confirmed applicable given `.pbq-diagram-wrapper` structure.
- Vue 2.7 `$nextTick` before DOM reads: Standard Vue pattern to ensure DOM is updated before
  reading `getBoundingClientRect()`. Established in Phase 1/2 codebase (`PbqCli.vue` uses
  `$nextTick` for scroll).

### Tertiary (LOW confidence)

- Picker z-index `100`: Assumed sufficient for NC custom app context. NC components typically
  use z-index 50–200 range for overlays. Not verified against NC's z-index scale.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero new dependencies, all patterns from existing code
- Positioning approach: HIGH — `getNodeScreenPosition()` already implemented and tested via Phase 2
- scoring_mode: HIGH — PHP backend confirmed to already support proportional scoring; frontend is pure display
- CSS overlay: MEDIUM — z-index and overflow:visible behavior in NC's layout context not verified empirically

**Research date:** 2026-03-16
**Valid until:** 2026-06-16 (Vue 2.7 LTS, no breaking changes expected; CSS positioning is stable)
