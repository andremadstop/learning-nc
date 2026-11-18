<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\RecertReminderMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\IssuanceService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassResult;
use OCA\Learning\Service\RecertReminderService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #85 review: the "certificate expired" activity against real persistence. With
 * recert_grace_days = 0 the daily RecertPeriodCloseJob (AssignmentService::closeExpiredPeriods)
 * releases the slot of an expired certificate before the reminder sweep runs. The sweep must still
 * claim the announcement (reminder outbox row with threshold 0, delivered). A certificate that gave
 * up its slot to a RENEWAL is not announced. The unit tests mock the mapper, so only this test
 * turns red when the sweep's query goes back to slot owners only.
 */
final class ExpiredSweepAfterCloseTest extends TestCase {
    private const USER = 'gh85-expired-learner';

    private IDBConnection $db;
    private IConfig $config;
    private IssuanceService $issuance;
    private CertificateMapper $certificates;
    private RecertReminderMapper $reminders;
    private int $courseId = 0;
    private ?string $graceBefore = null;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->config = Server::get(IConfig::class);
        $this->issuance = Server::get(IssuanceService::class);
        $this->certificates = Server::get(CertificateMapper::class);
        $this->reminders = Server::get(RecertReminderMapper::class);
        $keys = Server::get(KeyService::class);
        if (!$keys->hasActiveKey()) {
            $keys->init();
        }
        $this->graceBefore = $this->config->getAppValue('learning', 'recert_grace_days', '');
        $this->config->setAppValue('learning', 'recert_grace_days', '0');

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_courses')->values([
            'title' => $qb->createNamedParameter('Expired sweep course'),
            'instructor_id' => $qb->createNamedParameter('gh85-expired-instructor'),
            'status' => $qb->createNamedParameter('active'),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'cert_enabled' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
            'cert_validity_months' => $qb->createNamedParameter(12, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->courseId = $qb->getLastInsertId();
    }

    protected function tearDown(): void {
        foreach ($this->certIds() as $certId) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('learning_recert_reminders')
                ->where($qb->expr()->eq('cert_id', $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_certificates')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)))
            ->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_assignments')
            ->where($qb->expr()->eq('subject_id', $qb->createNamedParameter(self::USER)))
            ->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_courses')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        if ($this->graceBefore === '' || $this->graceBefore === null) {
            $this->config->deleteAppValue('learning', 'recert_grace_days');
        } else {
            $this->config->setAppValue('learning', 'recert_grace_days', $this->graceBefore);
        }
    }

    public function testAnExpiredCertificateIsAnnouncedAfterTheCloseJobReleasedItsSlot(): void {
        $cert = $this->issue();
        $this->setTimes($cert, time() - 86400 * 30, time() - 3600);

        // The close job runs first (grace 0): the slot is gone.
        $this->assertGreaterThanOrEqual(1, Server::get(AssignmentService::class)->closeExpiredPeriods(time()));
        $cert = $this->reload($cert);
        $this->assertNull($cert->getActiveIdemKey(), 'setup: the close job released the slot');

        Server::get(RecertReminderService::class)->sendRecertReminders();

        $claim = $this->reminders->findByCertAndThreshold((int)$cert->getId(), RecertReminderService::EXPIRED_THRESHOLD);
        $this->assertNotNull($claim, 'the expired certificate was announced although its slot is released');
        $this->assertNotNull($claim->getDeliveredAt());
    }

    public function testARenewedCertificateIsNotAnnouncedWhenItExpires(): void {
        $old = $this->issue();
        $this->setTimes($old, time() - 86400 * 30, time() + 86400);
        $old = $this->reload($old);
        $renewal = $this->issuance->issueIfPassedResult(self::USER, $this->courseId, new PassResult(true, 90, 80, true, time()), $old);
        $this->assertNotNull($renewal);
        $this->assertTrue($renewal->wasCreated, 'setup: renewed');
        $this->setTimes($old, time() - 86400 * 30, time() - 3600);

        Server::get(RecertReminderService::class)->sendRecertReminders();

        $this->assertNull(
            $this->reminders->findByCertAndThreshold((int)$old->getId(), RecertReminderService::EXPIRED_THRESHOLD),
            'a renewed certificate expires silently'
        );
    }

    private function issue(): Certificate {
        $result = $this->issuance->issueIfPassedResult(self::USER, $this->courseId, new PassResult(true, 90, 80, true, time()));
        $this->assertNotNull($result);
        $this->assertTrue($result->wasCreated);
        return $result->certificate;
    }

    private function reload(Certificate $cert): Certificate {
        return $this->certificates->findByVerificationId((string)$cert->getVerificationId());
    }

    private function setTimes(Certificate $cert, int $issuedAt, int $expiresAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_certificates')
            ->set('issued_at', $qb->createNamedParameter($issuedAt, IQueryBuilder::PARAM_INT))
            ->set('expires_at', $qb->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$cert->getId(), IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /** @return list<int> */
    private function certIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('learning_certificates')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
        $result = $qb->executeQuery();
        $ids = [];
        while (($id = $result->fetchOne()) !== false) {
            $ids[] = (int)$id;
        }
        $result->closeCursor();
        return $ids;
    }
}
