# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-22)

**Core value:** VirtuProf beantwortet Fragen basierend auf echtem Kursmaterial, nicht nur Pool-Fragen.
**Current focus:** Phase 36 - Dokument-Upload + Extraktion

## Current Position

Phase: 36 of 39 (Dokument-Upload + Extraktion)
Plan: 1 of 1 complete
Status: Phase 36 complete
Last activity: 2026-03-22 — Plan 36-01 executed (Document Upload + Extraction backend)

Progress: [###-------] 25% (1/4 phases)

## Performance Metrics

**Velocity:**
- Total plans completed: 1 (this milestone)
- Average duration: 11min
- Total execution time: 11min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 36-dokument-upload-extraktion | 1/1 | 11min | 11min |

**Recent Trend:**
- Last 5 plans: 36-01 (11min)
- Trend: Starting

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Keyword-Search statt Embeddings (Stufe 3 spaeter)
- pdftotext fuer PDF-Extraktion (bereits im Container)
- 500-Token Chunks (Kompromiss Kontext/Praezision)
- BackgroundJob fuer Chunking (grosse PDFs blockieren nicht)
- [36-01] Removed unused IDBConnection from DocumentService (PHPStan clean code)
- [36-01] Regenerated PHPStan baseline for new QBMapper return types
- [36-01] File paths stored relative to user home for NC Files API compatibility

### Existing Architecture

- GeminiService mit 5-Layer Security existiert
- RagContextService existiert (aktuell nur Pool-Fragen)
- AiChatMemoryService existiert
- LernbotFileService kann NC-Dateien lesen/schreiben
- BackgroundJob-Pattern etabliert (ConsistencyCheckJob, NotificationJob)
- PostgreSQL 16, NC OCP\Files API
- pdftotext im Docker-Container verfuegbar
- [36-01] DocumentService + DocumentController (6 Endpoints) fuer Kurs-Materialordner + PDF/MD-Extraktion
- [36-01] learning_course_documents Tabelle + material_folder Spalte in learning_courses

### Pending Todos

None yet.

### Blockers/Concerns

None yet.

## Session Continuity

Last session: 2026-03-22
Stopped at: Completed 36-01-PLAN.md
Resume file: None
