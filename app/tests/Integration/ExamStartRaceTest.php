<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Review 5.16.0 (#87): the total attempt limit of a certification cycle is check-then-insert.
 * WORKERS processes start a counting exam at the same moment while the learner has used
 * limit-1 attempts. Before, every start read "limit-1 used" before any insert landed, and each
 * inserted its own counting exam. Exactly one new exam may start; the parallel starts resume it.
 * Runs on PostgreSQL and MariaDB (scripts/nc-integration.sh, ENGINE=pgsql|mysql).
 */
final class ExamStartRaceTest extends TestCase {
    private const USER = 'r516-exam-race-learner';
    private const TEACHER = 'r516-exam-race-teacher';
    private const WORKERS = 8;
    private const LIMIT = 3;

    private IDBConnection $db;
    private IConfig $config;
    private int $courseId = 0;
    private int $poolId = 0;
    /** @var array<string, string> */
    private array $configBefore = [];

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->config = Server::get(IConfig::class);
        // only the course's total limit decides, not the admin's daily limit or cooldown
        foreach (['exam_attempt_limit_per_day' => '50', 'exam_attempt_cooldown_minutes' => '0'] as $key => $value) {
            $this->configBefore[$key] = $this->config->getAppValue('learning', $key, '');
            $this->config->setAppValue('learning', $key, $value);
        }
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_courses')->values([
            'title' => $qb->createNamedParameter('Exam race course'),
            'instructor_id' => $qb->createNamedParameter(self::TEACHER),
            'status' => $qb->createNamedParameter('active'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'cert_enabled' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
            'cert_pass_percent' => $qb->createNamedParameter(80, IQueryBuilder::PARAM_INT),
            'cert_validity_months' => $qb->createNamedParameter(12, IQueryBuilder::PARAM_INT),
            'exam_attempt_limit' => $qb->createNamedParameter(self::LIMIT, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->courseId = $qb->getLastInsertId();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_pools')->values([
            'user_id' => $qb->createNamedParameter(self::TEACHER),
            'name' => $qb->createNamedParameter('exam race pool'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->poolId = $qb->getLastInsertId();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_pools')->values([
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_questions')->values([
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter(self::TEACHER),
            'text' => $qb->createNamedParameter('exam race question'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_members')->values([
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter(self::USER),
            'role' => $qb->createNamedParameter('student'),
            'enrolled_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    protected function tearDown(): void {
        foreach (['learning_sessions' => 'pool_id', 'learning_questions' => 'pool_id', 'learning_course_pools' => 'pool_id', 'learning_pools' => 'id'] as $table => $column) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
        }
        foreach (['learning_course_members' => 'course_id', 'learning_assignments' => 'course_id', 'learning_courses' => 'id'] as $table => $column) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))->executeStatement();
        }
        foreach ($this->configBefore as $key => $value) {
            if ($value === '') {
                $this->config->deleteAppValue('learning', $key);
            } else {
                $this->config->setAppValue('learning', $key, $value);
            }
        }
    }

    public function testParallelStartsAtTheLastAttemptStartOneExam(): void {
        for ($i = 1; $i < self::LIMIT; $i++) {
            $this->insertCompletedExam(time() - 3600 * $i);
        }

        $outcomes = $this->runWorkers();

        $seen = 'outcomes: ' . implode(', ', $outcomes);
        $this->assertSame(self::LIMIT, $this->countingExams(), "no more counting exams than the limit allows ({$seen})");
        $sessionIds = [];
        foreach ($outcomes as $line) {
            $this->assertStringStartsWith('OK ', $line, "a parallel start resumes the exam instead of being refused ({$seen})");
            $sessionIds[] = (int)substr($line, 3);
        }
        $this->assertCount(1, array_unique($sessionIds), "all parallel starts got the same exam ({$seen})");
    }

    private function insertCompletedExam(int $at): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')->values([
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter(self::USER),
            'started_at' => $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT),
            'completed_at' => $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT),
            'total_questions' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'correct_answers' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'mode' => $qb->createNamedParameter('exam'),
        ])->executeStatement();
    }

    private function countingExams(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from('learning_sessions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
            ->andWhere($qb->expr()->isNull('exam_kind'));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    /**
     * Starts WORKERS processes that each boot Nextcloud, waits until all report READY and only
     * then writes GO to every stdin, so the starts really run at the same moment.
     *
     * @return list<string> one result line per worker
     */
    private function runWorkers(): array {
        $script = __DIR__ . '/Support/exam_start_worker.php';
        $procs = [];
        $pipes = [];
        try {
            for ($i = 0; $i < self::WORKERS; $i++) {
                $cmd = [PHP_BINARY, $script, self::USER, (string)$this->poolId, (string)$this->courseId];
                $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p);
                $this->assertIsResource($proc, "worker {$i} did not start");
                $procs[$i] = $proc;
                $pipes[$i] = $p;
            }
            foreach ($pipes as $i => $p) {
                $line = trim((string)fgets($p[1]));
                if ($line !== 'READY') {
                    $this->fail("worker {$i}: {$line} " . stream_get_contents($p[2]));
                }
            }
            foreach ($pipes as $p) {
                fwrite($p[0], "GO\n");
            }
            foreach ($pipes as $p) {
                fclose($p[0]);
            }
            $outcomes = [];
            foreach ($pipes as $i => $p) {
                $out = trim((string)stream_get_contents($p[1]));
                $err = trim((string)stream_get_contents($p[2]));
                fclose($p[1]);
                fclose($p[2]);
                $code = proc_close($procs[$i]);
                unset($procs[$i]);
                $this->assertSame(0, $code, "worker {$i} exit {$code}: {$out} {$err}");
                $outcomes[] = $out;
            }
            return $outcomes;
        } finally {
            foreach ($procs as $proc) {
                proc_terminate($proc);
            }
        }
    }
}
