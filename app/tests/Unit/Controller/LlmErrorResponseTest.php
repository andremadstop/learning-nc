<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\LlmErrorResponse;
use OCA\Learning\Service\Llm\LlmProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit #142: a config/auth failure told the user to ask for "the Gemini API key", whatever
 * provider the instance actually uses.
 */
class LlmErrorResponseTest extends TestCase {
    /** @return array<string, array{string}> */
    public static function configCategories(): array {
        return ['config' => ['config'], 'auth' => ['auth']];
    }

    #[DataProvider('configCategories')]
    public function testTheConfigurationMessageNamesNoProvider(string $category): void {
        $response = LlmErrorResponse::fromException(new LlmProviderException($category), 'generic');

        $this->assertSame(503, $response->getStatus());
        $error = (string)$response->getData()['error'];
        $this->assertStringStartsWith('AI service not configured.', $error);
        $this->assertStringNotContainsStringIgnoringCase('gemini', $error);
    }
}
