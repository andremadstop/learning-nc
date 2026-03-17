# Phase 5: PBQ Author Tool - Research

**Researched:** 2026-03-17
**Domain:** Vue 2.7 form authoring, dynamic component rendering, clipboard integration
**Confidence:** HIGH

## Summary

Phase 5 adds a visual editor — `PbqAuthorTool.vue` — that allows instructors to build PBQ question configs (JSON) via form fields rather than raw JSON editing. The tool must cover all five PBQ subtypes: `cli`, `placement`, `dropdown`, `cable`, and `multi_panel`.

All PBQ simulation components already exist and are fully functional. The author tool's primary job is (a) provide per-subtype form fields that generate valid `pbq_config` objects, and (b) render a live preview by passing the generated config directly to `PbqRenderer.vue`. No new backend endpoints, DB changes, or utility modules are needed. The only integration point with the existing app is a "Copy JSON" button that puts the serialized config on the clipboard so the instructor can paste it into `QuestionForm`.

The most complex part is the topology node editor for the `placement` and `multi_panel` subtypes — adding/removing nodes and links to build the `topology` object. This can be done with simple form fields (no canvas drag-drop), iterating over arrays in Vue 2 reactive data.

**Primary recommendation:** Implement `PbqAuthorTool.vue` as a single-file component with a tab/accordion per subtype, reactive form data that computes `generatedConfig` via a `computed` property, and a `<PbqRenderer>` in preview mode (`:disabled="true"`). Wire a "Copy JSON" button using `navigator.clipboard.writeText`. No new libraries needed.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| AUTHOR-01 | Visueller Editor zur Auswahl des PBQ-Typs und Eingabe der Config-Felder | Type selector via `<select>`, per-subtype form sections (v-if), reactive form data in Vue 2 data() |
| AUTHOR-02 | Automatische Generierung von gültigem PBQ-JSON aus Formulareingaben | `computed: generatedConfig()` maps form state to each subtype's exact config schema — same schema consumed by existing PBQ components |
| AUTHOR-03 | Live-Vorschau der resultierenden PBQ-Simulation im Editor | Pass `generatedConfig` as `:question` prop to `PbqRenderer` with `:disabled="false"` so it is interactive — no special preview mode needed |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Vue 2.7 | ^2.7.16 | Component framework | Project constraint |
| @nextcloud/vue | ^8.20.0 | NcButton, NcDialog, NcNoteCard | Project constraint; already used in QuestionForm |
| PbqRenderer.vue | existing | Live preview host | Already wires all 5 PBQ subtypes; zero new code for preview |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| navigator.clipboard | native browser API | Copy generated JSON to clipboard | Copy-to-clipboard button; no extra lib needed |
| DOMAIN_SCHEMAS (cliStateMachine.js) | existing | Enumerate valid CLI domains in UI | Populate domain dropdown in CLI form section |
| DEVICE_ICONS (networkTopologyIcons.js) | existing | Enumerate valid device types | Populate device-type select in topology node editor |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| navigator.clipboard.writeText | document.execCommand('copy') | execCommand is deprecated; navigator.clipboard requires HTTPS (NC always uses HTTPS) — use clipboard API |
| PbqRenderer for live preview | Custom per-subtype preview rendering | PbqRenderer already handles all 5 subtypes; re-implementing would duplicate ~200 lines and risk drift |
| Drag-drop topology builder | Simple form-field array editor for nodes/links | Drag-drop requires a canvas library (vue-konva, etc.) — form fields are sufficient for v1 and CSP-safe |
| NcDialog wrapper | Inline panel / new route | NcDialog matches QuestionForm pattern; easy to open from QuestionForm's "PBQ Config" button |

**Installation:**
No new packages required. All dependencies already present.

## Architecture Patterns

### Recommended Project Structure
```
src/
├── components/
│   ├── PbqAuthorTool.vue    # NEW: visual editor
│   ├── PbqRenderer.vue      # unchanged — used for live preview
│   ├── QuestionForm.vue     # add "Open PBQ Author" button (optional integration point)
│   └── ... (all other PBQ components unchanged)
```

### Pattern 1: Reactive Form → Computed Config
**What:** All author form state lives in `data()`. A single `computed: generatedConfig()` transforms form state into the exact JSON shape consumed by PbqRenderer.
**When to use:** Keeps form data and config shape decoupled. Adding a new field = update form data + update computed. No synchronization logic.

```javascript
// Source: derived from existing PbqRenderer.vue config consumption patterns
computed: {
  generatedConfig() {
    switch (this.selectedSubtype) {
      case 'cli':
        return {
          domain: this.cliForm.domain,
          hint: this.cliForm.hint || undefined,
          terminals: this.cliForm.terminals,
          command_outputs: this.cliForm.commandOutputs,
        }
      case 'placement':
        return {
          positions: this.placementForm.positions,
          device_options: this.placementForm.deviceOptions,
          scoring_mode: this.placementForm.scoringMode,
          topology: this.placementForm.useTopology ? this.topologyForm : null,
        }
      case 'dropdown':
        return {
          questions: this.dropdownForm.questions,
        }
      case 'multi_panel':
        return {
          cli: { domain: this.cliForm.domain, terminals: this.cliForm.terminals, command_outputs: this.cliForm.commandOutputs },
          placement: { positions: this.placementForm.positions, device_options: this.placementForm.deviceOptions, scoring_mode: this.placementForm.scoringMode },
          topology: this.topologyForm,
        }
      default:
        return {}
    }
  },
  previewQuestion() {
    return {
      pbq_subtype: this.selectedSubtype,
      pbq_config: this.generatedConfig,
    }
  },
  generatedJson() {
    return JSON.stringify({ pbq_subtype: this.selectedSubtype, pbq_config: this.generatedConfig }, null, 2)
  },
}
```

### Pattern 2: Per-Subtype Form Sections with v-if
**What:** The template has a `<select>` for subtype followed by N form sections, each gated with `v-if="selectedSubtype === 'X'"`. This avoids complex multi-step wizards while keeping unrelated fields out of DOM.
**When to use:** 5 subtypes — tabs would also work, but `v-if` sections are simpler to maintain in Vue 2.

```vue
<!-- Source: QuestionForm.vue pattern for conditional sections -->
<select v-model="selectedSubtype" class="nc-input">
  <option value="cli">CLI Terminal</option>
  <option value="placement">Device Placement</option>
  <option value="dropdown">Inline Dropdown</option>
  <option value="cable">Cable Mapping</option>
  <option value="multi_panel">Multi-Panel (CLI + Topology)</option>
</select>

<div v-if="selectedSubtype === 'cli'" class="author-section">
  <!-- CLI form fields -->
</div>
<div v-if="selectedSubtype === 'placement'" class="author-section">
  <!-- Placement form fields -->
</div>
```

### Pattern 3: Array-of-Objects Editor (Add/Remove Rows)
**What:** For arrays like `terminals`, `positions`, `nodes`, `links` — render `v-for` over a reactive array, with "Add" and "Remove" buttons. Mutations use `this.$set` or `splice()` for Vue 2 reactivity.
**When to use:** For every config field that is an array of objects (terminals, positions, nodes, links, device_options).

```javascript
// Source: Vue 2 reactivity docs — splice is reactive; push is reactive
addTerminal() {
  this.cliForm.terminals.push({ name: 'Terminal ' + (this.cliForm.terminals.length + 1) })
},
removeTerminal(index) {
  this.cliForm.terminals.splice(index, 1)
},
// For object property edits — use $set if adding new keys
updateTerminalField(index, field, value) {
  this.$set(this.cliForm.terminals[index], field, value)
},
```

### Pattern 4: Live Preview via PbqRenderer
**What:** Render `<PbqRenderer :question="previewQuestion" :disabled="false" />` below the form. Because `previewQuestion` is a computed, it auto-updates on every form change.
**When to use:** Always — this is AUTHOR-03.

```vue
<!-- Source: PbqRenderer.vue prop contract -->
<PbqRenderer
  v-if="selectedSubtype"
  :question="previewQuestion"
  :disabled="false"
  @submit="() => {}"
  @skip="() => {}"
/>
```

Note: PbqRenderer emits `submit` and `skip`. In author tool context, these do nothing — wire empty handlers to silence Vue warnings.

### Pattern 5: Copy JSON to Clipboard
**What:** A single button calls `navigator.clipboard.writeText(this.generatedJson)`.
**When to use:** For AUTHOR-02 / integration with QuestionForm.

```javascript
// Source: MDN navigator.clipboard API (HTTPS only — NC always uses HTTPS)
async copyJson() {
  try {
    await navigator.clipboard.writeText(this.generatedJson)
    this.copySuccess = true
    setTimeout(() => { this.copySuccess = false }, 2000)
  } catch (e) {
    // Fallback: show JSON in a textarea for manual copy
    this.showJsonFallback = true
  }
},
```

### Pattern 6: Opening Author Tool from QuestionForm (Integration)
**What:** Add a "PBQ Config Builder" NcButton in QuestionForm that opens `PbqAuthorTool` in an `NcDialog`. When the instructor copies JSON and closes, QuestionForm can have an optional paste-in textarea for `pbq_config`.
**When to use:** This is the "JSON can be eingefügt werden" success criterion (AUTHOR-02 + roadmap SC4).

The simplest v1 approach is a standalone dialog that outputs JSON via clipboard — no direct form binding needed. QuestionForm currently has no `pbq_config` or `pbq_subtype` fields, so extending QuestionForm is optional for this phase (the requirement is "generiertes JSON kann eingefügt werden", not "auto-fills QuestionForm").

### Anti-Patterns to Avoid
- **v-html anywhere:** Hard constraint. The JSON display textarea and preview are all standard Vue template rendering. Use `<pre>{{ generatedJson }}</pre>` or a `<textarea>` for the raw JSON display.
- **Triggering full re-render of PbqRenderer on every keystroke:** PbqRenderer is lightweight (no XHR, no heavy DOM). Computed prop updates are fine. No debounce needed for v1.
- **Building a separate preview renderer:** PbqRenderer already handles all 5 subtypes. Do not duplicate rendering logic.
- **Mutating arrays without Vue 2 reactivity methods:** `this.terminals[0].name = 'x'` does NOT trigger reactivity in Vue 2. Use `this.$set(this.terminals[0], 'name', 'x')` or replace the whole object.
- **Storing topology as a string and JSON.parsing it in computed:** Error-prone. Keep topology as a reactive plain object in `data()` and only serialize via `generatedJson` computed.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| PBQ preview rendering | Custom subtype-aware preview component | PbqRenderer.vue | Already handles all 5 subtypes + edge cases |
| Clipboard copy | execCommand / third-party lib | navigator.clipboard.writeText | Native API, available in all modern browsers, no CSP issues |
| Device-type validation | Hardcoded string list in form | Object.keys(DEVICE_ICONS) from networkTopologyIcons.js | Single source of truth; auto-extends when new icon types are added |
| Domain validation | Hardcoded string list | Object.keys(DOMAIN_SCHEMAS) from cliStateMachine.js | Same reason |
| JSON pretty-print | Custom serializer | JSON.stringify(obj, null, 2) | Native, zero deps |

**Key insight:** All infrastructure (rendering, state machine, icons, scoring) is in existing utils. The author tool is purely a form + a computed + a copy button.

## Common Pitfalls

### Pitfall 1: Vue 2 Reactivity with Nested Array-of-Objects
**What goes wrong:** Direct index assignment (`this.nodes[0].x = 100`) silently fails to trigger re-render.
**Why it happens:** Vue 2 cannot observe direct property mutations on array elements or new object keys.
**How to avoid:** Always use `this.$set(arr[i], 'field', value)` for field edits, or replace the element: `this.$set(this.nodes, i, { ...this.nodes[i], x: 100 })`. Use `splice` for add/remove.
**Warning signs:** Form fields update visually but generated JSON doesn't change.

### Pitfall 2: PbqRenderer's `@submit` and `@skip` events fire in preview
**What goes wrong:** If the author clicks "PBQ abschicken" in the preview, the event bubbles up. Without a handler, Vue emits a console warning.
**Why it happens:** PbqRenderer always shows the submit button (unless `disabled` is true, but we want an interactive preview).
**How to avoid:** Wire `@submit="() => {}"` and `@skip="() => {}"` on the PbqRenderer in the author tool context.
**Warning signs:** Vue warn "Unhandled event: submit".

### Pitfall 3: topology `null` vs empty object in PbqPlacement
**What goes wrong:** If the author switches from placement-with-topology to placement-without-topology, passing `topology: {}` instead of `null` breaks PbqPlacement (it checks `v-if="topologyConfig"` — an empty object is truthy, so it tries to render 0 nodes).
**Why it happens:** Form reset leaves the topology sub-object as `{}` instead of `null`.
**How to avoid:** In `generatedConfig`, use `topology: this.placementForm.useTopology && hasNodes ? this.topologyForm : null`. Check explicitly: `this.topologyForm.nodes && this.topologyForm.nodes.length > 0`.
**Warning signs:** Placement preview shows blank SVG instead of fallback grid.

### Pitfall 4: command_outputs keyed by command string — case sensitivity
**What goes wrong:** `evaluateCommand` does case-insensitive lookup: `k.toLowerCase() === normalized`. The author may enter keys with mixed case, which works at runtime but looks odd in JSON. Conversely, if the author enters a duplicate command in different case, only one will match.
**Why it happens:** The form lets the user enter arbitrary command strings as keys.
**How to avoid:** In the CLI form, normalize command_output keys to lowercase when generating config (or display a note). This is cosmetic but prevents confusion.
**Warning signs:** Instructor enters "Show IP Route" and "show ip route" as separate keys and is surprised that both match the same command.

### Pitfall 5: NcDialog scroll — form + preview can overflow
**What goes wrong:** PbqAuthorTool opened in an NcDialog with both a tall form and a live preview can overflow the dialog height in NC's default NcDialog sizing.
**Why it happens:** NcDialog has a max-height; nested scroll areas (terminal body) and topology SVG can expand the content beyond it.
**How to avoid:** Wrap the preview section in `overflow-y: auto; max-height: 400px`. Or open the author tool as a full-page view instead of a dialog (preferred for complex configs).
**Warning signs:** Submit button at the bottom is not reachable without scrolling past a very tall terminal preview.

## Code Examples

All schemas are derived from reading the existing PBQ component source:

### CLI Config Schema (from PbqCli.vue)
```json
{
  "pbq_subtype": "cli",
  "pbq_config": {
    "domain": "cisco_ios",
    "hint": "Optional hint text",
    "terminals": [
      { "name": "Router" }
    ],
    "command_outputs": {
      "show ip route": "Codes: C - connected, S - static\n..."
    }
  }
}
```

### Placement Config Schema (from PbqPlacement.vue)
```json
{
  "pbq_subtype": "placement",
  "pbq_config": {
    "positions": [
      { "id": "n1", "label": "Core Switch", "correct": "switch" }
    ],
    "device_options": ["router", "switch", "firewall", "server"],
    "scoring_mode": "strict",
    "topology": {
      "nodes": [{ "id": "n1", "type": "switch", "label": "Core Switch", "x": 200, "y": 150 }],
      "links": []
    }
  }
}
```

### Dropdown Config Schema (from PbqDropdown.vue)
```json
{
  "pbq_subtype": "dropdown",
  "pbq_config": {
    "questions": [
      { "id": "q1", "label": "Which cable type connects PC to switch?", "options": ["Cat5e", "Cat6", "Fiber"], "correct": "Cat5e" }
    ]
  }
}
```

### Multi-Panel Config Schema (from PbqMultiPanel.vue + PbqRenderer.vue)
```json
{
  "pbq_subtype": "multi_panel",
  "pbq_config": {
    "cli": {
      "domain": "cisco_ios",
      "terminals": [{ "name": "Router" }],
      "command_outputs": {}
    },
    "placement": {
      "positions": [{ "id": "n1", "label": "Router", "correct": "router" }],
      "device_options": ["router", "switch", "firewall"],
      "scoring_mode": "strict"
    },
    "topology": {
      "nodes": [{ "id": "n1", "type": "router", "label": "Router", "x": 200, "y": 150 }],
      "links": []
    }
  }
}
```

### Valid Domain Keys (from cliStateMachine.js)
```javascript
// Source: DOMAIN_SCHEMAS export in app/src/utils/cliStateMachine.js
const VALID_DOMAINS = Object.keys(DOMAIN_SCHEMAS)
// => ['cisco_ios', 'linux', 'windows', 'sql', 'generic']
```

### Valid Device Types (from networkTopologyIcons.js)
```javascript
// Source: DEVICE_ICONS export in app/src/utils/networkTopologyIcons.js
const VALID_DEVICE_TYPES = Object.keys(DEVICE_ICONS)
// => ['router', 'switch', 'firewall', 'server', 'cloud', 'workstation', 'ap', 'wre']
```

### previewQuestion shape expected by PbqRenderer
```javascript
// Source: PbqRenderer.vue props
// question: { pbq_subtype: string, pbq_config: object, image_path?: string }
// PbqRenderer reads: question.pbq_subtype, question.pbq_config
// config.topology → passed as topologyConfig prop to PbqPlacement
// config.scenario_image → used as scenarioImageUrl (base64 data URI)
```

## Integration Point: QuestionForm

QuestionForm currently has NO fields for `pbq_subtype` or `pbq_config`. The existing `save()` method does not include these fields in the emitted payload.

**For Phase 5, the integration is clipboard-based only.** The instructor:
1. Opens PBQ Author Tool (from a button in QuestionForm, or standalone)
2. Builds config visually
3. Clicks "JSON kopieren"
4. Pastes JSON into a raw textarea in QuestionForm

This requires QuestionForm to expose a `pbq_config` textarea for direct JSON input. That textarea is a minimal addition to QuestionForm: one text field bound to `form.pbqConfig` (string), serialized and sent in the save payload.

Alternatively, Phase 5 Plan 02 can handle the QuestionForm integration as its own task.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| PBQ configs written by hand as raw JSON | Visual form-based author tool | Phase 5 (this phase) | Removes JSON knowledge requirement for instructors |
| All 5 PBQ subtypes scattered across separate components | All configs share the same PbqRenderer interface | Phases 1-4 | Single preview component works for all subtypes |

**Deprecated/outdated:** None. This phase adds new capability without changing existing components.

## Open Questions

1. **Where is PbqAuthorTool opened from?**
   - What we know: The roadmap says "Generiertes JSON kann in QuestionForm eingefügt werden" as success criterion 4.
   - What's unclear: Whether it is a standalone route, a modal, or embedded in QuestionForm.
   - Recommendation: Make it a standalone `NcDialog` for v1. QuestionForm gets a "PBQ Config" button that opens the dialog. Keeps QuestionForm changes minimal.

2. **Does QuestionForm need a new `pbq_subtype` / `pbq_config` field in the DB save path?**
   - What we know: QuestionForm's `save()` currently doesn't include PBQ fields. DB columns `pbq_subtype` and `pbq_config` presumably already exist (they were added in earlier phases per STATE.md).
   - What's unclear: Whether QuestionForm already sends these fields or not (QuestionForm source was read — it does NOT send them).
   - Recommendation: Plan 02 should add a `pbqConfig` textarea to QuestionForm and include it in the save payload. This is a small change: one new `data.form.pbqSubtype` + `data.form.pbqConfig` field, pre-populated from `question.pbq_subtype` / `question.pbq_config` in `mounted()`.

3. **Cable subtype in author tool**
   - What we know: The cable subtype (PbqCable) has a complex pin-matrix config schema. It is the most complex author form to build (8-pin wiring, metrics dict, remediation options).
   - What's unclear: Whether v1 of the author tool needs full cable editing or can offer a simplified JSON-textarea passthrough for cable configs.
   - Recommendation: For v1, implement full form editors for `cli`, `placement`, `dropdown`, and `multi_panel`. For `cable`, provide a JSON textarea with a validator (check required keys: `cables[].pins`, `cables[].id`). Document this as a known v1 limitation.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Vitest ^4.1.0 |
| Config file | `app/vitest.config.js` |
| Quick run command | `cd ~/Workspace/Code/learning-nc/app && npm test -- --reporter=verbose` |
| Full suite command | `cd ~/Workspace/Code/learning-nc/app && npm test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AUTHOR-01 | generatedConfig returns valid cli config from form state | unit | `npm test -- --reporter=verbose pbqAuthorTool` | Wave 0 |
| AUTHOR-02 | generatedConfig returns valid placement/dropdown/multi_panel config | unit | `npm test -- --reporter=verbose pbqAuthorTool` | Wave 0 |
| AUTHOR-03 | previewQuestion shape matches PbqRenderer prop contract | unit | `npm test -- --reporter=verbose pbqAuthorTool` | Wave 0 |

### Sampling Rate
- **Per task commit:** `cd ~/Workspace/Code/learning-nc/app && npm test -- --reporter=verbose pbqAuthorTool`
- **Per wave merge:** `cd ~/Workspace/Code/learning-nc/app && npm test`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `app/tests/unit/pbqAuthorTool.test.js` — covers AUTHOR-01, AUTHOR-02, AUTHOR-03 (pure function tests for `generatedConfig` logic extracted from component)

*(Pattern established by pbqMultiPanel.test.js: test pure-function helpers that mirror component logic, no DOM/Vue mount required)*

## Sources

### Primary (HIGH confidence)
- Direct code inspection: `PbqRenderer.vue`, `PbqCli.vue`, `PbqPlacement.vue`, `PbqDropdown.vue`, `PbqCable.vue`, `PbqMultiPanel.vue`, `QuestionForm.vue` — prop contracts, config schemas, emit signatures
- `cliStateMachine.js` — DOMAIN_SCHEMAS keys and evaluateCommand contract
- `networkTopologyIcons.js` — DEVICE_ICONS keys (valid device types)
- `REQUIREMENTS.md`, `ROADMAP.md`, `STATE.md` — requirement IDs, success criteria, accumulated decisions
- `CLAUDE.md` — Vue 2.7, no v-html, Vitest test framework confirmed
- `vitest.config.js` — test pattern `tests/unit/**/*.test.js`, confirmed test framework
- `pbqMultiPanel.test.js` — established pattern for testing component logic as pure functions

### Secondary (MEDIUM confidence)
- MDN navigator.clipboard API — browser-native clipboard write, works under HTTPS (NC always HTTPS)
- Vue 2 reactivity docs — `$set` and `splice` requirements for nested object/array mutation

### Tertiary (LOW confidence)
- None — all findings derived from first-party code inspection.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new dependencies, all from existing project files
- Architecture: HIGH — config schemas directly readable from component source; form-to-computed pattern is standard Vue 2
- Pitfalls: HIGH — derived from close reading of existing component logic (Vue 2 reactivity, PbqRenderer event contract, topology null vs empty)

**Research date:** 2026-03-17
**Valid until:** 2026-06-17 (Vue 2.7 stable, no fast-moving deps)
