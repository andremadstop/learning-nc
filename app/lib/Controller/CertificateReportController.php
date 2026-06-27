<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\ForbiddenException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Instructor-facing, owner-scoped compliance report for a certifying course (Phase 156 Plan 01).
 *
 * Thin controller: both endpoints call the SAME service method so the on-screen JSON table and the
 * CSV download are byte-identical filtered sets. Authorization is per-course (the service gate maps
 * a foreign instructor to ForbiddenException → 403, a missing course to DoesNotExistException → 404).
 * The account identifier and email never appear in any surface — the projection is done in the
 * service and only the safe 5-field DTO reaches here.
 *
 * Auth mirrors CertificateController: a `?string $userId` ctor param (NC injects null when
 * unauthenticated) with a null-guard → 401; all endpoints are @NoAdminRequired so a non-admin
 * instructor is not blocked by the admin middleware (load-bearing, unit-uncatchable).
 */
class CertificateReportController extends Controller {

    private CertificateReportService $reportService;
    private LoggerInterface $logger;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        CertificateReportService $reportService,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->reportService = $reportService;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    /**
     * JSON report table for a certifying course (REPORT-01/02): one row per issued certificate,
     * server-side filtered by from/to (issued_at window) and expiringDays (expiry window).
     *
     * @NoAdminRequired
     */
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
     * @NoAdminRequired
     */
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

        // DE-source header labels (i18n source convention); NO recipient-id / email column.
        $lines = [];
        $lines[] = $this->csvLine(['Name', 'Bestanden am', 'Score (%)', 'Gültig bis', 'Verifizierungs-ID']);
        foreach ($result['rows'] as $r) {
            $lines[] = $this->csvLine([
                (string)$r['display_name'],
                $r['passed_at'] !== null ? gmdate('Y-m-d', (int)$r['passed_at']) : '',
                $r['score'] !== '' ? (string)$r['score'] : '—',
                $r['expires_at'] !== null ? gmdate('Y-m-d', (int)$r['expires_at']) : '',
                (string)$r['verification_id'],
            ]);
        }

        return new DataDownloadResponse(
            implode("\n", $lines),
            'cert-report-course-' . $courseId . '.csv',
            'text/csv'
        );
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

    /**
     * Encode a row as CSV with formula-injection protection. Copied from DataMobilityService (its
     * csvLine is private and unreachable by DI): tab-prefix any field starting with = + - @ so a
     * spreadsheet app never evaluates a cell as a formula.
     *
     * @param array<int, string> $fields
     */
    private function csvLine(array $fields): string {
        $fields = array_map(function ($f) {
            if (is_string($f) && $f !== '' && in_array($f[0], ['=', '+', '-', '@'], true)) {
                return "\t" . $f;
            }
            return $f;
        }, $fields);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, $fields);
        rewind($handle);
        $line = rtrim((string)stream_get_contents($handle), "\n");
        fclose($handle);
        return $line;
    }
}
