---
phase: 154-pass-definition
plan: 02
subsystem: database
tags: [migration, qbmapper, postgresql, mariadb, course-entity, certification]

# Dependency graph
requires:
  - phase: 154-01
    provides: PassResult DTO + PassCriteriaService skeleton (consumes cert config in 154-03)
provides:
  - learning_courses cert columns (cert_enabled, cert_pass_percent, cert_required_pool_ids, cert_validity_days)
  - Course entity accessors (getCertEnabled/PassPercent/RequiredPoolIds/ValidityDays) for QBMapper
  - jsonSerialize() emits cert_* fields in course API responses
affects: [154-03, 154-04, 154-05, 155-cert-artifact]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Idempotent column-add migration: hasTable + hasColumn guards, return changed ? schema : null"
    - "OCP\\DB\\Types (NC-native) over Doctrine\\DBAL for cross-DB column types; TEXT not VARCHAR"

key-files:
  created:
    - app/lib/Migration/Version009000Date20260626000000.php
  modified:
    - app/lib/Db/Course.php

key-decisions:
  - "jsonSerialize emits snake_case cert keys (cert_enabled, ...) not camelCase as planned — matches existing consumer convention (frontend reads c.maintenance_mode / c.leitner_sprint)"
  - "Migration applied via occ upgrade (relay had needsDbUpgrade=true: app code 4.4.7 vs DB-registered 4.4.6); migrations:execute is unavailable on this NC 33 instance"
  - "PASS-01..04 left Pending — only DB foundation laid; instructor-facing capability requires controller (154-04) + UI (154-05)"

patterns-established:
  - "Cert config stored as typed columns on learning_courses (not JSON in mode_config) — locked Phase 154 decision"
  - "cert_required_pool_ids stored as raw TEXT (addType 'text'); JSON decode happens in PassCriteriaService, not the mapper"

requirements-completed: []  # PASS-01..04 assigned to plan but NOT user-completable yet (no controller/UI); foundation only

# Metrics
duration: ~20min
completed: 2026-06-26
---

# Phase 154 Plan 02: Course Cert Columns + Entity Summary

**Four typed certification columns added to learning_courses (cert_enabled BOOLEAN, cert_pass_percent SMALLINT, cert_required_pool_ids TEXT, cert_validity_days INTEGER) via idempotent OCP\DB\Types migration, with Course entity accessors and snake_case jsonSerialize output.**

## Performance

- **Duration:** ~20 min
- **Completed:** 2026-06-26T13:59:35+02:00
- **Tasks:** 2
- **Files modified:** 2 (1 created, 1 modified)

## Accomplishments
- Version009000 migration adds 4 cert config columns to `learning_courses`, idempotent via `hasColumn` guards
- Course entity exposes cert fields via QBMapper-compatible magic accessors (PHPStan L5 clean)
- Course API responses now carry cert config (snake_case keys, `cert_required_pool_ids` json_decoded to array)
- Verified live on PG16 (relay); all 4 columns present with correct types and defaults

## Task Commits

1. **Task 1: Version009000 migration — 4 cert columns** - `02bfb9b` (feat)
2. **Task 2: Course.php entity cert accessors + jsonSerialize** - `bea1a44` (feat)

## Files Created/Modified
- `app/lib/Migration/Version009000Date20260626000000.php` - Idempotent migration adding cert_enabled/pass_percent/required_pool_ids/validity_days to learning_courses
- `app/lib/Db/Course.php` - 4 @method pairs, 4 addType() declarations, 4 jsonSerialize entries for cert fields

## Verification Evidence

### Migration run (via `occ upgrade` on relay PG16)
```
Updating database schema
Updated database
Updating <learning> ...
Updated <learning> to 4.4.7
```
NC was in `needsDbUpgrade: true` because the deployed app code (info.xml 4.4.7) outranked the DB-registered installed_version (4.4.6). This gates the standalone `migrations:execute` command (which is also not available on this NC 33 instance — only `migrations:preview` exists). `occ upgrade` ran all pending migration steps including 009000.

### Idempotency
Migration is recorded in `oc_migrations`:
```
 009000Date20260626000000
```
NC tracks executed migrations and will not re-run a recorded version. Structural idempotency is additionally guaranteed by `hasColumn` guards + `return $changed ? $schema : null` — a forced second run applies no changes and returns null.

### Columns on PG16 (`\d`-equivalent from information_schema)
```
      column_name       | data_type | column_default | is_nullable
------------------------+-----------+----------------+-------------
 cert_enabled           | boolean   | false          | YES
 cert_pass_percent      | smallint  | 80             | YES
 cert_required_pool_ids | text      |                | YES
 cert_validity_days     | integer   | 0              | YES
(4 rows)
```
- PASS-01: `cert_enabled` BOOLEAN default false ✓
- PASS-02: `cert_pass_percent` SMALLINT default 80 ✓
- PASS-03: `cert_required_pool_ids` TEXT nullable (no default = null) ✓
- PASS-04: `cert_validity_days` INTEGER default 0 (0 = no expiry) ✓

### PHPStan Level 5 (Course.php direct + full project)
```
Note: Using configuration file /var/www/html/custom_apps/learning/phpstan.neon.
 [OK] No errors
```

### MariaDB cross-DB gate (code-review per feedback_mysql_testing.md)
Pure column-add migration, lowest-risk shape — code-reviewed, throwaway container not required:
- All types use NC-native `OCP\DB\Types::` constants (no Doctrine\DBAL coupling, no string literals)
- `Types::BOOLEAN` → BOOLEAN (PG) / TINYINT(1) (MariaDB) — NC handles natively
- `Types::SMALLINT` → SMALLINT both; `Types::INTEGER` → INT both
- `Types::TEXT` → TEXT both (no length limit; avoids VARCHAR(N) MariaDB pitfalls)
- No raw SQL DDL, no new indexes (no ≤27-char constraint-name risk on MariaDB)

### Scope confirmations
- `cert_validity_days` is STORED but NOT evaluated in Phase 154 — expiry logic is Phase 155 only
- Migration uses `use OCP\DB\Types` (verified: no `Doctrine` reference in file)

## Decisions Made
1. **snake_case JSON keys** — The plan specified camelCase jsonSerialize keys (`certEnabled`). Every existing key in `Course::jsonSerialize()` is snake_case and the Vue frontend reads course fields as snake_case (`c.maintenance_mode`, `c.leitner_sprint` in CourseTabVerwaltung.vue). Emitting camelCase would have been a mixed-casing consumer bug. The plan's `key_links` only lock the *entity property* name `certEnabled` (addType + getter), not the JSON output key — so snake_case is free and correct.
2. **occ upgrade instead of migrations:execute** — `migrations:execute` is not defined on this NC 33 instance and was gated by `needsDbUpgrade`. `occ upgrade` (standard, reversible NC dev operation on the relay devcloud-app staging instance) cleared the flag and ran the migration as part of the normal upgrade path.
3. **PASS-01..04 not marked Complete** — see Deviations.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] jsonSerialize cert keys emitted as snake_case, not camelCase**
- **Found during:** Task 2 (Course.php entity)
- **Issue:** Plan specified camelCase JSON keys; all existing course API keys are snake_case and the frontend reads them as snake_case. CamelCase cert keys would be unreadable by consumers following the established convention.
- **Fix:** Emitted `cert_enabled` / `cert_pass_percent` / `cert_required_pool_ids` / `cert_validity_days`. Entity property + getter names stay camelCase (`certEnabled`) per key_links and QBMapper convention.
- **Files modified:** app/lib/Db/Course.php
- **Verification:** grep confirms 4 snake_case keys; PHPStan L5 clean
- **Committed in:** bea1a44

**2. [Rule 3 - Blocking] NC stuck in needsDbUpgrade; migrations:execute unavailable**
- **Found during:** Task 1 (running the migration)
- **Issue:** `occ migrations:execute learning 009000...` returned "Command not defined" — instance was in `needsDbUpgrade: true` (app code 4.4.7 vs DB-registered 4.4.6), and this NC 33 instance only exposes `migrations:preview`.
- **Fix:** Ran `occ upgrade` on the relay dev instance — bumped learning 4.4.6→4.4.7 and applied all pending migrations including 009000.
- **Verification:** `needsDbUpgrade: false` after; 009000 recorded in oc_migrations; 4 columns present.
- **Committed in:** n/a (operational, no file change)

---

**Total deviations:** 2 (1 consumer-correctness bug fix, 1 blocking-environment fix)
**Impact on plan:** Both necessary for correctness/completion. No scope creep — same 2 files, same 4 columns.

### Requirements traceability note (NOT marked complete)
PASS-01..04 are listed in this plan's frontmatter, but each is worded as an instructor-facing *capability* ("Instructor can enable certification…"). This plan delivers only the DB + entity foundation; the capability is not user-reachable until the controller endpoints (154-04) and Vue UI (154-05) ship. Marking them Complete now would create a false traceability state, so REQUIREMENTS.md PASS-01..04 remain **Pending**. They should be marked complete when 154-05 lands.

## Issues Encountered
- psql access initially failed with `role "nextcloud" does not exist`; the real PG superuser on devcloud-db is `oc_admin` (from container env). This also explains the orientation `grep -c cert = 0` (it was the same role error, not absent columns). Resolved by using `$POSTGRES_USER`/`$POSTGRES_DB` from the container env.

## Next Phase Readiness
- 154-03 (service layer) can consume `getCertEnabled()` / `getCertPassPercent()` / `getCertRequiredPoolIds()` / `getCertValidityDays()` directly — PHPStan L5 verified.
- **Carry-forward for release:** info.xml is NOT in this plan's scope and was not bumped for the migration; on this dev instance the migration ran via `occ upgrade` (app already at 4.4.7 in info.xml). For other instances (e.g. the MySQL user) to receive these columns on app upgrade, the release plan must bump info.xml above the version that ships this migration. Logged for the v5.0.0 release plan.

## Self-Check: PASSED

- FOUND: app/lib/Migration/Version009000Date20260626000000.php
- FOUND: app/lib/Db/Course.php (modified)
- FOUND: .planning/phases/154-pass-definition/154-02-SUMMARY.md
- FOUND: commit 02bfb9b (Task 1)
- FOUND: commit bea1a44 (Task 2)

---
*Phase: 154-pass-definition*
*Completed: 2026-06-26*
