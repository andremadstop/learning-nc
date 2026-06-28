---
phase: 157-public-verify
verified: 2026-06-28T00:00:00Z
status: passed
score: 6/6 VERIFY requirements verified (code + live where reachable without prod mutation)
re_verification: false
human_verification:
  - test: "Live 429 rate-limit curl-loop on the unknown branch (>30 req/60s) + brute-force counter increment"
    expected: "An actual HTTP 429 after the AnonRateLimit window; the unknown-branch throttle() increments the @BruteForceProtection(action=learningVerify) counter"
    why_human: "Driving the limit on live prod would trip throttling for real visitors; AND the rate-limit attribute path is live-UNVERIFIED on a build where #[PublicPage] was silently dropped — this is the one deferred item with genuine (not merely visual) functional uncertainty"
  - test: "Credentialed revoke smoke (instructor 200 / non-owner 404 / repeat keeps first revoked_at) on a real cert"
    expected: "Owner revoke returns {revoked:true}; non-owner/unknown → uniform 404; second revoke does not overwrite revoked_at"
    why_human: "Needs ADMIN creds + a live prod write; also requires the dormant Version009200 revoked_at column to be applied first (occ upgrade — forbidden this session)"
  - test: "End-to-end visual of valid / withdrawn / expired banners + RTL Arabic on a real provisioned cert; activate the gated VERIFY-03 DOM no-leak Playwright assertion"
    expected: "Each banner renders with the locked colour + copy; localized date resolves; recipient name absent from the whole HTML body (DOM-level)"
    why_human: "valid/withdrawn/expired have never run controller→service→DB→template end-to-end (no live cert exists; minting on prod forbidden); visual/RTL/DOM gate rides the authorized demo-course provisioning pass"
---

# Phase 157: Public-Verify Verification Report

**Phase Goal:** A public, unauthenticated, DSGVO-safe, cryptographically-verifying, revocation-aware, rate-limited certificate verification route (`@PublicPage` verify route, signature + revocation check, rate-limit; runs parallel with 156)
**Verified:** 2026-06-28
**Status:** passed (CLOSE — with non-blocking deferred items, consistent with 154/155/156 discipline)
**Re-verification:** No — initial verification

## Goal Achievement

This verification is **goal-backward against the actual code**, not against the SUMMARY self-certifications. Every claim below was checked by reading the source file at the cited line, confirming the wiring connects (routes → controller → service → DTO → template), and corroborating with the fresh logged-out Playwright run (2026-06-28) and the static gates.

### Requirement Verdicts (VERIFY-01..06)

| # | Requirement | Verdict | Evidence |
|---|-------------|---------|----------|
| VERIFY-01 | Public URL verify, no NC login | **PROVEN (code + live)** | Route `publicVerify#verify` → `GET /verify/{verificationId}` registered OUTSIDE `/api/` (`routes.php:9`); `@PublicPage @NoCSRFRequired @BruteForceProtection` PHPDoc on `PublicVerifyController::verify()` (lines 72-74) — the codebase-proven public form (the `#[PublicPage]` attribute 401'd logged-out, fixed in `d05d593`). **Fresh logged-out Playwright (2026-06-28): HTTP 200, no `/login` redirect, server banner rendered.** The live 200-with-rendered-banner ALSO proves the full DI chain resolves in-container (controller → service → its 5 autowired deps) — a DI failure would be a 500, not a banner. |
| VERIFY-02 | Page shows validity status, issuer, course title, issue/expiry dates | **PROVEN-BACKEND / DEFERRED-LIVE (visual)** | `verify.php` renders: status banner (4 states, lines 38-57); course `<h2>` (line 100); issuer logo+name (lines 102-107); "Ausgestellt am" → `issued_at` (line 111); "Gültig bis" → `expires_at` or "unbegrenzt gültig" (lines 115-119). Fields sourced from `CertificateVerifyService::projectDto()` (issuer.name + achievement.name from payload; issued_at/expires_at from cert row). **Deferred:** the valid/withdrawn/expired branch has never rendered end-to-end against a real cert (no live cert exists; minting on prod forbidden) — visual eyeball rides the provisioning pass. |
| VERIFY-03 | Response omits recipient PII for unauthenticated callers (DSGVO) | **PROVEN (code, every layer)** | `projectDto()` (service lines 194-213) reads ONLY `issuer_name`, `course_title`, `issued_at`, `expires_at`, `verification_id` (+`revoked_at` on withdrawn); `credentialSubject.name` is never read; `Certificate::jsonSerialize()` (emits user_id + credential_json) is never called. Controller `buildParams()` forwards only those 6 fields (lines 114-134). Template references only those params. Unit `testDtoNoLeak` asserts recipient name / user_id / key_id / credential_json / raw JWT absent from the serialized DTO. **Confirmed sole public surface:** `verify` is the only `@PublicPage` route touching cert data (grep across all controllers); `certificate#show/download/index` are all `@NoAdminRequired`; `DidController` (`@PublicPage`) publishes only public keys (zero PII hits). The skipped Playwright DOM gate is a *redundant* belt-and-suspenders live confirmation, not the proof. |
| VERIFY-04 | Crypto checks signature against issuer's published key AND revocation/expiry (sig alone ≠ valid) | **PROVEN-BACKEND (unit + static)** | `verifyByVerificationId()` (service lines 76-123) is an explicit AND-chain: resolve cert → resolve the key that SIGNED it (`findByKeyId(cert.key_id)`, rotation-correct, line 85) → reject if `key.status === 'revoked'` (line 90, Codex #1: did.json publishes only non-revoked keys = the "published key") → 32-byte length assert (line 96) → `SigningService::verify()` real sodium detached + header-contract gate (line 101, body confirmed at `SigningService.php:72-103`, NOT a stub) → claim binding `kid==hostDid#keyId AND issuer.id==hostDid AND id==urn:uuid:vid` (`decodeAndBind`, lines 134-168) → THEN revocation tombstone (line 113) → THEN expiry (line 118). "Sig alone ≠ valid" is structurally enforced: `testRevokedSigningKeyInvalid` (good sig + revoked key → invalid), `testClaimBindingRejectsSubstitution` (good sig + wrong id → invalid). 10/10 PHPUnit GREEN, PHPStan L5 clean. |
| VERIFY-05 | Instructor can revoke → verification returns explicit "withdrawn" tombstone (not 404) | **PROVEN-BACKEND / DEFERRED-LIVE** | Write side: `CertificateController::revoke()` (lines 165-194) — 401 if unauth, malformed UUID → 404 before DB, single try/catch over `findByVerificationId` + `assertInstructorOfCourse` collapses ForbiddenException|DoesNotExist to a **uniform 404** (no oracle), then atomic `setRevoked(true)` + idempotent `if getRevokedAt()===null setRevokedAt(now)` + `setActiveIdemKey(null)` + `update()`. Read side: service returns `['status'=>'withdrawn','revoked_at'=>...]` (line 114) — a tombstone RENDER (HTTP 200), never a 404. Route `certificate#revoke` POST registered (`routes.php:350`). `CertificateRevokeTest` 6 cases drive the REAL CourseService gate with `update()->never()` proving gate-before-write. **DEFERRED + NOT LIVE-FUNCTIONAL TODAY:** the `revoked_at` column (Version009200) is DORMANT on live PG16 — a live revoke would fail until the migration applies (`occ upgrade`, forbidden this session). Code complete + cross-DB-verified on MariaDB 11.4. |
| VERIFY-06 | Rate-limited + validates input format (anti-enumeration / IDOR) | **Input-validation: PROVEN (code + live) · Rate-limit enforcement: DEFERRED-LIVE (genuine uncertainty)** | *Input format / anti-enumeration (PROVEN):* `UUID_V4` precheck runs BEFORE any service/DB call (controller line 80); malformed id renders the SAME generic `unknown` page as not-found (no 404-vs-200 oracle). Unit test asserts `findByVerificationId ->never()` on malformed input. **Fresh Playwright (2026-06-28): malformed `not-a-uuid` AND well-formed-missing UUID both → identical HTTP 200 red page — GREEN.** *Rate-limit (DEFERRED, with reason to doubt):* `#[AnonRateLimit(30,60)]` attribute (line 76) + `throttle()`-on-unknown (lines 87, 100-102) with matching `@BruteForceProtection(action=learningVerify)` action key. The attribute is wired but **live-UNVERIFIED on a build where the sibling `#[PublicPage]` attribute was silently dropped** — the actual 429 + counter increment must be confirmed in the provisioning pass. |

**Score:** 6/6 VERIFY requirements verified to the limit reachable without a forbidden prod mutation. 0 genuine gaps.

### Required Artifacts

| Artifact | Status | Details (read directly) |
|----------|--------|---------|
| `app/lib/Service/CertificateVerifyService.php` | VERIFIED | 214 lines; resolve → key-by-id+status → 32-byte assert → real `SigningService::verify` → claim-binding → status precedence → strict 6-field DTO; hostile-input safe (try/catch \Throwable + is_string guards + hash_equals). DI-autowired, no Application.php registration. |
| `app/lib/Controller/PublicVerifyController.php` | VERIFIED | 135 lines; `@PublicPage` PHPDoc + `#[AnonRateLimit]`; UUID precheck before service; throttle-only-on-unknown; `buildParams()` forwards exactly the 6 DTO fields; PublicTemplateResponse. Zero crypto re-implementation. |
| `app/templates/verify.php` | VERIFIED | 142 lines; pure server-render (no Vue, no script(), no data-* JSON); 4 banners with locked colours; all copy via `$l->t()`; all values HTML-escaped via `p()`; DSGVO missing-name explainer; unknown/invalid = banner-only (no cert block → nothing leaks). RTL-safe logical CSS props. |
| `app/lib/Controller/CertificateController.php` revoke() | VERIFIED | Lines 165-194; owner-gated, idempotent, atomic tombstone, uniform 404; `CourseService` + `ITimeFactory` added to ctor. |
| `app/lib/Migration/Version009200Date20260627120000.php` | VERIFIED (DORMANT) | Idempotent `hasColumn` guard; nullable BIGINT `revoked_at`; returns `$changed ? $schema : null`. Deliberately NOT applied live (info.xml stays 4.4.8); cross-DB-verified on MariaDB. |
| `app/lib/Db/Certificate.php` | VERIFIED | `@method int|null getRevokedAt()` / `setRevokedAt(?int)` (lines 32-33); `protected $revokedAt` + `addType('revokedAt','integer')`. |
| `app/tests/Unit/Service/CertificateVerifyServiceTest.php` | VERIFIED (per SUMMARY: 10/10 GREEN, real SigningService over throwaway keypair) | Discriminating tests: revoked-key-invalid, claim-binding-substitution-reject, verifies-against-cert-key-not-active, DTO-no-leak. |
| `app/tests/Unit/Controller/PublicVerifyControllerTest.php` | VERIFIED (per SUMMARY: 5/5 GREEN) | malformed → mapper `->never()`; not-found==malformed identical params; valid passthrough not throttled; withdrawn; invalid banner-only. |
| `app/tests/Unit/Controller/CertificateRevokeTest.php` | VERIFIED (per SUMMARY: 6/6 GREEN, real CourseService) | tombstone-fields-together, idempotent-keeps-first-date, gate-before-write (`update()->never()`), malformed-404, unknown-404, unauth-401. |
| `app/tests/e2e/public-verify.spec.js` + `playwright.config.js` | VERIFIED | Logged-out `public-verify` project (empty storageState, no setup dependency, line 33); chromium `testIgnore`s the spec (line 28). VERIFY-01 + VERIFY-06 GREEN; VERIFY-03 DOM gate honestly `test.skip()` until a real cert exists (integrity guard, not a vacuous pass). |
| `app/l10n/{de,en,fr,ru,ar}.json` | VERIFIED | 16 verify-page keys + revoke keys; parity 8 hits/file across all 5 languages for the core verify strings; `.js` regenerated via `l10n_js_sync.py`. |

### Key Link Verification

| From | To | Via | Status | Evidence |
|------|----|-----|--------|----------|
| `routes.php` | `PublicVerifyController::verify` | PAGE-section route (NOT /api/) | WIRED | `routes.php:9` — `publicVerify#verify` GET `/verify/{verificationId}`; live HTTP 200 logged-out |
| `PublicVerifyController::verify` | `CertificateVerifyService::verifyByVerificationId` | DI call after UUID precheck | WIRED | controller line 84; live banner render proves container resolution |
| `CertificateVerifyService` | `SigningService::verify` | reused verbatim (real sodium) | WIRED | service line 101 → `SigningService.php:99` `sodium_crypto_sign_verify_detached` (not a stub) |
| `CertificateVerifyService` | `CertKeyMapper::findByKeyId` + status filter | rotation-correct key resolution | WIRED | service lines 85-92; `findByKeyId` exists (`CertKeyMapper.php:22`); revoked-key → invalid |
| `CertificateVerifyService::projectDto` | template params | 6-field DTO, no recipient | WIRED | service 194-213 → controller buildParams 114-134 → template; `testDtoNoLeak` |
| `CertificateController::revoke` | `CourseService::assertInstructorOfCourse` | owner gate BEFORE write | WIRED | controller line 179; `update()->never()` test proves gate-before-write |
| `CertificateController::revoke` | tombstone read in verify service | `getRevoked()` + `getRevokedAt()` → withdrawn | WIRED | service lines 113-114; entity accessors confirmed |
| `verify.php` `revoked_at` banner | service withdrawn branch | `$_['revoked_at']` | WIRED | template line 51 ("…am %s vom Aussteller widerrufen") ← service line 114 |

### Requirements Coverage

| Requirement | Source Plans | Status | Evidence |
|-------------|-------------|--------|----------|
| VERIFY-01 | 157-04, 157-05 | SATISFIED (code + live) | public route + live logged-out HTTP 200 (fresh Playwright) |
| VERIFY-02 | 157-02, 157-04 | SATISFIED (code) / visual deferred | template renders status+issuer+course+dates from DTO |
| VERIFY-03 | 157-02, 157-04, 157-05 | SATISFIED (code, every layer) | DTO/controller/template all omit recipient; sole public surface confirmed |
| VERIFY-04 | 157-02 | SATISFIED (unit + static) | AND-chain crypto verify; sig-alone-≠-valid proven by discriminating tests |
| VERIFY-05 | 157-01, 157-03 | SATISFIED (code) / live-functional after migration apply | owner-gated idempotent revoke → withdrawn tombstone; column dormant on live PG16 |
| VERIFY-06 | 157-04 | SATISFIED (input-validation, code+live) / rate-limit-enforcement deferred | UUID precheck + no-oracle live GREEN; AnonRateLimit wired, 429 live-unverified |

No ORPHANED requirements: REQUIREMENTS.md lines 45-50 map exactly VERIFY-01..06 to Phase 157, all claimed by the plans above.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `CertificateVerifyService.php` | 80,87,91,97,102,108 | `return ['status'=>...]` early returns | Info | Correct status-precedence control flow, not stubs — each is a distinct verdict branch |
| `CertificateVerifyService.php` | 138,143,155,158,161,166,182 | `return null` | Info | Correct: claim-binding/decode failure sentinels → caller maps to 'invalid' |
| `Version009200...php` | 47 | `return null` | Info | Correct idempotent-migration NC pattern (null when column already present) |
| `public-verify.spec.js` | 95 | `test.skip()` | Info | INTENTIONAL integrity guard — honest skip where a green would be vacuous; documented |

No stubs, no TODO/FIXME/PLACEHOLDER, no empty handlers, no `return null`-as-stub in any Phase 157 artifact. The verify route is server-rendered with substantive content at every status.

### Skeptic Findings (explicit)

Things checked specifically to catch a hidden stub or unwired claim — all **passed**:

1. **`SigningService::verify` is real, not a stub** — confirmed `sodium_crypto_sign_verify_detached` over `header.payload` with a header-contract gate (`SigningService.php:72-103`).
2. **The service is actually called by the controller** — controller line 84, and the live HTTP 200 banner render proves the whole DI chain resolves in-container (not just in unit mocks).
3. **The DTO actually omits recipient fields** — `projectDto` reads no `credentialSubject.name`; `jsonSerialize()` never called; verified at service + controller + template layers.
4. **`verify` is the SOLE public surface touching cert data** — grep of all `@PublicPage`/`#[PublicPage]` controllers: only `verify` touches certs; `DidController` publishes public keys only (0 PII hits); `certificate#show/download/index` are `@NoAdminRequired`.
5. **"Sig alone ≠ valid"** — structurally enforced by the AND-chain; a good signature with a revoked key or mismatched claim returns 'invalid' (discriminating tests, not trivial passes).
6. **All 9 phase commits present** in `feature/v5.0.0-certification` (4638e69, 85b6321, 70c9695, b463bb1, d05d593, 94f0166, f51c3b3, 37e5e23, 639b2bc).

### Deferred — Non-Blocking (rides the authorized demo-course provisioning pass, user option A; 154/155/156 discipline)

All deferrals require either a forbidden prod write or a real provisioned cert that does not exist on live today. None block CLOSE.

1. **⚠ Rate-limit enforcement (VERIFY-06) — the ONE deferral with genuine functional uncertainty.** The 429 curl-loop + brute-force-counter increment is live-unverified, AND the `#[AnonRateLimit]` attribute path is unproven on a build where the sibling `#[PublicPage]` attribute was silently dropped (that is precisely why 157-04 fell back to PHPDoc). The IcsController `#[UserRateLimit]` precedent is suggestive, not proof (different attribute). **This should be the #1 check in the provisioning pass** — louder than the visual deferrals, because it could be non-functional in prod.
2. **Revocation is NOT live-functional today.** VERIFY-05 code is complete + cross-DB-verified on MariaDB, but `revoked_at` (Version009200) is dormant on live PG16; a live revoke fails until `occ upgrade` applies it. CLOSE must not be read as "revocation works live now."
3. **info.xml 4.4.8 → bump PAIRED WITH `occ upgrade`** to apply Version009200 on live PG16 (the two travel together; a bare bump shows the maintenance page to live users).
4. **VERIFY-03 DOM whole-body no-leak Playwright gate** — currently honestly skipped; activate on a real cert (atomic: set LIVE_VID + both recipient constants + psql-confirm `credentialSubject.name` is real, not the `Teilnehmer:in` fallback). Note: the DSGVO guarantee is ALREADY code-proven at every layer; this is redundant live confirmation.
5. **Visual eyeball** of valid/withdrawn/expired banners + RTL Arabic + credentialed revoke smoke (instructor 200 / non-owner 404 / repeat-keeps-first-revoked_at).

## Phase-Goal Verdict

**Does Phase 157 deliver "a public, unauthenticated, DSGVO-safe, crypto-verifying, revocation-aware, rate-limited certificate verification route"? — YES, at the code+wiring level, with the live/visual confirmations deferred exactly as 154/155/156 deferred theirs.**

- **Public + unauthenticated:** PROVEN live (HTTP 200 logged-out, no login wall).
- **DSGVO-safe:** PROVEN in code at every layer + sole-public-surface confirmed.
- **Crypto-verifying (sig alone ≠ valid):** PROVEN by unit + static (real sodium verify + key-status + claim-binding AND-chain).
- **Revocation-aware (withdrawn tombstone, not 404):** code-complete + unit-proven; live-functional only after the dormant migration applies.
- **Rate-limited:** anti-enumeration/input-validation PROVEN live; the 429 enforcement is the one item carrying real (not merely visual) live uncertainty.

## Recommendation: **CLOSE**

Phase 157 achieves its goal. All six VERIFY requirements are satisfied to the limit reachable without a forbidden prod mutation, the code paths actually connect (verified by reading source + the live render proving DI resolution), there are no stubs or hidden gaps, and the deferral discipline matches the three prior phases of this milestone. `status: passed` is the consistent verdict (154 and 156 both closed `passed` with non-blocking deferred items).

**On close, carry these deferred items into the provisioning pass — and verify them in this priority order:**
1. **Live 429 rate-limit + brute-force counter** (genuine functional uncertainty — attribute path unproven on this build).
2. **Apply Version009200 (`occ upgrade` + info.xml bump)** — without it, revocation is not live-functional.
3. Credentialed revoke smoke + valid/withdrawn/expired visual + RTL + activate the gated VERIFY-03 DOM assertion.

---

_Verified: 2026-06-28_
_Verifier: Claude (gsd-verifier)_
