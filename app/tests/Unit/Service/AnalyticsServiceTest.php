<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Analytics;
use OCA\Learning\Db\AnalyticsMapper;
use OCA\Learning\Service\AnalyticsService;
use PHPUnit\Framework\TestCase;

class AnalyticsServiceTest extends TestCase {
    public function testRecordEncodesMetricValueBeforeDelegating(): void {
        $mapper = $this->createMock(AnalyticsMapper::class);
        $analytics = new Analytics();

        $mapper->expects($this->once())
            ->method('record')
            ->with('alice', 7, 'mastery', '{"value":75,"label":"Box 5"}')
            ->willReturn($analytics);

        $service = new AnalyticsService($mapper);

        $result = $service->record('alice', 7, 'mastery', [
            'value' => 75,
            'label' => 'Box 5',
        ]);

        $this->assertSame($analytics, $result);
    }

    public function testGetUserMetricsDelegatesToMapper(): void {
        $mapper = $this->createMock(AnalyticsMapper::class);
        $expected = [['metric_type' => 'sessions']];

        $mapper->expects($this->once())
            ->method('findByUser')
            ->with('alice')
            ->willReturn($expected);

        $service = new AnalyticsService($mapper);

        $this->assertSame($expected, $service->getUserMetrics('alice'));
    }

    public function testGetPoolMetricsDelegatesToMapper(): void {
        $mapper = $this->createMock(AnalyticsMapper::class);
        $expected = [['metric_type' => 'accuracy']];

        $mapper->expects($this->once())
            ->method('findByUserAndPool')
            ->with('alice', 11)
            ->willReturn($expected);

        $service = new AnalyticsService($mapper);

        $this->assertSame($expected, $service->getPoolMetrics('alice', 11));
    }

    public function testGetByTypeDelegatesToMapper(): void {
        $mapper = $this->createMock(AnalyticsMapper::class);
        $expected = [['metric_type' => 'streak']];

        $mapper->expects($this->once())
            ->method('findByType')
            ->with('streak', 'alice')
            ->willReturn($expected);

        $service = new AnalyticsService($mapper);

        $this->assertSame($expected, $service->getByType('streak', 'alice'));
    }
}
