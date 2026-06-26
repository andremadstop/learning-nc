---
phase: 155-certificate-artifact-issuer
plan: 02
subsystem: issuer-identity
tags: [certificates, ed25519, sodium, icrypto, did-web, key-rotation, occ-command, public-route, vc-jwt]

# Dependency graph
requires:
  - phase: 155-01
    provides: CertKey entity + CertKeyMapper (findActive/findAllNonRevoked), learning_cert_keys table, frozen VC-JOSE-COSE contract
provides:
  - KeyService (init / rotate / getActiveSigningMaterial / hostDid) — sodium keygen + ICrypto encrypt-at-rest
  - learning:cert:init-issuer OCC command (+ --rotate)
  - Public did.json route (DidController) publishing ALL non-revoked verificationMethods
  - did:web:<host>:apps:learning identity + kid==verificationMethod.id single source of truth (KeyService::hostDid)
affects: [155-03-signingservice, 155-04-issuance, 155-07-phase-close]

# Tech tracking
tech-stack:
  added: []   # ext-sodium is a PHP core extension already present; no Composer deps (per ADR)
  patterns:
    - "Encrypt-at-rest: secret base64 -> EncryptionService.encrypt() -> store ciphertext only; sodium_memzero plaintext"
    - "Plaintext-fallback defeat: getActiveSigningMaterial() hard-errors unless decrypt yields exactly 64 bytes"
    - "Rotation = update old active to 'retired' (never delete); did.json serves all non-revoked keys; kid selects"
    - "Single-source did:web: KeyService::hostDid() used by both the command output and DidController so kid never drifts"

key-files:
  created:
    - app/lib/Service/KeyService.php
    - app/lib/Command/InitIssuerCommand.php
    - app/lib/Controller/DidController.php
    - app/tests/Unit/Service/KeyServiceTest.php
  modified:
    - app/appinfo/info.xml
    - app/appinfo/routes.php
    - app/lib/AppInfo/Application.php

key-decisions:
  - "DidController reuses KeyService::hostDid() (not a private copy) so verificationMethod.id and the future JWT kid share one formula — eliminates the Pitfall-4 kid-drift risk by construction"
  - "keyId == base64url(public key) — deterministic, no extra randomness, and the JWK `x` value equals the keyId fragment source"
  - "did.json emitted as plain JSONResponse (application/json), per plan + RESEARCH Pattern 2; did:web resolvers commonly accept it. Flagged for 155-07 to revisit if its curl assertion demands application/did+json"
  - "Requirements CERT-01..04 left Pending — code complete + unit/static-proven, but live occ/curl needs the 155-01 migration applied (155-07). Marking deferred to 155-07, consistent with the 154/155-01 deferral discipline"

# Metrics
duration: ~40min
completed: 2026-06-27
---

# Phase 155 Plan 02: KeyService + Issuer Identity (did:web) Summary

**The NC instance's cryptographic issuer identity: an `occ learning:cert:init-issuer` command that generates an Ed25519 keypair with the private key ICrypto-encrypted at rest, plus a public `did.json` resolving every non-revoked public key — rotation built in from day one. No signing code (155-03 gate respected).**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-06-27
- **Completed:** 2026-06-27
- **Tasks:** 3 (Task 1 is TDD: RED -> GREEN)
- **Files:** 4 created, 3 modified

## Accomplishments
- **KeyService** generates an Ed25519 keypair via `ext-sodium`, stores ONLY the ICrypto ciphertext of the base64 secret, and `sodium_memzero`s every plaintext copy. `init()` refuses to create a second active key (points the admin at `--rotate`).
- **Encrypt-at-rest is enforced, not assumed** — `init()` treats a null/empty/passthrough ciphertext as a hard failure, and `getActiveSigningMaterial()` rejects any decrypt that is not exactly 64 bytes, defeating `EncryptionService::decrypt()`'s silent plaintext fallback (the silent-corruption trap called out in the plan `<interfaces>`).
- **Rotation never deletes** — `rotate()` flips the current active key to `retired` (UPDATE) then inserts a fresh active key; both stay in `findAllNonRevoked()` so past certificates keep verifying (CERT-04 linchpin).
- **`learning:cert:init-issuer` OCC command** (mirrors `ExportCourseCommand`), registered in BOTH `info.xml <commands>` and `Application.php` DI; prints `key_id`, the `did:web` identifier, and the full `kid` (`did#key_id`) so the admin can eyeball did.json alignment.
- **Public `did.json`** (`DidController`, `@PublicPage @NoCSRFRequired`, IcsController NC-33 docblock style) at `GET /apps/learning/did.json` — builds a `verificationMethod` for every non-revoked key with `publicKeyJwk {kty:OKP,crv:Ed25519,x:<b64u>}`, `@context [did/v1, jws-2020/v1]`, `assertionMethod` mirroring the vm ids (RESEARCH Pattern 2 verbatim). Only public material is ever serialized.
- **kid never drifts** — `DidController` and the command both derive the did:web string from `KeyService::hostDid()`, so `verificationMethod.id` and the 155-03 JWT `kid` come from one formula.

## Task Commits

1. **Task 1 (RED): failing KeyServiceTest** - `b2ef7f9` (test)
2. **Task 1 (GREEN): KeyService — keygen + encrypt-at-rest + rotation** - `c44bc00` (feat)
3. **Task 2: InitIssuerCommand (occ) + DI + info.xml** - `e5b9e1c` (feat)
4. **Task 3: DidController — public did.json (all non-revoked keys)** - `5a51940` (feat)

**Plan metadata:** _(final docs commit — this SUMMARY + STATE + ROADMAP)_

## Files Created/Modified
- `app/lib/Service/KeyService.php` - init/rotate/getActiveSigningMaterial/hostDid; sodium keygen + ICrypto encrypt-at-rest + 64-byte hard validation
- `app/lib/Command/InitIssuerCommand.php` - `learning:cert:init-issuer` (+ `--rotate`); prints key_id/did/kid
- `app/lib/Controller/DidController.php` - public did.json; verificationMethod from findAllNonRevoked(); publicKeyJwk only
- `app/tests/Unit/Service/KeyServiceTest.php` - 4 tests / 14 assertions (encrypt-at-rest, rotation-preserves, no-double-active, hard-error-on-bad-decrypt)
- `app/appinfo/info.xml` - registered `InitIssuerCommand` in `<commands>`
- `app/appinfo/routes.php` - `did#index` -> `/did.json` GET
- `app/lib/AppInfo/Application.php` - DI `registerService(InitIssuerCommand::class, ...)` + use import

## Requirements Status

**CERT-01..04 deliberately left Pending** in REQUIREMENTS.md — `requirements mark-complete` was intentionally NOT run.

The code for all four is complete and unit/static-proven, but read as live TRUE/FALSE statements about the running system, none is verifiable yet because **the 155-01 migration is not applied to any DB** (cross-DB go/no-go + version bump is 155-07's job). A live `occ learning:cert:init-issuer` would fail with "table learning_cert_keys does not exist", and the live did.json curl is explicitly routed to Gate 2 / 155-07.

- **CERT-01** (occ init -> Ed25519 keypair + did:web) — command implemented + registered (both info.xml and DI); KeyService.init() unit-proven. Live occ deferred to 155-07.
- **CERT-02** (resolvable public did.json) — DidController + route implemented; build logic = RESEARCH Pattern 2. Live unauthenticated curl + kid-alignment is the 155-07 / ADR follow-up #3 assertion.
- **CERT-03** (private key encrypted at rest, ICrypto, never plaintext) — `init()` stores ciphertext only, zeroes plaintext, refuses passthrough; `getActiveSigningMaterial()` rejects non-64-byte decrypts. Unit-proven (encrypt-at-rest test).
- **CERT-04** (rotation does not invalidate past certs) — `rotate()` retires-not-deletes; `did.json` serves all non-revoked keys. Unit-proven (rotation-preserves test).

155-07 marks all four complete after applying the migration and running the live occ + curl assertions.

## Decisions Made
- **DidController reuses `KeyService::hostDid()`** rather than re-deriving the did:web string — one formula for `verificationMethod.id` and the 155-03 JWT `kid`, killing kid-drift (Pitfall 4) structurally.
- **`keyId = base64url(public key)`** — deterministic; the JWK `x` source and the kid fragment are the same value.
- **did.json content-type is `application/json`** (plain `JSONResponse`, per plan + Pattern 2). did:web resolvers generally accept it; recorded here so 155-07 can switch to `application/did+json` if its curl assertion is strict.

## Deviations from Plan

None - plan executed exactly as written. No signing code (the non-negotiable 155-03 gate was respected: this plan is key generation + encrypt-at-rest + did resolution + rotation foundation only).

## Issues Encountered
- **No local PHP on the workstation** — `php` not installed; lint via `docker exec -i devcloud-app php -l`, PHPStan + PHPUnit run on the relay container. (Carried from 155-01.)
- **`deploy-prod.sh --php-only` does not sync `tests/`** — only `lib/` + `l10n`. The new `KeyServiceTest.php` had to be `scp`'d to the host and `docker cp`'d into the container before PHPUnit could see it ("No tests executed" until then). Noted for future test-bearing plans on this deploy path.
- **The `deploy-prod.sh` "Verifying deploy" step prints a pre-existing harmless `Class "OCP\AppFramework\App" not found` fatal** — it loads Application.php standalone via CLI without the NC bootstrap. Unrelated to this change; PHPStan still reports "No errors". (Carried from 155-01.)

## Verification Results
- **KeyServiceTest**: `OK (4 tests, 14 assertions)` on relay (RED confirmed first: class-not-found before KeyService existed). Behaviours: encrypt-at-rest (+ round-trip to 64-byte secret), rotation-preserves (retired not deleted, both non-revoked), no-double-active (init throws), hard-error-on-bad-decrypt.
- **php -l**: clean on KeyService, InitIssuerCommand, DidController (via container).
- **PHPStan Level 5**: `No errors` (run on relay via `deploy-prod.sh --php-only`, twice — after Task 1 and after Task 3).
- **grep gates**: `InitIssuerCommand` present in BOTH info.xml and Application.php; `did#index` in routes.php; `PublicPage` + `publicKeyJwk` in DidController.

## User Setup Required
None for this plan. (Post-release, an admin runs `occ learning:cert:init-issuer` once per instance — but that requires the migration applied, which is 155-07 / the v5.0.0 release plan.)

## Next Phase Readiness
- **155-03 (SigningService)** can now resolve signing material via `KeyService::getActiveSigningMaterial()` (returns `{key: CertKey, secret: 64-byte}`) and must emit a JWT whose `kid` == `KeyService::hostDid() . '#' . key->getKeyId()` == the `verificationMethod.id` in did.json. ADR follow-ups #1 (encoding correctness) + #2 (independent Python verifier) land there.
- **155-07** applies the 155-01 migration (cross-DB PG16 + MariaDB 11.4), runs the live `occ learning:cert:init-issuer` smoke + the `curl did.json` kid-alignment assertion (ADR follow-up #3), and marks CERT-01..04 complete.
- **Carry-forward:** migration still NOT applied + info.xml NOT version-bumped (release plan's job).

## Self-Check: PASSED

All 4 created files present on disk; all 3 modified files contain the expected references; all 4 task commits (b2ef7f9, c44bc00, e5b9e1c, 5a51940) exist in git history.

---
*Phase: 155-certificate-artifact-issuer*
*Completed: 2026-06-27*
