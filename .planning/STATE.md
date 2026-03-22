---
gsd_state_version: 1.0
milestone: v4.1
milestone_name: RAG Stufe 2
status: in_progress
stopped_at: Completed 37-01-PLAN.md
last_updated: "2026-03-22T08:31:00Z"
last_activity: 2026-03-22 — Plan 37-01 executed (Chunking Pipeline)
progress:
  total_phases: 39
  completed_phases: 28
  total_plans: 41
  completed_plans: 49
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-22)

**Core value:** VirtuProf beantwortet Fragen basierend auf echtem Kursmaterial, nicht nur Pool-Fragen.
**Current focus:** Phase 37 - Chunking Pipeline

## Current Position

Phase: 37 of 39 (Chunking Pipeline)
Plan: 1 of 1 complete
Status: Phase 37 complete
Last activity: 2026-03-22 — Plan 37-01 executed (Chunking Pipeline)

Progress: [██████████] 100%

## Performance Metrics

**Velocity:**
- Total plans completed: 1 (this milestone)
- Average duration: 11min
- Total execution time: 11min

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 36-dokument-upload-extraktion | 1/1 | 11min | 11min |
| 37-chunking-pipeline | 1/1 | 7min | 7min |

**Recent Trend:**
- Last 5 plans: 36-01 (11min), 37-01 (7min)
- Trend: Accelerating

*Updated after each plan completion*
| Phase 36 P02 | 3min | 2 tasks | 4 files |

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
- [Phase 36]: Used scoped CSS in CourseMaterials.vue for self-contained styling
- [Phase 36]: Auto-scan after folder linking for immediate instructor feedback
- [37-01] 4 chars/token heuristic for chunk token estimation (no tokenizer dependency)
- [37-01] Paragraph-first splitting with sentence fallback for oversized paragraphs
- [37-01] ALL CAPS heading detection for PDF text, title-case conversion for readability

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
- [37-01] learning_rag_chunks Tabelle + ChunkingService + ChunkingJob (5-min interval)
- [37-01] Chunking pipeline: extracted -> pending -> chunking -> chunked status flow

### Pending Todos

None yet.

### Blockers/Concerns

None yet.

## Session Continuity

Last session: 2026-03-22T08:31:00Z
Stopped at: Completed 37-01-PLAN.md
Resume file: None
