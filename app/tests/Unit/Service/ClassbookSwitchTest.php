<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\KudosMapper;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\ClassbookService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #36: the course switch mode_config.classbook hid the class register only in the
 * frontend. GET /api/courses/{id}/classbook still handed a learner the other members' profiles
 * (name, bio, help topics, online status) and POST .../kudos still worked. The server now
 * honours the switch for learners; instructors keep the register.
 */
#[AllowMockObjectsWithoutExpectations]
class ClassbookSwitchTest extends TestCase {
    private KudosMapper $kudos;

    private function service(?array $modeConfig, bool $viewerIsInstructor): ClassbookService {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn(['id' => 7, 'is_instructor' => $viewerIsInstructor, 'mode_config' => $modeConfig]);

        $members = [];
        foreach (['learner' => 'student', 'classmate' => 'student', 'teacher' => 'instructor'] as $uid => $role) {
            $member = new CourseMember();
            $member->setUserId($uid);
            $member->setRole($role);
            $members[] = $member;
        }
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourse')->willReturn($members);

        $telos = new UserTelos();
        $telos->setUserId('classmate');
        $telos->setVisibility('course');
        $telos->setBio('Ich helfe gern bei Subnetting');
        $telosMapper = $this->createMock(UserTelosMapper::class);
        $telosMapper->method('findByUserIds')->willReturn([$telos]);

        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);

        $this->kudos = $this->createMock(KudosMapper::class);
        $this->kudos->method('countReceivedByCourseAndUsers')->willReturn([]);
        $this->kudos->method('countGivenSince')->willReturn(0);

        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult([], []))]);
        return new ClassbookService($courseService, $memberMapper, $pools, $telosMapper, $this->kudos, $db, $this->createMock(IUserManager::class));
    }

    /** @return list<string> */
    private function profileIds(array $classbook): array {
        return array_map(static fn (array $p): string => $p['user_id'], $classbook['profiles']);
    }

    public function testALearnerGetsNoProfilesWithTheClassRegisterOff(): void {
        $classbook = $this->service(['classbook' => false], false)->getClassbook(7, 'learner');

        $this->assertSame([], $classbook['profiles']);
        $this->assertSame(0, $classbook['kudos_remaining']);
        $this->assertFalse($classbook['can_toggle_visibility']);
    }

    public function testALearnerKeepsTheRegisterWhenTheSwitchIsOnOrMissing(): void {
        $this->assertContains('classmate', $this->profileIds($this->service(['classbook' => true], false)->getClassbook(7, 'learner')));
        $this->assertContains('classmate', $this->profileIds($this->service(null, false)->getClassbook(7, 'learner')), 'a course saved before the switch keeps the register');
    }

    public function testTheInstructorKeepsTheRegisterWithTheSwitchOff(): void {
        $this->assertContains('classmate', $this->profileIds($this->service(['classbook' => false], true)->getClassbook(7, 'teacher')));
    }

    public function testALearnerCannotSendKudosWithTheClassRegisterOff(): void {
        $service = $this->service(['classbook' => false], false);
        $this->kudos->expects($this->never())->method('insert');

        $this->expectException(ForbiddenException::class);
        $service->giveKudos(7, 'learner', 'classmate', 'Danke');
    }

    public function testKudosStillWorkWithTheSwitchOn(): void {
        $service = $this->service(null, false);
        $this->kudos->expects($this->once())->method('insert')->willReturnArgument(0);

        $result = $service->giveKudos(7, 'learner', 'classmate', 'Danke');
        $this->assertSame('ok', $result['status']);
    }
}
