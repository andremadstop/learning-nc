---
phase: 05-pbq-author-tool
verified: 2026-03-17T08:50:00Z
status: human_needed
score: 9/9 must-haves verified
re_verification: false
human_verification:
  - test: "Open QuestionForm, select 'CLI Terminal' PBQ type, fill in terminal + command, observe live preview in PbqAuthorTool dialog"
    expected: "Live PbqRenderer preview updates reactively as form fields are filled — CLI terminal renders and responds to typed commands"
    why_human: "Reactive live-preview requires browser rendering; Vue reactivity + PbqRenderer interactivity cannot be verified with grep"
  - test: "Click 'JSON kopieren' in PbqAuthorTool"
    expected: "Clipboard receives the generated JSON string; 'Kopiert!' feedback shows for ~2 seconds"
    why_human: "navigator.clipboard.writeText requires a real browser context; cannot be tested programmatically"
  - test: "Paste copied JSON into QuestionForm pbq-config textarea, save question, reopen question"
    expected: "Saved question pre-populates pbq_subtype select and pbq-config textarea with the persisted values"
    why_human: "End-to-end round-trip through PHP backend save/load cannot be verified statically"
---

# Phase 5: PBQ Author Tool — Verification Report

**Phase Goal:** Visueller Editor zum Erstellen von PBQ-Fragen-Configs ohne manuelle JSON-Eingabe. Live-Vorschau. Generiertes JSON in QuestionForm einfügbar.
**Verified:** 2026-03-17T08:50:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (from Plan 01 + Plan 02 must_haves)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Instructor can select a PBQ subtype from a dropdown (cli, placement, dropdown, cable, multi_panel) | VERIFIED | PbqAuthorTool.vue line 6-13: `<select v-model="selectedSubtype">` with all 5 `<option>` values |
| 2 | All per-subtype form fields render for the selected subtype only | VERIFIED | Lines 16/52/121/152/161: `v-if="selectedSubtype === 'cli'"` through `v-if="selectedSubtype === 'multi_panel'"` — all 5 conditional sections present |
| 3 | generatedConfig computed returns valid, schema-correct config for all five subtypes | VERIFIED | Lines 311-381: switch-case over all 5 subtypes with correct key shapes; 9 unit tests pass (62/62 total) |
| 4 | generatedJson computed returns pretty-printed JSON string matching PbqRenderer's expected input | VERIFIED | Lines 383-388: `JSON.stringify({ pbq_subtype, pbq_config }, null, 2)`; test "buildGeneratedJson output parses to object with pbq_subtype and pbq_config keys" passes |
| 5 | Topology null guard: placement/multi_panel with no nodes emits topology: null, not {} | VERIFIED | Lines 336-339 (placement) and 372-375 (multi_panel): `(useTopology && hasNodes) ? {...} : null`; test "buildPlacementConfig returns topology: null when useTopology is true but nodes array is empty" passes |
| 6 | command_outputs keys are normalised to lowercase | VERIFIED | Lines 319-322: `.reduce((acc, pair) => { acc[pair.cmd.toLowerCase()] = pair.output; ... })`; test "normalises command_outputs keys to lowercase" passes |
| 7 | Live preview renders the correct PBQ simulation for every subtype | VERIFIED (code) / ? (browser) | Lines 233-244: `<PbqRenderer :question="previewQuestion" ...>` with previewQuestion computed (lines 390-395); requires browser to confirm reactivity |
| 8 | Instructor can open PbqAuthorTool from a button in QuestionForm | VERIFIED | QuestionForm.vue line 100: `<NcButton @click="showAuthorTool = true">`; lines 114-120: NcDialog v-if="showAuthorTool" containing `<PbqAuthorTool />` |
| 9 | QuestionForm save() sends pbq_subtype and pbq_config in its payload | VERIFIED | QuestionForm.vue lines 301-302: `pbqSubtype: this.form.pbqSubtype || null, pbqConfig: this.form.pbqConfig ? this.form.pbqConfig.trim() : null` in save() |

**Score:** 9/9 truths verified (7 automated, 2 human-needed for browser behaviour)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/tests/unit/pbqAuthorTool.test.js` | Unit tests for generatedConfig and generatedJson pure-function logic | VERIFIED | 201 lines, 9 test cases in `describe('PbqAuthorTool config generation')`, all pass |
| `app/src/components/PbqAuthorTool.vue` | Visual editor, all 5 subtype sections, computed config/JSON, min 200 lines | VERIFIED | 695 lines, all 5 subtype v-if sections, generatedConfig/generatedJson/previewQuestion computed, PbqRenderer live preview, NcButton copy |
| `app/src/components/QuestionForm.vue` | PBQ config section with subtype select, config textarea, open-author-tool button | VERIFIED | Contains pbqSubtype, pbqConfig in form data, showAuthorTool flag, PbqAuthorTool in NcDialog |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `PbqAuthorTool.vue` | `cliStateMachine.js` | `import { DOMAIN_SCHEMAS }` | WIRED | Line 258: `import { DOMAIN_SCHEMAS } from '../utils/cliStateMachine.js'`; used in validDomains computed (line 303-305) and in domain `<option v-for>` |
| `PbqAuthorTool.vue` | `networkTopologyIcons.js` | `import { DEVICE_ICONS }` | WIRED | Line 259: `import { DEVICE_ICONS } from '../utils/networkTopologyIcons.js'`; used in validDeviceTypes computed (line 307-309) and device-type selects |
| `PbqAuthorTool.vue` | `PbqRenderer.vue` | `:question="previewQuestion" @submit="()=>{}" @skip="()=>{}"` | WIRED | Line 260: `import PbqRenderer from './PbqRenderer.vue'`; in components (line 265); template lines 237-243 with correct props |
| `QuestionForm.vue` | `PbqAuthorTool.vue` | `NcDialog v-if="showAuthorTool"` containing `<PbqAuthorTool />` | WIRED | Line 137: import; line 141: in components; lines 114-120: NcDialog with showAuthorTool flag |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| AUTHOR-01 | 05-01, 05-02 | Visueller Editor zur Auswahl des PBQ-Typs und Eingabe der Config-Felder | SATISFIED | PbqAuthorTool.vue: subtype selector + 5 per-subtype form sections; QuestionForm.vue: pbq_subtype select; 9 unit tests covering all form-to-config logic |
| AUTHOR-02 | 05-01, 05-02 | Automatische Generierung von gültigem PBQ-JSON aus Formulareingaben | SATISFIED | generatedConfig + generatedJson computed in PbqAuthorTool.vue; QuestionForm save() emits pbqSubtype + pbqConfig; clipboard copy via `copyJson()` |
| AUTHOR-03 | 05-02 | Live-Vorschau der resultierenden PBQ-Simulation im Editor | SATISFIED (code) / NEEDS HUMAN (interactive) | PbqRenderer wired via previewQuestion computed; browser test needed to confirm reactive update per subtype |

No orphaned requirements. All three AUTHOR-0x IDs are claimed by plans 05-01 and 05-02.

### Anti-Patterns Found

| File | Pattern | Severity | Impact |
|------|---------|----------|--------|
| None | — | — | — |

Checks run:
- `v-html`: No matches in PbqAuthorTool.vue or QuestionForm.vue. JSON output displayed via `{{ generatedJson }}` in `<pre>` tag — CSP compliant.
- TODO/FIXME/PLACEHOLDER: None found in implementation logic (HTML `placeholder=` attributes are form UX, not code stubs).
- Empty implementations (`return null`, `return {}`): `cable` subtype returns `{}` on JSON parse failure — this is intentional documented v1 behaviour, not a stub.
- Console.log-only handlers: None.

### Human Verification Required

#### 1. Live Preview Reactivity

**Test:** Open any question pool, click "+" to create a question, select "CLI Terminal" from the PBQ Type dropdown, click "PBQ Config Builder" to open the dialog. Add a terminal named "Router", add a command output with cmd="show ip route" and some output text.
**Expected:** The "Vorschau" section below the form immediately shows an interactive CLI terminal for the current subtype. Switching the subtype selector updates the preview to the corresponding PBQ type.
**Why human:** Vue 2 computed reactivity and PbqRenderer rendering require a live browser; grep confirms the wiring is present but cannot execute the component lifecycle.

#### 2. Clipboard Copy

**Test:** With some config filled in, click "JSON kopieren" in PbqAuthorTool.
**Expected:** The button label changes to "Kopiert!" for ~2 seconds, then reverts. Pasting (Ctrl+V) into a text field shows the generated JSON with `pbq_subtype` and `pbq_config` keys.
**Why human:** `navigator.clipboard.writeText` requires a real browser security context and user gesture — not executable in Node/Vitest.

#### 3. End-to-End Save/Load Round-Trip

**Test:** Copy the generated JSON, close the dialog, paste into the "PBQ Config (JSON)" textarea, select a PBQ type, save the question. Reopen the question for editing.
**Expected:** The PBQ Type select pre-populates with the saved subtype, and the textarea shows the indented JSON from the previous save.
**Why human:** Requires the PHP backend (QuestionController/QuestionService) to persist and return `pbq_subtype` + `pbq_config` fields — not verifiable statically.

### Commit Verification

All 4 documented commits exist in the git history:

| Hash | Message |
|------|---------|
| `f5cb761` | test(05-01): add failing tests for PbqAuthorTool config generation (RED) |
| `adb031c` | feat(05-01): add PbqAuthorTool.vue visual editor for PBQ configs |
| `ce009ea` | feat(05-02): add PbqRenderer live preview to PbqAuthorTool |
| `c8a808d` | feat(05-02): add PBQ config section to QuestionForm with author tool integration |

### Summary

Phase 5 goal is **achieved in code**. All 9 observable truths are verified at the implementation level:

- PbqAuthorTool.vue (695 lines) delivers a complete visual editor for all 5 PBQ subtypes with computed config generation, topology null-guard, lowercase command_output normalisation, PbqRenderer live preview wiring, and clipboard copy with fallback textarea.
- pbqAuthorTool.test.js (201 lines, 9 tests) covers all config-generation edge cases; full test suite is 62/62 green.
- QuestionForm.vue is correctly extended with pbq_subtype selector, pbq_config textarea, showAuthorTool NcDialog, mounted() pre-population, and save() payload extension.
- All key imports (DOMAIN_SCHEMAS, DEVICE_ICONS, PbqRenderer, PbqAuthorTool) are present and wired into the template and computed properties.
- No v-html, no TODO/FIXME stubs, no empty handlers in the phase deliverables.

Three items require browser validation: live-preview reactivity, clipboard copy behaviour, and the backend save/load round-trip for pbq_subtype + pbq_config.

---

_Verified: 2026-03-17T08:50:00Z_
_Verifier: Claude (gsd-verifier)_
