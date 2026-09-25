<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * A failed model call. The message never contains the provider's response body; a redacted
 * short text for administrators goes into $adminDetail and is only shown by the connection test.
 *
 * category: auth|rate_limit|overloaded|refusal|timeout|empty|config|bad_request|network|disabled|consent
 */
class LlmProviderException extends \RuntimeException {
    public function __construct(
        public readonly string $category,
        string $message = '',
        public readonly string $adminDetail = '',
    ) {
        parent::__construct($message !== '' ? $message : $category);
    }
}
