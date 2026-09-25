<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\Llm\ModelCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * 5.6.0 Task A4: a small, verified list (no family heuristic) of which request parameters a
 * model accepts. Sending `temperature` or the wrong token parameter to a model that rejects it
 * is a 400 on every single request, so every entry here was checked against the vendor docs on
 * 2026-09-25 and is pinned by this test:
 *
 * Anthropic (Messages API, claude-api reference, thinking/sampling table): sampling parameters
 * (`temperature`/`top_p`/`top_k`) are "Removed - 400" on Claude Fable 5 / 5.1, Opus 5.5, Opus 5,
 * Opus 4.8 / 4.7 and Sonnet 5; "Allowed" on Opus 4.6, Sonnet 4.6 and Haiku 4.5. The token limit
 * is always `max_tokens`. Unknown Anthropic model → no temperature (spec 2.2: safe default).
 *
 * OpenAI (developers.openai.com): API reference, Chat Completions `max_tokens`: "This value is
 * now deprecated in favor of max_completion_tokens, and is not compatible with o-series models".
 * Latest-model guide: "When reasoning effort is not none, remove temperature, top_p, and
 * top_logprobs". The adapter sends no reasoning_effort, so the model default applies; for the
 * reasoning models below it is either not `none` or `none` is unsupported (gpt-6-astra, o-series),
 * and gpt-5.4 (default `none`) is treated the same way for safety. Reasoning models = model pages
 * listing "Reasoning token support": gpt-6-astra/sol/luna, gpt-5.6(/-terra/-luna), gpt-5.5,
 * gpt-5.4, gpt-5.2, gpt-5.1, gpt-5(/-mini/-nano), o1, o3(/-mini/-pro), o4-mini; dated snapshots
 * (`o3-2025-04-16`) are the same model. Anything else on an OpenAI-compatible endpoint →
 * `max_tokens` + temperature (spec 2.2).
 */
class ModelCapabilitiesTest extends TestCase {
    private ModelCapabilities $caps;

    protected function setUp(): void {
        $this->caps = new ModelCapabilities();
    }

    /** @return array<string, array{string}> */
    public static function anthropicWithoutTemperature(): array {
        return [
            'opus 5' => ['claude-opus-5'],
            'opus 5.5' => ['claude-opus-5-5'],
            'sonnet 5' => ['claude-sonnet-5'],
            'fable 5' => ['claude-fable-5'],
            'fable 5.1' => ['claude-fable-5-1'],
            'opus 4.7' => ['claude-opus-4-7'],
            'opus 4.8' => ['claude-opus-4-8'],
            'unknown future model' => ['claude-opus-6'],
            'unknown fable variant' => ['claude-fable-5-2'],
            'empty model' => [''],
        ];
    }

    /** @dataProvider anthropicWithoutTemperature */
    public function testAnthropicModelsRejectingSamplingGetNoTemperature(string $model): void {
        $this->assertSame(['temperature' => false, 'tokenParam' => 'max_tokens'],
            $this->caps->forModel('anthropic', $model));
    }

    /** @return array<string, array{string}> */
    public static function anthropicWithTemperature(): array {
        return [
            'opus 4.6' => ['claude-opus-4-6'],
            'sonnet 4.6' => ['claude-sonnet-4-6'],
            'haiku 4.5' => ['claude-haiku-4-5'],
        ];
    }

    /** @dataProvider anthropicWithTemperature */
    public function testAnthropicModelsAllowingSamplingKeepTemperature(string $model): void {
        $this->assertSame(['temperature' => true, 'tokenParam' => 'max_tokens'],
            $this->caps->forModel('anthropic', $model));
    }

    public function testAnthropicMatchingIsExactNotByPrefix(): void {
        // "claude-opus-4-6-custom" is not the verified model; unknown → safe default.
        $this->assertFalse($this->caps->forModel('anthropic', 'claude-opus-4-6-custom')['temperature']);
        $this->assertTrue($this->caps->forModel('anthropic', ' Claude-Haiku-4-5 ')['temperature']);
    }

    /** @return array<string, array{string}> */
    public static function openAiReasoningModels(): array {
        $ids = ['gpt-6-astra', 'gpt-6-sol', 'gpt-6-luna', 'gpt-5.6', 'gpt-5.6-terra', 'gpt-5.6-luna',
            'gpt-5.5', 'gpt-5.4', 'gpt-5.2', 'gpt-5.1', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
            'o1', 'o3', 'o3-mini', 'o3-pro', 'o4-mini', 'o3-2025-04-16', 'gpt-5-2025-08-07'];
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [$id];
        }
        return $out;
    }

    /** @dataProvider openAiReasoningModels */
    public function testOpenAiReasoningModelsUseMaxCompletionTokensWithoutTemperature(string $model): void {
        $this->assertSame(['temperature' => false, 'tokenParam' => 'max_completion_tokens'],
            $this->caps->forModel('openai_compatible', $model));
    }

    /** @return array<string, array{string}> */
    public static function openAiCompatibleStandardModels(): array {
        return [
            'gpt-4o' => ['gpt-4o'],
            'gpt-4.1' => ['gpt-4.1'],
            'mistral' => ['mistral-large-latest'],
            'llama on ionos' => ['meta-llama/Llama-3.3-70B-Instruct'],
            'openrouter-prefixed id is not guessed' => ['openai/o3'],
            'no family heuristic' => ['o3-turbo'],
            'no family heuristic gpt-5' => ['gpt-5-custom-finetune'],
            'malformed snapshot' => ['o3-2025-4-16'],
            'empty' => [''],
        ];
    }

    /** @dataProvider openAiCompatibleStandardModels */
    public function testOtherOpenAiCompatibleModelsUseStandardParameters(string $model): void {
        $this->assertSame(['temperature' => true, 'tokenParam' => 'max_tokens'],
            $this->caps->forModel('openai_compatible', $model));
    }

    public function testNonChatCompletionProvidersGetStandardParameters(): void {
        foreach (['gemini', 'ollama', 'disabled', 'unknown'] as $provider) {
            $this->assertSame(['temperature' => true, 'tokenParam' => 'max_tokens'],
                $this->caps->forModel($provider, 'o3'), $provider);
        }
    }
}
