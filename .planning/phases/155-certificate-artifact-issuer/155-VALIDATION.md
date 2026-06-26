---
phase: 155
slug: certificate-artifact-issuer
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-06-26
updated: 2026-06-26
---

# Phase 155 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Detailed per-requirement test map lives in 155-RESEARCH.md `## Validation Architecture`.
> Per-task map below populated by gsd-planner from the 7-plan breakdown.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Frameworks** | PHPUnit (PHP unit, IN-CONTAINER only — no local vendor/bin/phpunit), Vitest (JS/Vue unit), PHPStan L5 (static), test-api.sh (API integration), Python `cryptography` Ed25519 (independent verify, dev-only — node `jose` NOT installed), ephemeral `mariadb:11.4` container (cross-DB) |
| **Config file** | `app/phpunit.xml`, `app/vite.config.mjs`, `phpstan.neon` |
| **Quick run command** | `cd app && npm run test` (Vitest) · PHPUnit in-container: `./scripts/deploy-prod.sh --php-only && ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpunit --filter <Test>'` · PHPStan via deploy-prod.sh --phpstan |
| **Full suite command** | `./scripts/deploy-prod.sh --test` (PHPStan + PHPUnit) + `cd app && npm run test` + `scripts/test-api.sh` + `scripts/cross-db-migration-check.sh` |
| **Estimated runtime** | PHPStan ~30s · Vitest ~10s · PHPUnit ~20s · test-api.sh ~1min · cross-db check ~1min |

> Verify commands use **direct-output assertions** (grep for `OK (` / `Tests:` and the absence of `FAILURES|Errors:`) — the PHPUnit summary-stripping regexes are buggy per the brief.

---

## Sampling Rate

- **After every task commit:** relevant Vitest (`npm run test`) or PHPUnit subset; PHPStan L5 (Gate 1, Pflicht).
- **After every plan wave:** Full Gate 1 (PHPStan + ESLint + Vitest).
- **Before `/gsd:verify-work`:** Full suite green + Gate 2 (test-api.sh) after deploy + cross-DB go/no-go + independent-verify + kid-curl green.
- **Max feedback latency:** ~30 seconds (Gate 1).

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 155-01-T2 | 01 | 1 | CERT-04/06 (migration) | php -l + grep | `php -l Version009100…` + no-oc_ + no-TEXT-index grep | ✅ created in-task | ⬜ pending |
| 155-01-T3 | 01 | 1 | CERT-03 (leakage-safe serialize) | PHPUnit | `(container) phpunit --filter CertEntityTest` | ✅ created in-task | ⬜ pending |
| 155-02-T1 | 02 | 2 | CERT-01/03/04 (KeyService) | PHPUnit | `(container) phpunit --filter KeyServiceTest` | ✅ created in-task | ⬜ pending |
| 155-02-T2 | 02 | 2 | CERT-01 (occ command) | php -l + grep | `php -l InitIssuerCommand` + info.xml/Application grep | ✅ created in-task | ⬜ pending |
| 155-02-T3 | 02 | 2 | CERT-02/04 (did.json) | php -l + grep | `php -l DidController` + route + publicKeyJwk grep | ✅ created in-task | ⬜ pending |
| 155-03 | 03 | 3 | CERT-06 (signing + ADR #1/#2) | PHPUnit + Py | `(container) phpunit --filter SigningServiceTest` + `verify-credential.py` | ✅ created in-task | ⬜ pending |
| 155-04-T1 | 04 | 4 | CERT-05/06/11 (issuance) | PHPUnit | `(container) phpunit --filter IssuanceServiceTest` | ✅ created in-task | ⬜ pending |
| 155-04-T2 | 04 | 4 | CERT-12 (notification) | php -l + parity | `php -l Notifier` + `check-i18n-parity.sh` | ✅ created in-task | ⬜ pending |
| 155-04-T3 | 04 | 4 | CERT-05 (pass hook) | PHPUnit | `(container) phpunit --filter 'PassCriteriaServiceTest\|IssuanceServiceTest'` | ✅ created in-task | ⬜ pending |
| 155-05-T1 | 05 | 5 | CERT-07/09 (controller; download=OB3 JSON-LD EnvelopedVerifiableCredential, ?format=jwt) | PHPUnit | `(container) phpunit --filter CertificateControllerTest` (asserts JSON-LD envelope + raw-jwt) | ✅ created in-task | ⬜ pending |
| 155-05-T2 | 05 | 5 | CERT-07 (JS client) | ESLint | `npx eslint CertificateService.js` | ✅ created in-task | ⬜ pending |
| 155-06-T1 | 06 | 6 | CERT-07/08/10/11/13 (Certificate.vue) | ESLint + parity | `npx eslint Certificate.vue` + Options-API grep + `check-i18n-parity.sh` | ✅ created in-task | ⬜ pending |
| 155-06-T2 | 06 | 6 | CERT-07/08/09/13 (component spec) | Vitest | `npm run test -- Certificate` | ✅ created in-task | ⬜ pending |
| 155-06-T3 | 06 | 6 | CERT-07..13 (end-to-end) | human-verify | relay checkpoint (issuance→notify→view→print→QR→download→LinkedIn→i18n) | n/a manual | ⬜ pending |
| 155-07-T1 | 07 | 7 | CERT-03 (leakage gate) | PHPUnit + grep | `(container) phpunit --filter LeakageAuditTest` + export-service grep | ✅ created in-task | ⬜ pending |
| 155-07-T2 | 07 | 7 | CERT-04 (cross-DB) | shell go/no-go | `scripts/cross-db-migration-check.sh` (ephemeral mariadb:11.4 + PG16) | ✅ created in-task | ⬜ pending |
| 155-07-T3 | 07 | 7 | CERT-04 (kid + rotation) | test-api.sh curl | did.json + kid alignment + rotation-preserves assertions | ✅ created in-task | ⬜ pending |
| 155-07-T4 | 07 | 7 | CERT-06 (ADR #2 independent-verify on REAL cert) | shell + Python | `scripts/verify-issued-cert-gate.sh` → `python3 scripts/verify-credential.py` (fail-not-skip; valid→0, tampered→≠0) | ✅ created in-task | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

> **Nyquist:** every `<verify>` carries an `<automated>` command pointing at a test the task itself creates (TDD-per-task model — no separate scaffold plan, no double-created test files). No 3 consecutive tasks without an automated verify.

---

## Wave 0 / Test-Ownership Model

TDD-per-task: each code-producing task creates and owns its own test file in the same task (`tdd="true"` tasks
carry a `<behavior>` block; 155-03 is a dedicated `type: tdd` plan for the signing round-trip). There is NO separate
test-scaffold plan — this avoids double-creating test files. Frameworks already exist (PHPUnit, Vitest, PHPStan,
test-api.sh); only new test files + the cross-DB harness + the independent-verify script are net-new.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Full student experience (issuance→notify→view→print→QR→download→LinkedIn→i18n) | CERT-05/07/08/09/12/13 | Visual + interactive on a live instance | 155-06 human-verify checkpoint on relay (8 steps) |
| QR scan → verify URL | CERT-08 | Physical scan with a phone | Scan rendered QR, confirm it targets `<base>/apps/learning/verify/<vid>` |
| Independent-verifier validation of a REAL issued credential | CERT-06 | ADR follow-up #2 — external Ed25519 verifier | Homed in **155-07 Task 4** (`scripts/verify-issued-cert-gate.sh`): extracts a real issued JWT, runs `python3 scripts/verify-credential.py` (Python `cryptography` Ed25519), MUST fail-not-skip; valid→exit 0, tampered→non-zero |
| Cross-DB go/no-go | CERT-04 | Needs an ephemeral MariaDB 11.4 container | Run `scripts/cross-db-migration-check.sh`; record GREEN |
| Private-key leakage sign-off | CERT-03 | Security gate (Rule 18) | 155-LEAKAGE-AUDIT.md enumerated review + LeakageAuditTest |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify (TDD-per-task; tests created in the same task)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Test ownership: each task owns its test file (no separate scaffold plan)
- [x] No watch-mode flags
- [x] Feedback latency < 30s (Gate 1)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** planner-approved 2026-06-26
