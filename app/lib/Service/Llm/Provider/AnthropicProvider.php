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
 * Anthropic Messages API (spec 4.2), raw over LlmHttpClient, no SDK. Pinned by
 * tests/Fixtures/llm/anthropic-request.json; the API reference each field was checked against is
 * cited in AnthropicProviderTest.
 *
 * - POST https://api.anthropic.com/v1/messages, headers `x-api-key` and
 *   `anthropic-version: 2023-06-01`. The connection's baseUrl must be exactly that endpoint: key and
 *   consent are bound to baseUrl, so any other value is a config error and nothing is sent.
 * - Body: `model` (default claude-opus-5), `max_tokens` = max(4096, requested tokens) as plan A5d
 *   says (current models think by default and thinking counts against max_tokens), top-level
 *   `system` only when not empty, one `user` message. `temperature` only
 *   where ModelCapabilities allows it, clamped to the API range 0–1 (default 0.7).
 * - Never sent: `thinking`, `output_config`, server-side `fallbacks` or any beta header — a
 *   fallback would re-run the prompt on a model the admin did not choose.
 * - Answer: `stop_reason: refusal` is checked first and is category `refusal` even when partial
 *   text came back (admin detail: the refusal category only). Otherwise the `text` blocks are the
 *   answer; thinking and other blocks never are. No text → `empty`, naming a cut-off stop reason.
 * - Usage `input_tokens` / `output_tokens`. Timeout 45 s (spec 2.2). No logging here.
 */
final class AnthropicProvider implements LlmProviderInterface {
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2023-06-01';
    public const TIMEOUT_SEC = 45;
    private const DEFAULT_MODEL = 'claude-opus-5';
    private const DEFAULT_TEMPERATURE = 0.7;
    private const MIN_MAX_TOKENS = 4096;

    public function __construct(
        private LlmHttpClient $http,
        private ApiKeyStore $keys,
        private LlmOutputNormalizer $normalizer,
        private ModelCapabilities $capabilities,
    ) {
    }

    public function complete(LlmConnection $connection, LlmRequest $request): LlmResult {
        if ($connection->provider !== 'anthropic') {
            throw new LlmProviderException('config', '', 'Connection is not an Anthropic connection');
        }
        if (!self::isAnthropicEndpoint($connection->baseUrl)) {
            throw new LlmProviderException('config', '', 'Anthropic base URL must be https://api.anthropic.com/v1');
        }
        $apiKey = trim((string)$this->keys->get($connection));
        if ($apiKey === '') {
            throw new LlmProviderException('config', '', 'No API key configured for this connection');
        }

        $model = $connection->model !== '' ? $connection->model : self::DEFAULT_MODEL;
        $body = [
            'model' => $model,
            'max_tokens' => max(self::MIN_MAX_TOKENS, $request->maxOutputTokens),
        ];
        $system = trim($request->systemPrompt);
        if ($system !== '') {
            $body['system'] = $system;
        }
        $body['messages'] = [['role' => 'user', 'content' => $request->userPrompt]];
        if ($this->capabilities->forModel('anthropic', $model)['temperature']) {
            $body['temperature'] = max(0.0, min(1.0, $request->temperature ?? self::DEFAULT_TEMPERATURE));
        }

        $headers = ['x-api-key' => $apiKey, 'anthropic-version' => self::API_VERSION];
        $response = $this->http->postJson($connection, self::ENDPOINT, $headers, $body, self::TIMEOUT_SEC);
        $data = $response['json'] ?? [];

        $stopReason = is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : '';
        if ($stopReason === 'refusal') {
            throw new LlmProviderException('refusal', '', self::refusalDetail($data['stop_details'] ?? null));
        }

        $text = $this->normalizer->normalize(self::answerText($data['content'] ?? null));
        if ($text === '') {
            $cutOff = in_array($stopReason, ['max_tokens', 'model_context_window_exceeded'], true);
            throw new LlmProviderException('empty', '',
                $cutOff ? "No answer text before the limit (stop_reason=$stopReason)" : '');
        }

        return new LlmResult(
            $text,
            (int)($data['usage']['input_tokens'] ?? 0),
            (int)($data['usage']['output_tokens'] ?? 0),
            $model,
        );
    }

    /** Exactly https://api.anthropic.com/v1 (host case and one trailing slash aside). */
    private static function isAnthropicEndpoint(string $baseUrl): bool {
        $u = parse_url($baseUrl);
        return is_array($u)
            && strtolower((string)($u['scheme'] ?? '')) === 'https'
            && strtolower((string)($u['host'] ?? '')) === 'api.anthropic.com'
            && (!isset($u['port']) || $u['port'] === 443)
            && rtrim((string)($u['path'] ?? ''), '/') === '/v1'
            && !isset($u['user']) && !isset($u['pass']) && !isset($u['query']) && !isset($u['fragment']);
    }

    /** Only the refusal category, and only if it looks like one; never the explanation. */
    private static function refusalDetail(mixed $details): string {
        $category = is_array($details) ? ($details['category'] ?? null) : null;
        if (is_string($category) && preg_match('/^[a-z0-9_]{1,40}$/', $category) === 1) {
            return "Refused by the model (category: $category)";
        }
        return 'Refused by the model';
    }

    /** The `text` blocks of the content list; anything else is no answer. */
    private static function answerText(mixed $content): string {
        if (!is_array($content) || !array_is_list($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        return $text;
    }
}
