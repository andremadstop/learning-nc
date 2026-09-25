<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * One prompt for one provider call. Immutable.
 */
final class LlmRequest {
    public function __construct(
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly ?float $temperature = null,
        public readonly int $maxOutputTokens = 1200,
    ) {
    }
}
