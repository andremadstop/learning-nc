<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * Immutable snapshot of the `ai_connection` app config value (spec 2.3).
 *
 * Two fingerprints, with different jobs:
 * - connectionFingerprint() identifies the endpoint (provider, preset, scheme, host, port, path).
 *   API keys are bound to it, so a key never follows the admin to another URL.
 * - recipientFingerprint() identifies who receives user data (endpoint + processingLocal, and for
 *   OpenRouter the model and the one routed provider). User consent is bound to it (spec 3.2).
 * The model name is deliberately not part of either, except for OpenRouter where the model
 * decides the actual recipient.
 */
final class LlmConnection {
    public const PROVIDERS = ['gemini', 'ollama', 'openai_compatible', 'anthropic', 'disabled'];
    public const OPENAI_COMPATIBLE_PRESETS = ['openai', 'mistral', 'ionos', 'openrouter', 'custom'];

    /**
     * @param array{order: list<string>, allowFallbacks: false}|null $openRouterRouting
     * @param array<string, mixed>|null $adminAck
     */
    public function __construct(
        public readonly int $rev,
        public readonly bool $enabled,
        public readonly string $provider,
        public readonly string $preset,
        public readonly string $baseUrl,
        public readonly string $model,
        public readonly bool $processingLocal,
        public readonly bool $allowInternalNetwork,
        public readonly ?array $openRouterRouting,
        public readonly ?array $adminAck,
    ) {
    }

    /**
     * Validating factory. An unknown provider yields a disabled connection (fail closed);
     * an invalid configuration of a known provider throws \InvalidArgumentException.
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self {
        $provider = strtolower(trim(self::str($a['provider'] ?? '')));
        if (!in_array($provider, self::PROVIDERS, true)) {
            $provider = 'disabled';
        }
        $rev = is_int($a['rev'] ?? null) ? max(0, $a['rev']) : 0;
        $processingLocal = ($a['processingLocal'] ?? false) === true;
        $allowInternal = ($a['allowInternalNetwork'] ?? false) === true;
        $baseUrl = trim(self::str($a['baseUrl'] ?? ''));
        $model = trim(self::str($a['model'] ?? ''));
        $adminAck = is_array($a['adminAck'] ?? null) ? $a['adminAck'] : null;

        if ($provider === 'disabled') {
            return new self($rev, false, 'disabled', '', $baseUrl, $model, $processingLocal, $allowInternal, null, $adminAck);
        }

        $preset = '';
        if ($provider === 'openai_compatible') {
            $preset = strtolower(trim(self::str($a['preset'] ?? '')));
            if (!in_array($preset, self::OPENAI_COMPATIBLE_PRESETS, true)) {
                throw new \InvalidArgumentException('Unknown preset for openai_compatible');
            }
        }

        self::validateBaseUrl($baseUrl, $allowInternal);
        if ($model === '') {
            throw new \InvalidArgumentException('Model must not be empty');
        }

        $routing = null;
        if ($preset === 'openrouter') {
            $routing = self::validateOpenRouterRouting($a['openRouterRouting'] ?? null);
        }

        return new self($rev, ($a['enabled'] ?? false) === true, $provider, $preset, $baseUrl, $model,
            $processingLocal, $allowInternal, $routing, $adminAck);
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'rev' => $this->rev,
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'preset' => $this->preset,
            'baseUrl' => $this->baseUrl,
            'model' => $this->model,
            'processingLocal' => $this->processingLocal,
            'allowInternalNetwork' => $this->allowInternalNetwork,
            'openRouterRouting' => $this->openRouterRouting,
            'adminAck' => $this->adminAck,
        ];
    }

    public function connectionFingerprint(): string {
        return self::hashCanonical($this->endpointParts());
    }

    public function recipientFingerprint(): string {
        $parts = $this->endpointParts();
        $parts['processingLocal'] = $this->processingLocal;
        if ($this->preset === 'openrouter' && $this->openRouterRouting !== null) {
            $parts['model'] = $this->model;
            $parts['providerOrder'] = $this->openRouterRouting['order'];
            $parts['allowFallbacks'] = false;
        }
        return self::hashCanonical($parts);
    }

    /** @return array{provider: string, preset: string, scheme: string, host: string, port: int, pathPrefix: string} */
    private function endpointParts(): array {
        $u = parse_url($this->baseUrl);
        $u = is_array($u) ? $u : [];
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        $port = isset($u['port']) ? (int)$u['port'] : ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0));
        return [
            'provider' => $this->provider,
            'preset' => $this->preset,
            'scheme' => $scheme,
            'host' => strtolower((string)($u['host'] ?? '')),
            'port' => $port,
            'pathPrefix' => rtrim((string)($u['path'] ?? ''), '/'),
        ];
    }

    /** @param array<string, mixed> $data */
    private static function hashCanonical(array $data): string {
        return hash('sha256', (string)json_encode(self::ksortRecursive($data), JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function ksortRecursive(array $data): array {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::ksortRecursive($v);
            }
        }
        if (!array_is_list($data)) {
            ksort($data);
        }
        return $data;
    }

    private static function validateBaseUrl(string $url, bool $allowInternal): void {
        $u = parse_url($url);
        if (!is_array($u) || !isset($u['scheme'], $u['host']) || $u['host'] === '') {
            throw new \InvalidArgumentException('Base URL must be an absolute URL');
        }
        $scheme = strtolower($u['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowInternal)) {
            throw new \InvalidArgumentException('Base URL must use https');
        }
        if (isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || isset($u['query'])) {
            throw new \InvalidArgumentException('Base URL must not contain credentials, query or fragment');
        }
    }

    /**
     * OpenRouter is only allowed with a fixed single target provider and no fallbacks,
     * otherwise the recipient the user consents to is not the one that receives the data.
     *
     * @return array{order: list<string>, allowFallbacks: false}
     */
    private static function validateOpenRouterRouting(mixed $routing): array {
        if (!is_array($routing) || !is_array($routing['order'] ?? null) || !array_is_list($routing['order'])
            || count($routing['order']) !== 1 || !is_string($routing['order'][0])
            || trim($routing['order'][0]) === '' || ($routing['allowFallbacks'] ?? null) !== false) {
            throw new \InvalidArgumentException('OpenRouter requires exactly one target provider and allowFallbacks=false');
        }
        return ['order' => [trim($routing['order'][0])], 'allowFallbacks' => false];
    }

    private static function str(mixed $v): string {
        return is_string($v) ? $v : '';
    }
}
