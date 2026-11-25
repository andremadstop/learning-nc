<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\MaterialShareService;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #110 — the read share of the material folder and the Text viewer URL.
 *
 * The share is what makes /f/{fileid} resolve for a member: it lives in the owner's home and
 * the viewer looks the file up in the member's own folder. It is read-only (Text derives its
 * read-only mode from the missing write permission), unmarked shares of the instructor are
 * never touched by a revoke, and every failure means "no redirect", never "no material".
 */
#[AllowMockObjectsWithoutExpectations]
class MaterialShareServiceTest extends TestCase {
    private const COURSE = 7;
    private const OWNER = 'teacher';
    private const LEARNER = 'anna';
    private const NOTE = 'learning:course:7';

    /** @var IRootFolder&MockObject */
    private $rootFolder;
    /** @var CourseMapper&MockObject */
    private $courseMapper;
    /** @var IShareManager&MockObject */
    private $shareManager;
    /** @var IAppManager&MockObject */
    private $appManager;
    /** @var IUserManager&MockObject */
    private $userManager;
    /** @var IURLGenerator&MockObject */
    private $urlGenerator;
    /** @var LoggerInterface&MockObject */
    private $logger;
    private Course $course;
    /** @var Folder&MockObject the linked material folder */
    private $folder;
    /** @var list<IShare&MockObject> shares returned for the folder */
    private array $shares = [];
    /** @var array<string, mixed> last value passed to each setter of the new share */
    private array $shareCalls = [];

    protected function setUp(): void {
        $this->course = new Course();
        $this->course->setId(self::COURSE);
        $this->course->setInstructorId(self::OWNER);
        $this->course->setMaterialFolder('/Kurs/Material');

        $this->folder = $this->createMock(Folder::class);
        $home = $this->createMock(Folder::class);
        $home->method('get')->willReturnCallback(function (string $path) {
            if ($path === '/Kurs/Material') {
                return $this->folder;
            }
            if ($path === '/Alt') {
                return $this->createMock(Folder::class);
            }
            throw new NotFoundException($path);
        });
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid) use ($home) {
            if ($uid !== self::OWNER) {
                throw new \RuntimeException('only the owner has the folder');
            }
            return $home;
        });

        $this->shareManager = $this->createMock(IShareManager::class);
        $this->shareManager->method('getSharesBy')->willReturnCallback(
            fn () => $this->shares);
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $this->courseMapper->method('findById')->willReturnCallback(fn (int $id) => $this->course);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function service(): MaterialShareService {
        return new MaterialShareService($this->courseMapper, $this->rootFolder, $this->shareManager,
            $this->appManager, $this->userManager, $this->urlGenerator, $this->logger);
    }

    /**
     * A new share: every setter records its value (asserted by the caller) and returns the
     * share, because setNode() is declared ": self".
     */
    private function newShare(): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        foreach (['setNode', 'setShareType', 'setSharedWith', 'setPermissions', 'setSharedBy', 'setNote'] as $setter) {
            $share->method($setter)->willReturnCallback(function ($value) use ($setter, $share) {
                $this->shareCalls[$setter] = $value;
                return $share;
            });
        }
        $this->shareManager->method('newShare')->willReturn($share);
        return $share;
    }

    private function share(string $sharedWith, string $note): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getSharedWith')->willReturn($sharedWith);
        $share->method('getNote')->willReturn($note);
        return $share;
    }

    private function memberWithTextApp(bool $enabled = true): void {
        $user = $this->createMock(\OCP\IUser::class);
        $this->userManager->method('get')->with(self::LEARNER)->willReturn($user);
        $this->appManager->method('isEnabledForUser')->with('text', $user)->willReturn($enabled);
    }

    // ---------------------------------------------------------------- ensureLearnerAccess ---

    /** The lazy share: read-only, by the owner, with the course's marker note. */
    public function testAMemberGetsAReadShareMarkedWithTheCourseNote(): void {
        $share = $this->newShare();
        $this->shareManager->expects($this->once())->method('createShare')->with($share)->willReturn($share);

        $this->assertTrue($this->service()->ensureLearnerAccess($this->course, self::LEARNER));
        $this->assertSame($this->folder, $this->shareCalls['setNode'] ?? null);
        $this->assertSame(IShare::TYPE_USER, $this->shareCalls['setShareType'] ?? null);
        $this->assertSame(self::LEARNER, $this->shareCalls['setSharedWith'] ?? null);
        $this->assertSame(Constants::PERMISSION_READ, $this->shareCalls['setPermissions'] ?? null);
        $this->assertSame(self::OWNER, $this->shareCalls['setSharedBy'] ?? null);
        $this->assertSame(self::NOTE, $this->shareCalls['setNote'] ?? null);
    }

    /** Already shared with this member: access is what we wanted, so that is success. */
    public function testAnExistingShareIsSuccess(): void {
        $this->newShare();
        $this->shareManager->method('createShare')->willThrowException(
            new AlreadySharedException('x', $this->createMock(IShare::class)));

        $this->assertTrue($this->service()->ensureLearnerAccess($this->course, self::LEARNER));
    }

    /** The owner of the folder is never asked to share it with themselves. */
    public function testTheOwnerNeedsNoShare(): void {
        $this->shareManager->expects($this->never())->method('newShare');

        $this->assertTrue($this->service()->ensureLearnerAccess($this->course, self::OWNER));
    }

    /** No linked folder (or a folder that is gone): no share, no access claim. */
    public function testNoMaterialFolderMeansNoAccess(): void {
        $this->course->setMaterialFolder(null);
        $this->shareManager->expects($this->never())->method('newShare');

        $this->assertFalse($this->service()->ensureLearnerAccess($this->course, self::LEARNER));
    }

    /** Sharing refused (link shares off, quota, …): the caller falls back to plain text. */
    public function testSharingRefusedIsNoAccess(): void {
        $this->newShare();
        $this->shareManager->method('createShare')->willThrowException(new GenericShareException('nope'));

        $this->assertFalse($this->service()->ensureLearnerAccess($this->course, self::LEARNER));
    }

    // ---------------------------------------------------------------- textViewUrl ------------

    private function file(int $id = 77): File&MockObject {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        return $file;
    }

    /** The URL of the file page: the viewer opens this exact file, read-only for a member. */
    public function testTextViewUrlIsTheFilePageOfThatFile(): void {
        $this->memberWithTextApp();
        $this->newShare();
        $this->shareManager->method('createShare')->willReturnArgument(0);
        $this->urlGenerator->expects($this->once())->method('linkToRoute')
            ->with(MaterialShareService::VIEWER_ROUTE, ['fileid' => '77'])
            ->willReturn('/f/77');

        $this->assertSame('/f/77', $this->service()->textViewUrl($this->course, self::LEARNER, $this->file()));
    }

    /** Without the Text app for this user there is no viewer and no share is created. */
    public function testTextViewUrlWithoutTheTextAppIsNullAndCreatesNoShare(): void {
        $this->memberWithTextApp(false);
        $this->shareManager->expects($this->never())->method('newShare');
        $this->urlGenerator->expects($this->never())->method('linkToRoute');

        $this->assertNull($this->service()->textViewUrl($this->course, self::LEARNER, $this->file()));
    }

    /** An unknown account gets no viewer URL. */
    public function testTextViewUrlWithoutAUserIsNull(): void {
        $this->userManager->method('get')->with(self::LEARNER)->willReturn(null);
        $this->shareManager->expects($this->never())->method('newShare');

        $this->assertNull($this->service()->textViewUrl($this->course, self::LEARNER, $this->file()));
    }

    /** A storage without a file id cannot be addressed by /f/{fileid}. */
    public function testTextViewUrlWithoutAFileIdIsNullBeforeAnyShare(): void {
        $this->userManager->expects($this->never())->method('get');
        $this->shareManager->expects($this->never())->method('newShare');

        $this->assertNull($this->service()->textViewUrl($this->course, self::LEARNER, $this->file(0)));
    }

    /** Refused sharing means no viewer URL: download() then serves the plain text response. */
    public function testTextViewUrlIsNullWhenTheShareIsRefused(): void {
        $this->memberWithTextApp();
        $this->newShare();
        $this->shareManager->method('createShare')->willThrowException(new GenericShareException('nope'));
        $this->urlGenerator->expects($this->never())->method('linkToRoute');

        $this->assertNull($this->service()->textViewUrl($this->course, self::LEARNER, $this->file()));
    }

    /** The owner sees the file page without any share. */
    public function testTextViewUrlForTheOwnerNeedsNoShare(): void {
        $user = $this->createMock(\OCP\IUser::class);
        $this->userManager->method('get')->with(self::OWNER)->willReturn($user);
        $this->appManager->method('isEnabledForUser')->with('text', $user)->willReturn(true);
        $this->shareManager->expects($this->never())->method('newShare');
        $this->urlGenerator->method('linkToRoute')->willReturn('/f/77');

        $this->assertSame('/f/77', $this->service()->textViewUrl($this->course, self::OWNER, $this->file()));
    }

    // ---------------------------------------------------------------- revoke -----------------

    /** Only this course's shares of that folder leave with the membership. */
    public function testRevokeDeletesOnlyThisLearnersMarkedShare(): void {
        $ours = $this->share(self::LEARNER, self::NOTE);
        $otherLearner = $this->share('berta', self::NOTE);
        $byHand = $this->share(self::LEARNER, '');
        $otherCourse = $this->share(self::LEARNER, 'learning:course:9');
        $this->shares = [$ours, $otherLearner, $byHand, $otherCourse];
        $this->shareManager->expects($this->once())->method('deleteShare')->with($ours);

        $this->service()->revokeLearnerAccess($this->course, self::LEARNER);
    }

    /** The owner never had a share of their own folder. */
    public function testRevokeForTheOwnerDeletesNothing(): void {
        $this->shares = [$this->share(self::OWNER, self::NOTE)];
        $this->shareManager->expects($this->never())->method('deleteShare');

        $this->service()->revokeLearnerAccess($this->course, self::OWNER);
    }

    /** A revoked learner whose share is gone already: nothing to delete, no error. */
    public function testRevokeWithoutAnyShareIsQuiet(): void {
        $this->shares = [];
        $this->shareManager->expects($this->never())->method('deleteShare');

        $this->service()->revokeLearnerAccess($this->course, self::LEARNER);
    }

    /** Relinking the folder: the old folder's marked shares go, the instructor's own stay. */
    public function testRevokeCourseSharesOfTheOldFolderOnlyDeletesMarkedOnes(): void {
        $ours1 = $this->share(self::LEARNER, self::NOTE);
        $ours2 = $this->share('berta', self::NOTE);
        $byHand = $this->share(self::LEARNER, '');
        $this->shares = [$ours1, $ours2, $byHand];
        $deleted = [];
        $this->shareManager->expects($this->exactly(2))->method('deleteShare')
            ->willReturnCallback(function (IShare $share) use (&$deleted): void {
                $deleted[] = $share;
            });

        $this->service()->revokeCourseShares(self::COURSE, '/Alt');

        $this->assertSame([$ours1, $ours2], $deleted);
    }

    /** A folder that is gone takes no shares with it (there are none to take). */
    public function testRevokeCourseSharesOfAGoneFolderIsQuiet(): void {
        $this->shareManager->expects($this->never())->method('getSharesBy');

        $this->service()->revokeCourseShares(self::COURSE, '/Weg');
    }

    /** The home itself was never a material folder, so it never carries shares of a course. */
    public function testRevokeCourseSharesOfTheHomeIsNotAttempted(): void {
        $this->shareManager->expects($this->never())->method('getSharesBy');

        $this->service()->revokeCourseShares(self::COURSE, '/');
    }
}
