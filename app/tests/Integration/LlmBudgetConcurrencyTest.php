<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\Llm\LlmBudget;
use OCA\Learning\Service\Llm\LlmBudgetStore;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 Task B2 (spec 5.1) against the real database: the conditional UPDATE decides, not a
 * read-then-write. 20 processes reserve at the same moment; exactly the limit may succeed.
 * Runs on PostgreSQL and MariaDB (scripts/nc-integration.sh, ENGINE=pgsql|mysql).
 */
final class LlmBudgetConcurrencyTest extends TestCase {
    private const T0 = 1_800_000_030;
    private const WORKERS = 20;

    private IDBConnection $db;
    private IAppConfig $appConfig;
    private LlmBudgetStore $store;
    private int $now = self::T0;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->store = Server::get(LlmBudgetStore::class);
        $this->clean();
    }

    protected function tearDown(): void {
        $this->clean();
    }

    private function clean(): void {
        $this->db->getQueryBuilder()->delete(LlmBudgetStore::TABLE)->executeStatement();
        $this->appConfig->deleteKey('learning', LlmBudget::GLOBAL_LIMIT_KEY);
    }

    private function budgetAt(): LlmBudget {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn () => $this->now);
        return new LlmBudget($this->store, $this->appConfig, $time);
    }

    /** @return array<string, mixed>|null */
    private function row(string $bucket, int $window): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('count', 'inflight', 'updated_at')->from(LlmBudgetStore::TABLE)
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter($window, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row === false ? null : $row;
    }

    private function rowCount(string $bucket): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from(LlmBudgetStore::TABLE)
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }

    private function assertRateLimited(callable $fn): void {
        try {
            $fn();
            $this->fail('expected rate_limit');
        } catch (LlmProviderException $e) {
            $this->assertSame('rate_limit', $e->category);
        }
    }

    public function testCountLimitHitsExactlyAtNPlusOne(): void {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->store->tryIncrementCount('probe:n', 600, 3, self::T0), "call {$i}");
        }
        $this->assertFalse($this->store->tryIncrementCount('probe:n', 600, 3, self::T0));
        $this->assertSame(3, (int)$this->row('probe:n', 600)['count']);

        $this->store->decrementCount('probe:n', 600);
        $this->assertTrue($this->store->tryIncrementCount('probe:n', 600, 3, self::T0));
        $this->assertFalse($this->store->tryIncrementCount('probe:n', 600, 0, self::T0), 'limit 0 never reserves');
    }

    public function testUserMinuteLimitThroughTheBudget(): void {
        $budget = $this->budgetAt();
        for ($i = 0; $i < LlmBudget::USER_PER_MINUTE; $i++) {
            $budget->release($budget->reserve('b2-alice', LlmPurpose::Chat));
        }
        $this->assertRateLimited(fn () => $budget->reserve('b2-alice', LlmPurpose::Chat));

        $minute = intdiv(self::T0, 60) * 60;
        $this->assertSame(10, (int)$this->row(LlmBudget::userBucket('b2-alice', 'm'), $minute)['count']);
        $this->assertSame(10, (int)$this->row(LlmBudget::GLOBAL_BUCKET, intdiv(self::T0, 86400) * 86400)['count'],
            'the rejected eleventh call gave its global count back');
    }

    public function testANewWindowRemovesTheOlderOnes(): void {
        $budget = $this->budgetAt();
        $bucket = LlmBudget::userBucket('b2-alice', 'm');
        $budget->release($budget->reserve('b2-alice', LlmPurpose::Chat));
        $this->now += 60;
        $budget->release($budget->reserve('b2-alice', LlmPurpose::Chat));
        $this->now += 60;
        $budget->release($budget->reserve('b2-alice', LlmPurpose::Chat));

        $this->assertSame(1, $this->rowCount($bucket));
        $this->assertSame(1, (int)$this->row($bucket, intdiv($this->now, 60) * 60)['count']);
    }

    public function testSlotsAreFreedByReleaseAndAfterTheTtl(): void {
        $budget = $this->budgetAt();
        $slot = LlmBudget::userBucket('b2-alice', 'inflight');
        $first = $budget->reserve('b2-alice', LlmPurpose::Chat);
        $budget->reserve('b2-alice', LlmPurpose::Chat);
        $budget->reserve('b2-alice', LlmPurpose::Chat);
        $this->assertRateLimited(fn () => $budget->reserve('b2-alice', LlmPurpose::Chat));
        $this->assertSame(3, (int)$this->row($slot, 0)['inflight']);

        $budget->release($first);
        $budget->release($first);
        $this->assertSame(2, (int)$this->row($slot, 0)['inflight'], 'a second release is a no-op');
        $budget->reserve('b2-alice', LlmPurpose::Chat);
        $this->assertSame(3, (int)$this->row($slot, 0)['inflight']);

        // three orphaned slots: still blocked at exactly the TTL, free one second later
        $this->now += LlmBudget::INFLIGHT_TTL;
        $this->assertRateLimited(fn () => $budget->reserve('b2-alice', LlmPurpose::Chat));
        $this->now += 1;
        $budget->reserve('b2-alice', LlmPurpose::Chat);
        $this->assertSame(1, (int)$this->row($slot, 0)['inflight']);
    }

    public function testLongUserIdsFitTheBucketColumn(): void {
        $uid = str_repeat('z', 64);
        $budget = $this->budgetAt();
        $budget->release($budget->reserve($uid, LlmPurpose::Chat));
        $this->assertSame(1, (int)$this->row(LlmBudget::userBucket($uid, 'm'), intdiv(self::T0, 60) * 60)['count']);
    }

    public function testTwentyParallelReservationsAgainstAGlobalLimitOfTenGiveExactlyTen(): void {
        $this->appConfig->setValueString('learning', LlmBudget::GLOBAL_LIMIT_KEY, '10');
        $outcomes = $this->runWorkers('-', LlmPurpose::AdminTest);

        $this->assertSame(['OK' => 10, 'RATE_LIMIT' => 10], $outcomes);
        $day = intdiv(time(), 86400) * 86400;
        $this->assertSame(10, (int)$this->row(LlmBudget::GLOBAL_BUCKET, $day)['count']);
    }

    public function testTwentyParallelCallsOfOneUserGetExactlyThreeSlots(): void {
        $outcomes = $this->runWorkers('b2-parallel', LlmPurpose::Chat);

        $this->assertSame(['OK' => 3, 'RATE_LIMIT' => 17], $outcomes);
        $this->assertSame(3, (int)$this->row(LlmBudget::userBucket('b2-parallel', 'inflight'), 0)['inflight']);
        // every rejected call gave its counts back: only the three successful ones remain
        $day = intdiv(time(), 86400) * 86400;
        $this->assertSame(3, (int)$this->row(LlmBudget::userBucket('b2-parallel', 'd'), $day)['count']);
    }

    /**
     * Starts WORKERS processes that each boot Nextcloud, waits until all report READY and only
     * then writes GO to every stdin, so the reservations really run at the same moment.
     *
     * @return array<string, int> outcome => number of workers
     */
    private function runWorkers(string $uid, LlmPurpose $purpose): array {
        // the minute window must not roll over between the first and the last reservation
        while (time() % 60 > 40) {
            usleep(200_000);
        }
        $script = __DIR__ . '/Support/llm_budget_worker.php';
        $procs = [];
        $pipes = [];
        try {
            for ($i = 0; $i < self::WORKERS; $i++) {
                $cmd = [PHP_BINARY, $script, $uid, $purpose->value];
                $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p);
                $this->assertIsResource($proc, "worker {$i} did not start");
                $procs[$i] = $proc;
                $pipes[$i] = $p;
            }
            foreach ($pipes as $i => $p) {
                $line = trim((string)fgets($p[1]));
                if ($line !== 'READY') {
                    // stderr only now: reading it earlier would block until the worker exits
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
                $outcomes[$out] = ($outcomes[$out] ?? 0) + 1;
            }
            ksort($outcomes);
            return $outcomes;
        } finally {
            foreach ($procs as $proc) {
                proc_terminate($proc);
            }
        }
    }
}
