---
phase: 156-compliance-report
plan: 02
subsystem: ui
tags: [certificates, dsgvo, compliance-report, vue3, options-api, i18n, csv-export, vitest]

# Dependency graph
requires:
  - phase: 156-compliance-report
    plan: 01
    provides: "GET /api/courses/{courseId}/cert-report (JSON {rows:[display_name,passed_at,score,expires_at,verification_id]}) + /cert-report/export/csv — owner-scoped, recipient-id-free DTO"
provides:
  - "cert-report.js pure util: buildCertReportQuery (stable-order, empty-omitting), shouldShowCertReport (instructor+cert_enabled gate), formatScore/formatDate"
  - "CourseTabTeilnehmer.vue instructor compliance section (Abschluss subtab): filterable table + Export CSV, consuming the clean DTO endpoint (no user_id client-side)"
  - "12 new i18n keys across DE/EN/FR/RU/AR (real translations)"
affects: [v5.0.0-release]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "One serializer for two surfaces: buildCertReportQuery() builds BOTH the table-fetch URL and the CSV-download URL → identical filter params by construction (table == CSV)"
    - "Branchable logic in a pure util tested by Vitest; the Vue section is thin glue (155-06 lesson — no @vue/test-utils in this codebase)"
    - "Gate-by-util: shouldShowCertReport(isInstructor, course.cert_enabled) is the single source for section visibility"

key-files:
  created:
    - app/src/utils/cert-report.js
    - app/tests/unit/CertReport.test.js
  modified:
    - app/src/components/CourseTabTeilnehmer.vue
    - app/l10n/de.js
    - app/l10n/de.json
    - app/l10n/en.js
    - app/l10n/en.json
    - app/l10n/fr.js
    - app/l10n/fr.json
    - app/l10n/ru.js
    - app/l10n/ru.json
    - app/l10n/ar.js
    - app/l10n/ar.json
    - app/css/learning.css
    - app/js/learning.css
    - app/js/learning.js

key-decisions:
  - "Section lives in the instructor 'summary' (Abschluss) subtab, replacing the Phase-107 placeholder note — the natural home for an instructor course-completion view. Non-cert courses keep the placeholder."
  - "Plain HTML <input type=date>/<input type=number> (not NcDateTimePicker) — testable, dependency-free; converted to unix seconds in the component (from=start-of-day UTC, to=end-of-day UTC so the upper bound is inclusive)."
  - "buildCertReportQuery() drives BOTH the table fetch URL and the CSV URL (not axios params for one + the util for the other) so the must-have 'same filter params' is structural, not coincidental."

requirements-completed: [REPORT-01, REPORT-02, REPORT-03]

# Metrics
duration: ~35min
completed: 2026-06-27
---

# Phase 156 Plan 02: Compliance-Report UI Summary

**Instructor-only, DSGVO-safe compliance section in the course Teilnehmer/Abschluss tab: a filterable certificate table (passed-date range + expiry window) bound to the clean 156-01 DTO endpoint, plus an Export CSV button — with all branchable logic in a Vitest-proven pure util and one shared query-string serializer guaranteeing the table and the CSV carry identical filters.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-06-27
- **Tasks:** 2 (Task 1 TDD: RED → GREEN; Task 2 component + i18n + deploy)
- **Files modified:** 16 (2 created, 14 modified)

## Accomplishments
- `app/src/utils/cert-report.js` — `buildCertReportQuery({from,to,expiringDays})` omits null/undefined/'' (keeps 0) in a stable `from,to,expiringDays` order; `shouldShowCertReport(isInstructor, course)` is true ONLY for `isInstructor===true && course.cert_enabled` (snake_case); `formatScore` (em-dash on the backend's empty `''`), `formatDate` (UTC `YYYY-MM-DD`, `''` on falsy). 15 Vitest cases.
- `CourseTabTeilnehmer.vue` — instructor compliance section in the `summary` (Abschluss) subtab, gated by `showCertReport` computed. Filter inputs (from/to date + expiringDays number) → `certFilters()` converts to unix seconds (to = end-of-day, inclusive). `fetchCertReport()` and `exportCertReportCsv()` both build their URL via `buildCertReportQuery(this.certFilters())`. Table columns Name / Bestanden am / Score (%) / Gültig bis ("unbegrenzt" when null) / Verifizierungs-ID; rows rendered straight from `response.data.rows` (NO user_id). Empty-state + issued-only data-boundary note. Fetch-on-reveal via `lazyLoad('summary')`.
- i18n — 12 new keys (4 already existed: Name / Export CSV / Bestanden am / Gültig bis) added to all 5 langs in `.json` (DE source value==key; real EN/FR/RU/AR), `.js` regenerated via `l10n_js_sync.py`; key-parity + .js↔.json value-sync both green.
- Built + deployed frontend via `deploy-prod.sh --js-only` (dist bundles committed, per 155-06 precedent).

## Task Commits

1. **Task 1 (TDD RED): failing cert-report util tests** - `8f06204` (test)
2. **Task 1 (TDD GREEN): pure cert-report util** - `45df112` (feat)
3. **Task 2: compliance section + i18n (5 langs) + deploy** - `066b7e3` (feat)

## Files Created/Modified
- `app/src/utils/cert-report.js` (created) - pure helpers (query builder, gating, format)
- `app/tests/unit/CertReport.test.js` (created) - 15 Vitest cases
- `app/src/components/CourseTabTeilnehmer.vue` (modified) - compliance section + methods + CSS
- `app/l10n/{de,en,fr,ru,ar}.{js,json}` (modified) - 12 new keys, real translations
- `app/css/learning.css`, `app/js/learning.css`, `app/js/learning.js` (modified) - rebuilt dist bundles

## Decisions Made
- **Section in the instructor `summary`/Abschluss subtab** — replaces the stale Phase-107 placeholder; non-cert courses still see the placeholder (section hidden, not a blank tab).
- **One serializer for table + CSV** — `buildCertReportQuery()` is load-bearing for both surfaces, making the "table == CSV filtered set" must-have structural.
- **Plain date/number inputs** over NcDateTimePicker — dependency-free, unix-second conversion in `certDateToUnix` (to = +86399 inclusive upper bound).

## Deviations from Plan

None - plan executed exactly as written. (No Rule 1-4 deviations. The optional `certCollapsed` data field from the plan was omitted — the section is not collapsible, which is a simpler non-functional choice, not a deviation in capability.)

## Issues Encountered
None. TDD RED confirmed module-not-found first; GREEN passed on the first util implementation. The i18n JSON was re-sorted to the repo's canonical sorted order on write (deterministic, key-set unchanged) — parity + value-sync green.

## Verification

- **Vitest `CertReport.test.js`:** GREEN — 15 tests (query-string empty/null/0 + stable order; gating truth table incl. null/undefined course; formatScore/formatDate).
- **ESLint:** 0 errors on `cert-report.js` + `CourseTabTeilnehmer.vue`.
- **i18n parity:** key-parity OK across DE/EN/FR/RU/AR (2240 keys each) + `.js`↔`.json` value-sync OK.
- **grep gate (REPORT-04):** added cert-report code introduces NO `user_id`/`listCertificates`/recipient-id (the one `exam.user_id` hit at line 373 is pre-existing telos/upcoming-exams code, matched only because `target_cert` contains "cert"). Rows render straight from the clean DTO.
- **must-have contains-checks:** component contains `fetchCertReport` + `cert-report/export/csv`; test contains `buildCertReportQuery`. Key-links satisfied (axios GET `/cert-report`; `window.location.href` `/cert-report/export/csv`).
- **Build + deploy:** `deploy-prod.sh --js-only` — "Build checks passed", "JS + CSS deployed".

**Completion status (honest):** logic-proven (util via Vitest) + statically clean (ESLint) + i18n-parity green + deployed. The live render + actual CSV file download are DEFERRED to the demo-course visual check (user option A — non-blocking, same as CERT-07/08/13). No authenticated browser walkthrough ran this plan.

## Requirements

- **REPORT-01** ✅ Complete — instructor sees the per-course compliance table (display name, passed date, score, expiry, verification UUID) from the clean DTO endpoint; no email/user_id on screen. The UI path now exists (was 156-01 backend-complete, deferred).
- **REPORT-02** ✅ Complete — from/to + expiringDays filters drive BOTH the table fetch and the CSV download via the same `buildCertReportQuery()` string.
- **REPORT-03** ✅ Complete — Export CSV button downloads the filtered report (display name only).
- **REPORT-04** (already Complete in 156-01) — preserved: the UI consumes `/cert-report` (NOT raw `/api/certificates`), so no recipient-id reaches the client.

## Next Phase Readiness
- Phase 156 (Compliance-Report) is now feature-complete (backend 156-01 + UI 156-02). All four REPORT requirements Complete.
- Runs parallel with **Phase 157 (Public-Verify)** — independent surface, no shared files.
- Carry-forward (unchanged): revocation path must null `active_idem_key` (R2-2); reconcile info.xml 4.4.8 → the v5.0.0 release bump (CHANGELOG + git tag) at milestone close; the live credentialed Gate 2 (instructor 200 / non-owner 403 / text/csv / no-@) + the browser visual check ride Andre's demo-course pass.

## Self-Check: PASSED

Both created files exist on disk (`app/src/utils/cert-report.js`, `app/tests/unit/CertReport.test.js`); all 3 task commits (`8f06204`, `45df112`, `066b7e3`) exist in git history.

---
*Phase: 156-compliance-report*
*Completed: 2026-06-27*
