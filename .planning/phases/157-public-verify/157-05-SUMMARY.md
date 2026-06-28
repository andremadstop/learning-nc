---
phase: 157-public-verify
plan: 05
subsystem: testing
tags: [certificate, public-verify, playwright, e2e, dsgvo, no-leak, cross-db, phase-gate]

# Dependency graph
requires:
  - phase: 157-public-verify
    plan: 04
    provides: "PublicVerifyController + templates/verify.php — the live logged-out public verify route this spec drives"
  - phase: 157-public-verify
    plan: 02
    provides: "CertificateVerifyService DSGVO DTO (6 display fields, never recipient identity) — the projection the no-leak gate proves"
  - phase: 157-public-verify
    plan: 01
    provides: "Version009200 revoked_at (dormant) — asserted in the cross-DB MariaDB gate"
provides:
  - "app/tests/e2e/public-verify.spec.js — logged-out reachability (VERIFY-01, live GREEN) + DOM-level no-recipient-leak gate (VERIFY-03, gated/skipped until a real cert is provisioned)"
  - "playwright.config.js public-verify project — no-storageState anonymous context (no admin auth dependency)"
  - "Phase-close gate evidence: cross-DB GO (incl. revoked_at), full cert PHPUnit suite GREEN, leak grep clean"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Logged-out Playwright project = empty storageState + NO setup dependency (anonymous visitor); existing chromium project testIgnores the spec so it never pulls in admin auth.setup.js"
    - "Language-NEUTRAL e2e assertions — chromium sends Accept-Language: en so the verify h1 localizes ('Verification failed' vs 'Verifizierung fehlgeschlagen'); assert the stable banner CLASS (lrn-verify__banner) + the locked per-status background colours (#d92f2f/#2f9a48/#e69900/#6c757d), never a translated string"
    - "Integrity-guarded no-leak gate: test.skip() (NOT pass) when the live cert is absent (generic red banner) so absence is never asserted against a page that never contained the name — auto-activates once a real cert (non-red banner) renders"

key-files:
  created:
    - app/tests/e2e/public-verify.spec.js
  modified:
    - app/playwright.config.js

key-decisions:
  - "DOM no-leak (VERIFY-03) is GATED, not green: the synthetic cert eb97720c is GONE from live devcloud (verified: oc_learning_certificates has ZERO rows; user zz-test-cert155 → 'user not found'), and minting a fresh cert on prod is forbidden (PROD BOUNDARY). A skip is honest where a green would be vacuous (advisor integrity guard)."
  - "The STATE.md 'Synthetic Cert Smoke — LEFT IN PLACE' block is STALE — the cert was cleaned up afterwards; the 157-04 SUMMARY's 'eb97720c GONE' is the accurate state (empirically re-confirmed this plan via psql)."
  - "Assert the LIVE cert's REAL recipient ('ZZ Testkandidat' / 'zz-test-cert155'), NOT the unit fixture 'Jürgen Müller' (never in any real cert → vacuous). Constants carry a DO-NOT-revert comment."
  - "No requirements flipped, no gsd-tools state/roadmap commands run (VERIFY-03 is gated not proven; every prior 157 plan defers the flip to phase close; gsd-tools corrupts the v5.0.0 frontmatter)."

patterns-established:
  - "Provisioning-pass atomicity note baked into the spec + this SUMMARY: updating LIVE_VID, both recipient constants, AND re-running the psql + base64url-decode presence confirmation is ONE step — doing fewer makes the gate skip forever or pass vacuously."

requirements-completed: []  # VERIFY-01 live-reachability-proven; VERIFY-03 gated-pending-provisioning. Flip at 157 close (orchestrator/phase-verifier), 154/155/156 deferral discipline.

# Metrics
duration: ~25min
completed: 2026-06-27
---

# Phase 157 Plan 05: Public-Verify Playwright Gate + Phase-Close Sweep Summary

**The phase verification gate: a logged-out Playwright spec proving the public verify route is reachable with no NC session (VERIFY-01, live GREEN) and a DOM-level whole-body no-recipient-leak assertion (VERIFY-03) that is honestly GATED (test.skip, not a vacuous green) because the live synthetic cert was cleaned up and minting on prod is forbidden — plus the phase-close automated sweep: cross-DB GO incl. revoked_at, the full cert PHPUnit suite 22/22, PHPStan L5 clean, and a leak grep clean across all three new 157 surfaces.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-06-27
- **Tasks:** 2 (Task 1 Playwright spec + config; Task 2 phase-gate sweep — no new code)
- **Files modified/created:** 2 (1 created, 1 modified)

## Accomplishments

- **`app/tests/e2e/public-verify.spec.js`** — 3 logged-out tests against the live devcloud public route (no-auth context, empty storageState):
  - **VERIFY-01 (live GREEN):** malformed `not-a-uuid` → HTTP 200, NOT a `/login` redirect, the server-rendered `lrn-verify__banner` + red `#d92f2f` generic banner present, recipient strings absent. Proves the route is reachable with no NC session (no 401/403/login wall).
  - **VERIFY-06 (live GREEN):** a well-formed-but-missing UUID renders the SAME red generic banner as malformed → no 404-vs-200 existence oracle.
  - **VERIFY-03 (gated/skipped):** on the live cert page, grab `page.content()` and assert `'ZZ Testkandidat'` + `'zz-test-cert155'` are absent from the WHOLE HTML body (visible text, scripts, data-*, embedded JSON, fallback texts — Codex #3). Self-skips when the cert is absent (generic red banner) so the absence is never asserted vacuously; auto-activates once a real cert (non-red banner) renders.
- **`app/playwright.config.js`** — new `public-verify` project: no `setup` dependency, no `storageState` (so it needs no admin creds and hits the route anonymously); the existing `chromium` project now `testIgnore`s the spec.
- **Phase-close gate (Task 2, all green):** cross-DB GO exit 0 (Version009100 + Version009200 `revoked_at` on ephemeral MariaDB 11.4; PG16 = documented post-review no-op); leak grep clean across `CertificateVerifyService.php` + `PublicVerifyController.php` + `verify.php` (only doc-comment prose + the allowed `credentialSubject.achievement.name` course-title read — no recipient projection); full cert PHPUnit suite **22/22, 83 assertions** (`--filter CertificateVerify|PublicVerify|Revoke`); PHPStan L5 No errors; ESLint 0 on the spec.

## Task Commits

1. **Task 1:** `639b2bc` (test) — `public-verify.spec.js` + the `public-verify` Playwright project. Live run: **2 passed, 1 skipped (gated)**; ESLint 0.
2. **Task 2:** no commit — verification/gate sweep, no files changed (results recorded here).

## Files Created/Modified

- `app/tests/e2e/public-verify.spec.js` — logged-out reachability + DOM no-leak gate.
- `app/playwright.config.js` — anonymous `public-verify` project; `chromium` excludes the spec.

## Decisions Made

- **VERIFY-03 gated, not green** — see Issues; a skip is the honest outcome when the live cert is gone and minting is forbidden.
- **Language-neutral selectors** — the page localizes to the browser's Accept-Language; assert the banner class + locked status colours, never a translated h1.
- **No requirements flip / no gsd-tools commands** — VERIFY-03 is gated (flipping it would be the vacuous pass one layer up); 154/155/156 deferral discipline defers the flip to phase close; gsd-tools corrupts the v5.0.0 frontmatter → STATE/ROADMAP hand-edited.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Language-dependent assertions failed (page localizes to English under Playwright)**
- **Found during:** Task 1 (first Playwright run)
- **Issue:** The first spec asserted the German banner string `'Verifizierung fehlgeschlagen'`. Chromium sends `Accept-Language: en-US`, so NC served the verify page in **English** ("Verification failed") — both reachability tests failed on `toContain` even though the page rendered correctly (the failure DOM showed the NC guest-box public layout = the verify page, in English). `curl` had passed earlier only because it sent no Accept-Language (server default = German).
- **Fix:** Switched all assertions to language-NEUTRAL selectors — the stable `lrn-verify__banner` CSS class and the locked per-status background colours (`#d92f2f` fail / `#2f9a48` valid / `#e69900` withdrawn / `#6c757d` expired). The provisioned-cert skip-guard also keys on colour, not banner text.
- **Files modified:** app/tests/e2e/public-verify.spec.js
- **Verification:** re-run → 2 passed, 1 skipped; ESLint 0.
- **Committed in:** `639b2bc`

### Adapted from Plan (interpretation, not a silent change)

**2. VERIFY-03 written as a GATED skip, not a live green — the plan's "GREEN against the live eb97720c cert" premise no longer holds.**
- The plan (and its `key_links`) assumed the live synthetic cert `eb97720c` with recipient "ZZ Testkandidat" was present. **Empirically it is GONE:** `SELECT * FROM oc_learning_certificates` on live devcloud → ZERO rows; `occ user:info zz-test-cert155` → "user not found". Minting a fresh cert is a forbidden prod mutation (PROD BOUNDARY). Per the advisor-baked integrity guard, the no-leak test SELF-SKIPS (does NOT pass) until a real cert is provisioned — a skip is honest, a green would be vacuous. The spec + this SUMMARY make the gating explicit; nothing is silently skipped. (Advisor-confirmed this is the correct adaptation.)

---

**Total deviations:** 1 auto-fixed (Rule 1) + 1 documented plan-adaptation (gated VERIFY-03). No scope creep.

## Issues Encountered

- **Live valid cert is gone (re-confirmed this plan).** The cert table is empty and the test user is deleted, so the strongest VERIFY-03 gate could not run green without an unauthorized prod mint. Resolved by gating (skip-until-provisioned) rather than faking a pass. The **STATE.md "Synthetic Cert Smoke — LEFT IN PLACE" block is STALE** — trust the 157-04 "eb97720c GONE" note and this empirical re-check.

## DEFERRED to the authorized demo-course provisioning pass (user option A — flagged, NOT skipped)

All ride the milestone-close provisioning pass (154/155/156 discipline). None block phase close:

1. **info.xml 4.4.8 → 4.4.9 PAIRED WITH `occ upgrade`** — applies the dormant Version009200 `revoked_at` migration on live PG16. These two travel TOGETHER under authorization (155-07 pattern); a bumped info.xml without `occ upgrade` would show the maintenance/upgrade page to live users and break the logged-out e2e. info.xml stays **4.4.8** this plan. Then verify PG16: `occ db:show-table learning_certificates` (revoked_at present).
2. **VERIFY-03 DOM no-leak — activate the gated test on a REAL cert.** ATOMIC step: update `LIVE_VID` + both recipient constants (`RECIPIENT_DISPLAY`, `RECIPIENT_USERID`) to the freshly-minted cert AND re-run the psql + base64url-decode presence confirmation (`credentialSubject.name === RECIPIENT_DISPLAY`, not the `Teilnehmer:in` fallback) BEFORE trusting the absence assertion. Doing fewer than all three makes the gate skip forever or pass vacuously.
3. **Credentialed revoke smoke** — instructor 200 / non-owner 404 / repeat-keeps-first-revoked_at (needs ADMIN_PASS; bruteforce-reset 172.21.0.1 first).
4. **Live throttle / rate-limit curl-loop** — drive the unknown branch past 30/60 → confirm an actual HTTP 429, and confirm the unknown-branch `throttle()` increments the brute-force counter (157-04 carry: `@BruteForceProtection` PHPDoc + `#[AnonRateLimit]` are live-unverified).
5. **WITHDRAWN / expired visual banner eyeball** + browser visual of all 4 banners + RTL Arabic on a real cert (157-04 carry: valid/withdrawn/expired never ran controller→service→DB→template end-to-end; localized date `$l->l('date', …)` hits real NC IL10N first time then).

## Verification

- **Playwright (`public-verify` project, live devcloud, logged-out):** **2 passed, 1 skipped (gated).** VERIFY-01 reachable (HTTP 200, no login redirect) + VERIFY-06 no-oracle GREEN; VERIFY-03 DOM no-leak SKIPPED (cert not provisioned — not a vacuous pass).
- **ESLint:** 0 errors on the spec.
- **Cross-DB GO:** `scripts/cross-db-migration-check.sh` exit 0 — both tables + all 4 indexes + `revoked_at` (bigint, nullable) on MariaDB 11.4; container torn down. PG16 = deferred post-review live check.
- **Leak grep:** clean on `CertificateVerifyService.php` + `PublicVerifyController.php` + `verify.php` — only doc-comment prose + the allowed `credentialSubject.achievement.name` course-title read; NO recipient projection.
- **PHPUnit (relay):** `--filter CertificateVerify|PublicVerify|Revoke` → **22/22 GREEN, 83 assertions** (tests rsync'd + docker cp'd; `--php-only` doesn't sync `tests/`).
- **PHPStan L5:** No errors.

## Self-Check: (see appended result below)

---
*Phase: 157-public-verify*
*Completed: 2026-06-27*
