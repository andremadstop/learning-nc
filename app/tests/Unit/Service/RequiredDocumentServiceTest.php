<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\DocConfirmMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\DocumentUnavailableException;
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
        self::assertSame(0, RequiredDocumentService::validFrom(null, null));
        self::assertSame(1000, RequiredDocumentService::validFrom(null, 1000));
        self::assertSame(2000, RequiredDocumentService::validFrom(2000, 1000));
        self::assertSame(3000, RequiredDocumentService::validFrom(2000, 3000));
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
        ?CourseDocumentMapper $documentMapper = null,
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
            $documentMapper ?? $this->createMock(CourseDocumentMapper::class),
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

    /**
     * GitHub #75 (review): an IT course with the exam lock but without "confirm per renewal". A
     * confirmation from 5.13 (no version) of a file touched after it (typo fix, sync client)
     * keeps counting after the upgrade, for the exam gate and the coverage report alike; it
     * used to lock the exam again. A confirmation made in 5.14 (with version) still asks again
     * after a content change in every course, and so does the old one in a per-cycle course.
     */
    public function testAConfirmationFrom513KeepsCountingInACourseWithoutPerCycle(): void {
        $latest = ['anna' => [31 => ['confirmed_at' => 500, 'doc_version' => null]]];
        $touched = ['version' => self::V1, 'mtime' => 900];

        self::assertTrue($this->service($latest, $touched, false, null)->isConfirmed(self::course(false), self::document(), 'anna'), 'exam gate, IT course');
        self::assertSame(['anna' => 1], $this->service($latest, $touched, false, null)->countConfirmedByUsers(self::course(false), [self::document()], ['anna']), 'coverage, IT course');
        self::assertFalse($this->service($latest, $touched, true, null)->isConfirmed(self::course(true), self::document(), 'anna'), 'per-cycle course: the touched file asks again');

        $versioned = ['anna' => [31 => ['confirmed_at' => 500, 'doc_version' => self::V2]]];
        self::assertFalse($this->service($versioned, $touched, false, null)->isConfirmed(self::course(false), self::document(), 'anna'), 'a 5.14 confirmation of another version');
    }

    public function testCycleIsIgnoredWithoutTheCourseOption(): void {
        $latest = ['anna' => [31 => ['confirmed_at' => 500, 'doc_version' => self::V1]]];
        $file = ['version' => self::V1, 'mtime' => 100];

        self::assertTrue($this->service($latest, $file, false, 1000)->isConfirmed(self::course(false), self::document(), 'anna'));
        self::assertFalse($this->service($latest, $file, true, 1000)->isConfirmed(self::course(true), self::document(), 'anna'));
    }

    /**
     * GitHub #84: after a revoke the instructor opens a new cycle, and the required documents are
     * confirmed again — also in a course without "confirm again at every renewal". A confirmation
     * from before the new cycle no longer counts (exam gate and coverage alike); one made in the
     * new cycle does.
     */
    public function testAfterARevokeTheDocumentsAreConfirmedAgainWithoutPerCycle(): void {
        $course = self::course(false);
        $course->setCertEnabled(true);
        $file = ['version' => self::V1, 'mtime' => 100];
        $old = ['anna' => [31 => ['confirmed_at' => 500, 'doc_version' => self::V1]]];
        $new = ['anna' => [31 => ['confirmed_at' => 2500, 'doc_version' => self::V1]]];

        $service = fn(array $latest): RequiredDocumentService => $this->serviceWithRevokeCycle($latest, $file, ['anna' => 2000]);
        self::assertFalse($service($old)->isConfirmed($course, self::document(), 'anna'), 'exam gate: confirmed before the revoke');
        self::assertSame([], $service($old)->countConfirmedByUsers($course, [self::document()], ['anna']), 'coverage: confirmed before the revoke');
        self::assertTrue($service($new)->isConfirmed($course, self::document(), 'anna'), 'confirmed again in the new cycle');
    }

    /** GitHub #84: an IT course without certification never looks for a revoke cycle. */
    public function testACourseWithoutCertificationIsUnchanged(): void {
        $course = self::course(false);
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('revokeCycleStarts');
        $pass->expects($this->never())->method('confirmationCycleStarts');
        $service = $this->serviceWithPass(['anna' => [31 => ['confirmed_at' => 500, 'doc_version' => self::V1]]], ['version' => self::V1, 'mtime' => 100], $pass);
        self::assertTrue($service->isConfirmed($course, self::document(), 'anna'));
    }

    /**
     * @param array<string, array<int, array{confirmed_at: int, doc_version: string|null}>> $latest
     * @param array<string, int> $revokeStarts
     */
    private function serviceWithRevokeCycle(array $latest, ?array $file, array $revokeStarts): RequiredDocumentService {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('confirmationCycleStarts')->willReturn([]);
        $pass->method('revokeCycleStarts')->willReturn($revokeStarts);
        return $this->serviceWithPass($latest, $file, $pass);
    }

    /**
     * @param array<string, array<int, array{confirmed_at: int, doc_version: string|null}>> $latest
     */
    private function serviceWithPass(array $latest, ?array $file, PassCriteriaService $pass): RequiredDocumentService {
        $confirms = $this->createMock(DocConfirmMapper::class);
        $confirms->method('latestByUsers')->willReturnCallback(
            static fn(int $courseId, array $uids): array => array_intersect_key($latest, array_flip($uids))
        );
        $documents = $this->createMock(DocumentService::class);
        $documents->method('currentFileInfo')->willReturn($file);
        return new RequiredDocumentService(
            $confirms,
            $this->createMock(CourseVideoMapper::class),
            $this->createMock(CourseMapper::class),
            $documents,
            $pass,
            $this->createMock(AuditService::class),
            $this->createMock(ITimeFactory::class),
            new FakeDbConnection(),
            $this->createMock(CourseDocumentMapper::class),
        );
    }

    public function testConfirmAppendsTheCurrentVersionAndAuditsIt(): void {
        $confirms = $this->createMock(DocConfirmMapper::class);
        $calls = 0;
        $confirms->method('latestByUsers')->willReturnCallback(function () use (&$calls) {
            return $calls++ === 0 ? [] : ['anna' => [31 => ['confirmed_at' => 5000, 'doc_version' => self::V2]]];
        });
        $confirms->expects($this->once())->method('insert')->with('anna', 31, 9, 5000, self::V2, 12);
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::VIDEO_COMPLETED,
            'anna',
            ['course_id' => 9, 'content_id' => 31, 'document_id' => 12, 'document_version' => self::V2]
        );

        $state = $this->service([], ['version' => self::V2, 'mtime' => 100], false, null, $audit, $confirms)
            ->confirm(self::document(), 'anna');

        self::assertTrue($state['confirmed']);
    }

    /**
     * GitHub #75 (review M1): a click while the file could not be read (moved, outside the
     * material folder, storage hanging) stored a confirmation without version and a compliance
     * event. Such a row counted like one taken over from 5.13 and never asked again after the
     * file changed. Nothing is recorded now.
     */
    public function testConfirmRecordsNothingWhileTheFileCannotBeRead(): void {
        $confirms = $this->createMock(DocConfirmMapper::class);
        $confirms->method('latestByUsers')->willReturn([]);
        $confirms->expects($this->never())->method('insert');
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logComplianceEvent');

        $this->expectException(DocumentUnavailableException::class);
        $this->service([], null, false, null, $audit, $confirms)->confirm(self::document(), 'anna');
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
            ['course_id' => 9, 'content_id' => 31, 'document_id' => 12]
        );
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(7000);
        // GitHub #75: stored on the document of the course, not on the registry row
        $documentMapper = $this->createMock(CourseDocumentMapper::class);
        $documentMapper->expects($this->once())->method('setConfirmAfter')->with(9, 12, 7000)->willReturn(1);
        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->expects($this->never())->method('update');
        $service = new RequiredDocumentService(
            $this->createMock(DocConfirmMapper::class),
            $videos,
            $this->createMock(CourseMapper::class),
            $this->createMock(DocumentService::class),
            $this->createMock(PassCriteriaService::class),
            $audit,
            $time,
            new FakeDbConnection(),
            $documentMapper,
        );

        $row = $service->requestReconfirm(self::document(), 'teacher');

        self::assertSame(7000, $row['confirm_after']);
        self::assertSame(31, $row['id']);
    }

    /**
     * GitHub #75: the instructor's "Ask everyone to confirm again" is read from the document, so a
     * registry row that was added again for the same document still honours it.
     */
    public function testReconfirmRequestOnTheDocumentCountsForANewRegistryRow(): void {
        $documentMapper = $this->createMock(CourseDocumentMapper::class);
        $documentMapper->method('confirmAfterByIds')->with(9, [12])->willReturn([12 => 4500]);
        $confirms = $this->createMock(DocConfirmMapper::class);
        $confirms->expects($this->once())->method('latestByUsers')
            ->with(9, ['anna'], [77 => 12])
            ->willReturn(['anna' => [77 => ['confirmed_at' => 4000, 'doc_version' => self::V1]]]);
        $service = $this->service([], ['version' => self::V1, 'mtime' => 100], false, null, null, $confirms, 5000, $documentMapper);
        $row = self::document();
        $row->setId(77); // the row of the second "Required" tick

        $state = $service->statesForUser(self::course(false), [$row], 'anna')[77];

        self::assertFalse($state['confirmed']);
        self::assertTrue($state['again']);
    }

    /**
     * A row a concurrent request added first is skipped in its own savepoint (the outer
     * transaction stays usable on PostgreSQL) and is not reported as this request's change.
     */
    public function testAConcurrentlyAddedRowIsSkippedInASavepoint(): void {
        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->method('insert')->willThrowException(
            new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'learn_cv_course_doc_uq')
        );
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logComplianceEvent');
        $db = new FakeDbConnection();
        $service = new RequiredDocumentService(
            $this->createMock(DocConfirmMapper::class),
            $videos,
            $this->createMock(CourseMapper::class),
            $this->createMock(DocumentService::class),
            $this->createMock(PassCriteriaService::class),
            $audit,
            $this->createMock(ITimeFactory::class),
            $db,
            $this->createMock(CourseDocumentMapper::class),
        );

        $service->changeRequired(9, [self::document()], [], 'teacher');

        self::assertSame(2, $db->beginTransactionCalls, 'outer transaction plus one savepoint');
        self::assertSame(1, $db->rollBackCalls, 'only the savepoint');
        self::assertSame(1, $db->commitCalls);
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
            $this->createMock(CourseDocumentMapper::class),
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
            $this->createMock(CourseDocumentMapper::class),
        );

        self::assertSame(['anna' => 1], $service->countConfirmedByUsers($course, [self::document()], ['anna', 'ben', 'carl']));
    }
}
