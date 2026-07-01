---
phase: 161-audit-hardening
plan: 01
subsystem: audit
tags: [ed25519, sodium, checkpoints, hash-chain, background-job, migration, compliance]

# Dependency graph
requires:
  - phase: 160-foundation
    provides: "learning_audit_events compliance hash-chain (seq_num, chain_hash) + learning_audit_chain_state"
  - phase: 155-certification
    provides: "KeyService (getActiveSigningMaterial, hostDid), CertKey.getKeyId(), Ed25519 issuer key"
provides:
  - "learning_audit_checkpoints table (10 cols, 2 indexes) via Version009302"
  - "AuditCheckpointService: Ed25519 checkpoint signing over the chain + getLivenessStatus()"
  - "AuditCheckpointJob: weekly TimedJob (604800s), fault-isolated, registered in Application::boot()"

affects: [161-02, 161-03, 161-04, audit-verify, forgejo-anchor, liveness-banner]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Direct sodium_crypto_sign_detached() signing (NOT SigningService — its typ:vc+jwt header is frozen by ADR-155-ANCHOR)"
    - "Verbatim signed_payload persisted so external verifiers re-check exact bytes without canonical reconstruction"
    - "Fault-isolated TimedJob: run() swallows all Throwable, missed cycles surfaced via is_overdue"

key-files:
  created:
    - app/lib/Migration/Version009302Date20260701140000.php
    - app/lib/Service/AuditCheckpointService.php
    - app/lib/BackgroundJob/AuditCheckpointJob.php
    - app/tests/Unit/Service/AuditCheckpointServiceTest.php
    - app/tests/Unit/BackgroundJob/AuditCheckpointJobTest.php
  modified:
    - app/lib/AppInfo/Application.php
    - app/tests/Support/PhpUnitStubs.php

key-decisions:
  - "Chain tail read via ORDER BY seq_num DESC LIMIT 1 — one row yields both MAX(seq_num) and its chain_hash (head_hash)"
  - "from_event_id = last checkpoint to_event_id + 1 — contiguous, non-overlapping windows"
  - "anchor_url nullable, written NULL now; populated later by AUDIT-05 (Forgejo anchor)"

patterns-established:
  - "AuditCheckpointService signs with sodium directly and memzeroes the secret immediately after"
  - "Liveness (is_overdue >8d) drives admin nudges instead of throwing from the scheduler"

requirements-completed: [AUDIT-04]

# Metrics
duration: 20min
completed: 2026-07-01
---

# Phase 161 Plan 01: Audit Checkpoints Foundation Summary

**Ed25519-signed weekly checkpoints over the Phase-160 compliance hash chain: new `learning_audit_checkpoints` table, `AuditCheckpointService` (sodium-direct sign + liveness), and a fault-isolated weekly `AuditCheckpointJob` wired into boot.**

## Performance

- **Duration:** ~20 min
- **Completed:** 2026-07-01T15:40Z
- **Tasks:** 2
- **Files created:** 5
- **Files modified:** 2

## Accomplishments
- `learning_audit_checkpoints` migration (Version009302): 10 columns incl. TEXT `signed_payload` + nullable `anchor_url`, 2 indexes (both ≤27 chars), idempotent `hasTable` guard, PG16 + MariaDB 11.4 safe.
- `AuditCheckpointService::createCheckpoint()`: reads the chain tail, signs a deterministic no-PII payload with `sodium_crypto_sign_detached` (never `SigningService`), memzeroes the secret, stores the verbatim signed bytes, records liveness pointers, and skips cleanly when there are no new events.
- `AuditCheckpointService::getLivenessStatus()`: freshness, unanchored-event count, anchor status, `is_overdue` (>8 days).
- `AuditCheckpointJob` (weekly `TimedJob`, 604800s) swallowing all `Throwable`, registered in `Application::boot()` inside the existing `has()` guard.
- Tests: real-keypair **sign→verify roundtrip** over the verbatim `signed_payload`, window continuation/skip cases, liveness overdue/fresh, and job delegate + exception-swallow.

## Task Commits

Each task committed atomically:

1. **Task 1: Version009302 migration — learning_audit_checkpoints** — `c094774` (feat)
2. **Task 2: AuditCheckpointService + AuditCheckpointJob + boot wiring + tests** — `0207dea` (feat)

_Note: per project override, TDD test files were written alongside production code in the same task commit (no local PHP to run RED/GREEN separately; the orchestrator runs PHPStan L5 + PHPUnit centrally in the devcloud container)._

## Files Created/Modified
- `app/lib/Migration/Version009302Date20260701140000.php` — creates `learning_audit_checkpoints` (10 cols, 2 indexes).
- `app/lib/Service/AuditCheckpointService.php` — Ed25519 checkpoint signing + DB write + liveness.
- `app/lib/BackgroundJob/AuditCheckpointJob.php` — weekly TimedJob trigger.
- `app/lib/AppInfo/Application.php` — import + `boot()` registration of `AuditCheckpointJob`.
- `app/tests/Support/PhpUnitStubs.php` — added `OCP\BackgroundJob\TimedJob` stub (with `setInterval`).
- `app/tests/Unit/Service/AuditCheckpointServiceTest.php` — service tests incl. sign→verify roundtrip.
- `app/tests/Unit/BackgroundJob/AuditCheckpointJobTest.php` — job delegate + Throwable-swallow (reflection on protected `run()`).

## Decisions Made
- **Chain tail in one query:** `SELECT seq_num, chain_hash ... WHERE seq_num IS NOT NULL ORDER BY seq_num DESC LIMIT 1` gives both MAX(seq_num) (`to_event_id`) and its `chain_hash` (`head_hash`) — no separate MAX + lookup.
- **Contiguous windows:** `from_event_id = lastCheckpoint.to_event_id + 1`; count computed over `[from, to]`.
- **anchor_url deferred:** stored NULL now; AUDIT-05 (Forgejo anchor) will populate it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Corrected plan step ordering (fetch signing material before building payload)**
- **Found during:** Task 2 (AuditCheckpointService)
- **Issue:** The plan's action listed the payload build (step 4, using `$material['key']->getKeyId()`) *before* `$material = getActiveSigningMaterial()` (step 5) — an undefined-variable order. It also implied decrypting the key even on the no-new-events skip path.
- **Fix:** Fetch signing material only after the skip checks and before building the payload; capture `$now = time()` once so the signed `signed_at`, the `signed_at` column, and the config pointer all agree.
- **Files modified:** `app/lib/Service/AuditCheckpointService.php`
- **Verification:** Roundtrip test reconstructs `key_id`/`signed_at` from the captured payload and columns; they match.
- **Committed in:** `0207dea`

**2. [Rule 3 - Blocking] Added `OCP\BackgroundJob\TimedJob` to PhpUnitStubs**
- **Found during:** Task 2 (AuditCheckpointJob)
- **Issue:** `AuditCheckpointJob extends TimedJob`, but the stub bootstrap (which the orchestrator's PHPUnit run uses) only had `IJobList` + `QueuedJob` from Phase 160 — loading the job or its test would fatal.
- **Fix:** Added a guarded `TimedJob` stub mirroring `QueuedJob` (ITimeFactory ctor) plus `setInterval()`/`getInterval()`.
- **Files modified:** `app/tests/Support/PhpUnitStubs.php`
- **Verification:** Job constructor calls `parent::__construct($time)` then `setInterval(604800)`; stub provides both.
- **Committed in:** `0207dea`

**Note (test-dir case):** Plan frontmatter wrote `app/tests/unit/...`; the repo convention is `app/tests/Unit/...` (capital U) with namespace `OCA\Learning\Tests\Unit\{Service,BackgroundJob}`. Used the real case to match the autoloader/PHPUnit config.

---

**Total deviations:** 2 auto-fixed (1 bug, 1 blocking) + 1 path-case correction.
**Impact on plan:** All necessary for correctness/compilability. No scope creep — no extra features added (unplanned `is_overdue` guard was reverted to match the plan's exact formula).

## Issues Encountered
None beyond the deviations above. No PHP/PHPStan/PHPUnit executed locally per project override (no local PHP binary; orchestrator runs Gate 1 + migration centrally in devcloud).

## User Setup Required
None — the migration and job register automatically. Orchestrator applies `occ upgrade` centrally.

## Next Phase Readiness
- Table + service + job foundation ready for 161-02+ (audit:verify CLI over checkpoints, Forgejo anchoring via `anchor_url`, liveness banner via `getLivenessStatus()`).
- Pending central gates (orchestrator): PHPStan L5, full PHPUnit suite incl. the new sign→verify roundtrip, `occ upgrade` applying Version009302 on PG16 (MariaDB cross-check).

## Self-Check: PASSED

All claimed artifacts verified on disk and in git:
- FOUND: `app/lib/Migration/Version009302Date20260701140000.php`
- FOUND: `app/lib/Service/AuditCheckpointService.php`
- FOUND: `app/lib/BackgroundJob/AuditCheckpointJob.php`
- FOUND: `app/tests/Unit/Service/AuditCheckpointServiceTest.php`
- FOUND: `app/tests/Unit/BackgroundJob/AuditCheckpointJobTest.php`
- FOUND commit: `c094774` (Task 1 — migration)
- FOUND commit: `0207dea` (Task 2 — service + job + wiring + tests)
- `Application.php` contains 3 `AuditCheckpointJob` references (import + has() guard + add())

---
*Phase: 161-audit-hardening*
*Completed: 2026-07-01*
