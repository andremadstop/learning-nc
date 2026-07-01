---
phase: 161-audit-hardening
plan: 02
subsystem: audit
tags: [forgejo, external-anchor, tamper-evidence, soft-fail, checkpoints, compliance]

# Dependency graph
requires:
  - phase: 161-01
    provides: "AuditCheckpointService::createCheckpoint() + learning_audit_checkpoints table (nullable anchor_url)"
provides:
  - "AuditCheckpointService::doForgejoAnchor() — OFF-by-default, soft-fail external anchor into a Forgejo repo"
  - "forgejoPost() protected HTTP seam (file_get_contents-based, mockable)"
  - "appconfig keys: forgejo_anchor_enabled / forgejo_token / forgejo_owner / forgejo_repo / forgejo_base_url / last_anchor_status / last_anchor_attempted_at"

affects: [161-05, admin-settings, liveness-banner, audit-verify]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "OFF-by-default external side effect: no HTTP unless forgejo_anchor_enabled='true' AND token set"
    - "Soft-fail redundancy: checkpoint row is source of truth; anchor failure never rolls back / never rethrows"
    - "Protected forgejoPost() seam so unit tests inject canned {status,body} without a live HTTP call or a new DI dep"

key-files:
  created: []
  modified:
    - app/lib/Service/AuditCheckpointService.php
    - app/tests/Unit/Service/AuditCheckpointServiceTest.php

key-decisions:
  - "HTTP call extracted into protected forgejoPost() (still file_get_contents internally) — a namespaced file_get_contents override cannot populate the caller's magic $http_response_header, and injecting an IClientService dep would force editing Application.php DI (out of this plan's file scope)"
  - "last insert id read via IQueryBuilder::getLastInsertId() (the IDBConnection stub has no lastInsertId())"
  - "Always POST one file per checkpoint (checkpoint-{toEventId}.json) — avoids the PUT SHA-fetch round-trip"

patterns-established:
  - "doForgejoAnchor() is called last in createCheckpoint(), strictly after the INSERT + config pointers + info log"
  - "Forgejo token is read from appconfig only, never hardcoded and never written to a log line"

requirements-completed: [AUDIT-05]

# Metrics
duration: 12min
completed: 2026-07-01
---

# Phase 161 Plan 02: Forgejo External Anchor Scaffold Summary

**`AuditCheckpointService::doForgejoAnchor()` anchors each minted checkpoint into an admin-independent Forgejo repository (POST to the Contents API), OFF by default and soft-failing so a network/API error can never roll back or block a checkpoint — protecting against an admin who controls both the DB and the signing key.**

## Performance

- **Duration:** ~12 min
- **Completed:** 2026-07-01
- **Tasks:** 1
- **Files modified:** 2

## Accomplishments
- `doForgejoAnchor(int $checkpointId, int $toEventId, string $signedPayload): ?string` added to `AuditCheckpointService`:
  - **OFF by default** — returns immediately (no HTTP, no state change) unless `forgejo_anchor_enabled = 'true'` AND `forgejo_token` is non-empty.
  - **POST** to `{base_url}/api/v1/repos/{owner}/{repo}/contents/audit-checkpoints/checkpoint-{toEventId}.json` with body `{message, content=base64(signed_payload)}` (always POST — one file per checkpoint, no PUT/SHA round-trip).
  - **HTTP 201** → parses `.content.html_url`, `UPDATE`s `anchor_url` on the checkpoint row (by id), records `last_anchor_status='ok'` + `last_anchor_attempted_at`.
  - **Non-201 / exception** → logs a warning (token deliberately absent), records `last_anchor_status='failed'`, keeps the checkpoint row intact with `anchor_url = NULL`, and never rethrows.
- Wired into `createCheckpoint()` as the final step, strictly after the INSERT + config pointers, using `$insertQb->getLastInsertId()`.
- Added `forgejoPost()` protected seam (uses `file_get_contents` internally, no new deps) that returns `{status, body}` — the sole point unit tests override.
- Added config key `forgejo_base_url` (default `https://git.andrestiebitz.de`).
- Tests: OFF-by-default (both the `enabled='false'` and the `enabled='true' + empty token` branches, asserting **zero** HTTP calls), 201-success (URL written to `anchor_url`, `UPDATE` targets the new id, status `ok`), exception soft-fail (status `failed`, no `UPDATE`, no exception escapes), and HTTP-500 soft-fail.

## Task Commits

1. **Task 1: Forgejo anchor method + wiring + tests** — `2972d3c` (feat)

_Per project override, the TDD test file was written alongside production code in the same commit (no local PHP binary to run RED/GREEN separately; the orchestrator runs PHPStan L5 + PHPUnit centrally after the wave)._

## Files Created/Modified
- `app/lib/Service/AuditCheckpointService.php` — `doForgejoAnchor()` + `forgejoPost()` seam + `createCheckpoint()` wiring (`getLastInsertId()` → anchor).
- `app/tests/Unit/Service/AuditCheckpointServiceTest.php` — 5 anchor tests + `TestableAuditCheckpointService` double exposing the `forgejoPost()` seam.

## Decisions Made
- **Testable HTTP seam over literal in-method file_get_contents:** the plan's inline `@file_get_contents` + magic `$http_response_header` cannot be exercised in a unit test — a namespaced-function override does not populate the caller's `$http_response_header`, and a real `IClientService` dependency would force editing `Application.php` DI (outside this plan's two-file scope). Extracting a `protected forgejoPost()` that still uses `file_get_contents` internally keeps production behaviour and the constructor unchanged while making anchor success/failure fully unit-testable. (Deviation Rule 1/3.)
- **Last insert id via `IQueryBuilder::getLastInsertId()`**, not `IDBConnection::lastInsertId()` — the latter is absent from the OCP stub surface and would break the central PHPStan run.
- **`json_encode(..., JSON_THROW_ON_ERROR)` moved inside the `try`** so a payload-encode failure also soft-fails instead of escaping `createCheckpoint()`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug/Testability] Extracted HTTP call into a protected `forgejoPost()` seam**
- **Found during:** Task 1 (writing the soft-fail tests)
- **Issue:** The plan placed `@file_get_contents` + `$http_response_header` parsing directly inside `doForgejoAnchor()`. That is not unit-testable without a live network call — the magic `$http_response_header` local is populated only by the real built-in in the exact scope, so a namespaced stub cannot drive the 201/500/exception branches.
- **Fix:** Kept the identical logic (stream context, POST, status-line regex) inside a `protected forgejoPost(): array{status,body}`; `doForgejoAnchor()` consumes its result. Tests subclass the service (`TestableAuditCheckpointService`) and override only that seam. No new dependency, no new OCP surface, no DI/Application.php change.
- **Files modified:** `app/lib/Service/AuditCheckpointService.php`, `app/tests/Unit/Service/AuditCheckpointServiceTest.php`
- **Committed in:** `2972d3c`

**2. [Rule 1 - Bug] Guards for PHPStan-blind branch + exception containment**
- **Found during:** Task 1
- **Issue:** The anchor body never runs in tests but is analysed centrally; the plan's `json_encode` sat before the `try`, and `$data['content']['html_url']` assumed `$data` is an array.
- **Fix:** Pre-init `$http_response_header = []`; `is_string($response)` before `json_decode`; `is_array($data)` before the nested access; `is_string($htmlUrl) && $htmlUrl !== ''` before the `UPDATE`; `json_encode(JSON_THROW_ON_ERROR)` moved inside the `try` so nothing escapes.
- **Files modified:** `app/lib/Service/AuditCheckpointService.php`
- **Committed in:** `2972d3c`

**Note (test-dir case):** Plan frontmatter wrote `app/tests/unit/...`; the repo convention is `app/tests/Unit/...` (namespace `OCA\Learning\Tests\Unit\Service`). Used the real case to match the autoloader/PHPUnit config.

---

**Total deviations:** 2 auto-fixed (testability seam + PHPStan/containment guards) + 1 path-case correction. No scope creep — no anchor features beyond the plan (no AdminSettings UI; that is 161-05).

## Issues Encountered
None beyond the deviations above. No PHP/PHPStan/PHPUnit executed locally per project override (no local PHP binary; orchestrator runs Gate 1 + full PHPUnit centrally in the devcloud container after the wave).

## User Setup Required
None for this plan. Operator enablement (later): set `forgejo_anchor_enabled='true'` + `forgejo_token`/`forgejo_owner`/`forgejo_repo` in appconfig (surfaced by 161-05 AdminSettings). Token lives in appconfig only — never in code or logs.

## Next Phase Readiness
- `anchor_url` now populated on success; `last_anchor_status` / `last_anchor_attempted_at` pointers available for the liveness banner and 161-05 AdminSettings.
- Pending central gates (orchestrator): PHPStan L5, full PHPUnit suite (incl. the 5 new anchor tests + all 161-01 tests).

## Self-Check: PASSED

- FOUND: `app/lib/Service/AuditCheckpointService.php` contains `doForgejoAnchor` + `forgejo_anchor_enabled` + `forgejoPost`
- FOUND: `app/tests/Unit/Service/AuditCheckpointServiceTest.php` contains 5 anchor tests + `TestableAuditCheckpointService`
- FOUND commit: `2972d3c` (feat — anchor method + wiring + tests)
- Only the two in-scope files were committed (siblings' changes left unstaged); STATE.md / ROADMAP.md untouched; no PHP/deploy run.

---
*Phase: 161-audit-hardening*
*Completed: 2026-07-01*
