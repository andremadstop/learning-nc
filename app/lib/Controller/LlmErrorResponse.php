<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Maps a failed model call to the HTTP answer of the AI generation endpoints.
 *
 * Since the LLM gateway, the exception's message is only its category ('timeout', 'disabled' ...),
 * so the response is chosen by category, never by message text. Neither the category nor the
 * admin detail goes into the response body.
 */
final class LlmErrorResponse {
    private function __construct() {
    }

    public static function fromException(LlmProviderException $e, string $genericError): DataResponse {
        if ($e instanceof LlmConsentException || $e->category === 'consent') {
            return new DataResponse(['error' => 'AI consent required', 'consent_required' => true], Http::STATUS_FORBIDDEN);
        }

        return match ($e->category) {
            'disabled' => new DataResponse(['error' => 'AI feature disabled'], Http::STATUS_SERVICE_UNAVAILABLE),
            'config', 'auth' => new DataResponse(
                ['error' => 'AI service not configured. Please ask your administrator to check the AI provider settings.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            ),
            'rate_limit' => new DataResponse(['error' => 'Rate limit exceeded'], Http::STATUS_TOO_MANY_REQUESTS),
            'network', 'timeout', 'overloaded', 'empty' => new DataResponse(
                ['error' => 'AI service temporarily unavailable. Please try again later.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            ),
            default => new DataResponse(['error' => $genericError], Http::STATUS_INTERNAL_SERVER_ERROR),
        };
    }
}
