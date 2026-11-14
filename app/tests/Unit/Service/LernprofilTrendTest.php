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
 * 2026-11 audit S22: getLernhistorie() reads sessions oldest first (ORDER BY completed_at ASC),
 * while computeTrend() treats the first half of its input as the recent one. A learner whose
 * accuracy rose was told "declining" — in the API and in Fortschritt.md ("Trend: Rückgang").
 */
class LernprofilTrendTest extends TestCase {
    /**
     * @param list<float> $accuracies oldest first; the session query returns them newest first
     *        (ORDER BY completed_at DESC, audit #79), so the fake hands them out reversed
     */
    private function historyFor(array $accuracies): array {
        $rows = [];
        $start = time() - 20 * 86400;
        foreach ($accuracies as $i => $accuracy) {
            $rows[] = [
                'started_at' => $start + $i * 86400 - 600,
                'completed_at' => $start + $i * 86400,
                'total_questions' => 10,
                'correct_answers' => (int)round($accuracy * 10),
                'mode' => 'training',
            ];
        }
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetchAll(array_reverse($rows)))]);
        $service = new LernprofilService($db, new FakeCacheFactory());
        return $service->getLernhistorie('learner', 30);
    }

    public function testRisingAccuracyIsImproving(): void {
        $this->assertSame('improving', $this->historyFor([0.2, 0.4, 0.8, 0.9])['overall_trend']);
    }

    public function testFallingAccuracyIsDeclining(): void {
        $this->assertSame('declining', $this->historyFor([0.9, 0.8, 0.4, 0.2])['overall_trend']);
    }
}
