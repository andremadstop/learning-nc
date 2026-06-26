---
phase: 155-certificate-artifact-issuer
plan: 01
subsystem: database
tags: [certificates, ed25519, vc-jwt, did-web, qbmapper, migration, ob3, postgres, mariadb]

# Dependency graph
requires:
  - phase: 154-pass-definition
    provides: cert_* config columns on learning_courses (pass criteria that gate issuance)
provides:
  - learning_cert_keys table (issuer signing keys, rotation foundation)
  - learning_certificates table (issued credentials, key_id-linked)
  - CertKey entity with leakage-safe jsonSerialize (omits secret_key_enc)
  - Certificate entity (verification_id, key_id, credential_json, nullable expires_at)
  - CertKeyMapper (findByKeyId, findActive, findAllNonRevoked)
  - CertificateMapper (findByVerificationId, findByUserAndCourse idempotency, findByUserId)
  - 155-ADR-ANCHOR.md (frozen VC-JOSE-COSE signing contract for plans 02-07)
affects: [155-02-keyservice, 155-03-signingservice, 155-04-issuance, 155-05-controller, 155-07-phase-close]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Leakage-safe entity serialization: secret columns structurally absent from jsonSerialize()"
    - "Cross-DB migration: Types::* enums, no oc_ prefix, no index on TEXT, index names <=27 chars"
    - "key_id string reference (not FK) links certificates to signing keys for rotation"

key-files:
  created:
    - app/lib/Migration/Version009100Date20260627000000.php
    - app/lib/Db/CertKey.php
    - app/lib/Db/CertKeyMapper.php
    - app/lib/Db/Certificate.php
    - app/lib/Db/CertificateMapper.php
    - app/tests/Unit/Db/CertEntityTest.php
    - .planning/phases/155-certificate-artifact-issuer/155-ADR-ANCHOR.md
  modified: []

key-decisions:
  - "VC-JOSE-COSE signing contract frozen in 155-ADR-ANCHOR.md: header {alg:EdDSA,typ:vc+jwt,cty:vc,kid}, payload = OB3 object direct (no vc wrapper, no registered-claim mirroring)"
  - "secret_key_enc is TEXT (ICrypto ciphertext) and never enters jsonSerialize output"
  - "certificate -> key_id is a string reference (rotation: retired keys keep verifying past certs)"

patterns-established:
  - "Leakage primitive: jsonSerialize returns explicit allowlist of non-sensitive columns"
  - "Idempotency guard pattern: CertificateMapper::findByUserAndCourse for issuance"

requirements-completed: []  # deferred — see "Requirements Status" below (foundation-only, per 154 pattern)

# Metrics
duration: ~35min
completed: 2026-06-27
---

# Phase 155 Plan 01: Certificate Data Layer + ADR Anchor Summary

**Two cross-DB-safe tables (learning_cert_keys + learning_certificates), QBMapper entities with leakage-safe serialization, and the frozen VC-JOSE-COSE signing contract that plans 02-07 build on.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-06-27
- **Completed:** 2026-06-27
- **Tasks:** 3 (Task 3 is TDD: RED → GREEN)
- **Files modified:** 7 created

## Accomplishments
- Migration Version009100 creates both tables cross-DB-safe (PG16 + MariaDB 11.4): Types::* throughout, no oc_ prefix, credential_json (TEXT) unindexed, all index names <=27 chars
- CertKey::jsonSerialize() omits secret_key_enc by construction — the CERT-03 leakage primitive, proven by direct-output assertion (not summary-stripping)
- Certificate references its signing key_id — rotation foundation so retired keys keep verifying past certs
- Mappers expose the exact signatures plans 02-07 consume (incl. findByUserAndCourse idempotency guard for 155-04)
- 155-ADR-ANCHOR.md freezes the JWS header and routes ADR-001 follow-ups #1/#2 → 155-03, #3 → 155-07

## Task Commits

1. **Task 1: ADR anchor + VC-JOSE-COSE contract freeze** - `5dc56f9` (docs)
2. **Task 2: Migration Version009100 — two tables** - `40d364b` (feat)
3. **Task 3 (RED): failing CertEntityTest** - `23753f9` (test)
4. **Task 3 (GREEN): entities + mappers** - `970e903` (feat)

**Plan metadata:** _(final docs commit — this SUMMARY + STATE + ROADMAP)_

## Files Created/Modified
- `.planning/phases/155-certificate-artifact-issuer/155-ADR-ANCHOR.md` - Frozen signing contract + follow-up routing
- `app/lib/Migration/Version009100Date20260627000000.php` - Two-table migration
- `app/lib/Db/CertKey.php` - Issuer key entity; jsonSerialize omits secret_key_enc
- `app/lib/Db/CertKeyMapper.php` - findByKeyId / findActive / findAllNonRevoked
- `app/lib/Db/Certificate.php` - Issued credential entity (nullable expires_at)
- `app/lib/Db/CertificateMapper.php` - findByVerificationId / findByUserAndCourse / findByUserId
- `app/tests/Unit/Db/CertEntityTest.php` - 3 tests / 15 assertions (leakage + hydration)

## Requirements Status

CERT-03 / CERT-04 / CERT-06 are **deliberately left Pending** in REQUIREMENTS.md — this is a
schema-only entry-gate plan, so none of them is verifiably TRUE about the running system yet
(read as a TRUE/FALSE statement, the 154 foundation-deferral pattern):

- **CERT-03** (issuer private key stored encrypted at rest, ICrypto) — schema (`secret_key_enc` TEXT)
  + leakage primitive (`jsonSerialize` omits it) laid here; the actual keygen + ICrypto encrypt is
  **155-02 (KeyService)** → mark complete there.
- **CERT-04** (key rotation does not invalidate past certs) — rotation **foundation** laid (table +
  `findAllNonRevoked` + cert `key_id` linkage); no key can actually rotate until **155-02** → mark there.
- **CERT-06** (self-contained credential, fields embedded at signing) — an issuance/signing concern;
  no credential is issued yet → mark complete at **155-04 (IssuanceService)**.

The phase-close verifier (155-07) re-checks all three. `requirements mark-complete` was intentionally NOT run.

## Decisions Made
None beyond the plan — the signing contract and column shapes were specified in the plan's `<interfaces>` and ADR-001; this plan froze and implemented them as written.

## Deviations from Plan

None - plan executed exactly as written. No signing or key-generation code (entry-gate scope respected).

## Issues Encountered
- No local PHP on the workstation (`php` not installed) — linting and tests run only on the relay container. `php -l` performed via `docker exec -i devcloud-app php -l`; PHPStan + PHPUnit run on relay. Not a blocker.
- The deploy-prod.sh "Verifying deploy" step prints a pre-existing `Class "OCP\AppFramework\App" not found` fatal — it loads Application.php standalone via CLI without NC bootstrap. Harmless smoke quirk, unrelated to this change; PHPStan reported "No errors".

## Verification Results
- **php -l**: clean on the migration (via container).
- **PHPStan Level 5**: `No errors` (run on relay via `deploy-prod.sh --php-only`).
- **PHPUnit CertEntityTest**: `OK (3 tests, 15 assertions)` on relay (RED confirmed first: 3 class-not-found errors before implementation).
- **grep gates**: no `createTable('oc_`, no index on `credential_json`, all index names <=27 chars; anchor doc contains `vc+jwt` and `` NO `vc` wrapper ``.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Data layer + frozen contract ready for 155-02 (KeyService: sodium keygen + ICrypto encrypt + rotation + did.json).
- Migration is NOT yet applied to any DB and info.xml is NOT bumped — cross-DB go/no-go (PG16 + MariaDB 11.4) runs in 155-07; version bump is the v5.0.0 release plan's job (carry-forward from Phase 154 notes).
- ADR follow-ups registered: #1/#2 → 155-03, #3 → 155-07.

## Self-Check: PASSED

All 7 created files present on disk; all 4 task commits (5dc56f9, 40d364b, 23753f9, 970e903) exist in git history.

---
*Phase: 155-certificate-artifact-issuer*
*Completed: 2026-06-27*
