<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\ClassbookController;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\KudosMapper;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\ClassbookService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #50: the course owner (no member row) got "Make visible in course" in the class
 * register. The click flipped their global profile visibility, never listed them in this
 * register, and showed "now visible" / "private again" in turn. Only enrolled learners may
 * switch, and the endpoint refuses everyone else without touching the profile.
 */
#[AllowMockObjectsWithoutExpectations]
class ClassbookVisibilityToggleTest extends TestCase {
    /** @param array<string, string> $roles user id => member role */
    private function service(bool $viewerIsInstructor, array $roles, ?array $modeConfig = null): ClassbookService {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn(['id' => 7, 'is_instructor' => $viewerIsInstructor, 'mode_config' => $modeConfig]);

        $members = [];
        foreach ($roles as $uid => $role) {
            $member = new CourseMember();
            $member->setUserId($uid);
            $member->setRole($role);
            $members[$uid] = $member;
        }
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourse')->willReturn(array_values($members));
        $memberMapper->method('findByCourseAndUser')->willReturnCallback(
            static function (int $courseId, string $uid) use ($members): CourseMember {
                if (!isset($members[$uid])) {
                    throw new DoesNotExistException('no member');
                }
                return $members[$uid];
            }
        );

        $telosMapper = $this->createMock(UserTelosMapper::class);
        $telosMapper->method('findByUserIds')->willReturn([]);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);
        $kudos = $this->createMock(KudosMapper::class);
        $kudos->method('countReceivedByCourseAndUsers')->willReturn([]);
        $kudos->method('countGivenSince')->willReturn(0);

        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult([], []))]);
        return new ClassbookService($courseService, $memberMapper, $pools, $telosMapper, $kudos, $db, $this->createMock(IUserManager::class));
    }

    public function testTheCourseOwnerWithoutAMemberRowGetsNoSwitch(): void {
        $classbook = $this->service(true, ['learner' => 'student'])->getClassbook(7, 'owner');
        $this->assertFalse($classbook['can_toggle_visibility']);
    }

    public function testACoInstructorGetsNoSwitch(): void {
        $classbook = $this->service(true, ['learner' => 'student', 'teacher' => 'instructor'])->getClassbook(7, 'teacher');
        $this->assertFalse($classbook['can_toggle_visibility']);
    }

    public function testAnEnrolledLearnerKeepsTheSwitch(): void {
        $classbook = $this->service(false, ['learner' => 'student'])->getClassbook(7, 'learner');
        $this->assertTrue($classbook['can_toggle_visibility']);
    }

    public function testCanToggleVisibilityOnlyForEnrolledLearnersWithTheRegisterOn(): void {
        $this->assertTrue($this->service(false, ['learner' => 'student'])->canToggleVisibility(7, 'learner'));
        $this->assertFalse($this->service(true, ['learner' => 'student'])->canToggleVisibility(7, 'owner'), 'owner');
        $this->assertFalse($this->service(true, ['teacher' => 'instructor'])->canToggleVisibility(7, 'teacher'), 'co-instructor');
        $this->assertFalse($this->service(false, ['learner' => 'student'])->canToggleVisibility(7, 'outsider'), 'no member row');
        $this->assertFalse($this->service(false, ['learner' => 'student'], ['classbook' => false])->canToggleVisibility(7, 'learner'), 'register off');
    }

    public function testTheEndpointRefusesAnInstructorAndLeavesTheProfileAlone(): void {
        $classbook = $this->createMock(ClassbookService::class);
        $classbook->method('canToggleVisibility')->willReturn(false);
        $telosMapper = $this->createMock(UserTelosMapper::class);
        $telosMapper->expects($this->never())->method('update');

        $controller = new ClassbookController('learning', $this->createMock(IRequest::class), 'owner',
            $this->createMock(CourseService::class), $classbook, $telosMapper, $this->createMock(IUserManager::class));

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->toggleVisibility(7)->getStatus());
    }

    public function testTheEndpointStillFlipsALearnersVisibility(): void {
        $classbook = $this->createMock(ClassbookService::class);
        $classbook->method('canToggleVisibility')->willReturn(true);
        $telos = new UserTelos();
        $telos->setUserId('learner');
        $telos->setVisibility('private');
        $telosMapper = $this->createMock(UserTelosMapper::class);
        $telosMapper->method('findByUserIdOrNull')->willReturn($telos);
        $telosMapper->expects($this->once())->method('update')->willReturnArgument(0);

        $controller = new ClassbookController('learning', $this->createMock(IRequest::class), 'learner',
            $this->createMock(CourseService::class), $classbook, $telosMapper, $this->createMock(IUserManager::class));

        $response = $controller->toggleVisibility(7);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['visibility' => 'course'], $response->getData());
    }

    /**
     * GitHub #50: the visibility is global. When another tab already made the learner visible,
     * "Make visible in course" must leave them visible; a relative toggle made them private.
     */
    public function testTheRequestedVisibilityIsSetIdempotently(): void {
        $classbook = $this->createMock(ClassbookService::class);
        $classbook->method('canToggleVisibility')->willReturn(true);
        $telos = new UserTelos();
        $telos->setUserId('learner');
        $telos->setVisibility('course');
        $telosMapper = $this->createMock(UserTelosMapper::class);
        $telosMapper->method('findByUserIdOrNull')->willReturn($telos);
        $telosMapper->expects($this->never())->method('update');

        $controller = new ClassbookController('learning', $this->createMock(IRequest::class), 'learner',
            $this->createMock(CourseService::class), $classbook, $telosMapper, $this->createMock(IUserManager::class));

        $response = $controller->toggleVisibility(7, 'course');
        $this->assertSame(['visibility' => 'course'], $response->getData());
        $this->assertSame('course', $telos->getVisibility());
    }

    public function testAnUnknownVisibilityIsRefused(): void {
        $classbook = $this->createMock(ClassbookService::class);
        $classbook->method('canToggleVisibility')->willReturn(true);
        $telosMapper = $this->createMock(UserTelosMapper::class);
        $telosMapper->expects($this->never())->method('update');

        $controller = new ClassbookController('learning', $this->createMock(IRequest::class), 'learner',
            $this->createMock(CourseService::class), $classbook, $telosMapper, $this->createMock(IUserManager::class));

        $this->assertSame(Http::STATUS_BAD_REQUEST, $controller->toggleVisibility(7, 'public')->getStatus());
    }
}
