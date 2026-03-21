---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: Persönlicher Lernbot
status: in_progress
last_updated: "2026-03-21T15:15:00Z"
last_activity: 2026-03-21 — Phase 23 NC Files Integration implemented
progress:
  total_phases: 27
  completed_phases: 22
  total_plans: 35
  completed_plans: 35
  percent: 100
---

## Current Position

Phase: 24 (Note-Generator) — Not started
Plan: —
Status: Phase 23 complete (23-01-PLAN.md executed 2026-03-21)
Last activity: 2026-03-21 — Phase 23 NC Files Integration implemented

Progress: [██████████] 100%

## Project Reference

**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — für Einzellerner und Kursgruppen.

**Current Milestone:** v4.0 Persönlicher Lernbot
**Milestone Goal:** Jeder User bekommt einen persönlichen KI-Lernbegleiter der Stärken/Schwächen kennt, individuelle Zusammenfassungen und Lernpläne als Obsidian-kompatible Markdown-Notes im NC-Dateisystem erstellt, und über Sessions hinweg mitlernt.

## Performance Metrics

| Metric | Value |
|--------|-------|
| Phases completed (v4.0) | 2/6 |
| Requirements mapped | 22/22 |
| Requirements complete | 9/22 (PROF-01..04, FILES-01..05) |
| Phase 22 P01 | 15min | 4 tasks | 6 files |
| Phase 23 P01 | 10 | 4 tasks | 3 files |

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

### Phase 23 Decisions
- IRootFolder autowired — no Application.php changes needed for LernbotFileService
- writeNote is idempotent by filename — same subfolder+filename overwrites existing file (no duplicates)
- Body written verbatim — Wiki-Links and Tags preserved for Obsidian compatibility
- Subfolder auto-created inside writeNote — Phase 24+ can target custom subfolders without setup

## Session Continuity

Next action: `/gsd:plan-phase 24`

Phase 24 scope: Note-Generator — Gemini generates summaries for weak topics, saves as NC Files notes via LernbotFileService.
