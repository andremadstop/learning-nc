---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: PBQ OnVUE-Niveau Upgrade
status: completed
stopped_at: Completed 05-pbq-author-tool 05-01-PLAN.md
last_updated: "2026-03-17T07:37:06.974Z"
last_activity: "2026-03-17 — Phase 04 Plan 01 executed: PbqMultiPanel.vue + PbqRenderer extension, browser-verified"
progress:
  total_phases: 6
  completed_phases: 4
  total_plans: 8
  completed_plans: 7
  percent: 100
---

# Project State

## Project Reference

**Project:** Learning-NC — Nextcloud Spaced Repetition App
**Core value:** PBQ-Simulationen auf OnVUE-Niveau (CLI State Machine + SVG Topologie + Instructor Notes)
**Current focus:** Phase 5 — PBQ Author Tool (Phase 4 complete)

## Current Position

Phase: 4 of 6 complete (Multi-Panel Layout done)
Plan: All 6 plans complete across phases 1-4
Status: Phases 1-4 complete — Phase 5 (Author Tool) and Phase 6 (Instructor Notes) pending
Last activity: 2026-03-17 — Phase 04 Plan 01 executed: PbqMultiPanel.vue + PbqRenderer extension, browser-verified

Progress: [██████████] 100%

## Performance Metrics

**Velocity:**
- Total plans completed: 3
- Average duration: ~2min (Phase 02 Plan 01)
- Total execution time: ~2min

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 02-svg-topology-renderer | 01 | 2min | 3 | 4 |
| Phase 02-svg-topology-renderer P02 | 10min | 3 tasks | 2 files |
| Phase 03-inline-dropdown-auf-diagramm P01 | 4min | 2 tasks | 3 files |
| Phase 03-inline-dropdown-auf-diagramm P01 | 5min | 3 tasks | 3 files |
| Phase 04-multi-panel-layout P01 | 10min | 3 tasks | 3 files |
| Phase 05-pbq-author-tool P01 | 8min | 2 tasks | 2 files |

## Accumulated Context

### Decisions

- Phase 1+2 können parallel bearbeitet werden (keine gegenseitige Abhängigkeit)
- Phase 6 (Instructor Notes) ist unabhängig, kann parallel zu Phase 1-4 laufen
- NC CSP: Kein v-html für SVG — muss als Vue-Template-Elemente gerendert werden
- PostgreSQL: Kein createFunction() für arithmetische Ausdrücke (quoted identifier Bug)
- [Phase 01-cli-state-machine]: cliStateMachine.js is a pure ES module (no Vue dep) for reuse in Author Tool live preview
- [Phase 01-cli-state-machine]: Dynamic transitions use original trimmed cmd for capture groups to preserve interface name casing
- [Phase 01-cli-state-machine]: errorMsg is string or function — function receives cmd.trim() for domain-specific error messages (bash, windows)
- [Phase 01-cli-state-machine]: termModes and termContexts keyed by terminal name — allows multi-terminal questions each with independent state
- [Phase 01-cli-state-machine]: No v-html anywhere — all output lines pushed as plain strings into history array, rendered via {{ line }} with white-space: pre-wrap
- [Phase 02-svg-topology-renderer]: SVG icons bound via :d attribute on <path> elements — never v-html, CSP compliant
- [Phase 02-svg-topology-renderer]: DEVICE_ICONS is pure ES module (no Vue dep) — reusable in Author Tool
- [Phase 02-svg-topology-renderer]: Unknown device type falls back to <circle> silently — no throw, graceful degradation
- [Phase 02-svg-topology-renderer]: viewBox auto-computed from node bounds + 40px padding; empty nodes use 0 0 400 300
- [Phase 02-svg-topology-renderer]: topologyConfig prop takes priority over scenarioImage in v-if chain; hotspot divs gated on scenarioImage && !topologyConfig
- [Phase 02-svg-topology-renderer]: openPicker signature unchanged — node-click passes node.id matching config.positions[].id by schema contract
- [Phase 03-inline-dropdown-auf-diagramm]: overflow:visible added inline to .pbq-diagram-wrapper rule; closePicker() extracted as named method for addEventListener symmetry
- [Phase 03-inline-dropdown-auf-diagramm]: overflow:visible added inline to .pbq-diagram-wrapper rule; closePicker() extracted as named method for addEventListener symmetry
- [Phase 03-inline-dropdown-auf-diagramm]: Inline picker gated on pickerPos && topologyConfig; below-diagram picker gated on !topologyConfig — clean mode separation without code duplication
- [Phase 04-multi-panel-layout]: No overflow:hidden on .pbq-multi-panel — inline-picker needs visible overflow for absolute positioning
- [Phase 04-multi-panel-layout]: Multi-panel answeredCount sums cli + placement sub-keys (not top-level keys)
- [Phase 04-multi-panel-layout]: min-width:0 on flex children prevents horizontal overflow from monospace terminal content
- [Phase 05-pbq-author-tool]: PbqAuthorTool: topology null (not {}) when useTopology=false or nodes=[] — matches PbqRenderer null-check contract
- [Phase 05-pbq-author-tool]: PbqAuthorTool: command_outputs keys lowercased in computed to match evaluateCommand case-insensitive lookup
- [Phase 05-pbq-author-tool]: PbqAuthorTool: all state internal (no props) — Plan 02 wires it into admin UI

### Pending Todos

None.

### Blockers/Concerns

None.

## Session Continuity

Last session: 2026-03-17T07:37:06.959Z
Stopped at: Completed 05-pbq-author-tool 05-01-PLAN.md
Resume file: None
