<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\AuditService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditServiceTest extends TestCase {

    // -------------------------------------------------------------------------
    // Helpers — encode the GREEN contract that 160-02 must satisfy.
    // Formula: chain_hash = sha256(ksorted_canonical_json . '|' . prevHash)
    // user_ref = hash_hmac('sha256', userId, pepper)
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $fields */
    private function buildCanonical(array $fields): string {
        ksort($fields);
        return json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $fields */
    private function buildChainHash(array $fields, string $prevHash): string {
        $json = $this->buildCanonical($fields);
        return hash('sha256', $json . '|' . $prevHash);
    }

    // -------------------------------------------------------------------------
    // Existing passing tests (logEvent — must remain green)
    // -------------------------------------------------------------------------

    public function testLogEventInsertsCorrectValues(): void {
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 1);
        $db = new FakeDbConnection([$insertBuilder]);
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(\OCP\IConfig::class);
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);

        $service = new AuditService($db, $logger, $config, $secureRandom);
        $service->logEvent('moderation_action', 'user1', ['action' => 'approve']);

        $this->assertSame('insert', $insertBuilder->operation);
        $this->assertSame('learning_audit_events', $insertBuilder->table);

        $values = $insertBuilder->insertValues;
        $this->assertSame('moderation_action', $values['event_key']['value']);
        $this->assertSame('user1', $values['user_id']['value']);
        $this->assertSame('{"action":"approve"}', $values['context_json']['value']);
        $this->assertIsInt($values['created_at']['value']);
    }

    public function testLogEventDoesNotThrowOnDbError(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error');

        // Use a mock that throws on getQueryBuilder
        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->method('getQueryBuilder')
            ->willThrowException(new \RuntimeException('DB down'));

        $config = $this->createMock(\OCP\IConfig::class);
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $service = new AuditService($db, $logger, $config, $secureRandom);
        // Should not throw
        $service->logEvent('test', 'user1', []);
    }

    public function testLogEventWithEmptyContext(): void {
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 1);
        $db = new FakeDbConnection([$insertBuilder]);
        $logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(\OCP\IConfig::class);
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);

        $service = new AuditService($db, $logger, $config, $secureRandom);
        $service->logEvent('some_event', 'user2');

        $values = $insertBuilder->insertValues;
        $this->assertSame('[]', $values['context_json']['value']);
    }

    // -------------------------------------------------------------------------
    // RED phase stubs for logComplianceEvent (160-01).
    // These tests FAIL until 160-02 implements logComplianceEvent.
    // They encode the GREEN contract: ksorted canonical, hash_hmac pepper,
    // sha256(canonical + '|' + prevHash), exceptions propagate, CAS retries.
    // -------------------------------------------------------------------------

    /**
     * chain_hash is a 64-char lowercase hex string computed from ksorted canonical.
     * user_ref = hash_hmac('sha256', userId, pepper) — never plain sha256, never raw uid.
     * prev_hash for genesis event = str_repeat('0', 64).
     *
     * RED: fails with "Call to undefined method AuditService::logComplianceEvent"
     */
    public function testChainHashCorrect(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        // 3 QBs per logComplianceEvent call: [SELECT chain_state, INSERT event, UPDATE CAS]
        $selectQb = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => 0, 'last_hash' => str_repeat('0', 64)]));
        $insertQb = new FakeQueryBuilder(null, 1);
        $updateQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$selectQb, $insertQb, $updateQb]);
        $service = new AuditService($db, $logger, $config, $secureRandom);

        $service->logComplianceEvent('cert.issued', 'user1', ['course_id' => 42]);

        // chain_hash must be a 64-char hex sha256
        $chainHash = $insertQb->insertValues['chain_hash']['value'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $chainHash, 'chain_hash must be 64 hex chars');

        // user_ref must be peppered HMAC — not raw uid, not plain sha256
        $userRefFromInsert = $insertQb->insertValues['user_ref']['value'];
        $this->assertSame(
            hash_hmac('sha256', 'user1', 'testpepper'),
            $userRefFromInsert,
            'user_ref must be hash_hmac(sha256, userId, pepper)'
        );

        // genesis: prev_hash must be 64 zeros
        $prevHashFromInsert = $insertQb->insertValues['prev_hash']['value'];
        $this->assertSame(str_repeat('0', 64), $prevHashFromInsert, 'genesis prev_hash must be 64 zeros');
    }

    /**
     * Canonical contains exactly {seq, event_key, user_ref, course_id, created_at}.
     * Verified by reconstructing expected chain_hash from captured column values.
     *
     * RED: fails at logComplianceEvent call.
     */
    public function testCanonicalFieldSet(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        $selectQb = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => 0, 'last_hash' => str_repeat('0', 64)]));
        $insertQb = new FakeQueryBuilder(null, 1);
        $updateQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$selectQb, $insertQb, $updateQb]);
        $service = new AuditService($db, $logger, $config, $secureRandom);

        $service->logComplianceEvent('cert.issued', 'user1', ['course_id' => 42]);

        // Reconstruct expected canonical from captured values
        $seqNum = $insertQb->insertValues['seq_num']['value'];
        $userRef = $insertQb->insertValues['user_ref']['value'];
        $prevHash = $insertQb->insertValues['prev_hash']['value'];
        $chainHash = $insertQb->insertValues['chain_hash']['value'];
        $createdAt = $insertQb->insertValues['created_at']['value'];

        // Canonical must have exactly these 5 fields (ksorted)
        $expectedCanonical = [
            'seq'        => $seqNum,
            'event_key'  => 'cert.issued',
            'user_ref'   => $userRef,
            'course_id'  => 42,
            'created_at' => $createdAt,
        ];
        $expectedHash = $this->buildChainHash($expectedCanonical, $prevHash);

        $this->assertSame($expectedHash, $chainHash, 'chain_hash must match sha256(ksorted_canonical | prevHash)');
    }

    /**
     * user_ref equals hash_hmac('sha256', userId, pepper) for email-shaped uids.
     * Must never equal the raw uid (DSGVO-01: NC uids can be emails — PII in canonical).
     *
     * RED: fails at logComplianceEvent call.
     */
    public function testUserRefIsNotRawUid(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        $emailUid = 'user@example.com';
        $selectQb = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => 0, 'last_hash' => str_repeat('0', 64)]));
        $insertQb = new FakeQueryBuilder(null, 1);
        $updateQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$selectQb, $insertQb, $updateQb]);
        $service = new AuditService($db, $logger, $config, $secureRandom);

        $service->logComplianceEvent('cert.issued', $emailUid, ['course_id' => 42]);

        $userRefFromInsert = $insertQb->insertValues['user_ref']['value'];
        $expectedRef = hash_hmac('sha256', $emailUid, 'testpepper');

        $this->assertSame($expectedRef, $userRefFromInsert, 'user_ref must be peppered HMAC');
        $this->assertNotSame($emailUid, $userRefFromInsert, 'user_ref must not equal raw uid (PII risk)');
    }

    /**
     * second event's prev_hash must equal first event's chain_hash (chain linking).
     *
     * RED: fails at first logComplianceEvent call.
     */
    public function testChainLinks(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        // === First call: genesis chain state ===
        $selectQb1 = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => 0, 'last_hash' => str_repeat('0', 64)]));
        $insertQb1 = new FakeQueryBuilder(null, 1);
        $updateQb1 = new FakeQueryBuilder(null, 1);
        $db1 = new FakeDbConnection([$selectQb1, $insertQb1, $updateQb1]);
        $service1 = new AuditService($db1, $logger, $config, $secureRandom);
        $service1->logComplianceEvent('cert.issued', 'user1', ['course_id' => 42]);

        $chainHash1 = $insertQb1->insertValues['chain_hash']['value'];

        // === Second call: chain state now has last_hash = chainHash1 ===
        $selectQb2 = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => 1, 'last_hash' => $chainHash1]));
        $insertQb2 = new FakeQueryBuilder(null, 1);
        $updateQb2 = new FakeQueryBuilder(null, 1);
        $db2 = new FakeDbConnection([$selectQb2, $insertQb2, $updateQb2]);
        $service2 = new AuditService($db2, $logger, $config, $secureRandom);
        $service2->logComplianceEvent('cert.issued', 'user1', ['course_id' => 42]);

        $prevHash2 = $insertQb2->insertValues['prev_hash']['value'];
        $this->assertSame($chainHash1, $prevHash2, 'second event prev_hash must equal first event chain_hash');
    }

    /**
     * First event uses str_repeat('0', 64) as prev_hash (genesis).
     *
     * RED: fails at logComplianceEvent call.
     */
    public function testGenesisEvent(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        $selectQb = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => 0, 'last_hash' => str_repeat('0', 64)]));
        $insertQb = new FakeQueryBuilder(null, 1);
        $updateQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$selectQb, $insertQb, $updateQb]);
        $service = new AuditService($db, $logger, $config, $secureRandom);

        $service->logComplianceEvent('cert.issued', 'user1', ['course_id' => 42]);

        $prevHash = $insertQb->insertValues['prev_hash']['value'];
        $this->assertSame(str_repeat('0', 64), $prevHash, 'genesis event prev_hash must be 64 zeros');
    }

    /**
     * DB exception from beginTransaction must propagate — NOT swallowed (unlike logEvent).
     * logComplianceEvent must never wrap the exception in try/catch.
     *
     * RED: logComplianceEvent doesn't exist → Error thrown → expectException(RuntimeException) fails
     *      because Error is not RuntimeException. Test correctly fails (RED).
     */
    public function testLogComplianceEventPropagatesException(): void {
        $db = $this->createMock(\OCP\IDBConnection::class);
        $db->method('beginTransaction')->willThrowException(new \RuntimeException('DB down'));
        $db->method('rollBack');
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);
        $service = new AuditService($db, $logger, $config, $secureRandom);

        $this->expectException(\RuntimeException::class);
        $service->logComplianceEvent('cert.issued', 'user1', ['course_id' => 42]);
    }

    /**
     * CAS UPDATE returning 0 affected rows triggers retry; after 3 failed attempts
     * RuntimeException('logComplianceEvent: could not acquire chain slot after 3 attempts') is thrown.
     *
     * Setup: 9 builders (3 attempts × [SELECT, INSERT, UPDATE-returns-0])
     *
     * RED: fails at logComplianceEvent call.
     */
    public function testCasExhaustsRetries(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        $dbs = [];
        for ($i = 0; $i < 3; $i++) {
            // SELECT: each retry sees a new last_seq (concurrent writer raced ahead)
            $dbs[] = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => $i, 'last_hash' => str_repeat('0', 64)]));
            // INSERT: succeeds (1 row)
            $dbs[] = new FakeQueryBuilder(null, 1);
            // CAS UPDATE: always returns 0 affected rows → retry
            $dbs[] = new FakeQueryBuilder(null, 0);
        }
        $db = new FakeDbConnection($dbs);
        $service = new AuditService($db, $logger, $config, $secureRandom);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('logComplianceEvent: could not acquire chain slot after 3 attempts');
        $service->logComplianceEvent('cert.issued', 'user1', ['course_id' => 42]);
    }

    /**
     * Canonical JSON must NOT contain 'user_id' key — only 'user_ref' (pseudonym).
     * Verified by reconstructing hashes with and without user_id and confirming which matches.
     *
     * RED: fails at logComplianceEvent call.
     */
    public function testCanonicalExcludesPii(): void {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('testpepper');
        $secureRandom = $this->createMock(\OCP\Security\ISecureRandom::class);
        $logger = $this->createMock(LoggerInterface::class);

        $selectQb = new FakeQueryBuilder(FakeResult::fromFetch(['last_seq' => 0, 'last_hash' => str_repeat('0', 64)]));
        $insertQb = new FakeQueryBuilder(null, 1);
        $updateQb = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$selectQb, $insertQb, $updateQb]);
        $service = new AuditService($db, $logger, $config, $secureRandom);

        $service->logComplianceEvent('cert.issued', 'user@example.com', ['course_id' => 42]);

        $seqNum = $insertQb->insertValues['seq_num']['value'];
        $userRef = $insertQb->insertValues['user_ref']['value'];
        $prevHash = $insertQb->insertValues['prev_hash']['value'];
        $chainHash = $insertQb->insertValues['chain_hash']['value'];
        $createdAt = $insertQb->insertValues['created_at']['value'];

        // Canonical WITHOUT user_id must match stored chain_hash
        $canonicalWithoutPii = [
            'seq'        => $seqNum,
            'event_key'  => 'cert.issued',
            'user_ref'   => $userRef,
            'course_id'  => 42,
            'created_at' => $createdAt,
        ];
        $hashWithoutPii = $this->buildChainHash($canonicalWithoutPii, $prevHash);
        $this->assertSame($hashWithoutPii, $chainHash, 'canonical without user_id must match stored chain_hash');

        // Canonical WITH user_id would produce a DIFFERENT hash (proving user_id is excluded)
        $canonicalWithPii = $canonicalWithoutPii + ['user_id' => 'user@example.com'];
        $hashWithPii = $this->buildChainHash($canonicalWithPii, $prevHash);
        $this->assertNotSame($hashWithPii, $chainHash, 'canonical with user_id must not match (user_id excluded from hash)');
    }
}
