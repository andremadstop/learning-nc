<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

class GeminiProvider implements LlmProviderInterface {
    private const MODEL = 'gemini-2.5-flash';
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent';

    /** Gemini 2.5 Flash pricing (USD per token, as of 2025-05). */
    private const PRICE_INPUT_PER_TOKEN = 0.00000015;   // $0.15 / 1M tokens
    private const PRICE_OUTPUT_PER_TOKEN = 0.0000006;   // $0.60 / 1M tokens (non-thinking)

    private IConfig $config;
    private LoggerInterface $logger;

    public function __construct(IConfig $config, LoggerInterface $logger) {
        $this->config = $config;
        $this->logger = $logger;
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

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $latencyMs = (int)((microtime(true) - $startTime) * 1000);

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logApiMetrics($httpCode, $latencyMs, 0, 0, 'curl_error', $curlError);
            throw new \RuntimeException('curl error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            $this->logApiMetrics($httpCode, $latencyMs, 0, 0, 'http_error');
            throw new \RuntimeException('HTTP ' . $httpCode);
        }

        $data = json_decode((string)$response, true);

        $tokensInput = (int)($data['usageMetadata']['promptTokenCount'] ?? 0);
        $tokensOutput = (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0);

        $text = $this->normalizeModelTextOutput((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));

        if ($text === '') {
            $this->logApiMetrics($httpCode, $latencyMs, $tokensInput, $tokensOutput, 'empty_response');
            throw new \RuntimeException('Empty response from Gemini API');
        }

        $this->logApiMetrics($httpCode, $latencyMs, $tokensInput, $tokensOutput, 'ok');

        return $text;
    }

    private function logApiMetrics(
        int $httpStatus,
        int $latencyMs,
        int $tokensInput,
        int $tokensOutput,
        string $status,
        string $errorDetail = ''
    ): void {
        $costUsd = ($tokensInput * self::PRICE_INPUT_PER_TOKEN)
            + ($tokensOutput * self::PRICE_OUTPUT_PER_TOKEN);

        $context = [
            'metric' => 'gemini_api_call',
            'model' => self::MODEL,
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'tokens_total' => $tokensInput + $tokensOutput,
            'cost_usd' => round($costUsd, 6),
            'status' => $status,
        ];

        if ($errorDetail !== '') {
            $context['error'] = mb_substr($errorDetail, 0, 200);
        }

        $this->logger->info('gemini_api_call', $context);
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
