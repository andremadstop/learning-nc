---
phase: 37-chunking-pipeline
plan: 01
subsystem: database, backend
tags: [rag, chunking, background-job, text-processing, nlp]

requires:
  - phase: 36-dokument-upload-extraktion
    provides: CourseDocument entity with extracted text, DocumentService extraction pipeline
provides:
  - learning_rag_chunks table for storing document text chunks
  - RagChunk entity and mapper with course/document lookup
  - ChunkingService with ~500-token splitting and chapter detection
  - ChunkingJob background job for automatic chunking after extraction
  - chunking_status tracking on CourseDocument
affects: [38-keyword-search, rag-pipeline, virtuprof]

tech-stack:
  added: []
  patterns: [paragraph-accumulation chunking, heading detection heuristic, 4-char/token estimation]

key-files:
  created:
    - app/lib/Migration/Version004400Date20260322000000.php
    - app/lib/Db/RagChunk.php
    - app/lib/Db/RagChunkMapper.php
    - app/lib/Service/ChunkingService.php
    - app/lib/BackgroundJob/ChunkingJob.php
  modified:
    - app/lib/Db/CourseDocument.php
    - app/lib/Db/CourseDocumentMapper.php
    - app/lib/Service/DocumentService.php
    - app/lib/AppInfo/Application.php

key-decisions:
  - "4 chars/token heuristic for token estimation -- simple and sufficient for keyword search"
  - "Paragraph-first splitting with sentence fallback for oversized paragraphs"
  - "ALL CAPS heading detection for PDF-extracted text (title-case conversion for readability)"
  - "5-minute ChunkingJob interval for near-real-time processing"
  - "Removed unused CourseDocumentMapper DI from ChunkingService (PHPStan clean code)"

patterns-established:
  - "Chunking pipeline: extraction -> pending -> chunking -> chunked status flow"
  - "Re-chunking safe: deleteByDocumentId before insert for idempotent processing"

requirements-completed: [CHUNK-01, CHUNK-02, CHUNK-03, CHUNK-04]

duration: 7min
completed: 2026-03-22
---

# Phase 37 Plan 01: Chunking Pipeline Summary

**Background job splits extracted document text into ~500-token chunks with Markdown/PDF heading detection, stored in learning_rag_chunks for keyword search**

## Performance

- **Duration:** 7 min
- **Started:** 2026-03-22T08:23:29Z
- **Completed:** 2026-03-22T08:30:59Z
- **Tasks:** 2
- **Files modified:** 9

## Accomplishments
- DB migration creates learning_rag_chunks table with indices on course_id and document_id
- ChunkingService splits text by paragraphs, accumulates to ~500 tokens, detects headings (ATX, setext, ALL CAPS)
- ChunkingJob runs every 5 minutes, picks up extracted-but-unchunked documents automatically
- Full pipeline wiring: DocumentService sets chunking_status='pending' after extraction, Application.php registers the job

## Task Commits

Each task was committed atomically:

1. **Task 1: DB Migration + RagChunk Entity + Mapper** - `97e5805` (feat)
2. **Task 2: ChunkingService + ChunkingJob + Wiring** - `e3450ef` (feat)

## Files Created/Modified
- `app/lib/Migration/Version004400Date20260322000000.php` - Creates learning_rag_chunks table + chunking_status column
- `app/lib/Db/RagChunk.php` - Entity with all typed properties and jsonSerialize
- `app/lib/Db/RagChunkMapper.php` - Mapper with findByDocumentId, findByCourseId, deleteByDocumentId
- `app/lib/Service/ChunkingService.php` - Text chunking with heading detection and sentence splitting
- `app/lib/BackgroundJob/ChunkingJob.php` - TimedJob processing extracted documents every 5 min
- `app/lib/Db/CourseDocument.php` - Added chunkingStatus property
- `app/lib/Db/CourseDocumentMapper.php` - Added findExtractedUnchunked method
- `app/lib/Service/DocumentService.php` - Sets chunking_status='pending' after extraction
- `app/lib/AppInfo/Application.php` - Registers ChunkingJob in boot()

## Decisions Made
- Used 4 chars/token heuristic for token estimation (sufficient for keyword search, avoids tokenizer dependency)
- Paragraph-first splitting strategy with sentence-level fallback for oversized paragraphs
- ALL CAPS lines detected as PDF headings, converted to title case for readability
- Removed unused CourseDocumentMapper from ChunkingService DI (PHPStan flagged it)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Removed unused DI dependency from ChunkingService**
- **Found during:** Task 2 (PHPStan verification)
- **Issue:** CourseDocumentMapper was injected but never read in ChunkingService
- **Fix:** Removed the unused property and constructor parameter
- **Files modified:** app/lib/Service/ChunkingService.php
- **Verification:** PHPStan passes clean
- **Committed in:** e3450ef (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Minor cleanup for PHPStan compliance. No scope creep.

## Issues Encountered
- Docker cp failed with disk space errors when copying entire app directory; resolved by copying individual files via tar
- PHPStan cache showed stale errors after file replacement; resolved by clearing result cache

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- learning_rag_chunks table ready for Phase 38 (Keyword Search) to query against
- ChunkingJob will automatically populate chunks after document extraction
- End-to-end verification pending: run migration on learning-dev, trigger extraction, verify chunks created

---
*Phase: 37-chunking-pipeline*
*Completed: 2026-03-22*
