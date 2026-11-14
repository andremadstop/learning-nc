<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35: mode_config.tools is the course's master switch for the simulator tools.
 * PUT /mode-config {modeConfig: {...}} with the real CourseService behind the controller: the
 * key is stored, and a course saved without it keeps its tools (default true).
 */
#[AllowMockObjectsWithoutExpectations]
class CourseModeConfigToolsTest extends TestCase {
    private Course $course;
    private int $updates = 0;

    private function controllerFor(string $userId): CourseController {
        $this->course = new Course();
        $this->course->setId(7);
        $this->course->setInstructorId('teacher');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($this->course);
        $courseMapper->method('update')->willReturnCallback(function (Course $c): Course {
            $this->updates++;
            return $c;
        });
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('none'));

        $courseService = new CourseService(
            $courseMapper,
            $this->createMock(CoursePoolMapper::class),
            $members,
            $this->createMock(RoleService::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );

        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $courseMapper,
            $members,
            $this->createMock(CourseArchiveService::class),
            $courseService,
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(\OCA\Learning\Service\CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            $userId
        );
    }

    /** @return array<string, mixed> */
    private function storedModeConfig(): array {
        return json_decode((string)$this->course->getModeConfig(), true);
    }

    public function testToolsOffIsStoredAndReturned(): void {
        $response = $this->controllerFor('teacher')->updateModeConfig(7, ['training' => true, 'tools' => false]);

        $this->assertSame(200, $response->getStatus());
        $this->assertFalse($response->getData()['mode_config']['tools']);
        $this->assertFalse($this->storedModeConfig()['tools']);
        $this->assertSame(1, $this->updates);
    }

    public function testToolsOnIsStored(): void {
        $this->controllerFor('teacher')->updateModeConfig(7, ['tools' => true]);

        $this->assertTrue($this->storedModeConfig()['tools']);
    }

    /** A client that does not know the switch (or an older page) must not switch the tools off. */
    public function testMissingToolsKeyKeepsTheTools(): void {
        $this->controllerFor('teacher')->updateModeConfig(7, ['training' => true, 'leitner' => false]);

        $stored = $this->storedModeConfig();
        $this->assertTrue($stored['tools']);
        $this->assertFalse($stored['leitner']);
    }

    public function testLearnerCannotSwitchTheToolsOff(): void {
        $response = $this->controllerFor('learner')->updateModeConfig(7, ['tools' => false]);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame(0, $this->updates);
        $this->assertNull($this->course->getModeConfig());
    }

    /**
     * The tests above call updateModeConfig() positionally. Nextcloud maps the JSON body to
     * controller arguments BY NAME, so a renamed `$modeConfig` would keep them green while every
     * real request fell back to the [] default (all modes on, tools back on). The keys are the
     * ones CourseTabVerwaltung sends (CourseTabVerwaltungMounted.test.js).
     */
    public function testControllerParameterNamesMatchTheRequestFieldsTheFrontendSends(): void {
        $names = array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(CourseController::class, 'updateModeConfig'))->getParameters()
        );

        $this->assertSame(['courseId', 'modeConfig', 'talkRoomToken', 'leitnerSprint'], $names);
    }

    /**
     * Audit #63: an emptied Talk token field sends talkRoomToken ''. The controller turned it
     * into null ("leave the token alone"), so the old token stayed although the UI said saved.
     */
    public function testEmptyTalkTokenClearsTheStoredToken(): void {
        $controller = $this->controllerFor('teacher');
        $this->course->setTalkRoomToken('abc123');

        $response = $controller->updateModeConfig(7, ['training' => true], '');

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($this->course->getTalkRoomToken());
    }

    public function testOmittedTalkTokenKeepsTheStoredToken(): void {
        $controller = $this->controllerFor('teacher');
        $this->course->setTalkRoomToken('abc123');

        $controller->updateModeConfig(7, ['training' => true]);

        $this->assertSame('abc123', $this->course->getTalkRoomToken());
    }
}
