<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Db\CertKey;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Codeberg #8: on managed hosting (Hetzner Storage Share) there is no occ, so the issuer key
 * could not be created and certificates could never be enabled. initCertIssuer() is the web
 * twin of `occ learning:cert:init-issuer` — init only, never a replacement of an existing key.
 */
class InitCertIssuerTest extends TestCase {

    private function makeController(KeyService $keyService, ?AuditCheckpointService $checkpoints = null): SettingsController {
        return new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(IConfig::class),
            $this->createMock(IDBConnection::class),
            $keyService,
            $checkpoints ?? $this->createMock(AuditCheckpointService::class),
            'admin'
        );
    }

    /**
     * Events that waited for the missing key are checkpointed right away — otherwise the overdue
     * warning would blame cron for up to a week after the admin fixed the real cause.
     */
    public function testCheckpointsPendingEventsRightAfterKeyCreation(): void {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hasActiveKey')->willReturn(false);
        $keyService->method('init')->willReturn(new CertKey());
        $checkpoints = $this->createMock(AuditCheckpointService::class);
        $checkpoints->expects($this->once())->method('createCheckpoint');

        $response = $this->makeController($keyService, $checkpoints)->initCertIssuer();

        $this->assertSame(200, $response->getStatus());
    }

    /** A failing checkpoint does not turn a successful key creation into an error. */
    public function testCheckpointFailureStillReportsSuccess(): void {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hasActiveKey')->willReturn(false);
        $keyService->method('init')->willReturn(new CertKey());
        $checkpoints = $this->createMock(AuditCheckpointService::class);
        $checkpoints->method('createCheckpoint')->willThrowException(new \RuntimeException('db down'));

        $response = $this->makeController($keyService, $checkpoints)->initCertIssuer();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(true, $response->getData()['cert_issuer_ready']);
    }

    /** No key was made, so there is nothing to checkpoint with. */
    public function testNoCheckpointAttemptWhenKeyAlreadyExists(): void {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hasActiveKey')->willReturn(true);
        $checkpoints = $this->createMock(AuditCheckpointService::class);
        $checkpoints->expects($this->never())->method('createCheckpoint');

        $this->makeController($keyService, $checkpoints)->initCertIssuer();
    }

    public function testCreatesKeyWhenNoneExists(): void {
        $key = new CertKey();
        $key->setKeyId('kid-abc');
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hasActiveKey')->willReturn(false);
        $keyService->expects($this->once())->method('init')->willReturn($key);
        $keyService->method('hostDid')->willReturn('did:web:cloud.example:apps:learning');

        $response = $this->makeController($keyService)->initCertIssuer();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(true, $response->getData()['cert_issuer_ready']);
        $this->assertSame('kid-abc', $response->getData()['key_id']);
    }

    /** An existing key is never touched: it may already have signed certificates. */
    public function testRefusesWithConflictWhenKeyExists(): void {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hasActiveKey')->willReturn(true);
        $keyService->expects($this->never())->method('init');

        $response = $this->makeController($keyService)->initCertIssuer();

        $this->assertSame(409, $response->getStatus());
        $this->assertSame(true, $response->getData()['cert_issuer_ready']);
    }

    /** The race loser (double-click) gets 409, not a 500 that reads like breakage. */
    public function testLostLockRaceIsConflict(): void {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hasActiveKey')->willReturn(false);
        $keyService->method('init')->willThrowException(new \RuntimeException('Issuer key initialisation is already in progress'));

        $response = $this->makeController($keyService)->initCertIssuer();

        $this->assertSame(409, $response->getStatus());
    }

    /** Missing ext-sodium surfaces as a readable 500, not an exception dump. */
    public function testMissingSodiumIsReadableError(): void {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hasActiveKey')->willReturn(false);
        $keyService->method('init')->willThrowException(new \RuntimeException('ext-sodium is required to generate issuer signing keys'));

        $response = $this->makeController($keyService)->initCertIssuer();

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('ext-sodium is required to generate issuer signing keys', $response->getData()['error']);
        $this->assertSame(false, $response->getData()['cert_issuer_ready']);
    }
}
