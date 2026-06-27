---
phase: 155-certificate-artifact-issuer
plan: 05
subsystem: certificate-read-api
tags: [certificates, ob3, vc-jwt, jsonld, enveloped-vc, ownership, idor, http-api]

# Dependency graph
requires:
  - phase: 155-04
    provides: IssuanceService persists Certificate (credential_json = compact VC-JWT); CertificateMapper findByUserId/findByVerificationId
  - phase: 155-01
    provides: Certificate entity + CertificateMapper + learning_certificates schema
provides:
  - CertificateController.index() — list the authenticated user's own certificates (JSONResponse, @NoAdminRequired)
  - CertificateController.show(vid) — view one own cert; 404 unknown, 403 (bare) foreign
  - CertificateController.download(vid, format=jsonld|jwt) — DEFAULT OB3 JSON-LD EnvelopedVerifiableCredential (application/ld+json, CERT-09); ?format=jwt → raw compact JWT (application/vc+jwt)
  - CertificateService.js — listCertificates() / getCertificate(vid) / downloadUrl(vid, format) thin axios client for the 155-06 UI
affects: [155-06-certificate-vue, 155-07-phase-close]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Owner-scoped read controller: every endpoint authorizes against the DI-injected ?string $userId (never a caller-supplied id); show/download 403 a foreign cert with a BARE error (no cert body) and 404 a missing one — no IDOR"
    - "OB3 JSON-LD download = EnvelopedVerifiableCredential wrapping the compact VC-JWT ({'@context':[VC v2],'type':'EnvelopedVerifiableCredential','id':'data:application/vc+jwt,<jwt>'}) — the spec-correct way to present a JWT-secured VC as JSON-LD; json_encode with JSON_UNESCAPED_SLASHES"
    - "download() typed `: Response` (supertype) so the success DataDownloadResponse and the 403/404 JSONResponse co-exist under PHPStan L5 — mirrors IcsController::feed()"
    - "userId injected as ?string ctor param (mirrors IcsController), NOT IUserSession->getUID() — no controller in this codebase uses IUserSession; same auth result, codebase-consistent"

key-files:
  created:
    - app/lib/Controller/CertificateController.php
    - app/src/services/CertificateService.js
    - app/tests/Unit/Controller/CertificateControllerTest.php
  modified:
    - app/appinfo/routes.php
    - app/tests/Support/PhpUnitStubs.php

key-decisions:
  - "userId is the DI-injected ?string ctor param (IcsController pattern), not IUserSession->getUser()->getUID() as the plan <interfaces> text said twice — DELIBERATE codebase-consistency deviation: grep found zero controllers using IUserSession; the null-guard returns 401, identical auth semantics."
  - "download() return type is OCP\\AppFramework\\Http\\Response (supertype), not the plan's literal `: DataDownloadResponse` — the 403/404 paths return a JSONResponse, which under PHPStan L5 would be a type error against a `: DataDownloadResponse` signature. Matches IcsController::feed()'s `: Http\\Response`."
  - "CERT-07/09 left Pending — CERT-07 ('view AND print', window.print + print stylesheet) is the 155-06 Vue UI, not this backend; CERT-09's download MECHANISM is delivered + unit-proven here, but the user-facing download button is 155-06 and a live real certificate exists only after 155-07 (migration + issuer key). `requirements mark-complete` intentionally NOT run, consistent with the 155-02/03/04 deferral discipline."
  - "@NoAdminRequired on all three methods is load-bearing and unit-uncatchable: a method with NO annotation defaults to admin-required in NC, so a student would 403. Unit tests bypass the annotation middleware (they call methods directly), so correctness here rests on inspection — the annotation IS present on index/show/download."

# Metrics
duration: ~35min
completed: 2026-06-27
---

# Phase 155 Plan 05: CertificateController — Owner-Scoped Read API + OB3 JSON-LD Download Summary

**The issued credential is now reachable by its owner over authenticated HTTP. `CertificateController` exposes `index()` (list own certs), `show(vid)` (view one — 404 unknown, 403 bare-error foreign), and `download(vid, format)` whose DEFAULT is the CERT-09 artifact: an Open Badges 3.0 JSON-LD `EnvelopedVerifiableCredential` wrapping the stored compact VC-JWT (`application/ld+json`), with an optional `?format=jwt` raw-JWT path. Every endpoint is `@NoAdminRequired` and authorizes strictly against the authenticated user id — a student reads only their own certificates, no IDOR. `CertificateService.js` is the thin axios client the 155-06 Vue UI consumes. The PUBLIC verify route stays Phase 157.**

## Performance

- **Duration:** ~35 min
- **Started / Completed:** 2026-06-27
- **Tasks:** 2 (Task 1 TDD: RED → GREEN; Task 2 service client)
- **Files:** 3 created, 2 modified

## Accomplishments
- **CertificateController (Task 1)** — three `@NoAdminRequired`, owner-scoped endpoints:
  - `index(): JSONResponse` → `CertificateMapper::findByUserId($userId)` mapped through `jsonSerialize()`. The mapper is queried with the authenticated uid only; a caller cannot list another user's certs.
  - `show(string $verificationId): JSONResponse` → `findByVerificationId`; `DoesNotExistException` → 404; `userId !== current` → 403 with a **bare** `{'error':'Forbidden'}` (no cert body leaked).
  - `download(string $verificationId, string $format='jsonld'): Response` → same 404/403 ownership gate. **Default** builds the OB3 JSON-LD `EnvelopedVerifiableCredential` (`@context` `[https://www.w3.org/ns/credentials/v2]`, `type` `EnvelopedVerifiableCredential`, `id` `data:application/vc+jwt,<compact JWT>`), `json_encode`d with `JSON_UNESCAPED_SLASHES`, served as `application/ld+json` (`certificate-<vid>.json`). `?format=jwt` returns the raw compact JWT (`application/vc+jwt`, `certificate-<vid>.jwt`).
- **Routes** — three GET routes registered in `app/appinfo/routes.php`; live-confirmed via `occ router:list learning` (`learning.certificate.{index,show,download}`).
- **CertificateService.js (Task 2)** — thin axios client mirroring `CourseService.js`: `listCertificates()`, `getCertificate(vid)`, and a `downloadUrl(vid, format='jsonld')` helper (returns the `.json` OB3 link by default, `?format=jwt` for the raw JWT) for the 155-06 download button. No business logic. ESLint 0 errors.
- **Test infra** — extended `PhpUnitStubs.php` with a `Response` base + `JSONResponse`/`DataDownloadResponse` (so `download()`'s `: Response` union is honest in unit tests) and `Http::STATUS_FORBIDDEN`/`STATUS_NOT_FOUND`.

## Task Commits

1. **Task 1 (RED): failing CertificateControllerTest + stub additions** — `df83262` (test) — 6 cases; RED confirmed in-container (6× "CertificateController not found", stubs load clean)
2. **Task 1 (GREEN): CertificateController + routes** — `221afff` (feat) — 6/6 green, full suite 96/96, PHPStan L5 clean, 3 routes live in `router:list`
3. **Task 2: CertificateService.js** — `80d4ff2` (feat) — ESLint 0 errors; `listCertificates`/`getCertificate`/`downloadUrl`

**Plan metadata:** _(final docs commit — this SUMMARY + STATE + ROADMAP, all hand-edited)_

## Files Created/Modified
- `app/lib/Controller/CertificateController.php` — index/show/download; `?string $userId` ctor (IcsController pattern); ownership 403/404; OB3 JSON-LD envelope builder
- `app/src/services/CertificateService.js` — `listCertificates`/`getCertificate`/`downloadUrl`
- `app/tests/Unit/Controller/CertificateControllerTest.php` — 6 tests / 26 assertions
- `app/appinfo/routes.php` — 3 GET routes (certificate#index/show/download)
- `app/tests/Support/PhpUnitStubs.php` — `Response` base + `JSONResponse`/`DataDownloadResponse` stubs; `Http` 403/404 constants

## Requirements Status

**CERT-07 and CERT-09 deliberately left Pending** in REQUIREMENTS.md — `requirements mark-complete` was intentionally NOT run.

- **CERT-07** ("view AND print" — `window.print` + print stylesheet) is the **155-06 Vue UI**, not this backend plan. This plan only delivers the data source (`show`/`index`).
- **CERT-09** (download as OB3 JSON-LD file) — the download **mechanism** is delivered, deployed, route-registered, and unit-proven here (the default `EnvelopedVerifiableCredential` artifact). But the user-facing download button lands in **155-06**, and a live *real* certificate to download exists only after **155-07** applies the migration + provisions the issuer key. Marked complete downstream, consistent with the 155-02/03/04 deferral discipline.

## Deviations from Plan
- **[Decision] `?string $userId` ctor param, not `IUserSession->getUID()`** — the plan `<interfaces>` text twice said "Use IUserSession->getUser()->getUID()". Grep found **zero** controllers in this codebase using `IUserSession`; `IcsController` (the cited authenticated reference) injects `?string $userId` and null-guards it. Followed the codebase pattern: identical auth semantics (null → 401), DI-autowired, no `IUserSession` dependency. (Advisor-confirmed.)
- **[Decision] `download(): Response` not `: DataDownloadResponse`** — the 403/404 branches return a `JSONResponse`; a `: DataDownloadResponse` signature would be a PHPStan L5 type error. Widened to the `OCP\AppFramework\Http\Response` supertype, mirroring `IcsController::feed()`.
- **[Rule 3 - Blocking] Extended `PhpUnitStubs.php`** — the unit bootstrap had no `JSONResponse`/`DataDownloadResponse`/`Response` stub and lacked `Http::STATUS_FORBIDDEN`/`STATUS_NOT_FOUND`; without them the controller's union return type would `TypeError` and the assertions had no status constants. Added additive, `class_exists`-guarded stubs (a `Response` base both responses extend, so the `: Response` return type resolves). No production behavior touched.

## Verification Results
- **CertificateControllerTest**: `OK (6 tests, 26 assertions)` in the relay container. RED confirmed first (6× "CertificateController not found" — the stub additions loaded cleanly, so the failure was the missing class, not the harness).
- **Full suite**: `OK (96 tests, 360 assertions)` — +6 new, no regression from the stub additions (was 90/90 after 155-04).
- **PHPStan Level 5**: `No errors` (run on relay via `deploy-prod.sh --php-only`).
- **Routes live**: `occ router:list learning` shows `learning.certificate.index` (`GET /apps/learning/api/certificates`), `.show` (`/{verificationId}`), `.download` (`/{verificationId}/download`).
- **ESLint**: 0 errors on `CertificateService.js`.
- **Gate 2 (live authenticated API, test-api.sh)** — NOT run: no vault credentials / `ADMIN_PASS` in this environment (carried from 154-04). Routes/verbs are live-registered and the logic is unit-proven; a live `GET /api/certificates` assertion belongs to a credentialed Gate 2 / 155-07 run. With the 155-01 migration still unapplied + no issued certs on any DB, there is nothing to list live yet regardless.

## Security Notes (security-critical plan)
- **Strict ownership, no IDOR** — `index` queries `findByUserId(currentUid)` only; `show`/`download` load by verification-id then compare `cert.userId === currentUid`, returning **403 with a bare error (no cert body)** on mismatch and **404** on not-found. The 403-without-body behavior is explicitly asserted (`assertArrayNotHasKey('credential_json' | 'verification_id')`).
- **`@NoAdminRequired` present on all three methods** — without it NC defaults to admin-required and students would 403. This is **not catchable by the unit tests** (they bypass the annotation middleware), so it was verified by inspection and is documented as load-bearing.
- **No public/unauthenticated endpoint** — the public verify route is Phase 157; everything here requires an authenticated, owner-matched session. CSRF stays automatic (GET routes, no `@NoCSRFRequired`).

## User Setup Required
None for this plan. (A live certificate to list/view/download needs the 155-01 migration applied + `occ learning:cert:init-issuer` + a real pass — all 155-07 / the v5.0.0 release plan.)

## Next Phase Readiness
- **155-06 (Certificate.vue)** consumes `CertificateService.js`: `listCertificates()` for the list, `getCertificate(vid)` for detail, `downloadUrl(vid, 'jsonld'|'jwt')` for the download button. Print (window.print + stylesheet) and QR/LinkedIn live there; CERT-07 completes there.
- **155-07** applies the migration, provisions the issuer key, triggers a live pass, then can exercise a credentialed `GET /api/certificates` + download (Gate 2 / test-api.sh) against a real cert; marks CERT-07/09 complete alongside CERT-05/06/11/12.
- **Carry-forward:** migration still NOT applied + info.xml NOT version-bumped (Phase 154/155 carry); the python3-cryptography-in-container question (155-03) remains an open INBOX item for 155-07.

## Self-Check: PASSED

- Files on disk: `app/lib/Controller/CertificateController.php` FOUND, `app/src/services/CertificateService.js` FOUND, `app/tests/Unit/Controller/CertificateControllerTest.php` FOUND.
- Commits in history: `df83262` (RED) FOUND, `221afff` (GREEN controller+routes) FOUND, `80d4ff2` (CertificateService.js) FOUND.
- Tests: 6/6 CertificateControllerTest; full suite 96/96 (360 assertions); PHPStan L5 clean; ESLint 0; 3 routes live in `router:list`.

---
*Phase: 155-certificate-artifact-issuer*
*Completed: 2026-06-27*
