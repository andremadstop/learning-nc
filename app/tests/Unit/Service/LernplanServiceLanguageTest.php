<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * The weekly plan is written in the user's content language. Since 5.18.0 French and
 * Ukrainian can be chosen there, so the prompt must name them instead of falling back
 * to German.
 */
class LernplanServiceLanguageTest extends TestCase {
    private function languageName(string $code): string {
        $service = (new \ReflectionClass(LernplanService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(LernplanService::class, 'resolveLanguageName');
        $method->setAccessible(true);
        return $method->invoke($service, $code);
    }

    public function testEveryContentLanguageHasItsOwnName(): void {
        foreach (TranslationService::ALLOWED_LANGS as $code) {
            if ($code === 'de') {
                continue;
            }
            $this->assertNotSame('Deutsch', $this->languageName($code), "content language $code falls back to German");
        }
    }

    public function testFrenchAndUkrainian(): void {
        $this->assertSame('Français', $this->languageName('fr'));
        $this->assertSame('Українська', $this->languageName('uk'));
    }

    public function testUnknownCodeStaysGerman(): void {
        $this->assertSame('Deutsch', $this->languageName('xx'));
    }
}
