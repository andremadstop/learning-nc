---
phase: quick
plan: 01
subsystem: database
tags: [occ-command, rag, obsidian, markdown, chunking, vault-import]

requires:
  - phase: none
    provides: existing RagChunk/RagChunkMapper infrastructure
provides:
  - OCC command learning:import-vault for Obsidian vault RAG import
  - deleteByDocumentIdAndCourseId method on RagChunkMapper
affects: [virtuprof, rag-search, comptia-content]

tech-stack:
  added: []
  patterns: [OCC command with Symfony Console, inline chunking for decoupled imports]

key-files:
  created:
    - app/lib/Command/ImportVaultCommand.php
  modified:
    - app/lib/Db/RagChunkMapper.php
    - app/appinfo/info.xml
    - app/phpstan.neon

key-decisions:
  - "Inline chunking instead of reusing ChunkingService (coupled to CourseDocument entity)"
  - "Added Symfony Console to PHPStan scanDirectories for OCC command analysis"

patterns-established:
  - "OCC command pattern: DI via constructor, Symfony Console Command base class"
  - "Vault import uses document_id=0 as marker for non-document chunks"

requirements-completed: [VAULT-IMPORT-01]

duration: 6min
completed: 2026-03-28
---

# Quick Task 1: CompTIA A+ Vault als RAG-Quelle importieren Summary

**OCC command learning:import-vault importing Obsidian MD vaults as RAG chunks with frontmatter/wikilink stripping and idempotent re-import**

## Performance

- **Duration:** 6 min
- **Started:** 2026-03-28T16:17:49Z
- **Completed:** 2026-03-28T16:23:39Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments
- OCC command `learning:import-vault` with `--path` and `--course-id` options registered and callable
- Obsidian markdown cleanup: strips YAML frontmatter, image embeds, wikilinks, callouts
- Chunking at ~500 tokens with heading and parent-directory chapter detection
- Idempotent: re-run deletes only document_id=0 chunks for the target course, preserves all existing 179 document chunks

## Task Commits

Each task was committed atomically:

1. **Task 1: Add deleteByDocumentIdAndCourseId + register command** - `083858b` (feat)
2. **Task 2: Create ImportVaultCommand.php** - `4f10f91` (feat)

## Files Created/Modified
- `app/lib/Command/ImportVaultCommand.php` - OCC command with vault import, MD cleanup, chunking logic
- `app/lib/Db/RagChunkMapper.php` - Added deleteByDocumentIdAndCourseId for idempotent cleanup
- `app/appinfo/info.xml` - Registered ImportVaultCommand in commands block
- `app/phpstan.neon` - Added Symfony Console to scanDirectories for PHPStan

## Decisions Made
- Inline chunking instead of reusing ChunkingService -- the service's splitIntoChunks is private and coupled to CourseDocument entity
- Added `/var/www/html/3rdparty/symfony/console/` to PHPStan scanDirectories because NC bundles Symfony Console in 3rdparty, not via composer require

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan could not find Symfony Console classes**
- **Found during:** Task 2 (ImportVaultCommand PHPStan verification)
- **Issue:** Symfony Console is bundled in NC 3rdparty, not in the app's vendor; PHPStan reported 19 errors about unknown classes
- **Fix:** Added `/var/www/html/3rdparty/symfony/console/` to phpstan.neon scanDirectories
- **Files modified:** app/phpstan.neon
- **Verification:** PHPStan level 5 passes with 0 errors
- **Committed in:** 4f10f91 (Task 2 commit)

**2. [Rule 3 - Blocking] info.xml owned by www-data on learning-dev**
- **Found during:** Task 1 (deploy)
- **Issue:** scp failed due to file ownership; used /tmp copy + sudo workaround
- **Fix:** Deployed via /tmp and sudo cp
- **Files modified:** none (deploy workflow only)
- **Verification:** File deployed successfully

---

**Total deviations:** 2 auto-fixed (2 blocking)
**Impact on plan:** Both fixes necessary for PHPStan verification and deployment. No scope creep.

## Issues Encountered
- NC required `occ upgrade` after adding the `<commands>` block to info.xml (version fingerprint changed). Ran upgrade successfully.

## User Setup Required

Before running the import, the CompTIA vault must be volume-mounted into the container:

```bash
# Mount vault into container (docker-compose or bind mount)
# Then run:
ssh learning-dev 'docker exec -u www-data learning-app php occ learning:import-vault --path=/data/comptia-vault/comptia-a-plus-vault --course-id=20'
```

## Next Phase Readiness
- Command ready for production use once vault is mounted
- Existing 179 RAG chunks (document_id > 0) verified untouched
- VirtuProf search (searchByKeywords) will automatically include imported vault chunks

## Self-Check: PASSED

All 4 files found, both commit hashes verified.

---
*Phase: quick-01*
*Completed: 2026-03-28*
