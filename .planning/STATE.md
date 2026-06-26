---
gsd_state_version: 1.0
milestone: v5.0.0
milestone_name: "v5.0.0 Certification-as-a-Service"
current_phase: 155
current_plan: 01
status: in-progress
stopped_at: "Completed 155-01-PLAN.md (data layer + ADR anchor): Migration Version009100 (2 tables), CertKey/Certificate entities + mappers, leakage-safe jsonSerialize. PHPStan L5 clean, CertEntityTest 3/3 green on relay. Next: 155-02 (KeyService + DidController)."
last_updated: "2026-06-27T00:00:00.000Z"
last_activity: 2026-06-27 — Plan 155-01 complete: learning_cert_keys + learning_certificates tables (cross-DB-safe), QBMapper entities/mappers, CertKey::jsonSerialize omits secret_key_enc (CERT-03 primitive), 155-ADR-ANCHOR.md freezes VC-JOSE-COSE header. CERT-03/04/06 done. PHPStan L5 clean, PHPUnit 3/3 (15 assertions) on relay.
progress:
  total_phases: 4
  completed_phases: 1
  total_plans: 7
  completed_plans: 1
  percent: 14
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-26)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v5.0.0 Certification-as-a-Service — Phase 155: Certificate-Artifact & Issuer

## Current Position

Phase: 155 of 157 (Certificate-Artifact & Issuer) — executing
Plan: 155-01 complete (1/7) — next is 155-02 (KeyService + DidController)
Status: 155-01 data layer + ADR anchor shipped; entry-gate scope respected (no signing/keygen code yet)
Last activity: 2026-06-27 — Plan 155-01 complete: two cross-DB-safe tables (learning_cert_keys + learning_certificates), QBMapper entities/mappers, leakage-safe CertKey::jsonSerialize (CERT-03), 155-ADR-ANCHOR.md freezes the VC-JOSE-COSE signing header. PHPStan L5 clean; CertEntityTest 3/3 (15 assertions) green on relay.

Progress: [█░░░░░░░░░] 14% (1/7 plans in Phase 155)

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

## Session Continuity

Last session: 2026-06-27T00:00:00.000Z
Stopped at: Completed 155-01-PLAN.md — Phase 155 data layer + ADR anchor shipped (1/7)
Resume file: None
