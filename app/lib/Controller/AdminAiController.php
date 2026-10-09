<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmGateway;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * The admin's connection test (spec 2.4, 5.4; plan Task B5): POST /api/admin/ai/test.
 *
 * Sends the gateway's fixed test message to a candidate connection that may not be saved yet —
 * no user content, no subject user. 5.7.0 tests exactly what the admin can select (Gemini,
 * Ollama, see ConnectionStore::SELECTABLE_PROVIDERS); any other provider is refused with 400
 * before a request is made, so nothing a 5.8.0 adapter would reach can be contacted from here.
 *
 * Admin-only: no NoAdminRequired, so the App Framework refuses everyone else. The key the admin
 * typed is used for this one request and never stored, and it never comes back in the answer.
 */
class AdminAiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private LlmGateway $gateway,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @AdminRequired
     *
     * @param string $provider gemini | ollama
     * @param string $ollama_url Ollama endpoint (ignored for Gemini)
     * @param string $ollama_model Ollama model (ignored for Gemini)
     * @param ?string $key a Gemini key to test before saving it; empty = the stored key
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function test(string $provider, string $ollama_url = '', string $ollama_model = '', ?string $key = null): DataResponse {
        $provider = strtolower(trim($provider));
        // Check the whitelist first: fromLegacyValues() maps anything unknown to `disabled`, which
        // would answer 200 with a failed test instead of refusing the provider.
        if ($provider === 'disabled' || !in_array($provider, ConnectionStore::SELECTABLE_PROVIDERS, true)) {
            return new DataResponse(['error' => 'AI provider is not available in this release'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $candidate = ConnectionStore::fromLegacyValues($provider, true, $ollama_url, $ollama_model);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'Invalid AI connection: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        if ($candidate->provider !== $provider) {
            return new DataResponse(['error' => 'AI provider is not available in this release'], Http::STATUS_BAD_REQUEST);
        }

        $candidateKey = $provider === 'gemini' && $key !== null && trim($key) !== '' ? trim($key) : null;
        $result = $this->gateway->testConnection($candidate, $candidateKey);

        return new DataResponse([
            'ok' => $result['ok'],
            'model' => $result['model'],
            'latencyMs' => $result['latencyMs'],
            'category' => $result['category'],
            'detail' => self::redact($result['detail'], $candidateKey),
        ]);
    }

    /** The gateway already redacts; this is the last line before the answer leaves. */
    private static function redact(?string $detail, ?string $key): ?string {
        if ($detail === null || $key === null) {
            return $detail;
        }
        return str_replace($key, '[redacted]', $detail);
    }
}
