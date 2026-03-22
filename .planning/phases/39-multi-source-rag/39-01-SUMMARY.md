---
phase: 39-multi-source-rag
plan: 01
subsystem: ai
tags: [rag, gemini, chunks, citations, context-window, virtuprof]

requires:
  - phase: 38-chunk-suche
    provides: ChunkSearchService with keyword-based chunk retrieval
provides:
  - Multi-source RAG context builder with prioritized filling (chunks > questions > weaknesses)
  - Source citation rendering [Quelle: filename, Kap. X] in Gemini system prompt
  - User weakness detection from answer history
affects: [virtuprof, gemini, rag]

tech-stack:
  added: []
  patterns: [priority-based token budget enforcement, source citation rendering]

key-files:
  created: []
  modified:
    - app/lib/Service/RagContextService.php
    - app/lib/Service/GeminiService.php
    - app/lib/Controller/VirtuProfController.php

key-decisions:
  - "7500 token budget (conservative within 30k Gemini window, leaves room for system prompt + memory)"
  - "Priority trimming order: weaknesses dropped first, then pool questions, then chunks last"
  - "User weaknesses derived from user_answers table GROUP BY wrong count"

patterns-established:
  - "Priority-based context filling: add layers in priority order, trim in reverse"
  - "Citation format: [Quelle: filename, Kap. X] for document chunk sources"

requirements-completed: [RAG-01, RAG-02, RAG-03, RAG-04]

duration: 3min
completed: 2026-03-22
---

# Phase 39 Plan 01: Multi-Source RAG Context Summary

**Multi-source RAG context with document chunks as primary knowledge source, [Quelle: ...] citations, user weakness detection, and priority-based 7500-token budget enforcement**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-22T08:52:44Z
- **Completed:** 2026-03-22T08:55:37Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments

- RagContextService aggregates 4 knowledge sources with prioritized context-window filling
- GeminiService renders document chunks with [Quelle: filename, Kap. X] labels and instructs Gemini to cite sources
- User weaknesses (most frequently wrong questions) included in context for targeted tutoring
- Backward compatible: existing callers without userMessage param continue to work

## Task Commits

Each task was committed atomically:

1. **Task 1: Upgrade RagContextService with multi-source prioritized context** - `e08ba7e` (feat)
2. **Task 2: Update GeminiService to render chunks with citations and VirtuProfController to pass userMessage** - `81b1c7d` (feat)

## Files Created/Modified

- `app/lib/Service/RagContextService.php` - Multi-source context builder with ChunkSearchService injection, user weakness loading, priority-based token budget enforcement
- `app/lib/Service/GeminiService.php` - Chunk rendering with [Quelle: ...] labels, citation instruction in system prompt, user weakness rendering
- `app/lib/Controller/VirtuProfController.php` - Pass user message to buildContext for chunk search

## Decisions Made

- Token budget increased from 4000 to 7500 (Gemini context is ~30k, system prompt + memory use ~5k, 7500 is conservative)
- Priority trimming: drop weaknesses first, then pool questions, then chunks as last resort
- User weaknesses query uses JOIN on user_answers + questions tables with GROUP BY and wrong count ordering

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- RAG pipeline complete: upload -> extract -> chunk -> search -> context -> cite
- Ready for embedding-based search upgrade (Stufe 3) when desired
- Ready for end-to-end testing with real course materials

---
*Phase: 39-multi-source-rag*
*Completed: 2026-03-22*
