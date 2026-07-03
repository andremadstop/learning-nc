<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\RetentionService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 164 (DSGVO-03) — RetentionService crypto-erasure correctness.
 *
 * Expected-RED: all tests in this file FAIL/ERROR against the no-op skeleton (impl in 164-07).
 */
class RetentionServiceTest extends TestCase {

    /**
     * DSGVO-03 erasure + audit-chain integrity invariant.
     *
     * A cert older than the retention window must be:
     *   (1a) user_id   → null        (pseudonymization)
     *   (1b) credential_json → ''    (crypto-erasure; breaks sig verifiability — accepted;
     *                                 the public verify route reads anonymized_at IS NOT NULL
     *                                 as the "data erased" terminal state)
     *   (1c) anonymized_at → non-null (tombstone set by RetentionService; 164-07 must also
     *                                 add getAnonymizedAt() / setAnonymizedAt() to Certificate.php)
     *
     * Linked learning_audit_events rows:
     *   (2) user_id → null   (via IDBConnection::getQueryBuilder() direct UPDATE)
     *   chain_hash MUST NOT change — the hash function does NOT include user_id,
     *   so nulling user_id is chain-immutable by construction (verified at integration level).
     *
     * Clock: ITimeFactory pinned to a fixed "now" so the retention-years window is deterministic.
     *
     * RED mechanism: RetentionService::anonymizeExpired() throws LogicException('164-07')
     *   on the first call → test ERRORS → RED.
     * GREEN trigger (164-07): method implemented; all (1a/1b/1c) assertions pass.
     */
    public function testAnonymizeKeepsChain(): void {
        $now           = 1750000000;
        $retentionYears = 3;
        // 4 years ago — past the 3-year retention window (ConfigDefaults::RETENTION_YEARS_DEFAULT)
        $issuedAt = $now - ($retentionYears + 1) * 365 * 86400;

        // Cert with PII-bearing credential_json and live user_id (not yet anonymized)
        $cert = new Certificate();
        $cert->setId(99);
        $cert->setUserId('alice');
        $cert->setCredentialJson('eyJhbGciOiJFZERTQSJ9.{"sub":"alice","name":"Alice Musterfrau"}.sig');
        $cert->setIssuedAt($issuedAt);
        $cert->setExpiresAt($issuedAt + 365 * 86400); // long expired
        // anonymized_at: Certificate entity gains this property in 164-07 (migration col exists).

        /** @var Certificate|null $updatedCert captured by mapper mock */
        $updatedCert = null;
        $certMapper  = $this->createMock(CertificateMapper::class);
        // 164-07 harness: the retention sweep reads its candidates via findAnonymizableBefore
        // (anonymized_at IS NULL AND active_idem_key IS NULL AND issued_at < cutoff).
        $certMapper->method('findAnonymizableBefore')->willReturn([$cert]);
        $certMapper->method('update')
            ->willReturnCallback(static function (Certificate $c) use (&$updatedCert): Certificate {
                $updatedCert = $c;
                return $c;
            });

        $fakeDb       = new FakeDbConnection();

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->willReturn((string) $retentionYears);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);

        $logger = $this->createMock(LoggerInterface::class);

        $service = new RetentionService($certMapper, $fakeDb, $config, $time, $logger);

        // RED: anonymizeExpired() throws LogicException('164-07') → test ERRORS → RED.
        // GREEN (164-07): the method is implemented; assertions below then hold.
        $service->anonymizeExpired();

        // (1a) user_id nulled (pseudonymization)
        $this->assertNotNull($updatedCert,
            'DSGVO-03: anonymizeExpired() must call certMapper->update()');
        $this->assertNull($updatedCert->getUserId(),
            'DSGVO-03: user_id must be null after erasure');

        // (1b) credential_json scrubbed (crypto-erasure; removes PII from signed JWT)
        $this->assertEmpty($updatedCert->getCredentialJson(),
            'DSGVO-03: credential_json must be empty string after crypto-erasure');

        // (1c) anonymized_at tombstone (entity property added in 164-06; column since 164-01)
        $this->assertSame($now, $updatedCert->getAnonymizedAt(),
            'DSGVO-03: anonymized_at must be the erasure timestamp (tombstone)');

        // (2) Audit chain integrity: RetentionService must UPDATE learning_audit_events
        //   with SET user_id = NULL but MUST NOT touch chain_hash.
        //   FakeDbConnection::issuedBuilders[] captures every getQueryBuilder() call;
        //   FakeQueryBuilder::setCalls is keyed by field name.
        //   → asserting chain_hash absent from any UPDATE SET clause locks the
        //     "chain-immutable-by-construction" invariant at unit-test level.
        //   GREEN (164-07): impl calls $db->getQueryBuilder()->update(...)
        //     ->set('user_id', null)->where(...)—without set('chain_hash', ...).
        $auditUpdate = null;
        foreach ($fakeDb->issuedBuilders as $issuedQb) {
            if ($issuedQb->operation === 'update') {
                $auditUpdate = $issuedQb;
                break;
            }
        }
        $this->assertNotNull($auditUpdate,
            'DSGVO-03: RetentionService must issue an UPDATE via IDBConnection to null audit user_id');
        $this->assertArrayHasKey('user_id', $auditUpdate->setCalls,
            'DSGVO-03: user_id must appear in audit UPDATE SET clause');
        $this->assertArrayNotHasKey('chain_hash', $auditUpdate->setCalls,
            'DSGVO-03: chain_hash must NOT appear in audit UPDATE — chain-immutability by construction');
    }
}
