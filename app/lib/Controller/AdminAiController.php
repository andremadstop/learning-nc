<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmGateway;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;

/**
 * The admin's connection test (spec 2.4, 5.4; plan Task B5): POST /api/admin/ai/test.
 *
 * Sends the gateway's fixed test message to a candidate connection that may not be saved yet —
 * no user content, no subject user. The test covers exactly what the admin can select (Gemini,
 * Ollama, OpenAI-compatible, see ConnectionStore::SELECTABLE_PROVIDERS); any other provider is
 * refused with 400 before a request is made, so no unreleased adapter can be contacted from here.
 *
 * Admin-only: no NoAdminRequired, so the App Framework refuses everyone else. The key the admin
 * typed is used for this one request and never stored, and it never comes back in the answer.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class AdminAiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private LlmGateway $gateway,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $provider gemini | ollama | openai_compatible
     * @param string $ollama_url Ollama or OpenAI-compatible endpoint (ignored for Gemini)
     * @param string $ollama_model Ollama or OpenAI-compatible model (ignored for Gemini)
     * @param ?string $key a Gemini or OpenAI-compatible key to test before saving; empty = stored key
     * @param string $allow_internal_network yes = http and LAN addresses for an OpenAI-compatible endpoint
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function test(string $provider, string $ollama_url = '', string $ollama_model = '', ?string $key = null, string $allow_internal_network = 'no'): DataResponse {
        $provider = strtolower(trim($provider));
        // Check the whitelist first: fromLegacyValues() maps anything unknown to `disabled`, which
        // would answer 200 with a failed test instead of refusing the provider.
        if ($provider === 'disabled' || !in_array($provider, ConnectionStore::SELECTABLE_PROVIDERS, true)) {
            return new DataResponse(['error' => 'AI provider is not available in this release'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $candidate = ConnectionStore::fromLegacyValues($provider, true, $ollama_url, $ollama_model, $allow_internal_network === 'yes');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'Invalid AI connection: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        if ($candidate->provider !== $provider) {
            return new DataResponse(['error' => 'AI provider is not available in this release'], Http::STATUS_BAD_REQUEST);
        }

        $candidateKey = in_array($provider, ['gemini', 'openai_compatible'], true) && $key !== null && trim($key) !== '' ? trim($key) : null;
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
