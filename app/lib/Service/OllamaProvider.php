<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;

class OllamaProvider implements LlmProviderInterface {
    private const DEFAULT_URL = 'http://localhost:11434';
    private const DEFAULT_MODEL = 'llama3';

    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function isAvailable(): bool {
        return $this->getBaseUrl() !== '';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generateText(string $prompt, array $context = []): string {
        $model = trim($this->config->getAppValue('learning', 'ai_ollama_model', self::DEFAULT_MODEL));
        return $this->generateAt($this->getBaseUrl(), $model !== '' ? $model : self::DEFAULT_MODEL, $prompt, $context);
    }

    /**
     * Sends to the given host and model. LlmService passes them from the connection snapshot, so
     * the request goes to the recipient the consent was checked against, not to ai_ollama_url.
     *
     * @param array<string, mixed> $context
     */
    public function generateAt(string $baseUrl, string $model, string $prompt, array $context = []): string {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Ollama URL not configured');
        }
        $model = trim($model);
        if ($model === '') {
            throw new \RuntimeException('Ollama model not configured');
        }

        $systemPrompt = trim((string)($context['system_prompt'] ?? ''));
        $maxOutputTokens = max(128, min(4096, (int)($context['max_output_tokens'] ?? 1200)));
        $temperature = isset($context['temperature']) && is_numeric($context['temperature'])
            ? max(0.0, min(1.5, (float)$context['temperature']))
            : 0.7;

        $payload = json_encode([
            'model' => $model,
            'prompt' => $prompt,
            'system' => $systemPrompt,
            'stream' => false,
            'options' => [
                'temperature' => $temperature,
                'num_predict' => $maxOutputTokens,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($baseUrl . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('curl error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('HTTP ' . $httpCode);
        }

        $data = json_decode((string)$response, true);
        $text = trim((string)($data['response'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Empty response from Ollama');
        }

        return $text;
    }

    private function getBaseUrl(): string {
        $raw = trim($this->config->getAppValue('learning', 'ai_ollama_url', self::DEFAULT_URL));
        if ($raw === '') {
            return '';
        }

        $normalized = rtrim($raw, '/');
        if (!filter_var($normalized, FILTER_VALIDATE_URL)) {
            return '';
        }

        return $normalized;
    }
}
