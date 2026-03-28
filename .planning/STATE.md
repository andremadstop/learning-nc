---
gsd_state_version: 1.0
milestone: v2.3
milestone_name: milestone
status: executing
stopped_at: Completed 98-02-PLAN.md
last_updated: "2026-03-28T12:38:14.380Z"
last_activity: 2026-03-28 — Phase 98 complete (PracticumRunner UI + Praxis Tab in all 7 simulators)
progress:
  total_phases: 5
  completed_phases: 3
  total_plans: 6
  completed_plans: 6
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-28)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v3.4.0 UX-Konsolidierung & Simulator-Upgrade — Phase 96 next

## Current Position

Phase: 98 — Simulator-Praxis-Sessions (complete)
Plan: 02 complete (2/2)
Status: Phase 98 complete
Last activity: 2026-03-28 — Phase 98 complete (PracticumRunner UI + Praxis Tab in all 7 simulators)

Progress: [██████████] 100% (116/110 plans)

## Phase Overview

| Phase | Name | Requirements | Status |
|-------|------|--------------|--------|
| 96 | UX-Navigation Struktur | NAV-01, NAV-02, NAV-03, NAV-04 | In progress (1/? plans) |
| 97 | Code-Hygiene & Settings | NAV-05, NAV-06, NAV-07 | Not started |
| 98 | Simulator-Praxis-Sessions | SIM-01, SIM-02, SIM-03 | Complete (2/2 plans) |
| 99 | Student-Dashboard | DASH-01, DASH-02, DASH-03 | Not started |
| 100 | DevCloud-Integration & Leitner | DVCL-01, DVCL-02, DVCL-03, DVCL-04, LEIT-01 | Not started |

## Accumulated Context

### Decisions

- Dual-Audit (Codex + Gemini) completed 2026-03-28 — 17 independent findings
- Versionierung synchronisiert: GSD-Milestones = App Store Version (v3.4.0 nach v3.3.0)
- v13.0 Git-Tag geloescht (war interne GSD-Nummer, nicht App-Version)
- Simulator-Uebungen komplett ersetzen: gefuehrte Praxis-Sessions statt Random
- Codex-exklusive Findings: Pool-Ebene versteckt, Oldschool Dead End, Kursregeln != UI-Gating, Settings-Split, Werkzeug-Regeln
- Gemini-exklusiv: Leitner Sprint-Intervalle fuer Kurzkurse
- Beide: Abenteuer aus Arena, DE/EN Mix, Dozent 16 Tabs, Talk/Deck nicht integriert
- Phase 96+97 gruppieren nach Komplexitaet: Struktur-Aenderungen (96) getrennt von Hygiene-Aenderungen (97)
- Phase 100 haengt auch von Phase 96 ab (CourseDetail-Tab-Struktur muss vor Materialien-Tab stehen)
- [Phase 96]: Tab groups: Lernraum > Teilnehmer > Kommunikation > Wettbewerb > Verwaltung; student tabs stay flat
- [Phase 96]: Sprint+Elimination share gameshow config key; visibleTabs watcher added as tab-fallback safety
- [Phase 97]: Settings sub-tabs use course-sub-nav pattern for visual consistency
- [Phase 97]: epoch-tokens.css import kept, backend Zeitreise cleanup deferred
- [Phase 97]: MODE_MAP labels stay as const keys, this.t() applied in modeConfig computed
- [Phase 98]: Engine uses lnc-practicum- localStorage prefix for session persistence
- [Phase 98]: Session steps reference existing scenarioIds from *_scenarios.json (zero duplication)
- [Phase 98]: PracticumRunner uses SimulatorShell for embedded rendering per step
- [Phase 98]: Context panel collapsible, v-else changed to v-else-if in all 7 simulators

### Pending Todos

None yet.

### Blockers/Concerns

- Vue 2.7 Constraint bleibt — alle Aenderungen Vue 2 kompatibel
- App Store Release erst nach UX-Konsolidierung
- Phase 100 braucht Talk-Raum-IDs pro Kurs aus der DB — klaeren ob diese schon existieren

## Performance Metrics

- v13.0: 6 phases, 12 plans, 19 feature commits
- Test coverage: 549+ Vitest, PHPStan Level 5, 67 Playwright Checks

## Session Continuity

Last session: 2026-03-28T12:38:14.365Z
Stopped at: Completed 98-02-PLAN.md
Resume file: None
Next action: Execute next plan in phase 96
