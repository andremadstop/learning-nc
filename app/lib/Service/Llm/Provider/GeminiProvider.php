<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm\Provider;

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmHttpClient;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\LlmResult;

/**
 * Google Gemini, generateContent API (spec 2.2). The request is the one the 5.5
 * OCA\Learning\Service\GeminiProvider sent, pinned by tests/Fixtures/llm/gemini-request.json:
 * POST {baseUrl}/models/{model}:generateContent, key in x-goog-api-key, system_instruction always
 * present (also when empty), temperature 0–1.5 (default 0.7), maxOutputTokens 128–4096, one
 * candidate; the answer is the first part of the first candidate.
 *
 * Differences to 5.5: transport through LlmHttpClient (target check, pinning, no redirects, no
 * retry), model from the connection, <think> removal via the shared normalizer, categorised
 * exceptions, and no logging here (metrics move to the gateway).
 */
final class GeminiProvider implements LlmProviderInterface {
    public const TIMEOUT_SEC = 45;
    private const DEFAULT_MODEL = 'gemini-2.5-flash';
    private const DEFAULT_TEMPERATURE = 0.7;

    public function __construct(
        private LlmHttpClient $http,
        private ApiKeyStore $keys,
        private LlmOutputNormalizer $normalizer,
    ) {
    }

    public function complete(LlmConnection $connection, LlmRequest $request): LlmResult {
        if ($connection->provider !== 'gemini') {
            throw new LlmProviderException('config', '', 'Connection is not a Gemini connection');
        }
        $apiKey = $this->keys->get($connection);
        if ($apiKey === null || trim($apiKey) === '') {
            throw new LlmProviderException('config', '', 'No API key configured for this connection');
        }

        $model = $connection->model !== '' ? $connection->model : self::DEFAULT_MODEL;
        // encoded: the model is one path segment and must not be able to add a path, query or fragment
        $url = rtrim($connection->baseUrl, '/') . '/models/' . rawurlencode($model) . ':generateContent';

        $body = [
            'system_instruction' => [
                'parts' => [['text' => trim($request->systemPrompt)]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $request->userPrompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => max(0.0, min(1.5, $request->temperature ?? self::DEFAULT_TEMPERATURE)),
                'maxOutputTokens' => max(128, min(4096, $request->maxOutputTokens)),
                'candidateCount' => 1,
            ],
        ];

        $response = $this->http->postJson($connection, $url, ['x-goog-api-key' => trim($apiKey)], $body, self::TIMEOUT_SEC);
        $data = $response['json'] ?? [];

        $raw = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = $this->normalizer->normalize(is_string($raw) ? $raw : '');
        if ($text === '') {
            throw new LlmProviderException('empty');
        }

        return new LlmResult(
            $text,
            (int)($data['usageMetadata']['promptTokenCount'] ?? 0),
            (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            $model,
        );
    }
}
