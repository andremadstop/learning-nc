<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificationCycleController;
use OCA\Learning\Service\CertificationCycleService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NothingToResetException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #84 / #87: the routes behind "Open a new certification cycle". A foreign course, a
 * member of another course and a co-instructor all answer the same 404 (no oracle); a learner
 * who is not stuck answers 409 nothing_to_reset; a concurrent reopen 409 concurrent_change.
 */
#[AllowMockObjectsWithoutExpectations]
class CertificationCycleControllerTest extends TestCase {
    private function controller(CertificationCycleService $cycles, ?string $userId = 'teacher'): CertificationCycleController {
        return new CertificationCycleController('learning', $this->createMock(IRequest::class), $cycles, $this->createMock(LoggerInterface::class), $userId);
    }

    public function testOpenAnswersTheNewCycle(): void {
        $cycles = $this->createMock(CertificationCycleService::class);
        $cycles->expects($this->once())->method('open')->with(7, 31, 'teacher')->willReturn(['reason' => 'revoked', 'since' => 1800000000]);

        $response = $this->controller($cycles)->open(7, 31);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['reason' => 'revoked', 'since' => 1800000000], $response->getData());
    }

    public function testForeignCourseForeignMemberAndCoInstructorAllAnswer404(): void {
        foreach ([new ForbiddenException('No permission'), new DoesNotExistException('Member not found in this course')] as $refusal) {
            $cycles = $this->createMock(CertificationCycleService::class);
            $cycles->method('open')->willThrowException($refusal);
            $cycles->method('statesForCourse')->willThrowException($refusal);

            $this->assertSame([404, ['error' => 'Not found']], [$this->controller($cycles)->open(7, 31)->getStatus(), $this->controller($cycles)->open(7, 31)->getData()]);
            $this->assertSame(404, $this->controller($cycles)->states(7)->getStatus());
        }
    }

    public function testNothingToResetIsAConflict(): void {
        $cycles = $this->createMock(CertificationCycleService::class);
        $cycles->method('open')->willThrowException(new NothingToResetException());

        $response = $this->controller($cycles)->open(7, 31);

        $this->assertSame(409, $response->getStatus());
        $this->assertSame(['error' => 'nothing_to_reset'], $response->getData());
    }

    public function testAConcurrentReopenIsAConflict(): void {
        $unique = new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'learn_assign_period_uq');
        $cycles = $this->createMock(CertificationCycleService::class);
        $cycles->method('open')->willThrowException($unique);

        $response = $this->controller($cycles)->open(7, 31);

        $this->assertSame(409, $response->getStatus());
        $this->assertSame(['error' => 'concurrent_change'], $response->getData());
    }

    public function testStatesAreAListWithTheMemberId(): void {
        $cycles = $this->createMock(CertificationCycleService::class);
        $cycles->method('statesForCourse')->with(7, 'teacher')->willReturn([
            31 => ['reason' => 'revoked', 'new_cycle' => null, 'exhausted_pool_ids' => []],
        ]);

        $response = $this->controller($cycles)->states(7);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['members' => [['member_id' => 31, 'reason' => 'revoked', 'new_cycle' => null, 'exhausted_pool_ids' => []]]], $response->getData());
    }

    public function testWithoutASessionNothingIsRead(): void {
        $cycles = $this->createMock(CertificationCycleService::class);
        $cycles->expects($this->never())->method('open');
        $this->assertSame(404, $this->controller($cycles, null)->open(7, 31)->getStatus());
    }
}
