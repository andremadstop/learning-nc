<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\DocConfirmMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RequiredDocumentService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75: a read confirmation of a required document counts only for the current
 * certification cycle (course option), for the version of the file that was read (a rename is
 * no new version), and not from before an instructor's "Ask everyone to confirm again".
 * Before 5.14.0 a confirmation counted forever: the gate only checked completed_at IS NOT NULL.
 */
#[AllowMockObjectsWithoutExpectations]
class RequiredDocumentServiceTest extends TestCase {
    private const V1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const V2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    // ---- the rule --------------------------------------------------------------------------

    public function testNoConfirmationIsNotConfirmed(): void {
        $state = RequiredDocumentService::evaluate(null, 0, ['version' => self::V1, 'mtime' => 1]);
        self::assertFalse($state['confirmed']);
        self::assertFalse($state['updated']);
        self::assertFalse($state['again']);
    }

    public function testConfirmationOfTheCurrentVersionCounts(): void {
        $state = RequiredDocumentService::evaluate(['confirmed_at' => 500, 'doc_version' => self::V1], 0, ['version' => self::V1, 'mtime' => 100]);
        self::assertTrue($state['confirmed']);
    }

    public function testChangedVersionAsksAgainAsUpdated(): void {
        $state = RequiredDocumentService::evaluate(['confirmed_at' => 500, 'doc_version' => self::V1], 0, ['version' => self::V2, 'mtime' => 600]);
        self::assertFalse($state['confirmed']);
        self::assertTrue($state['updated'], '"Updated since you confirmed it"');
    }

    public function testConfirmationFromAnEarlierCycleDoesNotCount(): void {
        // cycle (renewal window) opened at 1000, confirmed last year at 500
        $state = RequiredDocumentService::evaluate(['confirmed_at' => 500, 'doc_version' => self::V1], 1000, ['version' => self::V1, 'mtime' => 100]);
        self::assertFalse($state['confirmed']);
        self::assertTrue($state['again']);
        self::assertFalse($state['updated']);
        // confirmed again inside the new cycle
        self::assertTrue(RequiredDocumentService::evaluate(['confirmed_at' => 1000, 'doc_version' => self::V1], 1000, ['version' => self::V1, 'mtime' => 100])['confirmed']);
    }

    public function testConfirmationFromBefore514CountsUntilTheFileIsModified(): void {
        // taken over by the migration: no version stored
        self::assertTrue(RequiredDocumentService::evaluate(['confirmed_at' => 500, 'doc_version' => null], 0, ['version' => self::V1, 'mtime' => 400])['confirmed']);
        $later = RequiredDocumentService::evaluate(['confirmed_at' => 500, 'doc_version' => null], 0, ['version' => self::V1, 'mtime' => 900]);
        self::assertFalse($later['confirmed']);
        self::assertTrue($later['updated']);
    }

    public function testUnreadableFileDoesNotInvalidate(): void {
        self::assertTrue(RequiredDocumentService::evaluate(['confirmed_at' => 500, 'doc_version' => self::V1], 0, null)['confirmed']);
    }

    public function testValidFromIsTheLaterOfCycleAndReconfirmRequest(): void {
        $video = new CourseVideo();
        self::assertSame(0, RequiredDocumentService::validFrom($video, null));
        self::assertSame(1000, RequiredDocumentService::validFrom($video, 1000));
        $video->setConfirmAfter(2000);
        self::assertSame(2000, RequiredDocumentService::validFrom($video, 1000));
        self::assertSame(3000, RequiredDocumentService::validFrom($video, 3000));
    }

    // ---- the service ----------------------------------------------------------------------

    /**
     * @param array<string, array<int, array{confirmed_at: int, doc_version: string|null}>> $latest
     */
    private function service(
        array $latest,
        ?array $file,
        bool $perCycle,
        ?int $cycleStart,
        ?AuditService $audit = null,
        ?DocConfirmMapper $confirms = null,
        int $now = 5000,
    ): RequiredDocumentService {
        $course = new Course();
        $course->setId(9);
        $course->setReconfirmPerCycle($perCycle);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        if ($confirms === null) {
            $confirms = $this->createMock(DocConfirmMapper::class);
            $confirms->method('latestByUsers')->willReturn($latest);
        }
        $documents = $this->createMock(DocumentService::class);
        $documents->method('currentFileInfo')->willReturn($file);
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('confirmationCycleStarts')->willReturnCallback(
            static fn(Course $c, array $uids): array => array_fill_keys($uids, $cycleStart)
        );
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);

        return new RequiredDocumentService(
            $confirms,
            $this->createMock(CourseVideoMapper::class),
            $courseMapper,
            $documents,
            $pass,
            $audit ?? $this->createMock(AuditService::class),
            $time,
            new FakeDbConnection(),
        );
    }

    private static function document(): CourseVideo {
        $video = new CourseVideo();
        $video->setId(31);
        $video->setCourseId(9);
        $video->setSourceType('document');
        $video->setVideoRef('12');
        $video->setDocumentId(12);
        return $video;
    }

    private static function course(bool $perCycle): Course {
        $course = new Course();
        $course->setId(9);
        $course->setReconfirmPerCycle($perCycle);
        return $course;
    }

    public function testCycleIsIgnoredWithoutTheCourseOption(): void {
        $latest = ['anna' => [31 => ['confirmed_at' => 500, 'doc_version' => self::V1]]];
        $file = ['version' => self::V1, 'mtime' => 100];

        self::assertTrue($this->service($latest, $file, false, 1000)->isConfirmed(self::course(false), self::document(), 'anna'));
        self::assertFalse($this->service($latest, $file, true, 1000)->isConfirmed(self::course(true), self::document(), 'anna'));
    }

    public function testConfirmAppendsTheCurrentVersionAndAuditsIt(): void {
        $confirms = $this->createMock(DocConfirmMapper::class);
        $calls = 0;
        $confirms->method('latestByUsers')->willReturnCallback(function () use (&$calls) {
            return $calls++ === 0 ? [] : ['anna' => [31 => ['confirmed_at' => 5000, 'doc_version' => self::V2]]];
        });
        $confirms->expects($this->once())->method('insert')->with('anna', 31, 9, 5000, self::V2);
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::VIDEO_COMPLETED,
            'anna',
            ['course_id' => 9, 'content_id' => 31, 'document_version' => self::V2]
        );

        $state = $this->service([], ['version' => self::V2, 'mtime' => 100], false, null, $audit, $confirms)
            ->confirm(self::document(), 'anna');

        self::assertTrue($state['confirmed']);
    }

    public function testConfirmIsANoOpWhileAValidConfirmationExists(): void {
        $confirms = $this->createMock(DocConfirmMapper::class);
        $confirms->method('latestByUsers')->willReturn(['anna' => [31 => ['confirmed_at' => 4000, 'doc_version' => self::V1]]]);
        $confirms->expects($this->never())->method('insert');
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logComplianceEvent');

        $state = $this->service([], ['version' => self::V1, 'mtime' => 100], false, null, $audit, $confirms)
            ->confirm(self::document(), 'anna');

        self::assertTrue($state['confirmed']);
    }

    public function testReconfirmRequestIsAuditedWithTheInstructorAsActor(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::CONTENT_RECONFIRM_REQUESTED,
            'teacher',
            ['course_id' => 9, 'content_id' => 31]
        );
        $mapper = $this->createMock(CourseVideoMapper::class);
        $mapper->method('update')->willReturnArgument(0);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(7000);
        $service = new RequiredDocumentService(
            $this->createMock(DocConfirmMapper::class),
            $mapper,
            $this->createMock(CourseMapper::class),
            $this->createMock(DocumentService::class),
            $this->createMock(PassCriteriaService::class),
            $audit,
            $time,
            new FakeDbConnection(),
        );

        $video = $service->requestReconfirm(self::document(), 'teacher');

        self::assertSame(7000, $video->getConfirmAfter());
    }

    /**
     * GitHub #75 (review): the coverage report asks for all learners' cycles at once and never
     * reloads the course per learner; a confirmation from before a learner's cycle is not counted.
     */
    public function testCoverageReadsTheCyclesOfAllLearnersInOneCall(): void {
        $latest = [
            'anna' => [31 => ['confirmed_at' => 4000, 'doc_version' => self::V1]],
            'ben' => [31 => ['confirmed_at' => 4000, 'doc_version' => self::V1]],
        ];
        $course = self::course(true);
        $documents = $this->createMock(DocumentService::class);
        $documents->method('currentFileInfo')->willReturn(['version' => self::V1, 'mtime' => 100]);
        $confirms = $this->createMock(DocConfirmMapper::class);
        $confirms->method('latestByUsers')->willReturn($latest);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->expects($this->never())->method('findById');
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('confirmationCycleStart');
        $pass->expects($this->once())->method('confirmationCycleStarts')
            ->with($course, ['anna', 'ben'])
            ->willReturn(['anna' => 3000, 'ben' => 4500]);
        $service = new RequiredDocumentService(
            $confirms,
            $this->createMock(CourseVideoMapper::class),
            $courseMapper,
            $documents,
            $pass,
            $this->createMock(AuditService::class),
            $this->createMock(ITimeFactory::class),
            new FakeDbConnection(),
        );

        self::assertSame(['anna' => 1], $service->countConfirmedByUsers($course, [self::document()], ['anna', 'ben']));
    }

    public function testCoverageCountsWithTheSameRuleAndReadsEachFileOnce(): void {
        $latest = [
            'anna' => [31 => ['confirmed_at' => 4000, 'doc_version' => self::V1]],
            'ben' => [31 => ['confirmed_at' => 4000, 'doc_version' => self::V2]],
        ];
        $course = self::course(false);
        $documents = $this->createMock(DocumentService::class);
        $documents->expects($this->once())->method('currentFileInfo')->willReturn(['version' => self::V1, 'mtime' => 100]);
        $confirms = $this->createMock(DocConfirmMapper::class);
        $confirms->method('latestByUsers')->willReturn($latest);
        $service = new RequiredDocumentService(
            $confirms,
            $this->createMock(CourseVideoMapper::class),
            $this->createMock(CourseMapper::class),
            $documents,
            $this->createMock(PassCriteriaService::class),
            $this->createMock(AuditService::class),
            $this->createMock(ITimeFactory::class),
            new FakeDbConnection(),
        );

        self::assertSame(['anna' => 1], $service->countConfirmedByUsers($course, [self::document()], ['anna', 'ben', 'carl']));
    }
}
