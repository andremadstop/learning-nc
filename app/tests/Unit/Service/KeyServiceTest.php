<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\CertKeyMapper;
use OCA\Learning\Service\EncryptionService;
use OCA\Learning\Service\KeyService;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * KeyService — sodium keygen + encrypt-at-rest + rotation (155-02).
 *
 * Four decisive behaviours (CERT-01/03/04):
 *  1. encrypt-at-rest      — stored secret_key_enc is ciphertext, never plaintext; round-trips to the 64-byte secret.
 *  2. rotation-preserves   — rotate() retires (never deletes) the old key; both stay non-revoked.
 *  3. no-double-active     — init() on an existing active key throws (must use rotate()).
 *  4. hard-error-on-decrypt— getActiveSigningMaterial() throws if decrypt yields non-64-byte material
 *                            (defeats EncryptionService's silent plaintext fallback).
 */
class KeyServiceTest extends TestCase {

    private function makeMapper(): CertKeyMapper {
        // In-memory fake: tracks insert/update so rotation (retire-not-delete) is observable.
        return new class extends CertKeyMapper {
            /** @var CertKey[] */
            public array $store = [];
            private int $nextId = 1;

            public function __construct() {
                // Skip parent (needs IDBConnection) — pure in-memory.
            }

            public function insert($entity): CertKey {
                $entity->setId($this->nextId++);
                $this->store[] = $entity;
                return $entity;
            }

            public function update($entity): CertKey {
                // Entity is held by reference in $store; status change is already reflected.
                return $entity;
            }

            public function findActive(): ?CertKey {
                $active = array_values(array_filter($this->store, fn(CertKey $k) => $k->getStatus() === 'active'));
                return $active === [] ? null : end($active);
            }

            /** @return CertKey[] */
            public function findAllNonRevoked(): array {
                return array_values(array_filter($this->store, fn(CertKey $k) => $k->getStatus() !== 'revoked'));
            }
        };
    }

    /**
     * Reversible transform standing in for ICrypto — encrypt prepends a marker so the
     * stored value is provably != the plaintext, decrypt strips it back.
     */
    private function makeEncryption(): EncryptionService {
        $enc = $this->createMock(EncryptionService::class);
        $enc->method('encrypt')->willReturnCallback(
            fn(?string $p) => $p === null ? null : 'ENC::' . $p
        );
        $enc->method('decrypt')->willReturnCallback(
            fn(?string $c) => $c === null ? null : (str_starts_with($c, 'ENC::') ? substr($c, 5) : $c)
        );
        return $enc;
    }

    private function makeService(CertKeyMapper $mapper, ?EncryptionService $enc = null): KeyService {
        return new KeyService(
            $mapper,
            $enc ?? $this->makeEncryption(),
            $this->createMock(IURLGenerator::class),
            $this->makeTxDb($mapper)
        );
    }

    /**
     * A transaction-simulating IDBConnection wired to the in-memory mapper: beginTransaction snapshots
     * $mapper->store, rollBack restores it. This lets rotate()'s atomic insert-new+retire-old be tested
     * — a rollback genuinely undoes the new-key insert, exactly as a real DB transaction would.
     *
     * Hand-rolled (not createMock) because the test-runtime IDBConnection stub doesn't declare the
     * transaction methods; an anonymous class can add them without touching the shared stub.
     */
    private function makeTxDb(object $mapper): IDBConnection {
        return new class($mapper) implements IDBConnection {
            private object $mapper;
            /** @var CertKey[]|null */
            private ?array $snapshot = null;

            public function __construct(object $mapper) {
                $this->mapper = $mapper;
            }

            public function beginTransaction(): void {
                $this->snapshot = $this->mapper->store;
            }

            public function commit(): void {
            }

            public function rollBack(): void {
                if ($this->snapshot !== null) {
                    $this->mapper->store = $this->snapshot;
                }
            }

            // Stub-interface members (unused by KeyService) — present so the anon class is concrete.
            public function getQueryBuilder() {
            }

            public function executeQuery(string $sql, array $params = []) {
            }

            public function escapeLikeParameter(string $input): string {
                return $input;
            }
        };
    }

    public function testInitEncryptsSecretAtRest(): void {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not loaded');
        }
        $mapper = $this->makeMapper();
        $service = $this->makeService($mapper);

        $key = $service->init();

        // Stored value is ciphertext: carries the encrypt marker, not the raw base64 secret.
        $stored = $key->getSecretKeyEnc();
        $this->assertStringStartsWith('ENC::', $stored);
        $this->assertSame('active', $key->getStatus());
        $this->assertNotSame('', $key->getKeyId());
        $this->assertSame($key->getKeyId(), $key->getPublicKeyB64u());

        // Round-trip: decrypting yields exactly the 64-byte Ed25519 secret key.
        $material = $service->getActiveSigningMaterial();
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($material['secret']));
        // And the stored ciphertext is NOT the plaintext base64 of that secret.
        $this->assertNotSame(base64_encode($material['secret']), $stored);
    }

    public function testRotationRetiresOldKeyAndKeepsBoth(): void {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not loaded');
        }
        $mapper = $this->makeMapper();
        $service = $this->makeService($mapper);

        $first = $service->init();
        $second = $service->rotate();

        // Old key retired (NOT deleted), new key active, both still resolvable.
        $this->assertSame('retired', $first->getStatus());
        $this->assertSame('active', $second->getStatus());
        $this->assertNotSame($first->getKeyId(), $second->getKeyId());

        $nonRevoked = $mapper->findAllNonRevoked();
        $this->assertCount(2, $nonRevoked);
        $ids = array_map(fn(CertKey $k) => $k->getKeyId(), $nonRevoked);
        $this->assertContains($first->getKeyId(), $ids);
        $this->assertContains($second->getKeyId(), $ids);

        // Exactly ONE active key post-rotation; the old one is retired but still published (FIX 4).
        $activeCount = count(array_filter($nonRevoked, fn(CertKey $k) => $k->getStatus() === 'active'));
        $this->assertSame(1, $activeCount, 'exactly one active key after rotation');
        $this->assertSame($second->getKeyId(), $mapper->findActive()->getKeyId(), 'the new key is the active one');
    }

    public function testHostDidPlainHostUnchanged(): void {
        // CRITICAL: root-domain, default port, no subpath MUST stay the historical form so the
        // 155-02/03 kid derivation and devcloud expectations never drift.
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getBaseUrl')->willReturn('https://example.com');
        $service = new KeyService($this->makeMapper(), $this->makeEncryption(), $url, $this->createMock(IDBConnection::class));

        $this->assertSame('did:web:example.com:apps:learning', $service->hostDid());
    }

    public function testHostDidEncodesPortAndSubpath(): void {
        // Non-default port + webroot → spec-correct did:web with %3A-encoded port and colon-joined path.
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getBaseUrl')->willReturn('https://example.com:8443/nextcloud');
        $service = new KeyService($this->makeMapper(), $this->makeEncryption(), $url, $this->createMock(IDBConnection::class));

        $this->assertSame('did:web:example.com%3A8443:nextcloud:apps:learning', $service->hostDid());
    }

    /**
     * FIX 4 / R6-6: if creating the NEW key fails mid-rotation, the OLD key must stay active —
     * never a zero-active-key window. Here the mapper throws on the SECOND insert (the rotation's
     * new key); rotate() must propagate the error while leaving the incumbent untouched.
     */
    public function testRotateKeepsOldKeyActiveIfNewKeyInsertFails(): void {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not loaded');
        }
        $mapper = new class extends CertKeyMapper {
            /** @var CertKey[] */
            public array $store = [];
            private int $nextId = 1;
            private int $inserts = 0;

            public function __construct() {
            }

            public function insert($entity): CertKey {
                $this->inserts++;
                if ($this->inserts >= 2) {
                    throw new \RuntimeException('simulated DB failure inserting the new active key');
                }
                $entity->setId($this->nextId++);
                $this->store[] = $entity;
                return $entity;
            }

            public function update($entity): CertKey {
                return $entity;
            }

            public function findActive(): ?CertKey {
                $active = array_values(array_filter($this->store, fn(CertKey $k) => $k->getStatus() === 'active'));
                return $active === [] ? null : end($active);
            }
        };
        $service = $this->makeService($mapper);

        $first = $service->init();
        $this->assertSame('active', $first->getStatus());

        try {
            $service->rotate();
            $this->fail('rotate() should have propagated the new-key insert failure');
        } catch (\RuntimeException $e) {
            // expected
        }

        // The old key is STILL active and still the sole active key — no zero-active-key window.
        $this->assertSame('active', $first->getStatus(), 'old key must remain active when new-key creation fails');
        $this->assertSame($first, $mapper->findActive(), 'old key is still the active key after a failed rotation');
    }

    /**
     * FIX R6-6 (atomicity): if the retire-UPDATE fails mid-rotation, the whole transaction rolls back —
     * the new-key insert is undone too, so exactly ONE active key survives: the ORIGINAL. This closes
     * the "two active keys" window a non-atomic failed retire would leave.
     */
    public function testRotateRollsBackToOriginalWhenRetireUpdateFails(): void {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not loaded');
        }
        $mapper = new class extends CertKeyMapper {
            /** @var CertKey[] */
            public array $store = [];
            private int $nextId = 1;

            public function __construct() {
            }

            public function insert($entity): CertKey {
                $entity->setId($this->nextId++);
                $this->store[] = $entity;
                return $entity;
            }

            public function update($entity): CertKey {
                throw new \RuntimeException('simulated DB failure retiring the old key');
            }

            public function findActive(): ?CertKey {
                $active = array_values(array_filter($this->store, fn(CertKey $k) => $k->getStatus() === 'active'));
                return $active === [] ? null : end($active);
            }

            /** @return CertKey[] */
            public function findAllNonRevoked(): array {
                return array_values(array_filter($this->store, fn(CertKey $k) => $k->getStatus() !== 'revoked'));
            }
        };
        $service = $this->makeService($mapper);

        $first = $service->init();

        try {
            $service->rotate();
            $this->fail('rotate() must propagate the retire-update failure');
        } catch (\RuntimeException $e) {
            // expected — transaction rolled back
        }

        // Rolled back: the new-key insert was undone, the incumbent is the SOLE surviving active key.
        $active = array_values(array_filter($mapper->store, fn(CertKey $k) => $k->getStatus() === 'active'));
        $this->assertCount(1, $active, 'exactly one active key after a rolled-back rotation');
        $this->assertSame($first->getKeyId(), $active[0]->getKeyId(), 'rotation fully rolled back to the ORIGINAL key');
        $this->assertSame($first, $mapper->findActive(), 'the original key is still the active one');
    }

    public function testInitTwiceThrowsWithoutRotate(): void {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not loaded');
        }
        $service = $this->makeService($this->makeMapper());
        $service->init();

        $this->expectException(\RuntimeException::class);
        $service->init();
    }

    public function testGetActiveSigningMaterialHardErrorsOnBadDecrypt(): void {
        $mapper = $this->makeMapper();

        // Decrypt yields a too-short blob (simulates plaintext-fallback / corruption).
        $enc = $this->createMock(EncryptionService::class);
        $enc->method('encrypt')->willReturnCallback(fn(?string $p) => $p);
        $enc->method('decrypt')->willReturn(base64_encode('not-a-64-byte-key'));

        $service = $this->makeService($mapper, $enc);

        $key = new CertKey();
        $key->setKeyId('kid');
        $key->setPublicKeyB64u('pub');
        $key->setSecretKeyEnc('whatever');
        $key->setStatus('active');
        $key->setCreatedAt(1750000000);
        $mapper->insert($key);

        $this->expectException(\RuntimeException::class);
        $service->getActiveSigningMaterial();
    }
}
