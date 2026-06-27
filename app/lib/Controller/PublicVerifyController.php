<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertificateVerifyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attributes\AnonRateLimit;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;

/**
 * PublicVerifyController — the public, unauthenticated certificate verify page (Phase 157, VERIFY-01/02/06).
 *
 * A thin shell over the 157-02 CertificateVerifyService. It does NOT re-implement any crypto: it
 * validates the verificationId FORMAT before any DB/service call (anti-enumeration), delegates the
 * resolve → Ed25519 verify → claim-binding → status-precedence → DSGVO-DTO to the service, and
 * projects that DTO into a PURE server-rendered PublicTemplateResponse (templates/verify.php). There
 * is no Vue island, no second JSON endpoint, no data-* JSON — the smallest possible attack surface.
 *
 * Hardening (Codex pre-plan review):
 *   - #[AnonRateLimit] (IP-keyed) caps ALL requests; #[BruteForceProtection] + $tpl->throttle() add a
 *     per-IP brute-force penalty ONLY on the unknown/malformed branch (never on the valid/withdrawn/
 *     expired happy paths — legitimate bulk verifiers must not be slowed).
 *   - The UUID_V4 precheck runs BEFORE the service is touched; a malformed id renders the SAME generic
 *     "unknown" page as a not-found id (no existence oracle, no 404-vs-200 difference).
 *   - Always HTTP 200: withdrawn/expired/unknown are tombstone/explainer RENDERS, not 404s.
 *
 * DSGVO: the template only ever receives the service's projected display fields (status, issuer_name,
 * course_title, issued_at, expires_at, revoked_at(withdrawn), verification_id). The recipient identity
 * (name / login / signing key / raw credential) is never read here and never reaches the template.
 */
class PublicVerifyController extends Controller {
    /** Verification ids are RFC 4122 UUIDv4 (IssuanceService::uuidv4) — reject anything else early. */
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /** Brute-force action key shared by the attribute and the throttle() call. */
    private const THROTTLE_ACTION = 'learningVerify';

    private CertificateVerifyService $verifyService;
    private IL10N $l;
    private Defaults $defaults;

    public function __construct(
        string $appName,
        IRequest $request,
        CertificateVerifyService $verifyService,
        IFactory $l10nFactory,
        Defaults $defaults
    ) {
        parent::__construct($appName, $request);
        $this->verifyService = $verifyService;
        $this->l = $l10nFactory->get('learning');
        $this->defaults = $defaults;
    }

    /**
     * Public verify page: GET /apps/learning/verify/{verificationId}.
     *
     * Status precedence is owned by the service; this method only adds the format precheck and the
     * throttle-on-unknown policy, then renders. Returns HTTP 200 for every status.
     *
     * Publicness/CSRF/brute-force are declared as PHPDoc annotations — the codebase-proven form for
     * public routes in THIS NC build (DidController/IcsController/PageController all use @PublicPage
     * PHPDoc; the #[PublicPage] attribute is NOT honoured here → would 401 logged-out). The IP-keyed
     * #[AnonRateLimit] caps ALL requests and is read via the (working) attribute path, exactly like
     * IcsController's #[UserRateLimit]. $tpl->throttle() (below) feeds @BruteForceProtection.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=learningVerify)
     */
    #[AnonRateLimit(limit: 30, period: 60)]
    public function verify(string $verificationId): PublicTemplateResponse {
        // VERIFY-06: validate the FORMAT before any DB/service call. A malformed id is treated exactly
        // like a not-found id (generic 'unknown' page + throttle) — no enumeration oracle.
        if (preg_match(self::UUID_V4, $verificationId) !== 1) {
            return $this->render(['status' => 'unknown'], true);
        }

        $result = $this->verifyService->verifyByVerificationId($verificationId);
        // throttle ONLY the unknown branch (not-found). 'invalid' is a rare integrity failure, not an
        // enumeration probe → not throttled. valid/withdrawn/expired are honest hits → not throttled.
        $throttle = (($result['status'] ?? 'unknown') === 'unknown');

        return $this->render($result, $throttle);
    }

    /**
     * Build the template params from the service result and wrap them in a PublicTemplateResponse.
     *
     * @param array<string, mixed> $result
     */
    private function render(array $result, bool $throttle): PublicTemplateResponse {
        $tpl = new PublicTemplateResponse('learning', 'verify', $this->buildParams($result));
        $tpl->setHeaderTitle($this->l->t('Zertifikat-Verifizierung'));
        if ($throttle) {
            $tpl->throttle(['action' => self::THROTTLE_ACTION]);
        }
        return $tpl;
    }

    /**
     * Project the service result into the strict template param set. The cert display fields are ONLY
     * added for the found+sig-OK statuses (valid/withdrawn/expired); unknown/invalid render banner-only
     * (no course/issuer/data), so a probe learns nothing and there is no leak.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function buildParams(array $result): array {
        $status = $result['status'] ?? 'unknown';

        $params = [
            'status' => $status,
            'issuer_logo' => $this->defaults->getLogo(),
        ];

        if (in_array($status, ['valid', 'withdrawn', 'expired'], true)) {
            $params['issuer_name'] = $result['issuer_name'] ?? '';
            $params['course_title'] = $result['course_title'] ?? '';
            $params['issued_at'] = $result['issued_at'] ?? null;
            $params['expires_at'] = $result['expires_at'] ?? null;
            $params['verification_id'] = $result['verification_id'] ?? '';
            if ($status === 'withdrawn') {
                $params['revoked_at'] = $result['revoked_at'] ?? null;
            }
        }

        return $params;
    }
}
