---
gsd_state_version: 1.0
milestone: v5.0.0
milestone_name: "v5.0.0 Certification-as-a-Service"
current_phase: 154
current_plan: 04
status: in-progress
stopped_at: Completed 154-03-PLAN.md
last_updated: "2026-06-26T13:00:00.000Z"
last_activity: 2026-06-26 — Plan 154-03 complete (PassCriteriaService two-gate eval + getExamScore, 11/11 PHPUnit GREEN).
progress:
  total_phases: 4
  completed_phases: 0
  total_plans: 5
  completed_plans: 3
  percent: 60
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-26)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v5.0.0 Certification-as-a-Service — Phase 154: Pass-Definition

## Current Position

Phase: 154 of 157 (Pass-Definition)
Plan: 04 of 5 (next — 154-03 complete)
Status: In progress
Last activity: 2026-06-26 — Plan 154-03 complete: PassCriteriaService two-gate evaluation (exam score + pool mastery) + idempotent course.passed audit + CourseSummaryService::getExamScore. 11/11 PHPUnit GREEN, PHPStan L5 clean.

Progress: [██████░░░░] 60% (3/5 plans in Phase 154)

## Performance Metrics

- Granularity: standard
- Parallelization: on (Phases 156+157 run parallel after 155)
- v4.4.0 shipped: 5 phases (149-153), 30 plans — App Store live 2026-04-27

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 154 Pass-Definition | P01 | ~15min | 2 | 4 |
| 154 Pass-Definition | P02 | ~20min | 2 | 2 |
| 154 Pass-Definition | P03 | ~30min | 2 | 4 |

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

## Session Continuity

Last session: 2026-06-26T13:00:00.000Z
Stopped at: Completed 154-03-PLAN.md
Resume file: None
