---
phase: 07-live-duell
plan: "01"
subsystem: api
tags: [php, postgresql, qbmapper, migration, live-duel, short-polling]

# Dependency graph
requires:
  - phase: 06-instructor-notes
    provides: Migration pattern and NC app framework conventions
provides:
  - oc_learning_duel_sessions table (code, UIDs, pool_id, question_ids, status, scores, ready flags, poll timestamps)
  - oc_learning_duel_answers table (duel_id, question_index, player_uid, answer_correct, answered_at, points_earned)
  - DuelService with full business logic (create/join/ready/state/answer/rematch + scoring matrix + 30s timeout)
  - DuelController with 6 endpoints all returning correct JSON
  - 6 routes registered in routes.php
affects: [07-02-frontend, any phase that uses duel state]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "boolean NC migration columns must use notnull:false to avoid 'Bool and NotNull' constraint error"
    - "OCS-APIRequest: true header required to bypass CSRF for curl-based API testing"
    - "bin2hex(random_bytes(3)) generates 6-char hex codes for unique room codes"

key-files:
  created:
    - app/lib/Migration/Version002400Date20260318000000.php
    - app/lib/Migration/Version002500Date20260318000000.php
    - app/lib/Db/DuelSession.php
    - app/lib/Db/DuelSessionMapper.php
    - app/lib/Db/DuelAnswer.php
    - app/lib/Db/DuelAnswerMapper.php
    - app/lib/Service/DuelService.php
    - app/lib/Controller/DuelController.php
  modified:
    - app/appinfo/routes.php

key-decisions:
  - "Scoring matrix from CONTEXT.md: correct+steal=+4, both correct faster/slower=+3/+2, tied within 50ms=+3/+3, both wrong=-1"
  - "30s inactivity timeout: getState detects abandoned player via last_poll timestamp, marks session expired, forfeit win"
  - "Boolean migration columns use notnull:false due to NC/Doctrine 'Bool and NotNull' constraint limitation"
  - "OCS-APIRequest header used to bypass CSRF in API requests (same as all other app endpoints)"
  - "question_type column name in learning_questions is question_type (not type); question text column is text (not question)"

patterns-established:
  - "DuelController follows TrainingController pattern: @NoAdminRequired + #[UserRateLimit] attributes"
  - "DuelService injects DuelSessionMapper, DuelAnswerMapper, IDBConnection, LoggerInterface via constructor"
  - "State response includes my_role to let frontend know creator vs opponent perspective"

requirements-completed: [DUEL-01, DUEL-02, DUEL-03]

# Metrics
duration: 8min
completed: 2026-03-18
---

# Phase 07 Plan 01: Live-Duell Backend Summary

**PHP backend for Duell-Modus: 2 DB tables, 4 Entity/Mapper files, DuelService with steal-scoring matrix + 30s timeout detection, DuelController with 6 HTTP endpoints, all live on learning-dev**

## Performance

- **Duration:** 8 min
- **Started:** 2026-03-18T10:58:43Z
- **Completed:** 2026-03-18T11:07:05Z
- **Tasks:** 2
- **Files modified:** 9

## Accomplishments
- oc_learning_duel_sessions + oc_learning_duel_answers tables created and migrated on learning-dev PostgreSQL
- DuelService: all 6 business methods including steal-scoring matrix (correct+first=+4, both correct=+3/+2, both wrong=-1) and 30s inactivity timeout
- DuelController + 6 routes: create/join/ready/state/answer/rematch verified working via curl smoke tests

## Task Commits

Each task was committed atomically:

1. **Task 1: DB migrations + Entity/Mapper** - `4570fb2` (feat)
2. **Task 2: DuelService + DuelController + routes** - `a261bfb` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified
- `app/lib/Migration/Version002400Date20260318000000.php` - Creates oc_learning_duel_sessions table
- `app/lib/Migration/Version002500Date20260318000000.php` - Creates oc_learning_duel_answers table
- `app/lib/Db/DuelSession.php` - Entity with typed properties for all session columns
- `app/lib/Db/DuelSessionMapper.php` - findByCode, findExpiredActive methods
- `app/lib/Db/DuelAnswer.php` - Entity for per-question answers
- `app/lib/Db/DuelAnswerMapper.php` - findByDuelAndQuestion, findByDuel methods
- `app/lib/Service/DuelService.php` - All business logic, scoring matrix, timeout detection
- `app/lib/Controller/DuelController.php` - 6 HTTP endpoints with rate limiting
- `app/appinfo/routes.php` - 6 duel routes added in "// Duels" section

## Decisions Made
- Scoring matrix exactly from CONTEXT.md: A correct + B wrong = A:+4, B:0 (steal bonus). Both correct A faster = A:+3, B:+2. Both wrong = -1 each. Tied (within 50ms) = +3 each.
- Timeout via `getState`: when polled, checks if other player's `last_poll` is >30s ago → marks expired, forfeit win (+100 score) for active player
- NC/Doctrine boolean constraint: boolean columns in migrations must use `notnull:false` (even if logically required) to avoid "Bool and NotNull, can not store false" error

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed wrong column names in learning_questions table**
- **Found during:** Task 2 (DuelService deployment test)
- **Issue:** DuelService used `type` (instead of `question_type`) and `question` (instead of `text`) as column names — caused SQL error "column type does not exist"
- **Fix:** Changed to `question_type` and `text` matching actual schema
- **Files modified:** app/lib/Service/DuelService.php
- **Verification:** POST /api/duels with real pool_id returns duel with question_ids array, GET state returns question text
- **Committed in:** a261bfb (Task 2 commit)

**2. [Rule 3 - Blocking] Fixed boolean+notnull migration constraint error**
- **Found during:** Task 1 (migration execution)
- **Issue:** NC/Doctrine threw "Column is type Bool and also NotNull, so it can not store false" for creator_ready, opponent_ready, answer_correct columns
- **Fix:** Changed notnull from true to false for all boolean columns in both migrations
- **Files modified:** app/lib/Migration/Version002400Date20260318000000.php, Version002500Date20260318000000.php
- **Verification:** occ app:enable runs cleanly, tables exist in PostgreSQL
- **Committed in:** 4570fb2 (Task 1 commit)

**3. [Rule 3 - Blocking] Fixed index name too long in migration**
- **Found during:** Task 1 (migration execution)
- **Issue:** NC migration threw "Index name too long" for learning_duel_sessions_code_idx
- **Fix:** Shortened to lduel_sess_code_uidx and lduel_ans_duel_q_idx
- **Files modified:** both migration files
- **Verification:** Migration runs without error
- **Committed in:** 4570fb2 (Task 1 commit)

---

**Total deviations:** 3 auto-fixed (1 Rule 1 bug, 2 Rule 3 blocking)
**Impact on plan:** All fixes were essential correctness issues. No scope creep.

## Issues Encountered
- user2 test user did not exist — created via `occ user:add user2` for smoke testing
- Pool ID 1 has no questions — used pool ID 9 for live testing

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Backend server is complete and verified: all 6 endpoints return correct JSON
- Scoring matrix verified: +4/0, +3/+2, +3/+3, -1/-1 all producing correct results
- 30s timeout detection in getState is implemented
- Ready for Phase 07-02: DuelMode.vue frontend component

---
*Phase: 07-live-duell*
*Completed: 2026-03-18*
