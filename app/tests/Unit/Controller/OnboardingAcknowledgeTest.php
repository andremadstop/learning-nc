<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Codeberg #5: the onboarding wizard only ever recorded "seen" in window.localStorage, so a new
 * browser, a second device or cleared site data brought it back. The server needs its own flag —
 * and it must be set for a skipped wizard too, otherwise moving the gate server-side would trap
 * every skipper in the wizard forever.
 *
 * Deliberately NOT UserTelos::onboarding_completed: TelosService::saveTelos() sets that on every
 * profile save, including a later edit in the settings, so it means "a profile exists" rather
 * than "the intro was seen".
 *
 * @group onboarding-acknowledge
 */
class OnboardingAcknowledgeTest extends TestCase {
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;
    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $configMock;
    private SettingsController $controller;

    protected function setUp(): void {
        $this->requestMock = $this->createMock(IRequest::class);
        $this->configMock = $this->createMock(IConfig::class);
        // Verified against the real signature: appName, request, config, db, keyService,
        // auditCheckpointService, userId. Only config and userId matter for this endpoint.
        $this->controller = new SettingsController(
            'learning',
            $this->requestMock,
            $this->configMock,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            'alice'
        );
    }

    public function testAcknowledgeStoresTheFlag(): void {
        $this->configMock->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'learning', 'onboarding_acknowledged', 'yes');

        $response = $this->controller->acknowledgeOnboarding();

        $this->assertSame(['status' => 'ok'], $response->getData());
    }

    public function testAcknowledgeIsIdempotent(): void {
        $this->configMock->expects($this->exactly(2))
            ->method('setUserValue')
            ->with('alice', 'learning', 'onboarding_acknowledged', 'yes');

        $this->controller->acknowledgeOnboarding();
        $second = $this->controller->acknowledgeOnboarding();

        $this->assertSame(['status' => 'ok'], $second->getData());
    }

    public function testPersonalSettingsExposeTheFlag(): void {
        $this->configMock->method('getUserValue')->willReturnCallback(
            static function (string $uid, string $app, string $key, $default = '') {
                return $key === 'onboarding_acknowledged' ? 'yes' : $default;
            }
        );

        $data = $this->controller->getPersonal()->getData();

        $this->assertArrayHasKey('onboarding_acknowledged', $data);
        $this->assertSame('yes', $data['onboarding_acknowledged']);
    }

    public function testPersonalSettingsDefaultToNotAcknowledged(): void {
        $this->configMock->method('getUserValue')->willReturnArgument(3);

        $data = $this->controller->getPersonal()->getData();

        $this->assertSame('no', $data['onboarding_acknowledged']);
    }
}
