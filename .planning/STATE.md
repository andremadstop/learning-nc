---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: PBQ OnVUE-Niveau Upgrade
status: planning
stopped_at: Completed 01-cli-state-machine-02-PLAN.md
last_updated: "2026-03-16T21:28:13.969Z"
last_activity: 2026-03-16 — .planning initialisiert, ROADMAP + REQUIREMENTS erstellt
progress:
  total_phases: 6
  completed_phases: 1
  total_plans: 2
  completed_plans: 2
  percent: 50
---

# Project State

## Project Reference

**Project:** Learning-NC — Nextcloud Spaced Repetition App
**Core value:** PBQ-Simulationen auf OnVUE-Niveau (CLI State Machine + SVG Topologie + Instructor Notes)
**Current focus:** Phase 1 — CLI State Machine (planning)

## Current Position

Phase: 1 of 6 (CLI State Machine)
Plan: 0 of 0 in current phase
Status: Ready to plan
Last activity: 2026-03-16 — .planning initialisiert, ROADMAP + REQUIREMENTS erstellt

Progress: [█████░░░░░] 50%

## Performance Metrics

**Velocity:**
- Total plans completed: 0
- Average duration: -
- Total execution time: -

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

### Pending Todos

None.

### Blockers/Concerns

None.

## Session Continuity

Last session: 2026-03-16T21:24:45.353Z
Stopped at: Completed 01-cli-state-machine-02-PLAN.md
Resume file: None
