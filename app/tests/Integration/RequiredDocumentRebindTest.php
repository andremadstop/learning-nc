<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\DocConfirmMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RequiredDocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75 (P9): making a required file optional deletes its registry row, and ticking it
 * again adds a new one. Up to 5.14.0-dev the confirmations hung off the registry row
 * (learning_doc_confirms.content_id), so every learner had to confirm the file again after a
 * misclick. They now belong to the document of the course and count again — measured by the
 * same rule: not for a changed file, not from an earlier certification cycle, not from before
 * the instructor's "Ask everyone to confirm again".
 *
 * Real database (ENGINE=pgsql|mysql scripts/nc-integration.sh) and the real mappers; only the
 * file (version), the cycle start, the clock and the audit chain are stand-ins. Every test rolls back.
 */
class RequiredDocumentRebindTest extends TestCase {
    private const COURSE = 975101;
    private const V1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const V2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private IDBConnection $db;
    private int $now = 10000;
    private string $version = self::V1;
    private ?int $cycleStart = null;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void {
        $this->db->rollBack();
    }

    private function course(bool $perCycle): Course {
        $course = new Course();
        $course->setId(self::COURSE);
        $course->setReconfirmPerCycle($perCycle);
        return $course;
    }

    private function service(Course $course): RequiredDocumentService {
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);
        $documents = $this->createMock(DocumentService::class);
        $documents->method('currentFileInfo')->willReturnCallback(fn(): array => ['version' => $this->version, 'mtime' => 1]);
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('confirmationCycleStarts')->willReturnCallback(
            fn(Course $c, array $uids): array => array_fill_keys($uids, $this->cycleStart)
        );
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn(): int => $this->now);
        return new RequiredDocumentService(
            Server::get(DocConfirmMapper::class),
            Server::get(CourseVideoMapper::class),
            $courses,
            $documents,
            $pass,
            $this->createMock(AuditService::class),
            $time,
            $this->db,
            Server::get(CourseDocumentMapper::class),
        );
    }

    private function document(): int {
        $doc = new CourseDocument();
        $doc->setCourseId(self::COURSE);
        $doc->setFilePath('Training/policy.pdf');
        $doc->setFileName('policy.pdf');
        $doc->setFileType('pdf');
        $doc->setStatus('uploaded');
        $doc->setCreatedAt(1);
        $doc->setUpdatedAt(1);
        return (int)Server::get(CourseDocumentMapper::class)->insert($doc)->getId();
    }

    /** Tick "Required" for the document, as the Required column does; returns the new row. */
    private function tick(RequiredDocumentService $service, int $documentId): CourseVideo {
        $video = new CourseVideo();
        $video->setCourseId(self::COURSE);
        $video->setSourceType('document');
        $video->setVideoRef((string)$documentId);
        $video->setDocumentId($documentId);
        $video->setTitle('policy.pdf');
        $video->setSortOrder(1);
        $video->setCreatedAt($this->now);
        $added = $service->changeRequired(self::COURSE, [$video], [], 'teacher');
        $this->assertCount(1, $added);
        return $added[0];
    }

    private function untick(RequiredDocumentService $service, CourseVideo $row): void {
        $service->changeRequired(self::COURSE, [], [$row], 'teacher');
    }

    public function testMakingAFileOptionalAndRequiredAgainKeepsTheConfirmations(): void {
        $course = $this->course(false);
        $service = $this->service($course);
        $documentId = $this->document();
        $first = $this->tick($service, $documentId);
        $this->assertTrue($service->confirm($first, 'anna')['confirmed']);

        $this->now = 11000;
        $this->untick($service, $first);
        $second = $this->tick($service, $documentId);

        $this->assertNotSame((int)$first->getId(), (int)$second->getId(), 'a new registry row');
        $this->assertTrue($service->isConfirmed($course, $second, 'anna'), 'the earlier confirmation counts again');
        $this->assertFalse($service->isConfirmed($course, $second, 'ben'));
        $this->assertSame(['anna' => 1], $service->countConfirmedByUsers($course, [$second], ['anna', 'ben']), 'coverage report, same rule');
    }

    public function testAFileChangedInBetweenIsNotConfirmed(): void {
        $course = $this->course(false);
        $service = $this->service($course);
        $documentId = $this->document();
        $first = $this->tick($service, $documentId);
        $service->confirm($first, 'anna');

        $this->now = 11000;
        $this->untick($service, $first);
        $this->version = self::V2;
        $second = $this->tick($service, $documentId);

        $state = $service->statesForUser($course, [$second], 'anna')[(int)$second->getId()];
        $this->assertFalse($state['confirmed']);
        $this->assertTrue($state['updated'], '"Updated since you confirmed it"');
    }

    public function testAConfirmationFromAnEarlierCycleIsNotConfirmed(): void {
        $course = $this->course(true);
        $service = $this->service($course);
        $documentId = $this->document();
        $first = $this->tick($service, $documentId);
        $service->confirm($first, 'anna');

        $this->now = 20000;
        $this->untick($service, $first);
        $this->cycleStart = 15000; // the renewal window opened after the confirmation
        $second = $this->tick($service, $documentId);

        $state = $service->statesForUser($course, [$second], 'anna')[(int)$second->getId()];
        $this->assertFalse($state['confirmed']);
        $this->assertTrue($state['again']);
    }

    public function testAskingEveryoneAgainSurvivesMakingTheFileOptionalAndRequiredAgain(): void {
        $course = $this->course(false);
        $service = $this->service($course);
        $documentId = $this->document();
        $first = $this->tick($service, $documentId);
        $service->confirm($first, 'anna');

        $this->now = 11000;
        $this->assertSame(11000, $service->requestReconfirm($first, 'teacher')['confirm_after']);
        $this->assertFalse($service->isConfirmed($course, $first, 'anna'));

        $this->now = 12000;
        $this->untick($service, $first);
        $second = $this->tick($service, $documentId);
        $this->assertFalse($service->isConfirmed($course, $second, 'anna'), 'a re-tick does not cancel the request');

        $this->now = 13000;
        $this->assertTrue($service->confirm($second, 'anna')['confirmed']);
    }
}
