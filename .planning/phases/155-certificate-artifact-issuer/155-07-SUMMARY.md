---
phase: 155-certificate-artifact-issuer
plan: 07
subsystem: testing
tags: [security, leakage-audit, cross-db, mariadb, postgres, did-web, kid, independent-verifier, ed25519, provisioning, idempotency]

# Dependency graph
requires:
  - phase: 155-02
    provides: KeyService (sodium keygen + ICrypto encrypt + rotation) + InitIssuerCommand + DidController (public did.json)
  - phase: 155-03
    provides: SigningService VC-JWT EdDSA + scripts/verify-credential.py (independent Python Ed25519 verifier)
  - phase: 155-04
    provides: IssuanceService (pass hook → OB3 → sign → persist → notify) + Migration Version009100
  - phase: 155-06
    provides: Certificate.vue student UI + CertificateService.js (consumed by the live walkthrough)
provides:
  - LeakageAuditTest + 155-LEAKAGE-AUDIT.md — Rule-18 enumerated sign-off that secret_key_enc cannot escape any export/snapshot/package/serialize surface
  - scripts/cross-db-migration-check.sh — ephemeral mariadb:11.4 utf8mb4 go/no-go for Version009100 (+ live PG16 assertion)
  - scripts/verify-issued-cert-gate.sh — independent Python Ed25519 verify on a REAL issued credential (fail-not-skip)
  - test-api.sh cert block — did.json resolves + JWT kid == verificationMethod.id + rotation-preserves
  - LIVE issuer provisioning on devcloud (Version009100 applied PG16; Ed25519 issuer key; did.json HTTP 200)
affects: [156-compliance-report, 157-public-verify]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Cross-DB go/no-go gate: an ephemeral `docker run --rm mariadb:11.4` (utf8mb4) applies the Version009100 DDL verbatim (oc_ prefix, exact VARCHAR lengths, TEXT-unindexed secret/credential columns, index names ≤64 chars) and asserts no 'Specified key was too long'; readiness must be an authenticated `SELECT 1` (mariadb-admin ping reports alive against the passwordless temp init server)"
    - "Fail-not-skip phase gate: verify-issued-cert-gate.sh HARD-FAILs (exit 1) if python3/cryptography is absent — a security gate that silently skips is worse than no gate"
    - "kid single source of truth: the issued JWT's kid (hostDid().'#'.keyId) is the SAME string as DidController's verificationMethod.id by construction — kid-drift is structurally impossible, asserted live by curl + base64url-decode"
    - "Live migration apply path on this NC33: migrations:execute / db:show-table / migrations:preview are unavailable in upgrade-required state — apply via `occ upgrade`, verify the pending set directly via psql against oc_migrations"

key-files:
  created:
    - app/tests/Unit/Service/LeakageAuditTest.php
    - scripts/cross-db-migration-check.sh
    - scripts/verify-issued-cert-gate.sh
    - .planning/phases/155-certificate-artifact-issuer/155-LEAKAGE-AUDIT.md
  modified:
    - scripts/test-api.sh
    - app/appinfo/info.xml

key-decisions:
  - "info.xml bumped 4.4.7→4.4.8 as a minimal migration-trigger ONLY — explicitly NOT 5.0.0 (reserved for the real release: CHANGELOG + git tag, rule 7). Reconcile this patch number at the v5.0.0 release."
  - "Live key rotation (occ --rotate) is opt-in (ALLOW_LIVE_ROTATE=1) and was NOT run — CERT-04 rotation-preserves is structurally + unit proven (findAllNonRevoked serves retired keys; did.json loops all non-revoked), not live-exercised against a destructive rotate."
  - "Trust model stated, not a gap: DB backups contain only the ICrypto ciphertext; recovery needs the separately-held config.php instance secret = the accepted NC at-rest model (same as telos fields)."
  - "Migration applied via `occ upgrade` (the only apply path on this NC33 in upgrade-required state); the pending set was confirmed directly via psql against oc_migrations before applying."

requirements-completed: [CERT-01, CERT-02, CERT-03, CERT-04, CERT-06]
# CERT-05 (auto-issue on pass) + CERT-12 (student notification) are owned by 155-04's frontmatter; their
# LIVE closure was proven during this plan's provisioning (see "Live Provisioning" + "CERT-12" below).

# Metrics
duration: ~90min (non-prod gates) + live provisioning session
completed: 2026-06-27
---

# Phase 155 Plan 07: Phase-Close Security & Portability Gates + Live Issuer Provisioning Summary

**The issuer is provisioned and the phase-close gates are green. Three go/no-go security gates: (1) the Ed25519 private key provably cannot leak through any export/snapshot/app-package/serialize surface (LeakageAuditTest, 39 assertions, + an enumerated 10-surface sign-off); (2) Migration Version009100 is cross-DB portable (GREEN on an ephemeral MariaDB 11.4 utf8mb4 container + live PG16); (3) the issued JWT `kid` aligns with the live `did.json` verificationMethod.id and a REAL issued credential verifies against the independent Python Ed25519 verifier (fail-not-skip). Live on devcloud: info.xml bumped to trigger `occ upgrade`, Version009100 applied on PG16 (both cert tables + the `learn_cert_idem_uq` UNIQUE idempotency guard), and `occ learning:cert:init-issuer` generated one Ed25519 issuer key (key_id `UI3V-D_j57IeIOlPBAW-2VQRu0dHB2lkZ0rDLj-LBU4`); `did.json` serves HTTP 200 with the public JWK only. A synthetic end-to-end smoke minted a REAL cert through the genuine pass pipeline, independently verified it, proved idempotency (3 passes → 1 cert), then cleaned up.**

## Performance

- **Duration:** ~90 min (the four non-prod gate scripts) + a separate authorized live-provisioning session
- **Started / Completed:** 2026-06-27
- **Tasks:** 4 `type=auto` (leakage gate, cross-DB go/no-go, kid↔did.json, independent-verifier gate) + the post-review live provisioning
- **Files:** 4 created, 2 modified

## Accomplishments
- **Task 1 — Private-key leakage gate (CERT-03, Rule 18).** `155-LEAKAGE-AUDIT.md` signs off 10 surfaces (DataExportService 7 tables, DataMobilityService 3, CourseArchiveService + snapshots, the Export/Import command family, NC user-migration, the app-store tarball, `CertKey::jsonSerialize`, no decrypted-byte logging, `sodium_memzero` after sign) + the explicit trust model. `LeakageAuditTest.php` asserts automatically: `jsonSerialize()` carries no `secret_key_enc`/`secret`, and the enumerated export/snapshot services never reference the `cert_keys` table. **Live GREEN: 3 tests / 39 assertions**, grep gate clean, PHPStan L5 clean.
- **Task 2 — Cross-DB go/no-go (CERT-04 portability).** `scripts/cross-db-migration-check.sh` mirrors the Version009100 DDL verbatim and applies it on an **ephemeral `mariadb:11.4` utf8mb4** container. **RAN → GO (exit 0):** both tables + all 3 indexes (22/19/23 chars) create, no "Specified key was too long", container torn down. PG16 is the live engine (asserted via the live apply, below).
- **Task 3 — kid↔did.json + rotation (ADR follow-up #3).** Added a cert block to `scripts/test-api.sh`: asserts verificationMethod ids are `did:web:<host>:apps:learning#…`, base64url-decodes an issued JWT header and HARD-asserts `kid == verificationMethod.id`, plus a rotation-preserves assertion. Guarded to auto-activate once the issuer is provisioned; the destructive `occ --rotate` is opt-in (`ALLOW_LIVE_ROTATE=1`).
- **Task 4 — Independent-verifier gate (ADR follow-up #2).** `scripts/verify-issued-cert-gate.sh` pulls a REAL issued JWT (`GET /api/certificates`), resolves the signer's public `x` from `did.json` by `kid`, runs `verify-credential.py`, asserts valid→0 + tampered→non-zero, and **FAIL-NOT-SKIPs** if python3/cryptography is absent.
- **Live provisioning** (authorized after the multi-AI review passed SHIP, `eb4de42`) — see below.

## Task Commits

1. **Task 1: leakage gate — LeakageAuditTest + enumerated sign-off** — `20f3666` (test) (CERT-03, Rule 18)
2. **Task 2: cross-DB migration go/no-go (PG16 + MariaDB 11.4 utf8mb4)** — `be014ab` (test)
3. **Task 3: kid↔did.json alignment + rotation-preserves in test-api.sh** — `93c4d6a` (test)
4. **Task 4: independent-verifier gate on a real issued credential** — `00cfeca` (test)
5. **Live: info.xml 4.4.7→4.4.8 to trigger occ upgrade (Version009100)** — `77e1159` (chore)
6. **Fix: guard test-api.sh jwt_header_kid against set -u unbound var** — `f6e3268` (fix)

_(Docs commits in the same plan: `8046747` non-prod gate record, `5a34526` live-provisioning record, `01942ad` synthetic-cert-smoke record.)_

**Plan metadata:** _(this SUMMARY + STATE + ROADMAP + REQUIREMENTS, all hand-edited — gsd-tools corrupt the v5.0.0 frontmatter)_

## Files Created/Modified
- `app/tests/Unit/Service/LeakageAuditTest.php` — secret_key_enc absent from all serialize/export (39 assertions)
- `scripts/cross-db-migration-check.sh` — ephemeral mariadb:11.4 utf8mb4 go/no-go for Version009100
- `scripts/verify-issued-cert-gate.sh` — independent Python Ed25519 verify on a real issued credential (fail-not-skip)
- `.planning/phases/155-certificate-artifact-issuer/155-LEAKAGE-AUDIT.md` — 10-surface enumerated sign-off + trust model
- `scripts/test-api.sh` — cert block: did.json resolves + kid==verificationMethod.id + rotation-preserves; `jwt_header_kid()` set -u fix
- `app/appinfo/info.xml` — version 4.4.7→4.4.8 (migration trigger only; NOT the v5.0.0 release bump)

## Live Provisioning (devcloud / relais, 2026-06-27)

Authorized after the multi-AI review passed **SHIP** (`eb4de42`). Live mutations on the production devcloud:

1. **info.xml 4.4.7→4.4.8** (`77e1159`) — minimal patch to flag `needsDbUpgrade`. Not 5.0.0 (release reserves CHANGELOG + tag). CHANGELOG untouched, no tag.
2. **`deploy-prod.sh --php-only`** — verified safe first (rsyncs lib/templates/routes/info.xml/l10n + docker cp + apache graceful + PHPStan; NO `occ upgrade`/`migrations:`). PHPStan L5 clean.
3. **`occ upgrade`** — turned maintenance ON, applied **Version009100** on live PG16 (additive `CREATE TABLE oc_learning_cert_keys` + `oc_learning_certificates`), set learning→4.4.8, maintenance OFF; base URL HTTP 302 (up). Side-effect: the same upgrade pulled a pending `spreed`/Talk app update from the App Store ("Update successful") — normal whole-instance upgrade behavior, non-destructive, logged for transparency.
4. **`occ learning:cert:init-issuer`** — generated ONE Ed25519 issuer key, key_id `UI3V-D_j57IeIOlPBAW-2VQRu0dHB2lkZ0rDLj-LBU4`. Re-run with no args REFUSES (exit 1, "use --rotate"); exactly one `active` row.

**Live GREEN (post-apply gates):**
- **Schema** — both tables + the **`learn_cert_idem_uq` UNIQUE on active_idem_key** (the R2-2 atomic idempotency guard), `learn_cert_vid_uniq`, `learn_cert_user_crs_idx`, confirmed by name via psql; `cert_keys.secret_key_enc` is TEXT (unindexed).
- **CERT-01** — key generated; re-run refuses (exit 1); one active key.
- **CERT-02** — `curl …/apps/learning/did.json` → HTTP 200; `verificationMethod.id = did:web:devcloud.andrestiebitz.de:apps:learning#UI3V…`; publicKeyJwk = `{kty:OKP, crv:Ed25519, x:UI3V…}` ONLY; affirmative no-secret check PASS (no `d`/`secret`/`secret_key_enc`); `x` == kid fragment == key_id (single source).
- **CERT-03** — LeakageAuditTest 39 assertions GREEN in-container; grep gate clean.

**Cosmetic discrepancy (noted, harmless):** `InitIssuerCommand` CLI printed `did:web:localhost:apps:learning` because `IURLGenerator::getBaseUrl()` resolves host to localhost in CLI context (despite `overwrite.cli.url`). The SERVED did.json (HTTP) and HTTP-context issuance (`GET /pass-status`) both correctly resolve `devcloud.andrestiebitz.de`; `hostDid()` derives the host from `getBaseUrl()` at call time — **nothing host-specific is persisted** (cert_keys stores no host), so issued certs (always minted in HTTP context) carry the correct kid that matches did.json. Follow-up: make the command's printed kid match the served host (display-only).

## Synthetic End-to-End Smoke (authorized, throwaway data cleaned up afterward)

A REAL certificate was minted through the genuine pass pipeline (NOT a hand-inserted row), closing the three "deferred-to-human" gates that the script-authoring run had stubbed:

- **Setup:** throwaway NC user `zz-test-cert155`, course 59 (`cert_enabled`, `cert_pass_percent=1`, `certRequiredPoolIds=[160]`, validity 365d). The two gate preconditions (one exam session 1/1 → score 100; one Leitner item box=5 → mastery 100%) were seeded in the DB, then the REAL evaluation was triggered via `GET /api/courses/59/pass-status`. The cert was **signed/persisted/notified by live code** — only the inputs were seeded.
- **CERT-05 (auto-issue) LIVE** — a real cert auto-issued through the genuine `evaluate()` → `issueIfPassed()` pipeline (verification_id `eb97720c-…`, key_id `UI3V…`).
- **CERT-06 (independent verify) LIVE** — `verify-issued-cert-gate.sh` PASS on the REAL JWT (independent Python Ed25519); tampered copy rejected (ADR follow-up #2 closed). python3-cryptography 43.0.0 present in-container.
- **CERT-04 idempotency LIVE** — 3 qualifying GETs → exactly 1 cert, same vid, `passed_events=1` (the `learn_cert_idem_uq` UNIQUE + the own read-guard).
- **kid==verificationMethod.id GREEN** — test-api.sh did.json + kid assertion (105 pass / 2 fail / 2 skip; the 2 fails are admin-only endpoints — the test user is a non-admin instructor; rotation SKIP is the opt-in destructive `--rotate`).
- **Cleanup:** test data removed afterward (0 certs; issuer key + did.json intact).

### CERT-12 (student notification) — open finding RESOLVED as a SQL artifact

The synthetic smoke recorded an OPEN FINDING that the `certificate_issued` notification's recipient read `user=oc_admin` (a Postgres role, not an NC user) instead of the student. **This was a false alarm.** Re-checked live during this close-out (read-only psql):

- `user` is a **reserved keyword in PostgreSQL** — the original `SELECT user FROM oc_notifications` returned `CURRENT_USER` (the DB session role `oc_admin`), NOT the column. Every notification across **every** app (firstrunwizard / learning / settings / spreed), spanning months, showed `oc_admin` — the tell that it was the keyword, not data.
- Querying the **properly-quoted `"user"` column** returns the real NC recipient ids (learning notifications: `raja`, `kingsdomn`, `benjamin`, `julian`, `andre`). `oc_admin` is not an NC user at all (`occ user:info oc_admin` → "user not found"; only `admin` exists).
- The deployed `IssuanceService::notify()` calls `setUser($userId)` with the correct student id. CERT-12's mechanism is sound; the apparent misattribution was entirely the unquoted-keyword query. 155-VERIFICATION.md's "delivered to the correct student (NOT oc_admin)" verdict stands. (The specific synthetic cert's notification row is gone with the cleanup, but the systematic evidence is decisive.)

## Requirements Status

- **CERT-01, CERT-02, CERT-03** — Complete, live-verified (key gen + refuse; did.json 200 + no-secret + x==kid; LeakageAuditTest 39 assertions).
- **CERT-06** — Complete, live-verified (independent Python Ed25519 verifier PASS on a REAL issued JWT; tampered rejected).
- **CERT-04** — Complete: cross-DB schema portability GREEN (MariaDB 11.4 + PG16), multi-key + `key_id` reference + atomic idempotency live. **Rotation-preserves is structurally + unit proven** (`findAllNonRevoked` serves retired keys; did.json loops all non-revoked) — the **live destructive `occ --rotate`** was deliberately NOT run (`ALLOW_LIVE_ROTATE` deferred).
- **CERT-05 + CERT-12** (155-04-owned) — closed LIVE here (auto-issue through the genuine pipeline; notification to the correct student per the resolved CERT-12 finding).

`requirements mark-complete` NOT run (gsd-tools corrupts the v5.0.0 frontmatter); REQUIREMENTS.md hand-edited.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] test-api.sh jwt_header_kid() unbound var under set -u**
- **Found during:** the synthetic smoke (first real cert; prior runs SKIPped the kid block)
- **Issue:** `local jwt="$1" h="${jwt%%.*}"` expanded `${jwt%%.*}` against the unset OUTER `jwt` before `local` assigned it, aborting under `set -u` ("jwt: unbound variable").
- **Fix:** split into separate `local` declarations; the kid block goes GREEN.
- **Committed in:** `f6e3268`

**2. [Decision] MariaDB readiness must be an authenticated SELECT 1**
- **Found during:** Task 2
- **Issue:** `mariadb-admin ping` reports alive against MariaDB's passwordless temp init server before the real server is up.
- **Fix:** readiness probe is a real authenticated `SELECT 1`.
- **Committed in:** `be014ab`

---

**Total deviations:** 2 (1 bug fix, 1 readiness-probe decision)
**Impact on plan:** Both correctness-necessary for the gates to be honest. No scope creep.

## Issues Encountered
- **Live migration apply path** — `db:show-table` / `migrations:status` / `migrations:preview` are unavailable on this NC33 in upgrade-required state (preview needs unreachable NC metadata → 404). Verified the pending set directly via psql against `oc_migrations` (009000 applied, 009100 absent pre-upgrade). Applied via `occ upgrade`.
- **No admin creds initially** — the authenticated Gate 2 + real-cert verify were "deferred to human" in the script-authoring run (vault `DevCloud-Zugangsdaten.md` is a dangling wikilink). The synthetic smoke (Andre-authorized) provided a real cert and a non-admin instructor session, closing them.
- **Container `python3-cryptography` is non-persistent** across container rebuild — the verify gate must (re)ensure it (43.0.0 was present for the live run).

## User Setup Required
The issuer is provisioned on devcloud. For a fresh instance: apply the migration (`occ upgrade`), then `occ learning:cert:init-issuer` once. (Documented for the v5.0.0 release.)

## Next Phase Readiness
- **Phase 156 (Compliance-Report)** — `learning_certificates` exists with the schema + a proven issuance path; the report reads it.
- **Phase 157 (Public-Verify)** — did.json resolves live (200), the kid↔verificationMethod.id contract holds, and the independent-verify mechanism is proven; the public verify route consumes exactly these.
- **Carry-forward:** (a) reconcile info.xml 4.4.8 → the real v5.0.0 release bump (CHANGELOG + tag); (b) revocation path MUST set `active_idem_key = NULL` when 156/157 build it (R2-2 follow-up); (c) the three visual eyeballs (CERT-07/08/13) ride on the demo course; (d) make InitIssuerCommand's printed kid match the served host (cosmetic).

## Self-Check: PASSED

- Files on disk: `LeakageAuditTest.php` FOUND, `cross-db-migration-check.sh` FOUND, `verify-issued-cert-gate.sh` FOUND, `155-LEAKAGE-AUDIT.md` FOUND.
- Commits in history: `20f3666` FOUND, `be014ab` FOUND, `93c4d6a` FOUND, `00cfeca` FOUND, `77e1159` FOUND, `f6e3268` FOUND.
- Live: Version009100 applied PG16; issuer key `UI3V…`; did.json HTTP 200; LeakageAuditTest 39 assertions GREEN.

---
*Phase: 155-certificate-artifact-issuer*
*Completed: 2026-06-27*
</content>
</invoke>
