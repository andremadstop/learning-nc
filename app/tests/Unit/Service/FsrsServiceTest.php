<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\FsrsService;
use PHPUnit\Framework\TestCase;

class FsrsServiceTest extends TestCase {
    public function testInitializeFromRatingUsesFsrsDefaults(): void {
        $service = new FsrsService();

        $initial = $service->initializeFromRating(1);

        $this->assertSame(0.4, $initial['stability']);
        $this->assertSame(1.0, $initial['difficulty']);
        $this->assertSame(1, $initial['interval_days']);
        $this->assertSame(1.0, $initial['retrievability']);
    }

    public function testReviewReturnsStablePositiveValues(): void {
        $service = new FsrsService();

        $result = $service->review(3.0, 0.5, 2, 3.0);

        $this->assertGreaterThan(0.0, $result['stability']);
        $this->assertGreaterThanOrEqual(0.1, $result['difficulty']);
        $this->assertLessThanOrEqual(1.0, $result['difficulty']);
        $this->assertGreaterThanOrEqual(1, $result['interval_days']);
        $this->assertEqualsWithDelta(0.9, $result['retrievability'], 0.0001);
    }

    public function testEasyReviewProducesHigherStabilityThanAgain(): void {
        $service = new FsrsService();

        $again = $service->review(3.0, 0.5, 1, 3.0);
        $easy = $service->review(3.0, 0.5, 4, 3.0);

        $this->assertGreaterThan($again['stability'], $easy['stability']);
        $this->assertLessThan($again['difficulty'], $easy['difficulty']);
    }
}
