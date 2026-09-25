<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Normalised provider answer plus the metadata the gateway records (never the prompt).
 */
final class LlmResult {
    public function __construct(
        public readonly string $text,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
    ) {
    }
}
