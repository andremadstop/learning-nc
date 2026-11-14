<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\IssuanceService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #35 renewal against real persistence on both engines (scripts/nc-integration.sh): the
 * slot CAS (CertificateMapper::releaseSlot) followed by the INSERT under the UNIQUE index on
 * active_idem_key, inside the caller's outer transaction exactly as PassCriteriaService::evaluate()
 * runs it (the unit tests mock the mapper). Checks that the successor can take the released slot
 * in the same transaction on both engines, that the old row keeps expiry and revoked, and that a
 * lost CAS (0 rows) dedupes and leaves the caller's outer transaction committable.
 */
final class CertificateRenewalTest extends TestCase {
    private const USER = 'gh35-renewal-learner';

    private IDBConnection $db;
    private IssuanceService $issuance;
    private CertificateMapper $certificates;
    private int $courseId = 0;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->issuance = Server::get(IssuanceService::class);
        $this->certificates = Server::get(CertificateMapper::class);
        Server::get(KeyService::class)->init();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_courses')->values([
            'title' => $qb->createNamedParameter('Renewal course'),
            'instructor_id' => $qb->createNamedParameter('gh35-renewal-instructor'),
            'status' => $qb->createNamedParameter('active'),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'cert_enabled' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
            'cert_validity_months' => $qb->createNamedParameter(12, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->courseId = $qb->getLastInsertId();
    }

    protected function tearDown(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_certificates')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)))
            ->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_courses')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function testRenewalTakesTheSlotOnceAndLeavesTheOldCertificateValid(): void {
        $pass = new PassResult(true, 90, 80, true, time());
        $first = $this->issuance->issueIfPassedResult(self::USER, $this->courseId, $pass);
        $this->assertNotNull($first);
        $this->assertTrue($first->wasCreated);
        // Issued a day earlier, so the successor never shares its second (production: the window
        // starts at issued_at + 1 anyway).
        $this->setIssuedAt($first->certificate, time() - 86400);
        $old = $this->reload($first->certificate);
        $oldExpiry = (int)$old->getExpiresAt();
        $this->assertGreaterThan(time(), $oldExpiry, 'setup: a 12-month certificate');

        // Renewal inside the caller's outer transaction, as PassCriteriaService runs it.
        $this->db->beginTransaction();
        $renewal = $this->issuance->issueIfPassedResult(self::USER, $this->courseId, $pass, $old);
        $this->db->commit();

        $this->assertNotNull($renewal);
        $this->assertTrue($renewal->wasCreated);
        $old = $this->reload($old);
        $new = $this->reload($renewal->certificate);
        $this->assertNull($old->getActiveIdemKey(), 'the old certificate released its slot');
        $this->assertSame($oldExpiry, (int)$old->getExpiresAt(), 'the old certificate keeps its expiry');
        $this->assertFalse((bool)$old->getRevoked(), 'superseded, not withdrawn');
        $this->assertSame(self::USER . ':' . $this->courseId, $new->getActiveIdemKey());
        $this->assertGreaterThan($oldExpiry, (int)$new->getExpiresAt(), 'the new term continues after the old expiry');
        $this->assertSame($new->getVerificationId(), $this->certificates->findByUserAndCourse(self::USER, $this->courseId)?->getVerificationId());

        // A second renewal of the same old certificate (a concurrent request that read it before
        // the first one committed) loses the CAS: no row, and the outer transaction still commits.
        // A write after the lost CAS, in the same outer transaction, must survive the COMMIT.
        $this->db->beginTransaction();
        $loser = $this->issuance->issueIfPassedResult(self::USER, $this->courseId, $pass, $old);
        $this->setExpiry($old, $oldExpiry - 1);
        $this->db->commit();

        $this->assertNotNull($loser);
        $this->assertFalse($loser->wasCreated);
        $this->assertSame($new->getVerificationId(), $loser->certificate->getVerificationId());
        $this->assertSame(2, $this->countCertificates(), 'the lost renewal inserted nothing');
        $this->assertSame($oldExpiry - 1, (int)$this->reload($old)->getExpiresAt(),
            'the outer transaction committed after the lost CAS (no poisoned transaction block)');
    }

    private function reload(Certificate $cert): Certificate {
        return $this->certificates->findByVerificationId((string)$cert->getVerificationId());
    }

    private function countCertificates(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))
            ->from('learning_certificates')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    private function setIssuedAt(Certificate $cert, int $issuedAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_certificates')
            ->set('issued_at', $qb->createNamedParameter($issuedAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$cert->getId(), IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function setExpiry(Certificate $cert, int $expiresAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_certificates')
            ->set('expires_at', $qb->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$cert->getId(), IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
