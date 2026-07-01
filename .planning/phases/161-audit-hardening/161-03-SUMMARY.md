---
phase: 161-audit-hardening
plan: 03
subsystem: audit
tags: [occ-command, ed25519, sodium, hash-chain, verification, compliance, dsgvo]

# Dependency graph
requires:
  - phase: 161-audit-hardening
    plan: 01
    provides: "learning_audit_checkpoints (signed_payload verbatim, signature hex, head_hash, key_id, anchor_url)"
  - phase: 160-foundation
    provides: "learning_audit_events compliance hash-chain (seq_num, chain_hash, prev_hash, user_ref, context_json)"
  - phase: 155-certification
    provides: "CertKeyMapper::findByKeyId(), CertKey::getPublicKeyB64u() (base64url Ed25519 pubkey)"
provides:
  - "occ learning:audit:verify — independent chain + checkpoint + anchor verification (AUDIT-06)"
  - "AuditVerifyCommand (occ-registered): exit 0 clean / exit 1 on any integrity failure"

affects: [161-04, audit-fork-runbook, compliance-audit-dashboard]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Verbatim 6-field canonical reconstruction (byte-identical to AuditService::logComplianceEvent) — the single source of truth for chain re-verification"
    - "Checkpoint sig verify: base64url pubkey (sodium_base642bin) + hex signature (hex2bin) — asymmetric encodings by column"
    - "hash_equals() for all hash comparisons (timing-safe)"
    - "Warning-only anchor consistency: network failure never touches the exit code"

key-files:
  created:
    - app/lib/Command/AuditVerifyCommand.php
    - app/tests/Unit/Command/AuditVerifyCommandTest.php
  modified:
    - app/appinfo/info.xml
    - app/lib/AppInfo/Application.php
    - app/tests/Support/PhpUnitStubs.php

key-decisions:
  - "IClientService injected as a 3rd ctor arg (plan's 2-arg DI snippet was incomplete for the anchor phase the same plan specifies) — Rule 3 plan-bug fix"
  - "--from-seq > 1 seeds the chain boundary from the window's first stored prev_hash (full audit still starts at genesis zeros) — prevents a false tamper alert on partial audits"
  - "Public key decoded via sodium_base642bin (base64url), signature via hex2bin — matches how 161-01 stored each"

patterns-established:
  - "occ audit:verify reconstructs the canonical independently of the writer so an auditor need not trust app logic"

requirements-completed: [AUDIT-06]

# Metrics
duration: 25min
completed: 2026-07-01
---

# Phase 161 Plan 03: occ learning:audit:verify Summary

**`occ learning:audit:verify` — an independent verifier that re-walks the Phase-160 compliance hash chain with the verbatim frozen 6-field canonical, verifies each Ed25519 checkpoint signature (base64url pubkey via `CertKeyMapper`, hex signature) and head-hash consistency, and best-effort-checks Forgejo anchors; exits 0 on an intact chain and 1 on any integrity failure, printing the fork-resolution runbook path.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-07-01
- **Tasks:** 2
- **Files created:** 2
- **Files modified:** 3

## Accomplishments

- **`AuditVerifyCommand`** with three verification phases:
  - **PHASE 1 — chain walk:** reconstructs each event's `chain_hash` from the FROZEN 6-field canonical copied verbatim from `AuditService::logComplianceEvent` (`seq/event_key/user_ref/course_id/created_at/payload_hash` → `ksort` → `json_encode(UNESCAPED_UNICODE|UNESCAPED_SLASHES|THROW)` → `sha256(canonical.'|'.prevHash)`). `user_ref` is read from the column (never recomputed from `user_id`), `payload_hash` is over the RAW stored `context_json` — both keep DSGVO-erased rows valid.
  - **PHASE 2 — checkpoint signatures:** resolves the public key via `CertKeyMapper::findByKeyId()` (unknown key_id = reportable failure), decodes the base64url pubkey with `sodium_base642bin(...URLSAFE_NO_PADDING)` and the hex signature with `hex2bin`, verifies with `sodium_crypto_sign_verify_detached`, then confirms `head_hash` equals the `chain_hash` of the `to_event_id` row.
  - **PHASE 3 — anchor consistency (warning-only):** if `anchor_url` is set, fetches it (5s timeout) and compares the anchored bytes to `signed_payload`; any network/mismatch issue is a WARNING that never changes the exit code.
- **Flags:** `--json` (machine-readable result object), `--from-seq N` (partial audit), `--fork-runbook` (prints the runbook path and exits 0).
- **Exit contract:** `Command::SUCCESS` when `failures === []`, else `Command::FAILURE` with each failure line + `Fork resolution runbook: docs/audit-fork-runbook.md`.
- **Registration:** `info.xml <commands>` + `Application::register()` DI factory (`IDBConnection`, `CertKeyMapper`, `IClientService`).
- **Tests (9 cases — the 6 required plus 3 extras):** clean chain+checkpoint, tampered event (reports `seq_num=2`), DSGVO-erased row (no false positive), invalid checkpoint signature, unknown key_id, any-failure-carries-runbook, `--fork-runbook` flag, and `--json` clean/tampered. Checkpoint tests use a REAL Ed25519 keypair with a base64url pubkey round-trip so the crypto is exercised end-to-end.

## Task Commits

Each task committed atomically:

1. **Task 1: AuditVerifyCommand + registration + HTTP client stubs** — `85eb075` (feat)
2. **Task 2: PHPUnit tests + getBody PHPStan hardening** — `7aea2c3` (test)

_Per project override, TDD test files were written alongside production code (no local PHP binary to run RED/GREEN separately; the orchestrator runs PHPStan L5 + PHPUnit + the live `occ learning:audit:verify` centrally in the devcloud container)._

## Files Created/Modified

- `app/lib/Command/AuditVerifyCommand.php` — the verifier (3 phases, 3 flags).
- `app/tests/Unit/Command/AuditVerifyCommandTest.php` — 10 cases + `CapturingOutput` helper + self-consistent chain builder.
- `app/appinfo/info.xml` — `<command>OCA\Learning\Command\AuditVerifyCommand</command>`.
- `app/lib/AppInfo/Application.php` — import + DI factory (3 injected deps).
- `app/tests/Support/PhpUnitStubs.php` — added idempotent `OCP\Http\Client\{IClientService, IClient, IResponse}` stubs.

## Decisions Made

- **IClientService injected (3rd arg).** The plan's DI snippet listed only `IDBConnection` + `CertKeyMapper`, but the same plan's PHASE 3 behavior requires an HTTP fetch. Injecting the client (and updating the factory) is a Rule-3 plan-bug fix, not scope creep — the executor success criteria explicitly list "anchor consistency (warning-only)".
- **`--from-seq > 1` boundary seed.** The plan pseudocode unconditionally seeded `prevHash = '0'×64`; for a partial audit that false-flags the window's first row as tampered (its real predecessor is `seq(from-1)`, not genesis). Fix: full audit (`fromSeq == 1`) starts at genesis zeros; partial audit adopts the window's first stored `prev_hash` as a trusted boundary, then advances normally.
- **Asymmetric encodings honored:** pubkey column is base64url (`sodium_base642bin`), signature column is hex (`hex2bin`) — decoding them the same way would corrupt one.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Injected `IClientService` for the anchor phase (plan DI snippet incomplete)**
- **Found during:** Task 1
- **Issue:** PHASE 3 (anchor consistency) needs an HTTP GET, but the plan's registration snippet injected only `IDBConnection` + `CertKeyMapper`.
- **Fix:** Added `IClientService` as a 3rd constructor arg and passed `$container->get(IClientService::class)` in the `Application.php` factory; added idempotent `OCP\Http\Client\*` stubs to `PhpUnitStubs`.
- **Files modified:** `AuditVerifyCommand.php`, `Application.php`, `PhpUnitStubs.php`
- **Commit:** `85eb075`

**2. [Rule 1 - Bug] `--from-seq > 1` false-positived the window's first row**
- **Found during:** Task 1
- **Issue:** Genesis-seeded `prevHash` is wrong for a partial audit — the first in-window row's true predecessor is `seq(from-1)`.
- **Fix:** Seed `prevHash` from the first fetched row's stored `prev_hash` column when `fromSeq > 1`, else genesis zeros.
- **Files modified:** `AuditVerifyCommand.php`
- **Commit:** `85eb075`

**3. [Rule 1 - Bug] `IResponse::getBody()` returns `string|resource` (PHPStan L5)**
- **Found during:** Task 2
- **Issue:** A direct `(string)` cast of a possible resource would fail PHPStan L5.
- **Fix:** `is_string()` guard + `stream_get_contents()` fallback.
- **Files modified:** `AuditVerifyCommand.php`
- **Commit:** `7aea2c3`

**Note (test-dir case):** the plan frontmatter wrote `app/tests/unit/Command/...`; the repo convention is `app/tests/Unit/Command/...` (capital U, namespace `OCA\Learning\Tests\Unit\Command`). Used the real case to match the autoloader/PHPUnit config (same as 161-01).

---

**Total deviations:** 3 auto-fixed (2 bugs, 1 blocking) + 1 path-case correction. No scope creep.

## Security hardening (Codex review)

An adversarial Codex security review of the built Phase-161 audit surface produced verified findings that were applied post-plan as atomic `fix(161-03-sec)` commits (write-side hardening on `AuditCheckpointService`, plan 01/02, is folded in here for traceability):

- **F1 [BLOCKER] — prev_hash tamper.** `AuditVerifyCommand` chain walk now also asserts the stored `prev_hash` column equals the expected in-memory previous hash (`hash_equals`). A prev_hash-only edit left the recomputed `chain_hash` self-consistent and slipped through before. Test: `testTamperedPrevHashOnlyDetected`. Commit `730261f`.
- **F2 [HIGH] — bind signature to columns.** After the Ed25519 signature verifies, the verified `signed_payload` is re-parsed and each signed field (`from/to/head_hash/event_count/signed_at/key_id`) is asserted equal to the stored column (mismatch = FAILURE). Adds `event_count == to−from+1`, cross-checkpoint window-gap warn, and an "events exist but no checkpoint verified → unanchored" warning line. Tests: signed-head_hash mismatch, wrong event_count, events-without-checkpoint. Commit `6509360`.
- **F3 [HIGH] — zero secret on all paths.** Signing in `createCheckpoint` (and `AuditExportController::signJsonl`) wrapped in try/finally; the raw secret and the `material['secret']` slot are both `sodium_memzero`d even when the invalid-length guard throws. Commit `c03cb03`.
- **F4 [HIGH] — anchor raw bytes.** Anchor stores Forgejo `content.download_url` (raw file bytes), not `content.html_url`; the verifier fetches it and `hash_equals`-compares the exact bytes to `signed_payload`. 161-02 anchor test updated; verify-side match/mismatch tests added. Commit `411adbe`.
- **F6 [MEDIUM/SSRF] — https-only anchor.** Store-side POST and verify-side GET both require an `https://` anchor URL (soft-skip + warning otherwise); full host allowlist deferred via `TODO(anchor-enablement)`. Commit `eeb4965`.
- **F7 [LOW] — pubkey length guard.** `strlen(pubKeyRaw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES` asserted before verify, and the verify wrapped in `try/catch (\SodiumException)` → recorded failure, never fatal. Test: 16-byte pubkey. Commit `c3c75cd`.

No local PHP/PHPStan/PHPUnit run (project override) — the orchestrator runs the central gates.

## Issues Encountered

None beyond the deviations above. Per project override, no PHP/PHPStan/PHPUnit/occ/deploy executed locally (no local PHP binary).

## Verification Status (deferred to orchestrator)

The following central gates are pending (orchestrator runs them in the devcloud container):

1. `./scripts/deploy-prod.sh --test` — PHPStan L5 clean + full PHPUnit suite green (incl. the 9 new AuditVerifyCommand cases).
2. `ssh relais 'docker exec -u www-data devcloud-app php occ learning:audit:verify'` — exits 0 on the live chain, prints "✓ Chain intact (N events, M checkpoints verified)".
3. DSGVO-erased row test passes (no false positive); tampered test reports `seq_num`; any failure carries `docs/audit-fork-runbook.md`.

_Note: `docs/audit-fork-runbook.md` is a printed path string only; the command does not read the file. Authoring the runbook doc is out of scope for 161-03 (later plan/task)._

## Self-Check: PASSED

All claimed artifacts verified on disk and in git:
- FOUND: `app/lib/Command/AuditVerifyCommand.php`
- FOUND: `app/tests/Unit/Command/AuditVerifyCommandTest.php`
- FOUND: `AuditVerifyCommand` in `app/appinfo/info.xml` + `Application.php` (import + DI factory)
- FOUND: `OCP\Http\Client\IClientService` stub in `PhpUnitStubs.php`
- FOUND commit: `85eb075` (Task 1 — command + registration + stubs)
- FOUND commit: `7aea2c3` (Task 2 — tests + hardening)

---
*Phase: 161-audit-hardening*
*Completed: 2026-07-01*
