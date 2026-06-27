---
phase: 157-public-verify
plan: 04
subsystem: api
tags: [certificate, public-verify, dsgvo, server-template, i18n, anti-enumeration, public-page]

# Dependency graph
requires:
  - phase: 157-public-verify
    plan: 02
    provides: "CertificateVerifyService::verifyByVerificationId(vid) — resolve -> Ed25519 verify -> claim-binding -> status precedence -> DSGVO DTO (status + 6 display fields, never recipient identity)"
  - phase: 157-public-verify
    plan: 01
    provides: "Certificate::getRevokedAt() — surfaced in the WITHDRAWN tombstone banner (revoked_at date)"
  - phase: 155-cert-artifact
    provides: "did:web issuer live on devcloud (key UI3V-D_j…, active); OCP\\Defaults issuer logo/name"
provides:
  - "PublicVerifyController — #[PublicPage]-equivalent verify route (UUID precheck before DB, throttle-on-unknown, AnonRateLimit, server-side DTO projection)"
  - "templates/verify.php — pure server-rendered 4-status verify page (no Vue island), $l->t() i18n, DSGVO missing-name explainer, RTL-safe"
  - "16 new verify-page i18n keys across DE/EN/FR/RU/AR"
affects: [157-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Public verify route = thin controller over the 157-02 service: UUID_V4 precheck BEFORE any service/DB call; malformed == not-found (identical generic page, no enumeration oracle); throttle() ONLY on the unknown branch (invalid/valid/withdrawn/expired never throttled)"
    - "Pure server-rendered PublicTemplateResponse (no Vue island, no data-* JSON, no 2nd JSON endpoint) — smallest attack surface; i18n via server-side \\$l->t() in the template (still parity-gated in l10n/*.json + regenerated .js)"
    - "Publicness via @PublicPage PHPDoc (the codebase-honoured form here) + #[AnonRateLimit] attribute (the working attribute path, IcsController precedent) — the #[PublicPage] attribute is NOT honoured by this NC build (401 logged-out)"

key-files:
  created:
    - app/lib/Controller/PublicVerifyController.php
    - app/templates/verify.php
    - app/tests/Unit/Controller/PublicVerifyControllerTest.php
  modified:
    - app/appinfo/routes.php
    - app/phpstan.neon
    - app/tests/Support/PhpUnitStubs.php
    - app/l10n/{de,en,fr,ru,ar}.json
    - app/l10n/{de,en,fr,ru,ar}.js

key-decisions:
  - "@PublicPage PHPDoc (not the #[PublicPage] attribute) — empirically the attribute form 401'd logged-out on this NC 33 build; PHPDoc is the proven form (DidController/IcsController/PageController). #[AnonRateLimit] stays an attribute (works, IcsController precedent)."
  - "Pure server-rendered template, no Vue island (LOCKED review decision) — verify.php mirrors privacy.php's shell but renders content server-side with \\$l->t()."
  - "throttle() only on the unknown branch; 'invalid' (sig fail / tampered row) is a rare integrity failure, NOT an enumeration probe → not throttled; valid/withdrawn/expired are honest hits → not throttled."
  - "Malformed UUID and not-found render the SAME generic red page (proven byte-identical params in the unit test) — no 404-vs-200 oracle."

patterns-established:
  - "Controller test proves the precheck short-circuits with a REAL CertificateVerifyService over a MOCKED CertificateMapper asserting findByVerificationId ->never() on malformed input."
  - "Non-mutating in-container template render smoke (no DB/prod) exercises all 4 banners + the valid course/issuer/DSGVO-box/badge and asserts a sentinel recipient name never leaks — closes the 'nothing renders the template' gate without minting a prod cert."

requirements-completed: []  # VERIFY-01/02/06 backend+live-unknown proven here; flip at 157 close after the 157-05 live valid/withdrawn/expired cert pass (154/155/156 deferral discipline)

# Metrics
duration: ~55min
completed: 2026-06-27
---

# Phase 157 Plan 04: PublicVerifyController + verify.php Summary

**The public, unauthenticated certificate verify page: a thin hardened controller (UUID precheck before any DB query, throttle-only-on-unknown, IP-keyed AnonRateLimit, no malformed/not-found oracle) over the 157-02 crypto service, rendering a pure server-side 4-status PHP template (valid/withdrawn/expired/unknown) with the Gemini-locked UX and the load-bearing DSGVO missing-name explainer — logged-out reachable (HTTP 200) and recipient-leak-free, 5/5 PHPUnit + PHPStan L5 + 5-lang i18n parity green.**

## Performance

- **Duration:** ~55 min
- **Completed:** 2026-06-27T18:24Z
- **Tasks:** 2 (Task 1 TDD controller; Task 2 template + i18n)
- **Files modified/created:** 13 (3 created, 10 modified incl. 5 json + 5 js)

## Accomplishments

- **`PublicVerifyController::verify(verificationId)`** — `@PublicPage @NoCSRFRequired @BruteForceProtection(action=learningVerify)` + `#[AnonRateLimit(30,60)]`. UUID_V4 precheck runs BEFORE the service is touched; a malformed id renders the SAME generic `unknown` page as a not-found (no oracle). `throttle()` fires ONLY on the unknown branch. Always HTTP 200 (withdrawn/expired/unknown are tombstone/explainer renders, not 404). All crypto + DSGVO projection delegated to the 157-02 service — zero crypto re-implementation.
- **`templates/verify.php`** — pure server-rendered (no Vue, no `script()`, no `data-*` JSON). Four banners with the locked DE copy + colours (valid #2f9a48 / withdrawn #e69900 / expired #6c757d / unknown|invalid #d92f2f), `role="status" aria-live="polite"`, icons `aria-hidden`. Information order: banner → course (h2) → issuer (logo via OCP\Defaults + name) → data block (issued_at; expires_at or "unbegrenzt gültig") → DSGVO missing-name info-box (elevates verification_id to the matching bridge) → fine print (vid + query time) → "Digital verifiziert" badge → plain-language `<details>` footer (no Ed25519/did:web jargon). Unknown/invalid render banner-only (no cert block → nothing leaks). RTL-safe via logical CSS props.
- **DSGVO no-leak** — the template only ever receives the service's 6 allow-listed display fields; `grep -E 'credentialSubject|user_id|getUserId|credential_json'` clean on both template + controller. The 157-02 `testDtoNoLeak` already proves the recipient name is absent from the DTO.
- **i18n** — 16 new keys (4 banners ×2 lines, labels, the DSGVO paragraph, badge, footer) added to all 5 langs (DE source==key; real EN/FR/RU/AR), `.js` regenerated via `l10n_js_sync.py`, parity gate green (2260 keys ×5).

## Task Commits

1. **Task 1 (RED):** `70c9695` (test) — 5 failing controller cases (malformed→mapper `->never()`; not-found==malformed identical params; valid passthrough; withdrawn tombstone; invalid banner-only). Confirmed RED (class-not-found).
2. **Task 1 (GREEN):** `b463bb1` (feat) — PublicVerifyController + PAGE-section route + phpstan attribute ignores. 5/5 GREEN, PHPStan L5 clean.
3. **Task 1 (fix):** `d05d593` (fix) — switch publicness to `@PublicPage` PHPDoc after the `#[PublicPage]` attribute 401'd logged-out; reword DSGVO doc comment to avoid self-tripping the leak grep.
4. **Task 2:** `94f0166` (feat) — verify.php server template + 16 i18n keys ×5 langs + regenerated .js.

## Files Created/Modified

- `app/lib/Controller/PublicVerifyController.php` — public verify route (precheck, throttle policy, DTO→template params). DI-autowired (CertificateVerifyService, IFactory→IL10N('learning'), OCP\Defaults).
- `app/templates/verify.php` — server-rendered 4-status verify page.
- `app/tests/Unit/Controller/PublicVerifyControllerTest.php` — 5 cases (26 assertions).
- `app/appinfo/routes.php` — `publicVerify#verify` in the PAGE section (NOT /api/); 157-03's revoke route untouched.
- `app/phpstan.neon` — ignore entries for the public-page attribute classes (absent from the ocp dev package; present at NC runtime).
- `app/tests/Support/PhpUnitStubs.php` — `PublicTemplateResponse` stub + `Response::throttle()/isThrottled()` + 4 security-attribute stubs.
- `app/l10n/{de,en,fr,ru,ar}.{json,js}` — 16 new verify-page keys.

## Decisions Made

- **`@PublicPage` PHPDoc over the `#[PublicPage]` attribute** — see Deviations (the attribute form 401'd logged-out; PHPDoc is codebase-proven).
- **Pure server-render, no Vue island** — locked review decision; smallest public attack surface; i18n via `$l->t()`.
- **throttle scope** — unknown only; invalid/valid/withdrawn/expired never throttled.
- **No info.xml bump** — stays 4.4.8 (157-01's dormant migration apply rides 157-05); `--php-only` deploy is occ-upgrade-free and prod-safe.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `#[PublicPage]` attribute returns 401 logged-out — switched to `@PublicPage` PHPDoc**
- **Found during:** Task 2 (live logged-out verification after deploy)
- **Issue:** The plan mandated the attribute form (`#[PublicPage] #[NoCSRFRequired] #[BruteForceProtection]`). Deployed live, the route returned **HTTP 401** to a logged-out caller — this NC 33 build does not honour the `#[PublicPage]` attribute for route publicness (it reads `@PublicPage` PHPDoc, the form every existing public route here uses: DidController/IcsController/PageController). VERIFY-01 (no 403/401 logged-out) was failing.
- **Fix:** Moved publicness/CSRF/brute-force to PHPDoc (`@PublicPage @NoCSRFRequired @BruteForceProtection(action=learningVerify)`); kept `#[AnonRateLimit(30,60)]` as an attribute (the rate-limit attribute path IS honoured — IcsController's live `#[UserRateLimit]` proves it). This mirrors IcsController exactly (PHPDoc public + attribute rate-limit).
- **Files modified:** app/lib/Controller/PublicVerifyController.php
- **Verification:** `curl` logged-out → **HTTP 200** (was 401). 5/5 PHPUnit + PHPStan L5 still clean.
- **Committed in:** `d05d593`

**2. [Rule 1 - Bug] Leak grep self-trip on the controller doc comment**
- **Found during:** Task 2 (final grep gate)
- **Issue:** The DSGVO doc comment literally contained `user_id` / `credential_json`, tripping the leak grep on the controller (the 156-01/02 self-tripping-grep gotcha).
- **Fix:** Reworded to "recipient identity (name / login / signing key / raw credential)".
- **Verification:** `grep -E 'credentialSubject|user_id|getUserId|credential_json'` on template + controller → clean.
- **Committed in:** `d05d593`

---

**Total deviations:** 2 auto-fixed (both Rule 1). **Impact:** the publicness fix was essential for VERIFY-01; no scope creep. The plan's "use attributes, don't mix forms" guidance yielded to the empirical 401 + the IcsController precedent.

## Issues Encountered

- **No live valid cert to render against.** The critical_notes/STATE pointed at synthetic cert `eb97720c-…` for a live valid-state smoke, but the DB row is **gone** (155-07 MEMORY: "dann abgeräumt"); the issuer key remains active. Minting a fresh cert is an unauthorized prod mutation (Staging/Prod-Zweifel + Ziel≠Weg rules), so it was NOT done. **Mitigation:** a non-mutating in-container template render smoke (fabricated params, no DB/prod) proved all 4 banners render, the valid/withdrawn/expired states show course/issuer/DSGVO-box/badge/vid, unknown/invalid are banner-only, and a sentinel recipient name ("Jürgen Müller", never in params) never appears. Combined with the live logged-out HTTP 200 + the unknown/malformed no-oracle checks, this closes Gate 1; the end-to-end live valid/withdrawn/expired **browser** visual + RTL eyeball rides the 157-05 demo-course pass (154/155/156 deferral discipline).

## Verification (Gate 1 + live)

- **PublicVerifyControllerTest:** 5/5 GREEN (26 assertions) — malformed→mapper `->never()`; not-found==malformed identical params; valid passthrough not throttled; withdrawn tombstone; invalid banner-only not throttled.
- **PHPStan L5:** No errors.
- **i18n parity:** green (2260 keys ×5); `.js` regenerated from `.json` (not hand-edited).
- **ESLint:** 0 errors (4 pre-existing warnings in untouched files).
- **Leak grep:** clean on verify.php + PublicVerifyController.php.
- **Live (logged-out, devcloud):** `GET /apps/learning/verify/{vid}` → **HTTP 200** (VERIFY-01). Malformed (`not-a-uuid`) and well-formed-missing UUID both → 200 + identical red "Verifizierung fehlgeschlagen" page (VERIFY-06, no oracle). `$l->t()` resolves server-side (German copy present). No recipient-name/user_id token in the rendered HTML.
- **Render smoke (non-mutating, in-container):** all 4 banners + valid course/issuer/DSGVO-box/badge/vid + no sentinel leak → PASS.

## Next Phase Readiness

- **157-05 (last plan):** live valid/withdrawn/expired cert pass (Andre's demo-course / authorized mint) → browser visual of the 4 banners + RTL Arabic eyeball + DOM no-leak on a REAL cert; Playwright logged-out leak spec; then apply the 157-01 dormant `revoked_at` migration via `occ upgrade` (info.xml bump) and run the deferred credentialed throttle/revoke smoke. VERIFY-01..06 flip at that close.
- **Carry-forward (flag, do NOT reopen 156 here):** 156's `CertificateReportService::decodePayload` still reads an UNVERIFIED JWT — `CertificateVerifyService` is the reusable verify-before-decode core a future 156 hardening pass can adopt.

---
*Phase: 157-public-verify*
*Completed: 2026-06-27*

## Self-Check: PASSED

- FOUND: app/lib/Controller/PublicVerifyController.php
- FOUND: app/templates/verify.php
- FOUND: app/tests/Unit/Controller/PublicVerifyControllerTest.php
- FOUND: .planning/phases/157-public-verify/157-04-SUMMARY.md
- FOUND commit: 70c9695 (Task 1 RED)
- FOUND commit: b463bb1 (Task 1 GREEN)
- FOUND commit: d05d593 (Task 1 fix — public route)
- FOUND commit: 94f0166 (Task 2 template + i18n)
