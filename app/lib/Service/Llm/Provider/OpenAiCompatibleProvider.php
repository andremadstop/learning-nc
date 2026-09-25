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
use OCA\Learning\Service\Llm\ModelCapabilities;

/**
 * OpenAI Chat Completions and the endpoints that speak it (spec 4.1): presets openai, mistral,
 * ionos, openrouter and custom. Pinned by tests/Fixtures/llm/openai-compatible-request.json; the
 * documentation each field was checked against is cited in OpenAiCompatibleProviderTest.
 *
 * - POST {baseUrl}/chat/completions, `Authorization: Bearer <key>`; a key is required except for
 *   `custom`, which then sends no Authorization header at all.
 * - messages: a system message only when the trimmed system prompt is not empty, then the user
 *   message. Token parameter (`max_tokens` or `max_completion_tokens`) and whether `temperature` is
 *   sent come from ModelCapabilities; values are clamped like the other adapters (tokens 128–4096,
 *   temperature 0–1.5, default 0.7).
 * - OpenRouter: `provider: {order: [<one provider>], allow_fallbacks: false}` on every request. The
 *   routing is checked again here (a snapshot need not come from fromArray()); without exactly one
 *   fixed provider nothing is sent, since the consented recipient would not be the actual one.
 * - Answer: `choices[0].message.content`, either a string or a list of chunks of which only
 *   `type: text` counts (Mistral reasoning models); `reasoning_content` and thinking chunks are
 *   never part of the answer. Timeout 45 s, `custom` 90 s (spec 2.2). No logging here.
 */
final class OpenAiCompatibleProvider implements LlmProviderInterface {
    public const TIMEOUT_SEC = 45;
    public const TIMEOUT_CUSTOM_SEC = 90;
    private const DEFAULT_TEMPERATURE = 0.7;

    public function __construct(
        private LlmHttpClient $http,
        private ApiKeyStore $keys,
        private LlmOutputNormalizer $normalizer,
        private ModelCapabilities $capabilities,
    ) {
    }

    public function complete(LlmConnection $connection, LlmRequest $request): LlmResult {
        if ($connection->provider !== 'openai_compatible') {
            throw new LlmProviderException('config', '', 'Connection is not an OpenAI-compatible connection');
        }
        $isCustom = $connection->preset === 'custom';
        $routedProvider = $connection->preset === 'openrouter' ? self::fixedOpenRouterProvider($connection->openRouterRouting) : null;

        $headers = [];
        $apiKey = trim((string)$this->keys->get($connection));
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        } elseif (!$isCustom) {
            throw new LlmProviderException('config', '', 'No API key configured for this connection');
        }

        $messages = [];
        $system = trim($request->systemPrompt);
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $request->userPrompt];

        $caps = $this->capabilities->forModel('openai_compatible', $connection->model);
        $body = [
            'model' => $connection->model,
            'messages' => $messages,
            $caps['tokenParam'] => max(128, min(4096, $request->maxOutputTokens)),
        ];
        if ($caps['temperature']) {
            $body['temperature'] = max(0.0, min(1.5, $request->temperature ?? self::DEFAULT_TEMPERATURE));
        }
        if ($routedProvider !== null) {
            $body['provider'] = ['order' => [$routedProvider], 'allow_fallbacks' => false];
        }

        $url = rtrim($connection->baseUrl, '/') . '/chat/completions';
        $timeout = $isCustom ? self::TIMEOUT_CUSTOM_SEC : self::TIMEOUT_SEC;
        $response = $this->http->postJson($connection, $url, $headers, $body, $timeout);
        $data = $response['json'] ?? [];

        $choice = is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
        $text = $this->normalizer->normalize(self::answerText($choice['message']['content'] ?? null));
        if ($text === '') {
            $truncated = ($choice['finish_reason'] ?? null) === 'length';
            throw new LlmProviderException('empty', '',
                $truncated ? 'No answer text before the token limit (finish_reason=length)' : '');
        }

        return new LlmResult(
            $text,
            (int)($data['usage']['prompt_tokens'] ?? 0),
            (int)($data['usage']['completion_tokens'] ?? 0),
            $connection->model,
        );
    }

    /**
     * The one provider OpenRouter must route to, or a config error before anything is sent. Takes
     * mixed on purpose: the snapshot's declared shape is only guaranteed by fromArray().
     */
    private static function fixedOpenRouterProvider(mixed $routing): string {
        $order = is_array($routing) ? ($routing['order'] ?? null) : null;
        if (!is_array($order) || count($order) !== 1 || !array_is_list($order) || !is_string($order[0])
            || trim($order[0]) === '' || ($routing['allowFallbacks'] ?? null) !== false) {
            throw new LlmProviderException('config', '', 'OpenRouter needs exactly one fixed provider and no fallbacks');
        }
        return trim($order[0]);
    }

    /** A plain string, or the `text` chunks of a chunk list; anything else is no answer. */
    private static function answerText(mixed $content): string {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $chunk) {
            if (is_array($chunk) && ($chunk['type'] ?? null) === 'text' && is_string($chunk['text'] ?? null)) {
                $text .= $chunk['text'];
            }
        }
        return $text;
    }
}
