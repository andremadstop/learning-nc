<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

interface LlmProviderInterface {
    public function isAvailable(): bool;

    /**
     * @param array<string, mixed> $context
     */
    public function generateText(string $prompt, array $context = []): string;
}
