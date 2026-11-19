<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\ConcurrentChangeException;
use OCA\Learning\Service\CertificationCycleService;
use OCA\Learning\Service\ExamAttemptLimitException;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\NothingToResetException;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\TrainingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #84 / #87 against real persistence on both engines (scripts/nc-integration.sh), wired by
 * Nextcloud's container as in production:
 *  - revoke → the old pass never reissues → the instructor opens a new cycle (while the 'passed'
 *    period of the revoked certificate still holds the UNIQUE slot) → the old pass still does not
 *    count → an exam of the new cycle issues a new certificate; nothing was deleted;
 *  - the total attempt limit refuses the next counting exam on the server, and the new cycle
 *    starts the count again; the earlier attempts stay.
 */
final class CertificationCycleTest extends TestCase {
    private const USER = 'gh84-cycle-learner';
    private const TEACHER = 'gh84-cycle-teacher';

    private IDBConnection $db;
    private IConfig $config;
    private int $courseId = 0;
    private int $poolId = 0;
    private int $memberId = 0;
    /** @var array<string, string> */
    private array $configBefore = [];

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->config = Server::get(IConfig::class);
        $keys = Server::get(KeyService::class);
        if (!$keys->hasActiveKey()) {
            $keys->init();
        }
        // isolate the total limit from the admin's daily limit and cooldown
        foreach (['exam_attempt_limit_per_day' => '50', 'exam_attempt_cooldown_minutes' => '0'] as $key => $value) {
            $this->configBefore[$key] = $this->config->getAppValue('learning', $key, '');
            $this->config->setAppValue('learning', $key, $value);
        }

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_courses')->values([
            'title' => $qb->createNamedParameter('Cycle course'),
            'instructor_id' => $qb->createNamedParameter(self::TEACHER),
            'status' => $qb->createNamedParameter('active'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'cert_enabled' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
            'cert_pass_percent' => $qb->createNamedParameter(80, IQueryBuilder::PARAM_INT),
            'cert_validity_months' => $qb->createNamedParameter(12, IQueryBuilder::PARAM_INT),
            'exam_attempt_limit' => $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->courseId = $qb->getLastInsertId();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_pools')->values([
            'user_id' => $qb->createNamedParameter(self::TEACHER),
            'name' => $qb->createNamedParameter('gh84 pool'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->poolId = $qb->getLastInsertId();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_members')->values([
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter(self::USER),
            'role' => $qb->createNamedParameter('student'),
            'enrolled_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->memberId = $qb->getLastInsertId();
    }

    protected function tearDown(): void {
        foreach (['learning_sessions' => 'user_id', 'learning_certificates' => 'user_id', 'learning_assignments' => 'subject_id', 'learning_course_members' => 'user_id'] as $table => $column) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(self::USER)))->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_pools')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_courses')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))->executeStatement();
        foreach ($this->configBefore as $key => $value) {
            if ($value === '') {
                $this->config->deleteAppValue('learning', $key);
            } else {
                $this->config->setAppValue('learning', $key, $value);
            }
        }
    }

    public function testARevokedLearnerGetsANewCertificateOnlyThroughANewCycle(): void {
        $pass = Server::get(PassCriteriaService::class);
        $certificates = Server::get(CertificateMapper::class);
        $cycles = Server::get(CertificationCycleService::class);

        $this->insertExam(time() - 7200, 1);
        $first = $pass->evaluate(self::USER, $this->courseId)->toArray();
        $this->assertTrue($first['certificate_issued'], 'setup: the first certificate');
        // The period that issued it still holds the learner's UNIQUE slot (the analysis' risk).
        $this->insertPeriod('passed', time() - 7200);

        $cert = $certificates->findByUserAndCourse(self::USER, $this->courseId);
        $this->assertNotNull($cert);
        $cert->setRevoked(true);
        $cert->setRevokedAt(time() - 60);
        $cert->setActiveIdemKey(null);
        $certificates->update($cert);

        $this->assertFalse($pass->evaluate(self::USER, $this->courseId)->toArray()['certificate_issued'], 'no reissue after a revoke');
        $this->assertSame(1, $this->rows('learning_certificates', 'user_id'));

        $opened = $cycles->open($this->courseId, $this->memberId, self::TEACHER);
        $this->assertSame('revoked', $opened['reason']);

        $inCycle = $pass->evaluate(self::USER, $this->courseId)->toArray();
        $this->assertFalse($inCycle['passed'], 'the exam from before the revoke no longer counts');
        $this->assertSame('revoked', $inCycle['new_cycle']['reason'] ?? null);
        $this->assertSame(1, $this->rows('learning_certificates', 'user_id'));

        $this->insertExam($opened['since'], 1);
        $renewed = $pass->evaluate(self::USER, $this->courseId)->toArray();
        $this->assertTrue($renewed['certificate_issued'], 'an exam of the new cycle issues a new certificate');
        $this->assertNotSame($cert->getVerificationId(), $renewed['certificate']['verification_id']);

        // nothing deleted: both certificates, both periods (old one released), both exams
        $this->assertSame(2, $this->rows('learning_certificates', 'user_id'));
        $this->assertSame(2, $this->rows('learning_assignments', 'subject_id'));
        $this->assertSame(2, $this->rows('learning_sessions', 'user_id'));
        $this->assertTrue($certificates->findByVerificationId((string)$cert->getVerificationId())->getRevoked());
        // A second click finds nothing to reset.
        $this->expectException(NothingToResetException::class);
        $cycles->open($this->courseId, $this->memberId, self::TEACHER);
    }

    public function testTheTotalLimitIsEnforcedOnTheServerAndANewCycleResetsIt(): void {
        $training = Server::get(TrainingService::class);
        $enforce = new \ReflectionMethod(TrainingService::class, 'enforceExamStartPolicy');
        $this->insertExam(time() - 7200, 0);
        $this->insertExam(time() - 3600, 0);

        try {
            $enforce->invoke($training, $this->poolId, self::USER, $this->courseId);
            $this->fail('the third attempt must be refused');
        } catch (ExamAttemptLimitException $e) {
            $this->assertSame('exam_attempts_exhausted', $e->getMessage());
        }
        // the pool window (no course) is not limited by the course
        $enforce->invoke($training, $this->poolId, self::USER, null);

        $opened = Server::get(CertificationCycleService::class)->open($this->courseId, $this->memberId, self::TEACHER);
        $this->assertSame('attempts', $opened['reason']);
        $enforce->invoke($training, $this->poolId, self::USER, $this->courseId);
        $this->assertSame(2, $this->rows('learning_sessions', 'user_id'), 'the earlier attempts stay as evidence');
    }

    /**
     * Review 5.16.0: two instructors open a cycle for the same learner at the same moment. The
     * second request decided on the row that held the slot before the first one committed. On
     * MySQL its locking read then found the first request's new row by the key and freed it,
     * opening a second cycle; the decided-on row id now has to hold the slot, or nothing is
     * written. Deterministic stand-in for that interleaving, on both engines.
     */
    public function testAReopenDecidedOnAnOldSlotIsRefusedAndWritesNothing(): void {
        $assignments = Server::get(AssignmentService::class);
        $this->insertPeriod('assigned', time() - 7200);
        $old = $this->slotRowId();
        $this->assertNotNull($old);

        $assignments->reopenPeriod($this->courseId, self::USER, 'attempts', self::TEACHER, null, $old);
        $new = $this->slotRowId();
        $this->assertNotSame($old, $new, 'the first reopen moved the slot to its new row');

        try {
            $assignments->reopenPeriod($this->courseId, self::USER, 'attempts', self::TEACHER, null, $old);
            $this->fail('a reopen decided on the old slot must be refused');
        } catch (ConcurrentChangeException $e) {
        }
        $this->assertSame($new, $this->slotRowId(), 'the first reopen\'s cycle keeps the slot');
        $this->assertSame(2, $this->rows('learning_assignments', 'subject_id'), 'no second cycle, no orphaned row');
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame($new, $assignments->activePeriodId($this->courseId, self::USER));
    }

    /** Read directly, not through activePeriodId(), so the test also runs against the old code. */
    private function slotRowId(): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('learning_assignments')
            ->where($qb->expr()->eq('active_period_key', $qb->createNamedParameter($this->courseId . ':user:' . self::USER)));
        $result = $qb->executeQuery();
        $id = $result->fetchOne();
        $result->closeCursor();
        return $id === false ? null : (int)$id;
    }

    private function insertExam(int $completedAt, int $correct): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')->values([
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter(self::USER),
            'started_at' => $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_INT),
            'completed_at' => $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_INT),
            'total_questions' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'correct_answers' => $qb->createNamedParameter($correct, IQueryBuilder::PARAM_INT),
            'mode' => $qb->createNamedParameter('exam'),
        ])->executeStatement();
    }

    private function insertPeriod(string $status, int $assignedAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_assignments')->values([
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'subject_type' => $qb->createNamedParameter('user'),
            'subject_id' => $qb->createNamedParameter(self::USER),
            'assigned_by' => $qb->createNamedParameter('system'),
            'assigned_at' => $qb->createNamedParameter($assignedAt, IQueryBuilder::PARAM_INT),
            'status' => $qb->createNamedParameter($status),
            'active_period_key' => $qb->createNamedParameter($this->courseId . ':user:' . self::USER),
        ])->executeStatement();
    }

    private function rows(string $table, string $column): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter(self::USER)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }
}
