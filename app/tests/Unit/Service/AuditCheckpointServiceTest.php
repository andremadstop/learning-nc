<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 161 (AUDIT-04) — AuditCheckpointService.
 *
 * The load-bearing assertion is the sign→verify roundtrip: a signature produced over the VERBATIM
 * signed_payload bytes must verify with sodium_crypto_sign_verify_detached against the issuer
 * public key (must_haves truth #2). We use a REAL Ed25519 keypair generated in-test, so the crypto
 * is exercised end-to-end, not mocked away.
 */
class AuditCheckpointServiceTest extends TestCase {

    /** @return array{pub: string, sec: string} */
    private function makeKeypair(): array {
        $kp = sodium_crypto_sign_keypair();
        return [
            'pub' => sodium_crypto_sign_publickey($kp),
            'sec' => sodium_crypto_sign_secretkey($kp),
        ];
    }

    /** Build a KeyService mock that hands back the given key material. */
    private function mockKeyService(string $keyId, string $secret): KeyService {
        $certKey = new CertKey();
        $certKey->setKeyId($keyId);

        $keyService = $this->createMock(KeyService::class);
        $keyService->method('getActiveSigningMaterial')
            ->willReturn(['key' => $certKey, 'secret' => $secret]);
        $keyService->method('hostDid')
            ->willReturn('did:web:test.example:apps:learning');
        return $keyService;
    }

    /**
     * Happy path: one signed checkpoint row inserted, and the captured signature verifies against
     * the captured verbatim signed_payload with the issuer public key.
     */
    public function testCreateCheckpointSignsVerifiablePayload(): void {
        $keys = $this->makeKeypair();
        $headHash = str_repeat('a', 64);

        // Builder queue MUST match createCheckpoint()'s issue order:
        //   1) last-checkpoint select  2) chain-tail select  3) window COUNT  4) INSERT
        $lastQb   = new FakeQueryBuilder(new FakeResult());                                  // no prior checkpoint → lastToSeq = 0
        $tailQb   = new FakeQueryBuilder(FakeResult::fromFetch(['seq_num' => 5, 'chain_hash' => $headHash]));
        $countQb  = new FakeQueryBuilder(FakeResult::fromFetchOne(5));
        $insertQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$lastQb, $tailQb, $countQb, $insertQb]);

        $config = $this->createMock(IConfig::class);
        $captured = [];
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$captured) {
                $captured[$key] = $value;
                return true;
            }
        );

        $service = new AuditCheckpointService(
            $this->mockKeyService('test-kid-123', $keys['sec']),
            $db,
            $config,
            $this->createMock(LoggerInterface::class)
        );

        $service->createCheckpoint();

        // Row was inserted into the right table.
        $this->assertSame('insert', $insertQb->operation);
        $this->assertSame('learning_audit_checkpoints', $insertQb->table);

        $values = $insertQb->insertValues;
        $signedPayload = $values['signed_payload']['value'];
        $sigHex        = $values['signature']['value'];

        // signed_payload is valid JSON with the expected window.
        $decoded = json_decode($signedPayload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('audit_checkpoint', $decoded['typ']);
        $this->assertSame(1, $decoded['from_event_id']);
        $this->assertSame(5, $decoded['to_event_id']);
        $this->assertSame($headHash, $decoded['head_hash']);
        $this->assertSame(5, $decoded['event_count']);
        $this->assertSame('test-kid-123', $decoded['key_id']);

        // signature is 128 hex chars (64 raw Ed25519 bytes).
        $this->assertMatchesRegularExpression('/^[a-f0-9]{128}$/', $sigHex);

        // THE roundtrip: verify the captured signature over the VERBATIM signed_payload bytes.
        $this->assertTrue(
            sodium_crypto_sign_verify_detached(hex2bin($sigHex), $signedPayload, $keys['pub']),
            'stored signature must verify against signed_payload with the issuer public key'
        );

        // Column values agree with the signed content (exact-bytes re-verify works).
        $this->assertSame(1, $values['from_event_id']['value']);
        $this->assertSame(5, $values['to_event_id']['value']);
        $this->assertSame($headHash, $values['head_hash']['value']);
        $this->assertSame('test-kid-123', $values['key_id']['value']);
        $this->assertSame($sigHex, $values['signature']['value']);
        $this->assertNull($values['anchor_url']['value']);

        // Liveness pointers were persisted.
        $this->assertArrayHasKey('last_checkpoint_at', $captured);
        $this->assertSame('5', $captured['last_checkpoint_to_event_id']);
    }

    /**
     * The signed head_hash equals the tail event's chain_hash — the checkpoint commits to the
     * exact head of the Phase-160 chain.
     */
    public function testCheckpointHeadHashMatchesChainTail(): void {
        $keys = $this->makeKeypair();
        $headHash = str_repeat('b', 64);

        $lastQb   = new FakeQueryBuilder(new FakeResult());
        $tailQb   = new FakeQueryBuilder(FakeResult::fromFetch(['seq_num' => 12, 'chain_hash' => $headHash]));
        $countQb  = new FakeQueryBuilder(FakeResult::fromFetchOne(12));
        $insertQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$lastQb, $tailQb, $countQb, $insertQb]);

        $config = $this->createMock(IConfig::class);
        $config->method('setAppValue')->willReturn(true);

        $service = new AuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']),
            $db,
            $config,
            $this->createMock(LoggerInterface::class)
        );
        $service->createCheckpoint();

        $decoded = json_decode($insertQb->insertValues['signed_payload']['value'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($headHash, $decoded['head_hash']);
        $this->assertSame(12, $decoded['to_event_id']);
    }

    /**
     * from_event_id continues from the previous checkpoint's to_event_id + 1 (no gap, no overlap).
     */
    public function testCreateCheckpointContinuesFromLastWindow(): void {
        $keys = $this->makeKeypair();

        // Prior checkpoint ended at to_event_id = 5; new tail is at seq 9.
        $lastQb   = new FakeQueryBuilder(FakeResult::fromFetchOne(5));
        $tailQb   = new FakeQueryBuilder(FakeResult::fromFetch(['seq_num' => 9, 'chain_hash' => str_repeat('c', 64)]));
        $countQb  = new FakeQueryBuilder(FakeResult::fromFetchOne(4));
        $insertQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$lastQb, $tailQb, $countQb, $insertQb]);

        $config = $this->createMock(IConfig::class);
        $config->method('setAppValue')->willReturn(true);

        $service = new AuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']),
            $db,
            $config,
            $this->createMock(LoggerInterface::class)
        );
        $service->createCheckpoint();

        $values = $insertQb->insertValues;
        $this->assertSame(6, $values['from_event_id']['value'], 'from_event_id must be lastTo + 1');
        $this->assertSame(9, $values['to_event_id']['value']);
    }

    /**
     * No new events since the last checkpoint → skip entirely (no key access, no insert).
     */
    public function testCreateCheckpointSkipsWhenNoNewEvents(): void {
        // lastToSeq = 7; tail is also at seq 7 → toSeq <= lastToSeq → skip before signing.
        $lastQb = new FakeQueryBuilder(FakeResult::fromFetchOne(7));
        $tailQb = new FakeQueryBuilder(FakeResult::fromFetch(['seq_num' => 7, 'chain_hash' => str_repeat('d', 64)]));
        $db = new FakeDbConnection([$lastQb, $tailQb]);

        $config = $this->createMock(IConfig::class);
        $config->expects($this->never())->method('setAppValue');

        // KeyService must NOT be touched when skipping — expect no getActiveSigningMaterial call.
        $keyService = $this->createMock(KeyService::class);
        $keyService->expects($this->never())->method('getActiveSigningMaterial');

        $service = new AuditCheckpointService(
            $keyService,
            $db,
            $config,
            $this->createMock(LoggerInterface::class)
        );
        $service->createCheckpoint();

        // Only the two SELECT builders were consumed — no INSERT issued.
        $this->assertNull($tailQb->operation);
    }

    /**
     * No compliance events at all (empty chain) → skip.
     */
    public function testCreateCheckpointSkipsWhenChainEmpty(): void {
        $lastQb = new FakeQueryBuilder(new FakeResult());   // no prior checkpoint
        $tailQb = new FakeQueryBuilder(new FakeResult());   // tail fetch → false
        $db = new FakeDbConnection([$lastQb, $tailQb]);

        $config = $this->createMock(IConfig::class);
        $config->expects($this->never())->method('setAppValue');

        $keyService = $this->createMock(KeyService::class);
        $keyService->expects($this->never())->method('getActiveSigningMaterial');

        $service = new AuditCheckpointService(
            $keyService,
            $db,
            $config,
            $this->createMock(LoggerInterface::class)
        );
        $service->createCheckpoint();

        $this->assertSame([], $tailQb->insertValues);
    }

    /**
     * getLivenessStatus() reports the appconfig pointers, counts unanchored events, and flags
     * overdue when the last checkpoint is older than 8 days.
     */
    public function testGetLivenessStatusOverdue(): void {
        $oldTs = time() - (9 * 86400); // 9 days ago → overdue

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default = '') use ($oldTs) {
                return match ($key) {
                    'last_checkpoint_at'           => (string)$oldTs,
                    'last_checkpoint_to_event_id'  => '10',
                    'forgejo_anchor_enabled'       => 'true',
                    'last_anchor_status'           => 'ok',
                    default                        => $default,
                };
            }
        );

        $countQb = new FakeQueryBuilder(FakeResult::fromFetchOne(3));
        $db = new FakeDbConnection([$countQb]);

        $service = new AuditCheckpointService(
            $this->createMock(KeyService::class),
            $db,
            $config,
            $this->createMock(LoggerInterface::class)
        );

        $status = $service->getLivenessStatus();

        $this->assertSame($oldTs, $status['last_checkpoint_at']);
        $this->assertSame(3, $status['events_since_checkpoint']);
        $this->assertTrue($status['anchor_enabled']);
        $this->assertSame('ok', $status['last_anchor_status']);
        $this->assertTrue($status['is_overdue']);
    }

    /**
     * A fresh checkpoint (just now) is not overdue and anchor defaults are reported.
     */
    public function testGetLivenessStatusFresh(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'last_checkpoint_at'          => (string)time(),
                    'last_checkpoint_to_event_id' => '20',
                    default                       => $default,
                };
            }
        );

        $countQb = new FakeQueryBuilder(FakeResult::fromFetchOne(0));
        $db = new FakeDbConnection([$countQb]);

        $service = new AuditCheckpointService(
            $this->createMock(KeyService::class),
            $db,
            $config,
            $this->createMock(LoggerInterface::class)
        );

        $status = $service->getLivenessStatus();

        $this->assertSame(0, $status['events_since_checkpoint']);
        $this->assertFalse($status['anchor_enabled']);
        $this->assertSame('none', $status['last_anchor_status']);
        $this->assertFalse($status['is_overdue']);
    }

    // ── AUDIT-05: Forgejo external anchor (Plan 161-02) ──────────────────────────────────────────

    /** A config mock whose getAppValue is driven by $values and whose setAppValue is captured. */
    private function anchorConfig(array $values, array &$captured): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            function (string $app, string $key, string $default = '') use ($values) {
                return $values[$key] ?? $default;
            }
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$captured) {
                $captured[$key] = $value;
                return true;
            }
        );
        return $config;
    }

    /** Builder queue for a normal mint (no anchor update): [last, tail, count, insert]. */
    private function mintQueue(int $lastInsertId = 1): array {
        return [
            new FakeQueryBuilder(new FakeResult()),                                             // lastToSeq = 0
            new FakeQueryBuilder(FakeResult::fromFetch(['seq_num' => 5, 'chain_hash' => str_repeat('a', 64)])),
            new FakeQueryBuilder(FakeResult::fromFetchOne(5)),
            new FakeQueryBuilder(null, 1, $lastInsertId),                                        // INSERT (lastInsertId)
        ];
    }

    /**
     * OFF by default: when forgejo_anchor_enabled='false' no HTTP call is made and no anchor UPDATE
     * is issued — anchor_url stays NULL on the freshly inserted checkpoint row.
     */
    public function testForgejoAnchorNoOpWhenDisabled(): void {
        $keys = $this->makeKeypair();
        $captured = [];
        $config = $this->anchorConfig(['forgejo_anchor_enabled' => 'false', 'forgejo_token' => 'secret'], $captured);
        $db = new FakeDbConnection($this->mintQueue());

        $service = new TestableAuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']), $db, $config, $this->createMock(LoggerInterface::class)
        );
        $service->createCheckpoint();

        $this->assertSame(0, $service->forgejoCalls, 'disabled anchor must make NO HTTP call');
        $this->assertArrayNotHasKey('last_anchor_status', $captured, 'no anchor status written when OFF');
        // No extra (UPDATE) builder was consumed — exactly the 4 mint builders were issued.
        $this->assertCount(4, $db->issuedBuilders);
        foreach ($db->issuedBuilders as $qb) {
            $this->assertNotSame('update', $qb->operation);
        }
    }

    /**
     * OFF when enabled but no token configured: still no HTTP call, no state change.
     */
    public function testForgejoAnchorNoOpWhenTokenEmpty(): void {
        $keys = $this->makeKeypair();
        $captured = [];
        $config = $this->anchorConfig(['forgejo_anchor_enabled' => 'true', 'forgejo_token' => ''], $captured);
        $db = new FakeDbConnection($this->mintQueue());

        $service = new TestableAuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']), $db, $config, $this->createMock(LoggerInterface::class)
        );
        $service->createCheckpoint();

        $this->assertSame(0, $service->forgejoCalls, 'missing token must make NO HTTP call');
        $this->assertArrayNotHasKey('last_anchor_status', $captured);
        $this->assertCount(4, $db->issuedBuilders);
    }

    /**
     * HTTP 201 success: the returned .content.download_url (the RAW file-bytes endpoint, F4) is written
     * to anchor_url on the checkpoint row (by id) and last_anchor_status='ok' is recorded.
     */
    public function testForgejoAnchorSuccessSetsUrlAndStatusOk(): void {
        $keys = $this->makeKeypair();
        $captured = [];
        $config = $this->anchorConfig([
            'forgejo_anchor_enabled' => 'true',
            'forgejo_token'          => 'secret-token',
            'forgejo_owner'          => 'andre',
            'forgejo_repo'           => 'audit-anchors',
            'forgejo_base_url'       => 'https://git.andrestiebitz.de',
        ], $captured);

        // [last, tail, count, insert(id=42), UPDATE].
        $queue = $this->mintQueue(42);
        $updateQb = new FakeQueryBuilder(null, 1);
        $queue[] = $updateQb;
        $db = new FakeDbConnection($queue);

        $downloadUrl = 'https://git.andrestiebitz.de/andre/audit-anchors/raw/branch/main/audit-checkpoints/checkpoint-5.json';
        $service = new TestableAuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']), $db, $config, $this->createMock(LoggerInterface::class)
        );
        $service->forgejoReturn = [
            'status' => 201,
            // F4: the anchor stores the raw file-bytes endpoint (download_url), never the HTML page url.
            'body'   => json_encode(['content' => [
                'html_url'     => 'https://git.andrestiebitz.de/andre/audit-anchors/src/branch/main/audit-checkpoints/checkpoint-5.json',
                'download_url' => $downloadUrl,
            ]], JSON_THROW_ON_ERROR),
        ];

        $service->createCheckpoint();

        $this->assertSame(1, $service->forgejoCalls);
        $this->assertSame('update', $updateQb->operation);
        $this->assertSame('learning_audit_checkpoints', $updateQb->table);
        $this->assertSame($downloadUrl, $updateQb->setCalls['anchor_url']['value'], 'download_url (raw bytes) written to anchor_url');
        // Update targets the just-inserted checkpoint id (42).
        $ids = array_column($updateQb->namedParameters, 'value');
        $this->assertContains(42, $ids, 'UPDATE must target the new checkpoint id');
        $this->assertSame('ok', $captured['last_anchor_status']);
        $this->assertArrayHasKey('last_anchor_attempted_at', $captured);
    }

    /**
     * Soft-fail on transport exception: the checkpoint row is untouched (no UPDATE), last_anchor_status
     * becomes 'failed', and NO exception escapes createCheckpoint().
     */
    public function testForgejoAnchorSoftFailsOnException(): void {
        $keys = $this->makeKeypair();
        $captured = [];
        $config = $this->anchorConfig([
            'forgejo_anchor_enabled' => 'true',
            'forgejo_token'          => 'secret-token',
        ], $captured);
        $db = new FakeDbConnection($this->mintQueue(7));

        $service = new TestableAuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']), $db, $config, $this->createMock(LoggerInterface::class)
        );
        $service->forgejoThrow = new \RuntimeException('connection refused');

        // Must NOT throw.
        $service->createCheckpoint();

        $this->assertSame(1, $service->forgejoCalls);
        $this->assertSame('failed', $captured['last_anchor_status']);
        $this->assertArrayHasKey('last_anchor_attempted_at', $captured);
        // No UPDATE issued — only the 4 mint builders. anchor_url on the inserted row stays NULL.
        $this->assertCount(4, $db->issuedBuilders);
        $insertQb = $db->issuedBuilders[3];
        $this->assertNull($insertQb->insertValues['anchor_url']['value']);
    }

    /**
     * Soft-fail on a non-201 HTTP status (500): last_anchor_status='failed', no UPDATE, no throw.
     */
    public function testForgejoAnchorSoftFailsOnHttp500(): void {
        $keys = $this->makeKeypair();
        $captured = [];
        $config = $this->anchorConfig([
            'forgejo_anchor_enabled' => 'true',
            'forgejo_token'          => 'secret-token',
        ], $captured);
        $db = new FakeDbConnection($this->mintQueue());

        $service = new TestableAuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']), $db, $config, $this->createMock(LoggerInterface::class)
        );
        $service->forgejoReturn = ['status' => 500, 'body' => 'internal error'];

        $service->createCheckpoint();

        $this->assertSame(1, $service->forgejoCalls);
        $this->assertSame('failed', $captured['last_anchor_status']);
        $this->assertCount(4, $db->issuedBuilders, 'no UPDATE builder on soft-fail');
    }

    /**
     * F6 (minimal SSRF guard): a non-https Forgejo base_url is rejected BEFORE any HTTP call — no POST,
     * last_anchor_status='failed', no UPDATE, no throw.
     */
    public function testForgejoAnchorSkipsNonHttpsBaseUrl(): void {
        $keys = $this->makeKeypair();
        $captured = [];
        $config = $this->anchorConfig([
            'forgejo_anchor_enabled' => 'true',
            'forgejo_token'          => 'secret-token',
            'forgejo_base_url'       => 'http://insecure.example',
        ], $captured);
        $db = new FakeDbConnection($this->mintQueue());

        $service = new TestableAuditCheckpointService(
            $this->mockKeyService('kid', $keys['sec']), $db, $config, $this->createMock(LoggerInterface::class)
        );
        $service->createCheckpoint();

        $this->assertSame(0, $service->forgejoCalls, 'non-https anchor endpoint must make NO HTTP call');
        $this->assertSame('failed', $captured['last_anchor_status']);
        $this->assertCount(4, $db->issuedBuilders, 'no UPDATE builder — anchor skipped before POST');
    }
}

/**
 * Test double exposing the protected forgejoPost() seam so anchor behaviour can be exercised without
 * a live HTTP call. forgejoReturn is the canned {status, body}; forgejoThrow simulates a transport
 * exception; forgejoCalls counts invocations (0 proves OFF-by-default made no request).
 */
final class TestableAuditCheckpointService extends AuditCheckpointService {
    /** @var array{status: int, body: string|false} */
    public array $forgejoReturn = ['status' => 0, 'body' => false];
    public ?\Throwable $forgejoThrow = null;
    public int $forgejoCalls = 0;

    /**
     * @return array{status: int, body: string|false}
     */
    protected function forgejoPost(string $apiUrl, string $token, string $body): array {
        $this->forgejoCalls++;
        if ($this->forgejoThrow !== null) {
            throw $this->forgejoThrow;
        }
        return $this->forgejoReturn;
    }
}
