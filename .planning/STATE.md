---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: Persönlicher Lernbot
status: planning
last_updated: "2026-03-21T15:01:39.716Z"
last_activity: 2026-03-21 — v4.0 roadmap created (Phases 22-27)
progress:
  total_phases: 27
  completed_phases: 21
  total_plans: 33
  completed_plans: 34
  percent: 100
---

## Current Position

Phase: 23 (NC Files Integration) — Not started
Plan: —
Status: Phase 22 complete (22-01-PLAN.md executed 2026-03-21)
Last activity: 2026-03-21 — Phase 22 LernprofilService implemented

Progress: [██████████] 100%

## Project Reference

**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — für Einzellerner und Kursgruppen.

**Current Milestone:** v4.0 Persönlicher Lernbot
**Milestone Goal:** Jeder User bekommt einen persönlichen KI-Lernbegleiter der Stärken/Schwächen kennt, individuelle Zusammenfassungen und Lernpläne als Obsidian-kompatible Markdown-Notes im NC-Dateisystem erstellt, und über Sessions hinweg mitlernt.

## Performance Metrics

| Metric | Value |
|--------|-------|
| Phases completed (v4.0) | 1/6 |
| Requirements mapped | 22/22 |
| Requirements complete | 4/22 (PROF-01..04) |
| Phase 22 P01 | 15min | 4 tasks | 6 files |

## Accumulated Context

### Architecture (existing, v3.2 basis)
- GeminiService + RagContextService + Chat-UI aus v3.2 sind die Basis
- NC Files API: IRootFolder→getUserFolder($userId)→newFile/newFolder — direkt aus PHP
- Obsidian braucht nur Markdown + YAML Frontmatter + Wiki-Links
- User hat NC Sync Client → Bot-Notes erscheinen automatisch lokal
- LiveSync (CouchDB) wäre Alternative aber zu komplex für diesen Scope
- Leitner/Training/Exam-Daten für Profil-Aggregation existieren in DB

### Key Decisions (v4.0)
- NC Files statt eigene DB für Notes — User besitzt seine Daten, Obsidian-kompatibel, NC Sync out-of-box
- Wiki-Links in Notes — Obsidian Graph View zeigt Wissenslandkarte
- Trigger-basiert statt proaktiv — Gemini-Kosten kontrollieren, nur bei echtem Bedarf generieren
- Max 50 Notes pro User — älteste archivieren
- Max 50 Kontext-Einträge für Chat-Memory — älteste komprimieren

### Constraints
- NC Files API: IRootFolder → getUserFolder() → direkte PHP-Dateizugriffe
- Note-Format: Standard-Markdown + YAML Frontmatter + Wiki-Links = Obsidian-kompatibel
- Speicher: Max 50 Notes pro User, älteste archivieren
- Gemini-Budget: Note-Generierung nur bei Trigger, nicht bei jeder Antwort
- Datenschutz: Notes lokal in NC, nur Lernfragen an Gemini, kein PII

### Phase 22 Decisions
- On-the-fly profile aggregation from existing tables (no new DB table)
- ICache TTL 300s with passive invalidation on every answer/session
- Pool-level granularity (chapter_ref not yet populated in existing data)
- Error rate = 1 - blended accuracy (50/50 Leitner + Training when both available)

## Session Continuity

Next action: `/gsd:plan-phase 23`

Phase 23 scope: NC Files Integration — create /Learning/ folder structure, write Markdown notes with YAML frontmatter + Wiki-Links, Obsidian-compatible.
