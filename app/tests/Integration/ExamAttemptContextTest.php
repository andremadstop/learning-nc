<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\TrainingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #47 against real persistence on both engines (scripts/nc-integration.sh): the exam
 * attempt limit and the cooldown count per context — the course an exam counts for, or the pool
 * window (course_id NULL). The unit tests check the where clauses on fakes; this checks the SQL,
 * including the NULL comparison, on PostgreSQL and MariaDB.
 */
final class ExamAttemptContextTest extends TestCase {
    private const USER = 'gh47-attempt-learner';
    // learning_sessions.course_id carries no foreign key; pool_id does (learn_sessions_pool_fk).
    private const COURSE_A = 947101;
    private const COURSE_B = 947102;

    private IDBConnection $db;
    private IConfig $config;
    private TrainingService $training;
    private string $limitBefore = '';
    private string $cooldownBefore = '';
    private int $poolId = 0;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->config = Server::get(IConfig::class);
        $this->training = Server::get(TrainingService::class);
        $this->limitBefore = $this->config->getAppValue('learning', 'exam_attempt_limit_per_day', '');
        $this->cooldownBefore = $this->config->getAppValue('learning', 'exam_attempt_cooldown_minutes', '');
        $this->config->setAppValue('learning', 'exam_attempt_limit_per_day', '2');
        $this->config->setAppValue('learning', 'exam_attempt_cooldown_minutes', '10');
        $this->deleteSessions();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_pools')->values([
            'user_id' => $qb->createNamedParameter(self::USER),
            'name' => $qb->createNamedParameter('gh47 attempt context'),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->poolId = $qb->getLastInsertId();
    }

    protected function tearDown(): void {
        $this->deleteSessions();
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_pools')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        $this->restore('exam_attempt_limit_per_day', $this->limitBefore);
        $this->restore('exam_attempt_cooldown_minutes', $this->cooldownBefore);
    }

    public function testATryInThePoolWindowNeitherStartsTheCooldownNorUsesUpTheCourseAttempts(): void {
        $this->insertExam(null, time() - 60);
        $this->insertExam(null, time() - 3600);

        $this->enforce(self::COURSE_A);
        $this->assertSame(1, $this->attemptNo(self::COURSE_A), 'the pool-window tries do not number the course exam');

        $this->assertSame('Exam attempt limit reached for the last 24 hours', $this->refusal(null), 'the pool window still keeps its own limit');
    }

    public function testACourseExamDoesNotBlockTheSamePoolInAnotherCourseOrThePoolWindow(): void {
        $this->insertExam(self::COURSE_A, time() - 60);

        $this->assertSame('Exam cooldown active', $this->refusal(self::COURSE_A));
        $this->enforce(self::COURSE_B);
        $this->enforce(null);
        $this->assertSame(2, $this->attemptNo(self::COURSE_A));
        $this->assertSame(1, $this->attemptNo(self::COURSE_B));
    }

    private function enforce(?int $courseId): void {
        $method = new \ReflectionMethod(TrainingService::class, 'enforceExamStartPolicy');
        $method->invoke($this->training, $this->poolId, self::USER, $courseId);
        $this->addToAssertionCount(1);
    }

    private function refusal(?int $courseId): ?string {
        try {
            (new \ReflectionMethod(TrainingService::class, 'enforceExamStartPolicy'))
                ->invoke($this->training, $this->poolId, self::USER, $courseId);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
        return null;
    }

    private function attemptNo(?int $courseId): int {
        return (int)(new \ReflectionMethod(TrainingService::class, 'getExamAttemptNo'))
            ->invoke($this->training, $this->poolId, self::USER, $courseId);
    }

    private function insertExam(?int $courseId, int $startedAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')->values([
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'course_id' => $qb->createNamedParameter($courseId, $courseId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter(self::USER),
            'started_at' => $qb->createNamedParameter($startedAt, IQueryBuilder::PARAM_INT),
            'completed_at' => $qb->createNamedParameter($startedAt + 30, IQueryBuilder::PARAM_INT),
            'total_questions' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'correct_answers' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'mode' => $qb->createNamedParameter('exam'),
        ])->executeStatement();
    }

    private function deleteSessions(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_sessions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)))
            ->executeStatement();
    }

    private function restore(string $key, string $value): void {
        if ($value === '') {
            $this->config->deleteAppValue('learning', $key);
        } else {
            $this->config->setAppValue('learning', $key, $value);
        }
    }
}
