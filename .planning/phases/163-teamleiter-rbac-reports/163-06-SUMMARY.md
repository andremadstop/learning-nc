---
phase: 163-teamleiter-rbac-reports
plan: "06"
subsystem: rbac-reminder
tags: [wave-3, tdd-green, rbac-04, idor, mandatory-reminder, opt-out-bypass]
dependency_graph:
  requires:
    - "163-02: sendComplianceReminder skeleton + RED tests (ReminderServiceTest, NotifierTest)"
    - "163-01: CertificateReportService ctor wiring (ReminderService + AssignmentService injected)"
    - "163-05: assertTeamLeadForGroup real body; expandGroup real body"
  provides:
    - "ReminderService::sendComplianceReminder real dispatch (no opt-out gate)"
    - "Notifier::prepare compliance_reminder real l10n render"
    - "CertificateReportService::remindMember 2nd-IDOR guard + dispatch"
    - "CertificateReportController::remindMember POST endpoint"
    - "POST /api/courses/{courseId}/group-report/remind route"
    - "test-api.sh IDOR assertions for remind endpoint (3 checks)"
  affects: []
tech_stack:
  added: []
  patterns:
    - "mandatory-bypass: sendComplianceReminder calls sendNotification directly, no notificationsEnabled gate"
    - "2nd-IDOR-surface: assertTeamLeadForGroup THEN in_array(target, expandGroup) — two independent checks before dispatch"
    - "generic-403: ForbiddenException body never leaks membership detail (avoids oracle)"
    - "assert-first: both guards precede sendComplianceReminder call — dispatch unreachable on denial"
key_files:
  modified:
    - app/lib/Service/ReminderService.php
    - app/lib/Notification/Notifier.php
    - app/lib/Service/CertificateReportService.php
    - app/lib/Controller/CertificateReportController.php
    - app/appinfo/routes.php
    - scripts/test-api.sh
decisions:
  - "sendComplianceReminder delegates to private sendNotification; does NOT call notificationsEnabled() — mandatory, bypasses voluntary opt-out (locked decision 2)"
  - "remindMember asserts lead role FIRST (via assertTeamLeadForGroup), then target membership (in_array + expandGroup) — order is a security requirement, not just test convenience"
  - "ForbiddenException→403 uses single catch block with generic body ('No permission') — both denial reasons (not-lead, foreign-target) return identical response to avoid membership oracle"
  - "own-group-member 200 test deferred to manual Gate 2 (requires live oversight row provisioned for test user)"
metrics:
  duration_secs: 390
  tasks_completed: 3
  files_authored: 0
  files_modified: 6
  completed_date: "2026-07-02T11:17:53Z"
---

# Phase 163 Plan 06: RBAC-04 Mandatory Compliance Reminder — Backend Summary

RBAC-04 backend: mandatory compliance reminder with independent 2nd-IDOR guard on the
remind POST endpoint. Flips three Wave-0 RED tests GREEN; no constructor changes.

---

## One-liner

Mandatory compliance reminder bypasses opt-out (no notificationsEnabled gate); remind POST
independently re-validates lead role + target membership before dispatching via INotificationManager.

---

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | sendComplianceReminder real dispatch + Notifier compliance_reminder l10n render | b2028d9 | ReminderService.php, Notifier.php |
| 2 | remindMember 2nd-IDOR guard (assertTeamLeadForGroup + in_array) then dispatch | 3c375c1 | CertificateReportService.php |
| 3 | remindMember POST endpoint + route + test-api IDOR assertions | 45c7cbd | CertificateReportController.php, routes.php, test-api.sh |

---

## RED → GREEN Tests

| Test | Was | Now |
|------|-----|-----|
| `ReminderServiceTest::testSendComplianceReminderDispatches` | RED — skeleton returns false, notify() never called | GREEN — sendNotification delegated, notify() called once |
| `ReminderServiceTest::testComplianceReminderIgnoresNotificationOptOut` | RED — skeleton returns false; opt-out config has no effect on no-op | GREEN — no notificationsEnabled gate; dispatches even when config returns 'no' |
| `NotifierTest::testComplianceReminderCasePrepares` | RED (ERROR) — skeleton throws UnknownNotificationException before setParsedSubject | GREEN — real l10n render; setParsedSubject called exactly once, no exception |
| `CertificateReportServiceTest::testRemindMemberForeignTargetThrows` | RED — no-op returns void without throwing; sendComplianceReminder never() constraint satisfied trivially | GREEN — foreign target → ForbiddenException; sendComplianceReminder never() constraint satisfied by real guard |
| `ReminderServiceTest::testComplianceReminderEmailNullSafe` | GREEN (structural) | GREEN — unchanged; no IUserManager in ctor |
| `NotifierTest::testUnknownSubjectStillThrows` | GREEN (regression guard) | GREEN — default branch unaffected |

---

## Security Properties Proven

### sendComplianceReminder: mandatory, no opt-out gate
The body delegates directly to the private `sendNotification` helper. There is no
`if (!$this->notificationsEnabled($userId))` guard — the method is structurally incapable
of skipping dispatch based on the user's voluntary preference. This is locked by
`testComplianceReminderIgnoresNotificationOptOut` which sets all config values to `'no'`
and asserts `notify()` is still called.

### remindMember: assert-first invariant with two independent IDOR guards
```
assertTeamLeadForGroup(courseId, groupId, leadUserId)   ← GATE 1 (fail-closed on empty groupId)
expandGroup(groupId) → $members
in_array(targetUserId, $members)                        ← GATE 2 (independent of report GET)
sendComplianceReminder(target, course, params)           ← only reachable after both pass
```
The test locks `sendComplianceReminder` with `expects($this->never())` on the denial path.
A team lead cannot use the remind endpoint to probe membership of their own group (GATE 2)
or of a foreign group (GATE 1).

### Generic 403 body
Single `catch (ForbiddenException)` returns `['error' => 'No permission']` for all three
denial conditions (not-lead, empty-groupId, foreign-target). No branching — no oracle.

---

## Deferred Items

- **own-group-member → 200** test in test-api.sh: requires a provisioned oversight row for
  the admin test user. Deferred to manual Gate 2 when `RoleService::getTeamLeadGroups`
  returns a real entry for the test setup.
- **PHP gates (PHPStan L5, PHPUnit)**: no local PHP binary. All PHP verification deferred
  to the orchestrator's central Gate 1 + Gate 2.

```
DEFERRED: ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app \
  php vendor/bin/phpstan analyse --no-progress'
DEFERRED: ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app \
  php vendor/bin/phpunit --filter "ReminderServiceTest|NotifierTest|CertificateReportServiceTest"'
```

Expected PHPUnit result after 163-06:
- ReminderServiceTest: 3 PASS (dispatches GREEN, opt-out-bypass GREEN, email-null-safe GREEN)
- NotifierTest: 2 PASS (compliance_reminder GREEN, unknown-subject GREEN)
- CertificateReportServiceTest::testRemindMemberForeignTargetThrows: GREEN

---

## Deviations from Plan

None — plan executed exactly as written. No constructor changes. No new dependencies.
No scope drift.

---

## Self-Check

- [x] app/lib/Service/ReminderService.php — FOUND (sendComplianceReminder delegates to sendNotification, no notificationsEnabled call)
- [x] app/lib/Notification/Notifier.php — FOUND (compliance_reminder case renders l10n, no throw)
- [x] app/lib/Service/CertificateReportService.php — FOUND (remindMember has assertTeamLeadForGroup + in_array + sendComplianceReminder)
- [x] app/lib/Controller/CertificateReportController.php — FOUND (remindMember POST method, 3 references)
- [x] app/appinfo/routes.php — FOUND (group-report/remind POST route)
- [x] scripts/test-api.sh — FOUND (RBAC-04 IDOR assertion block, 4 matches)
- [x] Commit b2028d9 — FOUND (Task 1)
- [x] Commit 3c375c1 — FOUND (Task 2)
- [x] Commit 45c7cbd — FOUND (Task 3)
- [x] No UnknownNotificationException throw for compliance_reminder — verified (grep count: 0)
- [x] sendComplianceReminder does NOT contain notificationsEnabled — verified (all 5 occurrences are in other methods)

## Self-Check: PASSED
