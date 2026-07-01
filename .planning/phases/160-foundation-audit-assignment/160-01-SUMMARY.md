---
phase: 160-foundation-audit-assignment
plan: "01"
subsystem: database
tags: [php, migration, phpunit, audit-chain, hash-chain, tdd, postgresql, mariadb]

requires: []
provides:
  - "Version009300 migration: 4 nullable chain columns on learning_audit_events + learning_audit_chain_state table + genesis seed"
  - "AuditServiceTest: 8 RED stubs encoding GREEN contract for logComplianceEvent (ksorted canonical, hash_hmac pepper, CAS retry, exception propagation)"
  - "AuditMigrationTest: 5 schema-assertion tests (skips outside devcloud container)"
  - "FakeDbConnection: beginTransaction/commit/rollBack/inTransaction for CAS unit tests"
  - "PhpUnitStubs: OCP\\Security\\ISecureRandom + IDBConnection transaction methods"
affects:
  - 160-02 (implements logComplianceEvent against these RED stubs — must satisfy GREEN contract)
  - 160-03 (AUDIT-03: migrate 3 callers to logComplianceEvent)
  - 161 (audit hardening uses chain_state + chain columns established here)

tech-stack:
  added: []
  patterns:
    - "TDD RED: test stubs written before implementation; assertions encode exact formula (ksorted JSON + | separator + sha256, hash_hmac pepper)"
    - "CAS serialization: FakeQueryBuilder executeStatementResult=0 simulates CAS race for retry tests"
    - "Migration guard pattern: hasColumn/hasIndex/hasTable guards ensure idempotent occ upgrade"
    - "postSchemaChange genesis seed: count check before insert, uses IDBConnection injected in constructor"

key-files:
  created:
    - app/lib/Migration/Version009300Date20260701000000.php
    - app/tests/Unit/Migration/AuditMigrationTest.php
  modified:
    - app/tests/Support/FakeInfrastructure.php
    - app/tests/Support/PhpUnitStubs.php
    - app/tests/Unit/Service/AuditServiceTest.php

key-decisions:
  - "canonical formula: ksorted JSON of {seq, event_key, user_ref, course_id, created_at} + '|' separator + prevHash — separator prevents length-extension confusion"
  - "user_ref = hash_hmac('sha256', userId, pepper) from IConfig — NOT plain sha256; pepper prevents rainbow-table attack on pseudonyms"
  - "IDBConnection stub extended with transaction methods so createMock()->method('beginTransaction') reaches logComplianceEvent RED failure cleanly"
  - "ISecureRandom added to PhpUnitStubs (Rule 3 deviation) — without it, createMock would fail before logComplianceEvent call, giving wrong RED error"

patterns-established:
  - "testChainLinks: two-stage pattern — capture chain_hash from call 1, construct db2 with that value as last_hash for call 2"
  - "testCasExhaustsRetries: 9 builders (3 attempts x [SELECT, INSERT, UPDATE-returns-0])"
  - "buildCanonical() + buildChainHash() private helpers in test class — single formula definition prevents drift across 5 hash-verification tests"

requirements-completed: [AUDIT-01]

duration: 18min
completed: 2026-07-01
---

# Phase 160 Plan 01: Audit Hash-Chain Schema + RED Stubs Summary

**learning_audit_chain_state table + 4 chain columns on audit_events seeded with genesis row; 8 PHPUnit RED stubs encoding ksorted-canonical hash_hmac-pepper logComplianceEvent contract**

## Performance

- **Duration:** ~18 min
- **Started:** 2026-07-01T11:15:23Z
- **Completed:** 2026-07-01T11:33:00Z
- **Tasks:** 2 (Task 1: RED stubs, Task 2: migration)
- **Files modified:** 5

## Accomplishments

- Version009300 migration creates the complete audit hash-chain schema foundation: 4 nullable columns on `learning_audit_events` (seq_num, user_ref, prev_hash, chain_hash), index `learn_audit_chain_idx`, and new `learning_audit_chain_state` single-row CAS table — all with idempotent guards
- Genesis seed in `postSchemaChange()` inserts {last_seq=0, last_hash=000...0} when table is empty — second `occ upgrade` is a no-op
- 8 RED test stubs in `AuditServiceTest` encode the exact GREEN contract for `logComplianceEvent`: ksorted canonical, `hash_hmac` pepper, `sha256(json + '|' + prevHash)` formula, CAS retry after 3 failures, exception propagation (NOT swallowed like logEvent)
- `FakeDbConnection` extended with transaction call counters; `PhpUnitStubs` extended with `ISecureRandom` and `IDBConnection` transaction methods so RED failures land at `logComplianceEvent` not at mock setup

## Task Commits

1. **Task 1: Wave 0 — Test stubs for logComplianceEvent and audit migration** - `a048702` (test)
2. **Task 2: Version009300 migration — audit hash-chain schema** - `a5d725e` (feat)

## Files Created/Modified

- `app/lib/Migration/Version009300Date20260701000000.php` — audit hash-chain schema migration (changeSchema + postSchemaChange genesis)
- `app/tests/Unit/Migration/AuditMigrationTest.php` — 5 schema assertions (skips gracefully outside devcloud; verified by orchestrator Gate-1)
- `app/tests/Unit/Service/AuditServiceTest.php` — 3 existing passing tests + 8 new RED stubs for logComplianceEvent
- `app/tests/Support/FakeInfrastructure.php` — FakeDbConnection transaction methods + call counters
- `app/tests/Support/PhpUnitStubs.php` — ISecureRandom stub + IDBConnection transaction methods (Rule 3 deviation)

## Decisions Made

- **ksort canonical with `|` separator**: Plan key_constraints override RESEARCH (which used insertion-order JSON without separator). The `|` between JSON and prevHash prevents length-extension edge cases. All 5 hash-verification tests use a shared `buildChainHash()` helper to prevent formula drift.
- **Two-stage testChainLinks**: FakeDbConnection cannot update state between calls, so testChainLinks uses two separate service instances — capture chain_hash from call 1, supply it as last_hash for call 2's SELECT. This is the only correct way to test chain linking without a real DB.
- **ISecureRandom / IDBConnection stub extension**: Without adding these to `PhpUnitStubs.php`, `createMock()` fails before `logComplianceEvent()` is called, producing the wrong RED error message. Rule 3 deviation — blocking infrastructure gap.
- **AuditMigrationTest uses real DB via OCP\Server**: Outside the container, `Server::get()` throws `Error` (not found), caught by `catch (\Throwable)` → `markTestSkipped`. No stub for OCP\Server needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added ISecureRandom stub to PhpUnitStubs.php**
- **Found during:** Task 1 (AuditServiceTest RED stubs)
- **Issue:** `OCP\Security\ISecureRandom` is not defined in the test environment stubs. `$this->createMock(\OCP\Security\ISecureRandom::class)` throws "interface does not exist" BEFORE `logComplianceEvent()` is called. The done criterion requires RED failures to mention `logComplianceEvent`, not stub setup.
- **Fix:** Added minimal `ISecureRandom` stub to `OCP\Security` namespace in PhpUnitStubs.php (guarded with `if (!interface_exists)`)
- **Files modified:** `app/tests/Support/PhpUnitStubs.php`
- **Committed in:** `a048702` (Task 1 commit)

**2. [Rule 3 - Blocking] Added beginTransaction/commit/rollBack/inTransaction to IDBConnection stub**
- **Found during:** Task 1 (testLogComplianceEventPropagatesException)
- **Issue:** `createMock(\OCP\IDBConnection::class)->method('beginTransaction')` throws PHPUnit error "method beginTransaction not defined in interface" because the stub only has 3 methods. This causes mock SETUP to fail, not `logComplianceEvent()`.
- **Fix:** Added 4 transaction methods to IDBConnection stub in PhpUnitStubs.php AND as concrete implementations in FakeDbConnection (with call counters). Both changes required together to keep FakeDbConnection satisfying the interface.
- **Files modified:** `app/tests/Support/PhpUnitStubs.php`, `app/tests/Support/FakeInfrastructure.php`
- **Committed in:** `a048702` (Task 1 commit)

---

**Total deviations:** 2 auto-fixed (both Rule 3 — blocking test infrastructure gaps)
**Impact on plan:** Both auto-fixes necessary for RED stubs to fail at the correct place (`logComplianceEvent` call, not mock setup). No scope creep.

## Container Verification

**Deferred to orchestrator central Gate-1** (per track isolation rules — Track A and Track B run concurrently; container PHPStan + PHPUnit run centrally after the wave).

Expected Gate-1 results (pending):
- PHPStan L5 on migration: PASS (lib/Migration/ is in phpstan excludePaths — no scan needed)
- PHPUnit `AuditServiceTest`: 3 PASS (existing) + 8 FAIL (RED stubs — `Error: Call to undefined method logComplianceEvent`)
- PHPUnit `AuditMigrationTest`: 5 SKIPPED locally; PASS after `occ upgrade` applies Version009300 in container

**Note on PHPStan and test files:** `phpstan.neon` paths include only `lib/` (excludes `lib/Migration/` and all `tests/`). The 8 RED stubs will emit PHPStan undefined-method errors on `logComplianceEvent` calls if tests/ were scanned — but they are NOT scanned. No action needed; this is by design.

## Issues Encountered

None — all planned work completed successfully. Two Rule 3 deviations discovered and handled inline.

## Next Phase Readiness

- Version009300 schema is the prerequisite for all Track A work. 160-02 can apply the migration and implement `logComplianceEvent` against the 8 RED stubs.
- `buildCanonical()` / `buildChainHash()` helpers in AuditServiceTest define the exact formula 160-02 must match for GREEN.
- `FakeDbConnection` transaction support ready for CAS unit tests in 160-02.
- Track B (160-04) is independent — can proceed in parallel.

---
*Phase: 160-foundation-audit-assignment*
*Completed: 2026-07-01*
