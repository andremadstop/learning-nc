---
phase: 164-rezertifizierung-retention-i18n
plan: 01
subsystem: database
tags: [migration, schema, php, nextcloud, recertification, retention, dsgvo]

# Dependency graph
requires:
  - phase: 164-RESEARCH
    provides: "Migration house rules, RECERT-02/06/DSGVO-03 schema design, index-name constraints"
  - phase: 163-teamleiter-rbac-reports
    provides: "Stable PHPUnitStubs OCP surface (TimedJob/IJobList/ITimeFactory/IConfig all present)"

provides:
  - "Version009600 migration: cert_validity_months on learning_courses"
  - "Version009600 migration: anonymized_at on learning_certificates"
  - "Version009600 migration: learning_recert_reminders table with UNIQUE(cert_id,threshold_days)"
  - "ConfigDefaults class with RETENTION_YEARS_DEFAULT/RECERT_GRACE_DAYS_DEFAULT/CERT_VALIDITY_MONTHS_DEFAULT/RECERT_REMINDER_THRESHOLDS"
  - "info.xml at 5.2.0.3 — migration travels with this bump"

affects:
  - 164-02-recert-guard-redesign
  - 164-03-period-close-job
  - 164-04-reminders
  - 164-05-retention-job
  - 164-06-cert-verify-status
  - 164-07-i18n-parity

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "hasTable→getTable→hasColumn guard pattern (mirror Version009500)"
    - "UNIQUE-slot idempotency via catch REASON_UNIQUE_CONSTRAINT_VIOLATION"
    - "ConfigDefaults constants class — string defaults for IConfig.getAppValue, int/array for code logic"

key-files:
  created:
    - app/lib/Migration/Version009600Date20260702000000.php
    - app/lib/AppInfo/ConfigDefaults.php
  modified:
    - app/appinfo/info.xml

key-decisions:
  - "RETENTION_YEARS_DEFAULT = '3' (string) — locked conservative vs research suggestion of 5; FLAGGED for AWO/DSGVO confirmation before production rollout (Art.17(3)(b) ArbSchG/AGG basis)"
  - "anonymized_at on learning_certificates — DSGVO-03 tombstone; scrubbing credential_json (crypto-erasure) is the accepted approach despite breaking signature verifiability"
  - "learning_recert_reminders UNIQUE(cert_id,threshold_days) — storm-proof idempotency over config-marker dedup (dismissal-proof, run-count-proof)"
  - "PhpUnitStubs: no changes — TimedJob/IJobList/ITimeFactory/IConfig all present from Phase 160/161"

patterns-established:
  - "ConfigDefaults: string constants for IConfig.getAppValue defaults; int/array for in-code logic — never normalize to one type"
  - "Migration $changed flag + return $changed ? $schema : null — exact Version009500 pattern"

requirements-completed: [RECERT-02, RECERT-03, RECERT-06, DSGVO-03]

# Metrics
duration: 15min
completed: 2026-07-02
---

# Phase 164 Plan 01: Schema + Config Foundation Summary

**Version009600 migration adds cert_validity_months + anonymized_at + learning_recert_reminders UNIQUE-idempotency table; ConfigDefaults class supplies 4 policy constants; info.xml bumped to 5.2.0.3**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-02T00:00:00Z
- **Completed:** 2026-07-02
- **Tasks:** 2
- **Files created/modified:** 3

## Accomplishments

- Migration Version009600 lays the three schema pieces all downstream waves depend on: per-course validity column, retention tombstone column, reminder-idempotency table
- ConfigDefaults class centralizes all four policy knobs in one place so services never hard-code magic values
- info.xml at 5.2.0.3 — orchestrator runs `occ upgrade` at wave merge, applying the migration cleanly

## Task Commits

1. **Task 1: Migration Version009600 + info.xml bump** — `396f2ae` (feat)
2. **Task 2: ConfigDefaults constants** — `9abd9fe` (feat)

## Files Created/Modified

- `app/lib/Migration/Version009600Date20260702000000.php` — Version009600 migration: cert_validity_months (learning_courses), anonymized_at (learning_certificates), learning_recert_reminders table with UNIQUE + plain index
- `app/lib/AppInfo/ConfigDefaults.php` — RETENTION_YEARS_DEFAULT/RECERT_GRACE_DAYS_DEFAULT/CERT_VALIDITY_MONTHS_DEFAULT/RECERT_REMINDER_THRESHOLDS
- `app/appinfo/info.xml` — version 5.2.0.2 → 5.2.0.3

## Decisions Made

- **retention_years default = 3 (not 5).** Research suggested 5 (Art.17(3)(b) Nachweispflicht). Locked at 3 conservatively. **FLAGGED** — confirm exact value with AWO/DSGVO before production rollout of RetentionJob.
- **PhpUnitStubs: no changes.** All Wave 2 OCP surface already present: `TimedJob` (Phase 161, L444), `IJobList` (Phase 160, L426), `ITimeFactory` (Phase 160, L292), `IConfig` with `getAppValue`/`setAppValue` (Phase 160, L184). Documented as intentional no-op.

## Deviations from Plan

None — plan executed exactly as written. PhpUnitStubs check was a confirmed no-op, documented explicitly.

## Issues Encountered

None. Table name `learning_certificates` confirmed from CertificateMapper and migration grep before writing (insurance against silent hasColumn no-op on wrong name).

## Deferred Verifications (central gate — NOT run here)

Per PROJECT_SPECIFIC_OVERRIDES (no local PHP binary):

- **PHPStan L5** — orchestrator runs at wave merge
- **PHPUnit** — orchestrator runs inside devcloud container
- **`occ upgrade`** — orchestrator applies Version009600 after wave merge (info.xml 5.2.0.3 is the trigger)
- **Gate 2 (test-api.sh)** — after deploy

## Next Phase Readiness

Wave 1 foundation is complete. Downstream waves can reference:
- `learning_recert_reminders` table + `learn_rcrt_rem_uq` idempotency key (RECERT-06 ReminderService)
- `learning_courses.cert_validity_months` (RECERT-01/02 IssuanceService DST-safe date math)
- `learning_certificates.anonymized_at` (DSGVO-03 RetentionJob + RECERT-01 status computation)
- `ConfigDefaults::*` constants (all services reading policy via IConfig)
- PhpUnitStubs already complete for Wave 2 RED tests

Wave 2 (164-02: RECERT-05 guard redesign) requires mandatory Codex security review before implementation — see 164-RESEARCH.md §RECERT-05.

---
*Phase: 164-rezertifizierung-retention-i18n*
*Completed: 2026-07-02*
