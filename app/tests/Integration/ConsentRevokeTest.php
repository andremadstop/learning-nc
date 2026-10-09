<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Mapper that lets a grant commit while a revocation is in progress: right before the first
 * mapper call of revoke() returns, another request stores a consent with raw SQL.
 */
final class RacingUserTelosMapper extends UserTelosMapper {
    /** @var (\Closure(): void)|null */
    public ?\Closure $race = null;

    public function findByUserIdOrNull(string $userId): ?UserTelos {
        $row = parent::findByUserIdOrNull($userId);
        $this->runRace();
        return $row;
    }

    public function revokeAiConsent(string $userId, int $now): int {
        $this->runRace();
        return parent::revokeAiConsent($userId, $now);
    }

    private function runRace(): void {
        $race = $this->race;
        $this->race = null;
        if ($race !== null) {
            $race();
        }
    }
}

/**
 * 5.7.0 Task B1, review finding: revoke() against the real mapper on both engines. A revocation
 * that reported success must leave no valid consent, even when a grant of the same user commits
 * while it runs.
 */
final class ConsentRevokeTest extends TestCase {
    private const UID = 'b1-consent-revoke-probe';

    private IDBConnection $db;
    private RacingUserTelosMapper $mapper;
    private ConsentService $consent;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->mapper = new RacingUserTelosMapper($this->db);
        $this->consent = new ConsentService($this->mapper, $this->createMock(AuditService::class), Server::get(IAppConfig::class));
        $this->deleteProbeRow();
    }

    protected function tearDown(): void {
        $this->deleteProbeRow();
    }

    public function testRevokeClearsAGrantThatCommitsWhileItRuns(): void {
        $c = ConnectionStore::legacyGemini(true);
        $this->insertRowWithoutConsent();
        $this->mapper->race = fn () => $this->grantWithRawSql($c->recipientFingerprint());

        $this->consent->revoke(self::UID);

        $this->assertNull($this->mapper->race, 'the concurrent grant ran');
        $this->assertFalse($this->consent->isValidFor(self::UID, $c), 'a successful revocation leaves no valid consent');
        $row = $this->mapper->findByUserId(self::UID);
        $this->assertNull($row->getAiConsentVersion());
        $this->assertNull($row->getAiConsentFingerprint());
        $this->assertNull($row->getAiConsentSource());
        $this->assertNull($row->getAiConsentAt());
        $this->assertSame(1, $row->getConsentEpoch());
    }

    public function testGrantThenRevokePersistsAndRaisesTheEpoch(): void {
        $c = ConnectionStore::legacyGemini(true);
        $this->consent->grant(self::UID, $c, $c->recipientFingerprint(), $this->consent->currentVersion(), 'dialog');
        $this->assertTrue($this->consent->isValidFor(self::UID, $c));
        $this->assertSame(0, $this->consent->epoch(self::UID));

        $this->consent->revoke(self::UID);
        $this->consent->revoke(self::UID);

        $this->assertFalse($this->consent->isValidFor(self::UID, $c));
        $this->assertSame(2, $this->consent->epoch(self::UID));
        $this->assertSame('never', $this->consent->state(self::UID, $c)['reason']);
    }

    public function testRevokeWithoutRowCreatesNone(): void {
        $this->consent->revoke(self::UID);
        $this->assertNull($this->mapper->findByUserIdOrNull(self::UID));
    }

    private function insertRowWithoutConsent(): void {
        $row = new UserTelos();
        $row->setUserId(self::UID);
        $row->setCreatedAt(time());
        $row->setUpdatedAt(time());
        $this->mapper->insert($row);
    }

    private function grantWithRawSql(string $fingerprint): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_telos')
            ->set('ai_consent_version', $qb->createNamedParameter($this->consent->currentVersion()))
            ->set('ai_consent_fingerprint', $qb->createNamedParameter($fingerprint))
            ->set('ai_consent_source', $qb->createNamedParameter('dialog'))
            ->set('ai_consent_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::UID)))
            ->executeStatement();
    }

    private function deleteProbeRow(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_user_telos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::UID)))
            ->executeStatement();
    }
}
