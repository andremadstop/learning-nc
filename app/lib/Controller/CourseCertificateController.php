<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CertificateArtifact;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\ForbiddenException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Attribute\OpenAPI;

/**
 * Instructor copies of the certificates of ONE course (GitHub #42): organisations that maintain
 * their staff's qualification must keep copies of the certificates for audits.
 *
 * Every action authorizes against the course in the URL: only an instructor of that course (its
 * creator or a co-instructor member, CourseService::assertInstructorOfCourse) gets anything. A caller
 * who does not teach the course gets 403 before any certificate is read; a certificate that belongs
 * to another course answers 404. CertificateController stays strictly owner-scoped for learners.
 *
 * The files are the learner's own files (CertificateArtifact) — there is no second render path. The
 * viewer reuses the learner's Certificate.vue, whose print gives the PDF.
 *
 * All three are GETs without a side effect except one audit event (AuditService::logEvent, the
 * pattern for instructor/admin actions outside the compliance hash chain): who looked at or copied
 * which certificate. The event never blocks the response.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CourseCertificateController extends Controller {
    public const AUDIT_EVENT = 'cert.instructor_access';

    private CertificateReportService $reportService;
    private AuditService $auditService;
    private LoggerInterface $logger;
    private ?string $userId;
    private CertLayoutService $layoutService;

    public function __construct(
        string $appName,
        IRequest $request,
        CertificateReportService $reportService,
        AuditService $auditService,
        LoggerInterface $logger,
        ?string $userId,
        CertLayoutService $layoutService
    ) {
        parent::__construct($appName, $request);
        $this->reportService = $reportService;
        $this->auditService = $auditService;
        $this->logger = $logger;
        $this->userId = $userId;
        $this->layoutService = $layoutService;
    }

    /**
     * One certificate of the course for the instructor's viewer. Same DTO shape the learner's
     * show() returns (credential_json is what the viewer decodes; user_id and key_id dropped), plus
     * the state the instructor must see: revoked / revoked_at / expired.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function show(int $courseId, string $verificationId): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $cert = $this->resolve($courseId, $verificationId);
        if ($cert instanceof JSONResponse) {
            return $cert;
        }

        $this->audit('view', $courseId, ['verification_id' => $verificationId]);

        $row = $this->reportService->describeCertificate($cert);
        $data = $cert->jsonSerialize();
        unset($data['user_id'], $data['key_id']);
        $data['revoked'] = (bool)$cert->getRevoked();
        $data['revoked_at'] = $cert->getRevokedAt();
        $data['expired'] = $row['expired'];
        // GitHub #43: the instructor's copy is drawn like the learner's (same frozen layout).
        $data['presentation'] = $this->layoutService->presentationForCertificate($cert);
        return new JSONResponse($data);
    }

    /**
     * The learner's certificate file for the instructor. Opened by browser navigation, which carries
     * no request token (see #41), so it is exempt from the CSRF check; the instructor gate in
     * resolve() still decides. PHPDoc form on purpose: the attribute form is not honoured in this NC
     * build (see AuditExportController).
     *
     * Accepted side effect: the cert.instructor_access audit row is written on every call, so a
     * cross-site navigation by another page can add one. The file then really did reach the
     * instructor's browser (the other page cannot read it), so the entry is not false; it may just
     * not have been triggered knowingly. Same for exportZip().
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function download(int $courseId, string $verificationId, string $format = 'jsonld'): Response {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $cert = $this->resolve($courseId, $verificationId);
        if ($cert instanceof JSONResponse) {
            return $cert;
        }

        $format = CertificateArtifact::normalizeFormat($format);
        $this->audit('download', $courseId, ['verification_id' => $verificationId, 'format' => $format]);

        return new DataDownloadResponse(
            CertificateArtifact::content($cert->getCredentialJson(), $format),
            CertificateArtifact::fileName($verificationId, $format),
            CertificateArtifact::mimeType($format)
        );
    }

    /**
     * Every certificate of the filtered report as one ZIP: the same filters (from / to /
     * expiringDays) and therefore the same rows as the table and the CSV. Each entry is the
     * learner's JSON-LD file, named <name>_<verification id>.json; an expired certificate carries
     * "_expired" in its name. Revoked certificates are not in the report and so not in the ZIP.
     * CSRF-exempt like download(), with the same accepted audit side effect (see there).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportZip(int $courseId): Response {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $certs = $this->reportService->findReportCertificates(
                $courseId,
                $this->userId,
                $this->toIntOrNull($this->request->getParam('from')),
                $this->toIntOrNull($this->request->getParam('to')),
                $this->toIntOrNull($this->request->getParam('expiringDays'))
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }

        if ($certs === []) {
            return new JSONResponse(['error' => 'No certificates'], Http::STATUS_NOT_FOUND);
        }
        if (!class_exists(\ZipArchive::class)) {
            $this->logger->error('exportZip: the PHP zip extension is missing', ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'learning-certs-');
        if ($tmp === false) {
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('cannot open zip');
            }
            $used = [];
            $vids = [];
            foreach ($certs as $cert) {
                $vid = (string)$cert->getVerificationId();
                $name = $this->entryName($cert, $used);
                $used[$name] = true;
                $vids[] = $vid;
                $zip->addFromString($name, CertificateArtifact::content($cert->getCredentialJson(), CertificateArtifact::FORMAT_JSONLD));
            }
            if ($zip->close() !== true) {
                throw new \RuntimeException('cannot write zip');
            }
            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('cannot read zip');
            }
        } catch (\Throwable $e) {
            $this->logger->error('exportZip error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        $this->audit('download_zip', $courseId, ['count' => count($vids), 'verification_ids' => $vids]);

        return new DataDownloadResponse($bytes, 'certificates-course-' . $courseId . '.zip', 'application/zip');
    }

    /**
     * The instructor gate, then the certificate of this course. Returns the certificate or the error
     * response: 403 for a course the caller does not teach (before any certificate read), 404 for a
     * malformed id, an unknown course or a certificate of another course.
     */
    private function resolve(int $courseId, string $verificationId): Certificate|JSONResponse {
        try {
            return $this->reportService->getCourseCertificate($courseId, $verificationId, (string)$this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * ZIP entry name: the report's display name (already screened: never the account id or an
     * email), reduced to letters, digits, dot, dash and underscore, then the verification id.
     *
     * @param array<string, bool> $used names already in the archive
     */
    private function entryName(Certificate $cert, array $used): string {
        $row = $this->reportService->describeCertificate($cert);
        $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', (string)$row['display_name']) ?? '';
        $base = trim($base, '._-');
        if ($base === '') {
            $base = 'certificate';
        }
        $base = mb_substr($base, 0, 60);
        $name = $base . '_' . $cert->getVerificationId() . ($row['expired'] ? '_expired' : '') . '.json';
        $n = 2;
        while (isset($used[$name])) {
            $name = $base . '_' . $cert->getVerificationId() . '_' . $n++ . '.json';
        }
        return $name;
    }

    /**
     * Facts only: course, action, verification id(s). Never a name, never blocking: logEvent
     * swallows its own failures, so a broken audit table cannot hide a certificate.
     *
     * @param array<string, mixed> $context
     */
    private function audit(string $action, int $courseId, array $context): void {
        $this->auditService->logEvent(self::AUDIT_EVENT, (string)$this->userId, ['course_id' => $courseId, 'action' => $action] + $context);
    }

    private function toIntOrNull(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int)$value : null;
    }
}
