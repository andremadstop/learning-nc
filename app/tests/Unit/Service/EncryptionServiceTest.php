<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\EncryptionService;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase {
    private EncryptionService $service;
    private ICrypto $crypto;

    protected function setUp(): void {
        $this->crypto = $this->createMock(ICrypto::class);
        $this->service = new EncryptionService($this->crypto);
    }

    public function testEncryptNullReturnsNull(): void {
        $this->assertNull($this->service->encrypt(null));
    }

    public function testEncryptEmptyReturnsEmpty(): void {
        $this->assertSame('', $this->service->encrypt(''));
    }

    public function testEncryptCallsCryptoAndReturnsResult(): void {
        $this->crypto->expects($this->once())
            ->method('encrypt')
            ->with('hello')
            ->willReturn('encrypted_hello');

        $result = $this->service->encrypt('hello');
        $this->assertSame('encrypted_hello', $result);
        $this->assertNotSame('hello', $result);
    }

    public function testDecryptNullReturnsNull(): void {
        $this->assertNull($this->service->decrypt(null));
    }

    public function testDecryptEmptyReturnsEmpty(): void {
        $this->assertSame('', $this->service->decrypt(''));
    }

    public function testDecryptCallsCryptoAndReturnsResult(): void {
        $this->crypto->expects($this->once())
            ->method('decrypt')
            ->with('encrypted_hello')
            ->willReturn('hello');

        $this->assertSame('hello', $this->service->decrypt('encrypted_hello'));
    }

    public function testDecryptFallsBackToRawStringOnException(): void {
        $this->crypto->expects($this->once())
            ->method('decrypt')
            ->with('plaintext-not-encrypted')
            ->willThrowException(new \Exception('Cannot decrypt'));

        $this->assertSame('plaintext-not-encrypted', $this->service->decrypt('plaintext-not-encrypted'));
    }

    public function testRoundTrip(): void {
        $this->crypto->method('encrypt')
            ->with('hello')
            ->willReturn('enc_hello');
        $this->crypto->method('decrypt')
            ->with('enc_hello')
            ->willReturn('hello');

        $encrypted = $this->service->encrypt('hello');
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame('hello', $decrypted);
    }
}
