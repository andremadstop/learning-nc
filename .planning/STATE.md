---
gsd_state_version: 1.0
milestone: v9.0
milestone_name: Simulator-Werkzeuge
status: defining_requirements
stopped_at: null
last_updated: "2026-03-24"
last_activity: 2026-03-24 — Milestone v9.0 started
progress:
  total_phases: 0
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-24)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** Defining requirements for v9.0 Simulator-Werkzeuge

## Current Position

Phase: Not started (defining requirements)
Plan: —
Status: Defining requirements
Last activity: 2026-03-24 — Milestone v9.0 started

## Accumulated Context

### Decisions

- Alle 7 Simulatoren in Scope (DNS, Firewall, Port-Scanner, Routing, NAT, Wireshark-Lite, 802.1X)
- Rein Frontend, kein Backend
- Kampagnenfaehig (einbettbar in Abenteuer/Zeitreise)
- Gleiche UX-Patterns wie Subnetzrechner (Toggle, Uebungsmodus, Erklaer-Modus)

### Existing Architecture

- Werkzeuge-Tab in App.vue (Subnetzrechner + VLAN)
- CLI State Machine (cliStateMachine.js) fuer Terminal-Simulationen
- SVG Topology Renderer (NetworkTopologySvg.vue) fuer Netzwerk-Diagramme
- Subnetzrechner Patterns: togglePresets.js, practiceEngine.js, subnetExplainer.js
- AbenteuerMode.vue mit simulation-Feld fuer eingebettete Simulatoren

### Pending Todos

None.

### Blockers/Concerns

None.

## Session Continuity

Last session: 2026-03-24
Stopped at: Requirements definition
Resume file: None
