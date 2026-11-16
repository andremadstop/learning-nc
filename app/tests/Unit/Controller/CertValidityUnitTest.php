<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #75: the certificate validity is entered in days, months or years (a year is stored as
 * 12 calendar months). Switching back to days must clear the months: a set months value wins over
 * the days in IssuanceService::computeExpiry(), and 0 months means "no expiry", so the old API
 * (null = unchanged) could not return a course to days at all.
 */
#[AllowMockObjectsWithoutExpectations]
class CertValidityUnitTest extends TestCase {
    private Course $course;

    private function controller(): CourseController {
        $mapper = $this->createMock(CourseMapper::class);
        $mapper->method('findById')->willReturn($this->course);
        $mapper->method('update')->willReturnArgument(0);
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));
        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $mapper,
            $members,
            $this->createMock(CourseArchiveService::class),
            $this->createMock(CourseService::class),
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(\OCA\Learning\Service\CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            'teacher'
        );
    }

    protected function setUp(): void {
        $this->course = new Course();
        $this->course->setId(7);
        $this->course->setInstructorId('teacher');
        $this->course->setCertValidityDays(365);
        $this->course->setCertValidityMonths(12);
    }

    public function testDaysUnitClearsTheMonths(): void {
        $response = $this->controller()->updateCertConfig(7, certValidityDays: 90, certValidityUnit: 'days');

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($this->course->getCertValidityMonths(), 'NULL = the days govern the expiry');
        $this->assertSame(90, $this->course->getCertValidityDays());
    }

    public function testYearsAreSavedAsMonths(): void {
        $this->course->setCertValidityMonths(null);
        $response = $this->controller()->updateCertConfig(7, certValidityMonths: 24, certValidityUnit: 'months');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(24, $this->course->getCertValidityMonths());
    }

    public function testExistingDaysStayValidWhenTheUnitIsNotSent(): void {
        $this->course->setCertValidityMonths(null);
        $response = $this->controller()->updateCertConfig(7, certPassPercent: 70);

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($this->course->getCertValidityMonths());
        $this->assertSame(365, $this->course->getCertValidityDays());
    }

    public function testMonthsUnitWithoutAValueIsRefused(): void {
        $response = $this->controller()->updateCertConfig(7, certValidityUnit: 'months');
        $this->assertSame(400, $response->getStatus());
        $response = $this->controller()->updateCertConfig(7, certValidityUnit: 'weeks');
        $this->assertSame(400, $response->getStatus());
    }
}
