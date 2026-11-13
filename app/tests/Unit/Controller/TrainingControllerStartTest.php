<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\TrainingController;
use OCA\Learning\Service\CertificationTrigger;
use OCA\Learning\Service\TrainingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35: POST /api/training/start with examKind='practice' starts the pool practice run of
 * a course — a session that never counts towards a certificate. Nextcloud fills controller
 * arguments from the request body by parameter NAME, so the key ExamMode sends (examKind,
 * courseId, poolId) must be exactly a parameter of start(). If the controller did not read
 * examKind, the practice card would silently start a counting exam.
 */
#[AllowMockObjectsWithoutExpectations]
class TrainingControllerStartTest extends TestCase {

    private function makeController(TrainingService $service, ?string $userId = 'alice'): TrainingController {
        return new TrainingController('learning', $this->createMock(IRequest::class), $service, $this->createMock(CertificationTrigger::class), $userId, $this->createMock(LoggerInterface::class));
    }

    public function testStartReadsTheRequestKeysExamModeSends(): void {
        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(TrainingController::class, 'start'))->getParameters()
        );

        foreach (['poolId', 'mode', 'courseId', 'lang', 'limit', 'timeLimitSeconds', 'examKind'] as $key) {
            $this->assertContains($key, $names, "request key '$key' must be a parameter of start()");
        }
    }

    public function testPracticeKindStartsThePoolPracticeRunNotACountingExam(): void {
        $service = $this->createMock(TrainingService::class);
        $service->expects($this->never())->method('startSession');
        $service->expects($this->once())->method('startPoolPracticeExam')->with(3, 7, 'alice', 'de')
            ->willReturn(['session_id' => 501, 'exam_kind' => 'practice']);

        // Named arguments mirror the JSON body ExamMode posts.
        $response = $this->makeController($service)->start(poolId: 3, limit: 90, mode: 'exam', timeLimitSeconds: 600, lang: 'de', courseId: 7, examKind: 'practice');

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('practice', $response->getData()['exam_kind']);
    }

    public function testWithoutExamKindTheCountingExamStartsAsBefore(): void {
        $service = $this->createMock(TrainingService::class);
        $service->expects($this->never())->method('startPoolPracticeExam');
        $service->expects($this->once())->method('startSession')->with(3, 'alice', 90, 'exam', 8100, null, 7, null)
            ->willReturn(['session_id' => 502]);

        $response = $this->makeController($service)->start(poolId: 3, limit: 90, mode: 'exam', timeLimitSeconds: 8100, courseId: 7);

        $this->assertSame(201, $response->getStatus());
    }

    public function testUnknownExamKindIsRefusedInsteadOfStartingACountingExam(): void {
        $service = $this->createMock(TrainingService::class);
        $service->expects($this->never())->method('startSession');
        $service->expects($this->never())->method('startPoolPracticeExam');

        $response = $this->makeController($service)->start(poolId: 3, mode: 'exam', courseId: 7, examKind: 'mock');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Unknown exam kind', $response->getData()['error']);
    }

    public function testPracticeRunWithoutCourseIsRefused(): void {
        $service = $this->createMock(TrainingService::class);
        $service->expects($this->never())->method('startSession');
        $service->expects($this->never())->method('startPoolPracticeExam');

        $response = $this->makeController($service)->start(poolId: 3, mode: 'exam', examKind: 'practice');

        $this->assertSame(400, $response->getStatus());
    }

    public function testPracticeRunOnAForeignCourseIs404(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('startPoolPracticeExam')->willThrowException(new DoesNotExistException('Course not found'));

        $response = $this->makeController($service)->start(poolId: 3, mode: 'exam', courseId: 99, examKind: 'practice');

        $this->assertSame(404, $response->getStatus());
    }

    public function testPracticeRunRefusalKeepsTheServerReason(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('startPoolPracticeExam')->willThrowException(new \Exception('Practice exams are not enabled for this course'));

        $response = $this->makeController($service)->start(poolId: 3, mode: 'exam', courseId: 7, examKind: 'practice');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Practice exams are not enabled for this course', $response->getData()['error']);
    }

    /** A database error is no refusal: the driver message (tables, columns, SQL) never reaches the browser. */
    public function testPracticeRunDatabaseErrorIsNotEchoedToTheClient(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('startPoolPracticeExam')->willThrowException(
            new \OCP\DB\Exception(0, 'SQLSTATE[42703]: column learning_sessions.exam_kind does not exist')
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $controller = new TrainingController('learning', $this->createMock(IRequest::class), $service, $this->createMock(CertificationTrigger::class), 'alice', $logger);

        $response = $controller->start(poolId: 3, mode: 'exam', courseId: 7, examKind: 'practice');

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('Failed to start practice exam', $response->getData()['error']);
        $this->assertStringNotContainsString('SQLSTATE', (string)json_encode($response->getData()));
    }

    /** Pre-existing, same leak: the counting exam start and the course practice exam start. */
    public function testCountingStartDatabaseErrorIsNotEchoedToTheClient(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('startSession')->willThrowException(new \OCP\DB\Exception(0, 'SQLSTATE[23505]: duplicate key value violates unique constraint "learning_sessions_pkey"'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $controller = new TrainingController('learning', $this->createMock(IRequest::class), $service, $this->createMock(CertificationTrigger::class), 'alice', $logger);

        $response = $controller->start(poolId: 3, mode: 'exam');

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('Failed to start training session', $response->getData()['error']);
    }

    public function testCoursePracticeStartDatabaseErrorIsNotEchoedToTheClient(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('startPracticeExam')->willThrowException(new \OCP\DB\Exception(0, 'SQLSTATE[42P01]: relation "oc_learning_sessions" does not exist'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $controller = new TrainingController('learning', $this->createMock(IRequest::class), $service, $this->createMock(CertificationTrigger::class), 'alice', $logger);

        $response = $controller->startPractice(courseId: 7);

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('Failed to start practice exam', $response->getData()['error']);
    }

    /** Intended refusals keep their reason: the learner must read "attempt limit", "cooldown" etc. */
    public function testCountingStartRefusalKeepsTheServerReason(): void {
        $service = $this->createMock(TrainingService::class);
        $service->method('startSession')->willThrowException(new \Exception('Exam cooldown active'));

        $response = $this->makeController($service)->start(poolId: 3, mode: 'exam');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Exam cooldown active', $response->getData()['error']);
    }
}
