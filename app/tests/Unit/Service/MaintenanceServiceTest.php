<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\FsrsService;
use OCA\Learning\Service\MaintenanceService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit S23: toggleMaintenance() let a non-owner through only with the member role
 * 'co-instructor', which nothing ever stores — co-instructors are members with role
 * 'instructor' (CourseEnrollmentService::addMember, isInstructorOfCourse). They got a 403.
 */
#[AllowMockObjectsWithoutExpectations]
class MaintenanceServiceTest extends TestCase {
    private function serviceWithMemberRole(array|false $memberRow, ?CourseMapper &$mapper = null): MaintenanceService {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('owner');
        $mapper = $this->createMock(CourseMapper::class);
        // Argument checked in the callback: with() on a plain stub is deprecated in PHPUnit 13.
        $mapper->method('findById')->willReturnCallback(function (int $id) use ($course): Course {
            $this->assertSame(7, $id, 'the service must look up the requested course');
            return $course;
        });

        return new MaintenanceService(
            new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch($memberRow))]),
            $mapper,
            $this->createMock(FsrsService::class),
            $this->createMock(TranslationService::class),
            $this->createMock(IConfig::class)
        );
    }

    public function testCoInstructorMayToggle(): void {
        $service = $this->serviceWithMemberRole(['role' => 'instructor'], $mapper);
        $mapper->expects($this->once())->method('update');
        $this->assertTrue($service->toggleMaintenance(7, true, 'co-teacher'));
    }

    public function testStudentMayNotToggle(): void {
        $service = $this->serviceWithMemberRole(['role' => 'student'], $mapper);
        $mapper->expects($this->never())->method('update');
        $this->expectException(ForbiddenException::class);
        $service->toggleMaintenance(7, true, 'student');
    }

    public function testNonMemberMayNotToggle(): void {
        $service = $this->serviceWithMemberRole(false, $mapper);
        $this->expectException(ForbiddenException::class);
        $service->toggleMaintenance(7, true, 'stranger');
    }

    /**
     * Audit #229: the maintenance queue (now opened by the dashboard widget, audit #41) shipped
     * the explanation and the raw PBQ config with its solution while an exam was open on the pool.
     *
     * @param array<int, array<string, mixed>> $examRows
     * @return array<int, array<string, mixed>>
     */
    private function maintenanceQueue(array $examRows, ?TranslationService $translation = null): array {
        $items = [
            ['id' => 1, 'question_id' => 10, 'pool_id' => 5, 'user_id' => 'alice', 'box' => 1, 'next_review' => 0,
                'stability' => null, 'last_reviewed' => null, 'text' => 'PBQ', 'explanation' => 'because',
                'question_type' => 'pbq', 'pbq_subtype' => 'dropdown',
                'pbq_config' => '{"questions":[{"id":"q1","options":["a","b"],"correct":"b"}]}', 'pool_name' => 'P'],
            ['id' => 2, 'question_id' => 11, 'pool_id' => 6, 'user_id' => 'alice', 'box' => 1, 'next_review' => 0,
                'stability' => null, 'last_reviewed' => null, 'text' => 'Open', 'explanation' => 'why',
                'question_type' => 'open', 'pbq_subtype' => null, 'pbq_config' => null, 'pool_name' => 'Q'],
        ];
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult([['id' => 3]])),                      // maintenance courses
            new FakeQueryBuilder(new FakeResult([['pool_id' => 5], ['pool_id' => 6]])), // their pools
            new FakeQueryBuilder(FakeResult::fromFetchAll($items)),                    // due items
            new FakeQueryBuilder(new FakeResult([                                      // answers
                ['id' => 90, 'question_id' => 11, 'text' => 'model answer', 'is_correct' => 1, 'position' => 0],
            ])),
            new FakeQueryBuilder(new FakeResult($examRows)),                           // open exams
        ]);
        if ($translation === null) {
            $translation = $this->createMock(TranslationService::class);
            $translation->method('translateQuestions')->willReturnArgument(0);
        }
        $service = new MaintenanceService(
            $db,
            $this->createMock(CourseMapper::class),
            new FsrsService(),
            $translation,
            $this->createMock(IConfig::class)
        );
        $byId = [];
        foreach ($service->getMaintenanceQueue('alice', 10) as $item) {
            $byId[(int) $item['id']] = $item;
        }
        return $byId;
    }

    public function testMaintenanceQueueStripsSolutionsOfAPoolWithAnOpenExam(): void {
        $items = $this->maintenanceQueue([['pool_id' => 5]]);

        $this->assertArrayNotHasKey('explanation', $items[1]);
        $this->assertSame(['questions' => [['id' => 'q1', 'options' => ['a', 'b']]]], $items[1]['pbq_config']);
        // the other pool has no exam open
        $this->assertSame('why', $items[2]['explanation']);
    }

    public function testMaintenanceQueueDecodesPbqConfigWithoutAnExam(): void {
        $items = $this->maintenanceQueue([]);

        $this->assertSame('because', $items[1]['explanation']);
        $this->assertSame('b', $items[1]['pbq_config']['questions'][0]['correct']);
    }

    public function testMaintenanceQueueHidesTheTranslatedModelAnswerOfAnOpenQuestion(): void {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('translateQuestions')->willReturnCallback(static function (array $items): array {
            foreach ($items as &$item) {
                foreach ($item['answers'] ?? [] as $i => $answer) {
                    $item['answers'][$i]['text'] = 'translated model answer';
                }
            }
            return $items;
        });

        $items = $this->maintenanceQueue([], $translation);

        $this->assertSame('', $items[2]['answers'][0]['text']);
        $this->assertArrayNotHasKey('is_correct', $items[2]['answers'][0]);
    }
}
