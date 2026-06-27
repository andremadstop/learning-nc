---
phase: 157-public-verify
plan: 01
subsystem: database
tags: [migration, certificate, revocation, cross-db, ed25519, did-web]

# Dependency graph
requires:
  - phase: 155-cert-artifact
    provides: "learning_certificates table (Version009100, live on PG16) + Certificate entity"
provides:
  - "Version009200 migration: nullable BIGINT revoked_at on learning_certificates (written, dormant, applyable)"
  - "Certificate entity revokedAt field (int|null) — getRevokedAt()/setRevokedAt()"
  - "cross-DB check extended to assert revoked_at (MariaDB 11.4 GO; PG16 documented no-op)"
affects: [157-02, 157-04, 157-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "ALTER-existing-table migration (getTable + addColumn) vs Version009100's createTable"
    - "Dormant migration: ship code, NOT info.xml bump — apply travels with occ upgrade later"

key-files:
  created:
    - app/lib/Migration/Version009200Date20260627120000.php
  modified:
    - app/lib/Db/Certificate.php
    - scripts/cross-db-migration-check.sh

key-decisions:
  - "revoked_at is a separate ALTER migration (Version009200), not a Version009100 edit — Version009100 is already applied live on PG16 with no revoked_at (SPEC ⊥ CODE, RESEARCH Pitfall 1)"
  - "info.xml stays 4.4.8: a bump would make devcloud needsDbUpgrade, and --php-only deploys info.xml without occ upgrade → live maintenance page. Bump + apply deferred to 157-05 authorized provisioning pass"
  - "revoked_at deliberately NOT added to jsonSerialize() — public DTO is projected server-side in 157-02, never via the owner serializer (no leak path)"

patterns-established:
  - "ALTER migration: $schema->getTable() + hasColumn guard + addColumn, return $changed ? $schema : null"
  - "Cross-DB column assertion via information_schema.columns (data_type=bigint, is_nullable=YES)"

requirements-completed: []  # VERIFY-05 is foundation-only this plan; flips at 157 close after live verify (155-style deferral)

# Metrics
duration: 21min
completed: 2026-06-27
---

# Phase 157 Plan 01: revoked_at Tombstone Column Summary

**Nullable BIGINT `revoked_at` on learning_certificates via a dormant ALTER migration (Version009200) + Certificate entity field + cross-DB MariaDB 11.4 parity assertion — the foundation every 157 tombstone/revoke plan reads or writes.**

## Performance

- **Duration:** 21 min
- **Started:** 2026-06-27T17:05:01Z
- **Completed:** 2026-06-27T17:26:06Z
- **Tasks:** 2
- **Files modified:** 3 (1 created, 2 modified)

## Accomplishments
- `Version009200Date20260627120000` — a `SimpleMigrationStep` that ALTERs the existing `learning_certificates` table (`getTable` + `hasColumn` guard + `addColumn('revoked_at', Types::BIGINT, ['notnull' => false])`), touching no other column. Written but NOT applied live; info.xml stays 4.4.8 so no in-phase deploy can push a `needsDbUpgrade` state to live devcloud.
- `Certificate` entity gains `revokedAt` (int|null): `protected $revokedAt`, `addType('revokedAt','integer')`, and `@method int|null getRevokedAt()` / `@method void setRevokedAt(?int $revokedAt)`. `jsonSerialize()` left byte-identical — no public leak path.
- `scripts/cross-db-migration-check.sh` mirrors `revoked_at BIGINT NULL` into the ephemeral MariaDB 11.4 DDL and adds an `information_schema.columns` assertion (type=bigint, nullable=YES). Exits 0 (GO). PG16 stays a documented no-op (live `occ db:show-table` post-upgrade).

## Task Commits

Each task was committed atomically:

1. **Task 1: revoked_at migration + Certificate entity field** - `16404e0` (feat)
2. **Task 2: extend cross-DB migration check with revoked_at** - `3196785` (test)

## Files Created/Modified
- `app/lib/Migration/Version009200Date20260627120000.php` - Dormant ALTER migration adding nullable `revoked_at`
- `app/lib/Db/Certificate.php` - `revokedAt` field + addType + @method block (jsonSerialize untouched)
- `scripts/cross-db-migration-check.sh` - revoked_at mirrored in DDL + asserted via information_schema

## Decisions Made
- **Separate ALTER migration, not a Version009100 edit** — Version009100 is already applied live on PG16 (155-07 LIVE) with no `revoked_at`; editing an applied migration would never re-run. A new Version009200 with `getTable()` (not `createTable()`) is the correct apply path.
- **info.xml NOT bumped (PROD-SAFETY)** — a bump → `needsDbUpgrade`; `deploy-prod.sh --php-only` rsyncs info.xml but does NOT run `occ upgrade`, which would show the maintenance/upgrade page to live users and break 157-05's logged-out e2e. Bump + `occ upgrade` deferred to the 157-05 authorized provisioning pass (155-01 → 155-07 pattern).
- **revoked_at kept out of jsonSerialize()** — the column is read only in the public verify WITHDRAWN branch via a server-side DTO (157-02); the owner serializer already emits user_id + credential_json, so adding revoked_at there would be a needless leakage surface.

## Deviations from Plan

None - plan executed exactly as written. No Rule 1-4 deviations.

## Issues Encountered
- The plan's Task-1 `php -l` verify (and the mandatory PHPStan L5 gate) run **in-container**, but the new files were local-only. Resolved by deploying via `./scripts/deploy-prod.sh --php-only` FIRST (the 155-07-verified-safe path: with info.xml unchanged it is rsync + docker cp + apache graceful + PHPStan, NO `occ upgrade` — Version009200 stays dormant). Both files then `php -l` clean in-container; PHPStan L5 reported "No errors". (The deploy's `OCP\AppFramework\App not found` line is the documented harmless standalone-CLI smoke artifact, not a syntax error.)

## Verification (Gate 1 + plan criteria)
- `php -l` clean in-container on both new/edited PHP files.
- PHPStan L5: **No errors**.
- `bash scripts/cross-db-migration-check.sh` → exit 0 (GO): 2 tables + 4 indexes + `revoked_at` (bigint, nullable), no "key too long", container torn down.
- info.xml STILL 4.4.8 (grep count 1); CHANGELOG untouched, no git tag.
- `jsonSerialize()` byte-identical to before (git diff confirms only the property + addType + @method block changed).

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- `revoked_at` is a real, typed, cross-DB-safe field ready for 157-02 (public verify WITHDRAWN tombstone branch reads it) and 157-04 (instructor-revoke write sets it + `active_idem_key=NULL`, idempotent — keep FIRST revocation time).
- **DEFERRED to 157-05 authorized provisioning pass:** info.xml bump (past 4.4.8) + `occ upgrade` to apply Version009200 live on PG16, then `occ db:show-table learning_certificates` to confirm `revoked_at` (the cross-DB script's deferred PG assertion). Reconcile the patch number at the v5.0.0 release (CHANGELOG + tag).
- The un-applied migration does NOT break the VALID-path e2e: `revoked_at` is read only in the WITHDRAWN branch.

## Self-Check: PASSED

- FOUND: app/lib/Migration/Version009200Date20260627120000.php
- FOUND: app/lib/Db/Certificate.php (revokedAt field + addType + @method)
- FOUND: scripts/cross-db-migration-check.sh (revoked_at asserted)
- FOUND: .planning/phases/157-public-verify/157-01-SUMMARY.md
- FOUND commit: 16404e0 (Task 1)
- FOUND commit: 3196785 (Task 2)

---
*Phase: 157-public-verify*
*Completed: 2026-06-27*
