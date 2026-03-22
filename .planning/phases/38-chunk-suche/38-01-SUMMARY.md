---
phase: 38-chunk-suche
plan: 01
subsystem: api
tags: [search, rag, ilike, postgresql, keyword-search]

requires:
  - phase: 37-chunking-pipeline
    provides: RagChunk entity, RagChunkMapper, learning_rag_chunks table
provides:
  - ChunkSearchService with search(courseId, query, limit) method
  - RagChunkMapper.searchByKeywords with relevance scoring
affects: [39-rag-context-integration]

tech-stack:
  added: []
  patterns: [ILIKE relevance scoring with CASE-WHEN sums, stopword extraction]

key-files:
  created: [app/lib/Service/ChunkSearchService.php]
  modified: [app/lib/Db/RagChunkMapper.php]

key-decisions:
  - "LOWER(x) LIKE pattern for cross-DB compatibility instead of iLike()"
  - "Chapter matches weighted 2x vs text matches for topical relevance"
  - "Punctuation stripping with German umlaut awareness in keyword extraction"

patterns-established:
  - "Relevance scoring: SUM of CASE WHEN LOWER(col) LIKE for each keyword"
  - "Stopword list covers DE+EN for bilingual course material"

requirements-completed: [SEARCH-01, SEARCH-02]

duration: 5min
completed: 2026-03-22
---

# Phase 38 Plan 01: Chunk Search Summary

**Keyword-based chunk search with ILIKE relevance scoring and DE/EN stopword extraction for RAG context retrieval**

## Performance

- **Duration:** 5 min
- **Started:** 2026-03-22T08:39:53Z
- **Completed:** 2026-03-22T08:45:45Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- RagChunkMapper.searchByKeywords with per-keyword relevance scoring (chapter=2, text=1)
- ChunkSearchService with keyword extraction (stopwords, short-word removal, dedup)
- Both files pass PHP lint and PHPStan level 5

## Task Commits

Each task was committed atomically:

1. **Task 1: RagChunkMapper searchByKeywords method** - `2400eb8` (feat)
2. **Task 2: ChunkSearchService with keyword extraction** - `5c3ac98` (feat)

## Files Created/Modified
- `app/lib/Db/RagChunkMapper.php` - Added searchByKeywords() with ILIKE matching and relevance scoring
- `app/lib/Service/ChunkSearchService.php` - New service: keyword extraction, search orchestration, debug logging

## Decisions Made
- Used `LOWER(col) LIKE LOWER(param)` pattern instead of `iLike()` for MySQL/PG compatibility
- Chapter matches weighted 2x (weight 2) vs text matches (weight 1) since chapter hit indicates topical relevance
- Keyword extraction strips punctuation with German umlaut awareness (a-z0-9 plus umlauts)
- Stopword list covers both German and English for bilingual course materials

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan type mismatch for mapRowToEntity return**
- **Found during:** Task 1
- **Issue:** mapRowToEntity() returns Entity base type, PHPStan required RagChunk in return array
- **Fix:** Added @var RagChunk annotation on intermediate variable
- **Files modified:** app/lib/Db/RagChunkMapper.php
- **Verification:** PHPStan passes clean
- **Committed in:** 2400eb8

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Minimal -- PHPStan type annotation needed for strict typing. No scope creep.

## Issues Encountered
- Docker cp produced 0-byte file due to SSHFS + permission issue; fixed with `sudo chown` + stdin redirect upload
- Pre-commit hook runs full PHPStan via SSH to container; required container file to be up-to-date before commit

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- ChunkSearchService ready for injection into RagContextService (Phase 39)
- No controller/route needed -- Phase 39 will call search() directly
- Container has latest files deployed

---
*Phase: 38-chunk-suche*
*Completed: 2026-03-22*
