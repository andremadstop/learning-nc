<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * One consent-checked unit of work: the connection snapshot taken at begin(), the user whose
 * data is sent, and why. Immutable, so an admin changing the connection mid-request cannot
 * produce a mixed state (spec 2.1).
 *
 * consentEpoch is the subject's consent_epoch read by LlmGateway::begin(). The gateway refuses to
 * send for a subject when it differs from a fresh read (a revocation in between) or is null (a
 * session that did not come from begin()).
 */
final class LlmSession {
    public function __construct(
        public readonly LlmConnection $connection,
        public readonly ?string $subjectUserId,
        public readonly LlmPurpose $purpose,
        public readonly ?int $consentEpoch = null,
    ) {
        if (($subjectUserId === null || $subjectUserId === '') && $purpose !== LlmPurpose::AdminTest) {
            throw new \InvalidArgumentException('Only admin_test may run without a subject user');
        }
    }

    /**
     * Readable recipient description. DPA links stay null until they are verified against the
     * provider documentation together with the adapters (plan Task A5).
     */
    public function recipient(): LlmRecipientInfo {
        $c = $this->connection;
        $host = strtolower((string)(parse_url($c->baseUrl, PHP_URL_HOST) ?? ''));
        $selfHosted = $c->provider === 'ollama' || $c->preset === 'custom';
        if ($selfHosted) {
            $name = ($c->provider === 'ollama' ? 'Ollama' : 'Custom') . ($host !== '' ? ' (' . $host . ')' : '');
            return $c->processingLocal
                ? new LlmRecipientInfo($name, 'local', true, false, null, null)
                : new LlmRecipientInfo($name, 'unknown', false, false, null, 'unknown_recipient');
        }
        return match (true) {
            $c->provider === 'gemini' => new LlmRecipientInfo('Google Gemini', 'us', false, false, null, 'third_country'),
            $c->provider === 'anthropic' => new LlmRecipientInfo('Anthropic', 'us', false, false, null, 'third_country'),
            $c->preset === 'openai' => new LlmRecipientInfo('OpenAI', 'us', false, false, null, 'third_country'),
            $c->preset === 'mistral' => new LlmRecipientInfo('Mistral AI', 'eu', false, false, null, null),
            $c->preset === 'ionos' => new LlmRecipientInfo('IONOS', 'eu', false, false, null, null),
            $c->preset === 'openrouter' => new LlmRecipientInfo(
                'OpenRouter → ' . ($c->openRouterRouting['order'][0] ?? '?'), 'unknown', false, true, null, 'intermediary'),
            default => new LlmRecipientInfo('', 'unknown', false, false, null, 'unknown_recipient'),
        };
    }
}
