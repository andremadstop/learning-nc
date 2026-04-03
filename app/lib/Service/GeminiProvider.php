<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;

class GeminiProvider implements LlmProviderInterface {
    private const MODEL = 'gemini-2.5-flash';
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent';

    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function isAvailable(): bool {
        return trim($this->config->getAppValue('learning', 'gemini_api_key', '')) !== '';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generateText(string $prompt, array $context = []): string {
        $apiKey = trim($this->config->getAppValue('learning', 'gemini_api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Gemini API key not configured');
        }

        $systemPrompt = trim((string)($context['system_prompt'] ?? ''));
        $maxOutputTokens = max(128, min(4096, (int)($context['max_output_tokens'] ?? 1200)));
        $temperature = isset($context['temperature']) && is_numeric($context['temperature'])
            ? max(0.0, min(1.5, (float)$context['temperature']))
            : 0.7;

        $payload = json_encode([
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxOutputTokens,
                'candidateCount' => 1,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
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
        $text = $this->normalizeModelTextOutput((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));

        if ($text === '') {
            throw new \RuntimeException('Empty response from Gemini API');
        }

        return $text;
    }

    private function normalizeModelTextOutput(string $text): string {
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', trim($text));
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        $normalized = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function (array $matches): string {
            $decoded = json_decode('"\\u' . $matches[1] . '"', true);
            return is_string($decoded) ? $decoded : $matches[0];
        }, $normalized) ?? $normalized;

        if ($this->looksLikeMojibake($normalized)) {
            $converted = mb_convert_encoding($normalized, 'Windows-1252', 'UTF-8');
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8') && !$this->looksLikeMojibake($converted)) {
                return trim($converted);
            }
        }

        return trim($normalized);
    }

    private function looksLikeMojibake(string $text): bool {
        return preg_match('/(?:Ã.|â.|Â.|Ð.|Ñ.)/u', $text) === 1;
    }
}
