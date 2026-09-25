<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * The subject has no valid consent for the recipient of the current connection snapshot.
 * Callers must not swallow it in a generic catch (spec 2.4).
 */
final class LlmConsentException extends LlmProviderException {
    public function __construct() {
        parent::__construct('consent', 'consent_required');
    }
}
