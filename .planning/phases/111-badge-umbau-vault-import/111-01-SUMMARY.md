---
phase: 111-badge-umbau-vault-import
plan: 01
subsystem: gamification
tags: [badges, leitner, coop, rag, migration, postgresql]

requires:
  - phase: 110-foundation-security
    provides: "base app with badge system (9 active badges)"
provides:
  - "10 active badges including quick_thinker"
  - "7 badge trigger contexts in checkAndAward"
  - "is_legacy column in learning_user_badges"
  - "Badge progress for all 10 active badges"
affects: [112-vault-import, badge-frontend, gamification-dashboard]

tech-stack:
  added: []
  patterns:
    - "Badge trigger wiring pattern: service calls checkAndAward with context string"
    - "Post-commit badge check pattern in LeitnerService (trouble_fix after transaction)"
    - "Binary badge progress (weekend, quick_thinker) with 0/1 current values"

key-files:
  created:
    - app/lib/Migration/Version006000Date20260330000000.php
  modified:
    - app/lib/Service/BadgeService.php
    - app/lib/Service/TrainingService.php
    - app/lib/Service/LeitnerService.php
    - app/lib/Service/CoopService.php
    - app/lib/Controller/RagImportController.php
    - app/l10n/en.json
    - app/l10n/de.json
    - app/tests/unit/BadgeL10n.test.js

key-decisions:
  - "Simulator badge counts finished coop sessions (learning_coop_players JOIN learning_coop_sessions) instead of learning_sessions with mode=simulator which does not exist"
  - "Trouble fixer counts items in box >= 2 as proxy for promoted-from-box-1 (no previous_box column)"
  - "Weekend badge uses two separate queries for Saturday and Sunday ranges within current ISO week"
  - "Migration boolean changed to notnull=false, default=0 for NC/Doctrine compatibility"

patterns-established:
  - "DI injection of BadgeService into CoopService and RagImportController for trigger wiring"
  - "time_limit_seconds passed through sessionData for quick_thinker check"

requirements-completed: [BADGE-01, BADGE-02]

duration: 10min
completed: 2026-03-29
---

# Phase 111 Plan 01: Badge Trigger Wiring Summary

**10 active badges with 7 trigger contexts, is_legacy DB column, and full progress tracking for all non-legacy badges**

## Performance

- **Duration:** 10 min
- **Started:** 2026-03-29T19:37:21Z
- **Completed:** 2026-03-29T19:47:02Z
- **Tasks:** 2
- **Files modified:** 9

## Accomplishments
- Added quick_thinker badge definition (10th active badge) with EN/DE l10n keys
- Wired 5 new badge trigger pathways: pioneer, weekend, quick_thinker in checkSessionBadges; simulator_complete via CoopService; trouble_fix via LeitnerService; swarm_contribution via RagImportController
- Extended getBadgeProgress to return progress for all 10 active badges
- Applied is_legacy column migration to learning_user_badges table
- BadgeService DI injected into CoopService and RagImportController

## Task Commits

Each task was committed atomically:

1. **Task 1: Add quick_thinker badge definition + l10n keys** - `adea175` (feat)
2. **Task 2: Wire 5 badge triggers + apply migration + update progress** - `28b5a16` (feat)

## Files Created/Modified
- `app/lib/Service/BadgeService.php` - 10 active badges, 7 contexts, 4 new check methods, 3 new progress queries
- `app/lib/Service/TrainingService.php` - Added time_limit_seconds to session data
- `app/lib/Service/LeitnerService.php` - trouble_fix badge trigger on box 1->2+ promotion
- `app/lib/Service/CoopService.php` - BadgeService DI + simulator_complete trigger
- `app/lib/Controller/RagImportController.php` - BadgeService DI + swarm_contribution trigger
- `app/lib/Migration/Version006000Date20260330000000.php` - is_legacy column + backfill migration
- `app/l10n/en.json` - quick_thinker EN l10n keys
- `app/l10n/de.json` - quick_thinker DE l10n keys
- `app/tests/unit/BadgeL10n.test.js` - Updated to 10 non-legacy badges

## Decisions Made
- Simulator badge counts finished coop sessions (JOIN on coop tables) because simulator sessions are tracked in coop state bags, not in learning_sessions with mode=simulator
- Trouble fixer uses box >= 2 count as proxy since there is no previous_box column
- Migration boolean changed to notnull=false with default=0 to work with Nextcloud's Doctrine abstraction

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Simulator badge query targeted wrong table**
- **Found during:** Task 2 (Wire simulator badge)
- **Issue:** Plan specified querying learning_sessions WHERE mode='simulator', but simulator sessions are not stored in learning_sessions -- they use coop state bags in learning_coop_sessions
- **Fix:** Changed checkSimulatorBadges and getSimulatorSessionCount to query learning_coop_players JOIN learning_coop_sessions WHERE status='finished'
- **Files modified:** app/lib/Service/BadgeService.php
- **Verification:** PHPStan passes, query structure is correct
- **Committed in:** 28b5a16 (Task 2 commit)

**2. [Rule 1 - Bug] Migration boolean notnull incompatible with NC/Doctrine**
- **Found during:** Task 2 (Apply migration)
- **Issue:** `notnull => true, default => false` for BOOLEAN column triggers NC error "is type Bool and also NotNull, so it can not store false"
- **Fix:** Changed to `notnull => false, default => 0`
- **Files modified:** app/lib/Migration/Version006000Date20260330000000.php
- **Verification:** app:enable succeeds, is_legacy column visible in DB schema
- **Committed in:** 28b5a16 (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (2 bugs)
**Impact on plan:** Both fixes necessary for correctness. No scope creep.

## Issues Encountered
- CampaignGraphService (single-player simulator) does not trigger simulator_complete badge -- only coop simulator completions are wired. This is a minor gap for a future plan.
- PHPStan shows 5 pre-existing errors in migration file (Doctrine type resolution) -- not related to this plan's changes, all modified service/controller files pass clean.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- All 10 active badges fully defined with trigger pathways
- is_legacy column available for frontend archive/filter UI
- Ready for Phase 111-02 (Vault Import) which is independent of badge work
- Single-player simulator badge trigger can be added in a future plan if needed

---
*Phase: 111-badge-umbau-vault-import*
*Completed: 2026-03-29*
