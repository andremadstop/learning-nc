---
gsd_state_version: 1.0
milestone: v11.0
milestone_name: Telos-Onboarding + VirtuProf Guide
status: roadmap_complete
stopped_at: null
last_updated: "2026-03-24"
last_activity: 2026-03-24 — Roadmap created (4 phases, 75-78)
progress:
  total_phases: 4
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-24)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v11.0 Telos-Onboarding + VirtuProf Guide

## Current Position

Phase: 75 of 78 (DB-Migration + Telos-API + Interview-Backend) — ready to plan
Plan: —
Status: Ready to plan Phase 75
Last activity: 2026-03-24 — Roadmap created (4 phases, 13 requirements mapped)

Progress: [░░░░░░░░░░] 0%

## Accumulated Context

### Decisions

- VirtuProf-Interview als Primary (10 Fragen, Structured Output)
- Formular als Fallback wenn KI deaktiviert
- user_telos als eigene DB-Tabelle (nicht NC config)
- Sichtbarkeits-Toggle: privat/kurs/dozent
- Guide-Modus: VirtuProf erklaert Tools beim ersten Besuch
- Antwortlaenge: kurz default, lang nur nach Eskalation
- Vornamen-Ansprache bereits implementiert (IUserManager)

### Existing Architecture

- VirtuProfController mit IUserManager + getUserFirstName()
- GeminiService mit userName-Parameter im System-Prompt
- LernprofilService aggregiert Staerken/Schwaechen automatisch
- VirtuProfBubble mit X-Button, Chat-History, Hint-System
- 11 User auf learning-dev, Dozent broecker

### Pending Todos

None.

### Blockers/Concerns

None.

## Session Continuity

Last session: 2026-03-24
Stopped at: Roadmap created, ready to plan Phase 75
Resume file: None
