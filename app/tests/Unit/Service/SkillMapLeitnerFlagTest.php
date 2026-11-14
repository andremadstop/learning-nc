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
 * GitHub #36: the Skill Map sidebar showed Leitner box rows for pools of a course with Leitner
 * switched off (they stay empty there). Each pool now says whether its course offers Leitner.
 */
class SkillMapLeitnerFlagTest extends TestCase {
    /** @param list<array<string, mixed>> $rows */
    private function enrich(array $rows, array $pools): array {
        $qb = new FakeQueryBuilder(new FakeResult([], $rows));
        $service = new LernprofilService(new FakeDbConnection([$qb]), new FakeCacheFactory());
        $enriched = $service->enrichPoolsWithCourseData('learner', $pools);
        $this->assertContains('c.mode_config', $qb->selects, 'the course mode config is read');
        return $enriched;
    }

    public function testPoolsCarryTheLeitnerSwitchOfTheirCourse(): void {
        $enriched = $this->enrich([
            ['pool_id' => 1, 'course_id' => 7, 'course_name' => 'Pflichtschulung', 'mode_config' => json_encode(['leitner' => false])],
            ['pool_id' => 2, 'course_id' => 8, 'course_name' => 'IT', 'mode_config' => json_encode(['leitner' => true])],
            ['pool_id' => 3, 'course_id' => 9, 'course_name' => 'Alt', 'mode_config' => null],
        ], [['pool_id' => 1], ['pool_id' => 2], ['pool_id' => 3], ['pool_id' => 4]]);

        $byPool = array_column($enriched, 'leitner_enabled', 'pool_id');
        $this->assertFalse($byPool[1]);
        $this->assertTrue($byPool[2]);
        $this->assertTrue($byPool[3], 'a course saved without mode config keeps Leitner');
        $this->assertTrue($byPool[4], 'a pool outside any course has Leitner');
    }

    public function testAPoolInSeveralCoursesHasLeitnerAsSoonAsOneOfThemOffersIt(): void {
        // Course 7 (lowest id) is a compliance course without Leitner, course 8 an IT course
        // with Leitner. The learner reaches both; the boxes from course 8 must stay visible.
        $enriched = $this->enrich([
            ['pool_id' => 1, 'course_id' => 7, 'course_name' => 'First', 'mode_config' => json_encode(['leitner' => false])],
            ['pool_id' => 1, 'course_id' => 8, 'course_name' => 'Second', 'mode_config' => json_encode(['leitner' => true])],
        ], [['pool_id' => 1]]);

        $this->assertSame('First', $enriched[0]['course_name'], 'the label stays with the first course');
        $this->assertSame(7, $enriched[0]['course_id']);
        $this->assertTrue($enriched[0]['leitner_enabled']);
    }

    public function testAPoolWhoseCoursesAllSwitchedLeitnerOffHasNone(): void {
        $enriched = $this->enrich([
            ['pool_id' => 1, 'course_id' => 7, 'course_name' => 'First', 'mode_config' => json_encode(['leitner' => false])],
            ['pool_id' => 1, 'course_id' => 8, 'course_name' => 'Second', 'mode_config' => json_encode(['leitner' => false])],
        ], [['pool_id' => 1]]);

        $this->assertFalse($enriched[0]['leitner_enabled']);
    }
}
