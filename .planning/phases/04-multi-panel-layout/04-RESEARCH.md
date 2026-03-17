# Phase 4: Multi-Panel Layout - Research

**Researched:** 2026-03-17
**Domain:** Vue 2.7 component composition, CSS layout, Nextcloud App CSP
**Confidence:** HIGH

## Summary

Phase 4 adds a `multi_panel` layout mode to PbqRenderer.vue that renders PbqCli (left) and NetworkTopologySvg via PbqPlacement (right) side by side. Both child components already exist and are fully functional as standalone units — the work here is purely orchestration and layout.

The implementation is a thin wrapper: a new `PbqMultiPanel.vue` component (or an inline branch in PbqRenderer) that places both components in a CSS Flexbox split. No new state logic is required. The CLI and topology panels operate independently; they each manage their own internal state (terminal history / node picker) with no cross-panel coupling needed.

The only complexity is responsive CSS (stack on < 768 px), picker position recalculation after layout shift, and wiring `onUpdate` for both panels into the same `localAnswer` object in PbqRenderer.

**Primary recommendation:** Create `PbqMultiPanel.vue` as a thin composition component that accepts the same `config`, `value`, `disabled` props as other PBQ subtypes. Register it in PbqRenderer as `subtype === 'multi_panel'`. Use CSS Flexbox with a single `@media` breakpoint.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| PANEL-01 | multi_panel=true zeigt CLI und Topologie nebeneinander | CSS Flexbox split with `flex-direction: row`; PbqCli left, PbqPlacement/NetworkTopologySvg right |
| PANEL-02 | Responsive Fallback: untereinander auf kleinen Screens (<768px) | `@media (max-width: 768px) { flex-direction: column }` — single breakpoint sufficient |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Vue 2.7 | ^2.7.16 | Component framework | Project constraint |
| @nextcloud/vue | ^8.20.0 | NC UI components (NcButton) | Project constraint |
| CSS Flexbox | native | Two-column layout | No extra deps, CSP-safe |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| PbqCli.vue | existing | CLI terminal panel | Left panel |
| PbqPlacement.vue | existing | SVG topology + inline picker | Right panel |
| NetworkTopologySvg.vue | existing | Renders topology JSON | Embedded in PbqPlacement |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| New PbqMultiPanel.vue | Inline v-else-if branch in PbqRenderer | Inline keeps fewer files but PbqRenderer already has enough branches. Separate component is cleaner. |
| CSS Flexbox | CSS Grid | Grid offers more control, but Flexbox is sufficient for 2-column equal split. Flex is simpler to write and debug. |

**Installation:**
No new packages required.

## Architecture Patterns

### Recommended Project Structure
```
src/
├── components/
│   ├── PbqRenderer.vue      # add multi_panel branch (v-else-if)
│   ├── PbqMultiPanel.vue    # NEW: thin composition wrapper
│   ├── PbqCli.vue           # unchanged
│   └── PbqPlacement.vue     # unchanged
```

### Pattern 1: Composition via Wrapper Component
**What:** PbqMultiPanel.vue receives the same props interface as other PBQ subtypes and delegates to PbqCli and PbqPlacement internally.
**When to use:** When two independent interactive components must appear side-by-side and share a single answer object.

**Prop contract (from PbqRenderer perspective):**
```javascript
// PbqRenderer.vue — new branch (after PbqCable):
<PbqMultiPanel
  v-else-if="subtype === 'multi_panel'"
  :config="config"
  :value="localAnswer"
  :disabled="disabled"
  @update="onUpdate"
/>
```

**PbqMultiPanel.vue internal structure:**
```vue
<template>
  <div class="pbq-multi-panel">
    <div class="pbq-panel pbq-panel--cli">
      <PbqCli
        :config="cliConfig"
        :value="cliValue"
        :disabled="disabled"
        @update="onCliUpdate"
      />
    </div>
    <div class="pbq-panel pbq-panel--topology">
      <PbqPlacement
        :config="placementConfig"
        :value="placementValue"
        :disabled="disabled"
        :topology-config="topologyConfig"
        @update="onPlacementUpdate"
      />
    </div>
  </div>
</template>
```

**Config schema for multi_panel:**
```json
{
  "pbq_subtype": "multi_panel",
  "pbq_config": {
    "cli": { /* PbqCli config: domain, terminals, command_outputs */ },
    "placement": { /* PbqPlacement config: positions, device_options, scoring_mode */ },
    "topology": { /* NetworkTopologySvg topology: nodes, links */ }
  }
}
```

**Answer object structure:**
```json
{
  "cli": { "Router": ["Router> show ip route", "..."] },
  "placement": { "node1": "router", "node2": "switch" }
}
```

`onUpdate` from PbqMultiPanel emits `(namespace, subvalue)` — PbqRenderer's existing `onUpdate(key, value)` handles this via `$set(this.localAnswer, namespace, subvalue)`.

### Pattern 2: CSS Flexbox Split with Responsive Breakpoint
**What:** Two-column layout using Flexbox, collapsing to single column below 768 px.
**When to use:** Always for multi_panel — this is the sole layout pattern.

```css
/* Source: MDN Flexbox docs + project convention */
.pbq-multi-panel {
  display: flex;
  flex-direction: row;
  gap: 16px;
  align-items: flex-start;
}

.pbq-panel {
  flex: 1;
  min-width: 0; /* prevents flex children from overflowing */
}

@media (max-width: 768px) {
  .pbq-multi-panel {
    flex-direction: column;
  }
}
```

`min-width: 0` on flex children is critical — without it, `PbqCli`'s terminal (which uses `max-height` and `overflow-y: auto`) can cause horizontal overflow.

### Anti-Patterns to Avoid
- **Hard-coding pixel widths on panels:** Flex equal split (`flex: 1`) adapts to container width in NC's variable-width content area.
- **Nesting position:relative on the multi-panel wrapper without accounting for PbqPlacement's inline picker:** PbqPlacement already uses `position: absolute` for the inline picker relative to `.pbq-diagram-wrapper`. The multi-panel container must not introduce a new stacking context that clips the picker. Avoid `overflow: hidden` on `.pbq-multi-panel`.
- **Forwarding the full flat `value` object to both children:** PbqCli and PbqPlacement have overlapping key namespaces if not scoped. Use `value.cli` / `value.placement` namespacing at the multi-panel level.
- **Using v-html anywhere:** Project hard constraint (NC CSP). Not relevant here (no SVG or HTML injection needed), but keep the pattern.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Responsive layout | Custom JS resize observer | CSS `@media` breakpoint | CSS is declarative, zero JS overhead, CSP-safe |
| Panel scroll sync | Custom scroll sync logic | Independent scroll per panel | Panels are independent — no sync needed |
| Picker position in multi-panel | Custom coordinate math | Existing `getNodeScreenPosition()` in NetworkTopologySvg | Already accounts for SVG viewBox scaling via `getScreenCTM()` |

**Key insight:** All hard work (CLI state machine, SVG rendering, inline picker positioning) is already done in Phases 1–3. Phase 4 is layout composition only.

## Common Pitfalls

### Pitfall 1: Inline picker offset wrong in multi-panel context
**What goes wrong:** PbqPlacement's inline picker uses `getBoundingClientRect()` of `.pbq-diagram-wrapper` to compute picker position relative to the wrapper. When the wrapper is inside a flex column, the offset is still correct — but only if no ancestor has `overflow: hidden` or `transform` that creates a new containing block.
**Why it happens:** `getBoundingClientRect()` returns viewport-relative coordinates; the subtraction of `wRect.left/top` is self-correcting as long as no clipping ancestor changes the visual position.
**How to avoid:** Keep `.pbq-multi-panel` without `overflow: hidden`. Use `overflow: visible` (default) on panel wrappers.
**Warning signs:** Picker appears offset from node by a fixed amount matching the left panel's width.

### Pitfall 2: Terminal max-height collapses in flex context
**What goes wrong:** `.pbq-terminal-body` has `max-height: 280px`. In a flex row, if the panel has no explicit height, the terminal looks fine. But if `align-items: stretch` is used on the multi-panel, the terminal body may expand unexpectedly.
**Why it happens:** Flex stretch fills the cross-axis; nested elements with `max-height` may conflict.
**How to avoid:** Use `align-items: flex-start` on `.pbq-multi-panel` (already in the recommended pattern above).
**Warning signs:** Terminal body taller than 280 px or panels at different heights causing alignment issues.

### Pitfall 3: totalCount / answeredCount in PbqRenderer does not cover multi_panel
**What goes wrong:** PbqRenderer's `totalCount` computed has a `switch` on `subtype`. There is no `case 'multi_panel'`. It returns `0` by default, causing the "X / 0 beantwortet" footer to always show 0.
**Why it happens:** New subtype was not added to the existing switch.
**How to avoid:** Add `case 'multi_panel':` that sums CLI terminals + placement positions from the nested config.
**Warning signs:** Footer shows "0 / 0 beantwortet" when questions are answered.

### Pitfall 4: Vue 2 reactivity — nested answer update
**What goes wrong:** PbqMultiPanel emits `@update(namespace, subvalue)`. PbqRenderer's `onUpdate` calls `this.$set(this.localAnswer, key, value)`. If `subvalue` is an object (e.g., `{ Router: [...] }`) and is later mutated internally, Vue 2 won't detect the mutation.
**Why it happens:** Vue 2 cannot detect property addition/deletion on nested plain objects without `$set`.
**How to avoid:** PbqMultiPanel should always emit a fresh copy: `this.$emit('update', 'cli', { ...newCliValue })`. PbqCli already does this (see `[...history]` copy in `submitCommand`).
**Warning signs:** Answer state updates don't trigger re-render in parent.

## Code Examples

### PbqMultiPanel.vue — minimal shell
```vue
<!-- Source: derived from existing PbqCli.vue + PbqPlacement.vue prop contracts -->
<template>
  <div class="pbq-multi-panel">
    <div class="pbq-panel pbq-panel--cli">
      <PbqCli
        :config="config.cli || {}"
        :value="value.cli || {}"
        :disabled="disabled"
        @update="onCliUpdate"
      />
    </div>
    <div class="pbq-panel pbq-panel--topology">
      <PbqPlacement
        :config="config.placement || {}"
        :value="value.placement || {}"
        :disabled="disabled"
        :topology-config="config.topology || null"
        @update="onPlacementUpdate"
      />
    </div>
  </div>
</template>

<script>
import PbqCli from './PbqCli.vue'
import PbqPlacement from './PbqPlacement.vue'

export default {
  name: 'PbqMultiPanel',
  components: { PbqCli, PbqPlacement },
  props: {
    config:   { type: Object, required: true },
    value:    { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
  },
  methods: {
    onCliUpdate(termName, history) {
      const cliVal = { ...(this.value.cli || {}), [termName]: history }
      this.$emit('update', 'cli', cliVal)
    },
    onPlacementUpdate(posId, device) {
      const placementVal = { ...(this.value.placement || {}), [posId]: device }
      this.$emit('update', 'placement', placementVal)
    },
  },
}
</script>

<style scoped>
.pbq-multi-panel {
  display: flex;
  flex-direction: row;
  gap: 16px;
  align-items: flex-start;
}
.pbq-panel { flex: 1; min-width: 0; }
@media (max-width: 768px) {
  .pbq-multi-panel { flex-direction: column; }
}
</style>
```

### PbqRenderer.vue — totalCount extension
```javascript
// Source: existing PbqRenderer.vue switch block, extend with multi_panel case
case 'multi_panel': {
  const cliTerms = (cfg.cli && cfg.cli.terminals || []).length
  const placementPos = (cfg.placement && cfg.placement.positions || []).length
  return cliTerms + placementPos
}
```

### PbqRenderer.vue — new v-else-if branch
```vue
<PbqMultiPanel
  v-else-if="subtype === 'multi_panel'"
  :config="config"
  :value="localAnswer"
  :disabled="disabled"
  @update="onUpdate"
/>
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Each PBQ subtype is fully standalone | Multi-panel composes two subtypes | Phase 4 | Enables combined CLI+topology questions |
| Flat `value` object keyed by terminal/position name | Namespaced `value.cli` / `value.placement` | Phase 4 | Prevents key collisions between panels |

**No deprecated patterns apply to this phase** — it is entirely additive.

## Open Questions

1. **Panel width ratio**
   - What we know: Equal split (`flex: 1` each) is simplest.
   - What's unclear: Whether the CLI terminal or topology diagram typically needs more horizontal space in real questions.
   - Recommendation: Start with equal split. If config authors need control, a future `panel_weights` config key (`[0.4, 0.6]` etc.) can be added without breaking the current interface.

2. **Hint text placement in multi_panel**
   - What we know: PbqCli renders `config.hint` internally at its own top. PbqPlacement has no hint field.
   - What's unclear: Should multi_panel support a top-level hint above both panels?
   - Recommendation: If `config.hint` is present in the multi_panel config, render it above the flex row in PbqMultiPanel (same pattern as PbqCli's hint block).

3. **answeredCount for multi_panel in PbqRenderer**
   - What we know: `answeredCount` is `Object.keys(this.localAnswer).length` — this counts top-level keys (`cli`, `placement`), not sub-items.
   - What's unclear: Whether the footer should show "2 / N" (namespaces answered) or actual answered items.
   - Recommendation: Keep simple — count sub-keys: `Object.keys(this.localAnswer.cli || {}).length + Object.keys(this.localAnswer.placement || {}).length`. Handle in `answeredCount` computed with a multi_panel branch.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Jest (via @vue/test-utils for Vue 2) |
| Config file | check `app/jest.config.js` or `app/package.json#jest` |
| Quick run command | `cd app && npx jest --testPathPattern=PbqMultiPanel` |
| Full suite command | `cd app && npx jest` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PANEL-01 | multi_panel renders both PbqCli and PbqPlacement in row layout | unit | `npx jest --testPathPattern=PbqMultiPanel -t "renders both panels"` | Wave 0 |
| PANEL-02 | Responsive: column layout class applied at narrow viewport | unit/smoke | `npx jest --testPathPattern=PbqMultiPanel -t "responsive"` | Wave 0 |

### Sampling Rate
- **Per task commit:** `cd app && npx jest --testPathPattern=PbqMultiPanel`
- **Per wave merge:** `cd app && npx jest`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `app/src/components/__tests__/PbqMultiPanel.spec.js` — covers PANEL-01, PANEL-02
- [ ] Confirm jest config path: `app/jest.config.js` — verify `@vue/vue2-jest` transform is present (used by Phases 1–3)

## Sources

### Primary (HIGH confidence)
- Direct code inspection: `PbqCli.vue`, `PbqPlacement.vue`, `NetworkTopologySvg.vue`, `PbqRenderer.vue` — prop contracts, emit signatures, existing CSS patterns
- `REQUIREMENTS.md` + `ROADMAP.md` + `STATE.md` — requirement IDs, phase boundaries, accumulated decisions
- `CLAUDE.md` — Vue 2.7, Webpack 5, no v-html constraint

### Secondary (MEDIUM confidence)
- MDN Flexbox: `flex: 1` + `min-width: 0` pattern for preventing overflow in flex children — well-established pattern
- Vue 2 reactivity docs: `$set` required for new object keys — verified from project's own existing usage in PbqCli.vue (`this.$set(this.localHistory, term.name, history)`)

### Tertiary (LOW confidence)
- None — all findings are derived from first-party code inspection.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — stack is identical to Phases 1–3, verified from package.json and component files
- Architecture: HIGH — prop contracts and emit signatures are directly readable from existing components
- Pitfalls: HIGH — derived from close reading of existing component logic (picker positioning, Vue 2 reactivity usage, totalCount switch)

**Research date:** 2026-03-17
**Valid until:** 2026-06-17 (stable Vue 2.7 ecosystem, no fast-moving dependencies)
