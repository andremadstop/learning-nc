<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;

/**
 * The 5.5 transport, until B3 routes every call through LlmGateway.
 *
 * Provider, host and model come from the connection snapshot (ConnectionStore::load()), the same
 * object the consent check uses, and never from the legacy keys ai_provider / ai_ollama_*: data
 * may only reach the recipient the user consented to. Each call loads the snapshot once.
 */
class LlmService {
    public function __construct(
        private GeminiProvider $geminiProvider,
        private OllamaProvider $ollamaProvider,
        private ConnectionStore $connectionStore,
    ) {
    }

    /** gemini, ollama or disabled — as the snapshot says. */
    public function getProviderId(): string {
        return $this->connectionStore->load()->provider;
    }

    public function isEnabled(): bool {
        return self::isActive($this->connectionStore->load());
    }

    public function isAvailable(): bool {
        $c = $this->connectionStore->load();
        if (!self::isActive($c)) {
            return false;
        }
        return match ($c->provider) {
            'gemini' => self::isLegacyGeminiEndpoint($c) && $this->geminiProvider->isAvailable(),
            'ollama' => true,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generateText(string $prompt, array $context = []): string {
        $c = $this->connectionStore->load();
        if (!self::isActive($c)) {
            throw new \RuntimeException('AI provider is disabled');
        }

        if ($c->provider === 'gemini') {
            // GeminiProvider only reaches its fixed endpoint; a snapshot naming another one would
            // send to a recipient the consent does not cover.
            if (!self::isLegacyGeminiEndpoint($c)) {
                throw new \RuntimeException('The configured Gemini endpoint is not supported by this transport');
            }
            if (!$this->geminiProvider->isAvailable()) {
                throw new \RuntimeException('Gemini API key not configured');
            }
            return $this->geminiProvider->generateText($prompt, $context);
        }
        if ($c->provider === 'ollama') {
            return $this->ollamaProvider->generateAt($c->baseUrl, $c->model, $prompt, $context);
        }
        throw new \RuntimeException('No AI provider configured');
    }

    private static function isActive(LlmConnection $c): bool {
        return $c->enabled && $c->provider !== 'disabled';
    }

    private static function isLegacyGeminiEndpoint(LlmConnection $c): bool {
        return hash_equals(ConnectionStore::legacyGemini(true)->connectionFingerprint(), $c->connectionFingerprint());
    }
}
