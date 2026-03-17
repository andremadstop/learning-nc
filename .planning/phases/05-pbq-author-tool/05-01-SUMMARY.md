---
phase: 05-pbq-author-tool
plan: 01
subsystem: ui
tags: [vue2, pbq, author-tool, tdd, vitest, forms, json-generator]

# Dependency graph
requires:
  - phase: 01-cli-state-machine
    provides: DOMAIN_SCHEMAS — CLI domain dropdown
  - phase: 02-svg-topology-renderer
    provides: DEVICE_ICONS — device-type selects
provides:
  - PbqAuthorTool.vue — visual editor for all 5 PBQ subtypes with computed JSON output
  - pbqAuthorTool.test.js — 9 unit tests for config generation logic
affects:
  - 05-02 (wires PbqAuthorTool into admin/question UI)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - TDD with inline helpers in test file (mirrors pbqMultiPanel.test.js pattern)
    - computed generatedConfig switch-case by subtype
    - topology null guard: null when useTopology=false OR nodes=[]
    - command_outputs keys normalised to lowercase in computed
    - hint key omitted when empty (conditional key assignment)

key-files:
  created:
    - app/tests/unit/pbqAuthorTool.test.js
    - app/src/components/PbqAuthorTool.vue
  modified: []

key-decisions:
  - "PbqAuthorTool.vue imports DOMAIN_SCHEMAS + DEVICE_ICONS for domain/device dropdowns (same pattern as PbqCli + PbqPlacement)"
  - "topology null (not {}) when useTopology=false or nodes=[] — matches PbqRenderer null-check contract"
  - "command_outputs keys lowercased in generatedConfig to match evaluateCommand case-insensitive lookup"
  - "cable subtype is rawJson passthrough (try/catch JSON.parse) — v1 limitation, plan acknowledged"
  - "No v-html anywhere — all output via {{ generatedJson }} in pre tag, CSP compliant"

patterns-established:
  - "Author tool: all state internal (no props) — Plan 02 wires it into admin UI"
  - "JSON output: pre.author-json-output + copy-to-clipboard with fallback textarea for non-clipboard envs"

requirements-completed:
  - AUTHOR-01
  - AUTHOR-02

# Metrics
duration: 8min
completed: 2026-03-17
---

# Phase 5 Plan 1: PBQ Author Tool — Config Generator Summary

**Visual editor SFC with 5 subtype form sections, computed generatedConfig/generatedJson, topology null-guard, and lowercase command_outputs normalisation — all covered by 9 TDD unit tests**

## Performance

- **Duration:** 8 min
- **Started:** 2026-03-17T07:27:04Z
- **Completed:** 2026-03-17T07:35:11Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- TDD test file with 9 unit tests covering all config-generation edge cases (topology null guard, hint omission, lowercase normalisation, multi-panel merge)
- PbqAuthorTool.vue (670 lines) with all 5 subtype sections (cli, placement, dropdown, cable, multi_panel)
- generatedConfig computed with correct schema for each subtype
- generatedJson computed as pretty-printed JSON string matching PbqRenderer's expected input
- Full test suite remains green (62/62 tests across 6 files)

## Task Commits

1. **Task 1: Wave 0 — test stub for generatedConfig logic** - `f5cb761` (test)
2. **Task 2: PbqAuthorTool.vue — subtype selector + per-subtype forms + computed JSON** - `adb031c` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `app/tests/unit/pbqAuthorTool.test.js` - 9 unit tests for buildCliConfig, buildPlacementConfig, buildDropdownConfig, buildMultiPanelConfig, buildGeneratedJson
- `app/src/components/PbqAuthorTool.vue` - 670-line SFC with all 5 subtype form sections, computed config/JSON, DOMAIN_SCHEMAS + DEVICE_ICONS imports

## Decisions Made

- PbqAuthorTool imports DOMAIN_SCHEMAS and DEVICE_ICONS for domain/device dropdowns — same utility modules as PbqCli and PbqPlacement, ensuring consistent option sets
- topology: null (not {}) when useTopology=false or nodes=[] — matches PbqRenderer's `config.topology || null` guard
- command_outputs keys lowercased in computed to match evaluateCommand's case-insensitive lookup contract
- cable subtype uses rawJson textarea passthrough (try/catch JSON.parse) — documented v1 limitation
- No v-html anywhere — JSON output displayed via {{ generatedJson }} in pre tag, CSP compliant

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- PbqAuthorTool.vue is standalone (no props, no router wiring)
- Plan 02 wires it into the admin question editor UI
- All success criteria met: tests green, component exists, no v-html, all 5 subtypes present

---
*Phase: 05-pbq-author-tool*
*Completed: 2026-03-17*
