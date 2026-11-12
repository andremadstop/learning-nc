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
 * 'instructor' (CourseService::addMember, isInstructorOfCourse). They got a 403.
 */
#[AllowMockObjectsWithoutExpectations]
class MaintenanceServiceTest extends TestCase {
    private function serviceWithMemberRole(array|false $memberRow, ?CourseMapper &$mapper = null): MaintenanceService {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('owner');
        $mapper = $this->createMock(CourseMapper::class);
        $mapper->method('findById')->willReturn($course);

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
}
