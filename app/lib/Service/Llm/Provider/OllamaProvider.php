<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm\Provider;

use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmHttpClient;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\LlmResult;

/**
 * Ollama, /api/generate without streaming (spec 2.2). The request is the one the 5.5
 * OCA\Learning\Service\OllamaProvider sent, pinned by tests/Fixtures/llm/ollama-request.json:
 * POST {baseUrl}/api/generate with model, prompt, system (always present, trimmed), stream:false,
 * options.temperature 0–1.5 (default 0.7) and options.num_predict 128–4096; no auth header.
 *
 * Differences to 5.5: transport through LlmHttpClient (target check — a LAN address only with
 * allowInternalNetwork —, pinning, no redirects, no retry, 90 s total), URL and model from the
 * connection snapshot, the shared normalizer instead of a plain trim (removes <think> reasoning),
 * token counts from prompt_eval_count/eval_count, categorised exceptions, no logging.
 */
final class OllamaProvider implements LlmProviderInterface {
    public const TIMEOUT_SEC = 90;
    private const DEFAULT_TEMPERATURE = 0.7;

    public function __construct(
        private LlmHttpClient $http,
        private LlmOutputNormalizer $normalizer,
    ) {
    }

    public function complete(LlmConnection $connection, LlmRequest $request): LlmResult {
        if ($connection->provider !== 'ollama') {
            throw new LlmProviderException('config', '', 'Connection is not an Ollama connection');
        }

        $body = [
            'model' => $connection->model,
            'prompt' => $request->userPrompt,
            'system' => trim($request->systemPrompt),
            'stream' => false,
            'options' => [
                'temperature' => max(0.0, min(1.5, $request->temperature ?? self::DEFAULT_TEMPERATURE)),
                'num_predict' => max(128, min(4096, $request->maxOutputTokens)),
            ],
        ];

        $url = rtrim($connection->baseUrl, '/') . '/api/generate';
        $response = $this->http->postJson($connection, $url, [], $body, self::TIMEOUT_SEC);
        $data = $response['json'] ?? [];

        $raw = $data['response'] ?? '';
        $text = $this->normalizer->normalize(is_string($raw) ? $raw : '');
        if ($text === '') {
            throw new LlmProviderException('empty');
        }

        return new LlmResult(
            $text,
            (int)($data['prompt_eval_count'] ?? 0),
            (int)($data['eval_count'] ?? 0),
            $connection->model,
        );
    }
}
