---
phase: 86-pipeline-tooling
plan: 02
subsystem: infra
tags: [bash, cli, devcloud, staleness, pipeline, slash-command]

# Dependency graph
requires:
  - phase: 86-01
    provides: "devcloud-sanitize.py CLI tool"
provides:
  - "Updated /lerninhalt skill with automated sanitize pipeline"
  - "devcloud-staleness.sh for detecting stale DevCloud content"
affects: [88-virtuprof-manifest, 89-cross-app-linking]

# Tech tracking
tech-stack:
  added: [bash]
  patterns: [staleness-mtime-compare, slash-command-pipeline-integration]

key-files:
  created: [scripts/devcloud-staleness.sh]
  modified: [~/.claude/commands/lerninhalt.md, .gitignore]

key-decisions:
  - "Staleness check uses mtime comparison (find -newer equivalent) rather than checksum for simplicity and speed"
  - "lerninhalt skill uploads from _devcloud/ exclusively, never from Personal-Vault directly"
  - "Exit code convention: 0=current, 1=stale/missing, 2=error (matching Unix conventions)"

patterns-established:
  - "Pipeline scripts in scripts/ with .gitignore negation pattern"
  - "Staleness detection via mtime comparison across vault and _devcloud/"

requirements-completed: [PIPE-02, PIPE-03]

# Metrics
duration: 3min
completed: 2026-03-27
---

# Phase 86 Plan 02: Lerninhalt Pipeline + Staleness Check Summary

**Automated sanitize pipeline in /lerninhalt skill and devcloud-staleness.sh for detecting outdated DevCloud content via mtime comparison**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-27T16:09:10Z
- **Completed:** 2026-03-27T16:12:14Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- /lerninhalt skill now runs sanitize pipeline automatically after content creation (Schritt 4)
- DevCloud upload reads exclusively from _devcloud/ (sanitized copies), never from Personal-Vault
- Staleness-check (Schritt 0) warns about outdated content before new content creation
- devcloud-staleness.sh detects stale and missing files across all 4 CompTIA tracks with colored output

## Task Commits

Each task was committed atomically:

1. **Task 1: /lerninhalt Skill mit Sanitize+Copy Pipeline erweitern** - non-repo file (~/.claude/commands/lerninhalt.md, outside git)
2. **Task 2: Staleness-Check Script** - `c4db9f7` (feat)

## Files Created/Modified
- `~/.claude/commands/lerninhalt.md` - Updated slash command with pipeline integration (outside repo, not committed)
- `scripts/devcloud-staleness.sh` - Bash script for stale content detection via mtime comparison
- `.gitignore` - Added negation for devcloud-staleness.sh

## Decisions Made
- Used mtime comparison (vault_file -nt devcloud_file) for staleness detection -- simple, fast, no dependency on checksums
- lerninhalt skill uploads exclusively from _devcloud/ to prevent accidental personal data leakage
- Colored output (STALE=yellow, MISSING=red) with tty detection for clean piped output
- Exit code 0/1/2 convention matching devcloud-sanitize.py pattern

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] .gitignore blocked scripts/ directory**
- **Found during:** Task 2 (commit)
- **Issue:** Blanket `scripts/` in .gitignore prevented adding devcloud-staleness.sh
- **Fix:** Added `!scripts/devcloud-staleness.sh` negation (same pattern as 86-01)
- **Files modified:** .gitignore
- **Verification:** File committed successfully
- **Committed in:** c4db9f7 (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Same pattern as 86-01, necessary for committing scripts. No scope creep.

## Issues Encountered
- Task 1 modifies ~/.claude/commands/lerninhalt.md which is outside the git repo -- cannot be committed as a git change. File was updated successfully but only tracked as documentation.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Phase 86 (Pipeline Tooling) is now complete: sanitize CLI + pipeline integration + staleness detection
- Ready for Phase 87 (NC Platform Setup) which has no dependency on Phase 86
- Ready for Phase 88 (VirtuProf Manifest) which depends on devcloud-sanitize.py from 86-01

---
*Phase: 86-pipeline-tooling*
*Completed: 2026-03-27*
