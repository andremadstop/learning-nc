---
phase: 155-certificate-artifact-issuer
plan: 04
subsystem: issuance
tags: [certificates, ob3, vc-jwt, issuance, idempotency, notification, theming, i18n, did-web]

# Dependency graph
requires:
  - phase: 155-02
    provides: KeyService (getActiveSigningMaterial / hostDid), CertKey, did:web identity
  - phase: 155-03
    provides: SigningService.sign() — compact VC-JWT EdDSA per frozen 155-ADR-ANCHOR
provides:
  - IssuanceService.issueIfPassed() — first-pass → build self-contained OB3/VC → sign → persist → notify; OWN idempotency guard
  - PassCriteriaService::evaluate() hook — auto-issues on first pass as a swallowed side-effect (never breaks GET /pass-status)
  - Notifier 'certificate_issued' case + 'Certificate issued: %s' i18n key in all 5 languages
affects: [155-05-certificate-view, 155-06-download-share, 155-07-phase-close]

# Tech tracking
tech-stack:
  added: []   # ext-sodium core; OCP\Defaults is public NC API; no Composer deps
  patterns:
    - "Issuance as a swallowed side-effect: evaluate() wraps issueIfPassed() in try/catch(\\Throwable)+log so an un-provisioned issuer key / certificates table can never 500 the read path"
    - "Self-contained OB3 (CERT-06): course title/description, score, threshold, validFrom, validUntil, issuer+branding, verification-id all frozen INTO the signed payload at issue time"
    - "OWN non-atomic idempotency guard (findByUserAndCourse + not-revoked) — deliberately NOT the 154 audit guard, deliberately NO unique constraint (revoke + re-issue must stay possible); rare concurrent double-issue deduped ON READ"
    - "Issuer branding via OCP\\Defaults (getName/getLogo) — OCP\\Theming\\IThemingDefaults does NOT exist in this NC's OCP package (PHPStan-unknown); Defaults is the public, DI-resolvable, PHPStan-known equivalent"

key-files:
  created:
    - app/lib/Service/IssuanceService.php
    - app/tests/Unit/Service/IssuanceServiceTest.php
  modified:
    - app/lib/Service/PassCriteriaService.php
    - app/lib/Notification/Notifier.php
    - app/tests/Unit/Service/PassCriteriaServiceTest.php
    - app/tests/Support/PhpUnitStubs.php
    - app/l10n/de.json
    - app/l10n/en.json
    - app/l10n/fr.json
    - app/l10n/ru.json
    - app/l10n/ar.json
    - app/l10n/de.js
    - app/l10n/en.js
    - app/l10n/fr.js
    - app/l10n/ru.js
    - app/l10n/ar.js

key-decisions:
  - "Issuer branding uses OCP\\Defaults, NOT OCP\\Theming\\IThemingDefaults — the latter is absent from this NC's vendor/nextcloud/ocp package (PHPStan 'unknown class') and not even resolvable at runtime here. OCP\\Defaults exposes getName():string + getLogo(bool):string, is in the OCP package, and is DI-autowirable. Resolves the plan's 'IThemingDefaults' wording in favour of the public, analysable API."
  - "Issuance hooked as a SWALLOWED side-effect of evaluate(): the sole caller is CourseController::getPassStatus() (GET /pass-status, live on devcloud). The 155-01 migration is NOT applied and no issuer key exists yet, so an un-guarded issueIfPassed() would throw (missing table / 'No active signing key') and 500 the read endpoint. try/catch(\\Throwable)+logger->warning keeps the read path intact (Rule 2 — critical missing error handling)."
  - "Idempotency stays the plan's CONSCIOUS non-atomic SELECT-then-INSERT with NO unique constraint (revoke + re-issue must remain possible); a rare concurrent double-issue is deduped on read. NOTE: this contradicts the task-prompt's 'MUST be atomic even under concurrent evaluate()' wording — the PLAN's documented decision wins; the SELECT→INSERT race is accepted, deduped on read."
  - "achievement.id is a stable, deterministic urn:learning:course:<courseId> (no randomness); credential id is urn:uuid:<verificationId> from random_bytes(16). DSGVO: no plaintext email in the payload (issuer branding identity only)."
  - "CERT-05/06/11/12 left Pending — code complete + unit-proven, but NOT live-verifiable (migration unapplied, no issuer key on any DB). Marked complete at 155-07, consistent with the 155-02/03 deferral discipline."

# Metrics
duration: ~70min
completed: 2026-06-27
---

# Phase 155 Plan 04: IssuanceService — Auto-Issue Self-Contained Credential Summary

**A pass now mints a certificate: `IssuanceService.issueIfPassed()` builds a self-contained OB3/VC 2.0 credential (course/score/threshold/dates/issuer+branding/verification-id all frozen at signing time), signs it via the 155-03 SigningService with the active key, persists the compact VC-JWT, and fires exactly one deduped Nextcloud notification. It is hooked into `PassCriteriaService::evaluate()` as a swallowed side-effect (CERT-05) so it can never 500 the live `GET /pass-status` read path, and enforces its OWN idempotency guard so repeated GETs issue exactly once.**

## Performance

- **Duration:** ~70 min
- **Started:** 2026-06-27
- **Completed:** 2026-06-27
- **Tasks:** 3 (Task 1 TDD: RED → GREEN) + 1 post-review enhancement (recipient identity)
- **Files:** 2 created, 15 modified

## Accomplishments
- **IssuanceService.issueIfPassed()** — bails on non-pass; OWN idempotency guard (`findByUserAndCourse` + not-revoked → return existing, no re-issue); builds the OB3/VC object; signs via `SigningService::sign()` with `KeyService::getActiveSigningMaterial()` (`sodium_memzero`s the secret after); persists the `Certificate` with the compact VC-JWT in `credential_json`; fires one deduped notification.
- **Self-contained credential (CERT-06)** — `@context` [VC v2, OB3 context-3.0.3], `id` `urn:uuid:<vid>`, `type` [VerifiableCredential, OpenBadgeCredential], `issuer` {did:web, Profile, themed name + logo image}, `validFrom`, `validUntil` (only when `cert_validity_days>0`), `credentialSubject` {AchievementSubject, **name=recipient NC display name**, achievement(name=frozen course title, description, criteria.narrative with threshold), result(score; threshold)}. Every field is frozen into the SIGNED payload — proven by decoding the real JWT in the unit test.
- **Recipient identity (CERT-06, DSGVO)** — `credentialSubject.name` is the recipient's NC display name via `IUserManager::get()->getDisplayName()` (falls back to the user id). The certificate is recipient-bound — required for the AWO compliance use case — while NO plaintext email is ever embedded (the test asserts no `@` in the subject).
- **Issuer branding (CERT-11)** via `OCP\Defaults::getName()` + `getLogo()`; `absoluteLogoUrl()` passes absolute theming URLs through and `getAbsoluteURL()`-wraps relative ones (falls back to the app icon).
- **Notifier 'certificate_issued' case (CERT-12)** mirrors `badge_earned`: translated `Certificate issued: %s` subject with the course title, app icon, app link. Fired from IssuanceService with `getCount()===0` dedup (mirrors `NotificationJob::sendNotification`).
- **i18n parity** — `Certificate issued: %s` added to de/en/fr/ru/ar `.json` in lockstep (real translations, not en-copies); `.js` bundles regenerated via `l10n_js_sync.py`; `check-i18n-parity.sh` green (2214 keys each, value-sync OK).
- **Hooked into evaluate() (CERT-05)** in the first-pass branch, immediately after `emitPassEventIfFirst()`, as a try/catch(\Throwable) swallowed side-effect — issuance failure logs a warning and the `PassResult` is returned unchanged.

## Blast Radius (CLAUDE.md MUST — evaluate())
- **Method:** `gitnexus impact` was unavailable this session (the CLI reinstalled deps then rejected `--target`; index stale at a106ce4). Blast radius established by call-graph grep instead.
- **Direct callers of `PassCriteriaService::evaluate()`:** exactly ONE — `CourseController::getPassStatus()` (`app/lib/Controller/CourseController.php:770`), serving `GET /api/courses/{courseId}/pass-status`.
- **Contract:** `evaluate()` returns a `PassResult` (consumed by the controller + frontend Zeugnisstatus widget). **Unchanged** — issuance is additive and the return shape is identical.
- **Risk:** MEDIUM (a live read endpoint). Mitigated structurally by the swallow-and-log guard; a 500 is impossible from the issuance branch.

## Task Commits

1. **Task 1 (RED): failing IssuanceServiceTest + OCP stubs** — `053ea02` (test) — 6 cases, RED confirmed in-container (class-not-found ×6)
2. **Task 1 (GREEN): IssuanceService** — `a1fcff7` (feat) — 6 tests / 36 assertions green, PHPStan L5 clean
3. **Task 2: Notifier certificate_issued + i18n (5 langs)** — `44fe53e` (feat) — php -l clean, parity green, .js regenerated
4. **Task 3: wire IssuanceService into evaluate()** — `3054639` (feat) — full suite 90/90 green, PHPStan L5 clean
5. **Post-review (advisor): freeze recipient display name into credentialSubject** — `ffc2cc8` (feat) — recipient-bound credential (CERT-06), DSGVO name-only; full suite 90/90 (334 assertions), PHPStan L5 clean

**Plan metadata:** _(final docs commit — this SUMMARY + STATE + ROADMAP)_

## Files Created/Modified
- `app/lib/Service/IssuanceService.php` — issueIfPassed/buildCredential/notify/absoluteLogoUrl/iso8601/uuidv4; ~250 LOC incl. docblocks
- `app/tests/Unit/Service/IssuanceServiceTest.php` — 6 tests / 36 assertions (issue-once, idempotent, not-passed, self-contained+validUntil, validUntil-omitted, branding); uses the REAL SigningService with a throwaway sodium key to decode + assert the actual signed payload
- `app/lib/Service/PassCriteriaService.php` — inject IssuanceService + LoggerInterface; first-pass swallowed-side-effect hook after emitPassEventIfFirst
- `app/lib/Notification/Notifier.php` — `case 'certificate_issued'`
- `app/tests/Unit/Service/PassCriteriaServiceTest.php` — makeService injects mocked IssuanceService + logger; 4 new regression tests (issues-once, no-issue-on-fail, swallows-failure)
- `app/tests/Support/PhpUnitStubs.php` — added OCP stubs absent from the unit bootstrap: `OCP\Notification\INotification` / extended `IManager` / `INotifier` / `UnknownNotificationException`, `OCP\Defaults`, `OCP\AppFramework\Utility\ITimeFactory`, `OCP\L10N\IFactory`, `IURLGenerator::getAbsoluteURL/getBaseUrl`, `IUserManager::get`, `IUser::getDisplayName`
- `app/tests/Unit/Service/CoopServiceTest.php` + `app/tests/Unit/Search/SearchProviderSafetyTest.php` — adjusted for the now-typed `IUserManager::get(): ?IUser` / `IUser::getDisplayName()` (createMock instead of addMethods; anon IUser impls add getDisplayName)
- `app/l10n/{de,en,fr,ru,ar}.json` + `.js` — `Certificate issued: %s` key (5 langs) + regenerated bundles

## Requirements Status

**CERT-05/06/11/12 deliberately left Pending** in REQUIREMENTS.md — `requirements mark-complete` was intentionally NOT run.

The code for all four is complete and unit-proven, but read as live TRUE/FALSE statements about the running system, none is verifiable yet: the **155-01 migration is not applied to any DB** and **no issuer key exists** (both are 155-07's job). A live pass today would hit the swallow-and-log guard (missing `learning_certificates` table / "No active signing key") and issue nothing — exactly the safe degradation that was designed.

- **CERT-05** (auto-issue on pass) — hook wired in evaluate(); unit-proven (issues-once + swallows-failure). Live issuance = 155-07.
- **CERT-06** (self-contained credential) — every field frozen into the signed payload; proven by decoding the real JWT. Live cross-DB verification = 155-07.
- **CERT-11** (issuer branding) — name + logo from OCP\Defaults baked into `issuer`. Live themed-instance check = 155-07.
- **CERT-12** (student notification) — one deduped `certificate_issued` notification; Notifier renders it. Live notification render = 155-07.

155-07 applies the migration, runs `occ learning:cert:init-issuer`, triggers a live pass, and marks all four complete.

## Deviations from Plan

- **[Decision] OCP\Defaults instead of OCP\Theming\IThemingDefaults** — the plan `<interfaces>` named `IThemingDefaults`, but that interface is absent from this NC's `vendor/nextcloud/ocp` package (PHPStan: 4× "unknown class") and not resolvable at runtime here either (`interface_exists` → false). Switched to the public `OCP\Defaults` (getName + getLogo), which is PHPStan-known and DI-autowirable. Same branding result; folded into the Task-1 GREEN commit.
- **[Rule 2 - Missing error handling] Swallow-and-log guard at the evaluate() call site** — not literally in the plan, but mandatory: the sole caller is a live read endpoint and the issuer/table are un-provisioned, so an un-guarded call would 500 `GET /pass-status`. Added `try/catch(\Throwable)+logger->warning`. IssuanceService itself stays clean/throwing so unit tests remain honest.
- **[Rule 3 - Blocking] Extended PhpUnitStubs.php** — the unit bootstrap lacked stubs for `OCP\Notification\INotification`, `OCP\Defaults`, `OCP\AppFramework\Utility\ITimeFactory`, `OCP\L10N\IFactory`, and `IURLGenerator::getAbsoluteURL`; mock generation failed without them. Added additive stubs (all `interface_exists`/`class_exists`-guarded). Also updated PassCriteriaServiceTest's constructor wiring (not in files_modified, but required by the new ctor params).
- **Atomicity caveat (surfaced, not resolved):** the task-prompt asked for an OWN *atomic* idempotency guard "even under concurrent evaluate()". The PLAN consciously decides the opposite (SELECT-then-INSERT, NO unique constraint, dedupe on read, so revoke + re-issue stays possible). Followed the PLAN. The SELECT→INSERT race from 154 is therefore inherited and accepted; a rare concurrent double-issue is deduped on read by callers (earliest non-revoked cert wins). No unique constraint was added.

## Issues Encountered
- **OCP\Theming\IThemingDefaults missing** (see Deviations) — cost one PHPStan round-trip; resolved via OCP\Defaults.
- **Unit bootstrap OCP gaps** — INotification/Defaults/ITimeFactory/IFactory not stubbed; first run failed on mock generation ("Class … does not exist") before the implementation even loaded. Added stubs; second RED was a clean class-not-found on IssuanceService.
- **No local PHP on the workstation** (carried 155-01..03) — `php -l`, PHPStan, PHPUnit all run in the relay `devcloud-app` container.
- **`deploy-prod.sh --php-only` does not sync `tests/`** (carried) — tests rsync'd to the host + `docker cp ~/learning-nc/app/tests` into the container before each phpunit run.
- **gitnexus impact CLI** reinstalled deps then rejected `--target`; index stale at a106ce4. Blast radius done via grep (see above). Did not burn turns fighting the tool (per advisor).

## Verification Results
- **IssuanceServiceTest**: `OK (6 tests, ~38 assertions)` in the relay container; RED confirmed first (6× class-not-found on IssuanceService). The REAL SigningService signs with a throwaway sodium key and the test decodes the actual JWT payload to assert self-containment + recipient name + branding + validUntil presence/absence + no-email.
- **PassCriteriaServiceTest**: `OK` — 11 tests incl. 4 new issuance-hook regressions; no 154 regression.
- **Full suite**: `OK (90 tests, 334 assertions)` — no regressions from the stub changes (CoopServiceTest + SearchProviderSafetyTest adjusted for the typed IUserManager/IUser).
- **PHPStan Level 5**: `No errors` (run on relay via `deploy-prod.sh --php-only`, after Task 1 and Task 3).
- **php -l** clean on Notifier.php (container); **grep** `certificate_issued` present in Notifier.
- **i18n parity** `check-i18n-parity.sh` green (2214 keys each across DE/EN/FR/RU/AR; .js↔.json value-sync OK); `Certificate issued` present in all 5 `.json`.

## User Setup Required
None for this plan. (Live issuance needs `occ learning:cert:init-issuer` + the 155-01 migration applied — both 155-07 / the v5.0.0 release plan.)

## Next Phase Readiness
- **155-05 (certificate view)** can read issued certs via `CertificateMapper::findByUserId/findByVerificationId`; the notification already links to the app (point it at the eventual certificate route when that exists).
- **155-07** applies the migration, runs `occ learning:cert:init-issuer`, triggers a live pass through `GET /pass-status`, re-runs the independent Python verifier on the REAL issued cert (ADR-001 #2), asserts kid↔did.json (#3), runs the leakage audit (Rule 18), and marks CERT-05/06/11/12 complete.
- **Carry-forward:** migration still NOT applied + info.xml NOT version-bumped; container `python3-cryptography` install remains non-persistent (open INBOX item for 155-07).

## Self-Check: PASSED

- Files on disk: `app/lib/Service/IssuanceService.php` FOUND, `app/tests/Unit/Service/IssuanceServiceTest.php` FOUND.
- Commits in history: `053ea02` (RED) FOUND, `a1fcff7` (GREEN IssuanceService) FOUND, `44fe53e` (Notifier+i18n) FOUND, `3054639` (evaluate hook) FOUND, `ffc2cc8` (recipient name) FOUND.
- Tests: 90/90 green (334 assertions); PHPStan L5 clean; i18n parity green.

---
*Phase: 155-certificate-artifact-issuer*
*Completed: 2026-06-27*
