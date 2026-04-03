<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;

class LlmService {
    private IConfig $config;
    private GeminiProvider $geminiProvider;
    private OllamaProvider $ollamaProvider;

    public function __construct(
        IConfig $config,
        GeminiProvider $geminiProvider,
        OllamaProvider $ollamaProvider
    ) {
        $this->config = $config;
        $this->geminiProvider = $geminiProvider;
        $this->ollamaProvider = $ollamaProvider;
    }

    public function getProviderId(): string {
        $provider = strtolower(trim($this->config->getAppValue('learning', 'ai_provider', 'gemini')));
        return in_array($provider, ['gemini', 'ollama', 'disabled'], true) ? $provider : 'gemini';
    }

    public function isEnabled(): bool {
        return $this->config->getAppValue('learning', 'ai_enabled', 'no') === 'yes'
            && $this->getProviderId() !== 'disabled';
    }

    public function isAvailable(): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $provider = $this->resolveProvider();
        return $provider !== null && $provider->isAvailable();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generateText(string $prompt, array $context = []): string {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('AI provider is disabled');
        }

        $provider = $this->resolveProvider();
        if ($provider === null) {
            throw new \RuntimeException('No AI provider configured');
        }

        if (!$provider->isAvailable()) {
            if ($this->getProviderId() === 'gemini') {
                throw new \RuntimeException('Gemini API key not configured');
            }
            throw new \RuntimeException('Ollama provider not configured');
        }

        return $provider->generateText($prompt, $context);
    }

    private function resolveProvider(): ?LlmProviderInterface {
        return match ($this->getProviderId()) {
            'gemini' => $this->geminiProvider,
            'ollama' => $this->ollamaProvider,
            default => null,
        };
    }
}
