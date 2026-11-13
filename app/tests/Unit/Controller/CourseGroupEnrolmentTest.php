<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\GroupUnavailableException;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35 — the course form's "Nextcloud group" field promised auto-enrolment and did nothing;
 * on edit the field was sent and never read. Now:
 * - create {title, description, ncGroupId}: the group is checked first (no half-made course for a
 *   mistyped group), then its current members are enrolled, and the response says how many;
 * - update {…, ncGroupId}: null leaves it, '' removes it, a CHANGED group enrols its members;
 *   saving with the same group again does not re-add people removed by hand.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseGroupEnrolmentTest extends TestCase {
    private CourseService $courseService;
    private CourseEnrollmentService $enrollment;
    private RoleService $roles;

    protected function setUp(): void {
        $this->courseService = $this->createMock(CourseService::class);
        $this->enrollment = $this->createMock(CourseEnrollmentService::class);
        $this->roles = $this->createMock(RoleService::class);
        $this->roles->method('isInstructor')->willReturn(true);
    }

    private function controller(): CourseController {
        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(CourseArchiveService::class),
            $this->courseService,
            $this->roles,
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->enrollment,
            $this->createMock(LoggerInterface::class),
            'teacher'
        );
    }

    private function course(?string $group = null): Course {
        $c = new Course();
        $c->setId(7);
        $c->setTitle('Arbeitsschutz');
        $c->setInstructorId('teacher');
        $c->setNcGroupId($group);
        return $c;
    }

    private function snapshot(int $added, int $already = 0): array {
        return [
            'added' => array_fill(0, $added, ['user_id' => 'x', 'display_name' => 'X']),
            'already' => array_fill(0, $already, ['user_id' => 'y', 'display_name' => 'Y']),
            'roleUnchanged' => [], 'notFound' => [], 'groupsUnavailable' => [], 'failed' => [],
        ];
    }

    public function testCreateWithAGroupEnrolsItsCurrentMembers(): void {
        $course = $this->course('team');
        // typed in another case: the group's canonical id is stored and enrolled
        $this->enrollment->expects($this->once())->method('assertGroupEnrollable')->with('Team', 'teacher')->willReturn('team');
        $this->courseService->expects($this->once())->method('create')->with('Arbeitsschutz', null, 'team', 'teacher')->willReturn($course);
        $this->enrollment->expects($this->once())->method('enrolGroupSnapshot')->with($course, 'team', 'teacher')->willReturn($this->snapshot(3, 1));

        $response = $this->controller()->create('Arbeitsschutz', null, ' Team ');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame(['group_id' => 'team', 'added' => 3, 'already' => 1, 'failed' => 0, 'error' => null], $response->getData()['enrollment']);
    }

    public function testCreateWithAnUnavailableGroupCreatesNothing(): void {
        $this->enrollment->method('assertGroupEnrollable')->willThrowException(new GroupUnavailableException(['nope']));
        $this->courseService->expects($this->never())->method('create');

        $response = $this->controller()->create('Arbeitsschutz', null, 'nope');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('group_unavailable', $response->getData()['code']);
    }

    public function testCreateWithAGroupTooLargeToEnrolCreatesNothing(): void {
        // Checked before the course is saved: otherwise the course would keep a group whose members
        // were never enrolled, under a label promising exactly that.
        $this->enrollment->method('assertGroupEnrollable')->willThrowException(new \InvalidArgumentException('too_many'));
        $this->courseService->expects($this->never())->method('create');
        $this->enrollment->expects($this->never())->method('enrolGroupSnapshot');

        $response = $this->controller()->create('Arbeitsschutz', null, 'everyone');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('too_many', $response->getData()['code']);
        $this->assertSame(CourseEnrollmentService::MAX_ENROLMENTS, $response->getData()['max']);
    }

    public function testUpdateToAGroupTooLargeToEnrolSavesNothing(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('old'));
        $this->enrollment->method('assertGroupEnrollable')->willThrowException(new \InvalidArgumentException('too_many'));
        $this->courseService->expects($this->never())->method('update');
        $this->enrollment->expects($this->never())->method('enrolGroupSnapshot');

        $response = $this->controller()->update(7, 'Arbeitsschutz', null, null, 'everyone');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('too_many', $response->getData()['code']);
    }

    public function testCreateWithoutAGroupEnrolsNobody(): void {
        $this->courseService->method('create')->willReturn($this->course());
        $this->enrollment->expects($this->never())->method('enrolGroupSnapshot');

        $response = $this->controller()->create('Arbeitsschutz', null, '');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertArrayNotHasKey('enrollment', $response->getData());
    }

    public function testCreateReportsAFailedEnrolmentInsteadOfHidingIt(): void {
        $this->enrollment->method('assertGroupEnrollable')->willReturn('team');
        $this->courseService->method('create')->willReturn($this->course('team'));
        $this->enrollment->method('enrolGroupSnapshot')->willThrowException(new \RuntimeException('db down'));

        $response = $this->controller()->create('Arbeitsschutz', null, 'team');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus(), 'the course itself was saved');
        $this->assertSame('failed', $response->getData()['enrollment']['error']);
    }

    public function testUpdateWithAChangedGroupStoresItAndEnrolsItsMembers(): void {
        $updated = $this->course('team');
        $this->courseService->expects($this->once())->method('assertInstructorOfCourse')->with(7, 'teacher')->willReturn($this->course('old'));
        $this->enrollment->expects($this->once())->method('assertGroupEnrollable')->with('team', 'teacher')->willReturn('team');
        $this->courseService->expects($this->once())->method('update')->with(7, 'Neu', null, null, 'teacher', 'team')->willReturn($updated);
        $this->enrollment->expects($this->once())->method('enrolGroupSnapshot')->with($updated, 'team', 'teacher')->willReturn($this->snapshot(2));

        $response = $this->controller()->update(7, 'Neu', null, null, 'team');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(2, $response->getData()['enrollment']['added']);
    }

    public function testUpdateWithTheSameGroupDoesNotReAddAnybody(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('team'));
        $this->courseService->expects($this->once())->method('update')->with(7, 'Neu', null, null, 'teacher', null)->willReturn($this->course('team'));
        $this->enrollment->expects($this->never())->method('enrolGroupSnapshot');

        $response = $this->controller()->update(7, 'Neu', null, null, 'team');

        $this->assertArrayNotHasKey('enrollment', $response->getData());
    }

    public function testUpdateWithTheSameGroupInAnotherCaseDoesNotReAddAnybody(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('team'));
        $this->enrollment->method('assertGroupEnrollable')->willReturn('team');
        $this->courseService->expects($this->once())->method('update')->with(7, null, null, null, 'teacher', null)->willReturn($this->course('team'));
        $this->enrollment->expects($this->never())->method('enrolGroupSnapshot');

        $this->controller()->update(7, null, null, null, 'TEAM');
    }

    public function testUpdateWithAnEmptyGroupRemovesItWithoutEnrolling(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('team'));
        $this->courseService->expects($this->once())->method('update')->with(7, null, null, null, 'teacher', '')->willReturn($this->course());
        $this->enrollment->expects($this->never())->method('assertGroupEnrollable');
        $this->enrollment->expects($this->never())->method('enrolGroupSnapshot');

        $this->assertSame(Http::STATUS_OK, $this->controller()->update(7, null, null, null, '')->getStatus());
    }

    public function testUpdateWithoutTheFieldLeavesTheGroupAlone(): void {
        $this->courseService->expects($this->never())->method('assertInstructorOfCourse');
        $this->courseService->expects($this->once())->method('update')->with(7, null, null, 'archived', 'teacher', null)->willReturn($this->course('team'));

        $this->assertSame(Http::STATUS_OK, $this->controller()->update(7, null, null, 'archived')->getStatus());
    }

    public function testUpdateWithAnUnavailableGroupChangesNothing(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->enrollment->method('assertGroupEnrollable')->willThrowException(new GroupUnavailableException(['board']));
        $this->courseService->expects($this->never())->method('update');

        $response = $this->controller()->update(7, 'Neu', null, null, 'board');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('group_unavailable', $response->getData()['code']);
    }

    /**
     * The frontend sends these JSON keys (CourseList.vue, CourseMemberPicker.vue). Nextcloud maps
     * request fields to controller arguments BY NAME; a renamed parameter would leave every unit
     * test above green (they call positionally) and silently drop the field in production.
     */
    public function testControllerParameterNamesMatchTheRequestFieldsTheFrontendSends(): void {
        $names = fn (string $class, string $method) => array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod($class, $method))->getParameters()
        );

        $this->assertSame(['title', 'description', 'ncGroupId'], $names(CourseController::class, 'create'));
        $this->assertSame(['courseId', 'title', 'description', 'status', 'ncGroupId'], $names(CourseController::class, 'update'));
        $this->assertSame(['courseId', 'userId', 'role'], $names(\OCA\Learning\Controller\CourseMemberController::class, 'add'));
        $this->assertSame(['courseId', 'userIds', 'groupIds', 'role'], $names(\OCA\Learning\Controller\CourseMemberController::class, 'bulk'));
        $this->assertSame(['courseId', 'search'], $names(\OCA\Learning\Controller\CourseMemberController::class, 'search'));
    }
}
