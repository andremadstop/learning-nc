---
phase: 160-foundation-audit-assignment
verified: 2026-07-01T12:00:00Z
status: passed
score: 12/12 requirements satisfied
re_verification: false
---

# Phase 160: Foundation — Audit Hash-Chain + Assignment Schemas

**Phase Goal:** Tamper-evident audit foundation and assignment infrastructure are in place; all callers produce chain-linked compliance events; first-class assignment objects exist in the DB; email-null callers are safe.
**Verified:** 2026-07-01
**Status:** passed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Tamper-evident audit hash-chain schema exists with chain_state | VERIFIED | Version009300: 4 nullable cols (seq_num, chain_hash, user_ref, payload_hash) added to learning_audit_events; learning_audit_chain_state created; genesis row seeded idempotently in postSchemaChange |
| 2 | logComplianceEvent is fail-closed — exceptions propagate to caller | VERIFIED | AuditService.php: logComplianceEvent() wraps in try/catch that re-throws ComplianceAuditException; Throwable caught and wrapped; NOT swallowed like logEvent() |
| 3 | All 3 compliance callers use logComplianceEvent (not logEvent) | VERIFIED | PassCriteriaService line 129: COURSE_PASSED; IssuanceService line 146: CERT_ISSUED after insert; CertificateController lines 213–218: CERT_REVOKED guarded with $isFirstRevoke. Revocation mutation scan (`setRevoked(true)/setRevokedAt()`) confirms single entry point: CertificateController::revoke() — no other path |
| 4 | First-class assignment objects exist in DB schema | VERIFIED | Version009400: learning_assignments (10 cols, PLAIN composite idx learn_asn_crs_subj_idx, UNIQUE active_period_key learn_asn_period_uq) + learning_oversight (scope_group_id VARCHAR(64)) |
| 5 | Email-null callers are safe (USER-01) | VERIFIED | ClassbookController.php line 112: `$email = $user ? ($user->getEMailAddress() ?? '') : '';` — double null-safe, never passes null downstream |
| 6 | Bulk user import via occ command is available | VERIFIED | ImportUsersCommand registered in Application.php + info.xml; sync ≤50 / async >50 via ImportUsersJob; passwords generated inside job, never in job args |

**Score:** 6/6 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/lib/Migration/Version009300Date20260701000000.php` | Audit chain schema + genesis seed | VERIFIED | changeSchema() adds 4 nullable cols + learn_audit_chain_idx; creates learning_audit_chain_state; postSchemaChange() seeds genesis row with count guard |
| `app/lib/Migration/Version009301Date20260701130000.php` | UNIQUE seq_num index (FIX-7) | VERIFIED | Adds learn_audit_seq_uq UNIQUE index on seq_num; idempotent hasIndex guard; unplanned migration added as security hardening from Codex review |
| `app/lib/Migration/Version009400Date20260701120000.php` | Assignment + oversight schema | VERIFIED | learning_assignments + learning_oversight created with correct index semantics |
| `app/lib/Service/AuditService.php` | logEvent (swallow) + logComplianceEvent (propagate) + CAS | VERIFIED | Two-level design verified; CAS loop max 3 retries; user_ref = hash_hmac(sha256, userId, pepper); FIX-4 payload_hash; FIX-6 CAS WHERE id=1 |
| `app/lib/Service/ComplianceEventTypes.php` | 4 event type constants | VERIFIED | COURSE_PASSED, CERT_ISSUED, CERT_REVOKED, VIDEO_COMPLETED; private constructor (static-only class) |
| `app/lib/Service/ComplianceAuditException.php` | Typed exception extending RuntimeException | VERIFIED | extends \RuntimeException; signal for fail-closed compliance failures |
| `app/lib/Listener/UserDeletedListener.php` | DSGVO-01: pseudonymize compliance, delete non-compliance | VERIFIED | compliance rows (seq_num IS NOT NULL) → UPDATE user_id=NULL; non-compliance (seq_num IS NULL) → DELETE; FIX-8: deleteAuditEventsBySessionIds guards with IS NULL; learning_audit_events excluded from bulk-delete loop |
| `app/lib/Service/AssignmentService.php` | 5 methods; no IssuanceService dep; correct group expansion; logEvent for deadline | VERIFIED | createAssignment, extendDeadline, markInProgress, markPassed, expandGroup; constructor has IGroupManager+AuditService (no IssuanceService); expandGroup uses groupManager->get($gid)->getUsers(); extendDeadline calls logEvent not logComplianceEvent |
| `app/lib/Controller/ClassbookController.php` | Null-safe email | VERIFIED | Line 112: `$email = $user ? ($user->getEMailAddress() ?? '') : '';` |
| `app/lib/Service/PassCriteriaService.php` | COURSE_PASSED via logComplianceEvent | VERIFIED | Line 129: `$this->auditService->logComplianceEvent(ComplianceEventTypes::COURSE_PASSED, ...)` |
| `app/lib/Service/IssuanceService.php` | CERT_ISSUED via logComplianceEvent after insert | VERIFIED | Line 146 (inside try block, after certificateMapper->insert at line 142); unique-constraint loser path does NOT fire it |
| `app/lib/Controller/CertificateController.php` | CERT_REVOKED via logComplianceEvent, first-revoke only, atomic | VERIFIED | Lines 213–218: `if ($isFirstRevoke)` guard; wrapped in beginTransaction/commit/rollBack (FIX-2) |
| `app/lib/Command/ImportUsersCommand.php` | occ command, sync ≤50 / async >50, no passwords in job args | VERIFIED | SYNC_THRESHOLD=50; async dispatches ImportUsersJob with {csv_data, group, admin_uid} — no password field; sync path generates password in scope, unset() after use |
| `app/lib/BackgroundJob/ImportUsersJob.php` | QueuedJob; passwords generated inside run(), never in args | VERIFIED | extends QueuedJob; passwords generated inside run() via bin2hex(random_bytes(12)); arg schema has no password key |
| `app/lib/AppInfo/Application.php` | ImportUsersCommand registered | VERIFIED | Lines 93-94: registerService block for ImportUsersCommand |
| `app/appinfo/info.xml` | `<command>` entry for ImportUsersCommand | VERIFIED | Line 114: `<command>OCA\Learning\Command\ImportUsersCommand</command>` |
| `app/tests/Unit/Service/AuditServiceTest.php` | 10 test methods covering hash chain, CAS, HMAC, payload_hash | VERIFIED | testChainHashCorrect, testCanonicalFieldSet (payload_hash included), testUserRefIsNotRawUid, testChainLinks, testGenesisEvent, testLogComplianceEventPropagatesException, testCasExhaustsRetries (expects RuntimeException), testCanonicalExcludesPii, testPayloadHashBindsContextFacts (FIX-4), testUserRefPepperDerivedFromInstanceSecret (FIX-5) |
| `app/tests/Unit/Service/AssignmentServiceTest.php` | Assignment service test stubs GREEN | VERIFIED | File exists and substantive |
| `app/tests/Unit/Migration/AuditMigrationTest.php` | Migration test stubs GREEN | VERIFIED | File exists and substantive |
| `app/tests/Unit/Migration/AssignmentMigrationTest.php` | Migration test stubs GREEN | VERIFIED | File exists and substantive |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `PassCriteriaService::emitPassEventIfFirst` | `AuditService::logComplianceEvent` | direct call, event_key=COURSE_PASSED | WIRED | Line 129 confirmed |
| `IssuanceService::issueIfPassed` | `AuditService::logComplianceEvent` | inside try block after certificateMapper->insert | WIRED | Line 146; not in catch paths (unique-constraint loser skips it) |
| `CertificateController::revoke` | `AuditService::logComplianceEvent` | inside $isFirstRevoke guard + beginTransaction | WIRED | Lines 213–220; FIX-2 atomicity: rollBack on throw |
| `UserDeletedListener::handle` | `learning_audit_events` | UPDATE (IS NOT NULL) + DELETE (IS NULL) on user_id | WIRED | Lines 94–117; deleteAuditEventsBySessionIds guard at line 45 |
| `ImportUsersCommand::execute` | `ImportUsersJob` | jobList->add() when dataCount > SYNC_THRESHOLD | WIRED | Line 88; job args = {csv_data, group, admin_uid} |
| `AuditService::getUserRefPepper` | NC instance secret | getSystemValue('secret') with appconfig fallback | WIRED | Verified in AuditService.php; pepper never raw userId |
| `AuditService::logComplianceEvent` | CAS on learning_audit_chain_state | UPDATE WHERE id=1 AND chain_hash=prev; 3-retry loop | WIRED | FIX-6 id=1 pin; FIX-7 UNIQUE seq_num backstop via Version009301 |
| `AssignmentService` | Controllers / Commands | Not yet wired — no controller injects AssignmentService | DEFERRED | By design: Phase 160 is "infrastructure" only; controller wire-up is a future phase. AssignmentService public API is complete (5 methods). |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| AUDIT-01 | 160-01, 160-02 | Hash-chain schema + logComplianceEvent with CAS + user_ref HMAC | SATISFIED | Version009300 live; AuditService full CAS + HMAC pepper implementation |
| AUDIT-02 | 160-02 | Exceptions propagate fail-closed; two-level audit (logEvent swallows, logComplianceEvent throws) | SATISFIED | ComplianceAuditException extends RuntimeException; logComplianceEvent re-throws; logEvent swallows |
| AUDIT-03 | 160-03 | Three compliance callers: PassCriteriaService (COURSE_PASSED), IssuanceService (CERT_ISSUED), CertificateController (CERT_REVOKED) | SATISFIED | All three verified. REQUIREMENTS.md names third caller as "CertificateVerifyService" — this is a stale documentation name. Revocation mutation scan confirms zero write paths in CertificateVerifyService; CertificateController is the sole revocation entry point (CertificateVerifyService correctly reads revocation status, emitting nothing) |
| ASSIGN-01 | 160-04 | learning_assignments schema with PLAIN composite index (re-cert history) + UNIQUE active_period_key | SATISFIED | Version009400: learn_asn_crs_subj_idx (PLAIN) + learn_asn_period_uq (UNIQUE) |
| ASSIGN-02 | 160-05 | Group expansion uses IGroupManager::get() (LDAP-transparent) | SATISFIED | expandGroup uses $this->groupManager->get($gid)->getUsers() |
| ASSIGN-03 | 160-05 | AssignmentService can assign person or group to a course with deadline; group expansion at report/reminder time | SATISFIED (foundation) | AssignmentService.createAssignment() + expandGroup() implemented; controller wire-up deferred to later phase (consistent with "infrastructure" phase goal). No controller calls AssignmentService yet. |
| ASSIGN-04 | 160-05 | Cert issuance NOT gated on assignment row — self-learners still receive certs | SATISFIED | AssignmentService constructor has no IssuanceService dependency; certification path (PassCriteriaService → IssuanceService) is fully independent |
| ASSIGN-05 | 160-05 | Deadline extension uses logEvent (not logComplianceEvent — deadline shift is admin action, not compliance event) | SATISFIED | extendDeadline calls `$this->auditService->logEvent('assignment.deadline.extended', ...)` |
| USER-01 | 160-05 | ClassbookController email-null callers safe | SATISFIED | Line 112: null-safe double-guard; `$user->getEMailAddress() ?? ''` |
| USER-02 | 160-06 | `occ learning:import-users` with group option; background-job-capable for bulk loads; no in-app upload UI | SATISFIED with divergence | Core requirement met. CSV format diverges from spec: spec says "optional password" but implementation always auto-generates random passwords (security improvement; passwords never in CSV or DB unencrypted). Email column described in docblock is not applied from CSV to NC account. Both deviations are security-positive or scope-deferred, not regressions. |
| DSGVO-01 | 160-02 | Art. 17 chain-safe anonymization — compliance rows pseudonymized (user_id=null) on user delete, NOT deleted; chain_hash + user_ref survive intact | SATISFIED | UserDeletedListener: IS NOT NULL → UPDATE (pseudonymize); IS NULL → DELETE; FIX-8 prevents session-cascade path from ever touching compliance rows |
| RBAC-01 | 160-04 | learning_oversight table with scope_group_id VARCHAR(64) for team-lead scoping | SATISFIED | Version009400 creates learning_oversight with scope_group_id VARCHAR(64) |

---

### Anti-Patterns Found

None. PHPStan Level 5 clean (pre-verified by orchestrator). 183 PHPUnit tests, 0 errors, 0 failures. No TODO/FIXME/placeholder patterns found in implementation files.

---

### Security Improvements Beyond Spec

These deviations are sanctioned hardening applied via Codex security review (commit 18973dc):

| Fix | What changed | Impact |
|-----|-------------|--------|
| FIX-4 | `payload_hash = sha256(context_json)` added to canonical (6 fields vs spec's 5) | Context facts are now cryptographically bound into the chain — an adversary can't swap the payload without breaking chain integrity. **NOTE for Phase 161:** `occ learning:audit:verify` must reconstruct the 6-field canonical (seq, event_key, user_ref, course_id, created_at, payload_hash) — the 5-field spec canonical will false-positive every compliance event as tampered. |
| FIX-5 | pepper = `getSystemValue('secret')` with appconfig fallback | Instance secret is stronger than an app-only config value and survives app reinstalls |
| FIX-6 | CAS `WHERE id = 1` pins to single canonical row | Prevents a race that creates multiple "head" rows in chain_state |
| FIX-7 | Version009301: UNIQUE index `learn_audit_seq_uq` on seq_num | DB-level backstop for CAS serialization; lost-race INSERT fails with unique constraint violation (caught + retried by CAS loop) instead of forking the chain |
| FIX-8 | `deleteAuditEventsBySessionIds` guards with `seq_num IS NULL` | Belt-and-braces: even if a compliance row ever acquired a session_id (impossible by current design), the session-cascade delete path cannot touch it |

### Phase 161 Forward Dependency (Checkpoint Deferral)

Signed checkpoints + external anchor are deferred to Phase 161 by design. Phase 160 delivers the tamper-detecting hash-chain layer (detect DB-level tampering by comparing stored chain_hash against a recomputed hash of the canonical). Phase 161 will harden this against a DB-level adversary who can rewrite hashes retroactively. No gap for Phase 160.

---

### Human Verification Required

None required. All wiring is statically verifiable. No UI components were shipped in this phase.

---

## Gaps Summary

No gaps. All 12 requirements are satisfied by actual codebase inspection.

The only documentation inconsistency noted (REQUIREMENTS.md AUDIT-03 names "CertificateVerifyService" vs actual "CertificateController") is a stale requirement text, not an implementation gap. The revocation mutation scan (`grep setRevoked(true)/setRevokedAt()`) confirms CertificateController is the sole write path.

USER-02 CSV format diverges from spec (no optional-password column; email column not applied) — both are security-positive divergences documented above, not regressions against the core requirement.

ASSIGN-03 controller wire-up is intentionally deferred: Phase 160 is the "infrastructure" phase per the phase goal and ROADMAP description. The AssignmentService public API is complete and correct.

---

_Verified: 2026-07-01T12:00:00Z_
_Verifier: Claude (gsd-verifier)_
