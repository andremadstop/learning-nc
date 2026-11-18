<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\DocumentController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #90 (U2): every course member used to receive the full course row, so a learner got
 * material_folder (a path in the instructor's home) and nc_group_id (the internal course
 * group). Learners now get has_material_folder instead; instructors of the course and admins
 * get the course as before.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseLearnerProjection90Test extends TestCase {
    private const ROLES = ['teacher' => 'owner', 'coteacher' => 'instructor', 'learner' => 'student', 'admin' => 'student'];

    private function course(): Course {
        $course = new Course();
        $course->setId(7);
        $course->setTitle('Pflichtschulung');
        $course->setInstructorId('teacher');
        $course->setMaterialFolder('/Home of teacher/Private/Material');
        $course->setNcGroupId('internal-group-42');
        return $course;
    }

    private function service(): CourseService {
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturnCallback(fn () => $this->course());
        $courses->method('findByInstructor')->willReturnCallback(fn (string $uid) => $uid === 'teacher' ? [$this->course()] : []);
        $members = $this->createMock(CourseMemberMapper::class);
        $member = static function (string $uid): CourseMember {
            $m = new CourseMember();
            $m->setCourseId(7);
            $m->setUserId($uid);
            $m->setRole(self::ROLES[$uid]);
            return $m;
        };
        $members->method('findByCourseAndUser')->willReturnCallback(static function (int $courseId, string $uid) use ($member) {
            if (!isset(self::ROLES[$uid]) || self::ROLES[$uid] === 'owner') {
                throw new DoesNotExistException('no member');
            }
            return $member($uid);
        });
        $members->method('findByUser')->willReturnCallback(static fn (string $uid) => isset(self::ROLES[$uid]) && self::ROLES[$uid] !== 'owner' ? [$member($uid)] : []);
        $members->method('findByCourse')->willReturn([]);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);
        $roles = $this->createMock(RoleService::class);
        $roles->method('isAdmin')->willReturnCallback(static fn (string $uid) => $uid === 'admin');

        return new CourseService(
            $courses,
            $pools,
            $members,
            $roles,
            new FakeDbConnection(),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
    }

    public function testALearnerGetsTheCourseWithoutFolderPathAndGroup(): void {
        $data = $this->service()->findById(7, 'learner');

        $this->assertArrayNotHasKey('material_folder', $data);
        $this->assertArrayNotHasKey('nc_group_id', $data);
        $this->assertTrue($data['has_material_folder'], 'the learner still learns that there is material');
        $this->assertStringNotContainsString('Private', json_encode($data) ?: '');
        $this->assertStringNotContainsString('internal-group-42', json_encode($data) ?: '');
    }

    public function testInstructorsAndAdminsGetTheFullCourse(): void {
        foreach (['teacher', 'coteacher', 'admin'] as $uid) {
            $data = $this->service()->findById(7, $uid);
            $this->assertSame('/Home of teacher/Private/Material', $data['material_folder'], $uid);
            $this->assertSame('internal-group-42', $data['nc_group_id'], $uid);
            $this->assertTrue($data['has_material_folder'], $uid);
        }
    }

    public function testTheCourseListProjectsTheLearnersCoursesOnly(): void {
        $learner = $this->service()->findAll('learner')['enrolled'][0];
        $this->assertArrayNotHasKey('material_folder', $learner);
        $this->assertArrayNotHasKey('nc_group_id', $learner);
        $this->assertTrue($learner['has_material_folder']);

        $co = $this->service()->findAll('coteacher')['enrolled'][0];
        $this->assertSame('/Home of teacher/Private/Material', $co['material_folder']);
        $this->assertSame('internal-group-42', $co['nc_group_id']);

        $admin = $this->service()->findAll('admin')['enrolled'][0];
        $this->assertSame('internal-group-42', $admin['nc_group_id']);

        $own = $this->service()->findAll('teacher')['own'][0];
        $this->assertSame('/Home of teacher/Private/Material', $own['material_folder']);
    }

    public function testACourseWithoutFolderSaysSo(): void {
        $course = new Course();
        $this->assertFalse($course->jsonSerialize()['has_material_folder']);
        $course->setMaterialFolder('');
        $this->assertFalse($course->jsonSerialize()['has_material_folder']);
    }

    /** The material-folder endpoint gave the same path to every member. */
    public function testTheMaterialFolderEndpointNamesThePathToInstructorsOnly(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn([]);
        $courseService->method('canManageCourse')->willReturnCallback(static fn (int $c, string $uid) => in_array($uid, ['teacher', 'coteacher'], true));
        $documents = $this->createMock(DocumentService::class);
        $documents->method('getMaterialFolder')->willReturn('/Home of teacher/Private/Material');
        $roles = $this->createMock(RoleService::class);
        $roles->method('isAdmin')->willReturnCallback(static fn (string $uid) => $uid === 'admin');
        $controller = fn (string $uid) => new DocumentController('learning', $this->createMock(IRequest::class), $documents,
            $courseService, $roles, $this->createMock(LoggerInterface::class), $uid);

        $this->assertSame(['folder' => null, 'linked' => true], $controller('learner')->getFolder(7)->getData());
        foreach (['teacher', 'coteacher', 'admin'] as $uid) {
            $this->assertSame(['folder' => '/Home of teacher/Private/Material', 'linked' => true], $controller($uid)->getFolder(7)->getData(), $uid);
        }
    }
}
