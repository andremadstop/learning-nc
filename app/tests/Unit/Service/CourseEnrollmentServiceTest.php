<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\AlreadyMemberException;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\GroupUnavailableException;
use OCA\Learning\Service\MemberNotFoundException;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35 — enrolment by instructors.
 *
 * The course permission check is the REAL CourseService::assertInstructorOfCourse over mocked
 * mappers, so "a student gets 403 and nothing is searched/inserted" tests the actual gate, not a
 * mock of it. Memberships live in $this->rows; insert() appends, findByCourseAndUser() compares
 * the user id exactly, as PostgreSQL does.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseEnrollmentServiceTest extends TestCase {
    private const COURSE_ID = 7;
    private const OWNER = 'teacher';

    /** @var list<CourseMember> */
    private array $rows = [];
    /** @var list<CourseMember> */
    private array $inserted = [];
    /** @var array<string, string> typed (lower-cased) => canonical uid of existing accounts */
    private array $accounts = [];
    /** @var array<string, list<string>> gid => member uids */
    private array $groups = [];
    /** @var array<string, list<string>> uid => gids */
    private array $userGroups = [];
    /** @var list<string> */
    private array $hiddenGroups = [];
    private bool $groupSharing = true;
    private bool $groupMembersOnly = false;
    private bool $enumeration = true;
    private bool $enumerationToGroups = false;
    private bool $enumerationToPhone = false;
    /** @var list<string> */
    private array $admins = [];
    /** @var list<string> */
    private array $excludedGroups = [];
    /** @var list<string> uids notified */
    private array $notified = [];
    private ?\Closure $insertHook = null;
    /** @var list<int> limits passed to IGroup::searchUsers() */
    private array $expandedLimits = [];
    private ?\Throwable $notifyFailure = null;

    private CourseMemberMapper $memberMapper;
    private ISearch $search;

    protected function setUp(): void {
        $this->accounts = ['alice' => 'alice', 'bob' => 'bob', 'carol' => 'carol', 'teacher' => 'teacher', 'cohost' => 'cohost', 'student' => 'student'];
        $this->rows = [
            $this->member('cohost', 'instructor'),
            $this->member('student', 'student'),
        ];
        $this->search = $this->createMock(ISearch::class);
    }

    private function member(string $uid, string $role, int $courseId = self::COURSE_ID): CourseMember {
        $m = new CourseMember();
        $m->setCourseId($courseId);
        $m->setUserId($uid);
        $m->setRole($role);
        $m->setEnrolledAt(1);
        return $m;
    }

    private function user(string $uid): IUser {
        $u = $this->createMock(IUser::class);
        $u->method('getUID')->willReturn($uid);
        $u->method('getDisplayName')->willReturn(ucfirst($uid) . ' Name');
        return $u;
    }

    private function service(): CourseEnrollmentService {
        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId(self::OWNER);
        $course->setTitle('Arbeitsschutz 2026');

        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturnCallback(function (int $id) use ($course) {
            if ($id !== self::COURSE_ID) {
                throw new DoesNotExistException('no course');
            }
            return $course;
        });

        $this->memberMapper = $this->createMock(CourseMemberMapper::class);
        $this->memberMapper->method('findByCourse')->willReturnCallback(
            fn (int $cid) => array_values(array_filter($this->rows, fn (CourseMember $m) => $m->getCourseId() === $cid))
        );
        $this->memberMapper->method('findByCourseAndUser')->willReturnCallback(function (int $cid, string $uid) {
            foreach ($this->rows as $m) {
                if ($m->getCourseId() === $cid && $m->getUserId() === $uid) {
                    return $m;
                }
            }
            throw new DoesNotExistException('not a member');
        });
        $this->memberMapper->method('insert')->willReturnCallback(function (CourseMember $m) {
            if ($this->insertHook !== null) {
                ($this->insertHook)($m);
            }
            $this->rows[] = $m;
            $this->inserted[] = $m;
            return $m;
        });
        $this->memberMapper->method('update')->willReturnArgument(0);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(function (string $typed) {
            $canonical = $this->accounts[strtolower($typed)] ?? null;
            return $canonical === null ? null : $this->user($canonical);
        });

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturnCallback(function (string $gid) {
            if (!isset($this->groups[$gid])) {
                return null;
            }
            $g = $this->createMock(IGroup::class);
            $g->method('getGID')->willReturn($gid);
            $g->method('hideFromCollaboration')->willReturn(in_array($gid, $this->hiddenGroups, true));
            $g->method('getUsers')->willReturnCallback(function () {
                $this->fail('a group is expanded only bounded, through searchUsers()');
            });
            $g->method('searchUsers')->willReturnCallback(function (string $search, ?int $limit = null) use ($gid) {
                $this->assertSame('', $search);
                $this->assertNotNull($limit, 'a group is never loaded without a limit');
                $this->expandedLimits[] = $limit;
                return array_map(fn (string $u) => $this->user($u), array_slice($this->groups[$gid], 0, $limit));
            });
            return $g;
        });
        $groupManager->method('getUserGroupIds')->willReturnCallback(fn (IUser $u) => $this->userGroups[$u->getUID()] ?? []);
        $groupManager->method('isAdmin')->willReturnCallback(fn ($uid) => in_array($uid, $this->admins, true));

        $shareManager = $this->createMock(IShareManager::class);
        $shareManager->method('allowGroupSharing')->willReturnCallback(fn () => $this->groupSharing);
        $shareManager->method('shareWithGroupMembersOnly')->willReturnCallback(fn () => $this->groupMembersOnly);
        $shareManager->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturnCallback(fn () => $this->excludedGroups);
        $shareManager->method('allowEnumeration')->willReturnCallback(fn () => $this->enumeration);
        $shareManager->method('limitEnumerationToGroups')->willReturnCallback(fn () => $this->enumerationToGroups);
        $shareManager->method('limitEnumerationToPhone')->willReturnCallback(fn () => $this->enumerationToPhone);

        $notifications = $this->createMock(INotificationManager::class);
        $notifications->method('createNotification')->willReturnCallback(function () {
            $n = $this->createMock(INotification::class);
            $state = new \ArrayObject();
            foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
                $n->method($setter)->willReturnSelf();
            }
            $n->method('setUser')->willReturnCallback(function (string $uid) use ($n, $state) { $state['user'] = $uid; return $n; });
            $n->method('setSubject')->willReturnCallback(function (string $s, array $p) use ($n, $state) { $state['subject'] = $s; $state['params'] = $p; return $n; });
            $n->method('getUser')->willReturnCallback(fn () => $state['user'] ?? '');
            $n->method('getSubject')->willReturnCallback(fn () => $state['subject'] ?? '');
            $n->method('getSubjectParameters')->willReturnCallback(fn () => $state['params'] ?? []);
            return $n;
        });
        $notifications->method('notify')->willReturnCallback(function (INotification $n) {
            if ($this->notifyFailure !== null) {
                throw $this->notifyFailure;
            }
            $this->assertSame('course_enrolled', $n->getSubject());
            $this->assertSame(['course_title' => 'Arbeitsschutz 2026', 'course_id' => self::COURSE_ID], $n->getSubjectParameters());
            $this->notified[] = $n->getUser();
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1700000000);
        $time->method('getDateTime')->willReturn(new \DateTime('@1700000000'));

        $courseService = new CourseService(
            $courseMapper,
            $this->createMock(CoursePoolMapper::class),
            $this->memberMapper,
            $this->createMock(RoleService::class),
            $this->createMock(IDBConnection::class),
            $groupManager,
            $userManager,
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );

        return new CourseEnrollmentService(
            $courseService,
            $this->memberMapper,
            $userManager,
            $groupManager,
            $shareManager,
            $this->search,
            $notifications,
            $time,
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @return list<string> */
    private function insertedUids(): array {
        return array_map(fn (CourseMember $m) => $m->getUserId(), $this->inserted);
    }

    /** @param list<array{user_id: string, display_name: string}> $entries */
    private function uids(array $entries): array {
        return array_map(fn (array $e) => $e['user_id'], $entries);
    }

    // ── single add ───────────────────────────────────────────────────────────

    public function testAddMemberStoresTheCanonicalUidNotWhatWasTyped(): void {
        $result = $this->service()->addMember(self::COURSE_ID, 'Alice', 'student', self::OWNER);

        // Stored as typed ("Alice"), findByCourseAndUser($id, 'alice') never matched: the learner
        // never saw the course.
        $this->assertTrue($result['created']);
        $this->assertSame(['alice'], $this->insertedUids());
        $this->assertSame('student', $this->inserted[0]->getRole());
        $this->assertSame(1700000000, $this->inserted[0]->getEnrolledAt());
        $this->assertSame(['alice'], $this->notified, 'the enrolled person is told, under the canonical uid');
    }

    public function testAddMemberUnknownAccountIsNotFound(): void {
        $this->expectException(MemberNotFoundException::class);
        try {
            $this->service()->addMember(self::COURSE_ID, 'ghost', 'student', self::OWNER);
        } finally {
            $this->assertSame([], $this->inserted);
        }
    }

    public function testAddMemberWithTheSameRoleIsAlreadyAMember(): void {
        $this->expectException(AlreadyMemberException::class);
        try {
            // typed in another case: still the same account, still already enrolled
            $this->service()->addMember(self::COURSE_ID, 'STUDENT', 'student', self::OWNER);
        } finally {
            $this->assertSame([], $this->inserted);
            $this->assertSame([], $this->notified);
        }
    }

    public function testAddMemberWithAnotherRoleChangesTheRole(): void {
        // the members tab's role toggle posts to the same endpoint
        $result = $this->service()->addMember(self::COURSE_ID, 'student', 'instructor', self::OWNER);

        $this->assertFalse($result['created']);
        $this->assertSame('instructor', $result['member']->getRole());
        $this->assertSame([], $this->inserted);
        $this->assertSame([], $this->notified, 'a role change is not an enrolment');
    }

    public function testAddingTheCourseOwnerIsAlreadyAMember(): void {
        $this->expectException(AlreadyMemberException::class);
        $this->service()->addMember(self::COURSE_ID, 'Teacher', 'student', 'cohost');
    }

    public function testACoInstructorEnrolsSomeoneElseWhoIsNotified(): void {
        $this->service()->addMember(self::COURSE_ID, 'alice', 'student', 'cohost');
        $this->assertSame(['alice'], $this->notified);
    }

    public function testNotificationFailureDoesNotUndoOrHideTheEnrolment(): void {
        $this->notifyFailure = new \RuntimeException('notifications app broken');

        $result = $this->service()->addMember(self::COURSE_ID, 'alice', 'student', self::OWNER);

        $this->assertTrue($result['created']);
        $this->assertSame(['alice'], $this->insertedUids());
    }

    public function testAConcurrentEnrolmentIsReportedAsAlreadyAMember(): void {
        $this->insertHook = function () {
            throw new DBException(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate key');
        };
        $this->expectException(AlreadyMemberException::class);
        $this->service()->addMember(self::COURSE_ID, 'alice', 'student', self::OWNER);
    }

    public function testAStudentCannotAddMembers(): void {
        $this->expectException(ForbiddenException::class);
        try {
            $this->service()->addMember(self::COURSE_ID, 'alice', 'student', 'student');
        } finally {
            $this->assertSame([], $this->inserted);
        }
    }

    // ── bulk ─────────────────────────────────────────────────────────────────

    public function testBulkEnrolsUsersAndTheCurrentMembersOfAGroupOnce(): void {
        $this->groups['team'] = ['alice', 'bob', 'student'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, ['Bob', 'ghost', 'bob'], ['team'], 'student', self::OWNER);

        // bob comes twice (typed and via the group) and is enrolled once; student already is a member
        $this->assertSame(['bob', 'alice'], $this->insertedUids());
        $this->assertSame(['bob', 'alice'], $this->uids($result['added']));
        $this->assertSame('Bob Name', $result['added'][0]['display_name']);
        $this->assertSame(['student'], $this->uids($result['already']));
        $this->assertSame(['ghost'], $result['notFound']);
        $this->assertSame([], $result['groupsUnavailable']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(['bob', 'alice'], $this->notified);
    }

    public function testBulkGroupIsASnapshotOfItsMembersAtThatMoment(): void {
        $this->groups['team'] = ['alice'];
        $service = $this->service();
        $service->bulkAdd(self::COURSE_ID, [], ['team'], 'student', self::OWNER);

        $this->groups['team'][] = 'carol'; // joins the group afterwards

        $this->assertSame(['alice'], $this->insertedUids(), 'nobody is added later on their own');
    }

    public function testBulkNeverChangesTheRoleOfAnExistingMember(): void {
        $this->groups['team'] = ['cohost', 'alice'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['team'], 'student', self::OWNER);

        $this->assertSame(['cohost'], $this->uids($result['already']));
        $this->assertSame('instructor', $this->rows[0]->getRole(), 'adding a group as students must not demote an instructor');
    }

    public function testBulkTreatsTheCourseOwnerAsAlreadyEnrolled(): void {
        $this->groups['team'] = ['teacher', 'alice'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['team'], 'student', 'cohost');

        $this->assertSame(['alice'], $this->insertedUids());
        $this->assertSame(['teacher'], $this->uids($result['already']));
    }

    public function testInstructorsWhoEnrolThemselvesAreNeitherDuplicatedNorNotified(): void {
        // the owner and a co-instructor both are in the group they enrol
        $this->groups['team'] = ['teacher', 'cohost', 'alice'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, ['Teacher'], ['team'], 'student', 'cohost');

        $this->assertSame(['alice'], $this->insertedUids());
        $this->assertSame(['cohost'], $this->uids($result['already']));
        // the owner was picked by name as a student: the owner stays an instructor, and the answer says so
        $this->assertSame([['user_id' => 'teacher', 'display_name' => 'Teacher Name', 'role' => 'instructor']], $result['roleUnchanged']);
        $this->assertSame(['alice'], $this->notified);
    }

    public function testBulkReportsAGroupTheInstructorMayNotSeeAndDoesNotExpandIt(): void {
        $this->groups['board'] = ['alice'];
        $this->groupMembersOnly = true;
        $this->userGroups['teacher'] = ['staff'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['board', 'nonexistent'], 'student', self::OWNER);

        // a missing group and a forbidden one look the same: the API reveals neither existence nor members
        $this->assertSame(['board', 'nonexistent'], $result['groupsUnavailable']);
        $this->assertSame([], $result['added']);
        $this->assertSame([], $this->inserted);
    }

    public function testBulkHonoursTheGroupMembersOnlyExclusionList(): void {
        $this->groups['staff'] = ['alice'];
        $this->groupMembersOnly = true;
        $this->userGroups['teacher'] = ['staff'];
        $this->excludedGroups = ['staff'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['staff'], 'student', self::OWNER);

        $this->assertSame(['staff'], $result['groupsUnavailable']);
    }

    public function testBulkAllowsTheInstructorsOwnGroupUnderGroupMembersOnly(): void {
        $this->groups['staff'] = ['alice'];
        $this->groupMembersOnly = true;
        $this->userGroups['teacher'] = ['staff'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['staff'], 'student', self::OWNER);

        $this->assertSame(['alice'], $this->uids($result['added']));
    }

    public function testBulkRefusesGroupsWhenGroupSharingIsOffOrTheGroupIsHidden(): void {
        $this->groups['team'] = ['alice'];
        $this->groups['hidden'] = ['bob'];
        $this->hiddenGroups = ['hidden'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['hidden'], 'student', self::OWNER);
        $this->assertSame(['hidden'], $result['groupsUnavailable']);

        $this->groupSharing = false;
        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['team'], 'student', self::OWNER);
        $this->assertSame(['team'], $result['groupsUnavailable']);
        $this->assertSame([], $this->inserted);
    }

    public function testBulkReportsConcurrentInsertsAsAlreadyAndOtherFailuresAsFailed(): void {
        $this->insertHook = function (CourseMember $m) {
            if ($m->getUserId() === 'alice') {
                throw new DBException(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate key');
            }
            if ($m->getUserId() === 'bob') {
                throw new DBException(0, 'connection lost');
            }
        };

        $result = $this->service()->bulkAdd(self::COURSE_ID, ['alice', 'bob', 'carol'], [], 'student', self::OWNER);

        $this->assertSame(['alice'], $this->uids($result['already']));
        $this->assertSame(['bob'], $this->uids($result['failed']), 'a failed insert is named, never counted as added');
        $this->assertSame(['carol'], $this->uids($result['added']));
        $this->assertSame(['carol'], $this->notified);
    }

    public function testBulkRejectsAnUnknownRole(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_role');
        $this->service()->bulkAdd(self::COURSE_ID, ['alice'], [], 'admin', self::OWNER);
    }

    public function testBulkRejectsAnEmptySelection(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing_selected');
        $this->service()->bulkAdd(self::COURSE_ID, ['', 5, null], [], 'student', self::OWNER);
    }

    public function testBulkRejectsTooManyAccountsBeforeInsertingAnything(): void {
        $this->groups['everyone'] = array_map(fn (int $i) => 'u' . $i, range(1, CourseEnrollmentService::MAX_ENROLMENTS + 1));
        try {
            $this->service()->bulkAdd(self::COURSE_ID, [], ['everyone'], 'student', self::OWNER);
            $this->fail('expected too_many');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('too_many', $e->getMessage());
        }
        $this->assertSame([], $this->inserted);
    }

    public function testAStudentCannotBulkEnrolOrExpandGroups(): void {
        $this->groups['team'] = ['alice'];
        $this->expectException(ForbiddenException::class);
        try {
            $this->service()->bulkAdd(self::COURSE_ID, [], ['team'], 'student', 'student');
        } finally {
            $this->assertSame([], $this->inserted);
        }
    }

    // ── course group field ───────────────────────────────────────────────────

    public function testGroupSnapshotForTheCourseFormEnrolsAsStudents(): void {
        $this->groups['team'] = ['alice', 'cohost'];
        $service = $this->service();
        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId(self::OWNER);
        $course->setTitle('Arbeitsschutz 2026');

        $result = $service->enrolGroupSnapshot($course, 'team', self::OWNER);

        $this->assertSame(['alice'], $this->uids($result['added']));
        $this->assertSame('student', $this->inserted[0]->getRole());
        $this->assertSame(['cohost'], $this->uids($result['already']));
    }

    public function testAssertGroupEnrollableReturnsTheCanonicalGroupId(): void {
        $this->groups['team'] = ['alice'];
        $this->assertSame('team', $this->service()->assertGroupEnrollable('team', self::OWNER));
    }

    public function testAssertGroupEnrollableRejectsAMissingGroup(): void {
        $this->expectException(GroupUnavailableException::class);
        $this->service()->assertGroupEnrollable('nope', self::OWNER);
    }

    // ── search ───────────────────────────────────────────────────────────────

    public function testAStudentGetsForbiddenAndNothingIsSearched(): void {
        $this->search->expects($this->never())->method('search');
        $this->expectException(ForbiddenException::class);
        $this->service()->search(self::COURSE_ID, 'a', 'student');
    }

    public function testANonMemberGetsForbiddenAndNothingIsSearched(): void {
        $this->search->expects($this->never())->method('search');
        $this->expectException(ForbiddenException::class);
        $this->service()->search(self::COURSE_ID, 'a', 'alice');
    }

    public function testSearchUsesTheCollaboratorSearchForUsersAndGroupsAndMarksMembers(): void {
        $this->search->expects($this->once())->method('search')
            ->with('al', [IShare::TYPE_USER, IShare::TYPE_GROUP], false, 25, 0)
            ->willReturn([[
                'exact' => [
                    'users' => [['label' => 'Alice Name', 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => 'alice'], 'shareWithDisplayNameUnique' => 'alice@example.org']],
                    'groups' => [],
                ],
                'users' => [
                    ['label' => 'Alice Name', 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => 'alice']],
                    ['label' => 'Student Name', 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => 'student'], 'shareWithDisplayNameUnique' => 'student@example.org'],
                    ['label' => 'mail', 'value' => ['shareType' => IShare::TYPE_EMAIL, 'shareWith' => 'x@example.org']],
                ],
                'groups' => [
                    ['label' => 'All staff', 'value' => ['shareType' => IShare::TYPE_GROUP, 'shareWith' => 'staff']],
                ],
            ], false]);
        $this->groups['staff'] = ['alice'];

        $result = $this->service()->search(self::COURSE_ID, '  al  ', 'cohost');

        $this->assertSame([
            'users' => [
                ['id' => 'alice', 'label' => 'Alice Name', 'is_member' => false],
                ['id' => 'student', 'label' => 'Student Name', 'is_member' => true],
            ],
            'groups' => [
                ['id' => 'staff', 'label' => 'All staff'],
            ],
        ], $result);
        $this->assertStringNotContainsString('@example.org', json_encode($result), 'no e-mail addresses are passed on');
    }

    public function testAnEmptySearchTermReturnsNothingWithoutSearching(): void {
        $this->search->expects($this->never())->method('search');
        $this->assertSame(['users' => [], 'groups' => []], $this->service()->search(self::COURSE_ID, '   ', self::OWNER));
    }

    // ── review round: roles, limits, enumeration rules ───────────────────────

    public function testAnExplicitlyPickedMemberWhoseRoleDiffersIsReportedAsRoleUnchanged(): void {
        // The instructor chose "Kursleitung" and picked an existing student by name. The role is
        // not changed silently, and the answer must say so instead of a plain "already".
        $result = $this->service()->bulkAdd(self::COURSE_ID, ['Student', 'cohost'], [], 'instructor', self::OWNER);

        $this->assertSame([['user_id' => 'student', 'display_name' => 'Student Name', 'role' => 'student']], $result['roleUnchanged']);
        $this->assertSame(['cohost'], $this->uids($result['already']), 'same role: simply already a member');
        $this->assertSame('student', $this->rows[1]->getRole());
        $this->assertSame([], $this->inserted);
    }

    public function testAMemberWhoOnlyCameInThroughAGroupIsNotRoleUnchanged(): void {
        $this->groups['team'] = ['student'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['team'], 'instructor', self::OWNER);

        $this->assertSame([], $result['roleUnchanged']);
        $this->assertSame(['student'], $this->uids($result['already']));
    }

    public function testTheUserAndGroupLimitsHaveTheirOwnCodes(): void {
        $users = array_map(fn (int $i) => 'u' . $i, range(1, CourseEnrollmentService::MAX_USER_IDS + 1));
        $groups = array_map(fn (int $i) => 'g' . $i, range(1, CourseEnrollmentService::MAX_GROUP_IDS + 1));
        foreach ([[$users, [], 'too_many_users'], [[], $groups, 'too_many_groups']] as [$u, $g, $code]) {
            try {
                $this->service()->bulkAdd(self::COURSE_ID, $u, $g, 'student', self::OWNER);
                $this->fail('expected ' . $code);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($code, $e->getMessage());
            }
        }
    }

    public function testAHugeGroupIsNeverLoadedBeyondTheEnrolmentLimit(): void {
        $this->groups['everyone'] = array_map(fn (int $i) => 'u' . $i, range(1, 5000));
        try {
            $this->service()->assertGroupEnrollable('everyone', self::OWNER);
            $this->fail('expected too_many');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('too_many', $e->getMessage());
        }
        $this->assertSame([CourseEnrollmentService::MAX_ENROLMENTS + 1], $this->expandedLimits, 'one more than the limit is enough to know');
    }

    public function testAGroupOverlappingEarlierPicksIsEnrolledInFull(): void {
        // B starts with 500 people already collected through A. A slice sized "what is left" (400)
        // would hold only those and silently drop u601..u700 while answering 200.
        $this->groups['a'] = array_map(fn (int $i) => 'u' . $i, range(1, 600));
        $this->groups['b'] = array_merge(
            array_map(fn (int $i) => 'u' . $i, range(1, 500)),
            array_map(fn (int $i) => 'u' . $i, range(601, 700))
        );

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['a', 'b'], 'student', self::OWNER);

        $this->assertCount(700, $result['added']);
        $this->assertContains('u700', $this->uids($result['added']));
        $limit = CourseEnrollmentService::MAX_ENROLMENTS + 1;
        $this->assertSame([$limit, $limit], $this->expandedLimits, 'every group is expanded with the fixed bound');
    }

    public function testBulkRejectsAGroupLargerThanTheLimitWithoutLoadingAllOfIt(): void {
        $this->groups['everyone'] = array_map(fn (int $i) => 'u' . $i, range(1, 5000));
        try {
            $this->service()->bulkAdd(self::COURSE_ID, [], ['everyone'], 'student', self::OWNER);
            $this->fail('expected too_many');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('too_many', $e->getMessage());
        }
        $this->assertSame([CourseEnrollmentService::MAX_ENROLMENTS + 1], $this->expandedLimits);
        $this->assertSame([], $this->inserted);
    }

    public function testAssertGroupEnrollableAcceptsAGroupAtTheLimit(): void {
        $this->groups['team'] = array_map(fn (int $i) => 'u' . $i, range(1, CourseEnrollmentService::MAX_ENROLMENTS));
        $this->assertSame('team', $this->service()->assertGroupEnrollable('team', self::OWNER));
    }

    /** @return iterable<string, array{0: string}> */
    public static function restrictedEnumeration(): iterable {
        yield 'enumeration limited to own groups' => ['groups'];
        yield 'enumeration off' => ['off'];
        yield 'enumeration limited to the phone book' => ['phone'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restrictedEnumeration')]
    public function testRestrictedEnumerationRefusesAForeignGroupAndRevealsNoMember(string $mode): void {
        // Sharing with a group never reveals who is in it; enrolling does. With enumeration
        // restricted, an instructor must not learn the members of a group they are not in.
        match ($mode) {
            'groups' => $this->enumerationToGroups = true,
            'off' => $this->enumeration = false,
            'phone' => $this->enumerationToPhone = true,
        };
        $this->groups['board'] = ['alice', 'bob'];
        $this->groups['staff'] = ['carol'];
        $this->userGroups['teacher'] = ['staff'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['board', 'staff'], 'student', self::OWNER);

        $this->assertSame(['board'], $result['groupsUnavailable']);
        $this->assertSame(['carol'], $this->uids($result['added']), 'the instructor\'s own group still works');
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('alice', $encoded);
        $this->assertStringNotContainsString('bob', $encoded);
        $this->assertSame(['carol'], $this->insertedUids());
    }

    public function testRestrictedEnumerationAlsoGuardsTheCourseFormGroup(): void {
        $this->enumerationToGroups = true;
        $this->groups['board'] = ['alice'];
        $this->expectException(GroupUnavailableException::class);
        $this->service()->assertGroupEnrollable('board', self::OWNER);
    }

    public function testAnAdminMayEnrolAnyVisibleGroupDespiteRestrictions(): void {
        $this->enumerationToGroups = true;
        $this->groupMembersOnly = true;
        $this->admins = ['teacher'];
        $this->groups['board'] = ['alice'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, [], ['board'], 'student', self::OWNER);

        $this->assertSame(['alice'], $this->uids($result['added']));
    }

    public function testSearchOffersNoGroupThatEnrolmentWouldRefuse(): void {
        $this->enumerationToGroups = true;
        $this->groups['board'] = ['alice'];
        $this->groups['staff'] = ['carol'];
        $this->userGroups['teacher'] = ['staff'];
        $this->search->method('search')->willReturn([[
            'exact' => ['users' => [], 'groups' => [['label' => 'Board', 'value' => ['shareType' => IShare::TYPE_GROUP, 'shareWith' => 'board']]]],
            'users' => [],
            'groups' => [['label' => 'Staff', 'value' => ['shareType' => IShare::TYPE_GROUP, 'shareWith' => 'staff']]],
        ], false]);

        $result = $this->service()->search(self::COURSE_ID, 'board', self::OWNER);

        $this->assertSame([['id' => 'staff', 'label' => 'Staff']], $result['groups']);
    }

    public function testGroupMembersOnlyTreatsTypedAccountsOutsideOwnGroupsAsNotFound(): void {
        $this->groupMembersOnly = true;
        $this->userGroups = ['teacher' => ['staff', 'everyone'], 'alice' => ['staff'], 'bob' => ['everyone'], 'carol' => ['other']];
        $this->excludedGroups = ['everyone'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, ['alice', 'Bob', 'carol'], [], 'student', self::OWNER);

        // bob shares only an excluded group, carol none: both look exactly like missing accounts
        $this->assertSame(['alice'], $this->uids($result['added']));
        $this->assertSame(['Bob', 'carol'], $result['notFound']);
        $this->assertSame(['alice'], $this->insertedUids());
        $this->assertStringNotContainsString('Carol Name', json_encode($result), 'no display name of a forbidden account');

        $this->expectException(MemberNotFoundException::class);
        $this->service()->addMember(self::COURSE_ID, 'carol', 'student', self::OWNER);
    }

    public function testGroupMembersOnlyStillLetsTheRoleOfAnExistingMemberBeChanged(): void {
        $this->groupMembersOnly = true;
        $this->userGroups = ['teacher' => ['staff']];

        // "student" shares no group with the owner, but is already in the course
        $result = $this->service()->addMember(self::COURSE_ID, 'student', 'instructor', self::OWNER);

        $this->assertFalse($result['created']);
        $this->assertSame('instructor', $result['member']->getRole());
    }

    public function testAnAdminMayEnrolTypedAccountsOutsideOwnGroups(): void {
        $this->groupMembersOnly = true;
        $this->admins = ['teacher'];

        $result = $this->service()->bulkAdd(self::COURSE_ID, ['carol'], [], 'student', self::OWNER);

        $this->assertSame(['carol'], $this->uids($result['added']));
    }
}
