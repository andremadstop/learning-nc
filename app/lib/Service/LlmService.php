<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;

/**
 * Whether AI is usable right now, for the UI and early controller answers. Sends nothing: every
 * model call goes through LlmGateway (Task B4 removed the 5.5 transport behind generateText()).
 *
 * Provider and endpoint come from the connection snapshot (ConnectionStore::load()), the object
 * the consent check uses, never from the legacy keys ai_provider / ai_ollama_*.
 */
class LlmService {
    public function __construct(
        private ConnectionStore $connectionStore,
        private ApiKeyStore $keys,
    ) {
    }

    /** gemini, ollama or disabled — as the snapshot says. */
    public function getProviderId(): string {
        return $this->connectionStore->load()->provider;
    }

    public function isEnabled(): bool {
        return self::isActive($this->connectionStore->load());
    }

    /**
     * Switched on, a backend 5.7.0 routes, and what that backend needs: Gemini a key for exactly
     * this endpoint, Ollama nothing.
     */
    public function isAvailable(): bool {
        $c = $this->connectionStore->load();
        if (!self::isActive($c)) {
            return false;
        }
        return match ($c->provider) {
            'gemini' => $this->keys->get($c) !== null,
            'ollama' => true,
            default => false,
        };
    }

    private static function isActive(LlmConnection $c): bool {
        return $c->enabled && $c->provider !== 'disabled';
    }
}
