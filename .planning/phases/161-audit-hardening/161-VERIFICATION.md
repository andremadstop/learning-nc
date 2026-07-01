---
phase: 161-audit-hardening
verified: 2026-07-01T20:45:00Z
status: human_needed
score: 6/6 must-haves verified (automated)
human_verification:
  - test: "Trigger a compliance event in devcloud (e.g. enrol + complete a quiz question), then run `ssh relais 'docker exec -u www-data devcloud-app php occ learning:audit:verify'` and confirm output changes from '0 events, 0 checkpoints' to 'N events, 1 checkpoint verified'."
    expected: "occ learning:audit:verify exits 0 and prints '✓ Chain intact (N events, 1 checkpoint verified)' where N > 0 and checkpoints_verified = 1."
    why_human: "The live chain is currently EMPTY (0 compliance events): createCheckpoint() correctly skips on an empty chain, and the PHPUnit sign→verify roundtrip uses a real Ed25519 keypair — but the end-to-end flow (real event → checkpoint minted → verify picks it up) has not run in production yet."
  - test: "Log in as admin, open NC Settings > Administration > Learning. Locate the 'Audit-Trail — Liveness' section. Then set `last_checkpoint_at` to a >8-day-old timestamp via `ssh relais 'docker exec -u www-data devcloud-app php occ config:app:set learning last_checkpoint_at --value=1'` and reload."
    expected: "The overdue warning banner (.audit-overdue-warning with role=alert) is visible and contains the occ command hint; it disappears after restoring a recent timestamp."
    why_human: "Vitest asserts the reactive auditIsOverdue data flag (createInstance convention, no @vue/test-utils), not the rendered DOM. Visual confirmation that the v-if actually shows the banner is required."
  - test: "Add a non-admin user to 'learning-auditors' group (occ group:adduser learning-auditors <user>). Log in as that user and navigate to /apps/learning/audit/export. Download the JSONL, the .sig file, and open the report in browser, then click 'Drucken / Als PDF speichern'."
    expected: "JSONL downloads with compliance event rows; .sig can be verified locally with sodium; HTML report renders the events table + sha256/sig footer; print dialog opens without a second navigation."
    why_human: "The live audit chain is empty so downloads will be empty JSONL (valid but trivial); the visual layout of the PHP form, print template, and download buttons needs human eye-check."
---

# Phase 161: Audit Hardening Verification Report

**Phase Goal:** The audit trail is independently verifiable (Ed25519-signed weekly checkpoints), operable by the Datenschutzbeauftragter (export), and monitored by admins (liveness widget); Forgejo anchor scaffolded for fork-detection.
**Verified:** 2026-07-01T20:45:00Z
**Status:** human_needed (6/6 must-haves pass automated checks; 3 human items remain)
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|---------|
| 1 | Ed25519 checkpoint row is created with a verifiable signature after createCheckpoint() | VERIFIED | PHPUnit real-keypair sign→verify roundtrip; AuditCheckpointService.php lines 86–151 use `sodium_crypto_sign_detached` + F3 memzero try/finally; live chain empty (correct skip behavior — 0 events) |
| 2 | Forgejo anchor is OFF by default; on failure the checkpoint row is kept intact | VERIFIED | AuditCheckpointService.php line 168–171: enabled gate + token empty check; soft-fail path (lines 229–239) never rolls back checkpoint; F4 stores `content.download_url`, not `html_url`; F6 https-only guard line 185 |
| 3 | `occ learning:audit:verify` exits 0 on a clean chain and exits 1 on any integrity failure | VERIFIED | AuditVerifyCommand.php: F1 prev_hash tamper (line 107), F2 checkpoint field binding (lines 221–235), F7 pubkey length guard (line 190), 6-field canonical verbatim copy; RUNBOOK_PATH const = `docs/audit-fork-runbook.md`; **verifier-observed:** `✓ Chain intact (0 events, 0 checkpoints verified)` exit 0 on devcloud |
| 4 | A member of 'learning-auditors' can download signed JSONL + .sig + HTML report; non-member gets 403 | VERIFIED | AuditExportController.php: assertAuditorOrDie() first line of every action; F5 PII_DENYLIST + recursive stripPii(); F3 memzero try/finally in signJsonl(); @NoAdminRequired+@NoCSRFRequired as PHPDoc; 4 routes registered; 403/200 gate live-proven per orchestrator |
| 5 | Admin settings show liveness widget with last checkpoint, events-since, anchor status, and overdue banner | VERIFIED (code) | SettingsController.php merges 5 audit_* keys via try/catch neutral fallback (lines 49–59); AdminSettings.vue `.section.audit-liveness` with v-if="auditIsOverdue" on `.audit-overdue-warning`; getAdmin() HTTP 200 with 5 audit_* keys live per orchestrator |
| 6 | Fork-resolution runbook exists at path matching `occ learning:audit:verify` output | VERIFIED | `docs/audit-fork-runbook.md` exists with 6 ## sections (Detect/Preserve/Classify/Resolve/Verify+Reference); AuditVerifyCommand::RUNBOOK_PATH = `'docs/audit-fork-runbook.md'` (byte-identical) |

**Score:** 6/6 truths verified (automated); 3 items deferred to human verification

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/lib/Migration/Version009302Date20260701140000.php` | 10-column checkpoints table | VERIFIED | 10 columns: id/from_event_id/to_event_id/head_hash/event_count/signed_at/key_id/signed_payload(TEXT)/signature/anchor_url(nullable); 2 indexes (<=27 chars); hasTable idempotent guard; PG16+MariaDB safe |
| `app/lib/Service/AuditCheckpointService.php` | Ed25519 signing + liveness + Forgejo anchor | VERIFIED | createCheckpoint(): sign-skip-insert-anchor; getLivenessStatus(): 5-key array; doForgejoAnchor(): OFF-by-default soft-fail; forgejoPost(): protected HTTP seam; F3+F4+F6 hardening present |
| `app/lib/BackgroundJob/AuditCheckpointJob.php` | Weekly TimedJob, Throwable-isolating | VERIFIED | TimedJob, setInterval(604800), run() catches \Throwable, logs warning, never rethrows |
| `app/lib/Command/AuditVerifyCommand.php` | Independent 3-phase verify, occ-registered | VERIFIED | Phase 1 (chain walk + F1 prev_hash), Phase 2 (checkpoint sig + F2 field binding + F7 pubkey length), Phase 3 (anchor warning-only + F6 https); --json/--from-seq/--fork-runbook flags; exits 0/1 |
| `app/lib/Controller/AuditExportController.php` | 4-endpoint group-gated export | VERIFIED | page/events/sig/report; @NoAdminRequired+@NoCSRFRequired PHPDoc (not attributes); assertAuditorOrDie() first; JSONL no user_id; PII strip (F5); memzero (F3); DataDownloadResponse |
| `app/templates/audit-export-print.php` | @media print report with sha256+sig footer | VERIFIED | @media print CSS; events table; sha256(JSONL) + sigHex footer; htmlspecialchars throughout; window.print() button; no external resources |
| `app/templates/audit-export-page.php` | Group-gated filter UI with CSP nonce | VERIFIED | Date/course inputs; 3 download links; inline script with `nonce="<?php p(\OC::$server->getContentSecurityPolicyNonceManager()->getNonce()); ?>"` |
| `app/src/components/AuditExport.vue` | Options-API download UI component | VERIFIED (orphan) | 174 lines; fromDate/toDate/courseId/loading/error data(); download()/openReport() methods; no `<script setup>`. Note: not imported in the SPA build graph — live UI is audit-export-page.php (by design per SUMMARY deviation) |
| `app/lib/Controller/SettingsController.php` | getAdmin() merges 5 audit_* keys | VERIFIED | AuditCheckpointService injected as 6th ctor arg; getLivenessStatus() wrapped in try/catch neutral fallback; @AdminRequired PHPDoc preserved; DataResponse return type unchanged |
| `app/src/components/AdminSettings.vue` | Liveness section + overdue banner | VERIFIED (code) | `.section.audit-liveness` div; v-if="auditIsOverdue" on `.audit-overdue-warning` role=alert; 5 data() fields; load() maps all 5 audit_* keys with ?? fallbacks; scoped CSS |
| `app/tests/unit/AdminSettingsLiveness.test.js` | Vitest: overdue banner behavior | VERIFIED | createInstance convention (repo-mandated, no @vue/test-utils); asserts auditIsOverdue reactive flag for true/false/omitted-keys cases; 4 tests pass |
| `docs/audit-fork-runbook.md` | 5-section fork-resolution procedure | VERIFIED | 6 ## headings (5 numbered + Reference); references `learning:audit:verify` and checkpoint/anchor/DSGVO-erasure mechanics; path byte-identical to AuditVerifyCommand::RUNBOOK_PATH |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| Application.php::boot() | AuditCheckpointJob::class | `$jobList->add(AuditCheckpointJob::class)` inside has() guard | WIRED | Application.php line 133–134; import at line 5 |
| AuditCheckpointService::createCheckpoint() | sodium_crypto_sign_detached | direct call, F3 try/finally memzero | WIRED | Lines 107–120; both local copy and material['secret'] slot zeroed |
| AuditCheckpointService::createCheckpoint() | doForgejoAnchor() | called after INSERT + config writes (line 151) | WIRED | Correct ordering: checkpoint is source of truth; anchor is redundancy |
| AuditVerifyCommand::execute() | learning_audit_events ordered by seq_num ASC | 6-field canonical verbatim copy | WIRED | Lines 123–135; ksort; json_encode(UNESCAPED_UNICODE|SLASHES|THROW); hash('sha256', ....'|'.$prevHash) |
| AuditVerifyCommand::execute() | sodium_crypto_sign_verify_detached | base64url pubkey via CertKeyMapper; hex sig via hex2bin; F7 length guard before call | WIRED | Lines 174–205; asymmetric encodings handled correctly |
| AuditVerifyCommand failure output | docs/audit-fork-runbook.md | RUNBOOK_PATH const emitted on FAILURE + --fork-runbook flag + JSON field | WIRED | Lines 64, 323, 343; byte-identical to the doc path |
| AuditExportController all actions | assertAuditorOrDie() | try/catch ForbiddenException → 403 JSONResponse | WIRED | Lines 89, 109, 128, 148 — FIRST line of every action before any DB access |
| SettingsController::getAdmin() | AuditCheckpointService::getLivenessStatus() | injected service, try/catch neutral fallback | WIRED | Lines 49–59; 5 audit_* keys in DataResponse lines 78–82 |
| AdminSettings.vue load() | /apps/learning/api/settings/admin | axios.get(generateUrl(...)) — existing call now carries audit_* keys | WIRED | Lines 460–464; all 5 keys mapped with ?? fallbacks |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| AUDIT-04 | 161-01 | Ed25519-signed weekly checkpoints via sodium_crypto_sign_detached (NOT SigningService) | VERIFIED | Version009302 migration live; AuditCheckpointService.php; AuditCheckpointJob wired; PHPUnit real-keypair roundtrip; 222 tests / 768 assertions pass |
| AUDIT-05 | 161-02 | External Forgejo anchor (config-flag + token, anchor_url column) OFF by default | VERIFIED | doForgejoAnchor() + forgejoPost() seam; F4 download_url; F6 https guard; off-by-default gate; soft-fail never rethrows |
| AUDIT-06 | 161-03 | occ learning:audit:verify checks chain + checkpoint sigs + anchor consistency | VERIFIED | AuditVerifyCommand.php 3-phase; F1/F2/F7 hardening; info.xml registered; **verifier-observed exit 0:** `✓ Chain intact (0 events, 0 checkpoints verified)`; DSGVO-erased rows no false-positive |
| AUDIT-07 | 161-04 | Auditor export (JSONL + .sig + HTML) for DPO without shell/admin rights | VERIFIED | AuditExportController.php; @NoAdminRequired+@NoCSRFRequired PHPDoc; assertAuditorOrDie(); F3+F5 hardening; 403/200 gate live |
| AUDIT-08 | 161-05 | Audit-Liveness admin widget; overdue checkpoint produces visible warning | VERIFIED (code + live) | SettingsController.php merges 5 keys; AdminSettings.vue liveness section + overdue v-if; live getAdmin HTTP 200 with 5 audit_* keys |
| AUDIT-09 | 161-06 | Fork-resolution runbook; documented admin process for discovered chain fork | VERIFIED | docs/audit-fork-runbook.md; 5 numbered sections; path = AuditVerifyCommand::RUNBOOK_PATH |

---

### Codex Security Review (F1-F7) — All Applied

| Finding | Severity | Applied | Evidence |
|---------|----------|---------|---------|
| F1 — prev_hash tamper detection | BLOCKER | Yes | AuditVerifyCommand.php line 107: `hash_equals($row['prev_hash'], $prevHash)` before chain_hash check |
| F2 — bind checkpoint sig to DB columns | HIGH | Yes | Lines 221–235: signed_payload re-parsed; each signed field asserted against stored column; event_count = to-from+1; window-gap warning |
| F3 — memzero on all paths | HIGH | Yes | AuditCheckpointService.php lines 112–118 (try/finally); AuditExportController.php lines 307–312 (try/finally); BOTH local copy and material['secret'] slot zeroed |
| F4 — anchor raw bytes not HTML page | HIGH | Yes | AuditCheckpointService.php line 211: `$data['content']['download_url']` stored; AuditVerifyCommand.php line 373: fetched bytes compared with hash_equals directly |
| F5 — PII strip from JSONL export | MEDIUM | Yes | AuditExportController.php: PII_DENYLIST const + recursive stripPii(); case-insensitive; nested-key safe |
| F6 — https-only SSRF guard | MEDIUM | Yes | AuditCheckpointService.php line 185: str_starts_with check + soft-fail; AuditVerifyCommand.php line 357: anchor skip with warning if non-https |
| F7 — pubkey length guard before sodium | LOW | Yes | AuditVerifyCommand.php lines 190–193: `strlen($pubKeyRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES` → reportable failure; wrapped in try/catch SodiumException |

---

### Anti-Patterns Found

| File | Pattern | Severity | Impact |
|------|---------|----------|--------|
| `app/lib/Migration/Version009302Date20260701140000.php` PHPDoc | Comment says "Forgejo HTML URL" for anchor_url but code correctly stores download_url (F4) | Info | Documentation-only mismatch; code behavior is correct |
| `app/src/components/AuditExport.vue` | Not imported in SPA build graph (orphan Vue file) | Info | Live auditor UI is served by `audit-export-page.php` (server-rendered PHP form) — by design per 161-04 deviation. AuditExport.vue is a future-SPA artifact validated by ESLint only |
| TODO comments | `TODO(anchor-enablement)` in AuditCheckpointService.php line 184 and AuditVerifyCommand.php line 358 | Info | Full SSRF host allowlist deferred until Forgejo token is provisioned; https-only guard is the current mitigation |

None of the above are blockers.

---

### Human Verification Required

#### 1. Live end-to-end checkpoint minting

**Test:** Generate at least one compliance event (e.g. log in as a learner, enrol in a course, answer a question). Then either wait for the weekly AuditCheckpointJob or call createCheckpoint() manually. Run `ssh relais 'docker exec -u www-data devcloud-app php occ learning:audit:verify'`.

**Expected:** occ exits 0 and prints `✓ Chain intact (N events, 1 checkpoints verified)` where N > 0 and checkpoints_verified = 1.

**Why human:** The live chain is currently EMPTY — verifier-observed: `✓ Chain intact (0 events, 0 checkpoints verified)`. The `createCheckpoint()` correctly returns early on an empty chain (no new events guard). PHPUnit proves the crypto with a real Ed25519 keypair (not a mock). But the full production pipeline (real compliance event → checkpoint minted with the live issuer key → occ verify finds and verifies it) has not executed. This is the final integration proof.

#### 2. Admin liveness widget — visual appearance and overdue banner

**Test:** Open NC Settings > Administration > Learning as an admin. Observe the "Audit-Trail — Liveness" section. Force an overdue state: `ssh relais 'docker exec -u www-data devcloud-app php occ config:app:set learning last_checkpoint_at --value=1'`. Reload the page.

**Expected:** The liveness section renders with the three table rows (last checkpoint, events-since, anchor status). The warning banner with `role="alert"` appears and mentions "occ learning:audit:verify". Restoring `last_checkpoint_at` to a recent timestamp removes the banner.

**Why human:** Vitest uses the repo-mandated `createInstance` convention (no @vue/test-utils), which asserts the reactive data flag but not the rendered DOM. The v-if logic is correct in source, but visual confirmation of the banner rendering in the actual NC admin panel is needed.

#### 3. Auditor export UI — filter form, download buttons, HTML print report

**Test:** Add a non-admin test user to `learning-auditors` (occ group:adduser). Log in. Navigate to `/apps/learning/audit/export`. Click all 3 download actions. Open the HTML report in a new tab, then click "Drucken / Als PDF speichern".

**Expected:** The filter UI (date-from, date-to, course-id) is visible and functional. JSONL download starts (even if empty). .sig download starts. The HTML report opens in a new tab with the events table + sha256/sig footer visible; the browser print dialog opens when the button is clicked.

**Why human:** The live chain is empty so download content is trivial, but the visual layout and navigation flow of both the PHP form and the print template need a real browser test to confirm CSP nonce correctness, print CSS, and download-attribute behavior.

---

### AUDIT-04 Empty-Chain Assessment

The live chain being EMPTY is the CORRECT behavior: `createCheckpoint()` has a "no new compliance events since last checkpoint" early-return (lines 68–71 of AuditCheckpointService.php), which correctly fires when `MAX(seq_num) <= lastToSeq = 0`. The verifier-observed `occ learning:audit:verify` output is `✓ Chain intact (0 events, 0 checkpoints verified)` — exit 0, no warnings. With maxSeq = 0, the code never enters the "events present but no checkpoint" branch, so no unanchored-chain warning fires.

PHPUnit sign→verify roundtrip (AuditCheckpointServiceTest) uses `sodium_crypto_sign_keypair()` for a real Ed25519 keypair — the crypto is exercised end-to-end, not mocked. This satisfies AUDIT-04 must_haves at the unit-test level. The human verification item #1 above covers the live production proof.

---

### Gaps Summary

No automated gaps found. All 6 requirements are implemented, all 7 Codex security findings are applied, and the live gates (PHPStan L5 clean, PHPUnit 222 tests / 768 assertions pass, migration applied on PG16, occ verify exits 0 verifier-observed, 403/200 gate verified, getAdmin HTTP 200 with 5 audit_* keys) are confirmed.

Three items remain for human verification: live checkpoint minting E2E, admin liveness widget visual, and auditor export UI visual. These do not block the phase goal technically but confirm the user-facing surfaces are production-ready.

---

_Verified: 2026-07-01T20:45:00Z_
_Verifier: Claude (gsd-verifier)_
