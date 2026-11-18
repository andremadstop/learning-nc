<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\PoolViewOnlyException;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\DB\Exception as DbException; // the unit-test stub in tests/Support/PhpUnitStubs.php
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #97: POST /api/courses/{id}/pools answered every refusal with 400 "Failed to add pool",
 * and the dialog showed that English text. Each reason now has its own status and code, which the
 * dialog turns into a sentence.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseAddPoolCodes97Test extends TestCase {
    private function controller(CourseService $service): CourseController {
        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(CourseArchiveService::class),
            $service,
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            'teacher'
        );
    }

    /** @return array<string, array{\Throwable, int, ?string}> */
    public static function refusals(): array {
        return [
            'view-only share' => [new PoolViewOnlyException('x'), Http::STATUS_FORBIDDEN, 'pool_view_only'],
            'not an instructor of the course' => [new ForbiddenException('x'), Http::STATUS_FORBIDDEN, 'no_permission'],
            'foreign or missing pool' => [new NotFoundException('x'), Http::STATUS_NOT_FOUND, 'pool_not_found'],
            'missing course' => [new DoesNotExistException('x'), Http::STATUS_NOT_FOUND, 'course_not_found'],
            'already in the course' => [new DbException(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION), Http::STATUS_CONFLICT, 'already_added'],
            'other database error' => [new DbException(DbException::REASON_DEADLOCK), Http::STATUS_BAD_REQUEST, null],
            'anything else' => [new \RuntimeException('x'), Http::STATUS_BAD_REQUEST, null],
        ];
    }

    #[DataProvider('refusals')]
    public function testEachRefusalCarriesItsCode(\Throwable $thrown, int $status, ?string $code): void {
        $service = $this->createMock(CourseService::class);
        $service->method('addPool')->willThrowException($thrown);

        $response = $this->controller($service)->addPool(7, 42);

        $this->assertSame($status, $response->getStatus());
        $data = $response->getData();
        $this->assertSame($code, $data['code'] ?? null);
        $this->assertIsString($data['error'] ?? null);
    }

    public function testAnAddedPoolIsCreated(): void {
        $service = $this->createMock(CourseService::class);
        $service->method('addPool')->willReturn(new CoursePool());
        $this->assertSame(Http::STATUS_CREATED, $this->controller($service)->addPool(7, 42)->getStatus());
    }
}
