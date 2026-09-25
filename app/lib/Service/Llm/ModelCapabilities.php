<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Which request parameters a model accepts (spec 2.2): a small list verified against the vendor
 * documentation on 2026-09-25, deliberately without a model-family heuristic. Sources and the
 * exact wording are recorded in ModelCapabilitiesTest, which pins every entry.
 *
 * - Anthropic: the token limit is always `max_tokens`. Sampling parameters are rejected (400) by
 *   the current models; an unknown Anthropic model therefore gets no temperature.
 * - OpenAI-compatible: OpenAI reasoning models need `max_completion_tokens` and reject
 *   `temperature` at their default reasoning effort; every other model gets the standard
 *   `max_tokens` + `temperature`. Dated snapshots (`o3-2025-04-16`) count as their model.
 * - Gemini and Ollama have their own request formats; they get the standard answer.
 */
class ModelCapabilities {

    /**
     * Claude models whose Messages API still accepts `temperature`. Verified to reject it:
     * claude-opus-5, claude-opus-5-5, claude-sonnet-5, claude-fable-5, claude-fable-5-1,
     * claude-opus-4-7, claude-opus-4-8 — they, and any Claude model not listed here, get none.
     */
    private const ANTHROPIC_WITH_TEMPERATURE = [
        'claude-opus-4-6',
        'claude-sonnet-4-6',
        'claude-haiku-4-5',
    ];

    /** OpenAI reasoning models (model pages list "Reasoning token support"). */
    private const OPENAI_REASONING = [
        'gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna',
        'gpt-5.6', 'gpt-5.6-terra', 'gpt-5.6-luna',
        'gpt-5.5', 'gpt-5.4', 'gpt-5.2', 'gpt-5.1',
        'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
        'o1', 'o3', 'o3-mini', 'o3-pro', 'o4-mini',
    ];

    /**
     * @return array{temperature: bool, tokenParam: 'max_tokens'|'max_completion_tokens'}
     */
    public function forModel(string $provider, string $model): array {
        $model = strtolower(trim($model));

        if ($provider === 'anthropic') {
            return [
                'temperature' => in_array($model, self::ANTHROPIC_WITH_TEMPERATURE, true),
                'tokenParam' => 'max_tokens',
            ];
        }

        if ($provider === 'openai_compatible' && $this->isOpenAiReasoningModel($model)) {
            return ['temperature' => false, 'tokenParam' => 'max_completion_tokens'];
        }

        return ['temperature' => true, 'tokenParam' => 'max_tokens'];
    }

    private function isOpenAiReasoningModel(string $model): bool {
        $base = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model) ?? $model;
        return in_array($base, self::OPENAI_REASONING, true);
    }
}
