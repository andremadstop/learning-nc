<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgress;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\RequiredDocumentService;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75: the exam gate decides required documents by the confirmation rule. Before 5.14.0
 * a completed progress row from any earlier year (or for an older file) unlocked the exam.
 */
#[AllowMockObjectsWithoutExpectations]
class RequiredDocumentGateTest extends TestCase {
    private function gate(bool $confirmedNow, ?VideoProgress $oldRow): VideoProgressService {
        $document = new CourseVideo();
        $document->setId(31);
        $document->setCourseId(9);
        $document->setSourceType('document');
        $document->setVideoRef('12');
        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->method('findByCourse')->willReturn([$document]);
        // the progress row the gate read before 5.14.0: completed last year
        $progress = $this->createMock(VideoProgressMapper::class);
        $progress->method('findByUserAndContent')->willReturn($oldRow);
        $required = $this->createMock(RequiredDocumentService::class);
        $required->method('isConfirmed')->willReturn($confirmedNow);
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn(new Course());

        return new VideoProgressService(
            $progress,
            $videos,
            $this->createMock(ITimeFactory::class),
            $this->createMock(AuditService::class),
            $this->createMock(IDBConnection::class),
            $required,
            $courses,
        );
    }

    public function testAConfirmationOfAnEarlierCycleNoLongerUnlocksTheExam(): void {
        $old = new VideoProgress();
        $old->setCompletedAt(1_700_000_000);

        $this->expectException(ForbiddenException::class);
        $this->gate(false, $old)->assertCourseVideosComplete(9, 'anna');
    }

    public function testAValidConfirmationUnlocksTheExam(): void {
        $this->gate(true, null)->assertCourseVideosComplete(9, 'anna');
        $this->addToAssertionCount(1);
    }
}
