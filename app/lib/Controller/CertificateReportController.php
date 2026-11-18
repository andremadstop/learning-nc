<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\CsvExportL10n;
use OCA\Learning\Service\CsvFormulaGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

/**
 * Instructor-facing, owner-scoped compliance report for a certifying course (Phase 156 Plan 01).
 *
 * Thin controller: both endpoints call the SAME service method so the on-screen JSON table and the
 * CSV download are byte-identical filtered sets. Authorization is per-course (the service gate maps
 * a foreign instructor to ForbiddenException → 403, a missing course to DoesNotExistException → 404).
 * The account identifier and email never appear in any surface — the projection is done in the
 * service and only the safe DTO (5 display fields plus the expired flag) reaches here.
 *
 * Auth mirrors CertificateController: a `?string $userId` ctor param (NC injects null when
 * unauthenticated) with a null-guard → 401; all endpoints are #[NoAdminRequired] so a non-admin
 * instructor is not blocked by the admin middleware (load-bearing, unit-uncatchable).
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CertificateReportController extends Controller {

    private CertificateReportService $reportService;
    private KeyService $keyService;
    private LoggerInterface $logger;
    private ?string $userId;
    private CsvExportL10n $csvL10n;

    public function __construct(
        string $appName,
        IRequest $request,
        CertificateReportService $reportService,
        KeyService $keyService,
        LoggerInterface $logger,
        ?string $userId,
        ?IL10N $l10n = null
    ) {
        parent::__construct($appName, $request);
        $this->reportService = $reportService;
        $this->keyService = $keyService;
        $this->logger = $logger;
        $this->userId = $userId;
        $this->csvL10n = new CsvExportL10n($l10n);
    }

    /**
     * JSON report table for a certifying course (REPORT-01/02): one row per issued certificate,
     * server-side filtered by from/to (issued_at window) and expiringDays (expiry window).
     */
    #[NoAdminRequired]
    public function certReport(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->reportService->getCourseReport(
                $courseId,
                $this->userId,
                $this->toIntOrNull($this->request->getParam('from')),
                $this->toIntOrNull($this->request->getParam('to')),
                $this->toIntOrNull($this->request->getParam('expiringDays'))
            );
            // GitHub #35: tell the instructor when certificates cannot be issued at all (no or no
            // usable issuer key) — learners then pass without getting one. Read only after the
            // instructor gate inside getCourseReport() has passed.
            $result['signing_available'] = $this->keyService->canSign();
            // GitHub #35: members who passed but got no certificate, whatever the cause. A read
            // path: if it fails the report still loads, with null meaning "could not be checked".
            try {
                $result['passed_without_certificate'] = $this->reportService->getPassedWithoutCertificate($courseId, $this->userId);
            } catch (\Throwable $e) {
                $this->logger->warning('certReport: passed-without-certificate check failed: ' . $e->getMessage(), ['app' => 'learning']);
                $result['passed_without_certificate'] = null;
            }
            return new DataResponse($result);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('certReport error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * CSV download of the SAME filtered set (REPORT-03): display-name only, injection-safe. Return
     * type is the Http\Response supertype because the error branches return a DataResponse.
     *
     * GitHub #41: the export button navigates the browser here (no request token), so without
     * the CSRF exemption the instructor got "CSRF check failed". Read-only GET; the instructor gate
     * in getCourseReport (ForbiddenException → 403) and the rate limit stay in force.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportCertReportCsv(int $courseId): Http\Response {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->reportService->getCourseReport(
                $courseId,
                $this->userId,
                $this->toIntOrNull($this->request->getParam('from')),
                $this->toIntOrNull($this->request->getParam('to')),
                $this->toIntOrNull($this->request->getParam('expiringDays'))
            );
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('exportCertReportCsv error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // GitHub #48: headers and status in the exporting user's language; NO recipient-id /
        // email column.
        $lines = [];
        $lines[] = CsvFormulaGuard::line($this->csvL10n->certReportHeader());
        foreach ($result['rows'] as $r) {
            $lines[] = CsvFormulaGuard::line([
                (string)$r['display_name'],
                $r['passed_at'] !== null ? gmdate('Y-m-d', (int)$r['passed_at']) : '',
                $r['score'] !== '' ? (string)$r['score'] : '—',
                $r['expires_at'] !== null ? gmdate('Y-m-d', (int)$r['expires_at']) : '',
                (string)$r['verification_id'],
                // An expiry filter also lists certificates that already expired (audit #222).
                $this->csvL10n->certStatus(!empty($r['expired'])),
            ]);
        }

        return new DataDownloadResponse(
            CsvExportL10n::BOM . implode("\n", $lines),
            'cert-report-course-' . $courseId . '.csv',
            CsvExportL10n::CONTENT_TYPE
        );
    }

    /**
     * Coverage report (Lückencheck B2): every enrolled learner with a coverage status, the last
     * certificate, its expiry and the required pools passed. Optional ?status= keeps one status.
     */
    #[NoAdminRequired]
    public function coverageReport(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($this->reportService->getCoverageReport($courseId, $this->userId, $this->statusParam()));
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('coverageReport error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * CSV of the SAME coverage list (same service call, same status filter), in the exporting
     * user's language. Navigation download like the certificate report CSV (GitHub #41): no
     * request token, so CSRF-exempt; read-only GET, instructor gate and rate limit stay in force.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportCoverageCsv(int $courseId): Http\Response {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $result = $this->reportService->getCoverageReport($courseId, $this->userId, $this->statusParam());
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('exportCoverageCsv error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $lines = [];
        $lines[] = CsvFormulaGuard::line($this->csvL10n->coverageHeader());
        foreach ($result['rows'] as $r) {
            $hasPools = (int)$r['required_pools_total'] > 0;
            $hasContent = (int)($r['required_content_total'] ?? 0) > 0;
            $lines[] = CsvFormulaGuard::line([
                (string)$r['display_name'],
                $this->csvL10n->coverageStatus((string)$r['status']),
                $r['last_certificate_at'] !== null ? gmdate('Y-m-d', (int)$r['last_certificate_at']) : '',
                $r['expires_at'] !== null ? gmdate('Y-m-d', (int)$r['expires_at']) : '',
                // Two numeric columns, not "1/2": a spreadsheet reads that as a date.
                $hasPools ? (string)(int)$r['required_pools_passed'] : '',
                $hasPools ? (string)(int)$r['required_pools_total'] : '',
                $hasContent ? (string)(int)$r['required_content_confirmed'] : '',
                $hasContent ? (string)(int)$r['required_content_total'] : '',
            ]);
        }

        return new DataDownloadResponse(
            CsvExportL10n::BOM . implode("\n", $lines),
            'coverage-course-' . $courseId . '.csv',
            CsvExportL10n::CONTENT_TYPE
        );
    }

    /** The ?status= filter of the coverage report as a string, or null when absent. */
    private function statusParam(): ?string {
        $status = $this->request->getParam('status');
        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Group compliance report for a team lead (RBAC-02): all members of the group for a course,
     * with status 'passed' | 'expired' | 'overdue' | 'missing' and an 'expiring' flag (expiringDays
     * only sets that flag, it never filters the members). The groupId is read from the request so
     * an absent/empty groupId fails closed inside the service (no "all-groups" fallback).
     */
    #[NoAdminRequired]
    public function groupReport(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $groupId = (string)($this->request->getParam('groupId') ?? '');

        try {
            $rows = $this->reportService->getGroupReport(
                $courseId,
                $groupId,
                $this->userId,
                $this->toIntOrNull($this->request->getParam('expiringDays'))
            );
            return new DataResponse(['rows' => $rows]);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Group or course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('groupReport error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Dispatch a mandatory compliance reminder to one group member (RBAC-04).
     *
     * The service independently re-validates BOTH that the caller leads the group
     * AND that the target uid belongs to that group — this is a separate IDOR surface
     * from the report GET and must not be trusted to have been gated there.
     *
     * groupId + memberRef are read from the POST body. memberRef is the opaque member
     * handle from the report DTO (never a raw uid). An absent/empty groupId lets the
     * service fail closed (ForbiddenException before any DB read).
     *
     * Returns a generic 403 on any denial — no membership-oracle detail in the body.
     */
    #[NoAdminRequired]
    // One POST per row click (TeamLeadDashboard has no batch path): 60 a minute lets a lead
    // remind a whole overdue group in one go and still bounds the learner mail queue.
    #[UserRateLimit(limit: 60, period: 60)]
    public function remindMember(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $groupId = (string)($this->request->getParam('groupId') ?? '');
        $memberRef = (string)($this->request->getParam('memberRef') ?? '');

        try {
            $this->reportService->remindMember($courseId, $groupId, $memberRef, $this->userId);
            return new DataResponse(['status' => 'ok']);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('remindMember error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * All (course_id, group_id) scopes this team lead is authorised to view.
     */
    #[NoAdminRequired]
    public function myTeamLeadScopes(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $scopes = $this->reportService->myTeamLeadScopes($this->userId);
            return new DataResponse(['scopes' => $scopes]);
        } catch (\Exception $e) {
            $this->logger->error('myTeamLeadScopes error: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * A numeric query param as int, otherwise null (so absent/garbage filters disable the filter).
     */
    private function toIntOrNull(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int)$value : null;
    }
}
