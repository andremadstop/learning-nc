---
phase: 157-public-verify
plan: 02
subsystem: service
tags: [certificate, verification, ed25519, vc-jwt, claim-binding, dsgvo, tdd]

# Dependency graph
requires:
  - phase: 155-cert-artifact
    provides: "SigningService::verify (Ed25519 detached + header-contract), CertKeyMapper::findByKeyId, CertificateMapper::findByVerificationId, KeyService::hostDid, frozen VC-JWT contract"
  - phase: 157-public-verify
    plan: 01
    provides: "Certificate::getRevokedAt() — read in the WITHDRAWN tombstone branch"
provides:
  - "CertificateVerifyService::verifyByVerificationId(string $vid): array — resolve -> key-by-id+status -> sig -> claim-binding -> status precedence -> DSGVO DTO"
  - "Reusable verify-before-decode primitive (a future 156 hardening pass can adopt it)"
affects: [157-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Claim binding in the verify SERVICE (kid==hostDid#key_id AND issuer.id==hostDid AND id==urn:uuid:vid) on top of SigningService's bytes-only verify — a valid signature alone never passes"
    - "Key resolved via findByKeyId(cert.key_id) + status!=revoked filter (CertKeyMapper returns revoked rows too) — rotation-correct, did.json-consistent"
    - "Server-side DSGVO DTO projection (never Certificate::jsonSerialize()); hostile payload returns invalid, never throws (try/catch \\Throwable + is_string guards + hash_equals)"

key-files:
  created:
    - app/lib/Service/CertificateVerifyService.php
    - app/tests/Unit/Service/CertificateVerifyServiceTest.php
  modified: []

key-decisions:
  - "Claim binding lives in CertificateVerifyService, NOT in SigningService (whose frozen contract stays bytes+header-only) — Codex #2 substitution fix without weakening the 155 signer"
  - "A revoked signing key forces 'invalid' even when the bytes verify (Codex #1 trust-anchor: did.json publishes only non-revoked keys)"
  - "PhpUnitStubs needed NO additions — real SigningService + mocked CertificateMapper/CertKeyMapper/KeyService/ITimeFactory all resolve in the existing bootstrap"
  - "hash_equals used for kid/issuer/id binding comparisons (constant-time, defensive; values are not secrets but the discipline is free)"

patterns-established:
  - "decodeAndBind(): strict base64url JSON decode of header+payload (mirrors CertificateReportService::decodePayload) then ALL-of claim binding; any decode failure / non-string / mismatch -> null -> invalid"
  - "Status precedence as additive array spread: ['status'=>X] + $dto so the DTO keys are fixed and the status leads"

requirements-completed: []  # VERIFY-02/03/04/05 backend-proven here; flip at 157 close after live Playwright + provisioning (155-style deferral)

# Metrics
duration: 17min
completed: 2026-06-27
---

# Phase 157 Plan 02: CertificateVerifyService Summary

**The cryptographic verification core of the public verify route: `verifyByVerificationId($vid)` resolves the cert, verifies the Ed25519 signature against the key that SIGNED it (rotation-correct), binds the claim (kid + issuer.id + credential id — not just the bytes), applies the locked status precedence (unknown/invalid/withdrawn/expired/valid), and projects a strict DSGVO DTO that leaks no recipient identity — 10/10 TDD GREEN, PHPStan L5 clean.**

## Performance

- **Duration:** ~17 min
- **Tasks:** 2 (RED test, GREEN implementation)
- **Files created:** 2 (service + test)

## Accomplishments

- `CertificateVerifyService::verifyByVerificationId(string $vid): array` — the heart of Phase 157. Verdict precedence (LOCKED): not-found → `unknown`; key unresolved / key status `revoked` / wrong key length / sig fail / claim mismatch / bad payload → `invalid`; revoked → `withdrawn` (+`revoked_at`); `expires_at !== null && < now` → `expired`; else `valid`.
- **"valid" is an AND, never a single signature.** All of: cert+key resolve, key.status != revoked (Codex #1 — `CertKeyMapper::findByKeyId` returns revoked rows; did.json disowns them), `public_key_b64u` decodes to exactly 32 bytes, `SigningService::verify` (reused verbatim — header-contract gate + sodium detached over `header.payload`), AND claim binding (Codex #2): header `kid === hostDid().'#'.cert.key_id`, payload `issuer.id === hostDid()`, payload `id === 'urn:uuid:'.$vid`.
- **Rotation correctness:** the cert is verified against `findByKeyId($cert->getKeyId())` — the key that signed it, NOT the active key. A `retired` (non-revoked) key still verifies its own cert. Asserted with `->with($cert->getKeyId())`.
- **DSGVO DTO (VERIFY-03):** projected server-side, exposing ONLY `issuer_name`, `course_title`, `issued_at`, `expires_at`, `verification_id` (+ `revoked_at` in the withdrawn branch). `credentialSubject.name` (recipient PII) is never read; `Certificate::jsonSerialize()` (emits user_id + credential_json) is never called.
- **Hostile-input safe:** `decodeAndBind()` wraps the header/payload decode in `try/catch(\Throwable)` and guards every field with `is_string()` before `hash_equals()`, so a malformed JWT or an `issuer`-as-string payload returns `invalid` instead of throwing a `TypeError` to the public route.
- 10 PHPUnit cases (8 plan behaviors + 2 strengthening sub-cases: foreign-issuer binding, not-found unknown) GREEN; 29 assertions. PHPStan L5 clean. Leak grep gate clean.

## Task Commits

1. **Task 1 (RED):** `4638e69` (test) — 10 failing cases; confirmed RED for the right reason (class not found, fixtures construct).
2. **Task 2 (GREEN):** `85b6321` (feat) — implementation; 10/10 GREEN, PHPStan L5 clean.

## Files Created/Modified

- `app/lib/Service/CertificateVerifyService.php` — verify core (resolve → key-by-id+status → sig → claim-binding → precedence → DSGVO DTO). DI-autowired (CertificateMapper, CertKeyMapper, SigningService, KeyService, ITimeFactory) — no Application.php registration.
- `app/tests/Unit/Service/CertificateVerifyServiceTest.php` — real SigningService over a throwaway sodium keypair (signature path exercised for real, never mocked); only mappers + `KeyService::hostDid()` + clock mocked. Signer and service share ONE KeyService mock so the kid binds by construction.

## Decisions Made

- **Claim binding in THIS service, not SigningService.** The 155 signer's verify contract (alg/typ/cty + bytes) stays frozen; the substitution defence (Codex #2) is layered on top here. Otherwise a `credential_json` lifted from a different cert signed by the same key would pass byte-verify.
- **Discriminating tests (not trivially-passing):** `testClaimBindingRejectsSubstitution` signs `id=urn:uuid:OTHER` with the SAME key and proves the SAME JWT is `valid` under its own matching vid (positive control → it was the id-binding rejecting, not a sig failure). `testRevokedSigningKeyInvalid` uses a really-signed JWT (status overrides a GOOD signature). `testVerifiesAgainstCertKeyNotActiveKey` asserts `findByKeyId(->with(cert.key_id))`.
- **No PhpUnitStubs additions.** The error was purely class-not-found; `ITimeFactory`, `DoesNotExistException`, `Entity`, `QBMapper` already present. `KeyService` mocks fine as a concrete class (`hostDid()` only).
- **`hash_equals` for the binding comparisons** — constant-time discipline (the values aren't secrets, but it's free defence and reads as intent).

## Deviations from Plan

None — plan executed as written. No Rule 1-4 deviations. (Added 2 extra test cases — `testClaimBindingRejectsForeignIssuer`, `testUnknownWhenNotFound` — strengthening the claim-binding and not-found branches; additive within scope, not a deviation.)

## Carry-Forward (route as follow-ups; do NOT reopen 156 here)

- **156 verify-before-decode primitive:** `CertificateReportService::decodePayload` reads name+score from an UNVERIFIED JWT (a tampered DB row → false compliance report). `CertificateVerifyService` is now the reusable verify-before-decode core a future 156 hardening pass can adopt. Flagged only — 156 is NOT reopened in this phase.
- **157-04 (PublicVerifyController)** consumes `verifyByVerificationId()`: render the four states; `throttle()` only on the `unknown`/malformed branch; do the UUID format check BEFORE the DB query (anti-enumeration); never distinguish malformed from not-found. Controller owns not-found/malformed UX — the service already returns `unknown` for not-found.
- **VERIFY-02/03/04/05 NOT flipped** — backend-proven (unit + static) here; they flip at 157 close after the live Playwright logged-out leak check + the 157-05 provisioning pass (155-style deferral). `requirements mark-complete` NOT run.

## Verification (Gate 1 + plan criteria)

- `--filter CertificateVerifyServiceTest` → **10/10 GREEN** (29 assertions) on relay (test scp+docker cp'd — `--php-only` does not sync `tests/`).
- PHPStan L5 → **No errors**.
- Leak grep gate on the service: only the necessary `getCredentialJson()` reads (feeding sig-verify + claim-decode, never projected — `testDtoNoLeak` asserts the JWT is absent from the serialized DTO) and defensive doc/container reads of `$payload['credentialSubject']` (achievement.name only; never `.name`). No `user_id` projection.
- No-leak DTO (VERIFY-03): `testDtoNoLeak` asserts "Jürgen Müller", `jmueller` (user_id), `key_id`, `credential_json`, the raw JWT, and `active_idem_key` are all absent from `json_encode($dto)`, and that the valid DTO exposes EXACTLY the 6 allow-listed keys.

## Self-Check: PASSED

- FOUND: app/lib/Service/CertificateVerifyService.php
- FOUND: app/tests/Unit/Service/CertificateVerifyServiceTest.php
- FOUND: .planning/phases/157-public-verify/157-02-SUMMARY.md
- FOUND commit: 4638e69 (Task 1 RED)
- FOUND commit: 85b6321 (Task 2 GREEN)

---
*Phase: 157-public-verify*
*Completed: 2026-06-27*
