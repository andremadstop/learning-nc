# Phase 156: Compliance-Report - Research

**Researched:** 2026-06-27
**Domain:** Nextcloud app — instructor-only, course-scoped CSV/report export over the `learning_certificates` table (Phase 155)
**Confidence:** HIGH (all patterns read from this codebase; no external library decisions)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Pattern reuse (do NOT invent new architecture)**
- Copy the established CSV pattern: `SummaryController::exportCsv()` (app/lib/Controller/SummaryController.php:113, returns `DataDownloadResponse($csv, '...csv', 'text/csv')`) and `DataMobilityService::exportCourseStatsCsv(int $courseId)`.
- The **At-Risk CSV export** (`GET /api/courses/{courseId}/at-risk/export/csv`) is the closest existing analogue: instructor-only, course-scoped, display-name CSV download. Mirror it.
- Data source: `learning_certificates` (from 155) joined with course + NC display name resolution. The cert row has issued_at, expires_at, verification_id, user_id; the **score lives in the credential JSON** (`credential_json` JWT payload).

**Security (mandatory, mirror existing course endpoints)**
- `RoleService::isInstructor` + explicit owner-scoping: the requesting user MUST be the instructor of THAT course (IDOR protection, as `studentDetail()` / `getAtRiskStudents()` do).
- Rate-limit the export endpoint (existing convention: 10 requests / 60s on exports) to prevent scraping.
- CSRF automatic via NC framework.

**DSGVO (REPORT-04 non-negotiable)**
- Resolve NC display name only (reuse the v1.7.0 display-name convention). NEVER emit email or user_id in the table OR the CSV. Mirror the `IssuanceService` recipient-name discipline (no email-shaped fallback) where a name must be shown.

**Filtering**
- Passed-date range (from/to) + expiry window (expiring within N days), applied server-side before CSV generation so the export matches the on-screen filtered set.

### Claude's Discretion
- Exact controller method names/routes, Vue component structure for the report table, CSV column order/header labels (i18n DE-source), whether the table lives in a new instructor tab or extends an existing course view.

### Deferred Ideas (OUT OF SCOPE)
- Wallet interoperability, public verification UI (Phase 157), revocation workflow (future), multi-tenant/platform features. None of these are in 156.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| REPORT-01 | Instructor can view a compliance report per course (who passed, when, score, expiry, verification-id) | New `CertificateReportService::getCourseReport()` reads `CertificateMapper::findByCourseId()`, decodes each `credential_json` JWT payload for {frozen display name, score}, projects to a 5-field DTO. Owner-scoped via `isInstructorOfCourse()`. New JSON GET endpoint mirrors `CourseController::atRisk()`. |
| REPORT-02 | Instructor can filter by date range and expiry window | Server-side filter params (`from`, `to`, `expiringDays`) applied as QB `where` clauses on `issued_at` / `expires_at` inside the shared service method. Same method backs both the JSON table and the CSV. Filters travel as GET query params. |
| REPORT-03 | Instructor can export the compliance report as CSV (download) | New `exportCertReportCsv()` controller method mirrors `CourseController::exportAtRiskCsv()` — `DataDownloadResponse($csv, 'cert-report-course-N.csv', 'text/csv')`, `#[UserRateLimit(limit: 10, period: 60)]`. Frontend `window.location.href = generateUrl(...)` with filter query params appended. |
| REPORT-04 | Report exposes display name only — no plaintext email/user_id (DSGVO) | **NON-NEGOTIABLE.** Read the frozen, already-email-screened `credentialSubject.name` from the decoded JWT (primary); neutral pseudonym `Teilnehmer:in` on any failure; project to a DTO that has NO `user_id` field. See Pitfalls 1 & 2 — both the at-risk template and `Certificate::jsonSerialize()` contain user_id leak surfaces that must NOT be copied. |
</phase_requirements>

## Summary

Phase 156 is an **export-only, single-pattern feature**: one instructor-only, course-scoped read over the Phase-155 `learning_certificates` table, surfaced as (a) a JSON table endpoint and (b) a CSV download. There is **zero new architecture** — every piece has a verbatim template already in this codebase. The work is: one new mapper method (`findByCourseId`), one new service (`CertificateReportService`) that decodes the stored VC-JWT for the per-cert score + frozen display name and applies server-side filters, two new controller methods (JSON + CSV) on a controller, two routes, and one instructor-gated Vue section.

The single hard constraint is REPORT-04 (no email / no user_id, table OR CSV). Two of the templates you are told to mirror contain **user_id leak surfaces** that must be deliberately NOT copied: `exportAtRiskCsv`'s `?? $s['user_id']` fallback, and `Certificate::jsonSerialize()`'s `user_id` key. The score has no choice of source — it exists only as a sprintf string inside the credential JWT (`credentialSubject.result[0].resultDescription`), so JWT-payload decode is mandatory; reuse the frozen `credentialSubject.name` from that same decode as the (structurally email-free) display name.

**Primary recommendation:** Build `CertificateReportService::getCourseReport(int $courseId, string $userId, ?int $from, ?int $to, ?int $expiringDays): array` — owner-scoped via `isInstructorOfCourse()`, filters applied in SQL, each row projected from the decoded JWT to a 5-field DTO with NO user_id. JSON endpoint mirrors `atRisk()`, CSV endpoint mirrors `exportAtRiskCsv()` (but uses the formula-injection-safe `csvLine` helper and the neutral-pseudonym fallback). Frontend: a collapsible instructor section in `CourseTabTeilnehmer.vue` following the at-risk visual pattern, shown only for `cert_enabled` courses.

## Standard Stack

No new libraries. Everything is NC App Framework + existing app code.

### Core (reuse, in-repo)
| Component | File | Purpose |
|-----------|------|---------|
| `CertificateMapper` | app/lib/Db/CertificateMapper.php | QBMapper over `learning_certificates`; **add** `findByCourseId()` |
| `Certificate` entity | app/lib/Db/Certificate.php | Has issued_at, expires_at, verification_id, user_id, credential_json (JWT). **No score column** — score is inside credential_json. |
| `IssuanceService::resolveDisplayName/looksLikeEmail` | app/lib/Service/IssuanceService.php:254 | The DSGVO display-name discipline to reuse (frozen name already passed this at issuance) |
| `CourseService::isInstructorOfCourse()` | app/lib/Service/CourseService.php:542 | Per-course owner check (owner OR member role 'instructor'); the IDOR-safe gate |
| `DataMobilityService::csvLine()` | app/lib/Service/DataMobilityService.php:382 | CSV row encoder **with formula-injection protection** (tab-prefix on `= + - @`) |
| `SigningService` b64u/decode | app/lib/Service/SigningService.php:80,108 | base64url decode pattern to mirror for the JWT payload decode |
| `IUserManager` | OCP | Live display-name resolution (only if Approach A is chosen — see Open Questions) |

### CSV/download mechanics
| Pattern | Source | Notes |
|---------|--------|-------|
| `DataDownloadResponse($csv, 'name.csv', 'text/csv')` | SummaryController.php:143, CourseController.php:394 | The download response shape |
| `#[UserRateLimit(limit: 10, period: 60)]` (PHP-8 attribute) | CourseController.php:366, ExportController.php:42 | **Attribute, not `@UserRateLimit` annotation.** Mirror at-risk's 10/60 for the export. |
| `@NoAdminRequired` (docblock) | every instructor method | Load-bearing — without it a non-admin instructor gets 403 (unit tests can't catch this; 155-05 note). |

### Installation
None. No npm/composer dependency added.

## Architecture Patterns

### Recommended structure (mirrors at-risk end to end)
```
app/lib/Db/CertificateMapper.php        # + findByCourseId(int): Certificate[]
app/lib/Service/CertificateReportService.php  # NEW: owner-scope + filter + JWT-decode + DTO projection
app/lib/Controller/<X>Controller.php    # + certReport() (JSON) + exportCertReportCsv() (CSV)
app/appinfo/routes.php                  # + 2 GET routes
app/src/components/CourseTabTeilnehmer.vue   # + collapsible "Zertifikate / Compliance" section (instructor, cert_enabled)
app/l10n/*.js + *.json                  # new keys, all 5 langs (DE source value==key)
```
**Controller choice (discretion):** add the two methods to `CourseController` (where `atRisk`/`exportAtRiskCsv`/`studentDetail` already live, same `$userId` ctor + `$courseService`) OR a new thin `CertificateReportController`. `CourseController` keeps the at-risk symmetry; a new controller keeps cert concerns together. Either is fine — recommend `CourseController` for minimal surface.

### Pattern 1: Owner-scoped instructor read (mirror `getAtRiskStudents`)
**What:** Load the course, gate on per-course ownership, then read.
**When:** Both the JSON and CSV endpoints.
```php
// Source: app/lib/Service/CourseService.php:1716 (getAtRiskStudents)
public function getCourseReport(int $courseId, string $userId, ?int $from, ?int $to, ?int $expiringDays): array {
    $course = $this->courseMapper->findById($courseId);
    if (!$this->isInstructorOfCourse($course, $userId)) {
        throw new ForbiddenException('No permission');   // → controller maps to 403
    }
    $certs = $this->certificateMapper->findByCourseId($courseId, $from, $to, $expiringDays);
    // project each to a 5-field DTO (see Pattern 3) — NEVER return entities
}
```
Controller maps `ForbiddenException` → `Http::STATUS_FORBIDDEN`, `DoesNotExistException` → 404 (exact at-risk controller shape, CourseController.php:350-395).

### Pattern 2: Server-side filtering — one query, two consumers
**What:** `from`/`to` constrain `issued_at`; `expiringDays` constrains `expires_at`. Applied as QB where-clauses in `findByCourseId` so the JSON table and the CSV are byte-identical filtered sets.
**When:** REPORT-02.
```php
// Source: at-risk recent-session filter, CourseService.php:1765 (gte on completed_at)
$qb->select('*')->from('learning_certificates')
   ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
   ->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
if ($from !== null)  { $qb->andWhere($qb->expr()->gte('issued_at', $qb->createNamedParameter($from))); }
if ($to !== null)    { $qb->andWhere($qb->expr()->lte('issued_at', $qb->createNamedParameter($to))); }
if ($expiringDays !== null) {
    $cutoff = $this->timeFactory->getTime() + $expiringDays * 86400;
    $qb->andWhere($qb->expr()->isNotNull('expires_at'));            // never-expiring certs are NOT "expiring"
    $qb->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($cutoff)));
}
$qb->orderBy('issued_at', 'DESC');
```
**Filters travel as GET query params** (`request->getParam('from')` etc.) — `window.location.href` cannot POST a body, so the CSV download must read filters from the query string, and the JSON endpoint reads the same params. Absent param = no constraint.

### Pattern 3: DTO projection + JWT-payload decode (score + frozen name)
**What:** The score exists ONLY as a string in the credential JWT (`'score:%s; threshold:%d'`, IssuanceService.php:213). Decode the JWT payload (middle segment) for the score AND the already-email-screened `credentialSubject.name`. Project to a 5-field DTO with NO user_id.
```php
// Decode mirrors SigningService.php:80 (base64_decode(strtr(..., '-_', '+/'), true))
$parts = explode('.', $cert->getCredentialJson());
$payload = isset($parts[1])
    ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/'), true), true)
    : null;
$name  = is_array($payload) ? ($payload['credentialSubject']['name'] ?? null) : null;
$rd    = is_array($payload) ? ($payload['credentialSubject']['result'][0]['resultDescription'] ?? '') : '';
$score = preg_match('/score:([0-9.]+)/', (string)$rd, $m) ? $m[1] : '';   // may be '' (null score)

$row = [
    'display_name'    => ($name === null || $name === '') ? 'Teilnehmer:in' : $name,  // neutral fallback, NEVER user_id
    'passed_at'       => $cert->getIssuedAt(),        // unix; format client-side or ISO here
    'score'           => $score,                       // '' → render as '—'
    'expires_at'      => $cert->getExpiresAt(),        // nullable (null = no expiry)
    'verification_id' => $cert->getVerificationId(),
];
```
Per-row decode failure must be guarded (skip-to-fallback) so one malformed cert does not nuke the whole export.

### Anti-Patterns to Avoid
- **Returning the `Certificate` entity / `jsonSerialize()`** — it emits `user_id` (Certificate.php:69). Project to the DTO. Applies to the JSON table too, not just the CSV (CONTEXT success-criterion 1: no user_id on screen).
- **`?? $cert user_id` fallback** — the at-risk CSV does `$s['display_name'] ?? $s['user_id']` (CourseController.php:382). NC user-ids can BE emails (IssuanceService warns this explicitly). Fall back to `Teilnehmer:in`, never the id.
- **Bare `RoleService::isInstructor($userId)`** — that only checks admin/group membership; an instructor of course A could pull course B's report = IDOR. Use `isInstructorOfCourse($course, $userId)` (the owner-scoping is the load-bearing half).
- **Re-deriving the score live** — the frozen score at issuance is the compliance truth; read it from the stored credential, not a fresh evaluation.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CSV row with user text | raw `fputcsv` | `DataMobilityService::csvLine()` | Formula-injection (`= + - @`) protection; names are user-controlled |
| File download | custom headers | `DataDownloadResponse` | NC sets content-disposition/type correctly |
| Rate limiting | custom counter | `#[UserRateLimit(limit:10,period:60)]` | NC middleware; anti-scraping per convention |
| base64url decode | new helper | mirror SigningService.php:80 | `strtr('-_','+/')` + `base64_decode(...,true)` already proven |
| DSGVO name resolution | new logic | reuse frozen `credentialSubject.name` / `resolveDisplayName` discipline | Already email-screened at issuance |
| Owner check | new query | `isInstructorOfCourse()` | IDOR-safe, throws `ForbiddenException` |

**Key insight:** This phase is a recombination of existing, security-reviewed primitives. The only genuinely new code is the JWT-payload decode for the score and the DTO projection — both small and both test-covered below.

## Common Pitfalls

### Pitfall 1: `user_id` fallback in the CSV (REPORT-04 fail)
**What goes wrong:** Copying `exportAtRiskCsv`'s `$s['display_name'] ?? $s['user_id']` puts a plaintext email (user-ids can be emails) into the compliance CSV.
**How to avoid:** Fallback chain ends at the neutral pseudonym `Teilnehmer:in`, never user_id.
**Warning sign:** Any `?? ...user_id...` or `->getUserId()` reaching an output column.

### Pitfall 2: Entity leak via `jsonSerialize()`
**What goes wrong:** `Certificate::jsonSerialize()` includes `user_id` and the raw `credential_json`. Returning entities from the JSON endpoint leaks user_id on screen.
**How to avoid:** Always project to the 5-field DTO; never return `$cert` or `$cert->jsonSerialize()`.
**Warning sign:** `new DataResponse($certs)` where `$certs` are entities.

### Pitfall 3: Missing `@NoAdminRequired`
**What goes wrong:** A controller method with no annotation defaults to admin-required; a non-admin instructor gets 403. Unit tests instantiate the controller directly and bypass the annotation middleware, so they stay green regardless (155-05 note).
**How to avoid:** `@NoAdminRequired` docblock on both new methods; verify by inspection + Gate-2 credentialed test.

### Pitfall 4: Filter drift between table and CSV
**What goes wrong:** Filtering the JSON in SQL but the CSV in PHP (or vice-versa) → export ≠ on-screen set.
**How to avoid:** One shared service method takes the filter params; both endpoints call it. Filters as GET query params on both.

### Pitfall 5: Fragile score parse
**What goes wrong:** `resultDescription` score field can be `''` (PassResult score null, IssuanceService.php:213-217); a strict cast or unguarded regex breaks the row.
**How to avoid:** `preg_match('/score:([0-9.]+)/', ...)`; empty → render `—`. Per-row try/guard on the whole decode.

### Pitfall 6: Index name length / cross-DB (only if adding an index)
**What goes wrong:** MariaDB utf8mb4 key-length blowup; index names >27 chars rejected (155 convention).
**How to avoid:** If adding `course_id` index, new migration `Version009200…`, index name ≤27 chars (e.g. `learn_cert_crs_idx`). See Open Questions — it's a nicety, not correctness.

## Code Examples

### CSV controller method (mirror exportAtRiskCsv, injection-safe, no user_id)
```php
// Source pattern: app/lib/Controller/CourseController.php:367 (exportAtRiskCsv)
/** @NoAdminRequired */
#[UserRateLimit(limit: 10, period: 60)]
public function exportCertReportCsv(int $courseId): Http\Response {
    try {
        $rows = $this->reportService->getCourseReport(
            $courseId, $this->userId,
            $this->toIntOrNull($this->request->getParam('from')),
            $this->toIntOrNull($this->request->getParam('to')),
            $this->toIntOrNull($this->request->getParam('expiringDays'))
        );
    } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
        return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
    } catch (\OCA\Learning\Service\ForbiddenException $e) {
        return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
    }
    $lines = [$this->csvLine([ /* i18n headers, NO user_id/email column */
        'Name', 'Bestanden am', 'Score (%)', 'Gültig bis', 'Verifizierungs-ID',
    ])];
    foreach ($rows as $r) {
        $lines[] = $this->csvLine([
            $r['display_name'],
            $r['passed_at'] ? gmdate('Y-m-d', $r['passed_at']) : '',
            $r['score'] !== '' ? $r['score'] : '—',
            $r['expires_at'] ? gmdate('Y-m-d', $r['expires_at']) : '',   // empty = no expiry
            $r['verification_id'],
        ]);
    }
    return new DataDownloadResponse(implode("\n", $lines), 'cert-report-course-' . $courseId . '.csv', 'text/csv');
}
```
(`csvLine` either injected from `DataMobilityService` or a private copy of DataMobilityService.php:382.)

### Frontend (mirror at-risk, instructor + cert_enabled gated)
```js
// Source: app/src/components/CourseTabTeilnehmer.vue:778 (exportAtRiskCsv) + :782 (fetchAtRisk)
exportCertReportCsv() {
  const url = generateUrl('/apps/learning/api/courses/{courseId}/cert-report/export/csv', { courseId: this.courseId })
    + '?from=' + (this.from || '') + '&to=' + (this.to || '') + '&expiringDays=' + (this.expiringDays || '')
  window.location.href = url
},
async fetchCertReport() {
  const url = generateUrl('/apps/learning/api/courses/{courseId}/cert-report', { courseId: this.courseId })
  const response = await axios.get(url, { params: { from: this.from, to: this.to, expiringDays: this.expiringDays } })
  this.certRows = response.data.rows || []
},
```
Section visible only when `this.isInstructor` (parent already gates the tab) **and** the course is certifying (`course.cert_enabled` — snake_case, jsonSerialize emits snake_case per 154-02). Options-API, `NcButton`/`NcDateTimePicker` for filters.

## State of the Art

| Old Approach | Current Approach | Why |
|--------------|------------------|-----|
| `@UserRateLimit` annotation (CONTEXT.md wording) | `#[UserRateLimit(limit:N,period:S)]` PHP-8 attribute | This codebase uses the attribute everywhere (ExportController, CourseController) |
| Score as a DB column | Score frozen inside the credential VC-JWT | 155 chose self-contained credentials; no score column exists — decode the JWT |

## Open Questions

1. **Display name: frozen (JWT) vs live (IUserManager)?**
   - Known: the score forces a JWT decode regardless; `credentialSubject.name` is in that same payload and already passed `looksLikeEmail` at issuance.
   - **Recommendation: use the frozen `credentialSubject.name` (Approach B)** — zero extra queries, structurally email-free, consistent with what Phase-157 verification will display. If a *current* display name is preferred (Approach A), reproduce `resolveDisplayName`+`looksLikeEmail` verbatim and still never fall back to user_id. State the choice in the plan; don't coin-flip.
   - Caveat either way: all fallback rows render `Teilnehmer:in` and become indistinguishable — relevant for the AWO "which employee passed" use case.

2. **Include revoked certs?**
   - Recommendation: filter `revoked = false` for the compliance table (revocation is Phase 157; R2-2 carry-forward says revoke nulls `active_idem_key`). A "withdrawn" column is out of scope for 156.

3. **`expiringDays` semantics — include already-expired?**
   - Genuine ambiguity. Recommendation: `expires_at <= now + N*86400 AND expires_at IS NOT NULL` → includes already-expired ("needs attention") and excludes never-expiring certs. The UI label must match the chosen semantics.

4. **course_id index?**
   - The existing `learn_cert_user_crs_idx (user_id, course_id)` does NOT serve a course-only filter (leading column mismatch). At single-course / bounded-membership scale this is irrelevant to correctness. If added: new migration `Version009200…`, name `learn_cert_crs_idx` (≤27 chars). **Nicety, not a blocker** — don't build a composite without profiling.

5. **Data boundary (surface to planner/AWO):** the report reflects *issued certificates*, not historical passes. Students who passed before issuance went live have no cert (backfill = DIFF-03, deferred). The report's data starts when issuance was provisioned on the instance.

## Validation Architecture

> nyquist_validation is enabled (config.json: `workflow.nyquist_validation: true`).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit (backend) + Vitest (frontend) + PHPStan L5 + ESLint; Gate 2 `scripts/test-api.sh` |
| Config file | app/phpunit.xml (relay/in-container), app/vite.config.mjs (Vitest), app/.eslintrc |
| Quick run command | `cd app && npm run test` (Vitest) · PHP lint `docker exec -i devcloud-app php -l` |
| Full suite command | PHPStan `ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpstan analyse --no-progress'` + `cd app && npm run test` + `scripts/test-api.sh` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| REPORT-04 | **No-leak: email-shaped name AND email-shaped user_id → output is `Teilnehmer:in`, never the id; CSV header has no user_id/email column; DTO has no user_id key** | unit (PHPUnit) | `phpunit --filter CertificateReportServiceTest` | ❌ Wave 0 |
| REPORT-01/04 | Owner-scoping/IDOR: instructor of course A requesting course B → `ForbiddenException`/403, no data | unit (PHPUnit) | `phpunit --filter CertificateReportServiceTest` | ❌ Wave 0 |
| REPORT-02 | Filter correctness: `from`/`to` bound issued_at; `expiringDays` bounds expires_at (NULL excluded); table query == CSV query (same method) | unit (PHPUnit) | `phpunit --filter CertificateReportServiceTest` | ❌ Wave 0 |
| REPORT-01 | Score parse: well-formed JWT → score; null/empty score → `''`/`—`; malformed credential → row falls back, export not nuked | unit (PHPUnit) | `phpunit --filter CertificateReportServiceTest` | ❌ Wave 0 |
| REPORT-03 | CSV shape: 5 columns, formula-injection prefix on `= + - @`, filtered set matches JSON | unit + Gate 2 | `phpunit` + `scripts/test-api.sh` (cert-report block) | ❌ Wave 0 (test-api block) |
| REPORT-01/03 | Endpoints registered, `@NoAdminRequired`, rate-limit attribute present | inspection + Gate 2 | `occ router:list learning` + credentialed `test-api.sh` | partial |
| REPORT-01 | Frontend: section instructor+cert_enabled gated; export builds filter query string | unit (Vitest) | `cd app && npm run test -- CertReport` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** PHP lint + `npm run test` (Vitest) + PHPStan L5 (Gate 1, ~30s).
- **Per wave merge:** full Vitest + PHPStan + `bash -n scripts/test-api.sh`.
- **Phase gate:** PHPUnit no-leak + IDOR + filter tests green; credentialed `scripts/test-api.sh` cert-report block green (bruteforce-reset `172.21.0.1` first) before `/gsd:verify-work`.

### Wave 0 Gaps
- [ ] `app/tests/unit/CertificateReportServiceTest.php` — covers REPORT-01/02/04 (the fallback-leak test is the load-bearing assertion: email-shaped name + email-shaped userId → `Teilnehmer:in`; assert `user_id` absent from every DTO and every CSV cell/header)
- [ ] `app/tests/unit/CertReport.test.js` (Vitest) — frontend filter→query-string + instructor/cert_enabled gating
- [ ] `scripts/test-api.sh` — add a cert-report block (instructor 200, non-owner 403, CSV content-type, no `@`/user_id in body) mirroring the at-risk block
- [ ] PhpUnitStubs: confirm `DataDownloadResponse`/`Http::STATUS_FORBIDDEN` present (added in 155-05) — likely already covered

## Sources

### Primary (HIGH confidence — read this session)
- app/lib/Controller/SummaryController.php (exportCsv at :113), app/lib/Controller/CourseController.php (atRisk/exportAtRiskCsv/studentDetail :350-401)
- app/lib/Db/Certificate.php (no score column; jsonSerialize emits user_id :69), app/lib/Db/CertificateMapper.php (findByUserAndCourse, IDOR-safe findByVerificationIdAndUserId)
- app/lib/Service/DataMobilityService.php (exportCourseStatsCsv :189, csvLine injection guard :382), app/lib/Service/RoleService.php (isInstructor), app/lib/Service/CourseService.php (isInstructorOfCourse :542, getAtRiskStudents :1716)
- app/lib/Service/IssuanceService.php (buildCredential resultDescription :213, resolveDisplayName/looksLikeEmail :254-271)
- app/lib/Service/SigningService.php (base64url decode :80, b64u :108), app/lib/Migration/Version009100…php (index naming ≤27, cross-DB)
- app/appinfo/routes.php (cert routes :343-345), app/src/components/CourseTabTeilnehmer.vue (:778 export, :782 fetch), app/src/components/CourseDetail.vue (isInstructor tab gating :210)
- .planning/config.json (nyquist_validation true), CONTEXT.md, REQUIREMENTS.md, STATE.md

### Secondary
- Advisor review (this session) — confirmed the two user_id leak surfaces, the name fork resolution, and the expiry-filter ambiguity.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all in-repo, read directly
- Architecture: HIGH — at-risk is a near-exact structural twin
- DSGVO/no-leak: HIGH — leak surfaces identified in the very templates being mirrored
- Pitfalls: HIGH — drawn from 154/155 execution notes + advisor

**Research date:** 2026-06-27
**Valid until:** 2026-07-27 (stable; internal codebase patterns, no fast-moving external deps)
