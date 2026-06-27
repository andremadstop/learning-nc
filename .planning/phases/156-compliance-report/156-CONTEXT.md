# Phase 156: Compliance-Report - Context

**Gathered:** 2026-06-27
**Status:** Ready for planning
**Source:** Roadmap spec + NLM prior-knowledge query (no discuss-phase — phase is tightly specified)

<domain>
## Phase Boundary

Instructors can see who passed which certifying course, filter by date/expiry, and export a DSGVO-safe CSV. Export-only feature — NOT a dashboard or analytics product. Reads the `learning_certificates` table from Phase 155.

Requirements: REPORT-01..04. REPORT-04 (no email / no user_id in CSV — display name only) is non-negotiable.

Success criteria (from roadmap):
1. Instructor opens a certifying course → compliance table: display name, passed date, score, expiry date, verification UUID — NO email/user_id.
2. Filter by passed-date range (from/to) and by expiry window (expiring within N days).
3. "Export CSV" → downloaded file with the filtered report, display name only.

Scope guardrails: NO multi-tenant platform; wallet interop deferred; export-only, not analytics.
</domain>

<decisions>
## Implementation Decisions (locked)

### Pattern reuse (do NOT invent new architecture)
- Copy the established CSV pattern: `SummaryController::exportCsv()` (app/lib/Controller/SummaryController.php:113, returns `DataDownloadResponse($csv, '...csv', 'text/csv')`) and `DataMobilityService::exportCourseStatsCsv(int $courseId)`.
- The **At-Risk CSV export** (`GET /api/courses/{courseId}/at-risk/export/csv` — CSV of Name/RiskLevel/Reasons/Accuracy) is the closest existing analogue: same shape (instructor-only, course-scoped, display-name CSV download). Mirror it.
- Data source: `learning_certificates` (from 155) joined with course + NC display name resolution. The cert row already has issued_at, expires_at, verification_id, user_id, the score lives in the credential JSON / course pass data.

### Security (mandatory, mirror existing course endpoints)
- `RoleService::isInstructor` + explicit owner-scoping: the requesting user MUST be the instructor of THAT course (IDOR protection, as `studentDetail()` does).
- Rate-limit the export endpoint (existing convention: `@UserRateLimit` 10 requests / 60s on exports) to prevent scraping.
- CSRF automatic via NC framework.

### DSGVO (REPORT-04 non-negotiable)
- Resolve NC display name only (reuse the v1.7.0 display-name convention). NEVER emit email or user_id in the table OR the CSV. Mirror the IssuanceService recipient-name discipline (no email-shaped fallback) where a name must be shown.

### Filtering
- Passed-date range (from/to) + expiry window (expiring within N days), applied server-side before CSV generation so the export matches the on-screen filtered set.

### Claude's Discretion
- Exact controller method names/routes, Vue component structure for the report table, CSV column order/header labels (i18n DE-source), whether the table lives in a new instructor tab or extends an existing course view.
</decisions>

<specifics>
## Specific Ideas / Reuse Targets (from NLM)

- `SummaryController` (6 endpoints), `DataMobilityService::exportCourseStatsCsv`, `CourseSummaryService::getClassSummary(int $courseId)`.
- At-Risk export route as the structural template.
- Frontend precedent: `InstructorDashboard.vue`, `CourseTabTeilnehmer.vue`, instructor-only course tabs (`v-if` role-gated), `StudentDetail.vue`.
- `RoleService` (isInstructor/getRole), composite DB indices precedent (migration adds course-scoped query indices if needed).
- Privacy precedent: student views strip sensitive fields; instructor views see full data — but the certificate report intentionally shows display name + cert metadata only.
</specifics>

<deferred>
## Deferred Ideas

- Wallet interoperability, public verification UI (Phase 157), revocation workflow (future), multi-tenant/platform features. None of these are in 156.
</deferred>

---

*Phase: 156-compliance-report*
*Context gathered: 2026-06-27 via roadmap spec + NLM query*
