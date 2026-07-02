---
phase: 163-teamleiter-rbac-reports
plan: 03
subsystem: rbac
tags: [rbac, oversight, roleservice, qbmapper, tdd-green]

requires:
  - phase: 163-01
    provides: "OversightMapper/Oversight skeleton + RoleServiceTest RED tests + RoleService constructor (5 params)"

provides:
  - "OversightMapper real query bodies: findByLead + existsForLeadGroupCourse"
  - "RoleService real isTeamLeadForGroup + getTeamLeadGroups — exact-triple RBAC-03 authorization"
  - "RoleServiceTest fully GREEN (all 6 tests)"

affects: [163-05, 163-06]

tech-stack:
  added: []
  patterns:
    - "existsForLeadGroupCourse: SELECT id WHERE exact triple + PARAM_INT on course_id + setMaxResults(1) + executeQuery/fetch/closeCursor"
    - "findByLead: SELECT * WHERE lead_user_id + findEntities() QBMapper helper"
    - "getTeamLeadGroups: array_map(Oversight→{course_id,group_id}) + array_values for list semantics"

key-files:
  modified:
    - app/lib/Db/OversightMapper.php
    - app/lib/Service/RoleService.php

key-decisions:
  - "Oversight entity imported in RoleService for PHPStan-L5 closure type hint (fn(Oversight $o): array)"
  - "IQueryBuilder imported in OversightMapper for PARAM_INT constant on course_id binding"
  - "No constructor/signature change — plan constraint honored; only method bodies replaced"

duration: ~5min
completed: 2026-07-02
---

# Phase 163 Plan 03: Wave 1 RBAC-03 Oversight Read Path Summary

**OversightMapper + RoleService real query bodies implemented; RoleServiceTest fully GREEN**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-07-02T09:06:58Z
- **Completed:** 2026-07-02T09:12:00Z
- **Tasks:** 2
- **Files modified:** 2 (lib only — no test changes)

## Accomplishments

- `OversightMapper::findByLead(string $leadUserId): array` — SELECT * WHERE lead_user_id = :lead; returns `findEntities($qb)`
- `OversightMapper::existsForLeadGroupCourse(int $courseId, string $leadUserId, string $groupId): bool` — SELECT id WHERE exact (course_id PARAM_INT, lead_user_id, scope_group_id) triple; setMaxResults(1); executeQuery→fetch→closeCursor; returns `$row !== false`
- `RoleService::isTeamLeadForGroup()` — single-line delegation to `oversightMapper->existsForLeadGroupCourse()`
- `RoleService::getTeamLeadGroups()` — `array_values(array_map(fn(Oversight $o) => [...], findByLead($leadUserId)))`

## Task Commits

1. **Task 1: OversightMapper real query bodies** — `5a53c66` (feat)
2. **Task 2: RoleService isTeamLeadForGroup + getTeamLeadGroups** — `d5a8dcc` (feat)

## RED Tests Now GREEN (after central Gate 1 deploy)

### RoleServiceTest — all 6 tests GREEN

| Test | Was | Now |
|------|-----|-----|
| `testIsTeamLeadForGroupTrueWhenRowExists` | RED (skeleton returned false) | GREEN (delegates to mapper mock returning true) |
| `testIsTeamLeadForGroupFalseForForeignGroup` | GREEN | GREEN (unchanged semantics) |
| `testIsTeamLeadForGroupFalseForWrongCourse` | GREEN | GREEN (unchanged semantics) |
| `testGetTeamLeadGroupsMapsOversightRows` | RED (skeleton returned []) | GREEN (maps 2 Oversight rows to structs) |
| `testGetTeamLeadGroupsReturnsEmptyWhenNoRows` | GREEN | GREEN (unchanged semantics) |
| `testGetTeamLeadGroupsIsScopedToLead` | GREEN | GREEN (lead2 still returns []) |

## Deferred Verifications (PHP gate — no local PHP binary)

Per PROJECT_SPECIFIC_OVERRIDES, the following are deferred to orchestrator central Gate 1:

- `ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpstan analyse --no-progress'` — PHPStan L5 on OversightMapper.php + RoleService.php
- `ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpunit --filter RoleServiceTest'` — confirm all 6 tests GREEN

## Deviations from Plan

None — plan executed exactly as written. Both task bodies implement the exact signatures described in the plan's `<action>` blocks. No constructor changes, no signature changes, no scope creep.

## Files Modified

- `app/lib/Db/OversightMapper.php` — findByLead + existsForLeadGroupCourse real bodies; added `use OCP\DB\QueryBuilder\IQueryBuilder`
- `app/lib/Service/RoleService.php` — isTeamLeadForGroup + getTeamLeadGroups real bodies; added `use OCA\Learning\Db\Oversight` for PHPStan closure type hint

## Next Phase Readiness

- 163-05 (RBAC-02: CertificateReportService::getGroupReport + assertTeamLeadForGroup) can now rely on real `isTeamLeadForGroup()` — the `existsForLeadGroupCourse` path is wired
- 163-06 (Team-lead dashboard endpoint) also relies on this authorization backing

---
*Phase: 163-teamleiter-rbac-reports*
*Completed: 2026-07-02*
