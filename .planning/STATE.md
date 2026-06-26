---
gsd_state_version: 1.0
milestone: v5.0.0
milestone_name: "v5.0.0 Certification-as-a-Service"
current_phase: 155
current_plan: 04
status: in-progress
stopped_at: "Completed 155-04-PLAN.md (IssuanceService): first-pass → build self-contained OB3/VC (course/score/threshold/dates/issuer+branding/vid frozen at signing) → sign via SigningService (active key, sodium_memzero secret) → persist VC-JWT → ONE deduped NC notification. OWN non-atomic idempotency guard (findByUserAndCourse + not-revoked); NO unique constraint (revoke + re-issue stays possible), deduped on read. Hooked into PassCriteriaService::evaluate() as a SWALLOWED side-effect (try/catch Throwable + log) so it can never 500 the live GET /pass-status. Issuer branding via OCP\\Defaults (IThemingDefaults absent from this NC's OCP package). Notifier 'certificate_issued' + 'Certificate issued: %s' in 5 langs (parity green). Full suite 90/90 (332 assertions); PHPStan L5 clean. CERT-05/06/11/12 Pending (live issuance = 155-07). Next: 155-05 (certificate view)."
last_updated: "2026-06-27T00:00:00.000Z"
last_activity: 2026-06-27 — Plan 155-04 complete: IssuanceService.issueIfPassed() builds a self-contained OB3/VC 2.0 credential (CERT-06: every field frozen into the signed payload), signs it via the 155-03 SigningService with KeyService::getActiveSigningMaterial() (sodium_memzero after), persists the compact VC-JWT, fires one deduped 'certificate_issued' notification (CERT-12), and brands the issuer from OCP\\Defaults name+logo (CERT-11). Hooked into PassCriteriaService::evaluate() (CERT-05) as a swallowed side-effect — sole caller is the live GET /pass-status, and migration+issuer-key are un-provisioned, so try/catch(Throwable)+log keeps the read path from 500-ing. OWN non-atomic idempotency guard (findByUserAndCourse + not-revoked; no unique constraint — revoke+re-issue stays possible; deduped on read). TDD: IssuanceServiceTest 6/6 (36 assertions, real SigningService decodes the actual JWT); + 4 PassCriteriaService hook regressions; full suite 90/90; PHPStan L5 clean. CERT-05/06/11/12 left Pending (code+unit done; live issuance/verify = 155-07).
progress:
  total_phases: 4
  completed_phases: 1
  total_plans: 7
  completed_plans: 4
  percent: 57
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-26)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v5.0.0 Certification-as-a-Service — Phase 155: Certificate-Artifact & Issuer

## Current Position

Phase: 155 of 157 (Certificate-Artifact & Issuer) — executing
Plan: 155-04 complete (4/7) — next is 155-05 (certificate view)
Status: 155-04 issuance shipped — IssuanceService auto-issues a self-contained OB3/VC on first pass (sign via 155-03 SigningService, persist VC-JWT, one deduped notification, issuer branding from OCP\Defaults). Hooked into PassCriteriaService::evaluate() as a swallowed side-effect so it cannot 500 the live GET /pass-status. OWN non-atomic idempotency guard; CERT-05/06/11/12 unit-proven, live issuance = 155-07.
Last activity: 2026-06-27 — Plan 155-04 complete: IssuanceService.issueIfPassed() (build self-contained OB3 → sign → persist → notify) + PassCriteriaService::evaluate() swallowed-side-effect hook + Notifier certificate_issued + i18n (5 langs). TDD 6/6 (36 assertions, real SigningService decodes the JWT) + 4 hook regressions; full suite 90/90; PHPStan L5 clean. CERT-05/06/11/12 Pending (live issuance/verify = 155-07).

Progress: [██████░░░░] 57% (4/7 plans in Phase 155)

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

## Session Continuity

Last session: 2026-06-27T00:00:00.000Z
Stopped at: Completed 155-04-PLAN.md — Phase 155 issuance (IssuanceService auto-issues self-contained OB3 on pass + student notification) shipped (4/7)
Resume file: None
