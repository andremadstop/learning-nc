# Architecture Research

**Domain:** Compliance training features integrated into an existing native Nextcloud app (PHP 8.1+, Vue 3.5, PG16 + MariaDB 11.4)
**Researched:** 2026-07-01
**Confidence:** HIGH (based on direct codebase read — real class names, real migration patterns)

---

## Context: This Is an Extension, Not a New System

The four v5.2.0 feature blocks extend an existing, live application. Every pattern used below mirrors what already exists in `app/lib/`. A rewrite or new subsystem is not warranted.

---

## New vs Modified Components — Explicit List

### Block 1: Video-/Material-Gating

| Component | New / Modified | Notes |
|-----------|---------------|-------|
| `learning_course_videos` table | **NEW** | per-course video registry with duration |
| `learning_watch_progress` table | **NEW** | per-user completion state, UNIQUE upsert |
| `learning_courses.video_gate_enabled` column | **NEW column on existing table** | boolean gate flag |
| `WatchProgressService` | **NEW** | heartbeat accept + completion logic + gate query |
| `VideoController` | **NEW** | `POST /api/video/heartbeat`, `POST /api/video/complete` |
| `VideoPlayer.vue` | **NEW** | wrapper component for all 3 source types |
| `NcMp4Player.vue` | **NEW** | direct NC file URL, range-request-aware |
| `VimeoPlayer.vue` | **NEW** | iframe + postMessage listener |
| `YouTubePlayer.vue` | **NEW** | iframe + postMessage (YT iframe API) |
| `TrainingService::startSession()` | **MODIFIED** | video gate check before session creation |

### Block 2: Teamleiter-RBAC-Reports

| Component | New / Modified | Notes |
|-----------|---------------|-------|
| `learning_team_leads` table | **NEW** | maps (course_id, lead_user_id, nc_group_id) |
| `RoleService::isTeamLead()` | **NEW method** | queries learning_team_leads |
| `RoleService::getLeadGroups()` | **NEW method** | returns groups a lead manages per course |
| `CertificateReportService::getGroupReport()` | **NEW method** | team-scoped variant with group-member filter |
| `CertificateReportController` | **MODIFIED** | new endpoint/param for team-lead path |
| `TeamLeadSettingsController` | **NEW** (or folded into CourseController) | CRUD for lead→group assignments |

### Block 3: Re-Zertifizierung

| Component | New / Modified | Notes |
|-----------|---------------|-------|
| `learning_courses.recert_enabled` column | **NEW column** | enables annual re-cert cycle |
| `learning_courses.recert_interval_days` column | **NEW column** | default 365 |
| `ReminderService::sendCertExpiryReminders()` | **NEW method** | mirrors sendExamReminders() pattern |
| `SendRemindersJob::run()` | **MODIFIED** | calls new sendCertExpiryReminders() |
| `IssuanceService::issueIfPassed()` | **MODIFIED** | allow re-issue when old cert is expired (period-close) |
| `PassCriteriaService::emitPassEventIfFirst()` | **MODIFIED** | per-period guard (see Crux 3) |

### Block 4: Username-Politur

| Component | New / Modified | Notes |
|-----------|---------------|-------|
| `UserCsvController` | **NEW** | CSV upload + user provision + enroll; admin-only |
| `UserProvisionService` | **NEW** | wraps IUserManager::createUser(), CourseService::enroll() |
| (no DB changes) | — | IUserManager + IGroupManager already injected in CourseService |

---

## DB Schema Sketches

All names follow the existing migration convention: table names WITHOUT `oc_` prefix (NC auto-prepends), `Types::*` enum everywhere, index names ≤ 27 chars, no index on TEXT/mediumtext columns (utf8mb4 key-length blowup — see Version009100 comment), nullable BIGINT for unix timestamps (no default), same as `revoked_at` in Version009200.

### learning_course_videos (NEW table)

```php
// In a new Version00XXXX migration
$table = $schema->createTable('learning_course_videos');
$table->addColumn('id',               Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
$table->addColumn('course_id',        Types::BIGINT, ['notnull' => true]);
$table->addColumn('video_ref',        Types::STRING, ['notnull' => true, 'length' => 255]);
// 'nc_file' | 'vimeo' | 'youtube'
$table->addColumn('source_type',      Types::STRING, ['notnull' => true, 'length' => 16]);
$table->addColumn('duration_seconds', Types::BIGINT, ['notnull' => false]); // null = unknown
$table->addColumn('sort_order',       Types::BIGINT, ['notnull' => true, 'default' => 0]);
$table->addColumn('created_at',       Types::BIGINT, ['notnull' => true, 'default' => 0]);
$table->setPrimaryKey(['id']);
$table->addIndex(['course_id'], 'learn_video_course_idx');       // 22 chars OK
$table->addUniqueIndex(['course_id', 'video_ref'], 'learn_video_crs_ref_uq'); // 24 chars OK
```

### learning_watch_progress (NEW table)

```php
$table = $schema->createTable('learning_watch_progress');
$table->addColumn('id',               Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
$table->addColumn('user_id',          Types::STRING, ['notnull' => true, 'length' => 64]);
$table->addColumn('course_id',        Types::BIGINT, ['notnull' => true]);
$table->addColumn('video_ref',        Types::STRING, ['notnull' => true, 'length' => 255]);
$table->addColumn('seconds_covered',  Types::BIGINT, ['notnull' => true, 'default' => 0]);
$table->addColumn('duration_seconds', Types::BIGINT, ['notnull' => false]);
$table->addColumn('completed_at',     Types::BIGINT, ['notnull' => false]); // null = not done
$table->addColumn('updated_at',       Types::BIGINT, ['notnull' => true, 'default' => 0]);
$table->setPrimaryKey(['id']);
$table->addUniqueIndex(['user_id', 'course_id', 'video_ref'], 'learn_watch_usr_crs_uq'); // 26 chars OK
$table->addIndex(['course_id', 'user_id'], 'learn_watch_crs_usr_idx'); // 24 chars OK
```

The UNIQUE on `(user_id, course_id, video_ref)` enables an idempotent INSERT OR UPDATE upsert (the same pattern the cert idem guard uses).

### New columns on learning_courses (ALTER via migration)

```php
// video gating
if (!$table->hasColumn('video_gate_enabled')) {
    $table->addColumn('video_gate_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
}
// re-cert
if (!$table->hasColumn('recert_enabled')) {
    $table->addColumn('recert_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
}
if (!$table->hasColumn('recert_interval_days')) {
    $table->addColumn('recert_interval_days', Types::BIGINT, ['notnull' => false]); // null = use cert_validity_days
}
```

### learning_team_leads (NEW table)

```php
$table = $schema->createTable('learning_team_leads');
$table->addColumn('id',           Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
$table->addColumn('course_id',    Types::BIGINT, ['notnull' => true]);
$table->addColumn('lead_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
$table->addColumn('nc_group_id',  Types::STRING, ['notnull' => true, 'length' => 255]);
$table->addColumn('created_at',   Types::BIGINT, ['notnull' => true, 'default' => 0]);
$table->setPrimaryKey(['id']);
$table->addUniqueIndex(['course_id', 'lead_user_id', 'nc_group_id'], 'learn_tl_crs_usr_grp_uq'); // 27 chars, exactly at limit
$table->addIndex(['course_id'], 'learn_tl_course_idx'); // 20 chars OK
$table->addIndex(['lead_user_id'], 'learn_tl_lead_idx'); // 19 chars OK
```

**Why a separate table and not extending `learning_course_members.role`:** CourseMember already has a `role` column (student/instructor). Adding `team_lead` there would work for enrollment but would require a second `group_scope` column to express "lead for which NC subgroup," making the table structurally ambiguous. A dedicated mapping table is explicit, queryable independently, and avoids modifying the existing member enrollment flow.

**Why not NC sub-admin (`ISubAdmin`):** No existing `ISubAdmin` usage in the codebase. NC sub-admin grants group-admin rights at the NC-admin level, which is broader than scoped course-report visibility. The app already uses `nc_group_id` (on Course) + `IGroupManager::isInGroup()` for enrollment gating; the team-lead pattern extends this naturally without acquiring NC admin-tier privileges.

---

## End-to-End Event Flow

### Full compliance lifecycle for one student in one course

```
[Instructor configures course]
  └─ Sets video_gate_enabled=true, attaches learning_course_videos rows
  └─ Sets cert_enabled=true, cert_validity_days=365, recert_enabled=true

[Student opens course]
  └─ Frontend loads VideoPlayer.vue, renders required videos in order

[Student watches video]
  NC-MP4 path:
    └─ <video> timeupdate fires → POST /api/video/heartbeat (seconds_covered)
    └─ <video> ended → POST /api/video/complete → WatchProgressService sets completed_at
  Vimeo/YouTube path:
    └─ iframe postMessage (timeupdate / ended events) → same two endpoints
    └─ CRUX: YT/Vimeo completion is CLIENT-ATTESTED — server stores what the client reports
             NC-MP4 can be hardened with server-side range accounting; external embeds cannot.
             Gate is still meaningful (attacker must actively fake postMessage; casual skip is prevented).

[All required videos completed]
  └─ WatchProgressService::allCompleted(userId, courseId) → true
     (COUNT matching completed_at IS NOT NULL = COUNT of learning_course_videos for this course)

[Student starts quiz]
  └─ POST /api/training/start?poolId=X&courseId=Y → TrainingController::start()
  └─ TrainingService::startSession()
       ├─ [EXISTING] enforceExamStartPolicy() — exam attempt limits
       ├─ [EXISTING] check examRequiresTraining
       └─ [NEW] if course->videoGateEnabled: WatchProgressService::assertAllCompleted(userId, courseId)
                 → throws ForbiddenException (→ 403) if any video incomplete
                 → SERVER ENFORCES: no training session row is written until gate passes

[Student completes exam — passes]
  └─ TrainingService::completeSession() → score stored in learning_sessions

[Student checks pass status]
  └─ GET /api/pass-status?courseId=Y → PassCriteriaService::evaluate()
  └─ Passes both gates (score + mastery) → emitPassEventIfFirst() → IssuanceService::issueIfPassed()
  └─ Certificate written to learning_certificates (verification_id, expires_at = now + cert_validity_days)

[Cert approaches expiry — ReminderService]
  └─ SendRemindersJob::run() (every hour) → at configured hour(s):
  └─ ReminderService::sendCertExpiryReminders()
       SELECT c.user_id, c.course_id, c.expires_at
       FROM learning_certificates c
       JOIN learning_courses co ON co.id = c.course_id
       WHERE co.recert_enabled = true
         AND c.revoked_at IS NULL
         AND c.expires_at IS NOT NULL
         AND c.expires_at BETWEEN :now AND :now + 30d
       (dedup via IConfig::getUserValue marker, same as EXAM_REMINDER_KEY_PREFIX pattern)
  └─ NC notification: 'cert_expiry_reminder' subject

[Cert expires — period close for re-issue]
  └─ RecertJob (NEW TimedJob, daily) or extended ReminderService:
       UPDATE learning_certificates SET revoked_at = :now, active_idem_key = NULL
       WHERE expires_at < :now AND revoked_at IS NULL AND course.recert_enabled = true
  └─ Nullifying active_idem_key frees the UNIQUE slot (see Version009100 comment: "revoke frees the slot")
     This is the ONLY safe path to unblock re-issue without breaking the existing idempotency guard.

[Student re-takes]
  └─ IssuanceService::issueIfPassed() checks findByUserAndCourse() — finds only revoked certs (revoked_at set)
  └─ Non-revoked cert = none → issues fresh cert for new period
  └─ PassCriteriaService::emitPassEventIfFirst() REQUIRES MODIFICATION (see Crux 3 below)
```

### Team-lead report flow

```
[Instructor assigns team lead]
  └─ POST /api/teamlead → inserts into learning_team_leads (course_id, lead_user_id, nc_group_id)

[Team lead views report]
  └─ GET /api/cert-report?courseId=X&groupId=Y → CertificateReportController
  └─ CertificateReportService::getGroupReport(courseId, leadUserId, ncGroupId)
       1. assertTeamLeadForGroup(leadUserId, courseId, ncGroupId) — queries learning_team_leads
          → ForbiddenException if no matching row
       2. groupMembers = IGroupManager::getGroup(ncGroupId)->getUsers() → [IUser]
          → extract user_ids
       3. certificateMapper->findByCourseIdAndUserIds(courseId, userIds, from, to, expiresBefore)
          → WHERE user_id IN (:userIds) — server-side filter, user_id never reaches response
       4. projectRow() for each cert — same DSGVO-safe projection as getCourseReport()
          → only display_name (frozen from JWT), passed_at, score, expires_at, verification_id
```

---

## Three Architectural Cruxes

### Crux 1: Server-side enforcement of the video gate

**What is enforced:** `TrainingService::startSession()` calls `WatchProgressService::assertAllCompleted(userId, courseId)` BEFORE writing any `learning_sessions` row. A student who has not completed all required videos gets a 403; they cannot start the quiz. This is identical in shape to the existing `exam_requires_training` boolean gate.

**What cannot be cryptographically proven:** For Vimeo/YouTube embeds, completion signals arrive via iframe `postMessage`. The server stores what the client reports. A technically motivated user could fake the API calls and mark a video complete without watching. This is unavoidable for third-party embeds — there is no server-to-Vimeo backend channel.

**Risk calibration:** The gate prevents accidental skip (scroll past, click next). It does not provide cryptographic proof of viewing external content. For compliance purposes this matches what LMS systems like Moodle do with external video embeds. If the AWO requires non-repudiation for external videos, NC-hosted MP4 should be the mandatory format.

**NC-MP4 hardening available:** `<video>` timeupdate fires every ~250ms; the server can accumulate `seconds_covered` and only accept `completed_at` when `seconds_covered >= duration_seconds * 0.9` (90% threshold). This is not spoofable without script injection.

### Crux 2: Team-lead authorization must filter BEFORE any DSGVO projection

`CertificateReportService::getCourseReport()` gates on `assertInstructorOfCourse()` and then runs `projectRow()` which never outputs `user_id`. The new `getGroupReport()` method MUST apply the `user_id IN (group members)` filter at the DB query level, not in PHP after fetching rows. Fetching all certs for a course and then filtering by group in PHP would bring `user_id` values (from `Certificate::getUserId()`) into memory and risk accidental exposure in logs. The CertificateMapper needs a new `findByCourseIdAndUserIds(courseId, userIds[], ...)` method that adds a `WHERE user_id IN (?)` clause to the existing query.

### Crux 3: Re-certification collides with two existing idempotency guards

This is the most significant design constraint in the milestone.

**Guard 1 — IssuanceService:** `issueIfPassed()` calls `findByUserAndCourse()` which returns any non-revoked cert. An *expired* cert is still non-revoked, so it blocks re-issue. The fix: when `recert_enabled=true` and `expires_at < now`, a period-close step must SET `revoked_at` (the existing revoke column from Phase 157) and NULL out `active_idem_key`. After that, `issueIfPassed()` finds no non-revoked cert and issues a fresh one. The period-close step runs in a new `RecertPeriodCloseJob` (TimedJob, daily) or is folded into `SendRemindersJob`.

**Guard 2 — PassCriteriaService:** `emitPassEventIfFirst()` uses the audit event log to suppress duplicate `course.passed` events. A student who passed year 1, gets their cert expired/period-closed, and re-passes year 2 will be silently suppressed. The fix: `emitPassEventIfFirst` must check "has the student passed in the current period" not "has the student ever passed." Since `RecertPeriodCloseJob` revokes the old cert, one approach: after period-close, write a `cert.period_closed` audit event; `emitPassEventIfFirst` checks for a `cert.period_closed` event newer than the last `course.passed` event. If found, it treats the student as eligible to fire a new `course.passed`. Alternatively: remove the audit-event dedup and rely solely on `IssuanceService`'s idempotency (the `active_idem_key` UNIQUE index) as the truth. Either approach is valid; the roadmapper must pick one and spec it explicitly before implementation.

**What the roadmapper must scope:** Phase for Re-Cert needs to include an explicit sub-task for the guard redesign. Treating it as "just add reminders and a column" will miss these guards and result in re-cert not actually issuing new certs.

---

## Standard Architecture

### System Overview (existing + new components)

```
Frontend (Vue 3.5 / Options API)
  ├── VideoPlayer.vue [NEW] ─── NcMp4Player / VimeoPlayer / YouTubePlayer [NEW]
  ├── ComplianceReport.vue [existing, extended for team-lead group filter]
  └── CourseDetail.vue [existing, shows video gate status]
       │ POST heartbeat/complete │ GET group-report │ POST startSession
       ▼                         ▼                  ▼
Controller Layer (OCP AppFramework)
  ├── VideoController [NEW] ── WatchProgressService [NEW]
  ├── CertificateReportController [MODIFIED] ── CertificateReportService::getGroupReport() [NEW method]
  └── TrainingController [existing] ─── TrainingService::startSession() [MODIFIED]
       │ read watch_progress │ read team_leads │ read certificates
       ▼                      ▼                 ▼
Service Layer
  ├── WatchProgressService [NEW]
  ├── RoleService [MODIFIED — isTeamLead(), getLeadGroups()]
  ├── ReminderService [MODIFIED — sendCertExpiryReminders()]
  └── PassCriteriaService [MODIFIED — re-cert guard redesign]
       │ IGroupManager │ IDBConnection
       ▼               ▼
DB Layer (QBMapper / raw IQueryBuilder)
  ├── learning_course_videos [NEW]
  ├── learning_watch_progress [NEW]
  ├── learning_team_leads [NEW]
  ├── learning_courses (+video_gate_enabled, +recert_enabled, +recert_interval_days) [MODIFIED]
  └── learning_certificates (existing — revoked_at used for period-close)
```

### Component Responsibilities

| Component | Responsibility | Implementation |
|-----------|----------------|----------------|
| `WatchProgressService` | Accept heartbeats, mark completion, gate assertion | New service, IDBConnection direct queries |
| `VideoController` | Authenticated endpoints for heartbeat + complete | New Controller, @NoAdminRequired |
| `RoleService` | Extended: check team-lead mapping | New `isTeamLead()` queries `learning_team_leads` |
| `CertificateReportService::getGroupReport()` | Group-scoped report with member filter | New method on existing service |
| `ReminderService::sendCertExpiryReminders()` | Expiry notifications | New method, same IConfig dedup pattern |
| `RecertPeriodCloseJob` | Close expired cert periods to unlock re-issue | New TimedJob, daily interval |
| `UserProvisionService` | CSV parse + IUserManager::createUser() + enroll | New service, admin-gated |

---

## Build Order — Dependency-Ordered

The four blocks are largely orthogonal (no hard dependency chain). Order by risk/foundation:

### Phase 1: Block 4 — Username-Politur (CSV Upload)

**Why first:** Quickest to build. De-risks the AWO onboarding of 2000 users before the compliance features are live. Establishes the group-provisioning substrate that Block 2's team-lead assignment UI shares. No architectural design risk — it is `IUserManager::createUser()` + existing `CourseService::enroll()`.

**Deliverable:** `UserCsvController`, `UserProvisionService`, minimal Vue upload form.

### Phase 2: Block 1 — Video-/Material-Gating

**Why second:** The headline compliance blocker. Contains the primary architectural crux (server-side gate point in `TrainingService::startSession()`). Must be built before Block 2 can be fully demonstrated (reports are more meaningful when courses have watched-video evidence). Two migrations (`learning_course_videos`, `learning_watch_progress`, new columns on courses).

**Deliverable:** `VideoController`, `WatchProgressService`, migrations, `VideoPlayer.vue` with all 3 adapters, gate in `TrainingService`.

### Phase 3: Block 2 — Teamleiter-RBAC-Reports

**Why third:** Reads the NC group infrastructure from Block 4 (group provisioning established). Extends `CertificateReportService` and `RoleService`. One migration (`learning_team_leads`). No guard-redesign complexity.

**Deliverable:** `learning_team_leads` migration, `RoleService` extensions, `CertificateReportService::getGroupReport()`, report endpoint, team-lead assignment UI (could be folded into CourseController).

### Phase 4: Block 3 — Re-Zertifizierung

**Why last:** Most design complexity (two idempotency guard redesigns). Requires certs to exist in production (live since v5.0.0, so data exists). Depends on `expires_at` being populated (already done by `IssuanceService` when `cert_validity_days > 0`). The guard redesign must be specced explicitly before implementation starts — the roadmapper should not let this be "just add a reminder."

**Deliverable:** New course columns migration, `ReminderService` extension, `RecertPeriodCloseJob`, `PassCriteriaService` guard redesign, `IssuanceService` re-issue unblocking.

---

## Anti-Patterns

### Anti-Pattern 1: Client-only video gate

**What:** Vue component hides the quiz tab until the video shows as "done" — no server check.

**Why it's wrong:** The API endpoint `POST /api/training/start` is callable directly by any authenticated user. A student bypasses the gate by calling the API directly. This gives legal compliance exposure: the course certificate records that the student "completed" training including video, but the server never enforced it.

**Do this instead:** Gate lives in `TrainingService::startSession()` on the server. The client UI can and should reflect the gate state (showing a locked quiz icon), but the enforcement is the server-side check before the session row is written.

### Anti-Pattern 2: Filtering team reports in PHP after fetching all certs

**What:** Fetch all certs for a course from the mapper, then PHP-filter by group membership.

**Why it's wrong:** Brings all `user_id` values into PHP memory for a report that a team lead is only authorized to see a subset of. Risks logging `user_id` values (PHPStan may not catch this). Scales poorly on large courses.

**Do this instead:** `IGroupManager::getGroup($ncGroupId)->getUsers()` returns the member set; build a `WHERE user_id IN (?)` at the QB level in a new `CertificateMapper::findByCourseIdAndUserIds()` method.

### Anti-Pattern 3: Re-cert via adding a "re-enroll" flag without touching the idempotency guards

**What:** Add a "reset status" button that sets a DB flag, then expect `IssuanceService` and `PassCriteriaService` to re-fire.

**Why it's wrong:** `issueIfPassed()` returns the existing non-revoked cert immediately, regardless of expiry. `emitPassEventIfFirst()` suppresses the second `course.passed` event unconditionally. Neither guard reads a "re-enroll" flag.

**Do this instead:** The period-close path (set `revoked_at`, NULL `active_idem_key`) is the only safe way to unblock issuance. The pass-event guard must also be explicitly modified for the re-cert period concept.

### Anti-Pattern 4: YT/Vimeo iframe wait for "ended" only

**What:** Only trigger `POST /api/video/complete` on the YT `onStateChange=ENDED` event.

**Why it's wrong:** Users closing the tab, losing network, or minimizing the browser before the ended event will never have their progress saved. The gate blocks their quiz permanently until they re-watch.

**Do this instead:** Periodic heartbeat (every 30s) writing `seconds_covered` to the server. Complete is set when `seconds_covered >= duration * threshold` OR on explicit ended event. This makes the gate resilient to interrupted sessions.

---

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Vimeo embed | `<iframe>` + Player.js postMessage | Vimeo Player.js SDK via script tag or ESM import |
| YouTube embed | `<iframe>` + YT IFrame API postMessage | Must be loaded via `https://www.youtube.com/iframe_api` |
| NC Files (MP4) | `<video src="NC_SHARE_URL">` or NC Preview URL | Needs NC-auth; use `/index.php/core/preview` or signed share token |
| NC Groups (`IGroupManager`) | Injected via DI (already in CourseService) | `getGroup($id)->getUsers()` — may be slow on large groups; cache per request |
| NC Notifications (`INotificationManager`) | Already in ReminderService | Re-use for cert_expiry_reminder subject |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| `VideoController` → `WatchProgressService` | Direct DI injection | Standard pattern |
| `TrainingService` → `WatchProgressService` | Direct DI injection | NEW dependency to inject via Application.php |
| `CertificateReportService` → `IGroupManager` | NEW constructor injection | Add to CertificateReportService constructor (currently uses only CourseService + CertificateMapper + ITimeFactory) |
| `SendRemindersJob` → `ReminderService` | Already injected | Just add new method call |
| `RecertPeriodCloseJob` → `CertificateMapper` | Direct DI injection | New job, new mapper dependency |
| `UserProvisionService` → `IUserManager` | DI injection | Already in CourseService — precedent exists |

---

## Sources

- Direct codebase read: `app/lib/Service/TrainingService.php`, `CertificateReportService.php`, `RoleService.php`, `ReminderService.php`, `PassCriteriaService.php`, `IssuanceService.php`
- Migration patterns: `Version009100Date20260627000000.php` (table creation), `Version009200Date20260627120000.php` (addColumn pattern)
- Entity patterns: `app/lib/Db/Course.php` (existing gate fields: `examRequiresTraining`, `certRequiredPoolIds`, `certValidityDays`)
- Background job patterns: `NotificationJob.php` (TimedJob), `SendRemindersJob.php` (hourly + ReminderService delegation)
- Confidence: HIGH — all classes named exist on disk with verified signatures

---

*Architecture research for: v5.2.0 Pflichtschulung / AWO-Readiness — learning-nc*
*Researched: 2026-07-01*
