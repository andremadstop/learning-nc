---
gsd_state_version: 1.0
milestone: v5.0.0
milestone_name: "v5.0.0 Certification-as-a-Service"
current_phase: 156
current_plan: 02
status: phase-156 in progress — 156-01 (backend) COMPLETE, ready for 156-02 (UI). Phase 157 runs parallel.
stopped_at: "156-01-PLAN COMPLETE (Compliance-Report backend). 3 commits: 5bf9578 (test RED) → e152b3c (mapper+gate+service GREEN) → ba45709 (controller+routes+test-api). Delivered: CertificateMapper::findByCourseId (time-free, filtered, revoked=false, newest-first), CourseService::assertInstructorOfCourse (additive PUBLIC reusable IDOR-safe per-course owner gate), CertificateReportService::getCourseReport (gate-first owner-scoped read + per-cert VC-JWT decode for frozen name+score + strict 5-field DTO with NO recipient-id), CertificateReportController (JSON certReport + injection-safe CSV exportCertReportCsv, both @NoAdminRequired, #[UserRateLimit(10,60)] on CSV, Forbidden→403/DoesNotExist→404, ONE shared method so table==CSV). 7 real-logic PHPUnit tests/23 assertions GREEN (no-leak + IDOR with expects(never()) proving gate-before-read + filter cutoff + malformed-JWT fallback) running the REAL CourseService ownership path; PHPStan L5 clean whole-app; both routes live (occ router:list); grep gate zero recipient-id/getUserId hits in service+controller; bash -n test-api.sh valid. Requirements: REPORT-04 (DSGVO no-email, load-bearing test) Complete; REPORT-01/02/03 backend-complete but DEFERRED to 156-02 (UI re-lists them; '154/155 deferral discipline'). DEFERRED Gate 2: live credentialed test-api.sh cert-report block (no ADMIN_PASS in env) — written+bash-n-valid, rides Andre's demo-course creds. Carry-forward unchanged: revocation must null active_idem_key (R2-2); reconcile info.xml 4.4.8 → v5.0.0 release bump at milestone close. NEXT: /gsd:plan-phase 156 wave 2 (156-02 UI) OR /gsd:plan-phase 157 (Public-Verify, parallel)."
last_updated: "2026-06-27T14:30:00.000Z"
last_activity: 2026-06-27 — Executed 156-01-PLAN (Compliance-Report backend). TDD: failing tests → mapper findByCourseId + CourseService owner gate + CertificateReportService + thin CertificateReportController (JSON+CSV) + 2 routes + test-api block. 7 tests/23 assertions green, PHPStan L5 clean, routes live. REPORT-04 Complete; REPORT-01/02/03 backend-done → 156-02 UI. STATE/ROADMAP/REQUIREMENTS hand-edited (gsd-tools corrupt v5.0.0 frontmatter).
progress:
  total_phases: 4
  completed_phases: 2
  total_plans: 2
  completed_plans: 1
  percent: 50
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-26)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v5.0.0 Certification-as-a-Service — Phase 156: Compliance-Report (backend done; UI next). Phase 157 parallel.

## Current Position

Phase: 156 of 157 (Compliance-Report) — IN PROGRESS. 156-01 (backend) COMPLETE; 156-02 (UI) next. Phase 157 (Public-Verify) runs parallel. (Phases 154 + 155 COMPLETE.)
Plan: 156-01 of 2 done (1/2). 156-01-SUMMARY.md written.
Status: 156-01 delivered the DSGVO-safe, owner-scoped compliance-report BACKEND — `CertificateMapper::findByCourseId` (time-free, filtered, revoked=false, newest-first), the reusable IDOR-safe `CourseService::assertInstructorOfCourse` gate, `CertificateReportService::getCourseReport` (gate-first read + per-cert VC-JWT decode for frozen name+score + strict 5-field DTO with NO recipient-id), and a thin `CertificateReportController` exposing a JSON table + an injection-safe CSV download (both call the ONE shared service method → table == CSV). 7 real-logic PHPUnit tests/23 assertions GREEN (no-leak + IDOR proving gate-before-read + filter cutoff + malformed-JWT fallback) on the REAL CourseService ownership path; PHPStan L5 clean whole-app; both routes live; grep gate zero recipient-id hits. REPORT-04 (DSGVO no-email) Complete; REPORT-01/02/03 backend-complete, deferred to 156-02 (UI). Live credentialed Gate 2 deferred (no ADMIN_PASS — rides demo-course creds).
Last activity: 2026-06-27 — Executed 156-01-PLAN. See Execution Notes (156-01) below.

Progress: [█████░░░░░] Phase 156 IN PROGRESS (1/2 plans; backend done, UI next). Milestone v5.0.0: 2/4 phases complete (154+155), 156 in progress, 157 parallel-ready.

## Synthetic Cert Smoke — 2026-06-27 (authorized by Andre, throwaway data LEFT IN PLACE)

End-to-end LIVE smoke on devcloud (relais) that CLOSES the three "deferred-to-human" 155-07 gates from the stopped_at note. A real certificate was minted through the genuine pass pipeline (NOT a hand-inserted row).

- **Throwaway data (NOT cleaned up):** NC user `zz-test-cert155` (display "ZZ Testkandidat", added to `learning-instructors` group), pool 160, question 16629, course 59 (cert_enabled, cert_pass_percent=1, certRequiredPoolIds=[160], validity 365d). Certificate id=1, **verification_id `eb97720c-59d1-4c65-ba49-229c47341047`**, key_id UI3V-D_j…, active_idem_key `zz-test-cert155:59`.
- **Pass mechanism (genuine evaluate()→issueIfPassed()):** seeded the two gate preconditions in the DB, then triggered the REAL evaluation via `GET /api/courses/59/pass-status` as the test user. Seeded: one `oc_learning_sessions` row (mode=exam, completed_at set, 1/1 correct → score 100 ≥ Gate-1 threshold 1) + one `oc_learning_leitner_items` row (box=5 → mastery 100% ≥ Gate-2 threshold). The cert was signed/persisted/notified by live code; only the inputs were seeded.
- **GREEN now (were deferred):** verify-issued-cert-gate.sh PASS on the REAL JWT (independent Python Ed25519, tampered copy rejected → ADR follow-up #2 closed). test-api.sh did.json + **kid == verificationMethod.id GREEN** (105 pass / 2 fail / 2 skip; the 2 fails are admin-only endpoints — test user is a non-admin instructor; rotation SKIP is opt-in destructive). Idempotency proven LIVE: 3 qualifying GETs → exactly 1 cert, same vid, passed_events=1 (R2-2).
- **Bug fixed (deviation Rule 1, uncommitted in working tree):** scripts/test-api.sh `jwt_header_kid()` line 862 — `local jwt="$1" h="${jwt%%.*}"` expanded `${jwt%%.*}` against the unset OUTER jwt before local assigned it, aborting under `set -u` ("jwt: unbound variable"). Only surfaced now (first real cert; prior runs SKIPped). Split into separate `local` declarations → kid block goes GREEN.
- **✅ RESOLVED (was: ⚠ OPEN FINDING on CERT-12):** the earlier observation that the `certificate_issued` notification's recipient read `user=oc_admin` (a Postgres role, not an NC user) was a **PostgreSQL reserved-word artifact, NOT a bug.** `user` is a reserved keyword: the original `SELECT user FROM oc_notifications` returned `CURRENT_USER` (the DB session role `oc_admin`), not the column. Re-checked read-only on live devcloud during the 2026-06-27 close-out: EVERY notification across EVERY app (firstrunwizard / learning / settings / spreed), spanning months, showed `oc_admin` — the tell. Querying the **properly-quoted `"user"` column** returns the real NC recipient ids (learning: raja, kingsdomn, benjamin, julian, andre); `occ user:info oc_admin` → "user not found". Deployed `IssuanceService::notify()` calls `setUser($userId)` with the correct student id; notification attribution is correct. 155-VERIFICATION.md's "delivered to the correct student (NOT oc_admin)" verdict stands. CERT-12 marked Complete.
- **Browser-view nuance:** the polished in-app "Zertifikat ansehen" modal (Certificate.vue) only mounts on the STUDENT summary tab (CourseTabTeilnehmer → CourseSummary). `is_instructor` is OWNERSHIP-based (`course.instructor_id===userId`), NOT group-based, so zz-test-cert155 (owner of course 59) sees the instructor class-summary tab and CANNOT reach that modal — removing the group would NOT flip it. Guaranteed browser-viewable AS the test user NOW: `GET /apps/learning/api/certificates/eb97720c-…/download` (JSON-LD EnvelopedVerifiableCredential) or `?format=jwt`. To screenshot the rendered Certificate.vue card, reassign course 59 to a second throwaway owner (demoting zz-test-cert155 to pure student) — NOT done unprompted.

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

### Execution Notes (155-07 LIVE) — live provisioning done, STOPPED before Andre's browser human-verify

- **Authorized live run** after the multi-AI review passed SHIP (eb4de42). Scope: apply the schema + provision the issuer on live devcloud, run all automatable post-apply gates, then STOP before the browser-only 155-06 human-verify (Andre's step). No SUMMARY, no requirements flip, no phase-close.
- **info.xml 4.4.7→4.4.8** — minimal patch to trigger `occ upgrade` (commit `77e1159`, only `app/appinfo/info.xml` staged). Explicitly NOT 5.0.0 (reserved for the real release: CHANGELOG + git tag, rule 7). CHANGELOG untouched, no tag. Reconcile this patch number at the v5.0.0 release.
- **Migration apply path** — `db:show-table`, `migrations:status`, `migrations:preview` are all unavailable/unusable on this NC33 in upgrade-required state (preview needs unreachable NC metadata → 404). Verified the pending set directly via psql against `oc_migrations` (009000 applied, 009100 absent; both cert tables absent pre-upgrade). DB: dbname=nextcloud, dbuser=oc_admin, container devcloud-db, prefix oc_.
- **`occ upgrade`** turned maintenance ON, updated DB schema (Version009100), set learning→4.4.8, turned maintenance OFF. Post-checks: `maintenance:mode` disabled, base URL HTTP 302 (login redirect = up). **Side-effect:** the same `occ upgrade` pulled a pending `spreed` (Talk) app update from the App Store ("Update successful") — normal NC whole-instance upgrade behavior, non-destructive, logged for transparency.
- **Schema verified live (psql)** — `oc_learning_cert_keys` (id, key_id, public_key_b64u, secret_key_enc TEXT, status, created_at; UNIQUE learn_certkey_kid_uniq) + `oc_learning_certificates` (… active_idem_key; **UNIQUE `learn_cert_idem_uq` on active_idem_key** [the R2-2 atomic idempotency guard], UNIQUE learn_cert_vid_uniq, INDEX learn_cert_user_crs_idx).
- **CERT-01 live** — `occ learning:cert:init-issuer` → key_id `UI3V-D_j57IeIOlPBAW-2VQRu0dHB2lkZ0rDLj-LBU4`. Re-run with no args REFUSES (exit 1, "use --rotate"); exactly one `active` row in cert_keys.
- **CERT-02 live** — `curl …/apps/learning/did.json` → HTTP 200; verificationMethod.id = `did:web:devcloud.andrestiebitz.de:apps:learning#UI3V…`; publicKeyJwk = {kty:OKP, crv:Ed25519, x:UI3V…} ONLY; affirmative no-secret check PASS (no `d`, no `secret`, no `secret_key_enc` anywhere in body); `x` == kid fragment == key_id (single source).
- **CERT-03 live** — LeakageAuditTest 3 tests / 39 assertions GREEN in-container (tests rsync'd + docker cp'd; --php-only doesn't sync tests); grep gate: secret_key_enc absent from DataExportService/DataMobilityService/CourseArchiveService.
- **Cosmetic discrepancy (noted, harmless):** InitIssuerCommand CLI printed `did:web:localhost:apps:learning` because in CLI `IURLGenerator::getBaseUrl()` resolved host to localhost (despite overwrite.cli.url=devcloud). The SERVED did.json (HTTP) and HTTP-context issuance (GET /pass-status) both correctly resolve `devcloud.andrestiebitz.de`. `hostDid()` derives host purely from getBaseUrl() at call time — **nothing host-specific is persisted** (cert_keys stores no host), so issued certs (always minted in HTTP context) carry the correct kid that matches did.json. Follow-up: make the command's printed kid match the served host (display-only).
- **DEFERRED-TO-HUMAN (ride on Andre's 155-06 pass):** (a) Gate 2 `scripts/test-api.sh` authenticated suite — no admin password accessible (vault `DevCloud-Zugangsdaten.md` is a dangling wikilink, absent on workstation+cockpit; no ADMIN_PASS in env). Chromium login mechanism probed OK → `ADMIN_PASS=… TRANSPORT=local scripts/test-api.sh` runs green once Andre supplies the password (bruteforce-reset 172.21.0.1 first). The did.json id-format portion of ADR follow-up #3 is already GREEN unauthenticated (asserted directly). (b) Real-cert independent verify `scripts/verify-issued-cert-gate.sh` — needs a real issued JWT (Andre's pass produces one); verifier MECHANISM already GREEN (synthetic VC-JWT, valid→0/tampered→1); python3-cryptography 43.0.0 present in-container. (c) Rotation-preserves live (`ALLOW_LIVE_ROTATE=1`) — destructive `occ --rotate`, NOT run.

### Execution Notes (156-01) — Compliance-Report backend COMPLETE

- **Plan executed exactly as written — ZERO deviations (no Rule 1-4).** All `<interfaces>` matched the codebase. TDD RED confirmed first (findByCourseId/CertificateReportService not-found), then GREEN on the first implementation attempt.
- **The owner gate is the REAL CourseService in tests** — `CertificateReportServiceTest` constructs a genuine `CourseService` (12 mocked deps, copied from `CourseServiceTest`'s ctor wiring) so `assertInstructorOfCourse` runs for real; the IDOR test adds `$certMapper->expects($this->never())->method('findByCourseId')` to PROVE the gate runs BEFORE any read (a bare exception assertion wouldn't). Never stubbed the gate to a boolean.
- **Test JWTs need no signing** — the report DECODES `parts[1]`, never verifies the signature (trusted internal read of already-issued certs), so fixtures are `'header.' . base64url(json) . '.sig'` (no sodium). Mirrors the issuer's `base64_decode(strtr(...,'-_','+/'), true)` strict decode.
- **csvLine + looksLikeEmail COPIED, not injected** — both are `private` in DataMobilityService / IssuanceService (unreachable by DI). Copied verbatim as private methods; no public-surface widening on either origin class. Email regex `/[^@\s]+@[^@\s]+\.[^@\s]+/` with `trim()` (defence-in-depth re-screen of the already-screened frozen name).
- **Mapper is time-free; service owns the clock** — `findByCourseId` takes an ABSOLUTE `expiresBefore` cutoff; the `expiringDays → now + days*86400` arithmetic lives in the service (ITimeFactory), so the filter test drives a fake clock through the service and asserts the mapper is called with the computed cutoff. `expires_at IS NOT NULL AND <= cutoff` (already-expired included, never-expiring excluded).
- **grep gate (REPORT-04) is self-trippable** — keep the instructor param `$userId` (camelCase, won't match `user_id`) and NEVER write the literal recipient-id token in a comment in the service/controller files, or the final `grep "user_id\|getUserId"` gate trips on your own prose. (The mapper file legitimately uses the `course_id` column string — the gate only scans service+controller.)
- **`@NoAdminRequired` on BOTH controller methods is load-bearing AND unit-uncatchable** — unit tests instantiate the controller directly and bypass the annotation middleware, so they're green even if it's missing; a non-admin instructor would get 403 in prod without it. Verified by inspection + the deferred credentialed Gate 2.
- **Requirements discipline:** REPORT-04 (pure backend DSGVO guarantee, load-bearing no-leak test) flipped Complete; REPORT-01/02/03 ("instructor can view/filter/export") are backend-complete but DEFERRED to 156-02 (the UI plan re-lists them) — consistent with 154 (marked at UI 154-05) and 155 (marked at live verify). `requirements mark-complete` NOT run (gsd-tools corrupts v5.0.0 frontmatter — REQUIREMENTS hand-edited).
- **DEFERRED Gate 2** — live credentialed `scripts/test-api.sh` cert-report block (instructor 200 / non-owner 403 / text/csv / no-@ / no-recipient-id) is written + `bash -n` valid; the authenticated section only runs with `ADMIN_PASS` (script exits earlier without it), so it rides Andre's demo-course creds (bruteforce-reset 172.21.0.1 first). Same deferral as 154/155.

## Session Continuity

Last session: 2026-06-27T14:30:00.000Z
Stopped at: 156-01-PLAN COMPLETE (Compliance-Report backend). 156-01-SUMMARY.md written; REQUIREMENTS.md (REPORT-04 Complete; REPORT-01/02/03 backend-complete → 156-02 UI), ROADMAP.md (156: 1/2, In Progress), and STATE.md hand-edited (gsd-tools corrupt v5.0.0 frontmatter). 3 task commits: 5bf9578 (test RED) → e152b3c (mapper+gate+service) → ba45709 (controller+routes+test-api). 7 tests/23 assertions green, PHPStan L5 clean, both routes live, grep gate clean. Live credentialed Gate 2 deferred (no ADMIN_PASS — rides demo-course creds).

Resume: /gsd:plan-phase 156 wave 2 → 156-02 (instructor compliance UI in CourseTabTeilnehmer.vue: filter inputs + table from the clean DTO endpoint + Export CSV button + pure util + Vitest + i18n 5 langs) — OR /gsd:plan-phase 157 (Public-Verify) which runs PARALLEL. Endpoints ready for the UI: GET /api/courses/{courseId}/cert-report (JSON {rows:[…5 fields]}) + /cert-report/export/csv. DI construction confirmed live (credential-free smoke: both routes → HTTP 401 unauthenticated, NO 500 → controller autowires cleanly). **GUARDS for 156-02:** (1) its frontmatter MUST carry REPORT-01/02/03 or they never flip (ROADMAP line 492 already lists them — don't lose it). (2) REPORT-04 (no-leak) stays true ONLY if 156-02 consumes the clean `/cert-report` DTO endpoint — NOT raw `/api/certificates` (which emits credential_json + recipient-id). Carry: revocation path MUST set active_idem_key=NULL (R2-2); reconcile info.xml 4.4.8 → v5.0.0 release bump (CHANGELOG + tag) at milestone close.
Resume file: .planning/ROADMAP.md (Phase 156 wave 2 = 156-02; Phase 157 plans TBD)

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
