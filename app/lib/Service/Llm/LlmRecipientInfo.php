<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Human-readable description of who receives the data. Derived from the connection, never
 * stored: the consent binds to the recipient fingerprint, this only explains it.
 */
final class LlmRecipientInfo {
    public function __construct(
        public readonly string $name,
        /** us|eu|unknown|local */
        public readonly string $region,
        public readonly bool $isLocal,
        public readonly bool $intermediary,
        public readonly ?string $dpaUrl,
        public readonly ?string $warningKey,
    ) {
    }
}
