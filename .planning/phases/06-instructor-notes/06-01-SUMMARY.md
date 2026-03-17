---
phase: 06-instructor-notes
plan: 01
subsystem: database
tags: [postgresql, migration, php, nextcloud, entity, service, controller, vitest]

# Dependency graph
requires:
  - phase: 05-pbq-author-tool
    provides: QuestionForm PBQ integration, full question stack through controller/service/entity
provides:
  - instructor_note (TEXT, nullable) and note_visible (BOOLEAN, default false) columns in oc_learning_questions
  - Question entity with getInstructorNote/setInstructorNote/getNoteVisible/setNoteVisible + jsonSerialize keys
  - QuestionService.create()/update() accept and persist instructorNote/noteVisible params
  - QuestionController.create()/update() accept and thread params to service
  - Migration Version002300 with hasColumn idempotency guards, deployed on learning-dev
  - 5 unit tests for shouldShowNote(noteVisible, instructorNote) visibility logic
affects: [06-02-frontend, QuestionForm, study-mode-display, exam-mode]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - hasColumn idempotency guard pattern in SimpleMigrationStep (established in 002200, continued here)
    - note_visible always set (no null guard); instructorNote can be null (no null guard needed)
    - note_visible defaults to false via ?? false in jsonSerialize (null leak prevention)

key-files:
  created:
    - app/lib/Migration/Version002300Date20260317000000.php
    - app/tests/unit/instructorNote.test.js
  modified:
    - app/lib/Db/Question.php
    - app/lib/Service/QuestionService.php
    - app/lib/Controller/QuestionController.php

key-decisions:
  - "instructor_note is NOT stripped during active exam (unlike explanation) — visibility is frontend-gated via note_visible flag only"
  - "noteVisible always set in create/update (no null guard) — ensures clean bool false default, never NULL"
  - "shouldShowNote logic: !!(noteVisible && instructorNote) — both must be truthy (non-empty, non-null)"
  - "appinfo/info.xml must be present in container to trigger migrations via occ app:enable"

patterns-established:
  - "Pattern: note_visible in jsonSerialize uses ?? false null guard to prevent null leaking to frontend"
  - "Pattern: deploy appinfo + occ app:enable for migration trigger on this NC setup"

requirements-completed: [NOTE-01, NOTE-04]

# Metrics
duration: 10min
completed: 2026-03-17
---

# Phase 06 Plan 01: Instructor Notes — Backend Data Layer Summary

**PostgreSQL migration adding instructor_note (TEXT) + note_visible (BOOLEAN) threaded through NC Entity/Service/Controller, with 5 vitest unit tests for visibility logic**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-03-17T08:26:06Z
- **Completed:** 2026-03-17T08:36:30Z
- **Tasks:** 3 completed
- **Files modified:** 5

## Accomplishments

- DB migration Version002300 deployed on learning-dev — 5430 existing questions preserved, columns confirmed
- Full PHP stack: Question entity (properties + addType + jsonSerialize + getters/setters), Service (create+update), Controller (create+update)
- API GET /pools/{id}/questions verified to return `instructor_note` and `note_visible` per question object
- 67 unit tests pass (all suites green), including 5 new instructorNote tests

## Task Commits

Each task was committed atomically:

1. **Task 1: Wave 0 — instructorNote unit test scaffold** - `8cd2a06` (test)
2. **Task 2: DB Migration + Entity extension** - `0362cff` (feat)
3. **Task 3: Service + Controller param extension + deploy** - `eb94dda` (feat)

**Plan metadata:** (docs commit — see below)

## Files Created/Modified

- `app/tests/unit/instructorNote.test.js` - 5 vitest tests covering shouldShowNote() visibility logic
- `app/lib/Migration/Version002300Date20260317000000.php` - Migration adding instructor_note + note_visible with hasColumn idempotency guards
- `app/lib/Db/Question.php` - Added instructorNote/noteVisible properties, addType calls, jsonSerialize entries, 4 getter/setter methods
- `app/lib/Service/QuestionService.php` - Extended create() + update() signatures with instructorNote/noteVisible params
- `app/lib/Controller/QuestionController.php` - Extended create() + update() to accept and forward params to service

## Decisions Made

- `instructor_note` is NOT stripped during active exam sessions (unlike `explanation`) — visibility is exclusively frontend-gated via the `note_visible` boolean flag. No server-side exam-mode suppression needed.
- `noteVisible` is always set in create/update without a null guard to ensure it's never NULL in DB (always false by default).
- `appinfo/info.xml` must exist in the container for `occ app:enable` to trigger migrations — was empty on learning-dev; copied from local repo before enabling.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Deployed appinfo/info.xml to container before running migration**
- **Found during:** Task 3 (deploy step)
- **Issue:** `/var/www/html/custom_apps/learning/appinfo/` was empty in container. `occ app:enable` failed with "appinfo file cannot be read", blocking the migration.
- **Fix:** Copied `app/appinfo/info.xml` and `routes.php` via SCP to learning-dev /tmp, then `docker cp` into container appinfo dir. Then `occ app:enable learning` succeeded and triggered migration 002300.
- **Files modified:** Container-only (not committed — info.xml already tracked in git repo)
- **Verification:** Migration 002300 appears in `oc_migrations` table, columns confirmed in `\d oc_learning_questions`
- **Committed in:** Part of Task 3 commit `eb94dda`

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Required to complete the deploy step. No scope creep.

## Issues Encountered

- `scp` to learning-dev migration dir failed (files owned by root). Workaround: `scp` to `/tmp`, then `sudo cp` on learning-dev. This is the established pattern for this environment.
- `occ upgrade` reported "already latest version" instead of running migrations. Root cause: appinfo empty. Fixed by populating appinfo and using `occ app:enable` instead.

## User Setup Required

None - migration ran automatically on learning-dev via `occ app:enable`.

## Next Phase Readiness

- Data layer complete: both columns exist in DB, API returns them, PHP stack persists them
- Plan 02 (Frontend) can proceed: QuestionForm needs instructor note textarea + visibility toggle, study modes need conditional note display using `note_visible` flag
- `shouldShowNote(noteVisible, instructorNote)` logic is tested and ready for extraction to `src/utils/instructorNoteUtils.js`

---
*Phase: 06-instructor-notes*
*Completed: 2026-03-17*

## Self-Check: PASSED

All files present and commits verified:
- `8cd2a06` — test(06-01): instructorNote unit test scaffold
- `0362cff` — feat(06-01): DB migration + Question entity
- `eb94dda` — feat(06-01): Service + Controller + deploy
