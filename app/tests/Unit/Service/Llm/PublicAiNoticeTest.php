<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\PublicAiNotice;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 D6: the public privacy page (no login) says who receives data and where, but must never
 * publish the address of a server the admin runs.
 */
class PublicAiNoticeTest extends TestCase {
    private function ollama(string $url, bool $local): LlmConnection {
        return LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => $url, 'model' => 'llama3',
            'allowInternalNetwork' => true, 'processingLocal' => $local,
        ]);
    }

    public function testGeminiIsNamedWithItsRegion(): void {
        $this->assertSame(
            ['active' => true, 'provider' => 'Google Gemini', 'region' => 'us'],
            PublicAiNotice::describe(ConnectionStore::legacyGemini(true))
        );
    }

    public function testSwitchedOffAiHasNoRecipient(): void {
        $this->assertSame(
            ['active' => false, 'provider' => '', 'region' => ''],
            PublicAiNotice::describe(ConnectionStore::legacyGemini(false))
        );
        $this->assertSame(
            ['active' => false, 'provider' => '', 'region' => ''],
            PublicAiNotice::describe(LlmConnection::fromArray(['provider' => 'disabled']))
        );
    }

    public function testALocalOllamaServerIsDescribedWithoutItsAddress(): void {
        $notice = PublicAiNotice::describe($this->ollama('http://10.0.5.9:11434', true));

        $this->assertTrue($notice['active']);
        $this->assertSame('local', $notice['region']);
        $this->assertStringContainsString('Ollama', $notice['provider']);
        $this->assertStringNotContainsString('10.0.5.9', json_encode($notice, JSON_THROW_ON_ERROR));
    }

    public function testAnOllamaServerOfUnknownLocationIsUnknownRegion(): void {
        $notice = PublicAiNotice::describe($this->ollama('http://ollama.example.org:11434', false));

        $this->assertTrue($notice['active']);
        $this->assertSame('unknown', $notice['region']);
        $this->assertStringNotContainsString('example.org', json_encode($notice, JSON_THROW_ON_ERROR));
    }
}
