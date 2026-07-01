# Research Summary — v5.2.0 Pflichtschulung

**Project:** learning-nc
**Domain:** Compliance-training feature additions to existing native Nextcloud app (PHP 8.1+, Vue 3.5 Options API, PG16 + MariaDB 11.4)
**Researched:** 2026-07-01
**Confidence:** HIGH overall (all files primary-source — real classes, real migration patterns, real codebase greps)

---

## Executive Summary

v5.2.0 adds six compliance-training capability blocks to a live app that already provides signed verifiable certificates and a public verify portal. The context is an AWO-Sachsen lead (Jan Knizek, 2000 employees) where competing products (Forma LMS, Docebo) require a second system, have no cryptographic cert verification, and cannot run inside the org's Nextcloud instance. The differentiation is already structural — the task is operational completeness, not architecture invention. Every feature block has a direct analog in established LMS platforms (Absorb, Moodle Workplace, Docebo), and the research confirms all six blocks are buildable by extending existing services rather than adding subsystems.

Three architectural foundations must be placed before feature work begins and are prohibitively expensive to retrofit: (1) the audit trail must be hash-chained and signed from the first compliance event in v5.2.0 — historical events before the retrofit are unprovable forever; (2) the credential `proof` field must be structured as an array now, because per-cert signed JWTs are immutable after issuance and adding a second proof later would require re-minting every cert; (3) the Assignment model must be built as a first-class DB object because `learning_course_members` has no lifecycle columns and retrofitting re-cert periods onto a single-row-per-enrollment model is structurally incompatible. These three decisions sit in Phase 1 as schema and service groundwork.

The highest-stakes non-technical item for the AWO deployment is the Betriebsvereinbarung (works-council agreement under BetrVG § 87 Abs. 1 Nr. 6). Watch-time segment data constitutes Verhaltens-/Leistungskontrolle, and the Betriebsrat can block deployment retroactively if it discovers behavioral data was stored. The design mitigation is already in the architecture: heartbeat segments are transient (deleted when completed_at is set), and only (user_id, content_id, completed_at) persists permanently. AWO must be informed this agreement is required before production rollout — that is their responsibility, but informing them is ours.

---

## Key Findings

### Recommended Stack

Zero new npm or composer dependencies for any of the six blocks. All PHP via existing NC OCP interfaces; frontend player SDKs (Vimeo, YouTube) loaded lazily from CDN at runtime with no bundle impact. The existing stack — PHP 8.1+, Vue 3.5 Options API, OCP\BackgroundJob\TimedJob, OCP\Notification\IManager, OCP\IGroupManager, OCP\Files\IRootFolder, ext-sodium — provides everything needed.

**Core technologies (extended, not added):**

| Component | Purpose | Status |
|-----------|---------|--------|
| PHP `IRootFolder->getUserFolder($instructorId)->fopen()` | Server-side video streaming with enrollment gate | Existing — used in DocumentService; extend to VideoStreamController |
| `OCP\BackgroundJob\TimedJob` | Scheduled jobs: reminder, period-close, audit checkpoint | Existing — copy scheduling boilerplate |
| `OCP\Notification\IManager` | Primary reminder channel (email-less-safe, mandatory) | Existing in ReminderService |
| `OCP\IMailer` | Optional additive channel where `$user->getEMailAddress()` non-null | Existing — add to re-cert only as additive |
| `OCP\IGroupManager` | Group-scoped assignments, oversight authorization, member expansion | Existing — already in RoleService, CourseService |
| ext-sodium `sodium_crypto_sign_detached` | Audit checkpoint signing (reuse existing key infrastructure) | Existing — used by SigningService |
| Vimeo Player SDK | CDN-loaded, event tracking | Runtime CDN `player.vimeo.com/api/player.js`, zero bundle |
| YouTube IFrame API | CDN-loaded, event tracking | Runtime CDN `youtube.com/iframe_api`, zero bundle |

**Note:** Migration version numbers in research files are illustrative — multiple files independently claim overlapping version slots. Assign coherent version sequence at roadmap time (current live migration = Version009200).

**New DB migrations required:**

| Table / Change | Purpose | Compat |
|----------------|---------|--------|
| ALTER `learning_audit_events` + CREATE `learning_audit_chain_state` | Hash-chain foundation (RED-1 Layer 1) | PG16 + MariaDB |
| CREATE `learning_audit_checkpoints` | Ed25519 signed checkpoint (RED-1 Layer 2) | PG16 + MariaDB |
| CREATE `learning_assignments` | First-class obligation object (RED-3) | PG16 + MariaDB |
| CREATE `learning_oversight` | Team-lead view-permission scoping (RED-3) | PG16 + MariaDB |
| CREATE `learning_video_progress` | Watch-tracking working state (transient segments, DSGVO) | PG16 + MariaDB |
| CREATE `learning_course_videos` | Per-course video registry with duration | PG16 + MariaDB |
| ALTER `learning_courses` + `learning_certificates` | video_gate_enabled, recert_enabled, recert_interval_days, re_cert_reminder_sent_at | PG16 + MariaDB |

**What NOT to add — hard constraints:**

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `@vimeo/player` in package.json | ~50 KB bundle; CDN load is Vimeo's recommended path | CDN runtime lazy-load |
| `video.js` / `plyr.js` or any video lib | NC-hosted MP4 via native `<video>` needs none | Native `<video>` element |
| IShareManager for video access | Not currently used; share lifecycle must mirror enrollment; harder to audit | VideoStreamController with enrollment gate |
| IMailer as primary reminder path | No IMailer in existing reminder path (confirmed grep); breaks username-only users | INotificationManager always + IMailer additive only |
| WebSocket library | NC has no WS server; existing no-WS constraint | POST polling (same pattern as CoopService) |
| SCORM runtime | Months of work; not AWO's actual need | NC-MP4 native with gating |
| Multi-tenancy infrastructure | Business model is on-prem first; NC groups provide group-isolation | NC group model |
| `JSONB` column type | PG16-only; breaks MariaDB 11.4 | `Types::TEXT` with JSON-encoded string |
| `VARCHAR(512)` in composite index | utf8mb4 pushes to ~2 KB index key near MariaDB limits | Surrogate BIGINT PK + `CHAR(64)` SHA-256 hash |
| `youtube.com` embed domain | Loads Google tracking before consent, DSGVO Art. 6 | `youtube-nocookie.com` + consent gate |
| `learning_team_leads` table | Superseded by `learning_oversight` from RED-3 | `learning_oversight` |
| Client-only video completion gate | Trivially spoofable (DevTools `ended` event, direct API POST) | Server-side `learning_video_progress.is_complete` |
| In-app CSV upload form for user creation | Adds HTTP surface; occ pattern is NC idiom | `learning:import-users` occ command |

---

### Expected Features

**Must have for AWO contract / v5.2.0 (P1):**

| Feature | Block | Notes |
|---------|-------|-------|
| Video gating — NC-MP4: 95% watched → quiz unlocks, seek prevention, resume | 1 | Hard gate via VideoStreamController + server-side interval merge |
| Video gating — Vimeo/YouTube: best-effort, honestly documented | 1 | Client-attested; seek-prevention not possible for external embeds; document limitation |
| Group-based course assignment via learning_assignments | 2/3 | Uses IGroupManager; LDAP/AD-transparent |
| Teamleiter-RBAC: oversight role scoped to NC group, group-filtered compliance report | 2 | learning_oversight table; IDOR-safe assert-then-project pattern |
| Re-Zertifizierung: expiry status states, 30+7-day reminders, re-enrollment path | 3 | Rolling-from-pass only; idempotency guard redesign required |
| Username-only users: cert issuance + report display without email | 4 | email-null audit first; cert claim-binding verified to use NC uid (display name) |
| CSV bulk-enrollment helper (occ command) | 4 | occ `learning:import-users`; NOT an in-app form; depends on Assignment schema from Phase 1 |
| Tamper-evident audit trail (hash-chain + Ed25519 checkpoints) | RED-1 | Core compliance value prop; must ship in Phase 1 |

**Should have — add in v5.2.x after validation (P2):**
- Document "mark as read" material type (PDF acknowledgment button)
- Grace period (14 days) on cert expiry
- Team-lead triggered in-app reminders to incomplete group members
- Upcoming expirations panel on team-lead dashboard
- Configurable `cert_validity_months` per course (default 12)
- CSV bulk import dry-run / preview
- External audit anchor (Forgejo publish of checkpoint) — design now, 20 lines to enable

**Defer to v5.3+ (P3):**
- Fixed-calendar recert (all-renew-by-date scheduling)
- WKD/PGP countersignature on audit checkpoint (YubiKey-touch, separate milestone)
- Multi-level manager hierarchy
- Automated email as primary notification channel (design IMailer as additive now)

**Anti-features (explicitly do not build):**
- Forward-seek prevention for YouTube/Vimeo (IFrame API doesn't block seek; false auditor confidence)
- SCORM runtime / SCORM import
- Blocking system access on cert expiry (org policy decision, not LMS)
- Fixed-calendar recert in v5.2.0 (net-new data model complexity)
- Per-question analytics / per-minute engagement data (DSGVO concern + out of scope)

---

## Architecture Approach

This is an extension of a live system, not a new subsystem. All new components mirror existing patterns — TimedJob subclasses, IDBConnection mappers, DI via Application.php, @NoAdminRequired controller annotations. Three server-side enforcement points that must not be bypassed via client:

1. Video completion gate lives in `TrainingService::startSession()` — not in Vue, not trusting client params
2. Team-lead authorization runs as first line of `CertificateReportService::getGroupReport()` — gate before any mapper call
3. Re-cert period-close runs via `RecertPeriodCloseJob` (TimedJob) — flips revoked_at + NULLs active_idem_key to unlock re-issue

**Major new components:**

| Component | Responsibility |
|-----------|----------------|
| `VideoStreamController` | IRootFolder stream with enrollment gate + Range HTTP (206 Partial Content) |
| `VideoProgressService` | Server-side interval-merge, is_complete decision, transient-segment lifecycle |
| `AuditService::logComplianceEvent()` | Hash-chained compliance write (does NOT swallow exceptions — behavioral change vs logEvent()) |
| `AuditCheckpointService` + `AuditCheckpointJob` | Weekly Ed25519-signed checkpoint over compliance events |
| `AssignmentService` | Assignment CRUD, group expansion, due-date reminder queries |
| `RoleService` extensions | `isTeamLeadForGroup()`, `getTeamLeadGroups()` via `learning_oversight` |
| `CertificateReportService::getGroupReport()` | Group-scoped report; DB-level WHERE user_id IN filter before any PHP row access |
| `RecertPeriodCloseJob` | Daily TimedJob; closes expired cert periods (revoked_at + NULL active_idem_key) |
| `ImportUsersCommand` | occ `learning:import-users` via IUserManager + group assignment, BackgroundJob for 2000 users |

**NC file access for course videos — mandatory pattern:**

Course MP4 files live in the instructor's NC namespace. Students get 403 on direct WebDAV access. A VideoStreamController endpoint is required: verify enrollment via CourseService, open server-side via `IRootFolder->getUserFolder($instructorId)->get($path)->fopen('r')`, serve 206 Partial Content with Range header. ARCHITECTURE.md's suggestion of `<video src="NC_SHARE_URL">` is incorrect for this access model. IShareManager (Option B) is not recommended — it is currently unused in the app and creates enrollment-lifecycle coupling.

---

## Three RED Foundation Decisions

### RED-1: Tamper-Evident Audit Trail

**What to build (Phase 1):**
- Layer 1: Per-event hash chain on `learning_audit_events` (three new columns: seq_num, prev_hash, chain_hash + new `learning_audit_chain_state` single-row serialization table). Chain hash: `sha256(canonical_json(seq, event_key, user_id, course_id, created_at) || prev_hash)`. PII-minimal — display names / email-shaped identifiers live in context_json and are excluded from hash (Art. 17 erasure safe).
- New `AuditService::logComplianceEvent()` method — unlike `logEvent()`, this does NOT swallow exceptions; a silently-dropped compliance write would look like tampering to a verifier. Three existing callers must be migrated: `PassCriteriaService::emitPassEventIfFirst()`, `IssuanceService`, `CertificateVerifyService`.
- Layer 2: CREATE `learning_audit_checkpoints` table + `AuditCheckpointService` + weekly `AuditCheckpointJob`. Uses `sodium_crypto_sign_detached` directly — do NOT reuse `SigningService::sign()` whose header contract is frozen to `typ:vc+jwt`.
- `occ learning:audit:verify` command must ship alongside the chain — a tamper-evident log with no verifier is theater.
- Layer 3 (fast-follow): External Forgejo anchor (single HTTP PUT to Forgejo contents API, stored in anchor_url column). One config flag + token + ~20 lines. Design now, deploy as fast-follow. NOTE: Layer 3 is the layer that defeats the instance admin holding BOTH key and DB — it is what makes the trail actually audit-defensible against the strongest adversary.

**Serialization:** NC OCP IQueryBuilder has no forUpdate(); use fork-detectable chain-state pattern (concurrent writes create a visible branch, not an undetectable gap). Compliance events are already serialized upstream by the active_idem_key idempotency guard.

**Retrofit cost:** Historical provability is permanently lost. Chain starts from v5.2.0 deploy. All pre-v5.2.0 compliance events are unprovable forever.

### RED-2: Cert Verifiability + Proof-Array Hook

**Decision: defer WKD per-cert signing to v5.3+.** Three decisive reasons: (1) YubiKey physical touch is incompatible with automated IssuanceService::issueIfPassed() — no human in the loop; (2) no core-PHP OpenPGP without new deps (violates zero-dep constraint); (3) did:web + Ed25519 (v5.0.0) already satisfies independent auditor verification. WKD belongs on the periodic audit checkpoint (RED-1 Layer 3), not per-cert.

**What to build now — two lines:** Change `proof` in VC credential JSON from a scalar object to an array (`proof: [{...}]`). Ensure `CertificateVerifyService` iterates `proof` rather than accessing a scalar.

**Retrofit cost:** Omitting the proof-array hook = re-mint all certs at v5.3. The hook is two lines.

### RED-3: Assignment as a First-Class Object

**What to build:** Two tables, not one.

`learning_assignments` (obligation): course_id, polymorphic subject (subject_type='user'|'group', subject_id), due_date, recert_interval_days override, status (persisted: assigned/in_progress/passed; derived at read: overdue/expired), active_period_key (nullable-unique, same pattern as active_idem_key on certificates — NULL when period closes, freeing the slot for re-cert). CRITICAL: `(course_id, subject_type, subject_id)` must be a PLAIN index, NOT UNIQUE — re-cert creates a new row per period.

`learning_oversight` (view-permission): course_id, lead_user_id, scope_group_id. This table supersedes ARCHITECTURE.md's `learning_team_leads` — oversight (view permission) and assignment (obligation) have different cardinalities and column profiles.

**Assignment does NOT gate issuance:** Self-enrolled learners have no assignment row; routing emitPassEventIfFirst through the assignment table would break cert issuance for all non-compliance users. Assignment is a status reporting layer — the pass event UPDATES it, does not depend on it.

**Re-cert guard resolution:** `PassCriteriaService::emitPassEventIfFirst()` redesigned to check "does student have an active assignment for this course where active_period_key IS NOT NULL AND status != 'passed'?". On period close, `RecertPeriodCloseJob` sets active_period_key = NULL and creates a new assignment row with a fresh key. No invented `cert.period_closed` audit event needed.

**LDAP/AD transparency confirmed:** IGroupManager already abstracts LDAP/AD users in RoleService (no LDAP conditionals). A group assignment subject_type='group' automatically covers LDAP/AD group members.

**Retrofit cost:** Structurally impossible without a new table. `learning_course_members` has no lifecycle columns.

---

## Critical Pitfalls

1. **Client-side video gate — trivially spoofable (CRITICAL).** Gate in TrainingService::startSession(), server-side, checks learning_video_progress.is_complete. Warning sign: POST /api/video/complete reads client flag.
2. **DSGVO + Betriebsrat — watch-segment behavioral data (CRITICAL, deployment-blocking).** learning_video_progress stores intervals as working state ONLY; on completed_at write the row is deleted. Permanently persisted: (user_id, content_id, completed_at) only. Roadmapper must spec the cleanup trigger explicitly. Inform AWO a Betriebsvereinbarung is a deployment prerequisite.
3. **IDOR in team-lead compliance report (HIGH).** assertTeamLeadForGroup() as FIRST line of getGroupReport(); group-member filter at DB query level (WHERE user_id IN). Authorization source: learning_oversight, not NC subadmin flag.
4. **Re-cert idempotency guard collision (HIGH).** IssuanceService::issueIfPassed() returns the expired cert, blocking re-issue; PassCriteriaService suppresses the 2nd course.passed event. Both must be redesigned via RED-3 period-close. "Just add a reminder column" = sends reminders but never issues new certs.
5. **DST-unsafe annual expiry (MEDIUM).** Use DateTimeImmutable::modify('+1 year'), not +365*86400. Unit test: issued 2026-03-29 → expires 2027-03-29.
6. **Reminder storm (MEDIUM).** Per-cert-per-threshold composite key (certId, threshold_days).
7. **Cert history corruption (MEDIUM).** Re-cert creates a NEW cert row; old row immutable; old verification_id URL resolves permanently.
8. **CSV bulk import timeout at 2000 users (MEDIUM).** Synchronous createUser() loop times out → BackgroundJob.
9. **No-email user — IMailer must not be load-bearing (MEDIUM).** INotificationManager only channel for username-only users; check getEMailAddress() non-null before any IMailer call.
10. **YouTube/Vimeo DSGVO (HIGH).** youtube-nocookie.com + dnt=1 + consent gate before third-party load.

---

## Implications for Roadmap

### Phase 1 — Foundation (Schemas + Audit Hardening + Email-Null Audit)
RED decisions must precede all feature work. Delivers: audit chain schema + logComplianceEvent() + 3 callers migrated + AuditCheckpointService/Job + `occ learning:audit:verify`; learning_assignments + learning_oversight schemas; IssuanceService proof-array hook (2 lines); email-null audit (grep getEMailAddress() call sites). Standard patterns — no additional research.

### Phase 2 — Video Gating + Username-Politur
Headline compliance blocker + primary architectural crux. Delivers: VideoStreamController (enrollment gate + Range 206); VideoProgressService (interval merge, DSGVO transient cleanup); learning_video_progress + learning_course_videos migrations; VideoPlayer.vue (NcMp4/Vimeo/YouTube adapters); gate in TrainingService::startSession(); DSGVO consent overlay; ImportUsersCommand (BackgroundJob). RESEARCH FLAG: Range 206 behavior needs live curl verification early.

### Phase 3 — Teamleiter-RBAC-Reports
Depends on learning_assignments + learning_oversight from Phase 1. Delivers: AssignmentService; RoleService extensions; CertificateReportService::getGroupReport() (DB-level filter, IDOR-safe); team-lead assignment UI. Standard patterns.

### Phase 4 — Re-Zertifizierung
Most design complexity. Delivers: ALTER courses/certificates; RecertPeriodCloseJob; PassCriteriaService + IssuanceService guard redesign; ReminderService expiry+due reminders; per-threshold dedup; DST-safe expiry; immutable cert history. GUARD REDESIGN must be explicitly specced before implementation.

### Phase Ordering Rationale
- Audit chain before feature work (cannot be backdated); Assignment schemas before RBAC/re-cert; video gating independent (can parallelize with Phase 1 schema); re-cert last (needs full idempotency surface). Betriebsvereinbarung = deployment gate: inform AWO during Phase 2 design review.

---

## Confidence Assessment

Overall HIGH. Stack/Features/Architecture/RED = HIGH (direct class reads); Pitfalls HIGH (code) / MEDIUM (legal — BetrVG § 87 Abs. 1 Nr. 6 confirm with counsel).

### Gaps to Address During Implementation
| Gap | How to Handle |
|-----|---------------|
| Range-request 206 behavior under S3/nginx backends | Verify with curl early in Phase 2 |
| declared_duration source if ffprobe unavailable on relay | Fallback: admin enters duration seconds manually |
| Betriebsvereinbarung scope/timeline with AWO | Inform during Phase 2 design review; transient-segment design as mitigation |
| Migration version numbers | Assign coherent sequence at roadmap time (live = 009200) |
| External Forgejo anchor token | Config flag + token in .env; zero schema change |
| v5.0.0 cert claim-binding | VERIFIED: credentialSubject.name = display name, no email (email-safe) |

---

## Sources

Primary (direct codebase read): AuditService.php, TrainingService.php, CertificateReportService.php, RoleService.php, ReminderService.php, PassCriteriaService.php, IssuanceService.php, SigningService.php, Version009100/009200 migrations, SendRemindersJob.php.
External (HIGH): Vimeo player.js GitHub + npm (v2.30.4), YouTube IFrame API docs, nextcloud/server user_ldap (IGroupManager LDAP transparency).
Secondary (MEDIUM): NC forum #8729 (Range behavior), BetrVG § 87 (confirm with counsel).

Research files synthesized: STACK.md, STACK-FOUNDATIONS.md, FEATURES.md, ARCHITECTURE.md, PITFALLS.md, + App-Requirements-Compliance-Business.md (UG business handover).

---
*Research completed: 2026-07-01*
*Ready for roadmap: yes*
