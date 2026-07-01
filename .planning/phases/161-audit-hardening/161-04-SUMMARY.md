---
phase: 161-audit-hardening
plan: 04
subsystem: audit
tags: [ed25519, sodium, jsonl, export, dsgvo, auditor, print, group-gate]

# Dependency graph
requires:
  - phase: 161-audit-hardening
    plan: 01
    provides: "learning_audit_events compliance hash-chain (seq_num, user_ref, chain_hash, context_json)"
  - phase: 155-certification
    provides: "KeyService::getActiveSigningMaterial() — active issuer Ed25519 secret key"
provides:
  - "AuditExportController: GET /audit/export/{page,events,sig,report} — group-gated (@NoAdminRequired + learning-auditors)"
  - "Deterministic JSONL compliance event log (user_ref only, never user_id — DSGVO)"
  - "Detached Ed25519 signature over exact JSONL bytes (signed inline via KeyService, NOT AuditCheckpointService)"
  - "Self-contained printable HTML report (window.print → PDF) with sha256 + sig footer"
  - "Non-admin auditor page (self-contained server-rendered filter UI) + AuditExport.vue"

affects: [161-verify, forgejo-anchor, dpo-workflow]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Group-gated non-admin surface: @NoAdminRequired + IGroupManager::isInGroup($userId, auditor_group) — NOT admin"
    - "assertAuditorOrDie() throws ForbiddenException (app class) → each action catches → JSONResponse 403; DB untouched on deny"
    - "Deterministic JSONL (fixed key order + fixed json_encode flags) so same filters = byte-identical bytes = stable signature"
    - "Detached sig signed inline via KeyService::getActiveSigningMaterial(), secret memzeroed immediately"
    - "Report HTML rendered via ob_start()+include of a plain-PHP template (unit-testable, zero NC-template-engine dep)"

key-files:
  created:
    - app/lib/Controller/AuditExportController.php
    - app/templates/audit-export-print.php
    - app/templates/audit-export-page.php
    - app/src/components/AuditExport.vue
    - app/tests/Unit/Controller/AuditExportControllerTest.php
  modified:
    - app/appinfo/routes.php
    - app/tests/Support/PhpUnitStubs.php  # isInGroup + TemplateResponse stubs (committed via sibling d3e3161 — clean merge)

key-decisions:
  - "ForbiddenException = OCA\\Learning\\Service\\ForbiddenException (codebase convention), NOT the plan's literal \\OCP\\...\\ForbiddenException (absent in this NC build)"
  - "@NoCSRFRequired added to all 4 actions (plan showed only @NoAdminRequired) — GET downloads/page via <a>/navigation carry no requesttoken"
  - "course_id filter is an EXACT PHP post-filter on decoded context_json (not a fragile LIKE)"
  - "Live UI = self-contained PHP form (zero build wiring, per override rule 5); AuditExport.vue is a standalone deliverable validated by central ESLint (not the vite build — orphan file, not in the import graph)"
  - ".sig payload = bin2hex(sig) . \"\\n\" — verifiers MUST trim before hex2bin"

requirements-completed: [AUDIT-07]

# Metrics
duration: 40min
completed: 2026-07-01
---

# Phase 161 Plan 04: Auditor Export Summary

**Group-gated auditor export of the Phase-160 compliance hash-chain — a deterministic JSONL event log, a detached Ed25519 signature over the exact JSONL bytes, and a printable HTML report — reachable by a NON-admin member of the configurable `learning-auditors` group without shell access.**

## Performance

- **Duration:** ~40 min
- **Completed:** 2026-07-01
- **Tasks:** 2 auto tasks (Task 3 human-verify delegated to orchestrator central Gate 2)
- **Files created:** 5
- **Files modified:** 2

## Accomplishments

- **AuditExportController** (`page`/`events`/`sig`/`report`): `@NoAdminRequired` + `@NoCSRFRequired` PHPDoc annotations (never attributes — the 401-regression lesson), `assertAuditorOrDie()` as the FIRST line of every action gating on `IGroupManager::isInGroup($userId, $auditorGroup)` where `$auditorGroup = getAppValue('learning','auditor_group','learning-auditors')`.
- **Deterministic JSONL** (`buildJsonl` = `fetchEvents` + `serializeJsonl`): selects `seq_num, event_key, user_ref, context_json, created_at, chain_hash` from the unprefixed `learning_audit_events WHERE seq_num IS NOT NULL ORDER BY seq_num ASC`; date bounds applied in SQL, `course_id` as an EXACT PHP post-filter on decoded `context_json`; output rows carry `course_id` lifted from context and **never** `user_id` (DSGVO — only the pseudonymous `user_ref`). Fixed key order + `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES` → byte-deterministic.
- **Detached Ed25519 signature** signed INLINE via `KeyService::getActiveSigningMaterial()` (NOT `AuditCheckpointService` — avoids the sibling-plan file conflict), 64-byte key-length guard, `sodium_crypto_sign_detached` over the exact JSONL bytes, `sodium_memzero` immediately after; returned as `hex . "\n"`.
- **Printable HTML report** (`audit-export-print.php`): self-contained `@media print` template (no external resources, all values `htmlspecialchars`-escaped) with an events table + integrity footer embedding `sha256(JSONL)` and the sig hex for cross-reference; rendered via `ob_start()+include` (unit-testable).
- **Non-admin auditor page** (`audit-export-page.php`): group-gated `TemplateResponse('learning','audit-export-page', [...], 'user')` serving a self-contained filter UI (date-from/to + course-id) with three download actions; links carry graceful-degrade `href`s (work even if the nonce'd inline filter script is CSP-blocked).
- **AuditExport.vue**: Options-API component (mirrors existing components) providing the same UI for future SPA integration.
- **Tests** (`AuditExportControllerTest`): non-auditor → 403 on all 4 actions with ZERO DB access (no leak, structurally); JSONL has no `user_id` at decoded-JSON-key level (raw rows deliberately carry `user_id` to prove it is dropped); exact `course_id` filter; real-keypair detached-sig verify against the exact JSONL bytes (trim → `hex2bin` → `sodium_crypto_sign_verify_detached`); report HTML embeds `sha256(JSONL)` and does not leak the raw uid.

## Task Commits

1. **Task 1: AuditExportController + print template + routes + tests** — `b990983` (feat)
2. **Task 2: AuditExport.vue + auditor page template** — `8c5465b` (feat)

_PhpUnitStubs additions (`IGroupManager::isInGroup`, `OCP\AppFramework\Http\TemplateResponse`) landed in the sibling 161-03 commit `d3e3161` via a clean merge (disjoint file regions from the sibling's `getBody` stub) — already durable in HEAD, nothing to re-commit._

## Task 3 — Human-Verify Checkpoint

**Delegated to the orchestrator's central Gate 2** per the autonomous-run mandate. The automatable verification (non-auditor → 403, page reachable for a non-admin group member, `.sig` verifies against the downloaded JSONL, no `user_id` leak) is performed centrally on devcloud after deploy; this executor did NOT block on the checkpoint.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] ForbiddenException class corrected to the codebase convention**
- **Found during:** Task 1
- **Issue:** The plan's interface literally referenced `\OCP\AppFramework\Http\Exceptions\ForbiddenException`, which does not exist in this NC build (grep shows the app uses its own `OCA\Learning\Service\ForbiddenException`, caught → 403, in CertificateReportController/MaintenanceController/SupportTicketController). Copying it verbatim would fatal under central PHPStan/PHPUnit.
- **Fix:** `assertAuditorOrDie()` throws `OCA\Learning\Service\ForbiddenException`; each action catches it → `JSONResponse(['error'=>'Forbidden…'], 403)`.
- **Files:** `app/lib/Controller/AuditExportController.php`
- **Commit:** `b990983`

**2. [Rule 2 - Missing critical annotation] @NoCSRFRequired added to all 4 actions**
- **Found during:** Task 1
- **Issue:** The plan's interface examples showed only `@NoAdminRequired`. The page + downloads are triggered by browser navigation / `<a download>` links (no requesttoken), so the CSRF middleware would reject them. PageController::index is the precedent.
- **Fix:** Added `@NoCSRFRequired` (PHPDoc) alongside `@NoAdminRequired` on `page`/`events`/`sig`/`report`.
- **Files:** `app/lib/Controller/AuditExportController.php`
- **Commit:** `b990983`

**3. [Rule 3 - Blocking] Added IGroupManager::isInGroup + TemplateResponse to PhpUnitStubs**
- **Found during:** Task 1
- **Issue:** The Phase-160 `IGroupManager` stub only had `get()`; `PublicTemplateResponse` was stubbed but plain `TemplateResponse` was absent. The controller test would fatal without both.
- **Fix:** Added `isInGroup(string,string): bool` to the existing IGroupManager block and a guarded `TemplateResponse` class (getParams/getTemplateName/getRenderAs). Additive + guarded, no existing stub changed.
- **Files:** `app/tests/Support/PhpUnitStubs.php` (landed in sibling `d3e3161`, clean merge).

## Security hardening (Codex review)

An adversarial Codex security review of the built audit-export surface produced verified findings applied post-plan as atomic `fix(161-*-sec)` commits:

- **F5 [MEDIUM/DSGVO] — strip PII from exported context.** `AuditExportController::fetchEvents` now recursively removes a case-insensitive denylist (`user_id, uid, email, mail, e_mail, displayname, display_name, ip, ip_address`) from each event's context before emitting. The exact stored bytes are preserved when nothing is stripped (facts-only common case) so `payload_hash` provenance is untouched. Test: seeded `user_id`/`email`/nested-`ip` context → neither key nor value appears in the JSONL. Commit `9d6391d`.
- **F3 [HIGH] — zero signing secret on all paths.** `signJsonl` wraps the sign in try/finally and `sodium_memzero`s both the local copy and `material['secret']`, even when the invalid-length guard throws (shared with the `AuditCheckpointService` fix). Commit `c03cb03`.

The `AuditVerifyCommand` / `AuditCheckpointService` findings (F1, F2, F4, F6, F7) are documented in `161-03-SUMMARY.md`. No local PHP/PHPStan/PHPUnit run (project override).

## Notes for the Orchestrator (central gates)

- **Task-2 done-criterion nuance:** `npm run build` exits 0 and shows no SPA regression, but it does NOT compile `AuditExport.vue` (an unimported `.vue` is skipped by vite tree-shaking). The real validator for the component is **ESLint** (`--ext .vue`, Gate 1) — run locally here with 0 findings, re-run centrally. The LIVE auditor UI is the self-contained PHP form (`audit-export-page.php`), not the Vue island.
- **`.sig` format:** `bin2hex(sig) . "\n"`. The central "sig verifies against JSONL" check MUST `trim()` before `hex2bin()` (the trailing newline makes a naive `hex2bin(file_contents)` fail).
- **STATE.md / ROADMAP.md left untouched** and no PHP/occ/deploy run, per the project overrides — the orchestrator owns STATE/ROADMAP + PHPStan L5 + PHPUnit + deploy + live 403/export verification.

## Self-Check: PASSED

- All 6 deliverable files exist on disk.
- routes.php contains all 4 `audit_export#` routes.
- HEAD PhpUnitStubs carries `isInGroup` + `TemplateResponse` (via sibling `d3e3161`).
- Task commits `b990983` + `8c5465b` present in git history.
- ESLint on AuditExport.vue: 0 findings; `npm run build` exit 0 (no SPA regression).
