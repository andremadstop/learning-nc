---
phase: 160-foundation-audit-assignment
plan: "04"
subsystem: database
tags: [migration, phpunit, tdd, postgresql, mariadb, assignments, oversight, schema]

requires:
  - phase: 160-02
    provides: ComplianceEventTypes class (ComplianceEventTypesTest passes once it lands)

provides:
  - Version009400 migration: learning_assignments table (10 columns, PLAIN composite index, nullable UNIQUE active_period_key)
  - Version009400 migration: learning_oversight table (scope_group_id VARCHAR(64))
  - AssignmentServiceTest: 4 RED stubs for AssignmentService (160-05 target)
  - ImportUsersCommandTest: 2 RED stubs for ImportUsersCommand (160-05/06 target)
  - ImportUsersJobTest: 2 RED stubs for ImportUsersJob (160-05/06 target)
  - AssignmentMigrationTest: schema assertion tests (skipped outside container; green after 009400 applies)
  - ComplianceEventTypesTest: constants test (green after 160-02 deploys)

affects:
  - 160-05 (AssignmentService implementation — tests stubs define the contract)
  - 160-06 (ImportUsersCommand/Job implementation — test stubs define contract)
  - 161 (Audit hardening — 009400 co-deploys with 009300 via occ upgrade)
  - 163 (Teamleiter-RBAC queries learning_assignments + learning_oversight)
  - 164 (Re-certification writes new learning_assignments rows, relies on PLAIN composite index)

tech-stack:
  added: []
  patterns:
    - "Nullable UNIQUE via addUniqueIndex on active_period_key — mirrors Version009100 active_idem_key pattern; multiple NULLs coexist on PG16 + MariaDB (ANSI SQL)"
    - "PLAIN addIndex for composite (course_id, subject_type, subject_id) — re-cert history rows require non-unique"
    - "TDD RED stubs: test files reference not-yet-existing classes; skipped/fail cleanly until implementation lands"
    - "Migration guard: hasTable() before createTable() — idempotent on repeat occ upgrade"
    - "IGroupManager::get() (NC 33) not getGroup() — verified against lib/public/IGroupManager.php:58"

key-files:
  created:
    - app/lib/Migration/Version009400Date20260701120000.php
    - app/tests/Unit/Service/AssignmentServiceTest.php
    - app/tests/Unit/Service/ComplianceEventTypesTest.php
    - app/tests/Unit/Command/ImportUsersCommandTest.php
    - app/tests/Unit/Command/ImportUsersJobTest.php
    - app/tests/Unit/Migration/AssignmentMigrationTest.php
  modified: []

key-decisions:
  - "learning_oversight uses scope_group_id VARCHAR(64) — NOT nc_group_id VARCHAR(255) from ARCHITECTURE.md (ARCHITECTURE.md is wrong)"
  - "learning_assignments composite index is PLAIN addIndex, NOT addUniqueIndex — re-certification creates second row per (course, subject)"
  - "Version009400 has no constructor — no postSchemaChange data seeding needed (assignment tables start empty)"
  - "IGroupManager::get() used in test stubs (NC 33 API) — not getGroup()"
  - "ImportUsersJob uses 4-arg constructor incl. ITimeFactory (NC 33 QueuedJob pattern)"

patterns-established:
  - "New test directories tests/Unit/Command/ and tests/Unit/Migration/ established for Phase 160+ tests"

requirements-completed: [ASSIGN-01, RBAC-01]

duration: 15min
completed: 2026-07-01
---

# Phase 160 Plan 04: Track B — Assignment Migration + Test Stubs Summary

**Version009400 migration creates learning_assignments (PLAIN composite, nullable-UNIQUE active_period_key) and learning_oversight (scope_group_id VARCHAR(64)); 5 RED test stubs define contracts for AssignmentService and ImportUsers{Command,Job}**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-01T~12:00Z
- **Completed:** 2026-07-01T~12:15Z
- **Tasks:** 2/2
- **Files modified:** 6 created, 0 modified

## Accomplishments

- Version009400 migration file written with correct PLAIN composite index and nullable UNIQUE active_period_key — matches exactly the proven Version009100 active_idem_key pattern
- learning_oversight table uses scope_group_id VARCHAR(64) (not ARCHITECTURE.md's erroneous VARCHAR(255))
- 5 RED test stub files created covering AssignmentService, ImportUsersCommand, ImportUsersJob, migration schema assertions, and ComplianceEventTypes constants
- New test directories `tests/Unit/Command/` and `tests/Unit/Migration/` established

## Task Commits

1. **Task 1: Track B test stubs (RED phase)** — `74058c9` (test)
2. **Task 2: Version009400 migration** — `baade91` (feat)

## Files Created/Modified

- `app/lib/Migration/Version009400Date20260701120000.php` — learning_assignments + learning_oversight DDL
- `app/tests/Unit/Service/AssignmentServiceTest.php` — 4 RED stubs (expandGroup, periodKeyFormat, extendDeadline, noCertGate)
- `app/tests/Unit/Service/ComplianceEventTypesTest.php` — constants test for Track A ComplianceEventTypes
- `app/tests/Unit/Command/ImportUsersCommandTest.php` — 2 RED stubs (largeCsv→job, smallCsv→sync)
- `app/tests/Unit/Command/ImportUsersJobTest.php` — 2 RED stubs (createsUserPerRow, passwordsNotInJobArgs)
- `app/tests/Unit/Migration/AssignmentMigrationTest.php` — schema assertions (skipped outside NC container)

## Decisions Made

- ARCHITECTURE.md's `learning_team_leads` table and `nc_group_id VARCHAR(255)` NOT built — plan explicitly supersedes it with `learning_oversight` + `scope_group_id VARCHAR(64)`
- No constructor on Version009400 — no genesis seeding needed (that pattern is only for Version009300's chain_state)
- IGroupManager::get() in test stubs (NC 33) — plan overrides RESEARCH.md's `getGroup()` reference, citing direct source verification

## Deviations from Plan

None — plan executed exactly as written. All 6 files are direct transcriptions from the plan's `<action>` blocks.

## Issues Encountered

- No local PHP binary available on the CachyOS workstation — `php -l` syntax check could not be executed locally. Files are direct transcriptions from the plan's verbatim code blocks; no custom logic was added. Container syntax validation deferred to orchestrator Gate-1.

## Container Verification Status

**Deferred to orchestrator central Gate-1.**

The following verifications require the devcloud container and will be run by the orchestrator after the wave:

- PHPStan L5 on `Version009400Date20260701120000.php`
- `occ upgrade` applying Version009400 cleanly
- AssignmentMigrationTest schema assertions (table exists, composite index is PLAIN, active_period_key is UNIQUE, scope_group_id length 64)
- PHPUnit RED confirmation on AssignmentServiceTest, ImportUsersCommandTest, ImportUsersJobTest (expect "class not found")
- ComplianceEventTypesTest passes after Track A (160-02) deploys ComplianceEventTypes

## Next Phase Readiness

- 160-05 (AssignmentService implementation): test stubs define the full constructor signature `(IDBConnection, IGroupManager, AuditService)` and all 4 method contracts
- 160-06 (ImportUsersCommand/Job): test stubs define the 4-arg constructor pattern and CSV dispatch threshold (>50 rows → job)
- Version009400 schema is the prerequisite for all Phase 163 (teamleiter queries) and Phase 164 (re-cert writes) work

---
*Phase: 160-foundation-audit-assignment*
*Completed: 2026-07-01*
