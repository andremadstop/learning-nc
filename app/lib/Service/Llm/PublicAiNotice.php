<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

/**
 * What the public privacy page (no login) may say about the AI recipient: who and in which region,
 * never the address of a server the admin runs (an internal host name or IP must not reach
 * anonymous visitors). Logged-in users get the full recipient from the consent API instead.
 */
final class PublicAiNotice {
    /**
     * @return array{active: bool, provider: string, region: string} region: us|eu|unknown|local
     */
    public static function describe(LlmConnection $c): array {
        if (!$c->enabled || $c->provider === 'disabled') {
            return ['active' => false, 'provider' => '', 'region' => ''];
        }
        if ($c->provider === 'gemini') {
            return ['active' => true, 'provider' => 'Google Gemini', 'region' => 'us'];
        }
        if ($c->provider === 'ollama') {
            return $c->processingLocal
                ? ['active' => true, 'provider' => 'Ollama (Server dieser Lernplattform)', 'region' => 'local']
                : ['active' => true, 'provider' => 'Ollama (vom Betreiber eingetragener Server)', 'region' => 'unknown'];
        }
        // not selectable in 5.7.0 (ConnectionStore::load() turns them off); never name or guess one
        return ['active' => true, 'provider' => 'KI-Dienst (vom Betreiber eingetragen)', 'region' => 'unknown'];
    }
}
