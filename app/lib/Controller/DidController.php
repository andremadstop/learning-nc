<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\CertKeyMapper;
use OCA\Learning\Service\KeyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * DidController — public did:web document for the instance issuer (CERT-02 + CERT-04 linchpin).
 *
 * Serves /apps/learning/did.json (path-based did:web:<host>:apps:learning). Publishes a
 * verificationMethod for EVERY non-revoked key (active + retired) so that key rotation never
 * invalidates past certificates — the JWT `kid` selects which key verifies a given credential.
 *
 * Public + no-CSRF (mirrors IcsController::feed() DOCBLOCK style on NC 33). No secret material
 * is ever exposed: only the public Ed25519 key (publicKeyJwk, no `d`).
 */
class DidController extends Controller {
    private KeyService $keyService;
    private CertKeyMapper $certKeyMapper;

    public function __construct(
        string $appName,
        IRequest $request,
        KeyService $keyService,
        CertKeyMapper $certKeyMapper
    ) {
        parent::__construct($appName, $request);
        $this->keyService = $keyService;
        $this->certKeyMapper = $certKeyMapper;
    }

    /**
     * GET /apps/learning/did.json — the did:web DID document.
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function index(): JSONResponse {
        $did = $this->keyService->hostDid();

        $verificationMethods = [];
        foreach ($this->certKeyMapper->findAllNonRevoked() as $key) {
            // verificationMethod.id MUST equal the JWT `kid` that 155-03 emits (curl-verified in 155-07).
            $vmId = $did . '#' . $key->getKeyId();
            $verificationMethods[] = [
                'id' => $vmId,
                'type' => 'JsonWebKey2020',
                'controller' => $did,
                'publicKeyJwk' => [
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => $key->getPublicKeyB64u(),
                ],
            ];
        }

        return new JSONResponse([
            '@context' => [
                'https://www.w3.org/ns/did/v1',
                'https://w3id.org/security/suites/jws-2020/v1',
            ],
            'id' => $did,
            'verificationMethod' => $verificationMethods,
            'assertionMethod' => array_map(
                static fn(array $vm): string => $vm['id'],
                $verificationMethods
            ),
        ]);
    }
}
