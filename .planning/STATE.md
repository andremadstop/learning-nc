---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: PBQ OnVUE-Niveau Upgrade
status: executing
stopped_at: Completed 02-svg-topology-renderer-02-PLAN.md
last_updated: "2026-03-16T21:58:32.458Z"
last_activity: "2026-03-16 — Phase 02 Plan 01 executed: NetworkTopologySvg.vue + DEVICE_ICONS + unit tests"
progress:
  total_phases: 6
  completed_phases: 2
  total_plans: 4
  completed_plans: 4
  percent: 75
---

# Project State

## Project Reference

**Project:** Learning-NC — Nextcloud Spaced Repetition App
**Core value:** PBQ-Simulationen auf OnVUE-Niveau (CLI State Machine + SVG Topologie + Instructor Notes)
**Current focus:** Phase 2 — SVG Topology Renderer (Plan 01 complete, Plan 02 pending)

## Current Position

Phase: 2 of 6 (SVG Topology Renderer)
Plan: 1 of 2 in current phase
Status: In progress — Plan 02 (PbqPlacement integration) next
Last activity: 2026-03-16 — Phase 02 Plan 01 executed: NetworkTopologySvg.vue + DEVICE_ICONS + unit tests

Progress: [████████░░] 75%

## Performance Metrics

**Velocity:**
- Total plans completed: 3
- Average duration: ~2min (Phase 02 Plan 01)
- Total execution time: ~2min

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 02-svg-topology-renderer | 01 | 2min | 3 | 4 |
| Phase 02-svg-topology-renderer P02 | 10min | 3 tasks | 2 files |

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

### Pending Todos

None.

### Blockers/Concerns

None.

## Session Continuity

Last session: 2026-03-16T21:55:33.818Z
Stopped at: Completed 02-svg-topology-renderer-02-PLAN.md
Resume file: None
