---
phase: 160-foundation-audit-assignment
plan: "02"
subsystem: audit
tags: [php, audit-chain, hash-chain, hmac, cas, dsgvo, art17, tdd, green-phase]

requires:
  - 160-01 (Version009300 migration + AuditServiceTest RED stubs + FakeDbConnection transaction support)

provides:
  - "ComplianceEventTypes.php: 4 compliance event type constants"
  - "AuditService::logComplianceEvent(): CAS hash-chain serialization, hash_hmac pepper, ksorted canonical, exceptions propagate"
  - "UserDeletedListener: DSGVO Art.17(3)(b) chain-safe erasure — compliance rows pseudonymized, non-compliance rows deleted"

affects:
  - 160-03 (AUDIT-03: migrate 3 callers to logComplianceEvent — these callers now have the implementation to call)
  - 161 (audit hardening reads chain_state and chain columns established in 160-01 and populated by logComplianceEvent)
  - 162 (VideoProgressService emits VIDEO_COMPLETED — constant now defined)
  - Phase 164 (Re-Zert retention period relies on chain integrity surviving Art.17 erasure)

tech-stack:
  added: []
  patterns:
    - "CAS optimistic locking: UPDATE learning_audit_chain_state WHERE last_seq=:expected — 0 affected rows = retry (max 3); no forUpdate() needed"
    - "hash_hmac('sha256', userId, pepper) as user_ref — pepper from appconfig, generated once via ISecureRandom if absent; stable forever"
    - "ksort canonical before json_encode — ensures stable key order regardless of PHP array insertion order"
    - "Explicit '|' domain separator between canonical JSON and prevHash — prevents length-extension edge cases"
    - "DSGVO Art.17(3)(b) partition: UPDATE (null user_id WHERE seq_num IS NOT NULL) + DELETE (WHERE seq_num IS NULL) — single user_id=:uid scan"
    - "IQueryBuilder::PARAM_NULL for null UPDATE (not PDO::PARAM_NULL) — NC OCP convention"

key-files:
  created:
    - app/lib/Service/ComplianceEventTypes.php
  modified:
    - app/lib/Service/AuditService.php
    - app/lib/Listener/UserDeletedListener.php
    - app/tests/Unit/Service/AuditServiceTest.php

key-decisions:
  - "IConfig + ISecureRandom added as required constructor params (not nullable): NC DI auto-wires both; required params are PHPStan-clean without null-guards"
  - "DSGVO-01 uses IQueryBuilder::PARAM_NULL (not PDO::PARAM_NULL): matches existing NC OCP convention for null updates; IQueryBuilder import was already present on line 6"
  - "logEvent() left 100% unchanged: different error contract (swallows) must be preserved; only constructor signature changed above it"
  - "3 existing logEvent tests updated to 4-arg constructor: prerequisite for 11/11 GREEN (Rule 3 deviation — enabling fix)"

requirements-completed: [AUDIT-02, DSGVO-01]

duration: ~22min
completed: 2026-07-01
---

# Phase 160 Plan 02: ComplianceEventTypes + logComplianceEvent() + DSGVO-01 Summary

**ksorted hash_hmac CAS chain implementation (GREEN phase for 160-01 RED stubs) + Art.17(3)(b) chain-safe user erasure in UserDeletedListener**

## Performance

- **Duration:** ~22 min
- **Completed:** 2026-07-01
- **Tasks:** 2
- **Files modified:** 4 (3 modified, 1 created)

## Accomplishments

- **ComplianceEventTypes.php** created with 4 constants: `COURSE_PASSED`, `CERT_ISSUED`, `CERT_REVOKED`, `VIDEO_COMPLETED`. Static-only class (private constructor).

- **AuditService::logComplianceEvent()** implemented as the GREEN phase for all 8 RED stubs from 160-01:
  - Constructor extended with `IConfig $config` + `ISecureRandom $secureRandom` (NC DI auto-wires)
  - `getUserRefPepper()`: reads `audit_user_ref_pepper` from appconfig; generates 32-char random value via `ISecureRandom` once on first call; stable forever
  - Canonical: ksorted associative array `{seq, event_key, user_ref, course_id, created_at}` — no PII, no `user_id`
  - `user_ref = hash_hmac('sha256', $userId, $pepper)` — rainbow-table-resistant pseudonym
  - `chain_hash = hash('sha256', $canonical . '|' . $prevHash)` — explicit domain separator
  - CAS retry loop (max 3): `UPDATE learning_audit_chain_state SET last_seq=:new, last_hash=:h WHERE last_seq=:expected`; 0 affected rows = rollback + retry
  - All exceptions propagate (no `catch` that swallows) — failed compliance write is a hard error
  - After 3 CAS failures: `throw new \RuntimeException('logComplianceEvent: could not acquire chain slot after 3 attempts')`

- **UserDeletedListener DSGVO-01** (Art. 17 chain-safe erasure):
  - `'learning_audit_events'` removed from bulk-delete foreach array
  - Added `UPDATE learning_audit_events SET user_id=NULL WHERE user_id=:uid AND seq_num IS NOT NULL` — pseudonymizes compliance rows (chain_hash + user_ref immutable, chain stays verifiable)
  - Added `DELETE FROM learning_audit_events WHERE user_id=:uid AND seq_num IS NULL` — removes non-compliance rows (no Art.17(3)(b) retention basis)
  - Session-based delete on line 41 (`deleteByIds('learning_audit_events', 'session_id', $sessionIds)`) left unchanged — compliance events have `session_id=NULL` and are unaffected

## Task Commits

1. **Task 1: ComplianceEventTypes + AuditService::logComplianceEvent() — GREEN phase** — `c78a44a`
2. **Task 2: DSGVO-01 — UserDeletedListener Art.17 chain-safe erasure** — `803f82d`

## Files Created/Modified

- `app/lib/Service/ComplianceEventTypes.php` — 4 compliance event type constants
- `app/lib/Service/AuditService.php` — extended constructor (IConfig + ISecureRandom), getUserRefPepper(), logComplianceEvent()
- `app/lib/Listener/UserDeletedListener.php` — learning_audit_events removed from bulk-delete; DSGVO-01 partition block added
- `app/tests/Unit/Service/AuditServiceTest.php` — 3 existing logEvent test constructors fixed to 4-arg signature

## Decisions Made

- **Required constructor params (not nullable)**: `IConfig` and `ISecureRandom` are required, not `?IConfig = null`. NC DI auto-wires both from the server container. Making them nullable would require null-guards in `getUserRefPepper()` to satisfy PHPStan L5 — unnecessary complexity since production always has both.

- **`IQueryBuilder::PARAM_NULL` for null UPDATE**: The plan specifies this and the import was already on line 6. The existing `setColumnToNull()` private method uses `\PDO::PARAM_NULL` — the new inline code uses the NC OCP constant, which is the correct pattern for new code.

- **`logEvent()` unchanged**: The existing 3-test contract (wraps all exceptions, no IConfig/ISecureRandom usage) is preserved exactly. Only the constructor above it changed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Enabling] Fixed 3 existing logEvent tests to use 4-arg constructor**
- **Found during:** Task 1 implementation
- **Issue:** `testLogEventInsertsCorrectValues`, `testLogEventDoesNotThrowOnDbError`, `testLogEventWithEmptyContext` all call `new AuditService($db, $logger)` — 2 args. Adding `IConfig` and `ISecureRandom` as required params causes `ArgumentCountError` on those 3 tests. "All 11 green" done-criterion requires all 3 to pass.
- **Fix:** Added `$config = $this->createMock(\OCP\IConfig::class)` and `$secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class)` to each of the 3 tests. The new params are not called by `logEvent()` so the mocks have no behavior stubs — just satisfying the constructor signature.
- **Files modified:** `app/tests/Unit/Service/AuditServiceTest.php`
- **Committed in:** `c78a44a` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule 3 — enabling fix; no scope creep)

## Container Verification

**Deferred to orchestrator central Gate-1** (per track isolation rules — Track A and Track B run concurrently; container PHPStan L5 + PHPUnit run centrally after the wave).

Expected Gate-1 results (pending):

| Check | Expected Result |
|-------|----------------|
| PHPStan L5 on `lib/Service/ComplianceEventTypes.php` | PASS — no deps, pure constants |
| PHPStan L5 on `lib/Service/AuditService.php` | PASS — all types declared; `(int)$qb3->...->executeStatement()` cast ensures `int` return for CAS check |
| PHPStan L5 on `lib/Listener/UserDeletedListener.php` | PASS — `IQueryBuilder::PARAM_NULL` defined in real NC OCP; `isNotNull` on `string` column is valid |
| PHPUnit `AuditServiceTest` — 3 existing logEvent tests | PASS (GREEN) |
| PHPUnit `AuditServiceTest` — 8 logComplianceEvent stubs | PASS (GREEN — implementation satisfies contract) |
| PHPUnit `AuditServiceTest` total | 11/11 PASS |

## Next Phase Readiness

- 160-03 (AUDIT-03) can now migrate the 3 callers (`PassCriteriaService`, `IssuanceService`, revoke writer) to `logComplianceEvent()`. The implementation they call is complete.
- `ComplianceEventTypes::COURSE_PASSED` / `CERT_ISSUED` / `CERT_REVOKED` constants are available for caller migration.
- DSGVO-01 is fully wired — user deletion on devcloud will no longer erase compliance chain entries.

---
*Phase: 160-foundation-audit-assignment*
*Completed: 2026-07-01*
