---
phase: 156-compliance-report
plan: 01
subsystem: api
tags: [certificates, dsgvo, idor, csv-export, vc-jwt, compliance-report, qbmapper]

# Dependency graph
requires:
  - phase: 155-cert-artifact
    provides: "learning_certificates table, signed compact VC-JWT in credential_json (credentialSubject.name frozen + email-screened, result[0].resultDescription score), CertificateMapper, IssuanceService FALLBACK_RECIPIENT/looksLikeEmail pattern, did:web issuer"
provides:
  - "CertificateMapper::findByCourseId — time-free, filtered (issued_at window + absolute expiry cutoff), revoked=false, newest-first"
  - "CourseService::assertInstructorOfCourse — reusable IDOR-safe per-course owner gate (DoesNotExist→404 / Forbidden→403)"
  - "CertificateReportService::getCourseReport — gate-first owner-scoped read + per-cert JWT decode + strict 5-field DTO (no recipient-id, ever)"
  - "CertificateReportController — JSON table + injection-safe CSV download, both calling the ONE shared service method (table == CSV)"
  - "test-api.sh cert-report block — instructor 200 / non-owner 403 / text/csv / HARD no-@/no-recipient-id gate"
affects: [157-public-verify, compliance-report-ui, v5.0.0-release]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "At-risk export recombination: thin controller + one shared service method feeds both JSON and CSV surfaces (table == CSV filtered set)"
    - "Gate-before-read: ownership assertion runs before any certificate query (IDOR-safe, proven by expects(never()) on the mapper)"
    - "Service owns the clock, mapper is time-free: day-count→absolute-cutoff arithmetic in the service so the mapper stays deterministic + unit-testable"
    - "Defence-in-depth re-screen: looksLikeEmail re-applied at report time even though the frozen name was screened at issuance"

key-files:
  created:
    - app/lib/Service/CertificateReportService.php
    - app/lib/Controller/CertificateReportController.php
    - app/tests/Unit/Service/CertificateReportServiceTest.php
  modified:
    - app/lib/Db/CertificateMapper.php
    - app/lib/Service/CourseService.php
    - app/appinfo/routes.php
    - scripts/test-api.sh

key-decisions:
  - "Separate thin CertificateReportController (not CourseController) — near-zero blast radius, no ctor change to the large CourseController"
  - "csvLine + looksLikeEmail COPIED as private methods (both private in their origin classes, unreachable by DI) — no signature changes to DataMobilityService/IssuanceService"
  - "Mapper is time-free; the service computes the absolute expiry cutoff (now + days*86400) so the filter is driven by a fake clock in unit tests"
  - "Live credentialed test-api.sh cert-report run is the DEFERRED Gate 2 (no ADMIN_PASS in env) — block is written, bash -n valid, runs green once creds supplied"

patterns-established:
  - "Pattern 1: strict 5-field DTO projection [display_name, passed_at, score, expires_at, verification_id] — recipient-id/email never reach an output column"
  - "Pattern 2: per-row try/guard around JWT decode so a malformed credential degrades to the neutral pseudonym without aborting the report"

requirements-completed: [REPORT-04]  # REPORT-01/02/03 backend-complete but deferred to 156-02 (UI) per the 154/155 deferral discipline — they are re-listed on 156-02 and read "instructor can view/filter/export", which needs the Vue tab. REPORT-04 (DSGVO no-email) is a pure backend guarantee, proven by the load-bearing no-leak test, so it flips now.

# Metrics
duration: ~40min
completed: 2026-06-27
---

# Phase 156 Plan 01: Compliance-Report Backend Summary

**Owner-scoped, DSGVO-safe certificate compliance report: one time-free mapper query, one service that decodes the stored VC-JWT for frozen name + score and projects to a strict 5-field DTO, and a thin controller exposing a JSON table and a byte-identical injection-safe CSV — recipient-id/email never reach any surface.**

## Performance

- **Duration:** ~40 min
- **Completed:** 2026-06-27
- **Tasks:** 2 (Task 1 TDD: RED → GREEN)
- **Files modified:** 7 (3 created, 4 modified)

## Accomplishments
- `CertificateReportService::getCourseReport` — gate-first (per-course owner check BEFORE any read), server-side filter (from/to on issued_at, expiringDays→absolute cutoff), per-cert VC-JWT payload decode (frozen name + score via `/score:([0-9.]+)/`), strict 5-field DTO. Email-shaped frozen name OR email-shaped account id → neutral `Teilnehmer:in`.
- `CertificateMapper::findByCourseId` — time-free QBMapper query: `course_id` + `revoked=false`, optional `issued_at` window, optional `expires_at IS NOT NULL AND <= cutoff` (already-expired included, never-expiring excluded), newest-first.
- `CourseService::assertInstructorOfCourse` — additive PUBLIC reusable IDOR-safe gate (loads course → DoesNotExist, gates via existing private `isInstructorOfCourse` → Forbidden, returns the Course).
- `CertificateReportController` — `certReport()` JSON + `exportCertReportCsv()` CSV, both `@NoAdminRequired`, `#[UserRateLimit(10,60)]` on CSV, Forbidden→403 / DoesNotExist→404; both call the SAME service method so the table and the CSV are the same filtered set. Injection-safe `csvLine`, DE-source headers, no recipient-id column.
- 7 real-logic unit tests (23 assertions) GREEN against the genuine `CourseService` ownership path (no boolean stub of the gate): no-leak, IDOR (with `expects(never())` on the mapper proving gate-before-read), co-instructor allowed, filter cutoff, null cutoff, empty score, malformed-JWT fallback.

## Task Commits

1. **Task 1 (TDD RED): failing CertificateReportService tests** - `5bf9578` (test)
2. **Task 1 (TDD GREEN): mapper + owner gate + service** - `e152b3c` (feat)
3. **Task 2: controller (JSON + CSV) + routes + test-api block** - `ba45709` (feat)

## Files Created/Modified
- `app/lib/Service/CertificateReportService.php` (created) - owner-scoped read, JWT decode, 5-field DTO, copied looksLikeEmail
- `app/lib/Controller/CertificateReportController.php` (created) - JSON + CSV endpoints, copied csvLine, toIntOrNull param helper
- `app/tests/Unit/Service/CertificateReportServiceTest.php` (created) - 7 real-logic tests
- `app/lib/Db/CertificateMapper.php` (modified) - added findByCourseId
- `app/lib/Service/CourseService.php` (modified) - added public assertInstructorOfCourse
- `app/appinfo/routes.php` (modified) - certificateReport#certReport + #exportCertReportCsv
- `scripts/test-api.sh` (modified) - cert-report block (instructor/non-owner/CSV/no-leak gates)

## Decisions Made
- **Thin separate controller** over extending CourseController — isolates cert concerns, no ctor change to the large CourseController (near-zero blast radius), as the plan front-loaded.
- **Copy (not inject) csvLine + looksLikeEmail** — both are `private` in their origin classes (DataMobilityService / IssuanceService) and unreachable via DI; copying avoids widening any existing public surface.
- **Mapper stays time-free** — the service (which owns ITimeFactory) computes the absolute expiry cutoff, so the filter test drives a fake clock through the service and the mapper has no clock dependency.

## Deviations from Plan

None - plan executed exactly as written. (No Rule 1-4 deviations were needed; all interfaces matched the codebase as specified in the plan's `<interfaces>` block.)

## Issues Encountered
None. The TDD RED run confirmed class/method-not-found first; GREEN passed on the first implementation attempt. PHPStan L5 clean on first deploy.

## Verification

- **PHPUnit `CertificateReportServiceTest`:** GREEN — 7 tests / 23 assertions (no-leak + IDOR + filter cutoff + null cutoff + co-instructor + empty score + malformed fallback). Regression: `CourseServiceTest` + `IssuanceServiceTest` 16 tests / 75 assertions GREEN (additive change safe).
- **PHPStan L5:** clean (whole app).
- **Routes:** both resolve via `occ router:list learning` (`learning.certificatereport.certreport`, `learning.certificatereport.exportcertreportcsv`).
- **grep gate (REPORT-04):** `grep "user_id\|getUserId"` on the service + controller → ZERO output-bound hits.
- **`bash -n scripts/test-api.sh`:** valid.
- **DI construction (credential-free live smoke):** both routes return HTTP **401** unauthenticated (`/cert-report` + `/cert-report/export/csv` on a dummy course id) — the controller is instantiated by the container BEFORE auth middleware, so 401-not-500 proves the autowiring (ctor param order, deps) is sound. This is the one failure mode that `router:list`/PHPStan/unit tests cannot catch.
- **DEFERRED to Gate 2 (no ADMIN_PASS in env, carried from 154/155):** the live credentialed cert-report block (instructor 200 / non-owner 403 / text/csv / no-@ in body). Runs green once Andre supplies the password (`bruteforce-reset 172.21.0.1` first). Same deferral discipline as Phase 155.

**Completion status (honest):** logic-proven (service via mocked mapper/clock) + statically clean (PHPStan L5) + DI-loadable (401 smoke). The mapper SQL itself (`revoked=false`, `IS NOT NULL` expiry guard, `issued_at DESC` ordering) and the end-to-end JSON/CSV bodies are exercised only at the deferred credentialed Gate 2 — no runtime row-level verification happened this plan.

## Requirements

All four are BACKEND-complete this plan. Following the 154/155 deferral discipline (a "user can do X" requirement flips only when the user-facing path exists), the three user-facing capabilities ride to **156-02 (UI)**, which re-lists them; only the pure backend DSGVO guarantee flips now.

- **REPORT-01** (backend-complete, → 156-02) instructor-only JSON endpoint returns {display_name, passed_at, score, expires_at, verification_id} per issued cert. "Instructor can VIEW" needs the Vue tab.
- **REPORT-02** (backend-complete, → 156-02) from/to + expiringDays applied server-side in the one shared method; table == CSV filtered set. "Instructor can FILTER" needs the filter inputs.
- **REPORT-03** (backend-complete, → 156-02) CSV download endpoint returns the same filtered rows, injection-safe, display-name only. "Instructor can EXPORT" needs the download button.
- **REPORT-04** ✅ **Complete** — no recipient-id key in any DTO, no recipient-id/email column in the CSV, email-shaped name → 'Teilnehmer:in' (proven by the load-bearing no-leak test, not by inspection). A pure backend guarantee — flips now.

## Next Phase Readiness
- Backend report surface is complete and unit-proven; a compliance-report UI (instructor tab) can consume `/api/courses/{courseId}/cert-report` + `/cert-report/export/csv` directly.
- Runs parallel with **Phase 157 (Public-Verify)** — independent surface, no shared files touched.
- Carry-forward (unchanged from 155): revocation path must set `active_idem_key=NULL` (R2-2); reconcile info.xml 4.4.8 → the v5.0.0 release bump at milestone close; live credentialed Gate 2 ride-along with Andre's demo-course pass.

## Self-Check: PASSED

All 4 created files exist on disk (CertificateReportService, CertificateReportController, CertificateReportServiceTest, this SUMMARY); all 3 task commits (`5bf9578`, `e152b3c`, `ba45709`) exist in git history.

---
*Phase: 156-compliance-report*
*Completed: 2026-06-27*
