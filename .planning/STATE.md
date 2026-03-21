---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: Persönlicher Lernbot
status: completed
last_updated: "2026-03-21T18:44:45.626Z"
last_activity: 2026-03-21 — Phase 27 Auto-Trigger implemented
progress:
  total_phases: 27
  completed_phases: 25
  total_plans: 37
  completed_plans: 39
  percent: 100
---

## Current Position

Phase: 27 (Auto-Trigger) — Complete
Plan: 27-01-PLAN.md
Status: Phase 27 complete (27-01-PLAN.md executed 2026-03-21)
Last activity: 2026-03-21 — Phase 27 Auto-Trigger implemented

Progress: [██████████] 100%

## Project Reference

**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — für Einzellerner und Kursgruppen.

**Current Milestone:** v4.0 Persönlicher Lernbot
**Milestone Goal:** Jeder User bekommt einen persönlichen KI-Lernbegleiter der Stärken/Schwächen kennt, individuelle Zusammenfassungen und Lernpläne als Obsidian-kompatible Markdown-Notes im NC-Dateisystem erstellt, und über Sessions hinweg mitlernt.

## Performance Metrics

| Metric | Value |
|--------|-------|
| Phases completed (v4.0) | 3/6 |
| Requirements mapped | 22/22 |
| Requirements complete | 13/22 (PROF-01..04, FILES-01..05, NOTE-01..04) |
| Phase 22 P01 | 15min | 4 tasks | 6 files |
| Phase 23 P01 | 10min | 4 tasks | 3 files |
| Phase 24 P01 | 32min | 4 tasks | 4 files |
| Phase 25 P01 | 29min | 4 tasks | 3 files |
| Phase 27 P01 | 19min | 4 tasks | 7 files |
| Phase 26 P01 | 143 | 6 tasks | 10 files |

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

### Phase 24 Decisions
- generateNote() in GeminiService bypasses user rate-limit — internal call, not user input
- NoteGeneratorService strips any Gemini-generated frontmatter and replaces with authoritative meta
- slugify() normalizes German umlauts for cross-platform-safe filenames
- Pool access check in Controller: ownership OR pool_share OR course membership/instructor
- FOLDER_SUMMARIES constant was private in LernbotFileService — used string literal in NoteGeneratorService

### Phase 25 Decisions
- Fortschritt.md is data-driven (no Gemini) — faster, cheaper, always available without API key
- Lernplan.md written to /Learning/ root via empty subfolder arg in LernbotFileService::writeNote
- buildWeeklySummary groups 28-day history into 4 rolling ISO week buckets
- Language defaults to 'de' (not 'en') — target audience is German-speaking learners

### Phase 26 Decisions
- Memory entries injected as system prompt addendum (not Gemini conversation history API) — avoids multi-turn billing, stays within callGeminiApi() architecture
- loadMemory() passes max 10 entries to Gemini to prevent token overflow; DB stores up to 50
- Compression via generateNote() reuses existing trusted-caller path bypassing user rate limits; fallback to static summary if Gemini unavailable
- Summary entries stored as role=summary in DB but filtered from getChatHistory response (internal compression artefact)
- DB files (migration/entity/mapper) were already committed in Phase 27 scaffolding by Codex — adopted as-is

### Phase 27 Decisions
- Hook TRIG-01 in TrainingService.completeSession() for single responsibility; tryAutoGenerateExamNote() swallows errors to protect exam flow
- TRIG-02 count query uses 30-day window to catch cumulative weak patterns across sessions
- WeeklyLernplanJob generates note for weakest pool only (top 1) to control Gemini API costs
- LernplanService PHPStan false positive (comparison 0 > 0) added to baseline — DB integer inferred as literal 0

## Session Continuity

v4.0 Milestone complete — all 27 phases implemented.
Next action: Release v4.0 (CHANGELOG + tarball + signing + App Store upload)
