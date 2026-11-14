<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;

/**
 * Row limits of the learning profile queries.
 *
 * Audit #78: training and exam stats ran ONE query over all pools, capped at count($pools) * 10
 * (or * 5) and ordered newest first. A learner with 30 recent sessions in pool A filled the whole
 * cap, so pools B and C came back without sessions and their error rate fell back to Leitner data
 * or the neutral 50 %. Each pool now gets its own query with its own limit.
 *
 * Audit #79: the history read ORDER BY completed_at ASC LIMIT 500, so a learner with more than 500
 * sessions in the window lost the NEWEST ones (the current weeks), not the oldest.
 */
class LernprofilQueryLimitsTest extends TestCase {
    private function session(int $poolId, int $completedAt, int $correct = 7): array {
        return ['pool_id' => $poolId, 'correct_answers' => $correct, 'total_questions' => 10, 'completed_at' => $completedAt];
    }

    /** @return array{0: LernprofilService, 1: FakeDbConnection} */
    private function serviceWith(array $resultsPerQuery): array {
        $builders = array_map(static fn (array $rows) => new FakeQueryBuilder(FakeResult::fromFetchAll($rows)), $resultsPerQuery);
        $db = new FakeDbConnection($builders);
        return [new LernprofilService($db, new FakeCacheFactory()), $db];
    }

    private function poolFilter(FakeQueryBuilder $qb): mixed {
        foreach ($qb->andWhereCalls as $condition) {
            if (($condition['left'] ?? null) === 'pool_id') {
                return $condition;
            }
        }
        return null;
    }

    public function testEveryPoolGetsItsOwnTrainingQueryAndStats(): void {
        $busy = array_map(fn (int $i) => $this->session(1, 10_000 - $i), range(0, 9));
        [$service, $db] = $this->serviceWith([$busy, [$this->session(2, 500, 3)], [$this->session(3, 400, 9)]]);

        $stats = (new \ReflectionMethod(LernprofilService::class, 'getTrainingStatsByPool'))
            ->invoke($service, 'learner', [1, 2, 3]);

        $this->assertSame([1, 2, 3], array_keys($stats));
        $this->assertSame(10, $stats[1]['sessions']);
        $this->assertSame(1, $stats[2]['sessions']);
        $this->assertSame(30.0, $stats[2]['accuracy']);
        $this->assertSame(90.0, $stats[3]['accuracy']);

        $this->assertCount(3, $db->issuedBuilders);
        foreach ([1, 2, 3] as $i => $poolId) {
            $qb = $db->issuedBuilders[$i];
            $this->assertSame(10, $qb->maxResults);
            $filter = $this->poolFilter($qb);
            $this->assertSame('eq', $filter['type'] ?? null, 'one pool per query, not IN (all pools)');
            $this->assertSame($poolId, $filter['right']['value'] ?? null);
        }
    }

    public function testEveryPoolGetsItsOwnExamQuery(): void {
        [$service, $db] = $this->serviceWith([
            [$this->session(1, 900), $this->session(1, 800)],
            [$this->session(2, 700, 5)],
        ]);

        $stats = (new \ReflectionMethod(LernprofilService::class, 'getExamStatsByPool'))
            ->invoke($service, 'learner', [1, 2]);

        $this->assertSame([1, 2], array_keys($stats));
        $this->assertSame(50.0, $stats[2]['last_score']);
        $this->assertCount(2, $db->issuedBuilders);
        foreach ([1, 2] as $i => $poolId) {
            $qb = $db->issuedBuilders[$i];
            $this->assertSame(5, $qb->maxResults);
            $this->assertSame($poolId, $this->poolFilter($qb)['right']['value'] ?? null);
        }
    }

    public function testHistoryCapKeepsTheNewestSessions(): void {
        $now = time();
        // what the database hands back for ORDER BY completed_at DESC LIMIT 500: the newest first
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['started_at' => $now - $i * 600 - 60, 'completed_at' => $now - $i * 600, 'total_questions' => 10, 'correct_answers' => 5, 'mode' => 'training'];
        }
        $qb = new FakeQueryBuilder(FakeResult::fromFetchAll($rows));
        $service = new LernprofilService(new FakeDbConnection([$qb]), new FakeCacheFactory());

        $history = $service->getLernhistorie('learner', 365);

        $this->assertSame([['field' => 'completed_at', 'direction' => 'DESC']], $qb->orderBys);
        $this->assertSame(500, $qb->maxResults);
        $this->assertSame(500, $history['total_sessions']);
        $last = end($history['history']);
        $this->assertSame(date('Y-m-d', $now), $last['date'], 'today, the newest day, is part of the history');
        $dates = array_column($history['history'], 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }
}
