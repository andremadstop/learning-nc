---
gsd_state_version: 1.0
milestone: v4.2.0
milestone_name: Lehrplan-Timeline + Admin-Werkzeuge
status: Planning
last_updated: "2026-04-08T22:30:00.000Z"
last_activity: 2026-04-08 — v4.2.0 milestone started
progress:
  total_phases: 0
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-08)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v4.2.0 Lehrplan-Timeline + Admin-Werkzeuge

## Current Position

Phase: Not started (defining requirements)
Plan: —
Status: Defining requirements
Last activity: 2026-04-08 — Milestone v4.2.0 started

## Accumulated Context

### Decisions
- [v4.2.0] Kein Adminer — NLM warnt vor IDOR-Risiko, stattdessen OCC-Commands + API
- [v4.2.0] course_schedule Tabelle — Verknüpft chapter_ref mit Datum, synchron mit curriculum_scopes
- [v4.2.0] Export-Logik als DataMobilityService — wiederverwendbar für OCC + API
- [v4.2.0] Jahrgangs-Merge muss FSRS-Stabilitätswerte erhalten
- [v4.2.0] Timeline nutzt bestehende chapter_ref + curriculum_scopes als Basis
- [v4.2.0] occ learning:import-vault als Vorbild für neue OCC-Commands

### NLM Research (2026-04-08)
- curriculum_scopes + chapter_ref bilden logische Basis für Timeline-Stationen
- LeitnerService.getStats(poolId, userId) für Fortschritt pro Pool
- CourseSummaryService für aggregierte Sicht
- ExamReadiness/Countdown Widget als UI-Vorbild
- ExportController hat CSV/JSON Export — in Service extrahieren für OCC-Zugriff
- occ learning:import-vault existiert als Command-Vorbild
- CourseSnapshotService für Archivierung

## Session Continuity

Last session: 2026-04-08
Stopped at: v4.2.0 planning started
Next action: Create REQUIREMENTS.md and ROADMAP.md, then build
