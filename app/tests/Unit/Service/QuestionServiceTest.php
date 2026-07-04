<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AUDIT v5.2.1 (pre-live review, finding H): QuestionService::verifyExportAccess() must allow the
 * pool owner, an edit-share holder, AND the instructor of a course this pool is attached to — but
 * MUST reject an enrolled student who is neither owner, edit-share holder, nor instructor. The
 * private isCourseInstructorForPool() helper deliberately checks ONLY course.instructor_id (not
 * learning_course_members), so a student row never satisfies it regardless of enrollment.
 *
 * @group audit-h-export-access
 */
class QuestionServiceTest extends TestCase {
    /** @var PoolMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $poolMapperMock;
    /** @var PoolShareMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $shareMapperMock;

    protected function setUp(): void {
        $this->poolMapperMock = $this->createMock(PoolMapper::class);
        $this->shareMapperMock = $this->createMock(PoolShareMapper::class);
    }

    /**
     * @param FakeQueryBuilder[] $queuedBuilders builders consumed in call order by
     *        isCourseInstructorForPool() (only reached when canEditPool() is false)
     */
    private function createService(array $queuedBuilders = []): QuestionService {
        $questionMapper = $this->createMock(QuestionMapper::class);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $translationService = $this->createMock(TranslationService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $db = new FakeDbConnection($queuedBuilders);

        return new QuestionService(
            $questionMapper,
            $answerMapper,
            $this->shareMapperMock,
            $this->poolMapperMock,
            $db,
            $translationService,
            $logger
        );
    }

    /**
     * Course instructor: not the pool owner, no pool_share row, but IS the instructor of a course
     * carrying this pool → verifyExportAccess() must NOT throw.
     */
    public function testVerifyExportAccessAllowsCourseInstructor(): void {
        $this->poolMapperMock->method('find')
            ->with(10, 'instructor-1')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->shareMapperMock->method('findByPoolAndUser')
            ->with(10, 'instructor-1')
            ->willReturn(null);

        // isCourseInstructorForPool() query finds a matching cp/course row.
        $instructorQuery = new FakeQueryBuilder(FakeResult::fromFetch(['id' => 1]));
        $service = $this->createService([$instructorQuery]);

        $service->verifyExportAccess(10, 'instructor-1');
        $this->addToAssertionCount(1); // reaching here without an exception IS the assertion
    }

    /**
     * Enrolled student: not the owner, no share, not the course instructor (only
     * learning_course_members, which isCourseInstructorForPool() deliberately does not check) →
     * verifyExportAccess() must throw.
     */
    public function testVerifyExportAccessRejectsEnrolledStudent(): void {
        $this->poolMapperMock->method('find')
            ->with(10, 'student-1')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->shareMapperMock->method('findByPoolAndUser')
            ->with(10, 'student-1')
            ->willReturn(null);

        // isCourseInstructorForPool() query finds no row — the student is not the instructor.
        $noInstructorQuery = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $service = $this->createService([$noInstructorQuery]);

        $this->expectException(\Exception::class);
        $service->verifyExportAccess(10, 'student-1');
    }
}
