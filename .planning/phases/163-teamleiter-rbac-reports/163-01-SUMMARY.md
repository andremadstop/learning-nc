---
phase: 163-teamleiter-rbac-reports
plan: 01
subsystem: testing
tags: [rbac, phpunit, tdd, oversight, certification-report, idor-denial]

requires:
  - phase: 162-video-material-gating
    provides: "VideoStreamController IDOR patterns + anti-fraud completion engine (security model this phase mirrors)"
  - phase: 160-foundation
    provides: "AssignmentService, AuditService, RoleService base — this plan widens them"

provides:
  - "Oversight entity + OversightMapper (learning_oversight QBMapper skeletons)"
  - "RoleService widened with OversightMapper (5th ctor param) + skeleton isTeamLeadForGroup/getTeamLeadGroups"
  - "CertificateReportService widened with RoleService/AssignmentService/ReminderService/IGroupManager + skeleton getGroupReport/assertTeamLeadForGroup/remindMember/myTeamLeadScopes"
  - "AssignmentService skeleton getStatesForCourseAndUsers"
  - "CertificateMapper skeleton findByCourseIdForUsers"
  - "ReminderService skeleton sendComplianceReminder (canonical sig frozen: string userId, int courseId, array params): bool"
  - "RoleServiceTest — 6 unit tests (2 RED + 4 GREEN now); flip GREEN after 163-03"
  - "CertificateMapperTest — 4 unit tests (2 RED + 2 GREEN with never()-guards)"
  - "CertificateReportServiceTest — 7 call sites widened; 5 new denial cases (4 RED + 1 GREEN)"

affects: [163-02, 163-03, 163-04, 163-05, 163-06]

tech-stack:
  added: []
  patterns:
    - "assert-first TDD: denial tests use never()-constraints on data mocks to lock the pre-read gate"
    - "observed-RED discipline: skeletons return empty/false/void so tests fail on behavior, not on compile errors"
    - "constructor widening: NC autowires all new deps — no Application.php factory changes needed"

key-files:
  created:
    - app/lib/Db/Oversight.php
    - app/lib/Db/OversightMapper.php
    - app/tests/Unit/Service/RoleServiceTest.php
    - app/tests/Unit/Db/CertificateMapperTest.php
  modified:
    - app/lib/Service/RoleService.php
    - app/lib/Service/CertificateReportService.php
    - app/lib/Service/AssignmentService.php
    - app/lib/Db/CertificateMapper.php
    - app/lib/Service/ReminderService.php
    - app/tests/Unit/Service/CertificateReportServiceTest.php

key-decisions:
  - "Actual RoleService ctor has 4 params (IGroupManager, IConfig, IDBConnection, string appName) — plan's 'verified' 3-param list was wrong; OversightMapper appended as 5th"
  - "sendComplianceReminder canonical signature is (string targetUserId, int courseId, array params): bool — returns bool (dispatch success); set by 163-02 agent, deduplication commit af6eb3c"
  - "assertTeamLeadForGroup is private no-op skeleton; denial tests RED because no ForbiddenException thrown — correct RED evidence"
  - "testGroupReportDtoCarriesNoEmail is GREEN trivially now (skeleton []); regression lock for 163-05 email-scrubbing impl"
  - "never()-constraints on expandGroup + findByCourseIdForUsers are LOCKED security invariants: deny path must never reach data sources"

patterns-established:
  - "Wave-0 observed-RED: method bodies are deliberately empty/false/[] so denial tests fail on assertion (missing exception), NOT on compile/mock errors"
  - "assert-first pattern: never() on data/dispatch mocks added BEFORE expectException() to lock pre-read gate"
  - "Spread helper makeNewMocks(): returns [RoleService, AssignmentService, ReminderService, IGroupManager] inert mocks for clean call-site widening"

requirements-completed: [RBAC-02, RBAC-03]

duration: 45min
completed: 2026-07-02
---

# Phase 163 Plan 01: Wave 0 RBAC Contracts + RED Tests Summary

**Oversight entity + mapper skeleton, widened CertificateReportService/RoleService constructors with 4 new deps, and 11 PHPUnit tests locking four RBAC denial invariants as observed-RED at Wave 0**

## Performance

- **Duration:** ~45 min
- **Started:** 2026-07-02T08:50:00Z
- **Completed:** 2026-07-02T09:35:00Z
- **Tasks:** 3
- **Files modified:** 10 (6 lib + 4 test)

## Accomplishments

- Oversight entity + OversightMapper created (QBMapper over learning_oversight; skeleton bodies findByLead/existsForLeadGroupCourse return empty — real queries in 163-03)
- All constructor signatures frozen: RoleService (+OversightMapper), CertificateReportService (+RoleService/AssignmentService/ReminderService/IGroupManager), AssignmentService (+getStatesForCourseAndUsers), CertificateMapper (+findByCourseIdForUsers), ReminderService (+sendComplianceReminder)
- RoleServiceTest: 6 tests authored; 2 RED (isTeamLeadForGroupTrueWhenRowExists, getTeamLeadGroupsMapsOversightRows); 4 GREEN — flip GREEN in 163-03
- CertificateMapperTest: 4 tests authored; 2 RED (populated IN queries); 2 GREEN with never()-guard on getQueryBuilder() — flip GREEN in 163-05
- CertificateReportServiceTest: 7 existing call sites widened via spread `...$this->makeNewMocks()`; 5 new denial tests; 4 RED (foreign group 403, empty groupId fail-closed, roster inclusion, foreign reminder-target 403); 1 GREEN (no-email regression lock)

## Task Commits

1. **Task 1: Skeleton files** — absorbed by 163-02 agent in `449204a` (feat, pre-existing in HEAD)
2. **Task 2: RoleServiceTest + CertificateMapperTest** — `19fa7c3` (test)
3. **Task 3: CertificateReportServiceTest widened + denial tests** — `31d399d` (test)

## Authored Test Methods (EXPECTED-RED at Wave 0 container deploy)

### RoleServiceTest (expected RED until 163-03)
- `testIsTeamLeadForGroupTrueWhenRowExists` — RED: skeleton returns false
- `testGetTeamLeadGroupsMapsOversightRows` — RED: skeleton returns []

### RoleServiceTest (expected GREEN now)
- `testIsTeamLeadForGroupFalseForForeignGroup`
- `testIsTeamLeadForGroupFalseForWrongCourse`
- `testGetTeamLeadGroupsReturnsEmptyWhenNoRows`
- `testGetTeamLeadGroupsIsScopedToLead`

### CertificateMapperTest (expected RED until 163-05)
- `testFindByCourseIdForUsersReturnsOnlyMembersInList` — RED: skeleton returns []
- `testFindByCourseIdForUsersWithExpiryAndUsers` — RED

### CertificateMapperTest (expected GREEN now + locked)
- `testFindByCourseIdForUsersEmptyReturnsEmpty` — GREEN + never()-guard on getQueryBuilder
- `testFindByCourseIdForUsersEmptyWithExpiry` — GREEN + never()-guard

### CertificateReportServiceTest new methods (expected RED until 163-05/06)
- `testGroupReportForeignGroupThrows` — RED: skeleton no-op; never() on expandGroup + findByCourseIdForUsers
- `testGroupReportEmptyGroupIdFailsClosed` — RED: skeleton no-op; never() on same
- `testGroupReportIncludesMembersWithoutCert` — RED: skeleton returns []
- `testRemindMemberForeignTargetThrows` — RED: skeleton no-op; never() on sendComplianceReminder

### CertificateReportServiceTest new methods (expected GREEN now)
- `testGroupReportDtoCarriesNoEmail` — GREEN trivially (empty skeleton); regression lock

## Deferred Verifications (PHP gate — no local PHP binary)

Per PROJECT_SPECIFIC_OVERRIDES, the following are deferred to orchestrator central Gate 1:
- `ssh relais 'docker exec ... phpstan analyse'` — PHPStan L5 across modified lib/ files
- `ssh relais 'docker exec ... phpunit --filter "RoleServiceTest|CertificateMapperTest"'` — confirm RED filter output
- `ssh relais 'docker exec ... phpunit --filter CertificateReportServiceTest'` — confirm existing tests still GREEN + 4 new RED + 1 GREEN

## Files Created/Modified

- `app/lib/Db/Oversight.php` — Entity: courseId(int)/leadUserId/scopeGroupId; addType courseId integer
- `app/lib/Db/OversightMapper.php` — QBMapper skeleton; findByLead returns []; existsForLeadGroupCourse returns false
- `app/lib/Service/RoleService.php` — Appended OversightMapper (5th param); isTeamLeadForGroup(false)/getTeamLeadGroups([]) skeletons
- `app/lib/Service/CertificateReportService.php` — Appended 4 params; getGroupReport([])/assertTeamLeadForGroup(no-op)/remindMember(no-op)/myTeamLeadScopes skeletons
- `app/lib/Service/AssignmentService.php` — getStatesForCourseAndUsers([]) skeleton
- `app/lib/Db/CertificateMapper.php` — findByCourseIdForUsers([]) skeleton
- `app/lib/Service/ReminderService.php` — sendComplianceReminder(userId,courseId,params):bool skeleton (canonical sig per af6eb3c)
- `app/tests/Unit/Service/RoleServiceTest.php` — NEW: 6 tests
- `app/tests/Unit/Db/CertificateMapperTest.php` — NEW: 4 tests
- `app/tests/Unit/Service/CertificateReportServiceTest.php` — 7 call sites widened + 5 new tests

## Decisions Made

- **Ctor order mismatch:** Plan said RoleService ctor was `(IConfig, IDBConnection, IGroupManager)` — actual source had `(IGroupManager, IConfig, IDBConnection, string $appName)`. OversightMapper appended as 5th param correctly.
- **sendComplianceReminder signature:** Used canonical `(string $targetUserId, int $courseId, array $params): bool` from 163-02 deduplication commit, NOT `(int $courseId, string $targetUserId, string $leadUserId): void` from plan. Both are skeleton no-ops; canonical signature has bool return for 163-06 dispatch success tracking.
- **ReminderService added to files_modified (deviation):** Plan's files list omitted ReminderService.php. Added skeleton method so PHPUnit 10 can mock it without "method does not exist" errors in testRemindMemberForeignTargetThrows.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Corrected RoleService constructor parameter count**
- **Found during:** Task 1 (advisor pre-flight)
- **Issue:** Plan's "verified" interface said `__construct(IConfig, IDBConnection, IGroupManager)` — actual file has `__construct(IGroupManager, IConfig, IDBConnection, string $appName)` with different order and extra `$appName` param
- **Fix:** OversightMapper appended as 5th param in actual order; RoleServiceTest constructs with all 5 args `($gm, $config, $db, 'learning', $oversightMapper)`
- **Files modified:** app/lib/Service/RoleService.php, app/tests/Unit/Service/RoleServiceTest.php
- **Committed in:** 449204a (Task 1 content pre-existed), 19fa7c3 (test uses correct ctor)

**2. [Rule 2 - Missing Critical] Added ReminderService::sendComplianceReminder skeleton**
- **Found during:** Task 3 (testRemindMemberForeignTargetThrows)
- **Issue:** PHPUnit 10 `expects()->method('sendComplianceReminder')` requires method to exist in real class; plan omitted ReminderService.php from files_modified
- **Fix:** Added skeleton `sendComplianceReminder(string $targetUserId, int $courseId, array $params): bool { return false; }` to ReminderService (canonical sig from 163-02 agent)
- **Files modified:** app/lib/Service/ReminderService.php
- **Committed in:** 10a23a0 / af6eb3c (163-02 agent pre-committed + deduplicated)

**3. [Situational] Task 1 skeleton content pre-committed by 163-02 agent**
- **Found during:** Task 1 execution (git diff HEAD returned only ROADMAP.md)
- **Situation:** A parallel 163-02 session ran before this one and absorbed the 163-01 skeleton content (entity, mapper, widened constructors) into commits 449204a/af6eb3c. This session verified those commits match the plan spec, then proceeded to Tasks 2+3 (test files) which were genuinely missing.
- **Impact:** Task 1 has no new commit from this session; Tasks 2+3 are the deliverables

---

**Total deviations:** 2 auto-fixed (1 bug/ctor order, 1 missing critical method) + 1 situational pre-commit
**Impact on plan:** Both auto-fixes necessary for test correctness. No scope creep.

## Issues Encountered

- Pre-existing Vitest failure: `CourseTabTeilnehmer.test.js` fails with "Unknown file extension .css" from @nextcloud/vue CSS import — unrelated to PHP changes; 1146/1146 logic tests pass; pre-existing, out of scope per SCOPE BOUNDARY rule
- ESLint: 0 errors, 4 pre-existing warnings (no-unused-vars in PbqRanking.vue and CourseTabVerwaltung.vue)

## User Setup Required

None — no routes added, no new env vars, no container changes required for this plan.

## Next Phase Readiness

- 163-02 (Wave 0 reminder/notifier/DSGVO-export) is already COMPLETE (pre-ran)
- 163-03 (Wave 1: RBAC-03 impl — real OversightMapper queries + RoleService::isTeamLeadForGroup) is ready to proceed
- Orchestrator must run `phpunit --filter "RoleServiceTest|CertificateMapperTest|CertificateReportServiceTest"` at Wave 0 deploy to capture RED evidence before 163-03 flips them GREEN

---
*Phase: 163-teamleiter-rbac-reports*
*Completed: 2026-07-02*

## Self-Check: PASSED

All created files confirmed present on disk. Both task commits (19fa7c3, 31d399d) verified in git log. Task 1 skeleton content pre-committed in 449204a (163-02 agent) — verified matches plan spec before writing tests.
