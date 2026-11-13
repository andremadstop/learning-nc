<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseMemberController;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Service\AlreadyMemberException;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\MemberNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35 — the enrolment endpoints read exactly these request fields:
 *   POST /members        {userId, role}
 *   POST /members/bulk   {userIds, groupIds, role}
 *   GET  /member-search  ?search=
 * and map the outcomes to the status codes the members tab handles (201/200/404/409/403/500).
 * Before, every error of POST /members was a 400 "Failed to add member", so the tab's 404/409
 * messages could never appear.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseMemberControllerTest extends TestCase {

    private function controller(CourseEnrollmentService $service, ?string $userId = 'teacher'): CourseMemberController {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text, array $p = []) => $p === [] ? $text : vsprintf($text, $p));
        return new CourseMemberController('learning', $this->createMock(IRequest::class), $service, $l, $this->createMock(LoggerInterface::class), $userId);
    }

    private function member(): CourseMember {
        $m = new CourseMember();
        $m->setCourseId(7);
        $m->setUserId('alice');
        $m->setRole('student');
        return $m;
    }

    public function testAddPassesTheTypedUserIdAndRoleAndAnswers201(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->expects($this->once())->method('addMember')->with(7, 'Alice', 'instructor', 'teacher')
            ->willReturn(['member' => $this->member(), 'created' => true]);

        $response = $this->controller($service)->add(7, 'Alice', 'instructor');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    public function testAddAnswers200WhenOnlyTheRoleChanged(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->method('addMember')->willReturn(['member' => $this->member(), 'created' => false]);

        $this->assertSame(Http::STATUS_OK, $this->controller($service)->add(7, 'alice', 'student')->getStatus());
    }

    public function testAddUnknownAccountIs404WithATranslatedMessage(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->method('addMember')->willThrowException(new MemberNotFoundException('x'));

        $response = $this->controller($service)->add(7, ' ghost ', 'student');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'User "ghost" not found.', 'code' => 'user_not_found'], $response->getData());
    }

    public function testAddExistingMemberIs409(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->method('addMember')->willThrowException(new AlreadyMemberException('x'));

        $response = $this->controller($service)->add(7, 'alice', 'student');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame('already_member', $response->getData()['code']);
    }

    public function testAddByAStudentIs403AndMissingCourseIs404(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->method('addMember')->willReturnCallback(function (int $courseId) {
            throw $courseId === 7 ? new ForbiddenException('no') : new DoesNotExistException('no');
        });
        $controller = $this->controller($service, 'student');

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->add(7, 'alice')->getStatus());
        $missing = $controller->add(999, 'alice');
        $this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
        $this->assertSame('course_not_found', $missing->getData()['code']);
    }

    public function testBulkPassesUserIdsGroupIdsAndRoleThrough(): void {
        $result = ['added' => [['user_id' => 'alice', 'display_name' => 'Alice']], 'already' => [], 'roleUnchanged' => [], 'notFound' => ['ghost'], 'groupsUnavailable' => [], 'failed' => []];
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->expects($this->once())->method('bulkAdd')
            ->with(7, ['alice', 'ghost'], ['team'], 'student', 'teacher')
            ->willReturn($result);

        $response = $this->controller($service)->bulk(7, ['alice', 'ghost'], ['team'], 'student');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    public function testBulkWithAFailedInsertIsNotReportedAsSuccess(): void {
        $result = ['added' => [], 'already' => [], 'roleUnchanged' => [], 'notFound' => [], 'groupsUnavailable' => [], 'failed' => [['user_id' => 'bob', 'display_name' => 'Bob']]];
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->method('bulkAdd')->willReturn($result);

        $response = $this->controller($service)->bulk(7, ['bob'], [], 'student');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame($result, $response->getData(), 'the body still names who failed');
    }

    public function testBulkRejectsNonListBodiesWithoutCallingTheService(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->expects($this->never())->method('bulkAdd');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($service)->bulk(7, 'alice', [], 'student')->getStatus());
    }

    public function testBulkMapsValidationAndPermissionErrors(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $errors = [
            new \InvalidArgumentException('too_many'),
            new \InvalidArgumentException('too_many_users'),
            new \InvalidArgumentException('too_many_groups'),
            new \InvalidArgumentException('invalid_role'),
            new ForbiddenException('no'),
        ];
        $service->method('bulkAdd')->willReturnCallback(function () use (&$errors) {
            throw array_shift($errors);
        });
        $controller = $this->controller($service);

        $tooMany = $controller->bulk(7, ['a'], [], 'student');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $tooMany->getStatus());
        $this->assertSame('too_many', $tooMany->getData()['code']);
        $this->assertStringContainsString((string)CourseEnrollmentService::MAX_ENROLMENTS, $tooMany->getData()['error']);
        // each limit names its own number: 201 people must not be told "at most 1000"
        $tooManyUsers = $controller->bulk(7, ['a'], [], 'student');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $tooManyUsers->getStatus());
        $this->assertSame('too_many_users', $tooManyUsers->getData()['code']);
        $this->assertStringContainsString((string)CourseEnrollmentService::MAX_USER_IDS . ' people', $tooManyUsers->getData()['error']);
        $this->assertStringNotContainsString((string)CourseEnrollmentService::MAX_ENROLMENTS, $tooManyUsers->getData()['error']);
        $tooManyGroups = $controller->bulk(7, ['a'], [], 'student');
        $this->assertSame('too_many_groups', $tooManyGroups->getData()['code']);
        $this->assertStringContainsString((string)CourseEnrollmentService::MAX_GROUP_IDS . ' groups', $tooManyGroups->getData()['error']);
        $this->assertSame('invalid_role', $controller->bulk(7, ['a'], [], 'admin')->getData()['code']);
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->bulk(7, ['a'], [], 'student')->getStatus());
    }

    public function testSearchPassesTheSearchParameter(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->expects($this->once())->method('search')->with(7, 'ali', 'teacher')
            ->willReturn(['users' => [['id' => 'alice', 'label' => 'Alice', 'is_member' => false]], 'groups' => []]);

        $response = $this->controller($service)->search(7, 'ali');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('alice', $response->getData()['users'][0]['id']);
    }

    public function testSearchByAStudentIs403(): void {
        $service = $this->createMock(CourseEnrollmentService::class);
        $service->method('search')->willThrowException(new ForbiddenException('no'));

        $response = $this->controller($service, 'student')->search(7, 'a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertArrayNotHasKey('users', $response->getData());
    }
}
