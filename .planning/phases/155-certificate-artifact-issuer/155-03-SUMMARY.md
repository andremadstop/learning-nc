---
phase: 155-certificate-artifact-issuer
plan: 03
subsystem: signing-core
tags: [certificates, ed25519, sodium, vc-jwt, vc-jose-cose, eddsa, did-web, independent-verifier, tdd]

# Dependency graph
requires:
  - phase: 155-02
    provides: KeyService (hostDid / getActiveSigningMaterial), CertKey entity, did:web identity + kid==verificationMethod.id single source of truth
provides:
  - SigningService.sign(credential, CertKey, secretRaw) → compact VC-JWT (EdDSA, ext-sodium, no deps)
  - SigningService.verify(jwt, publicRaw) → bool (sodium_crypto_sign_verify_detached)
  - SigningService.b64u() base64url helper (public, reusable)
  - scripts/verify-credential.py — dev-only INDEPENDENT Python (cryptography) Ed25519 JWS verifier (ADR-001 follow-up #2)
  - Frozen header/payload encoding proven by byte-stability + header-contract tests (ADR-001 follow-up #1)
affects: [155-04-issuance, 155-07-phase-close]

# Tech tracking
tech-stack:
  added: []   # ext-sodium is PHP core; Python cryptography is a DEV-ONLY container/test module, never an app dep
  patterns:
    - "VC-JWT sign: b64u(json_encode(header, JSON_UNESCAPED_SLASHES)) . '.' . b64u(json_encode(payload, JSON_UNESCAPED_SLASHES)) . '.' . b64u(sodium_crypto_sign_detached(...)) — signed bytes == emitted bytes, zero canonicalization"
    - "Payload = OB3 credential object DIRECTLY — no vc/vp wrapper, no iss/sub/nbf/jti registered-claim mirroring (VC-JWT 1.1 forbidden)"
    - "kid = KeyService::hostDid() . '#' . key->getKeyId() — SAME source as DidController.verificationMethod.id, kid-drift impossible by construction"
    - "Independent verifier (Python cryptography Ed25519) proves third-party verifiability — automates ADR-001 follow-up #2, not prose"

key-files:
  created:
    - app/lib/Service/SigningService.php
    - app/tests/Unit/Service/SigningServiceTest.php
    - scripts/verify-credential.py
  modified:
    - .gitignore

key-decisions:
  - "SigningService injects KeyService and calls hostDid() (NOT a private parse_url re-derivation) so the JWT kid is the SAME string as DidController.verificationMethod.id by construction — resolves the plan Task-2 'host from IURLGenerator' wording in favour of the must_haves key_link + 155-02 handoff (kill kid-drift, Pitfall 4)"
  - "Independent verifier uses argv (jwt + base64url x) rather than a tmp file — cleaner; Task-2 'read jwt + x from argv' is the implementation spec"
  - "Test 5 runs for real, never silently skips: python3 present in the dev container; cryptography installed via apt (python3-cryptography 43.0.0). markTestSkipped only if python3 itself is absent (per plan). A skip would falsely read as a pass — avoided"
  - "verify-credential.py lives in repo-root scripts/, which the release Makefile allowlist (appinfo/css/img/js/lib/templates) AND the deploy bundle both EXCLUDE — dev-only by construction; flagged for the 155-07 leakage audit. Added an explicit !scripts/verify-credential.py .gitignore negation so the deliverable is tracked"
  - "CERT-06 left Pending — 155-03 delivers the signing MECHANISM (proven incl. independent verify), but CERT-06's substance ('all fields embedded at signing time, self-contained credential') is realized when IssuanceService (155-04) builds the OB3 object; live cert + cross-DB verification is 155-07. Consistent with the 155-02 CERT-01..04 deferral discipline"

# Metrics
duration: ~35min
completed: 2026-06-27
---

# Phase 155 Plan 03: SigningService (VC-JWT EdDSA Signer) Summary

**The ~80-LOC signing core that turns an OB3 credential object into a compact VC-JWT, signed with Ed25519 via `ext-sodium` (no Composer deps) per the FROZEN 155-ADR-ANCHOR contract — and proven verifiable not only by the app but by an INDEPENDENT Python `cryptography` Ed25519 verifier (ADR-001 follow-ups #1 + #2 automated, not prose). Strict TDD: 5 tests RED → GREEN, byte-stability + tamper + header-contract + third-party-verify all green.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-06-27
- **Completed:** 2026-06-27
- **Tasks:** 3 (TDD: RED → GREEN → REFACTOR; REFACTOR was a no-op, no commit per plan)
- **Files:** 3 created, 1 modified

## Accomplishments
- **SigningService.sign()** builds the FROZEN JWS header `{alg:EdDSA, typ:vc+jwt, cty:vc, kid}` and signs `base64url(header).base64url(payload)` with `sodium_crypto_sign_detached`. The payload is the credential object DIRECTLY — no `vc`/`vp` wrapper, no `iss`/`sub`/`nbf`/`jti` mirroring (VC-JWT 1.1 is forbidden by the anchor).
- **Byte fidelity is structural** — both header and payload are serialized with `JSON_UNESCAPED_SLASHES`, so the SIGNED bytes are exactly the bytes that get base64url-encoded and emitted. There is no canonicalization path. The byte-stability test asserts `b64uDecode(payloadSegment) === json_encode(credential, JSON_UNESCAPED_SLASHES)` (ADR-001 follow-up #1).
- **kid never drifts** — SigningService injects `KeyService` and derives the kid from `hostDid() . '#' . key->getKeyId()`, the SAME formula DidController uses for `verificationMethod.id`. Re-deriving via `parse_url` would have re-introduced the Pitfall-4 kid-drift the 155-02 design eliminated; the must_haves key_link ("same string as DidController") and the 155-02 handoff (line 124) settle it decisively.
- **Independent third-party verification** — `scripts/verify-credential.py` (Python `cryptography`, `Ed25519PublicKey.from_public_bytes`) verifies the signature over `header.payload` using ONLY the base64url JWK `x` from did.json. Test 5 shells out to it and asserts exit 0 on a valid JWT AND non-zero on a tampered one — automating ADR-001 follow-up #2 (the in-app `verify()` is NOT the only verifier).
- **verify()** round-trips via `sodium_crypto_sign_verify_detached` (catches `SodiumException` on malformed input → false), and the public `b64u()` helper is reusable by 155-04's issuance path.
- **Dev-only verifier, by construction** — `verify-credential.py` sits in repo-root `scripts/`, excluded from both the release Makefile allowlist and the deploy bundle; it ships nowhere. NO `package.json`/`composer.json` dependency was added.

## Task Commits

1. **Task 1 (RED): failing SigningServiceTest** — `13520e9` (test) — 5 cases, RED confirmed in-container (4× class-not-found + Test 5 script-not-resolvable; Test 5 genuinely executed)
2. **Task 2 (GREEN): SigningService + verify-credential.py + .gitignore negation** — `74578bb` (feat) — 5/5 green, 17 assertions, PHPStan L5 clean
3. **Task 3 (REFACTOR):** _no commit_ — code already typed/docblocked, `b64u` helper extracted, no dead code, PHPStan L5 green; nothing to change (plan permits skipping)

**Plan metadata:** _(final docs commit — this SUMMARY + STATE + ROADMAP)_

## Files Created/Modified
- `app/lib/Service/SigningService.php` — sign/verify/b64u; EdDSA VC-JWT per frozen anchor; ~80 LOC incl. docblocks
- `app/tests/Unit/Service/SigningServiceTest.php` — 5 tests / 17 assertions (round-trip, tamper-payload, header-contract+no-vc, byte-stability, independent-python-verify)
- `scripts/verify-credential.py` — dev-only independent Ed25519 JWS verifier (Python cryptography); argv: `<jwt> <base64url-x>`; exit 0 valid / 1 invalid / 2 usage / 3 malformed
- `.gitignore` — added `!scripts/verify-credential.py` negation (the `scripts/*` blanket-ignore otherwise dropped the deliverable)

## Requirements Status

**CERT-06 deliberately left Pending** in REQUIREMENTS.md — `requirements mark-complete` was intentionally NOT run.

CERT-06 reads "*Each credential is self-contained — course, score, threshold, issue/expiry dates, issuer, verification-id embedded at signing time*." 155-03 delivers the signing **mechanism** (and proves byte-fidelity + independent verifiability), but the *self-contained, all-fields-embedded* substance is realized when **IssuanceService (155-04)** assembles the OB3 credential object and hands it to `sign()`. Live issuance + cross-DB verification is 155-07. This mirrors the 155-02 CERT-01..04 deferral discipline (mark complete only when live-verifiable as a TRUE/FALSE statement about the running system).

- **CERT-06** (self-contained credential) — signing core complete + unit/independent-proven; field-embedding is 155-04, live verification 155-07.

## Decisions Made
- **Inject `KeyService`, kid via `hostDid()`** — not a second `parse_url` derivation. Guarantees kid == `DidController.verificationMethod.id` by construction (key_link + 155-02 handoff > the plan's under-specified "host from IURLGenerator" wording, which merely describes what `hostDid()` does internally).
- **Independent verifier via argv** (`jwt`, `base64url x`) rather than a tmp file — cleaner; matches the Task-2 implementation spec.
- **Test 5 genuinely runs** — `python3-cryptography` (43.0.0) installed in the dev container via `apt-get` (no pip/ensurepip in the image). A `markTestSkipped` would have read as a pass; the headline ADR follow-up #2 must actually execute. 155-07 re-runs this on a REAL issued cert and MUST fail if no independent verifier is available.
- **Dev-only verifier confirmed structural** — repo-root `scripts/` is outside the release Makefile allowlist and the deploy bundle; nothing extra needed beyond the gitignore negation to track the file. Flagged for the 155-07 leakage audit.

## Deviations from Plan

None of substance — plan executed as written. Two clarifications:
- **kid derivation:** the plan Task-2 line "Host from IURLGenerator (parse_url base URL host)" was satisfied via `KeyService::hostDid()` (which does exactly that parse_url internally) rather than a duplicate derivation, honouring the must_haves key_link "same string as DidController". No behavioural difference; eliminates kid-drift.
- **[Rule 3 - Blocking] `.gitignore` negation added:** `scripts/*` blanket-ignored the new deliverable. Added `!scripts/verify-credential.py` so the required artifact is tracked (folded into the GREEN commit). Out-of-scope: none.

## Issues Encountered
- **Container lacked Python `cryptography`** — the plan assumed it was "installed here", but the relay `devcloud-app` container had `python3` without `cryptography`, and no `pip`/`pip3`/`ensurepip`. Installed `python3-cryptography` (43.0.0) via `apt-get install` (as root in the container) so Test 5 runs for real. **Caveat:** this install does NOT persist across a container rebuild — 155-07's independent-verify gate must (re)ensure `cryptography` is available wherever it runs, or its assertion will error rather than pass/fail cleanly.
- **`deploy-prod.sh --php-only` does not sync `tests/`** (carried from 155-01/02) — `SigningServiceTest.php` was `scp`'d to the host and `docker cp`'d into the container; `verify-credential.py` was `scp`'d to `relais:/tmp` then `docker cp`'d to `/tmp/verify-credential.py`, with phpunit invoked as `docker exec -e VERIFY_SCRIPT=/tmp/verify-credential.py ...`.
- **`deploy-prod.sh` "Verifying deploy" prints a harmless `Class "OCP\AppFramework\App" not found`** (standalone-CLI smoke without NC bootstrap, carried from 155-01/02) — unrelated; PHPStan still reports "No errors".
- **No local PHP on the workstation** — `php -l` and phpunit run in the container; the Python verifier was self-tested locally (workstation has cryptography 43.0.3) before deploy: exit 0 valid / exit 1 tampered.

## Verification Results
- **SigningServiceTest**: `OK (5 tests, 17 assertions)` in the relay container (RED confirmed first: 4× class-not-found + Test 5 script-not-resolvable — Test 5 genuinely executed, not skipped). Behaviours: round-trip-verifies, tampered-payload-fails, header-contract (exact `{alg,typ,cty,kid}` + no `vc`/`vp`/`iss`/`sub`/`nbf`/`jti`), byte-stability (no canonicalization), independent-python-verify (valid→exit 0, tampered→non-zero).
- **php -l**: clean on `SigningService.php` (via container).
- **PHPStan Level 5**: `No errors` (run on relay via `deploy-prod.sh --php-only`).
- **Independent verifier (Python cryptography 43.0.0/43.0.3)**: accepts valid JWT (exit 0, "OK: ... independently verified by Python cryptography"), rejects tampered JWT (exit 1, "FAIL: Ed25519 signature is invalid") — proven both locally and inside the container test.
- **No new app dependency**: `package.json`/`composer.json` untouched; `verify-credential.py` excluded from the release Makefile allowlist + deploy bundle.

## User Setup Required
None for this plan. (155-07's leakage audit must confirm `verify-credential.py` is absent from the app package, and its independent-verify gate needs `python3` + `cryptography` available wherever it runs — `apt-get install python3-cryptography` in the container if rebuilt.)

## Next Phase Readiness
- **155-04 (IssuanceService)** can now build the OB3 credential object (course/score/threshold/dates/issuer/verification-id) and call `SigningService::sign($ob3, $material['key'], $material['secret'])` with `$material = KeyService::getActiveSigningMaterial()`. The emitted JWT's `kid` is already `verificationMethod.id` — no extra wiring. CERT-06 flips at the issuance/phase-close boundary.
- **155-07** re-runs the independent verifier on a REAL issued cert (ADR follow-up #2 on live data), asserts kid ↔ did.json (#3, `test-api.sh`), runs the leakage audit (Rule 18 — confirm `verify-credential.py` + secret material never ship), and cross-DB go/no-go (PG16 + MariaDB 11.4).
- **Carry-forward:** migration still NOT applied + info.xml NOT version-bumped (release plan's job); container `python3-cryptography` install is non-persistent.

## Self-Check: PASSED

- Files on disk: `app/lib/Service/SigningService.php` FOUND, `app/tests/Unit/Service/SigningServiceTest.php` FOUND, `scripts/verify-credential.py` FOUND (tracked via gitignore negation).
- Commits in history: `13520e9` (RED test) FOUND, `74578bb` (GREEN feat, incl. SigningService + verify-credential.py + .gitignore) FOUND.
- Tests: 5/5 green, 17 assertions, independent verifier executed (not skipped); PHPStan L5 clean.

---
*Phase: 155-certificate-artifact-issuer*
*Completed: 2026-06-27*
