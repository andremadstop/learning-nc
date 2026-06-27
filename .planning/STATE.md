---
gsd_state_version: 1.0
milestone: v5.0.0
milestone_name: "v5.0.0 Certification-as-a-Service"
current_phase: 155
current_plan: 05
status: in-progress
stopped_at: "155-07 NON-PROD gates executed + committed (Tasks 1-4 scripts); STOPPED at the live-provisioning boundary (multi-AI review gates the live schema change). REAL GREEN this run: (T1) leakage gate — LeakageAuditTest 3/3 (39 assertions) on relay + 10-surface 155-LEAKAGE-AUDIT.md + PHPStan L5 clean + grep gate (secret_key_enc absent from all 3 export surfaces); (T2) cross-DB go/no-go — scripts/cross-db-migration-check.sh ran GO (exit 0) on an ephemeral mariadb:11.4 utf8mb4 container (both tables + all 3 indexes, no 'key too long'); (T4 mechanism) independent Ed25519 verifier proven GREEN against a locally-synthesized signed VC-JWT (valid→0, tampered→1, zero prod contact). DEFERRED-TO-POST-REVIEW-LIVE-GATE: applying Version009100 to live PG16 (occ upgrade), occ learning:cert:init-issuer, info.xml bump, a real student pass, the live did.json+kid assertion in test-api.sh (currently SKIP — live did.json is HTTP 500, cert_keys table absent), the destructive rotation-preserves check (ALLOW_LIVE_ROTATE, NOT run), and verify-issued-cert-gate.sh on a real issued cert. NO live migration / init-issuer / info.xml bump / real pass performed (HARD PROD BOUNDARY honored). Requirements NOT marked complete (checkpoint, not plan-close). Commits: 20f3666 (T1), be014ab (T2), 93c4d6a (T3 test-api.sh), 00cfeca (T4). Combined 06+07 live walkthrough recorded below (Session Continuity)."
last_updated: "2026-06-27T00:00:00.000Z"
last_activity: 2026-06-27 — 155-07 non-prod security+portability gates RUN + committed; stopped at the live-provisioning boundary (gated behind multi-AI review). GREEN: leakage gate (LeakageAuditTest 3/3, 39 assertions, PHPStan L5, 10-surface audit), cross-DB go/no-go on ephemeral mariadb:11.4 utf8mb4 (GO), independent verifier mechanism on a synthetic JWT. DEFERRED to post-review live gate: live migration/occ upgrade, init-issuer, info.xml bump, real pass, live did.json+kid + rotation + real-cert independent-verify. No prod mutation. Requirements left Pending (checkpoint). Commits 20f3666, be014ab, 93c4d6a, 00cfeca.
progress:
  total_phases: 4
  completed_phases: 1
  total_plans: 7
  completed_plans: 5
  percent: 71
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-26)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v5.0.0 Certification-as-a-Service — Phase 155: Certificate-Artifact & Issuer

## Current Position

Phase: 155 of 157 (Certificate-Artifact & Issuer) — executing
Plan: 155-05 complete (5/7); 155-06 PAUSED at human-verify; 155-07 NON-PROD gates run + committed, STOPPED at the live-provisioning boundary
Status: 155-07 ran the automatable security + portability gates that do NOT mutate live prod, then STOPPED at the live-provisioning boundary (all live schema/data changes are gated behind a multi-AI review that has not happened yet). REAL GREEN this run: leakage gate (LeakageAuditTest 3/3 = 39 assertions on relay + 10-surface 155-LEAKAGE-AUDIT.md sign-off + PHPStan L5 clean + grep gate clean), cross-DB go/no-go (scripts/cross-db-migration-check.sh → GO on an ephemeral mariadb:11.4 utf8mb4 container: both tables + all 3 indexes, no 'key too long'), and the independent Ed25519 verifier mechanism (proven GREEN on a locally-synthesized signed VC-JWT — valid→0, tampered→1, zero prod contact). Task 3 (test-api.sh kid↔did.json + rotation) and Task 4 (verify-issued-cert-gate.sh) scripts are written + committed but their LIVE execution is DEFERRED-TO-POST-REVIEW-LIVE-GATE (live did.json is HTTP 500 — cert_keys table absent; rotation is a destructive occ --rotate gated behind ALLOW_LIVE_ROTATE, NOT run). NO live migration / occ upgrade / init-issuer / info.xml bump / real pass performed. Requirements left Pending (this is a checkpoint, not plan-close). 155-06 human-verify is BLOCKED on the same provisioning — combined post-review walkthrough recorded in Session Continuity.
Last activity: 2026-06-27 — 155-07 non-prod gates run + committed (20f3666, be014ab, 93c4d6a, 00cfeca); stopped at the live-provisioning boundary. See Execution Notes (155-07).

Progress: [███████░░░] 71% (5/7 plans complete; 155-06 + 155-07 both paused pending the multi-AI review + live provisioning)

## Performance Metrics

- Granularity: standard
- Parallelization: on (Phases 156+157 run parallel after 155)
- v4.4.0 shipped: 5 phases (149-153), 30 plans — App Store live 2026-04-27

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 154 Pass-Definition | P01 | ~15min | 2 | 4 |
| 154 Pass-Definition | P02 | ~20min | 2 | 2 |
| 154 Pass-Definition | P03 | ~30min | 2 | 4 |
| 154 Pass-Definition | P04 | ~50min | 3 | 15 |
| 154 Pass-Definition | P05 | ~25min | 3 | 4 |
| 155 Cert-Artifact | P01 | ~35min | 3 | 7 |
| 155 Cert-Artifact | P02 | ~40min | 3 | 7 |
| 155 Cert-Artifact | P03 | ~35min | 3 | 4 |
| 155 Cert-Artifact | P04 | ~70min | 3 | 15 |
| 155 Cert-Artifact | P05 | ~35min | 2 | 5 |

## Accumulated Context

### Key Decisions (v5.0.0)

- [Roadmap] Phase 154 first — lucky-guess exclusion + FSRS-vs-pass separation locked before any crypto work
- [Roadmap] Phase 155 entry gate — signing-format ADR (VC-JWT vs eddsa-jcs-2022) recorded as FIRST task; no signing code before ADR is decided
- [Roadmap] Issuer = did:web of the NC instance; NO multi-tenant platform; wallet interop deferred to v6+
- [Roadmap] Key rotation (oc_learning_cert_keys) IS in scope for Phase 155 — owner decision; rotation must not invalidate past certs
- [Roadmap] Phases 156+157 run parallel after Phase 155 closes (config.parallelization=true)

See PROJECT.md Key Decisions for full table and prior milestone decisions.

### Critical Pitfalls (carry into Phase 155)

- PITFALL 1: Signing wrong bytes (canonicalization) — ADR resolves this as first task of Phase 155
- PITFALL 2: Private key in wrong location — ICrypto::encrypt() only; never AppConfig plaintext; never in exports/snapshots/app package

### Blockers/Concerns

- TOOLING: `gsd-tools state update-progress` / `record-session` corrupted STATE.md frontmatter during 154-01 execution (overwrote milestone v5.0.0 → v2.3, lost current_phase/plan, wrong phase totals). Restored manually. Avoid `state advance-plan`/`update-progress` on this STATE.md until the parser handles the v5.0.0 frontmatter shape.

### Execution Notes (154-01)

- Skeleton constructor uses plain params + scoped `@phpstan-ignore-next-line`: PHPStan L5 rejects both unused promoted readonly properties and unused plain params; deps go unused until 154-03 replaces the body.

### Execution Notes (154-02)

- cert columns stored as typed columns on `learning_courses` (not JSON in mode_config) — locked Phase 154 decision. cert_required_pool_ids is raw TEXT; JSON decode happens in PassCriteriaService, not the mapper.
- jsonSerialize emits **snake_case** cert keys (cert_enabled, ...), deviating from the plan's camelCase — frontend reads course fields snake_case (c.maintenance_mode). Entity property/getter names stay camelCase.
- relay devcloud needed `occ upgrade` to apply the migration (was needsDbUpgrade=true: code 4.4.7 vs DB 4.4.6); `migrations:execute` is unavailable on this NC 33 instance, only `migrations:preview`. devcloud-db PG superuser is `oc_admin` (not `nextcloud`).
- **PASS-01..04 deliberately left Pending** in REQUIREMENTS.md — only DB foundation laid; instructor-facing capability needs controller (154-04) + UI (154-05). Mark complete at 154-05.
- **Release carry-forward:** info.xml NOT bumped in this plan; other instances won't get cert columns on app upgrade until the v5.0.0 release plan bumps info.xml past this migration's ship version.

### Execution Notes (154-03)

- **mastery_rate is already a percentage (0-100.0)** — getMasteryStats() returns round($mastered/$total*100,1). Gate 2 compares `mastery_rate >= cert_pass_percent` directly. Research Pattern 2's x100 multiplier was WRONG per codebase.
- **PASS-07 audit trigger is LAZY** — course.passed event fires inside evaluate(), called by GET /pass-status (154-04), not at exam completion. Idempotency guard (SELECT-then-INSERT on decoded context_json course_id) makes repeated GETs safe.
- **Lucky-guess exclusion is STRUCTURAL, verified** — no is_guessed column anywhere in app/lib/; the "Guessed" self-rating button lives ONLY in LeitnerMode.vue (FSRS UI). Exam-mode sessions score correct_answers objectively; Gate 1 reads getExamScore (mode='exam') so guesses never reach it. Gate tests MOCK getExamScore/getMasteryStats → they prove gate LOGIC, not the exclusion (exclusion asserted indirectly via CourseSummaryServiceTest mode/completed_at filters).
- **Accepted race condition (deferred):** emitPassEventIfFirst SELECT→INSERT not atomic; two simultaneous qualifying GETs could double-insert. Append-only audit table → minor data-quality only. Proper fix (course_id column + UNIQUE index) deferred to future migration.
- **PASS-02/03/05/07 marked complete** in REQUIREMENTS via this plan's frontmatter. PASS-01/04 remain Pending until controller (154-04) + UI (154-05).

### Execution Notes (154-04)

- **`occ routes:list` does not exist on NC 33** — correct command is `occ router:list <app>`. Plan verify commands using `routes:list` fail with "no commands in the routes namespace". Update future plans.
- **No `app/src/services/` dir existed** — components call axios inline. Created `CourseService.js` as the first service module; 154-05 consumes it (CourseTabVerwaltung + CourseSummary).
- **i18n parity gate requires all 5 langs** — `check-i18n-parity.sh` enforces identical key-sets across DE/EN/FR/RU/AR. Adding keys to only de+en (as the plan said) would fail Gate 1. Added real FR/RU/AR translations. Regenerate `.js` with `python3 scripts/l10n_js_sync.py`; guard with `--check` + parity script.
- **PASS-01..04/06 still Pending** — endpoints wired but end-to-end (instructor/student-facing) capability needs 154-05 UI. Mark complete at 154-05, consistent with the 154-02/03 deferral notes. No requirements flipped in this plan.
- **Authenticated live API tests not run** — vault credentials file absent + no `ADMIN_PASS`/`SECOND_PASS` in env. Routes/verbs confirmed via unauthenticated smoke (405 on GET cert-config, 401 on pass-status/PATCH; no 500 → DI sound). Logic unit-proven in 154-03. test-api.sh assertions are `bash -n` valid and run under Gate 2 with creds.
- **PassCriteriaService autowired** — constructor takes only DI-resolvable services/interfaces; no Application.php registration.

### Execution Notes (154-05)

- **Cert config reads SNAKE_CASE from the `course` prop** (cert_enabled, cert_pass_percent, cert_required_pool_ids, cert_validity_days) — the entity's jsonSerialize emits snake_case (154-02 decision). The plan's camelCase `created()` would never have synced; used the existing `watch.course` (immediate) handler instead.
- **Pool checkboxes emit integer `pool_id`** — NOT `course['pools'][n]['id']` (that's the mapping-row id). Carried the 154-04 gotcha through. A new `coursePools` prop was threaded CourseDetail → CourseTabVerwaltung (outside the plan's files_modified).
- **Zeugnisstatus is a NEW widget in CourseSummary.vue** — lines 64-81 were the functional snapshot/swarm card, not a placeholder to replace.
- **createInstance() test harness hardcodes the exposed computeds** — the 4 new Zeugnisstatus computeds had to be registered in its defineProperties block for the state-assertion tests to read them.
- **Phase 154 CLOSED** — all 7 PASS requirements Complete. Next: Phase 155 (Certificate-Artifact & Issuer); ENTRY GATE = signing-format ADR (VC-JWT vs eddsa-jcs-2022) as FIRST task, no signing code before ADR.
- **`CourseService::findById()['pools'][n]['id']` is the course-pool MAPPING row id, NOT the pool id** — actual pool id is `'pool_id'` (`getPoolSnapshot()` adds no id; `CoursePool::jsonSerialize` sets both). Pool-ID validation must read `pool_id`. The plan's interface comment was wrong; fixed post-review in commit `2767662`. Carry into 154-05 when the UI sends `certRequiredPoolIds`.

### Execution Notes (155-01)

- **VC-JOSE-COSE contract is FROZEN** in `155-ADR-ANCHOR.md` — header `{alg:EdDSA,typ:vc+jwt,cty:vc,kid}`, payload = OB3 object DIRECT (no `vc` wrapper, no `iss`/`sub`/`nbf`/`jti` mirroring), sign with `JSON_UNESCAPED_SLASHES`. did:web path-based `did:web:<host>:apps:learning` → `/apps/learning/did.json`, `publicKeyJwk`, kid == verificationMethod.id. Plans 02-07 must satisfy this verbatim.
- **CERT-03 leakage primitive is structural** — `CertKey::jsonSerialize()` returns an explicit allowlist `['id','key_id','public_key_b64u','status','created_at']`; `secret_key_enc` is never a key in any serialized form. Re-audited in 155-07.
- **certificate.key_id is a string reference (not a DB FK)** to `learning_cert_keys.key_id` — rotation: retired keys keep verifying past certs (`CertKeyMapper::findAllNonRevoked` = status != 'revoked').
- **No local PHP on the workstation** — `php` not installed; lint via `docker exec -i devcloud-app php -l`, PHPStan/PHPUnit on relay only. deploy-prod.sh "Verifying deploy" prints a pre-existing harmless `OCP\AppFramework\App not found` (standalone-CLI smoke); PHPStan still reports "No errors".
- **Migration NOT applied + info.xml NOT bumped** — cross-DB go/no-go (PG16+MariaDB 11.4) is 155-07; version bump is the v5.0.0 release plan's job (carry-forward from Phase 154).
- **ADR follow-ups routed:** #1 encoding correctness + #2 independent verifier → 155-03; #3 kid↔did.json curl → 155-07.

### Execution Notes (155-02)

- **Encrypt-at-rest is enforced both ways** — `KeyService::init()` rejects a null/empty/passthrough ciphertext (never stores plaintext) and `sodium_memzero`s every plaintext copy; `getActiveSigningMaterial()` rejects any decrypt that is not exactly 64 bytes, defeating `EncryptionService::decrypt()`'s silent plaintext fallback (the documented silent-corruption trap). 155-04 must call `getActiveSigningMaterial()` (not decrypt directly).
- **kid single source of truth** — both the occ command and `DidController` derive the did:web string from `KeyService::hostDid()` (`did:web:<host>:apps:learning`). 155-03's JWT `kid` MUST be `hostDid() . '#' . keyId` == `verificationMethod.id`. keyId == base64url(public key).
- **Rotation = retire-not-delete** — `rotate()` UPDATEs the old active row to `status='retired'` then inserts a fresh active; `did.json` serves all `findAllNonRevoked()` (active + retired). Never delete a key with live certs.
- **CERT-01..04 left Pending** — code-complete + unit/static-proven, but live occ/curl needs the 155-01 migration applied (table missing on devcloud). A live `occ learning:cert:init-issuer` would fail today. 155-07 applies the migration, runs live occ + did.json curl (ADR #3), and marks all four complete.
- **deploy-prod.sh --php-only does NOT sync tests/** — only lib/ + l10n. New test files must be scp'd to host + `docker cp`'d into the container, else PHPUnit reports "No tests executed".
- **did.json content-type = application/json** (plain JSONResponse, per plan + Pattern 2). 155-07 may switch to `application/did+json` if its curl assertion is strict.

### Execution Notes (155-03)

- **SigningService injects KeyService, kid via `hostDid()`** — NOT a private `parse_url` re-derivation. The kid (`hostDid().'#'.keyId`) is the SAME string as `DidController.verificationMethod.id` by construction, so kid-drift (Pitfall 4) is structurally impossible. The plan's Task-2 "host from IURLGenerator" is satisfied by `hostDid()` internally; the must_haves key_link + 155-02 handoff (line 124) settle the wording. 155-04 calls `sign($ob3, $material['key'], $material['secret'])` from `KeyService::getActiveSigningMaterial()`.
- **Byte fidelity is structural** — header AND payload serialized with `JSON_UNESCAPED_SLASHES`; signing operates on `b64u(header).'.'.b64u(payload)`, so signed bytes == emitted bytes (zero canonicalization). Payload = OB3 object DIRECTLY (no `vc`/`vp` wrapper, no `iss`/`sub`/`nbf`/`jti` — VC-JWT 1.1 forbidden). Proven by byte-stability + header-contract tests (ADR-001 follow-up #1).
- **Independent verifier runs for real** — `scripts/verify-credential.py` (Python `cryptography` Ed25519) re-verifies `header.payload` from the base64url JWK `x` alone (ADR-001 follow-up #2). Container had `python3` but no `cryptography` and no pip/ensurepip → installed `python3-cryptography` 43.0.0 via `apt-get` (as root). **This install is non-persistent across container rebuild — 155-07's independent-verify gate must (re)ensure `cryptography` is present or it errors instead of pass/fail.** Test invokes phpunit with `-e VERIFY_SCRIPT=/tmp/verify-credential.py` (script `docker cp`'d there; `scripts/` is not in the deploy bundle).
- **verify-credential.py is dev-only by construction** — repo-root `scripts/` is outside the release Makefile allowlist (`appinfo/css/img/js/lib/templates`) AND the deploy bundle (`lib/ appinfo/ l10n/ templates/`). Added a `!scripts/verify-credential.py` .gitignore negation (the `scripts/*` blanket-ignore otherwise dropped the deliverable). NO new package.json/composer dependency. Re-audited by 155-07 leakage gate (Rule 18).
- **CERT-06 left Pending** — 155-03 delivers the signing MECHANISM (+ independent verifiability); CERT-06's "self-contained, all-fields-embedded-at-signing-time" substance is realized at issuance (155-04) and verified live at 155-07. Consistent with the 155-02 CERT-01..04 deferral discipline. `requirements mark-complete` NOT run.
- **TDD 5/5 green (17 assertions)** on relay; RED confirmed first (4× class-not-found + Test 5 script-not-resolvable — Test 5 executed, not skipped). PHPStan L5 clean.

### Execution Notes (155-04)

- **Issuer branding uses `OCP\Defaults`, NOT `OCP\Theming\IThemingDefaults`** — the latter is ABSENT from this NC's `vendor/nextcloud/ocp` package (PHPStan: 4× "unknown class") and not resolvable at runtime here either (`interface_exists` → false; only the concrete `apps/theming/lib/ThemingDefaults.php` exists). `OCP\Defaults` exposes `getName():string` + `getLogo(bool):string`, is in the OCP package (PHPStan-known), and is DI-autowirable. Use `OCP\Defaults` for any instance-name/logo needs in 155-05/06/07.
- **Issuance is a SWALLOWED side-effect of `evaluate()`** — the sole caller is `CourseController::getPassStatus()` (live `GET /pass-status`). Migration unapplied + no issuer key ⇒ an un-guarded `issueIfPassed()` would throw (missing `learning_certificates` / "No active signing key") and 500 the endpoint. `evaluate()` wraps the call in `try/catch(\Throwable)+logger->warning`; `IssuanceService` itself stays clean/throwing (unit tests honest). 155-07's live test will see real issuance once the table + key exist.
- **OWN idempotency guard, non-atomic by design** — `findByUserAndCourse()` + not-revoked → return existing, else issue. NO unique constraint (revoke + re-issue must stay possible); a rare concurrent double-issue is deduped ON READ (earliest non-revoked cert wins). This consciously inherits 154's accepted SELECT→INSERT race. (Task-prompt asked for "atomic even under concurrent evaluate()"; the PLAN's documented non-atomic decision wins — flagged in the SUMMARY.)
- **Self-contained payload (CERT-06) frozen at signing** — `urn:uuid:<vid>` id, did:web issuer + themed name/logo, `validFrom`, `validUntil` ONLY when `cert_validity_days>0` (`expires_at = issued_at + days*86400`), achievement(name=frozen course title, criteria.narrative w/ threshold), result(score; threshold). `achievement.id = urn:learning:course:<id>` (deterministic). NO plaintext email (DSGVO).
- **Unit bootstrap OCP gaps** — `tests/Support/PhpUnitStubs.php` lacked `OCP\Notification\INotification`, `OCP\Defaults`, `OCP\AppFramework\Utility\ITimeFactory`, `OCP\L10N\IFactory`, `IURLGenerator::getAbsoluteURL`; mock generation failed until stubbed (additive, `*_exists`-guarded). Future issuance/notification/theming unit tests can rely on these now.
- **gitnexus impact unavailable** — CLI reinstalled deps then rejected `--target` (index stale at a106ce4). Blast radius done by grep: `evaluate()` has exactly ONE caller (`CourseController::getPassStatus`, `GET /pass-status`); return contract `PassResult` unchanged.
- **CERT-05/06/11/12 left Pending** — code + unit-proven, not live-verifiable (migration unapplied, no issuer key). 155-07 applies migration, `occ learning:cert:init-issuer`, triggers a live pass, marks all four complete. `requirements mark-complete` NOT run.

### Execution Notes (155-05)

- **Auth via `?string $userId` ctor param, NOT `IUserSession->getUID()`** — the plan `<interfaces>` text twice said to use `IUserSession`, but a grep found ZERO controllers in this codebase using it; `IcsController` (the cited authenticated reference) injects `?string $userId` and null-guards → 401. Followed the codebase pattern (identical auth, DI-autowired). Carry into 155-06/07.
- **`@NoAdminRequired` is load-bearing AND unit-uncatchable** — a controller method with NO annotation defaults to admin-required in NC, so a student would 403. Unit tests instantiate the controller directly and bypass the annotation middleware, so they are green regardless. The annotation is present on `index`/`show`/`download`; correctness rests on inspection (and a credentialed Gate 2 at 155-07).
- **`download()` typed `: Response` (supertype)** — the 403/404 branches return a `JSONResponse`; the plan's literal `: DataDownloadResponse` would be a PHPStan L5 type error. Mirrors `IcsController::feed()`'s `: Http\Response`.
- **CERT-09 download artifact = OB3 JSON-LD `EnvelopedVerifiableCredential`** wrapping the stored compact VC-JWT (`{'@context':[https://www.w3.org/ns/credentials/v2],'type':'EnvelopedVerifiableCredential','id':'data:application/vc+jwt,<jwt>'}`), `json_encode` w/ `JSON_UNESCAPED_SLASHES`, `application/ld+json`, `certificate-<vid>.json`. `?format=jwt` → raw compact JWT (`application/vc+jwt`). This is the spec-correct way to present a JWT-secured VC as JSON-LD (RESEARCH).
- **Ownership (no IDOR)** — `show`/`download` load by verification-id then compare `cert.userId === currentUid`: **403 with a BARE error (no cert body)** on mismatch, **404** on not-found. The no-leak behavior is asserted (`assertArrayNotHasKey credential_json/verification_id`).
- **PhpUnitStubs extended** — added a `Response` base + `JSONResponse`/`DataDownloadResponse` (so `download()`'s `: Response` union resolves in unit tests) and `Http::STATUS_FORBIDDEN`/`STATUS_NOT_FOUND`. Additive, `class_exists`-guarded. Future controller tests can rely on these.
- **`deploy-prod.sh --php-only` does NOT sync `tests/`** (carried) — rsync tests to host + `docker cp ~/learning-nc/app/tests` into the container before each phpunit run.
- **CERT-07/09 left Pending** — CERT-07 ("view AND print", window.print + stylesheet) is the 155-06 Vue UI; CERT-09's download MECHANISM is delivered + unit-proven here but the user-facing button is 155-06 and a live real cert is 155-07. `requirements mark-complete` NOT run (deferral discipline).
- **Gate 2 (live authenticated API) not run** — no vault creds / `ADMIN_PASS` in env (carried from 154-04); routes/verbs live-registered (`occ router:list`), logic unit-proven. With the 155-01 migration unapplied + no issued certs, there is nothing to list live yet anyway.

### Execution Notes (155-06) — PAUSED at human-verify checkpoint

- **Plan 06 is NOT complete** — it ends with a `checkpoint:human-verify` (blocking). All `type=auto` tasks (1+2) are done, committed, and the frontend is deployed; Task 3 (the live walkthrough) is PAUSED awaiting Andre's approval. `155-06-SUMMARY.md` is deliberately NOT written until then (task override). Commits: `316bd1b` (Certificate.vue + vendored QR + helper + i18n), `d4ff9e3` (tests + CourseSummary entry + i18n), `77c6732` (STATE/ROADMAP), `8493075` (dist bundle), `3ed5376` (webroot verify-URL fix).
- **⚠ The human-verify walkthrough is unexercisable in the current repo state.** devcloud has NO `oc_learning_cert*` tables (`psql \dt` → "Did not find any relation") because the **155-01 migration is unapplied** and **no issuer key exists**. So a live pass hits the 155-04 swallow-and-log guard → no certificate, no notification, nothing for the UI to open; and `occ learning:cert:init-issuer` would itself fail (missing `learning_cert_keys`). Provisioning (apply migration + init-issuer + the info.xml bump this NC needs to run `occ upgrade`) is **155-07 / release scope** and was deliberately NOT done here — applying a schema change on live devcloud is a Rule-4 architectural action that contradicts the deferral discipline every prior 155 plan kept. **Awaited decision: defer the human-verify to 155-07, or provision now.**
- **No `@vue/test-utils`; vitest only collects `tests/unit/**/*.test.js`** — the plan's `src/components/__tests__/Certificate.spec.js` would never be collected, and component-mounting isn't a codebase pattern (logic lives in utils). DEVIATION: extracted the testable logic into `app/src/utils/certificate-credential.js` (UTF-8-safe JWT decode, OB3 field extraction, verify-URL, LinkedIn-URL) and tested it in `app/tests/unit/CertificateShare.test.js` (10 cases; `npm run test -- Certificate` matches the capital-C filename). Behaviors 1 (print spy) + 5 (live i18n render) are covered by the relay human-verify (steps 4 + 8), not by mounting.
- **TDD note:** Task 2 is `tdd="true"`, but the helper logic was authored in Task 1 (it is shared by Certificate.vue), so `CertificateShare.test.js` is GREEN on first run rather than RED — characterization rather than test-first. Acceptable: the same module both ships the component logic and is asserted.
- **QR is a vendored MIT single file, NOT an npm dep** — `curl`'d qrcode-generator v1.4.4 (Kazuhiko Arase, MIT) from jsDelivr, replaced its trailing UMD wrapper with an ESM `export default qrcode`, added `/* eslint-disable */` under the intact MIT header (third-party style preserved). Node smoke-test confirmed it emits a valid SVG + gif data-URL for a verify URL; the component binds `qr.createDataURL(4,2)` to an `<img>` (no v-html). No `package.json` change.
- **UTF-8-safe JWT decode is load-bearing** — the entity returns the raw compact JWT in `credential_json` (no server decode), and titles/recipient names are German/Arabic-first, so `decodeCredential` uses `TextDecoder().decode(Uint8Array.from(atob(b64url-fixed), c=>c.charCodeAt(0)))`, not bare `atob`. Asserted with "Jürgen Müller" / "…für Pflegekräfte".
- **`window.print()` is literal in Certificate.vue** (must_haves contains-check) + a non-scoped `@media print` block isolates `.certificate-printable` via the visibility trick (works through NcModal's body-teleport).
- **[Rule 1 - Bug, post-review] verify URL is webroot/subpath-safe** — `window.location.origin` + a hardcoded `/apps/learning/verify/` dropped the webroot on subpath installs (`https://host/nextcloud/...`) and index.php-routed installs, so the QR, the LinkedIn `certUrl` and the displayed URL would 404 on any non-root Nextcloud (the app ships via the App Store to arbitrary instances; e.g. external user `ernesst`). devcloud is root, so the human-verify would NOT catch it. Fixed: `buildVerifyUrl(origin, path)` now joins origin + a `generateUrl('/apps/learning/verify/'+vid)` path. Commit `3ed5376` (+1 subpath test, rebuilt+redeployed). Advisor-caught.
- **CourseSummary empty-case handled** — `resolveCertificate()` calls `listCertificates()`, filters by `course_id` + not-revoked, newest first; on `[]` / error it leaves `certVerificationId=null` so the "Zertifikat ansehen" button stays hidden (devcloud returns nothing until 155-07).
- **i18n** — 14 new keys (13 in 155-06 commit 1, "Zertifikat ansehen" in commit 2) added to all 5 langs (DE source value==key; real EN/FR/RU/AR), `.js` regenerated via `l10n_js_sync.py`, `check-i18n-parity.sh` green. Note: the parity script lives at **repo-root** `scripts/`, not `app/scripts/` — the plan's `cd app && bash scripts/check-i18n-parity.sh` verify path is off by a dir; run from repo root.
- **Deploy** — `deploy-prod.sh --js-only` (no PHP touched); build "Build checks passed", "JS + CSS deployed". CERT-07/08/09/10/11/13 stay Pending (live render needs a real cert = 155-07); `requirements mark-complete` deliberately NOT run.

### Execution Notes (155-07) — NON-PROD gates run, STOPPED at the live-provisioning boundary

- **Scope of this run:** ONLY the automatable security + portability gates that do NOT mutate live prod. All live provisioning (schema/data) is gated behind a multi-AI review that has not happened. So `occ upgrade`/migration apply, `occ learning:cert:init-issuer`, info.xml bump, and any real student pass were FORBIDDEN and NOT done (HARD PROD BOUNDARY). `deploy-prod.sh --php-only` was verified safe first: it only rsyncs lib/templates/routes/info.xml(unchanged 4.4.7)+l10n + docker cp + apache graceful + PHPStan — NO `occ upgrade`/`migrations:`, so Version009100 stays dormant.
- **Task 1 (leakage gate) — REAL GREEN.** `155-LEAKAGE-AUDIT.md` (prior partial-run file, kept) signs off 10 surfaces + the trust model (DB backup holds only the ICrypto ciphertext; recovery needs the separately-held config.php secret = accepted NC at-rest model). Verified its table claims (DataExportService 7 tables, DataMobilityService 3, snapshot body via DataMobilityService — no cert tables). `LeakageAuditTest.php` (untracked prior file) ran 3 tests / 39 assertions GREEN on relay (deployed lib via --php-only, scp+docker cp tests, phpunit --filter). PHPStan L5 clean. grep gate clean. Commit `20f3666`.
- **Task 2 (cross-DB go/no-go) — REAL GREEN (MariaDB); PG16 DEFERRED.** `scripts/cross-db-migration-check.sh` mirrors Version009100 DDL verbatim (oc_ prefix, utf8mb4_general_ci, exact VARCHAR lengths, TEXT-unindexed secret/credential, index names). RAN locally (workstation docker, ephemeral `mariadb:11.4 --rm`, fully isolated) → GO (exit 0): both tables + all 3 indexes (22/19/23 chars), no 'Specified key was too long', container torn down. PG16 side is a deliberate no-op (live `occ db:show-table` post-review). Gotcha fixed: `mariadb-admin ping` reports alive against MariaDB's passwordless temp init server → readiness must be a real authenticated `SELECT 1`. Commit `be014ab`.
- **Task 3 (kid↔did.json + rotation) — WRITTEN + committed; LIVE DEFERRED.** Added a cert block to `scripts/test-api.sh`: asserts verificationMethod ids are `did:web:<host>:apps:learning#…`, decodes an issued JWT header and HARD-asserts `kid == verificationMethod.id`, plus rotation-preserves. Guarded: SKIP-with-note while issuer unprovisioned (confirmed live did.json is **HTTP 500** — cert_keys table absent), auto-activates to hard-assert once provisioned. The destructive `occ --rotate` step is opt-in (`ALLOW_LIVE_ROTATE=1`) — NOT run. `bash -n` OK. Commit `93c4d6a`.
- **Task 4 (independent-verify gate) — WRITTEN + committed; mechanism GREEN, real-cert DEFERRED.** `scripts/verify-issued-cert-gate.sh` pulls a REAL issued JWT (GET /api/certificates), resolves the public key x from did.json by kid, runs `verify-credential.py`, asserts valid→0 + tampered→non-zero, and FAIL-NOT-SKIPs if python3/cryptography absent. Its live run needs a real prod-issued cert → DEFERRED. The verifier MECHANISM it depends on was exercised GREEN against a locally-synthesized Ed25519 VC-JWT (workstation cryptography 43.0.3, zero prod contact): valid verified, tampered rejected. Commit `00cfeca`.
- **Requirements:** NONE marked complete (CERT-01/02/03/04/06 stay Pending) — this run returns a checkpoint, not a plan-close. CERT-03 (leakage) is fully proven this run but flips only at plan-close after live verification. `requirements mark-complete` NOT run (deferral discipline, consistent with 155-02..05).
- **Open carry:** container `python3-cryptography` is non-persistent (INBOX 2026-06-27) — the post-review verify-issued-cert-gate run must (re)ensure it. STATE/ROADMAP edited by hand (gsd-tools corrupt v5.0.0 frontmatter).

## Session Continuity

Last session: 2026-06-27T00:00:00.000Z
Stopped at: 155-07 non-prod security+portability gates RUN + committed (20f3666 leakage, be014ab cross-DB, 93c4d6a test-api.sh, 00cfeca verify gate); STOPPED at the live-provisioning boundary. Leakage gate + cross-DB (MariaDB) + independent-verifier mechanism are REAL GREEN; live did.json/kid + rotation + real-cert independent-verify + the 155-06 human-verify are all DEFERRED-TO-POST-REVIEW-LIVE-GATE (they share one blocker: the unapplied Version009100 migration + missing issuer key). NO prod mutation. Requirements left Pending. 155-06-SUMMARY + 155-07-SUMMARY deliberately NOT written (both plans paused, not closed).

Resume file: .planning/phases/155-certificate-artifact-issuer/155-07-PLAN.md (live gates) + 155-06-PLAN.md (Task 3 human-verify)

### POST-REVIEW LIVE PROVISIONING WALKTHROUGH (combined 155-06 + 155-07, run only after the multi-AI review approves the live schema change)

1. **Multi-AI review** of Version009100 + the provisioning plan → explicit approval (this is the gate that unblocks everything below).
2. **Bump `app/appinfo/info.xml` version** past 4.4.7 (so NC flags needsDbUpgrade) + `./scripts/deploy-prod.sh --php-only`.
3. **`occ upgrade`** on devcloud (migrations:execute is unavailable on this NC33 — upgrade is the apply path) → creates `learning_cert_keys` + `learning_certificates` on live PG16.
4. **Verify PG16:** `ssh relais 'docker exec -u www-data devcloud-app php occ db:show-table learning_cert_keys'` + `… learning_certificates` (the cross-db script's deferred PG assertion).
5. **`occ learning:cert:init-issuer`** → generates the Ed25519 issuer key (ICrypto-encrypted); note key_id + kid (CERT-01).
6. **`curl https://devcloud.andrestiebitz.de/apps/learning/did.json`** → 200 with a non-empty verificationMethod (CERT-02). [today it is HTTP 500]
7. **Trigger a real qualifying pass** for a test student (GET /pass-status) → certificate auto-issued + NC notification (CERT-05/12).
8. **Ensure `python3-cryptography` in the container** (non-persistent; INBOX open item) before the verify gate.
9. **Gate 2 — `ADMIN_PASS=… SECOND_PASS=… scripts/test-api.sh`** → did.json + `kid == verificationMethod.id` GREEN (ADR follow-up #3); optionally `ALLOW_LIVE_ROTATE=1` for the destructive rotation-preserves check (CERT-04).
10. **`scripts/verify-issued-cert-gate.sh`** (CERT_JWT=… or ADMIN_COOKIE+ADMIN_TOKEN) → real issued credential independently verified + tampered copy rejected (ADR follow-up #2 on a REAL cert).
11. **155-06 human-verify walkthrough:** open the student Certificate UI → render, `window.print()`, scan QR → public verify URL, download OB3 JSON-LD, copy LinkedIn Add-to-Profile; DE+EN i18n (CERT-07/08/09/10/13).
12. **`requirements mark-complete`** CERT-01..13 (each as a live TRUE statement) — manual edit if gsd-tools still corrupts the v5.0.0 frontmatter.
13. **Write `155-06-SUMMARY.md` + `155-07-SUMMARY.md`**, hand-edit STATE.md + ROADMAP.md (5/7→7/7, Phase 155 Complete), then `/gsd:verify-work` → Phase 156/157 (parallel).
