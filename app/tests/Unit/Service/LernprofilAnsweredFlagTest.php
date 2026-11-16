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
 * Fresh-eyes C3 (#36): the personal settings listed "Fire safety · 50 %" as a weakest topic for a
 * learner who had never answered a question. A pool without Leitner answers and without training
 * sessions gets the neutral error rate 0.5; each pool now says whether it was answered at all.
 */
class LernprofilAnsweredFlagTest extends TestCase {
    private function rows(array $rows): FakeQueryBuilder {
        return new FakeQueryBuilder(FakeResult::fromFetchAll($rows));
    }

    /** Course 7 with pools 1 (untouched) and 2 (one training session, 4 of 8 right). */
    private function profile(array $leitnerRows = []): array {
        $db = new FakeDbConnection([
            $this->rows([['pool_id' => 1], ['pool_id' => 2]]),                  // resolvePoolIds
            $this->rows($leitnerRows),                                           // Leitner boxes
            $this->rows([]),                                                     // training pool 1
            $this->rows([['pool_id' => 2, 'correct_answers' => 4, 'total_questions' => 8, 'completed_at' => 100]]),
            $this->rows([]),                                                     // exam pool 1
            $this->rows([]),                                                     // exam pool 2
            $this->rows([['id' => 1, 'name' => 'Fire safety'], ['id' => 2, 'name' => 'Data protection']]),
        ]);
        $service = new LernprofilService($db, new FakeCacheFactory());
        return array_column($service->aggregateProfile('learner', 7)['pools'], null, 'pool_id');
    }

    public function testAPoolWithoutAnyAnswerIsMarkedUnanswered(): void {
        $pools = $this->profile();

        $this->assertFalse($pools[1]['answered']);
        $this->assertSame(50.0, $pools[1]['error_rate'], 'the planners keep the neutral rate');
        $this->assertTrue($pools[2]['answered']);
        $this->assertSame(50.0, $pools[2]['error_rate']);
    }

    public function testLeitnerAnswersCountAsAnswered(): void {
        $pools = $this->profile([['pool_id' => 1, 'box' => 2, 'cnt' => 3, 'correct_count' => 2, 'incorrect_count' => 1]]);

        $this->assertTrue($pools[1]['answered']);
    }

    public function testLeitnerCardsWithoutAnswersAreNoAnswer(): void {
        $pools = $this->profile([['pool_id' => 1, 'box' => 1, 'cnt' => 8, 'correct_count' => 0, 'incorrect_count' => 0]]);

        $this->assertFalse($pools[1]['answered']);
    }

    /** Six untouched pools rank at 50 % above an answered pool at 25 %; the cap must not drop it. */
    public function testTheDisplayListKeepsAnAnsweredPoolBehindManyUntouchedOnes(): void {
        $ids = [1, 2, 3, 4, 5, 6, 7];
        $builders = [$this->rows(array_map(static fn (int $id): array => ['pool_id' => $id], $ids)), $this->rows([])];
        foreach ($ids as $id) {                                                  // training per pool
            $builders[] = $this->rows($id === 7 ? [['pool_id' => 7, 'correct_answers' => 6, 'total_questions' => 8, 'completed_at' => 100]] : []);
        }
        foreach ($ids as $id) {                                                  // exam per pool
            $builders[] = $this->rows([]);
        }
        $builders[] = $this->rows(array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Pool ' . $id], $ids));
        $service = new LernprofilService(new FakeDbConnection($builders), new FakeCacheFactory());

        $topics = $service->getWeakestAnsweredTopics('learner', 7, 5);

        $this->assertSame([7], array_column($topics, 'pool_id'));
        $this->assertSame(25.0, $topics[0]['error_rate']);
    }
}
