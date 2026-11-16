<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CertificateArtifact;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;

/**
 * Student-facing, authenticated read access to issued certificates (Phase 155 Plan 05).
 *
 * STRICT ownership: every endpoint authorizes against the authenticated user id — a student
 * may list / view / download ONLY their own certificates (no IDOR). The PUBLIC verify route
 * is Phase 157 and is deliberately NOT implemented here; all routes are @NoAdminRequired and
 * owner-scoped. index/show/revoke are called through axios and keep the framework CSRF check;
 * download() is opened by plain browser navigation, which carries no request token, so it is
 * the one action exempt from the CSRF check (GitHub #41).
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CertificateController extends Controller {
    /** Verification ids are RFC 4122 UUIDv4 (IssuanceService::uuidv4) — reject anything else early. */
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private CertificateMapper $certificateMapper;
    private CourseService $courseService;
    private ITimeFactory $timeFactory;
    private ?string $userId;
    private AuditService $auditService;
    private IDBConnection $db;
    private CertLayoutService $layoutService;

    public function __construct(
        string $appName,
        IRequest $request,
        CertificateMapper $certificateMapper,
        CourseService $courseService,
        ITimeFactory $timeFactory,
        ?string $userId,
        AuditService $auditService,
        IDBConnection $db,
        CertLayoutService $layoutService
    ) {
        parent::__construct($appName, $request);
        $this->certificateMapper = $certificateMapper;
        $this->courseService = $courseService;
        $this->timeFactory = $timeFactory;
        $this->userId = $userId;
        $this->auditService = $auditService;
        $this->db = $db;
        $this->layoutService = $layoutService;
    }

    /**
     * List the current user's issued certificates (newest first).
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $certs = $this->certificateMapper->findByUserId($this->userId);

        return new JSONResponse(array_map(
            fn (Certificate $cert): array => $this->projectOwnedCert($cert),
            $certs
        ));
    }

    /**
     * AUDIT MED-14: the owner-facing list/show DTO drops user_id and key_id — the client never
     * reads them (it decodes credential_json locally, see certificate-credential.js), so keeping
     * them only widened accidental exposure in API logs / browser caches. credential_json stays
     * because the frontend requires it; the raw artifact is still also served by /download.
     *
     * @return array<string, mixed>
     */
    private function projectOwnedCert(Certificate $cert): array {
        $data = $cert->jsonSerialize();
        unset($data['user_id'], $data['key_id']);
        return $data;
    }

    /**
     * Fetch a single owned certificate by its verification id.
     * Uniform 404 for BOTH a non-existent id and a foreign-owned one (no existence oracle / IDOR
     * side-channel — ownership is enforced in the owner-scoped mapper query).
     *
     * @NoAdminRequired
     */
    public function show(string $verificationId): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Reject malformed ids before any DB lookup (defence-in-depth; the route is owner-scoped).
        if (preg_match(self::UUID_V4, $verificationId) !== 1) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $cert = $this->certificateMapper->findByVerificationIdAndUserId($verificationId, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $data = $this->projectOwnedCert($cert);
        // GitHub #43: how this certificate is drawn (frozen at issuance; null = the standard card),
        // with its stamp / signature as data URLs: only whoever may see the certificate gets them.
        $data['presentation'] = $this->layoutService->presentationForCertificate($cert);
        // GitHub #67: the course can switch "Add to LinkedIn" off (e.g. internal mandatory training).
        $data['linkedin_allowed'] = $this->courseService->isLinkedInShareEnabled((int)$cert->getCourseId());
        return new JSONResponse($data);
    }

    /**
     * Download an owned certificate as a credential file. Same ownership semantics as show().
     *
     * DEFAULT (CERT-09 artifact): an Open Badges 3.0 JSON-LD EnvelopedVerifiableCredential
     * wrapping the stored compact VC-JWT — the spec-correct way to present a JWT-secured VC
     * as JSON-LD:
     *   {"@context":["https://www.w3.org/ns/credentials/v2"],
     *    "type":"EnvelopedVerifiableCredential",
     *    "id":"data:application/vc+jwt,<compact JWT>"}
     * served as application/ld+json, filename certificate-<vid>.json.
     *
     * OPTIONAL ?format=jwt → the raw compact JWT (application/vc+jwt, .jwt) for convenience.
     *
     * GitHub #41: the Download button navigates the browser to this URL, and a navigation carries
     * no request token, so the framework CSRF check answered "Access forbidden – CSRF check
     * failed". The action is a read-only GET (no state change) and stays owner-scoped through
     * findByVerificationIdAndUserId, so skipping the CSRF check exposes nothing a forged request
     * could change or read cross-origin. PHPDoc form on purpose: the attribute form is not
     * honoured in this NC build (see AuditExportController).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function download(string $verificationId, string $format = 'jsonld'): Response {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Reject malformed ids before any DB lookup (defence-in-depth; the route is owner-scoped).
        if (preg_match(self::UUID_V4, $verificationId) !== 1) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $cert = $this->certificateMapper->findByVerificationIdAndUserId($verificationId, $this->userId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        // Same file builder as the instructor copy in the compliance report (GitHub #42).
        $jwt = $cert->getCredentialJson();
        return new DataDownloadResponse(
            CertificateArtifact::content($jwt, $format),
            CertificateArtifact::fileName($verificationId, $format),
            CertificateArtifact::mimeType($format)
        );
    }

    /**
     * Revoke a certificate (VERIFY-05 write side). Authenticated + owner-gated: only an instructor
     * of the cert's course may revoke it. The row PERSISTS (revoked=true) as a tombstone the public
     * verify route reads — it is NOT deleted.
     *
     * Hardening (157-CONTEXT review_findings #5):
     *  - owner gate (assertInstructorOfCourse) runs BEFORE any write;
     *  - idempotent: a repeat revoke keeps the FIRST revoked_at (does not overwrite);
     *  - atomic: revoked=true + revoked_at + active_idem_key=NULL are persisted together;
     *  - active_idem_key=NULL (R2-2) frees the UNIQUE slot so a re-issue stays possible;
     *  - a foreign OR unknown cert returns a UNIFORM 404 (never 403 — no existence oracle),
     *    consistent with show()/download().
     *
     * @NoAdminRequired
     */
    public function revoke(string $verificationId): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Reject malformed ids before any DB lookup (anti-enumeration / IDOR).
        if (preg_match(self::UUID_V4, $verificationId) !== 1) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $cert = $this->certificateMapper->findByVerificationId($verificationId);
            // Owner gate BEFORE any write. A non-owner (ForbiddenException) and a missing course
            // (DoesNotExistException) both collapse to the uniform 404 below — no existence oracle.
            $this->courseService->assertInstructorOfCourse($cert->getCourseId(), $this->userId);
        } catch (DoesNotExistException | ForbiddenException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $cert->setRevoked(true);
        // Capture before the guard sets revokedAt — used to fire the compliance event only once.
        $isFirstRevoke = $cert->getRevokedAt() === null;
        // Idempotent: keep the FIRST revocation time on a repeat revoke.
        if ($cert->getRevokedAt() === null) {
            $cert->setRevokedAt($this->timeFactory->getTime());
        }
        // R2-2: free the UNIQUE idempotency slot so a re-issue stays possible.
        $cert->setActiveIdemKey(null);

        // FIX-2 (atomicity, fail-closed): the revoke mutation and its CERT_REVOKED compliance-audit
        // append commit or roll back TOGETHER. If the audit append throws (ComplianceAuditException),
        // the whole transaction rolls back so the tombstone is NOT persisted without a chained audit
        // event, and the error propagates — NC turns an uncaught controller exception into HTTP 500
        // (fail-closed). logComplianceEvent's own CAS transaction nests here via a savepoint.
        $this->db->beginTransaction();
        try {
            $this->certificateMapper->update($cert);
            // AUDIT-03 compliance event — only on first revoke (repeat calls are idempotent;
            // a duplicate chain entry for the same cert would be misleading).
            if ($isFirstRevoke) {
                $this->auditService->logComplianceEvent(
                    ComplianceEventTypes::CERT_REVOKED,
                    $this->userId ?? '',
                    ['course_id' => $cert->getCourseId(), 'verification_id' => $verificationId]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e; // audit failure or other — roll back so no un-audited tombstone survives
        }

        return new JSONResponse(['revoked' => true, 'verification_id' => $verificationId]);
    }
}
