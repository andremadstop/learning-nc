<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #67: the course profile chosen when a course is created. "mandatory" (mandatory training
 * with certificate) switches off Leitner, the simulator tools, the class register, the learning
 * partners, the game modes and "Add to LinkedIn", and switches the certification on. "it" (or no
 * profile) keeps today's defaults. The profile is a preselection only: nothing stores it, and each
 * switch stays changeable. The LinkedIn switch is new and must survive later mode-config saves.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseProfile67Test extends TestCase {
    private function service(CourseMapper $courses, bool $gamification = true): CourseService {
        $roles = $this->createMock(RoleService::class);
        $roles->method('isInstructor')->willReturn(true);
        $xp = $this->createMock(XpService::class);
        $xp->method('isGamificationEnabled')->willReturn($gamification);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);
        return new CourseService(
            $courses,
            $pools,
            $this->createMock(CourseMemberMapper::class),
            $roles,
            new FakeDbConnection(),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $xp,
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
    }

    private function inserting(): CourseMapper {
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('insert')->willReturnArgument(0);
        return $courses;
    }

    private function holding(?string $modeConfig): CourseMapper {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setModeConfig($modeConfig);
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);
        $courses->method('update')->willReturnArgument(0);
        return $courses;
    }

    // ── Creating a course ────────────────────────────────────────────────────

    public function testMandatoryProfileSwitchesTheExtrasOffAndPreparesTheCertification(): void {
        foreach ([true, false] as $gamification) {
            $course = $this->service($this->inserting(), $gamification)->create('Brandschutz', null, null, 'teacher', 'mandatory');
            $config = json_decode((string)$course->getModeConfig(), true);
            $this->assertSame([
                'training' => true,
                'leitner' => false,
                'exam' => true,
                'duel' => false,
                'gameshow' => false,
                'league' => false,
                'abenteuer' => false,
                'course_summary' => false,
                'tools' => false,
                'classbook' => false,
                'buddies' => false,
                'linkedin' => false,
                'credential_download' => true,
            ], $config, 'gamification ' . ($gamification ? 'on' : 'off'));
            $this->assertTrue($course->getCertEnabled(), 'the certification is switched on');
            // GitHub #75: every renewal asks for the required documents again
            $this->assertTrue($course->getReconfirmPerCycle(), 'required documents are confirmed per cycle');
            // GitHub #87: three counting attempts per pool, then the instructor decides
            $this->assertSame(3, $course->getExamAttemptLimit());
            // GitHub #86: required documents are confirmed only after they were opened
            $this->assertTrue($course->getOpenBeforeConfirm());
            // GitHub #89: "Exam only after all required content is completed" is on
            $this->assertTrue($course->getVideoGateEnabled(), 'required content holds the exam back');
            $this->assertNull($course->getEnabledTools(), 'the per-tool selection stays the default, only the master switch is off');
            $this->assertNull($course->getCertRequiredPoolIds());
        }
    }

    public function testItProfileAndNoProfileKeepTodaysDefaults(): void {
        foreach (['it', null] as $profile) {
            $on = $this->service($this->inserting(), true)->create('Netzwerk', null, null, 'teacher', $profile);
            $this->assertNull($on->getModeConfig(), 'gamification on: empty mode config = the defaults');
            $this->assertNotTrue($on->getCertEnabled());
            $this->assertNotTrue($on->getReconfirmPerCycle(), 'an IT course keeps one confirmation');
            $this->assertNull($on->getExamAttemptLimit(), 'an IT course keeps unlimited attempts');
            $this->assertNotTrue($on->getOpenBeforeConfirm(), 'an IT course confirms without opening');
            $this->assertNotTrue($on->getVideoGateEnabled(), 'an IT course keeps the exam open (GitHub #89)');

            $off = json_decode((string)$this->service($this->inserting(), false)->create('Netzwerk', null, null, 'teacher', $profile)->getModeConfig(), true);
            $this->assertFalse($off['duel']);
            $this->assertTrue($off['leitner']);
            $this->assertTrue($off['tools']);
            $this->assertTrue($off['classbook']);
            $this->assertTrue($off['buddies']);
            $this->assertTrue($off['linkedin']);
            $this->assertTrue($off['credential_download']);
        }
    }

    public function testAnUnknownProfileIsRefusedBeforeAnythingIsSaved(): void {
        $courses = $this->createMock(CourseMapper::class);
        $courses->expects($this->never())->method('insert');
        $this->expectException(\InvalidArgumentException::class);
        $this->service($courses)->create('Kurs', null, null, 'teacher', 'compliance');
    }

    public function testTheControllerAnswers400ForAnUnknownProfileAndPassesAKnownOne(): void {
        $service = $this->createMock(CourseService::class);
        $created = new Course();
        $created->setId(9);
        $created->setTitle('Brandschutz');
        $service->expects($this->once())->method('create')
            ->with('Brandschutz', null, null, 'teacher', 'mandatory')
            ->willReturn($created);
        $controller = new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(CourseArchiveService::class),
            $service,
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            'teacher'
        );

        $bad = $controller->create('Brandschutz', null, null, 'compliance');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());
        $this->assertSame('unknown_profile', $bad->getData()['code']);

        $this->assertSame(Http::STATUS_CREATED, $controller->create('Brandschutz', null, null, 'mandatory')->getStatus());
    }

    // ── The LinkedIn switch ──────────────────────────────────────────────────

    public function testTheLinkedInSwitchIsStoredAndNotTurnedBackOnByALaterSave(): void {
        $courses = $this->holding(null);
        $service = $this->service($courses);

        $off = $service->updateModeConfig(7, 'teacher', ['linkedin' => false]);
        $this->assertFalse($off['linkedin'], 'a switched-off LinkedIn button is not dropped on save');

        $this->assertTrue($service->updateModeConfig(7, 'teacher', [])['linkedin'], 'a course saved without the key keeps the button');
    }

    public function testIsLinkedInShareEnabledReadsTheCourseSwitch(): void {
        $this->assertFalse($this->service($this->holding('{"linkedin":false}'))->isLinkedInShareEnabled(7));
        $this->assertTrue($this->service($this->holding('{"linkedin":true}'))->isLinkedInShareEnabled(7));
        $this->assertTrue($this->service($this->holding(null))->isLinkedInShareEnabled(7), 'a course from before the switch keeps the button');

        $gone = $this->createMock(CourseMapper::class);
        $gone->method('findById')->willThrowException(new DoesNotExistException('gone'));
        $this->assertTrue($this->service($gone)->isLinkedInShareEnabled(7), 'a deleted course keeps the old behaviour');
    }

    public function testTheLearnersCertificateSaysWhetherLinkedInIsOffered(): void {
        foreach ([true, false] as $allowed) {
            $cert = new Certificate();
            $cert->setVerificationId('11111111-1111-4111-8111-111111111111');
            $cert->setUserId('alice');
            $cert->setCourseId(7);
            $cert->setKeyId('key-1');
            $cert->setCredentialJson('h.p.s');
            $cert->setRevoked(false);
            $cert->setIssuedAt(1700000000);
            $mapper = $this->createMock(CertificateMapper::class);
            $mapper->method('findByVerificationIdAndUserId')->willReturn($cert);
            $courses = $this->createMock(CourseService::class);
            $courses->expects($this->once())->method('isLinkedInShareEnabled')->with(7)->willReturn($allowed);

            $controller = new CertificateController(
                'learning',
                $this->createMock(IRequest::class),
                $mapper,
                $courses,
                $this->createMock(ITimeFactory::class),
                'alice',
                $this->createMock(AuditService::class),
                new FakeDbConnection(),
                $this->createMock(CertLayoutService::class)
            );
            $data = $controller->show('11111111-1111-4111-8111-111111111111')->getData();
            $this->assertSame($allowed, $data['linkedin_allowed']);
        }
    }
    // ── GitHub #95: the credential (JSON) switch ─────────────────────────────

    public function testTheCredentialSwitchIsStoredAndNotTurnedBackOnByALaterSave(): void {
        $service = $this->service($this->holding(null));

        $off = $service->updateModeConfig(7, 'teacher', ['credential_download' => false, 'linkedin' => true]);
        $this->assertFalse($off['credential_download'], 'the allowlist keeps the switch');
        $this->assertTrue($off['linkedin']);

        $this->assertTrue($service->updateModeConfig(7, 'teacher', [])['credential_download'], 'a course saved without the key keeps the section');
        $this->assertTrue($service->updateModeConfig(7, 'teacher', ['credential_download' => 1])['credential_download'], 'stored as a bool');
    }

    public function testIsCredentialDownloadEnabledReadsTheCourseSwitch(): void {
        $this->assertFalse($this->service($this->holding('{"credential_download":false}'))->isCredentialDownloadEnabled(7));
        $this->assertTrue($this->service($this->holding('{"credential_download":true}'))->isCredentialDownloadEnabled(7));
        $this->assertTrue($this->service($this->holding('{"linkedin":false}'))->isCredentialDownloadEnabled(7), 'a course saved before the switch existed shows the section');
        $this->assertTrue($this->service($this->holding(null))->isCredentialDownloadEnabled(7));
        $this->assertTrue($this->service($this->holding('not json'))->isCredentialDownloadEnabled(7), 'an unreadable config shows the section');
        $this->assertTrue($this->service($this->holding('{"credential_download":false}'))->isLinkedInShareEnabled(7), 'the two switches are independent');

        $gone = $this->createMock(CourseMapper::class);
        $gone->method('findById')->willThrowException(new DoesNotExistException('gone'));
        $this->assertTrue($this->service($gone)->isCredentialDownloadEnabled(7), 'a deleted course keeps the old behaviour');
    }

    public function testTheLearnersCertificateSaysWhetherTheCredentialSectionIsShown(): void {
        foreach ([true, false] as $allowed) {
            $cert = new Certificate();
            $cert->setVerificationId('11111111-1111-4111-8111-111111111111');
            $cert->setUserId('alice');
            $cert->setCourseId(7);
            $cert->setKeyId('key-1');
            $cert->setCredentialJson('h.p.s');
            $cert->setRevoked(false);
            $cert->setIssuedAt(1700000000);
            $mapper = $this->createMock(CertificateMapper::class);
            $mapper->method('findByVerificationIdAndUserId')->willReturn($cert);
            $courses = $this->createMock(CourseService::class);
            $courses->method('isLinkedInShareEnabled')->willReturn(true);
            $courses->expects($this->once())->method('isCredentialDownloadEnabled')->with(7)->willReturn($allowed);

            $controller = new CertificateController(
                'learning',
                $this->createMock(IRequest::class),
                $mapper,
                $courses,
                $this->createMock(ITimeFactory::class),
                'alice',
                $this->createMock(AuditService::class),
                new FakeDbConnection(),
                $this->createMock(CertLayoutService::class)
            );
            $data = $controller->show('11111111-1111-4111-8111-111111111111')->getData();
            $this->assertSame($allowed, $data['credential_download_allowed']);
            $this->assertTrue($data['linkedin_allowed'], 'the LinkedIn switch is read on its own');
            $this->assertSame('h.p.s', $data['credential_json'], 'the credential itself still reaches the card');
        }
    }
}
