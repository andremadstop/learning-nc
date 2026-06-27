<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
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
    private CertificateMapper $certificateMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        CertificateMapper $certificateMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->certificateMapper = $certificateMapper;
        $this->userId = $userId;
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
}
