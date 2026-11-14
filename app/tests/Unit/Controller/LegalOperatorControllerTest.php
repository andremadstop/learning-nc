<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\LegalOperatorController;
use OCA\Learning\Service\OperatorInfo;
use OCA\Learning\Service\ThemingLegalLinkSync;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-11 (high): the operator named in the imprint and privacy statement is an admin setting
 * instead of the app author's company hard-coded in privacy-info.json. Only an admin may read the
 * form or change it; a signed-in user reads the public operator fields for the privacy section of
 * the personal settings, and nothing else.
 */
#[AllowMockObjectsWithoutExpectations]
class LegalOperatorControllerTest extends TestCase {
    /** @var array<string, string> */
    private array $appValues = [];
    /** @var array<string, string> theming app values (the footer links) */
    private array $theming = [];

    private function controller(): LegalOperatorController {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $app === 'theming'
                ? ($this->theming[$key] ?? $default)
                : ($this->appValues[$key] ?? $default)
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, $value): void {
                if ($app === 'theming') {
                    $this->theming[$key] = (string)$value;
                    return;
                }
                $this->appValues[$key] = (string)$value;
            }
        );
        $config->method('deleteAppValue')->willReturnCallback(
            function (string $app, string $key): void {
                unset($this->appValues[$key]);
            }
        );
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(
            static fn (string $route): string => 'https://cloud.example/' . $route
        );
        $operator = new OperatorInfo($config);
        return new LegalOperatorController('learning', $this->createMock(IRequest::class), $operator,
            new ThemingLegalLinkSync($config, $urls, $operator), $this->createMock(LoggerInterface::class));
    }

    public function testAValidEntryIsStoredAndReturned(): void {
        $resp = $this->controller()->save('Musterschule e. V.', "Schulweg 1\n12345 Musterstadt", '', 'kontakt@musterschule.example', '');

        $this->assertSame(200, $resp->getStatus());
        $this->assertTrue($resp->getData()['configured']);
        $this->assertSame('Musterschule e. V.', $resp->getData()['operator']['name']);
        $this->assertSame('Musterschule e. V.', $this->appValues['operator_name']);
        $this->assertSame('kontakt@musterschule.example', $this->appValues['operator_email']);

        $read = $this->controller()->get()->getData();
        $this->assertTrue($read['configured']);
        $this->assertSame("Schulweg 1\n12345 Musterstadt", $read['operator']['address']);
    }

    public function testAPartialEntryIsRefusedWithTheFieldsAndWritesNothing(): void {
        $resp = $this->controller()->save('Musterschule e. V.', '', '', '', '');

        $this->assertSame(400, $resp->getStatus());
        $this->assertSame(['address' => 'required', 'email' => 'required'], $resp->getData()['fields']);
        $this->assertSame([], $this->appValues);
    }

    public function testAnInvalidEmailIsRefusedAndKeepsTheStoredDetails(): void {
        $this->controller()->save('Musterschule e. V.', 'Schulweg 1', '', 'kontakt@musterschule.example', '');

        $resp = $this->controller()->save('Andere Schule', 'Weg 2', '', 'keine-adresse', '');

        $this->assertSame(400, $resp->getStatus());
        $this->assertSame(['email' => 'invalid'], $resp->getData()['fields']);
        $this->assertSame('Musterschule e. V.', $this->appValues['operator_name']);
    }

    public function testAValidEntryFillsTheEmptyFooterLinks(): void {
        // Audit #206: no longer synced on every request, so saving the operator must do it.
        $this->theming = ['imprintUrl' => 'https://schule.example/impressum'];

        $this->controller()->save('Musterschule e. V.', 'Schulweg 1', '', 'kontakt@musterschule.example', '');

        $this->assertSame([
            'imprintUrl' => 'https://schule.example/impressum',
            'privacyUrl' => 'https://cloud.example/learning.page.privacy',
        ], $this->theming);
    }

    public function testARefusedEntryWritesNoFooterLink(): void {
        $this->controller()->save('Musterschule e. V.', '', '', '', '');

        $this->assertSame([], $this->theming);
    }

    public function testSavingAllFieldsEmptyRemovesTheOperator(): void {
        $this->controller()->save('Musterschule e. V.', 'Schulweg 1', '', 'kontakt@musterschule.example', '');

        $resp = $this->controller()->save('', '', '', '', '');

        $this->assertSame(200, $resp->getStatus());
        $this->assertFalse($resp->getData()['configured']);
        $this->assertSame([], $this->appValues);
    }

    public function testShowGivesOnlyTheOperatorFieldsOrNull(): void {
        $this->assertSame(['operator' => null], $this->controller()->show()->getData());

        $this->controller()->save('Musterschule e. V.', 'Schulweg 1', 'VR 1', 'kontakt@musterschule.example', 'Erika Muster');
        $this->appValues['ai_provider'] = 'gemini'; // unrelated app value must not leak

        $this->assertSame(['operator' => [
            'name' => 'Musterschule e. V.',
            'address' => 'Schulweg 1',
            'register' => 'VR 1',
            'email' => 'kontakt@musterschule.example',
            'content_responsible' => 'Erika Muster',
        ]], $this->controller()->show()->getData());
    }

    /** @return list<string> */
    private static function attributeNames(\ReflectionMethod $m): array {
        return array_map(static fn (\ReflectionAttribute $a) => $a->getName(), $m->getAttributes());
    }

    public function testReadingAndChangingTheFormIsAdminOnly(): void {
        foreach (['get', 'save'] as $method) {
            $m = new \ReflectionMethod(LegalOperatorController::class, $method);
            $doc = (string)$m->getDocComment();
            $this->assertStringNotContainsString('@NoAdminRequired', $doc, $method);
            $this->assertStringNotContainsString('@PublicPage', $doc, $method);
            $this->assertStringNotContainsString('@NoCSRFRequired', $doc, $method);
            $this->assertNotContains('OCP\AppFramework\Http\Attributes\NoAdminRequired', self::attributeNames($m), $method);
            $this->assertNotContains('OCP\AppFramework\Http\Attributes\PublicPage', self::attributeNames($m), $method);
        }
    }

    public function testShowNeedsASignedInUserButNoAdmin(): void {
        $m = new \ReflectionMethod(LegalOperatorController::class, 'show');
        $doc = (string)$m->getDocComment();

        $this->assertStringContainsString('@NoAdminRequired', $doc);
        $this->assertStringNotContainsString('@PublicPage', $doc);
    }

    public function testRoutesAreRegistered(): void {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $found = [];
        foreach ($routes['routes'] as $route) {
            if (str_starts_with($route['name'], 'legalOperator#')) {
                $found[$route['name']] = $route['verb'] . ' ' . $route['url'];
            }
        }

        $this->assertSame([
            'legalOperator#get' => 'GET /api/settings/admin/operator',
            'legalOperator#save' => 'PUT /api/settings/admin/operator',
            'legalOperator#show' => 'GET /api/legal/operator',
        ], $found);
    }
}
