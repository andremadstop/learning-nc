---
phase: 156-compliance-report
verified: 2026-06-27T17:04:45Z
status: passed
score: 8/8 must-haves verified
re_verification: false
---

# Phase 156: Compliance-Report Verification Report

**Phase Goal:** Instructors can see who passed which certifying course, filter by date/expiry, and export a DSGVO-safe CSV
**Verified:** 2026-06-27T17:04:45Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (from ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Instructor opens a certifying course and sees a compliance table (display name, passed date, score, expiry date, verification UUID — no email/user_id) | VERIFIED | `CourseTabTeilnehmer.vue` compliance section wired to `/cert-report` DTO endpoint; `grep user_id` on service+controller returns zero output-bound hits; 5-field DTO confirmed in `projectRow()` |
| 2 | Instructor can filter the report by passed-date range (from/to) and by expiry window (expiring within N days) | VERIFIED | `buildCertReportQuery()` feeds both `fetchCertReport()` and `exportCertReportCsv()` with identical params; `certFilters()` converts date inputs to unix seconds; `CertificateMapper::findByCourseId` applies all three server-side filters |
| 3 | Instructor clicks "Export CSV" and receives a downloaded file with display name only (no email, no user_id) | VERIFIED (code+test) | `exportCertReportCsv()` in controller builds injection-safe CSV with 5 columns (no user_id/email column); load-bearing no-leak PHPUnit test asserts exact 5-field DTO; **visual download deferred to demo-course pass (user option A, non-blocking)** |

**Score:** 3/3 success criteria verified (code + automated tests)

### Must-Haves from Plan Frontmatter (8 total across 156-01 + 156-02)

#### 156-01 Must-Haves (backend)

| # | Must-Have | Status | Evidence |
|---|-----------|--------|----------|
| 1 | Report rows have exactly 5 safe fields; no user_id or email in any DTO, JSON, or CSV cell | VERIFIED | `grep -n "user_id\|getUserId" CertificateReportService.php CertificateReportController.php` → 0 output hits; `testNoLeakProjectsFiveSafeFields()` asserts exact key order + `assertArrayNotHasKey('user_id'...)` |
| 2 | Foreign instructor (course A on course B) → ForbiddenException before any mapper read | VERIFIED | `testForeignInstructorIsForbiddenBeforeAnyRead()` calls real CourseService ownership check; `certMapper->expects(never())->method('findByCourseId')` confirms gate-before-read |
| 3 | from/to + expiringDays filter a single shared method; table == CSV filtered set | VERIFIED | Both `certReport()` and `exportCertReportCsv()` call same `$this->reportService->getCourseReport(...)` call; `testExpiringDaysConvertedToAbsoluteCutoff()` verifies absolute cutoff arithmetic |
| 4 | Malformed JWT falls back to 'Teilnehmer:in' without aborting the rest of the report | VERIFIED | `testMalformedJwtFallsBackWithoutAborting()` in 290-line test file; `decodePayload()` wraps all decode steps in try/catch + false-guard + null-check returns `[null, '']` |

#### 156-02 Must-Haves (UI)

| # | Must-Have | Status | Evidence |
|---|-----------|--------|----------|
| 5 | Instructor on cert_enabled course sees compliance section table (display_name, passed_at, score, expires_at, verification_id) from the DTO endpoint — never user_id | VERIFIED | `showCertReport` computed delegates to `shouldShowCertReport(this.isInstructor, this.course)`; table renders `v-for="row in certRows"` with no user_id column; `grep user_id listCertificates` in cert-report code paths → 0 hits |
| 6 | from/to + expiringDays filters: table fetch and CSV download carry the SAME query params | VERIFIED | `certFilters()` is the single source; both `fetchCertReport()` and `exportCertReportCsv()` call `buildCertReportQuery(this.certFilters())` — identical by construction, not coincidence |
| 7 | Export CSV button triggers browser download of /cert-report/export/csv + active filter query string | VERIFIED (code) | `exportCertReportCsv()` at line 894: `window.location.href = url + (qs ? '?' + qs : '')`; **visual download deferred to demo-course pass (user option A, non-blocking)** |
| 8 | Section hidden for students and non-certifying courses | VERIFIED | `shouldShowCertReport(isInstructor, course)` returns false for `isInstructor===false` OR `!course.cert_enabled`; Vitest 4-cell truth table confirms all combinations |

**Score:** 8/8 must-haves verified

### Required Artifacts

| Artifact | Status | Details |
|----------|--------|---------|
| `app/lib/Service/CertificateReportService.php` | VERIFIED | 150 lines; `getCourseReport()` gates first, converts day-count to absolute cutoff, calls mapper, projects each cert to 5-field DTO; no user_id in output |
| `app/lib/Db/CertificateMapper.php` — `findByCourseId` | VERIFIED | Lines 83–103; QBMapper query with course_id + revoked=false base, optional `gte/lte issued_at`, optional `isNotNull(expires_at) AND lte expires_at`; `orderBy issued_at DESC` |
| `app/lib/Service/CourseService.php` — `assertInstructorOfCourse` | VERIFIED | Line 563; public, additive; loads course (DoesNotExistException) + delegates to existing private `isInstructorOfCourse` (ForbiddenException); returns Course |
| `app/lib/Controller/CertificateReportController.php` | VERIFIED | 162 lines; both endpoints call `reportService->getCourseReport()`; CSV uses `csvLine()` (injection-safe, copied from DataMobilityService); `@NoAdminRequired` on both methods; `#[UserRateLimit]` on CSV |
| `app/appinfo/routes.php` | VERIFIED | Lines 200–201: `certificateReport#certReport` at `/api/courses/{courseId}/cert-report` and `certificateReport#exportCertReportCsv` at `/api/courses/{courseId}/cert-report/export/csv` |
| `app/tests/Unit/Service/CertificateReportServiceTest.php` | VERIFIED | 290 lines; 7 test methods (no-leak, IDOR, co-instructor, filter cutoff, no-cutoff null pass-through, empty score, malformed JWT fallback); exercises real `CourseService` ownership logic |
| `app/src/utils/cert-report.js` | VERIFIED | 71 lines; exports `buildCertReportQuery`, `shouldShowCertReport`, `formatScore`, `formatDate`; pure functions, no Vue/axios dependency |
| `app/tests/unit/CertReport.test.js` | VERIFIED | 79 lines; 15 test cases (6 query-string, 5 gating, 2 formatScore, 2 formatDate); **15/15 passed locally (Vitest run 2026-06-27T17:04:31Z)** |
| `app/src/components/CourseTabTeilnehmer.vue` — compliance section | VERIFIED | `showCertReport` computed at line 592; filter inputs + table at lines 400–453; `fetchCertReport()` at line 883; `exportCertReportCsv()` at line 894; imports from `cert-report.js` at line 493 |

### Key Link Verification

| From | To | Via | Status | Evidence |
|------|----|-----|--------|----------|
| `CertificateReportController` | `CertificateReportService::getCourseReport` | DI call, exceptions → 403/404 | WIRED | Both `certReport()` and `exportCertReportCsv()` call `$this->reportService->getCourseReport(...)` |
| `CertificateReportService::getCourseReport` | `CourseService::assertInstructorOfCourse` | Owner gate BEFORE any cert read | WIRED | Line 66 of service: `$this->courseService->assertInstructorOfCourse($courseId, $userId)` |
| `CertificateReportService::getCourseReport` | `CertificateMapper::findByCourseId` | Filtered query after gate | WIRED | Line 72: `$this->certificateMapper->findByCourseId($courseId, $from, $to, $expiresBefore)` |
| `CertificateReportService` | `credential_json` JWT payload | base64url decode + `credentialSubject` extraction | WIRED | `decodePayload()` mirrors `SigningService` pattern: `base64_decode(strtr($parts[1], '-_', '+/'), true)` |
| `CourseTabTeilnehmer.vue fetchCertReport()` | `/api/courses/{courseId}/cert-report` | `axios.get` with filter params | WIRED | Line 886–887: `generateUrl(...)` + `axios.get(url + (qs ? '?' + qs : ''))` |
| `CourseTabTeilnehmer.vue exportCertReportCsv()` | `/api/courses/{courseId}/cert-report/export/csv` | `window.location.href` + `buildCertReportQuery` | WIRED | Line 895–897: identical `buildCertReportQuery(this.certFilters())` as the table fetch |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| REPORT-01 | 156-01 + 156-02 | Instructor can view a compliance report per course (who passed, when, score, expiry, verification-id) | SATISFIED | Backend DTO endpoint + Vue table section wired; `testNoLeakProjectsFiveSafeFields()` verifies row shape |
| REPORT-02 | 156-01 + 156-02 | Instructor can filter the report by date range and expiry window | SATISFIED | `findByCourseId` server-side filters; `buildCertReportQuery` shared between table + CSV; `testExpiringDaysConvertedToAbsoluteCutoff()` green |
| REPORT-03 | 156-01 + 156-02 | Instructor can export the compliance report as CSV (download) | SATISFIED (code) | `exportCertReportCsv()` endpoint + injection-safe `csvLine()` + 5-column header (no user_id); Vue `exportCertReportCsv()` uses `window.location.href`; **visual download deferred (non-blocking)** |
| REPORT-04 | 156-01 | Report exposes display name only — no plaintext email (DSGVO) | SATISFIED | `grep user_id/getUserId` on service+controller → 0 output-bound hits; `looksLikeEmail()` re-screen in `projectRow()`; email-shaped frozen name → 'Teilnehmer:in' proven by load-bearing PHPUnit test |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `CertificateReportController.php` | 133, 135 | `return null` | Info | Inside `toIntOrNull()` helper — legitimate control flow converting absent/empty query params to PHP null; not a stub |

No stubs, no TODO/FIXME/PLACEHOLDER, no empty implementations found in any Phase 156 artifact.

### i18n Parity

All 5 languages (de/en/fr/ru/ar) contain the compliance keys in both `.json` and `.js` files. Key count confirmed: 6 hits per file for the 5 core terms (Compliance-Bericht, Noch keine Zertifikate, Verifizierungs-ID, Bestanden am, Gültig bis). The `.js` files were regenerated via `l10n_js_sync.py`.

### Human Verification (Deferred — Non-Blocking)

These items require a running devcloud instance with a demo certifying course. They are deferred to the same demo-course pass as CERT-07/08/13 from Phase 155 (user option A, explicitly non-blocking per project deferral discipline).

#### 1. Compliance Table Renders in Browser

**Test:** Open a certifying course (`cert_enabled=true`) as the instructor. Navigate to the Teilnehmer → Abschluss subtab.
**Expected:** A "Compliance-Bericht" section appears with columns: Name / Bestanden am / Score (%) / Gültig bis / Verifizierungs-ID. Rows show display names (no email addresses, no user IDs).
**Why human:** Visual rendering and NC app layout cannot be verified programmatically.

#### 2. Filter Inputs Narrow the Table

**Test:** Set a from/to date range that excludes some certificates, or enter an expiringDays value. Click "Filter anwenden".
**Expected:** The table updates to show only certificates matching the filter. The row count changes.
**Why human:** Real issued certificates required; table state change requires browser interaction.

#### 3. Export CSV Downloads Correctly

**Test:** With or without active filters, click "Export CSV" button.
**Expected:** Browser downloads `cert-report-course-{id}.csv` with columns: Name, Bestanden am, Score (%), Gültig bis, Verifizierungs-ID. No email address, no user_id column in the file.
**Why human:** File download triggers `window.location.href` — browser behavior, not verifiable via grep.

#### 4. Section Hidden from Students and Non-Certifying Courses

**Test:** Log in as a student and open a certifying course. Log in as an instructor and open a non-certifying course.
**Expected:** The "Compliance-Bericht" section does not appear in either scenario.
**Why human:** Role-based rendering requires live session context.

**Note:** The DSGVO guarantee (no email/user_id in any surface) is already proven by the load-bearing PHPUnit test and the zero-hit grep — the human check above is purely for the visual layout, not for data correctness.

---

_Verified: 2026-06-27T17:04:45Z_
_Verifier: Claude (gsd-verifier)_
