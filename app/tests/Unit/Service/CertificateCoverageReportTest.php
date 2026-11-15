<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\AppInfo\ConfigDefaults;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\ReminderService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Lückencheck B2: the coverage report lists EVERY enrolled learner of a certifying course — also
 * those who never passed, whom the issued-certificates report cannot show — with the status
 * current / renewal_open / expired (in grace) / overdue / never_passed.
 *
 * The real CourseService runs the instructor gate (only its mappers are mocked); the status is the
 * group report's classification refined with the shipped renewal (30-day) and grace (14-day)
 * windows from ConfigDefaults.
 */
#[AllowMockObjectsWithoutExpectations]
class CertificateCoverageReportTest extends TestCase {
    private const COURSE_ID = 7;
    private const OWNER = 'alice';
    private const NOW = 1_700_000_000;
    private const DAY = 86400;

    private function config(): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn(string $app, string $key, mixed $default = ''): string => (string)$default
        );
        return $config;
    }

    private function courseService(): CourseService {
        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId(self::OWNER);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);
        $memberLookup = $this->createMock(CourseMemberMapper::class);
        $memberLookup->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('no member'));
        return new CourseService(
            $courseMapper,
            $this->createMock(CoursePoolMapper::class),
            $memberLookup,
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
    }

    private function member(string $uid, string $role): CourseMember {
        $m = new CourseMember();
        $m->setUserId($uid);
        $m->setRole($role);
        return $m;
    }

    private function cert(string $uid, ?string $name, int $issuedAt, ?int $expiresAt, int $id = 1): Certificate {
        $payload = ['credentialSubject' => ['name' => $name, 'result' => [['resultDescription' => 'score:90']]]];
        $b64u = rtrim(strtr(base64_encode((string)json_encode($payload)), '+/', '-_'), '=');
        $cert = new Certificate();
        $cert->setId($id);
        $cert->setVerificationId('vid-' . $uid . '-' . $id);
        $cert->setUserId($uid);
        $cert->setCourseId(self::COURSE_ID);
        $cert->setCredentialJson('header.' . $b64u . '.sig');
        $cert->setIssuedAt($issuedAt);
        $cert->setExpiresAt($expiresAt);
        $cert->setRevoked(false);
        return $cert;
    }

    /**
     * @param list<CourseMember> $members
     * @param list<Certificate> $certs
     * @param array<string, array{status: string, due_date: int|null}> $states
     * @param array<string, array{passed: int, total: int}> $pools
     * @param array<string, string> $names uid => account display name
     */
    private function service(array $members, array $certs = [], array $states = [], array $pools = [], array $names = [], int $now = self::NOW, ?PassCriteriaService $pass = null): CertificateReportService {
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourse')->willReturn($members);
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseIdForUsers')->willReturnCallback(
            static fn(int $courseId, array $uids): array => array_values(array_filter($certs, static fn(Certificate $c): bool => in_array($c->getUserId(), $uids, true)))
        );
        $assignment = $this->createMock(AssignmentService::class);
        $assignment->method('getStatesForCourseAndUsers')->willReturn($states);
        if ($pass === null) {
            $pass = $this->createMock(PassCriteriaService::class);
            $pass->method('requiredPoolProgress')->willReturn($pools);
        }
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) use ($names): ?IUser {
            if (!isset($names[$uid])) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getDisplayName')->willReturn($names[$uid]);
            return $user;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);

        return new CertificateReportService(
            $this->courseService(), $certMapper, $time,
            $this->createMock(RoleService::class), $assignment, $this->createMock(ReminderService::class),
            $this->createMock(IGroupManager::class), $pass, $memberMapper, $users, $this->config()
        );
    }

    /** @return array<string, array<string, mixed>> rows by display name */
    private function byName(array $result): array {
        $out = [];
        foreach ($result['rows'] as $row) {
            $out[$row['display_name']] = $row;
        }
        return $out;
    }

    public function testAForeignInstructorIsRefusedBeforeAnyRead(): void {
        $members = $this->createMock(CourseMemberMapper::class);
        $members->expects($this->never())->method('findByCourse');
        $certs = $this->createMock(CertificateMapper::class);
        $certs->expects($this->never())->method('findByCourseIdForUsers');
        $assignment = $this->createMock(AssignmentService::class);
        $assignment->expects($this->never())->method('getStatesForCourseAndUsers');
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('requiredPoolProgress');
        $service = new CertificateReportService(
            $this->courseService(), $certs, $this->createMock(ITimeFactory::class),
            $this->createMock(RoleService::class), $assignment, $this->createMock(ReminderService::class),
            $this->createMock(IGroupManager::class), $pass, $members, $this->createMock(IUserManager::class), $this->config()
        );

        $this->expectException(ForbiddenException::class);
        $service->getCoverageReport(self::COURSE_ID, 'mallory', null);
    }

    public function testEveryLearnerIsListedWithTheirStatusAndNoInstructor(): void {
        $now = self::NOW;
        $result = $this->service(
            [
                $this->member(self::OWNER, 'student'), // the owner, even with a student row
                $this->member('ivo', 'instructor'),
                $this->member('cur', 'student'),
                $this->member('ren', 'student'),
                $this->member('exp', 'student'),
                $this->member('ovd', 'student'),
                $this->member('late', 'student'),
                $this->member('never', 'student'),
                $this->member('forever', 'student'),
            ],
            [
                $this->cert('cur', 'Cora Current', $now - 300 * self::DAY, $now + 60 * self::DAY),
                $this->cert('ren', 'Rena Renew', $now - 340 * self::DAY, $now + 10 * self::DAY),
                $this->cert('exp', 'Ezra Expired', $now - 370 * self::DAY, $now - 3 * self::DAY),
                $this->cert('ovd', 'Olga Overdue', $now - 400 * self::DAY, $now - 20 * self::DAY),
                $this->cert('forever', 'Finn Forever', $now - 900 * self::DAY, null),
                $this->cert('ivo', 'Ivo Instructor', $now - 10 * self::DAY, null),
            ],
            [
                'late' => ['status' => 'assigned', 'due_date' => $now - self::DAY, 'assigned_at' => 0],
                'never' => ['status' => 'assigned', 'due_date' => $now + self::DAY, 'assigned_at' => 0],
            ],
            ['cur' => ['passed' => 2, 'total' => 2], 'never' => ['passed' => 1, 'total' => 2]],
            ['late' => 'Lars Late', 'never' => 'Nora Never', self::OWNER => 'Alice Owner', 'ivo' => 'Ivo Instructor']
        )->getCoverageReport(self::COURSE_ID, self::OWNER, null);

        $rows = $this->byName($result);
        $this->assertSame(
            ['Cora Current', 'Ezra Expired', 'Finn Forever', 'Lars Late', 'Nora Never', 'Olga Overdue', 'Rena Renew'],
            array_column($result['rows'], 'display_name'),
            'every learner, sorted by name; neither the owner nor a co-instructor'
        );
        $this->assertSame('current', $rows['Cora Current']['status']);
        $this->assertSame('renewal_open', $rows['Rena Renew']['status']);
        $this->assertSame('expired', $rows['Ezra Expired']['status'], 'expired 3 days ago: inside the 14-day grace');
        $this->assertSame('overdue', $rows['Olga Overdue']['status'], 'expired 20 days ago: past the grace');
        $this->assertSame('current', $rows['Finn Forever']['status'], 'a certificate without expiry stays current');
        $this->assertSame('overdue', $rows['Lars Late']['status'], 'no certificate and the due date has passed');
        $this->assertSame('never_passed', $rows['Nora Never']['status']);

        $this->assertSame($now - 300 * self::DAY, $rows['Cora Current']['last_certificate_at']);
        $this->assertSame($now + 60 * self::DAY, $rows['Cora Current']['expires_at']);
        $this->assertNull($rows['Nora Never']['last_certificate_at']);
        $this->assertNull($rows['Nora Never']['expires_at']);
        $this->assertSame([2, 2], [$rows['Cora Current']['required_pools_passed'], $rows['Cora Current']['required_pools_total']]);
        $this->assertSame([1, 2], [$rows['Nora Never']['required_pools_passed'], $rows['Nora Never']['required_pools_total']]);
        $this->assertSame([0, 0], [$rows['Lars Late']['required_pools_passed'], $rows['Lars Late']['required_pools_total']]);
    }

    /** The boundaries come from the same parsers as the reminders and the renewal (ConfigDefaults). */
    public function testStatusBoundariesFollowTheRenewalAndGraceWindows(): void {
        $exp = self::NOW;
        $opensAt = ConfigDefaults::renewalOpensAt($this->config(), $exp);
        $graceEnd = $exp + 14 * self::DAY;
        $members = [$this->member('b', 'student')];
        $certs = [$this->cert('b', 'Bo Boundary', $exp - 365 * self::DAY, $exp)];
        $status = fn(int $now): string => $this->service($members, $certs, [], [], [], $now)
            ->getCoverageReport(self::COURSE_ID, self::OWNER, null)['rows'][0]['status'];

        $this->assertSame('current', $status($opensAt - 1));
        $this->assertSame('renewal_open', $status($opensAt), 'renewal opens with the first reminder');
        $this->assertSame('renewal_open', $status($exp), 'valid until the expiry second itself');
        $this->assertSame('expired', $status($exp + 1));
        $this->assertSame('expired', $status($graceEnd), 'the last second of the grace window');
        $this->assertSame('overdue', $status($graceEnd + 1));
    }

    public function testTheNewestCertificateDecidesWhateverTheMapperOrder(): void {
        $now = self::NOW;
        $result = $this->service(
            [$this->member('re', 'student')],
            [
                $this->cert('re', 'New Name', $now - 10 * self::DAY, $now + 355 * self::DAY, 2),
                $this->cert('re', 'Old Name', $now - 800 * self::DAY, $now - 400 * self::DAY, 1),
            ]
        )->getCoverageReport(self::COURSE_ID, self::OWNER, null);

        $this->assertSame('New Name', $result['rows'][0]['display_name']);
        $this->assertSame('current', $result['rows'][0]['status']);
        $this->assertSame($now - 10 * self::DAY, $result['rows'][0]['last_certificate_at']);
    }

    public function testTheStatusFilterKeepsOneStatusAndAnUnknownOneIsIgnored(): void {
        $now = self::NOW;
        $make = fn(): CertificateReportService => $this->service(
            [$this->member('a', 'student'), $this->member('b', 'student'), $this->member('c', 'student')],
            [$this->cert('a', 'Anna', $now - self::DAY, null)],
            ['b' => ['status' => 'assigned', 'due_date' => $now - self::DAY, 'assigned_at' => 0]],
            [],
            ['b' => 'Ben', 'c' => 'Cleo']
        );

        $this->assertSame(['Ben'], array_column($make()->getCoverageReport(self::COURSE_ID, self::OWNER, 'overdue')['rows'], 'display_name'));
        $this->assertSame(['Cleo'], array_column($make()->getCoverageReport(self::COURSE_ID, self::OWNER, 'never_passed')['rows'], 'display_name'));
        $this->assertSame(['Anna', 'Ben', 'Cleo'], array_column($make()->getCoverageReport(self::COURSE_ID, self::OWNER, 'bogus')['rows'], 'display_name'));
    }

    /**
     * requiredPoolProgress() reads per learner (certificate, window, best scores). A filtered view
     * must only pay for the rows it shows, and a filter nobody matches reads no progress at all.
     */
    public function testThePoolProgressIsReadOnlyForTheRowsThatPassTheFilter(): void {
        $now = self::NOW;
        $members = [$this->member('a', 'student'), $this->member('b', 'student'), $this->member('c', 'student')];
        $certs = [$this->cert('a', 'Anna', $now - self::DAY, null)];
        $states = ['b' => ['status' => 'assigned', 'due_date' => $now - self::DAY, 'assigned_at' => 0]];
        $names = ['b' => 'Ben', 'c' => 'Cleo'];

        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->once())->method('requiredPoolProgress')
            ->with(self::COURSE_ID, ['b'])
            ->willReturn(['b' => ['passed' => 1, 'total' => 2]]);
        $rows = $this->service($members, $certs, $states, [], $names, $now, $pass)
            ->getCoverageReport(self::COURSE_ID, self::OWNER, 'overdue')['rows'];
        $this->assertSame(['Ben'], array_column($rows, 'display_name'));
        $this->assertSame([1, 2], [$rows[0]['required_pools_passed'], $rows[0]['required_pools_total']]);

        $none = $this->createMock(PassCriteriaService::class);
        $none->expects($this->never())->method('requiredPoolProgress');
        $this->assertSame(
            ['rows' => []],
            $this->service($members, $certs, $states, [], $names, $now, $none)->getCoverageReport(self::COURSE_ID, self::OWNER, 'expired')
        );

        $all = $this->createMock(PassCriteriaService::class);
        $all->expects($this->once())->method('requiredPoolProgress')->with(self::COURSE_ID, ['a', 'b', 'c'])->willReturn([]);
        $this->assertCount(3, $this->service($members, $certs, $states, [], $names, $now, $all)->getCoverageReport(self::COURSE_ID, self::OWNER, null)['rows']);
    }

    public function testNoRowCarriesTheAccountIdOrAnEmail(): void {
        $now = self::NOW;
        $result = $this->service(
            [$this->member('jane@corp.example', 'student'), $this->member('max', 'student'), $this->member('eve', 'student')],
            [$this->cert('eve', 'eve@corp.example', $now - self::DAY, null)],
            [],
            [],
            ['jane@corp.example' => 'jane@corp.example', 'max' => 'max']
        )->getCoverageReport(self::COURSE_ID, self::OWNER, null);

        $this->assertCount(3, $result['rows']);
        foreach ($result['rows'] as $row) {
            $this->assertSame(
                ['member_ref', 'display_name', 'status', 'last_certificate_at', 'expires_at', 'required_pools_passed', 'required_pools_total'],
                array_keys($row)
            );
            $this->assertSame('Teilnehmer:in', $row['display_name']);
            $flat = (string)json_encode($row);
            $this->assertStringNotContainsString('@', $flat);
            $this->assertStringNotContainsString('max', $flat);
        }
    }

    public function testACourseWithoutLearnersReadsNoCertificates(): void {
        $certs = $this->createMock(CertificateMapper::class);
        $certs->expects($this->never())->method('findByCourseIdForUsers');
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$this->member('ivo', 'instructor')]);
        $service = new CertificateReportService(
            $this->courseService(), $certs, $this->createMock(ITimeFactory::class),
            $this->createMock(RoleService::class), $this->createMock(AssignmentService::class), $this->createMock(ReminderService::class),
            $this->createMock(IGroupManager::class), $this->createMock(PassCriteriaService::class), $members,
            $this->createMock(IUserManager::class), $this->config()
        );
        $this->assertSame(['rows' => []], $service->getCoverageReport(self::COURSE_ID, self::OWNER, null));
    }
}
