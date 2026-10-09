<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\TelosController;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentRecipientChangedException;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\TelosService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Review B1: the consent endpoints still wrote a bare version through TelosService::saveAiConsent()
 * (no fingerprint, source, time or audit), so an accepted dialog never produced a valid consent,
 * and nothing could revoke. They now go through ConsentService: the client sends back the
 * recipient fingerprint it was shown; the server never fills it in itself.
 */
class TelosControllerConsentTest extends TestCase {
    private ConsentService&MockObject $consent;
    private ConnectionStore&MockObject $store;
    private LlmConnection $gemini;

    protected function setUp(): void {
        $this->consent = $this->createMock(ConsentService::class);
        $this->consent->method('currentVersion')->willReturn('3.0');
        $this->store = $this->createMock(ConnectionStore::class);
        $this->gemini = ConnectionStore::legacyGemini(true);
        $this->store->method('load')->willReturnCallback(fn () => $this->gemini);
    }

    private function controller(?string $uid = 'alice'): TelosController {
        return new TelosController('learning', $this->createMock(IRequest::class), $uid,
            $this->createMock(TelosService::class), $this->createMock(CourseService::class),
            $this->consent, $this->store);
    }

    public function testStatusNamesTheReasonAndTheRecipientToConsentTo(): void {
        $this->consent->method('state')->with('alice', $this->gemini)
            ->willReturn(['valid' => false, 'reason' => 'version', 'source' => 'dialog']);

        $data = $this->controller()->getConsentStatus()->getData();

        $this->assertFalse($data['valid']);
        $this->assertSame('version', $data['reason']);
        $this->assertSame('3.0', $data['current_version']);
        $this->assertTrue($data['ai_enabled']);
        $this->assertSame('Google Gemini', $data['recipient']['name']);
        $this->assertSame($this->gemini->recipientFingerprint(), $data['recipient']['fingerprint']);
        $this->assertNull($data['previous_recipient_name']);
    }

    /** Spec 3.4: what the dialog needs to explain the recipient (third-country notice, intermediary). */
    public function testStatusDescribesTheRecipientAsSpec34Names(): void {
        $this->consent->method('state')->willReturn(['valid' => false, 'reason' => 'never', 'source' => null]);

        $recipient = $this->controller()->getConsentStatus()->getData()['recipient'];

        $this->assertSame('us', $recipient['region']);
        $this->assertFalse($recipient['processing_local']);
        $this->assertFalse($recipient['intermediary']);
        $this->assertSame('third_country', $recipient['warning_key']);
        $this->assertArrayHasKey('dpa_url', $recipient);
    }

    /** B6: "the AI provider was changed from {old} to {new}" needs the old name. */
    public function testStatusAfterARecipientChangeNamesThePreviousRecipient(): void {
        $this->consent->method('state')->willReturn(['valid' => false, 'reason' => 'recipient_changed', 'source' => 'dialog']);
        $this->consent->method('previousRecipientName')->with('alice', $this->gemini)->willReturn('Ollama (ollama.lan)');

        $data = $this->controller()->getConsentStatus()->getData();

        $this->assertSame('recipient_changed', $data['reason']);
        $this->assertSame('Ollama (ollama.lan)', $data['previous_recipient_name']);
        $this->assertSame('Google Gemini', $data['recipient']['name']);
    }

    public function testStatusWithAiSwitchedOffHasNoRecipient(): void {
        $this->gemini = LlmConnection::fromArray(['provider' => 'disabled']);
        $this->consent->method('state')->willReturn(['valid' => false, 'reason' => 'none', 'source' => null]);

        $data = $this->controller()->getConsentStatus()->getData();

        $this->assertFalse($data['ai_enabled']);
        $this->assertNull($data['recipient']);
    }

    public function testSaveGrantsForTheFingerprintTheClientWasShown(): void {
        $fp = $this->gemini->recipientFingerprint();
        $this->consent->expects($this->once())->method('grant')->with('alice', $this->gemini, $fp, '3.0', 'onboarding');

        $resp = $this->controller()->saveConsent('3.0', $fp, 'onboarding');

        $this->assertSame(200, $resp->getStatus());
        $this->assertTrue($resp->getData()['valid']);
    }

    public function testSaveWithoutAFingerprintIsRejectedAndNeverFilledInByTheServer(): void {
        $this->consent->expects($this->never())->method('grant');

        $this->assertSame(400, $this->controller()->saveConsent('3.0', null)->getStatus());
        $this->assertSame(400, $this->controller()->saveConsent('3.0', '')->getStatus());
    }

    public function testSaveForARecipientTheAdminChangedMeanwhileIs409WithTheNewRecipient(): void {
        $this->consent->method('grant')->willThrowException(new ConsentRecipientChangedException($this->gemini->recipientFingerprint()));

        $resp = $this->controller()->saveConsent('3.0', str_repeat('0', 64));

        $this->assertSame(409, $resp->getStatus());
        $this->assertSame('recipient_changed', $resp->getData()['error'], 'spec 3.4: {error: recipient_changed, recipient}');
        $this->assertSame('recipient_changed', $resp->getData()['reason']);
        $this->assertSame($this->gemini->recipientFingerprint(), $resp->getData()['recipient']['fingerprint']);
    }

    public function testSaveWithAnOutdatedVersionOrUnknownSourceIs400(): void {
        $this->consent->method('grant')->willThrowException(new \InvalidArgumentException('Consent version is not current'));

        $this->assertSame(400, $this->controller()->saveConsent('2.0', $this->gemini->recipientFingerprint())->getStatus());
    }

    public function testRevokeEndpointRevokes(): void {
        $this->consent->expects($this->once())->method('revoke')->with('alice');

        $resp = $this->controller()->revokeConsent();

        $this->assertSame(200, $resp->getStatus());
        $this->assertFalse($resp->getData()['valid']);
    }

    public function testEndpointsNeedAUser(): void {
        $this->consent->expects($this->never())->method('grant');
        $this->consent->expects($this->never())->method('revoke');
        $this->assertSame(401, $this->controller(null)->getConsentStatus()->getStatus());
        $this->assertSame(401, $this->controller(null)->saveConsent('3.0', 'x')->getStatus());
        $this->assertSame(401, $this->controller(null)->revokeConsent()->getStatus());
    }

    public function testTheFingerprintlessWriterIsGone(): void {
        $this->assertFalse(method_exists(TelosService::class, 'saveAiConsent'));
    }

    public function testTheRevokeRouteExists(): void {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $found = array_filter($routes['routes'], fn (array $r) => $r['name'] === 'telos#revokeConsent');
        $this->assertCount(1, $found);
        $route = array_values($found)[0];
        $this->assertSame('/api/profile/telos/consent', $route['url']);
        $this->assertSame('DELETE', $route['verb']);
    }
}
