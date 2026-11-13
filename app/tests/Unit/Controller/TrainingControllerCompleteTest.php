<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\TrainingController;
use OCA\Learning\Service\CertificationTrigger;
use OCA\Learning\Service\TrainingService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #35: POST /api/training/complete issues the certificate for a counting exam right away
 * and hands the result to the exam result screen. The request parameter the controller reads is
 * sessionId (plus optional lang); courseId in the body is ignored — the course comes from the
 * stored session, never from the client.
 */
#[AllowMockObjectsWithoutExpectations]
class TrainingControllerCompleteTest extends TestCase {

    private function makeController(TrainingService $service, CertificationTrigger $trigger, ?string $userId = 'alice'): TrainingController {
        return new TrainingController('learning', $this->createMock(IRequest::class), $service, $trigger, $userId);
    }

    public function testCompletionCarriesTheCertificationBlock(): void {
        $service = $this->createMock(TrainingService::class);
        $service->expects($this->once())->method('completeSession')->with(42, 'alice', 'de')
            ->willReturn(['session_id' => 42, 'score_percentage' => 85]);
        $trigger = $this->createMock(CertificationTrigger::class);
        $trigger->expects($this->once())->method('afterSessionCompleted')->with(42, 'alice')
            ->willReturn(['passed' => true, 'certificate_issued' => true, 'course_id' => 7]);

        $response = $this->makeController($service, $trigger)->complete(42, 'de');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(85, $response->getData()['score_percentage']);
        $this->assertSame(['passed' => true, 'certificate_issued' => true, 'course_id' => 7], $response->getData()['certification']);
    }

    // Training sessions and practice exams: the trigger returns null and the response is unchanged.
    public function testNoCertificationKeyWhenTriggerHasNothing(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('completeSession')->willReturn(['session_id' => 42]);
        $trigger = $this->createMock(CertificationTrigger::class);
        $trigger->method('afterSessionCompleted')->willReturn(null);

        $response = $this->makeController($service, $trigger)->complete(42);

        $this->assertSame(['session_id' => 42], $response->getData());
    }

    // A certification failure must never turn a completed exam into an error response.
    public function testThrowingTriggerStillReturnsTheResult(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('completeSession')->willReturn(['session_id' => 42, 'score_percentage' => 90]);
        $trigger = $this->createMock(CertificationTrigger::class);
        $trigger->method('afterSessionCompleted')->willThrowException(new \Error('boom'));

        $response = $this->makeController($service, $trigger)->complete(42);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['session_id' => 42, 'score_percentage' => 90], $response->getData());
    }

    // Completion failed → no certification attempt.
    public function testFailedCompletionDoesNotTrigger(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('completeSession')->willThrowException(new \Exception('Session not found'));
        $trigger = $this->createMock(CertificationTrigger::class);
        $trigger->expects($this->never())->method('afterSessionCompleted');

        $response = $this->makeController($service, $trigger)->complete(42);

        $this->assertSame(400, $response->getStatus());
    }
}
