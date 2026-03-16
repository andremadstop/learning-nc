# Phase 2: SVG Topology Renderer — Research

**Researched:** 2026-03-16
**Domain:** Vue 2.7 inline SVG rendering, coordinate mapping, Nextcloud CSP
**Confidence:** HIGH (all findings from direct codebase analysis + verified Vue 2.7 SVG behavior)

---

## Summary

Phase 2 adds `NetworkTopologySvg.vue` — a new component that renders network topology diagrams as
inline SVG from a JSON node-link schema. The critical constraint is Nextcloud's CSP: no `v-html`
anywhere. SVG elements must be rendered via Vue template syntax (`<svg>`, `<g>`, `<line>`, `<text>`
etc.) bound to data. This is fully supported in Vue 2.7 — SVG elements are valid in Vue templates
without any special configuration.

The 8 device-type icons (router, switch, firewall, server, cloud, workstation, ap, wre) will be
implemented as inline SVG path data defined in a JS constant (no external SVG file loading needed).
This avoids webpack SVG loader configuration issues and CSP concerns around `<img src="...svg">`.
Each icon is a minimal set of `<path>`/`<circle>`/`<rect>` elements drawn within a normalized
28x28 viewBox, rendered via a computed icon lookup in the template.

The coordinate mapping problem (SVG viewBox coordinates → screen pixel coordinates for hotspot
overlay positioning in `PbqPlacement`) requires `getScreenCTM()` after the SVG is mounted. In
practice, Phase 3 (Inline-Dropdown) will consume the position mapping. For Phase 2, the component
exposes a `@node-click` event with the node `id` — the parent decides what to do with it. This
is the correct separation of concerns: the SVG component knows nodes, the placement component
knows hotspot overlays.

`PbqPlacement.vue` gains an optional `topologyConfig` prop (the node-link JSON object). When
present, it renders `NetworkTopologySvg.vue` instead of the fallback grid — no image URL needed.

**Primary recommendation:** Render all SVG as Vue template elements using `v-for` over nodes and
links arrays. Store icon path data as a JS constant map keyed by device type. Emit `node-click`
with node id from the SVG component; handle positioning in PbqPlacement.

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| SVG-01 | NetworkTopologySvg.vue renders JSON node-link schema without raw SVG / v-html | Vue 2.7 supports SVG elements natively in templates — `<svg>`, `<line>`, `<g>`, `<path>` are all valid template elements, no v-html needed |
| SVG-02 | Icon library with 8 device types: router, switch, firewall, server, cloud, workstation, ap, wre | Inline SVG path data in JS constant (DEVICE_ICONS map); rendered via `:d` binding on `<path>` elements inside a `<g>` icon group |
| SVG-03 | Hotspot coordinates correctly computed via getScreenCTM() after viewBox scaling | `this.$refs.svg.getScreenCTM()` after `$nextTick` gives the CTM; node (x,y) in viewBox space transforms to screen space via matrix multiplication |
| SVG-04 | PbqPlacement can use SVG topology as background instead of image URL | New `topologyConfig` prop on PbqPlacement; when present, renders NetworkTopologySvg instead of `<img>` and the fallback grid |
</phase_requirements>

---

## Standard Stack

### Core (no new dependencies needed)

| Library | Version | Purpose | Notes |
|---------|---------|---------|-------|
| Vue 2.7 | existing | SVG element rendering via template, $refs for DOM access | Already in use |
| Pure JS | — | DEVICE_ICONS constant (inline SVG paths) | No library needed |

No new npm packages. No webpack SVG loader needed (inline SVG path strings in JS).

### Installation

```bash
# No new packages — pure Vue 2.7 component work
```

---

## Architecture Patterns

### Recommended Project Structure

```
app/src/
├── components/
│   ├── NetworkTopologySvg.vue   ← NEW: SVG renderer
│   ├── PbqPlacement.vue         ← MODIFIED: adds topologyConfig prop
│   └── ...
└── utils/
    ├── cliStateMachine.js       ← existing (Phase 1)
    └── networkTopologyIcons.js  ← NEW: DEVICE_ICONS constant
```

The icons utility is extracted to a separate module so it can be reused by the Author Tool (Phase 5)
without importing the full Vue component.

### JSON Schema (node-link format)

The input schema for `NetworkTopologySvg.vue`:

```json
{
  "nodes": [
    { "id": "r1",  "type": "router",      "label": "Core Router", "x": 200, "y": 100 },
    { "id": "sw1", "type": "switch",      "label": "Access SW",   "x": 100, "y": 250 },
    { "id": "fw1", "type": "firewall",    "label": "Firewall",    "x": 300, "y": 250 },
    { "id": "pc1", "type": "workstation", "label": "PC-01",       "x": 100, "y": 400 }
  ],
  "links": [
    { "from": "r1", "to": "sw1" },
    { "from": "r1", "to": "fw1" },
    { "from": "sw1", "to": "pc1" }
  ]
}
```

Coordinates (`x`, `y`) are in viewBox units (e.g. 0–600 x 0–400). The component uses a fixed
viewBox like `"0 0 600 400"` or computes bounds from node positions + padding.

### Pattern 1: NetworkTopologySvg.vue Template Structure

**What:** SVG rendered entirely via Vue template elements. No `v-html`.
**When to use:** Always — this is the only CSP-compliant approach.

```html
<!-- Source: Vue 2.7 SVG template support — HIGH confidence -->
<template>
  <svg
    ref="svg"
    :viewBox="viewBox"
    class="nts-svg"
    xmlns="http://www.w3.org/2000/svg"
  >
    <!-- Links (render before nodes so nodes are on top) -->
    <line
      v-for="link in links"
      :key="link.from + '-' + link.to"
      :x1="nodeById(link.from).x"
      :y1="nodeById(link.from).y"
      :x2="nodeById(link.to).x"
      :y2="nodeById(link.to).y"
      class="nts-link"
    />

    <!-- Nodes -->
    <g
      v-for="node in nodes"
      :key="node.id"
      :transform="'translate(' + node.x + ',' + node.y + ')'"
      class="nts-node"
      :class="{ 'nts-node--interactive': !disabled }"
      @click="!disabled && $emit('node-click', node.id)"
    >
      <!-- Icon (14x14, centered at 0,0) -->
      <g v-if="iconPaths(node.type).length" class="nts-icon">
        <path
          v-for="(d, i) in iconPaths(node.type)"
          :key="i"
          :d="d"
          class="nts-icon-path"
        />
      </g>
      <!-- Fallback circle if no icon -->
      <circle v-else r="14" class="nts-icon-fallback" />
      <!-- Label -->
      <text y="22" text-anchor="middle" class="nts-label">{{ node.label }}</text>
    </g>
  </svg>
</template>
```

Key points:
- All SVG elements are native template elements — Vue 2.7 renders them with correct SVG namespace automatically.
- `v-for` on `<line>` and `<g>` is standard Vue template syntax.
- `:d` binding on `<path>` is a plain string attribute binding — works identically to `:href` or `:class`.
- `xmlns="http://www.w3.org/2000/svg"` is not strictly needed when SVG is inline in HTML, but harmless.

### Pattern 2: DEVICE_ICONS Constant

**What:** JS object mapping device type string → array of SVG path `d` strings.
**When to use:** `iconPaths(type)` method in the component.

Each icon is drawn in a 28x28 coordinate space, centered at `(0,0)` (i.e., paths use coordinates
from -14 to +14). This means a `<g transform="translate(x,y)">` wrapper centers the icon on the
node's (x,y) position without additional offset math.

```javascript
// Source: app/src/utils/networkTopologyIcons.js
// All paths are in -14..+14 coordinate space (28x28 icon, centered at origin)

export const DEVICE_ICONS = {
  router: [
    // Circle body + 4 directional arrows
    'M0,-10 A10,10 0 1,1 0,10 A10,10 0 1,1 0,-10',
    'M0,-14 L3,-9 M0,-14 L-3,-9',    // up arrow
    'M0,14 L3,9 M0,14 L-3,9',         // down arrow
    'M-14,0 L-9,3 M-14,0 L-9,-3',    // left arrow
    'M14,0 L9,3 M14,0 L9,-3',         // right arrow
  ],
  switch: [
    // Rectangle body + bidirectional arrows
    'M-12,-6 L12,-6 L12,6 L-12,6 Z',
    'M-8,0 L-14,0 M-14,0 L-11,-3 M-14,0 L-11,3',   // left port
    'M8,0 L14,0 M14,0 L11,-3 M14,0 L11,3',           // right port
  ],
  firewall: [
    // Shield shape
    'M0,-13 L11,-7 L11,2 Q11,10 0,14 Q-11,10 -11,2 L-11,-7 Z',
    'M-5,-3 L-5,5 M5,-3 L5,5 M-5,1 L5,1',            // bars
  ],
  server: [
    // Rack unit rectangles
    'M-10,-12 L10,-12 L10,-4 L-10,-4 Z',
    'M-10,-2 L10,-2 L10,6 L-10,6 Z',
    'M-10,8 L10,8 L10,12 L-10,12 Z',
    'M7,-9 A1.5,1.5 0 1,1 7.01,-9',                   // LED dots
    'M7,-0.5 A1.5,1.5 0 1,1 7.01,-0.5',
  ],
  cloud: [
    // Cloud silhouette via arcs
    'M-8,4 Q-14,4 -14,-2 Q-14,-8 -8,-8 Q-6,-13 0,-13 Q6,-13 8,-8 Q14,-8 14,-2 Q14,4 8,4 Z',
  ],
  workstation: [
    // Monitor + stand
    'M-10,-12 L10,-12 L10,2 L-10,2 Z',   // screen
    'M-3,2 L-3,8 M3,2 L3,8',              // stand neck
    'M-7,8 L7,8',                          // base
  ],
  ap: [
    // Wireless access point — antenna + waves
    'M0,8 L0,-4',                          // pole
    'M-6,-6 Q0,-12 6,-6',                  // outer arc
    'M-3,-4 Q0,-8 3,-4',                   // inner arc
    'M-10,10 L10,10 L8,14 L-8,14 Z',      // mount
  ],
  wre: [
    // Wireless range extender — box + arcs
    'M-8,-4 L8,-4 L8,8 L-8,8 Z',          // body
    'M-5,-6 Q0,-12 5,-6',                  // outer arc
    'M-2,-5 Q0,-9 2,-5',                   // inner arc
  ],
}
```

**Note on icon fidelity:** These are functional symbolic representations (HIGH readability at small
sizes), not pixel-perfect reproductions of commercial network diagram tools. For Phase 2, the goal
is recognizability at ~28–36px rendered size. More detailed icons can be refined later.

### Pattern 3: getScreenCTM() for Coordinate Mapping

**What:** Maps node (x,y) from SVG viewBox space to CSS pixel coordinates for overlay positioning.
**When to use:** When parent needs screen pixel position of a node (e.g. for Phase 3 Inline-Dropdown).

```javascript
// In NetworkTopologySvg.vue or PbqPlacement.vue after SVG mounts
getNodeScreenPosition(nodeId) {
  const svg = this.$refs.svg
  if (!svg) return null
  const node = this.nodes.find(n => n.id === nodeId)
  if (!node) return null

  // Create SVG point in viewBox coordinate space
  const pt = svg.createSVGPoint()
  pt.x = node.x
  pt.y = node.y

  // Transform to screen (CSS pixel) coordinates
  const ctm = svg.getScreenCTM()
  if (!ctm) return null
  const screenPt = pt.matrixTransform(ctm)

  // screenPt.x and screenPt.y are viewport-relative pixel coordinates
  // Subtract the SVG element's bounding rect to get parent-relative offsets
  const rect = svg.getBoundingClientRect()
  return {
    left: screenPt.x - rect.left,  // relative to SVG element
    top:  screenPt.y - rect.top,
  }
},
```

This method is exposed by emitting `node-click` with the node id. The parent calls it via `$refs`
if needed, or the component can emit both id and position: `$emit('node-click', node.id, pos)`.

**CRITICAL:** `getScreenCTM()` must be called after the SVG is rendered and sized. Call inside
`$nextTick` if invoked immediately after data changes. If the SVG container is inside a flex or
grid layout that changes size, positions may be stale — recompute on window resize if needed.

### Pattern 4: PbqPlacement Integration

**What:** Add `topologyConfig` prop to `PbqPlacement.vue`. When set, render `NetworkTopologySvg`
instead of `<img>` + fallback grid.

```javascript
// PbqPlacement.vue — props addition
props: {
  config:        { type: Object, required: true },
  value:         { type: Object, default: () => ({}) },
  disabled:      { type: Boolean, default: false },
  scenarioImage: { type: String, default: null },
  topologyConfig: { type: Object, default: null },  // NEW
},
```

Template change — replace the current diagram section:

```html
<!-- NEW: SVG topology (takes priority over image) -->
<NetworkTopologySvg
  v-if="topologyConfig"
  :topology="topologyConfig"
  :disabled="disabled"
  @node-click="openPicker"
/>
<!-- Existing: image fallback -->
<img v-else-if="scenarioImage" :src="scenarioImage" class="pbq-diagram-img" alt="Network diagram" />
<!-- Existing: plain grid fallback -->
<div v-else class="pbq-topology-grid">...</div>
```

When `topologyConfig` is present, the hotspot `<div>` elements used for image overlays are NOT
rendered (they depend on `pos.x_pct`/`pos.y_pct` percentage positioning on an `<img>`). Instead,
the SVG nodes are themselves clickable via `@node-click`.

**Schema mapping:** `pbq_config.positions[].id` must match `topologyConfig.nodes[].id` for the
picker to know which position a click corresponds to. The component emits the node id directly.

### Anti-Patterns to Avoid

- **Using v-html to inject SVG strings:** Never. Violates NC CSP. All SVG must be native template elements.
- **Loading SVG files via `<img src="router.svg">`:** Fails CSP in NC (img-src restrictions for custom apps). Use inline path data.
- **Calling getScreenCTM() synchronously on mount:** May return null or stale values if SVG is not yet laid out. Always use `$nextTick`.
- **Storing SVG path data as template literals with HTML:** Path `d` attributes are plain strings with no HTML — safe to bind directly.
- **Using absolute pixel positions for icon layout:** Use `transform="translate(x,y)"` on the node `<g>` — much simpler than computing element offsets.
- **Requiring webpack SVG loader:** Not configured in webpack.config.js and would require devDependency changes. Inline path strings avoid this entirely.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SVG namespace | Custom createElement with NS | Vue 2.7 native template SVG | Vue 2.7 automatically applies SVG namespace to SVG elements in templates |
| Icon rendering | External SVG file imports + loader | Inline path data in JS constant | No webpack SVG loader configured; inline paths are zero-dep, CSP-safe |
| Coordinate transform | Manual CTM math | `svg.createSVGPoint().matrixTransform(svg.getScreenCTM())` | SVG DOM API handles all viewBox/scale/zoom math correctly |
| Responsive SVG | JS resize observers | `viewBox` + `preserveAspectRatio` on the SVG element | CSS handles scaling; viewBox makes coordinates layout-independent |

---

## Common Pitfalls

### Pitfall 1: Vue 2.7 SVG Attribute Binding for `class`

**What goes wrong:** `:class` binding on SVG elements in Vue 2.7 works the same as HTML elements —
but some SVG attributes that look like they should be properties are actually XML attributes (e.g.
`text-anchor`, `stroke-width`). Using `:text-anchor` will NOT work because Vue treats hyphenated
attr names as components.

**Why it happens:** SVG uses XML attribute syntax; kebab-case attribute names need to be passed as
`:attr="value"` (camelCase is wrong for SVG) or set inline without binding.

**How to avoid:** For static SVG presentation attributes (text-anchor, stroke-width, fill), set
them in CSS using `class` or inline as non-bound attributes. Only bind dynamic values.

```html
<!-- WRONG: -->
<text :text-anchor="'middle'">...</text>

<!-- RIGHT — static attr, no binding needed: -->
<text text-anchor="middle" class="nts-label">...</text>

<!-- RIGHT — dynamic binding uses camelCase in Vue: -->
<!-- Actually for SVG attrs, just put them in CSS or use inline static -->
```

### Pitfall 2: getScreenCTM() Returns null in Hidden/Invisible Elements

**What goes wrong:** If the SVG element is inside a `display:none` container (e.g. a tab that's
not active), `getScreenCTM()` returns null. This causes a TypeError.

**Why it happens:** Browsers don't compute screen CTM for hidden elements.

**How to avoid:** Always null-check the CTM result:
```javascript
const ctm = svg.getScreenCTM()
if (!ctm) return null  // caller must handle null gracefully
```

### Pitfall 3: viewBox Computed from Node Positions — Off-by-one Clipping

**What goes wrong:** If viewBox is computed as `min_x min_y width height` from node coordinates,
nodes at the boundary are clipped because the icon extends ±14px around the center point.

**Why it happens:** ViewBox clips at exact boundary.

**How to avoid:** Add padding (e.g. 30px on all sides) when computing viewBox from node bounds:
```javascript
computed: {
  viewBox() {
    const xs = this.nodes.map(n => n.x)
    const ys = this.nodes.map(n => n.y)
    const minX = Math.min(...xs) - 30
    const minY = Math.min(...ys) - 30
    const maxX = Math.max(...xs) + 30
    const maxY = Math.max(...ys) + 30
    return `${minX} ${minY} ${maxX - minX} ${maxY - minY}`
  }
}
```

### Pitfall 4: `v-for` Key Uniqueness for Links

**What goes wrong:** Using `link.from + '-' + link.to` as key fails if there are multiple links
between the same pair of nodes (parallel links).

**Why it happens:** Duplicate keys cause Vue reactivity issues.

**How to avoid:** Either prohibit parallel links in the schema (simplest for Phase 2) or use
index as part of the key: `link.from + '-' + link.to + '-' + index`.

### Pitfall 5: NC CSP and SVG `<use>` Element

**What goes wrong:** SVG `<use href="#sprite-id">` references to an external `<defs>` or external
file may be blocked by CSP. Even inline `<use>` pointing to a `<symbol>` in a hidden `<defs>`
block may have issues in some NC versions.

**Why it happens:** NC CSP restricts resource loading URIs.

**How to avoid:** Render icon paths directly at each node instead of using `<use>` sprites. This
is the approach documented in Pattern 2 above — each node gets its own `<path>` elements. The
minor DOM overhead (8 path elements × N nodes) is negligible for topology diagrams (N ≤ 20 nodes).

### Pitfall 6: Coordinate Origin Convention

**What goes wrong:** JSON author puts node (x,y) at top-left of icon bounding box (conventional
for CSS), but the component centers the icon at (x,y). Result: all nodes appear shifted 14px down
and right from where the author intended.

**Why it happens:** Inconsistent center-vs-corner convention.

**How to avoid:** Document the convention clearly: **node (x,y) is the CENTER of the icon**. The
`transform="translate(x,y)"` approach makes this natural — icon paths are drawn around origin
(0,0), so they render centered at the node's position. Communicate this in schema docs and the
Author Tool (Phase 5).

---

## Code Examples

### NetworkTopologySvg.vue — Full Component Skeleton

```javascript
// Source: Vue 2.7 component patterns — HIGH confidence from PbqCli.vue style
export default {
  name: 'NetworkTopologySvg',
  props: {
    topology: { type: Object, required: true },  // { nodes: [], links: [] }
    disabled: { type: Boolean, default: false },
  },
  computed: {
    nodes() { return this.topology.nodes || [] },
    links() { return this.topology.links || [] },
    viewBox() {
      if (!this.nodes.length) return '0 0 400 300'
      const xs = this.nodes.map(n => n.x)
      const ys = this.nodes.map(n => n.y)
      const pad = 40
      const minX = Math.min(...xs) - pad
      const minY = Math.min(...ys) - pad
      const w = Math.max(...xs) - Math.min(...xs) + pad * 2
      const h = Math.max(...ys) - Math.min(...ys) + pad * 2
      return `${minX} ${minY} ${w} ${h}`
    },
  },
  methods: {
    nodeById(id) {
      return this.nodes.find(n => n.id === id) || { x: 0, y: 0 }
    },
    iconPaths(type) {
      return DEVICE_ICONS[type] || []
    },
    getNodeScreenPosition(nodeId) {
      const svg = this.$refs.svg
      if (!svg) return null
      const node = this.nodes.find(n => n.id === nodeId)
      if (!node) return null
      const pt = svg.createSVGPoint()
      pt.x = node.x
      pt.y = node.y
      const ctm = svg.getScreenCTM()
      if (!ctm) return null
      const screenPt = pt.matrixTransform(ctm)
      const rect = svg.getBoundingClientRect()
      return { left: screenPt.x - rect.left, top: screenPt.y - rect.top }
    },
  },
}
```

### PbqPlacement.vue — topologyConfig Prop (diff)

Current `scenarioImage` prop usage in template:
```html
<!-- Before -->
<img v-if="scenarioImage" :src="scenarioImage" class="pbq-diagram-img" alt="Network diagram" />
<div v-else class="pbq-topology-grid">...</div>
<div v-if="scenarioImage" v-for="pos in config.positions" ...hotspot divs...>
```

After integrating SVG topology:
```html
<!-- After -->
<NetworkTopologySvg
  v-if="topologyConfig"
  ref="topologySvg"
  :topology="topologyConfig"
  :disabled="disabled"
  @node-click="openPicker"
/>
<img v-else-if="scenarioImage" :src="scenarioImage" class="pbq-diagram-img" alt="Network diagram" />
<div v-else class="pbq-topology-grid">...</div>
<!-- Hotspot divs: only render for image mode, not for SVG topology -->
<div v-if="scenarioImage && !topologyConfig" v-for="pos in config.positions" ...>
```

### Vitest Unit Test Pattern

The `networkTopologyIcons.js` module is pure JS — testable without Vue:

```javascript
// tests/unit/networkTopologyIcons.test.js
import { describe, it, expect } from 'vitest'
import { DEVICE_ICONS } from '../../src/utils/networkTopologyIcons.js'

describe('DEVICE_ICONS', () => {
  const REQUIRED_TYPES = ['router', 'switch', 'firewall', 'server', 'cloud', 'workstation', 'ap', 'wre']

  it('exports all 8 required device types', () => {
    for (const type of REQUIRED_TYPES) {
      expect(DEVICE_ICONS).toHaveProperty(type)
    }
  })

  it('each device type has at least one path string', () => {
    for (const type of REQUIRED_TYPES) {
      expect(Array.isArray(DEVICE_ICONS[type])).toBe(true)
      expect(DEVICE_ICONS[type].length).toBeGreaterThan(0)
    }
  })

  it('all path strings are non-empty strings', () => {
    for (const type of REQUIRED_TYPES) {
      for (const path of DEVICE_ICONS[type]) {
        expect(typeof path).toBe('string')
        expect(path.length).toBeGreaterThan(0)
      }
    }
  })
})
```

The coordinate mapping utility (viewBox computation, `getNodeScreenPosition`) is UI-layer logic
tightly coupled to DOM APIs (`getScreenCTM`). Unit tests for these require a DOM environment
(jsdom) which vitest supports but requires additional setup. Recommend manual verification
for DOM-touching methods; unit test only the pure JS utility.

---

## State of the Art

| Old Approach | Current Approach | Impact |
|--------------|------------------|--------|
| Image URL with absolute % hotspots | Inline SVG with `getScreenCTM()` | Coordinates correct after resize/zoom |
| Fallback grid (plain HTML divs) | NetworkTopologySvg.vue with real topology rendering | Visual topology matches exam diagrams |
| External SVG sprite files | Inline path data in JS constant | No webpack loader config, CSP-safe |
| `v-html` for SVG injection | Vue template SVG elements | NC CSP compliant |

**Deprecated/outdated:**
- `pbq-topology-grid` fallback: replaced by `NetworkTopologySvg` when `topologyConfig` is present, kept as fallback when neither image nor topology is provided.

---

## Open Questions

1. **Should viewBox be fixed or computed from node positions?**
   - What we know: Node coordinates in the schema are author-defined (x,y in arbitrary units)
   - What's unclear: Should the schema define a canonical viewBox, or should it be computed?
   - Recommendation: Compute from node bounds + padding (Pattern 3 above). Simpler for authors — they just place nodes intuitively. Add optional `viewBox` field to schema as override for edge cases.

2. **What happens when a node type is unknown (not in DEVICE_ICONS)?**
   - What we know: Template uses `v-if="iconPaths(node.type).length"` with circle fallback
   - What's unclear: Should unknown types log a warning or silently use fallback?
   - Recommendation: Silent fallback to circle in production. During development, a `console.warn` is acceptable. Never throw — topology must render even with bad data.

3. **Should links support labels (e.g. interface names, link speeds)?**
   - What we know: Current schema has `links[{from, to}]` — no label field
   - What's unclear: Phase 3 (Inline-Dropdown) requirements don't mention link labels; Phase 2 requirements don't either
   - Recommendation: Implement without link labels for Phase 2. Schema is extensible — add `label` field to links in Phase 5 (Author Tool) if needed.

4. **Does PbqPlacement need to pass `topologyConfig` via `pbq_config.topology`?**
   - What we know: PbqRenderer passes `config.scenario_image` as `:scenario-image` to PbqPlacement
   - What's unclear: Should the topology be a top-level field in `pbq_config`, or nested?
   - Recommendation: `pbq_config.topology` as a dedicated field (parallel to `scenario_image`). PbqRenderer passes it as `:topology-config="config.topology || null"`. This is clean and schema-additive.

---

## Validation Architecture

No `.planning/config.json` found — treat `nyquist_validation` as enabled.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Vitest 4.1.0 (confirmed in package.json) |
| Config file | `app/vitest.config.js` (exists, covers `tests/unit/**/*.test.js`) |
| Quick run command | `npm run test` (runs `vitest run`) |
| Full suite command | `npm run test` (same — unit tests only; e2e separate) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| SVG-01 | NetworkTopologySvg renders nodes and links from schema | manual + unit | `npm run test` (icon module) | ❌ Wave 0 |
| SVG-02 | All 8 device types have icon path data | unit | `npm run test` | ❌ Wave 0 |
| SVG-03 | getScreenCTM() coordinate mapping logic | manual | Deploy + browser verify | N/A |
| SVG-04 | PbqPlacement renders SVG when topologyConfig prop is set | manual | Deploy + browser verify | N/A |

**Automated coverage:** SVG-02 is fully unit-testable (DEVICE_ICONS is pure JS). SVG-01 partially
(schema validation logic is pure JS; Vue rendering requires DOM). SVG-03 and SVG-04 require browser
DOM and are verified manually on `learning-dev`.

### Sampling Rate

- **Per task commit:** `npm run test` (unit tests, ~1s)
- **Per wave merge:** `npm run test` + manual browser verification on learning-dev
- **Phase gate:** Full unit suite green + manual verification of all 5 success criteria

### Wave 0 Gaps

- [ ] `tests/unit/networkTopologyIcons.test.js` — covers SVG-02 (all 8 types present + non-empty paths)

*(Existing test infrastructure covers the rest — vitest config already set up from Phase 1)*

---

## Sources

### Primary (HIGH confidence — direct codebase analysis)

- `/home/andre/Workspace/Code/learning-nc/app/src/components/PbqPlacement.vue` — existing component, props contract, hotspot pattern
- `/home/andre/Workspace/Code/learning-nc/app/src/components/PbqRenderer.vue` — how PbqPlacement is mounted, configImage/scenarioImage pattern
- `/home/andre/Workspace/Code/learning-nc/app/webpack.config.js` — no SVG loader configured, informs inline-path decision
- `/home/andre/Workspace/Code/learning-nc/app/package.json` — Vue 2.7.16, Vitest 4.1.0 confirmed
- `/home/andre/Workspace/Code/learning-nc/app/vitest.config.js` — unit test setup, includes `tests/unit/**/*.test.js`
- `/home/andre/Workspace/Code/learning-nc/app/src/utils/cliStateMachine.js` — pure JS module pattern (parallel to planned networkTopologyIcons.js)
- `/home/andre/Workspace/Code/learning-nc/.planning/STATE.md` — NC CSP constraint (no v-html) locked decision
- `/home/andre/Workspace/Code/learning-nc/.planning/REQUIREMENTS.md` — SVG-01 through SVG-04

### Secondary (MEDIUM confidence)

- Vue 2.7 SVG template support: Vue renders SVG elements with correct XML namespace when used inside `<svg>` in templates. This is established behavior since Vue 2.x and confirmed by the PbqCable.vue component (which already uses inline SVG for cable path rendering, if present) — MEDIUM as it hasn't been verified in THIS codebase, but is standard Vue 2 behavior.
- SVG DOM `getScreenCTM()` + `createSVGPoint().matrixTransform()`: Standard SVG DOM API, supported in all modern browsers. Well-documented in MDN. HIGH confidence on the API itself; MEDIUM on null-return edge cases in NC's iframe/widget context.

### Tertiary (LOW confidence)

- Icon path shapes: Approximate symbolic paths designed for recognizability — not verified against any official network diagram standard. The specific path `d` values in the DEVICE_ICONS constant are design-level decisions, subject to visual refinement during implementation.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new deps, all patterns derived from existing codebase
- Architecture: HIGH — component structure follows PbqCli.vue precedent exactly
- SVG rendering approach: HIGH — Vue 2.7 native template SVG is well-established
- Icon path data: LOW — approximate symbol designs, require visual verification
- Coordinate mapping: MEDIUM — getScreenCTM() behavior in NC iframe/widget context not verified

**Research date:** 2026-03-16
**Valid until:** 2026-06-16 (Vue 2.7 is in LTS/maintenance — no breaking changes expected; SVG DOM APIs are stable)
