<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\AuditService;
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
use OCP\IRequest;

/**
 * Student-facing, authenticated read access to issued certificates (Phase 155 Plan 05).
 *
 * STRICT ownership: every endpoint authorizes against the authenticated user id — a student
 * may list / view / download ONLY their own certificates (no IDOR). The PUBLIC verify route
 * is Phase 157 and is deliberately NOT implemented here; all routes are @NoAdminRequired and
 * owner-scoped (CSRF stays automatic in the NC framework on these GETs).
 */
class CertificateController extends Controller {
    /** Verification ids are RFC 4122 UUIDv4 (IssuanceService::uuidv4) — reject anything else early. */
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private CertificateMapper $certificateMapper;
    private CourseService $courseService;
    private ITimeFactory $timeFactory;
    private ?string $userId;
    private AuditService $auditService;

    public function __construct(
        string $appName,
        IRequest $request,
        CertificateMapper $certificateMapper,
        CourseService $courseService,
        ITimeFactory $timeFactory,
        ?string $userId,
        AuditService $auditService
    ) {
        parent::__construct($appName, $request);
        $this->certificateMapper = $certificateMapper;
        $this->courseService = $courseService;
        $this->timeFactory = $timeFactory;
        $this->userId = $userId;
        $this->auditService = $auditService;
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
            static fn (Certificate $cert): array => $cert->jsonSerialize(),
            $certs
        ));
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

        return new JSONResponse($cert->jsonSerialize());
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
     * @NoAdminRequired
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

        $jwt = $cert->getCredentialJson();

        if ($format === 'jwt') {
            return new DataDownloadResponse(
                $jwt,
                'certificate-' . $verificationId . '.jwt',
                'application/vc+jwt'
            );
        }

        // Default CERT-09 artifact: OB3 JSON-LD EnvelopedVerifiableCredential wrapping the JWT.
        $envelope = [
            '@context' => ['https://www.w3.org/ns/credentials/v2'],
            'type' => 'EnvelopedVerifiableCredential',
            'id' => 'data:application/vc+jwt,' . $jwt,
        ];

        return new DataDownloadResponse(
            (string)json_encode($envelope, JSON_UNESCAPED_SLASHES),
            'certificate-' . $verificationId . '.json',
            'application/ld+json'
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

        return new JSONResponse(['revoked' => true, 'verification_id' => $verificationId]);
    }
}
