<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertBackgroundController;
use OCA\Learning\Controller\CertLayoutController;
use OCA\Learning\Db\Course;
use OCA\Learning\Service\CertBackgroundLibrary;
use OCA\Learning\Service\CertLayout;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CertLogoLibrary;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Tests\Unit\Service\CertLogoLibraryTest;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #93: the background library is the administrators'; a course's instructors may list the
 * backgrounds, see them and pick one with its strength, nobody else. Uploads are checked by content.
 */
#[AllowMockObjectsWithoutExpectations]
class CertBackgroundControllerTest extends TestCase {
    private const ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    /** @param array<string, mixed>|null $upload what getUploadedFile('background') returns */
    private function controller(bool $admin, CertBackgroundLibrary $library, ?CertLayoutService $layouts = null, ?array $upload = null): CertBackgroundController {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->willReturnCallback(fn(string $key) => $key === 'background' ? $upload : null);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new CertBackgroundController(
            'learning',
            $request,
            $library,
            $layouts ?? $this->createMock(CertLayoutService::class),
            $groups,
            $this->createMock(LoggerInterface::class),
            'someone',
        );
    }

    /** @return array{name: string, tmp_name: string, size: int, error: int, type: string} */
    private function upload(string $bytes, string $claimedType = 'image/png'): array {
        $file = (string)tempnam(sys_get_temp_dir(), 'bg');
        file_put_contents($file, $bytes);
        $this->tmpFiles[] = $file;
        return ['name' => 'background.png', 'tmp_name' => $file, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK, 'type' => $claimedType];
    }

    /** A library whose add() runs the real content check. */
    private function checkingLibrary(): CertBackgroundLibrary {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->method('add')->willReturnCallback(fn(string $name, string $bytes): array => [
            'id' => hash('sha256', $bytes),
            'name' => $name,
            'mime' => CertBackgroundLibrary::detectImage($bytes),
            'size' => strlen($bytes),
            'created_at' => 1,
        ]);
        return $library;
    }

    public function testOnlyAdministratorsTouchTheLibrary(): void {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->expects($this->never())->method('add');
        $library->expects($this->never())->method('rename');
        $library->expects($this->never())->method('delete');
        $library->expects($this->never())->method('content');
        $library->expects($this->never())->method('list');
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->never())->method('resetBackground');
        $controller = $this->controller(false, $library, $layouts, $this->upload((string)base64_decode(CertLogoLibraryTest::PNG)));

        $this->assertSame(403, $controller->index()->getStatus());
        $this->assertSame(403, $controller->upload('Frame')->getStatus());
        $this->assertSame(403, $controller->rename(self::ID, 'x')->getStatus());
        $this->assertSame(403, $controller->delete(self::ID)->getStatus());
        $this->assertSame(403, $controller->image(self::ID)->getStatus());
    }

    public function testAnSvgIsRefusedWhateverTheBrowserClaims(): void {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>';
        $response = $this->controller(true, $this->checkingLibrary(), null, $this->upload($svg, 'image/png'))->upload('Frame');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(['error' => 'unsupported_type'], $response->getData());
    }

    public function testAFileOverTheLimitIsRefusedBeforeItIsRead(): void {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->expects($this->never())->method('add');
        $upload = $this->upload('x');
        $upload['size'] = CertBackgroundLibrary::MAX_BYTES + 1;
        $this->assertSame(['error' => 'too_large'], $this->controller(true, $library, null, $upload)->upload('Frame')->getData());
        $this->assertSame(['error' => 'too_large'], $this->controller(true, $library, null, ['error' => UPLOAD_ERR_INI_SIZE])->upload('Frame')->getData());
        $this->assertSame(['error' => 'no_file'], $this->controller(true, $library, null, null)->upload('Frame')->getData());
    }

    public function testTheLimitIsTwoMegabytesNotTheLogoLimit(): void {
        $library = $this->checkingLibrary();
        $upload = $this->upload((string)base64_decode(CertLogoLibraryTest::PNG));
        $upload['size'] = CertLogoLibrary::MAX_BYTES + 1;   // over the logo limit, under the background limit
        $response = $this->controller(true, $library, null, $upload)->upload('Frame');
        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('added', $response->getData());
    }

    public function testTheListNamesTheCoursesThatUseEachBackground(): void {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->method('list')->willReturn([['id' => self::ID, 'name' => 'Gold frame', 'mime' => 'image/jpeg', 'size' => 9, 'created_at' => 1]]);
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->method('countCoursesUsingBackground')->willReturnCallback(fn(string $id): int => $id === self::ID ? 2 : 0);

        $this->assertSame([
            'backgrounds' => [['id' => self::ID, 'name' => 'Gold frame', 'mime' => 'image/jpeg', 'size' => 9, 'courses' => 2]],
            'max' => CertBackgroundLibrary::MAX_BACKGROUNDS,
        ], $this->controller(true, $library, $layouts)->index()->getData());
    }

    public function testDeletingAUsedBackgroundResetsItsCourses(): void {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->expects($this->once())->method('delete')->with(self::ID);
        $library->method('list')->willReturn([]);
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->once())->method('resetBackground')->with(self::ID)->willReturn(3);
        $layouts->expects($this->never())->method('resetLogo');

        $this->assertSame(3, $this->controller(true, $library, $layouts)->delete(self::ID)->getData()['courses_reset']);
    }

    public function testTheImageIsServedWithItsCheckedTypeAndNoSniffing(): void {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->method('content')->willReturn(['mime' => 'image/jpeg', 'bytes' => "\xFF\xD8\xFF"]);

        $response = $this->controller(true, $library)->image(self::ID);

        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        $this->assertSame('image/jpeg', $response->getHeaders()['Content-Type']);
        $this->assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
        $this->assertSame(404, $this->controller(true, $library)->image('../secret')->getStatus());
    }

    // ---- the course side ------------------------------------------------------------------

    private function courseController(?bool $instructor, CertBackgroundLibrary $library, ?CertLayoutService $layouts = null, ?string $layout = null): CertLayoutController {
        $courses = $this->createMock(CourseService::class);
        if ($instructor === true) {
            $course = new Course();
            $course->setId(7);
            $course->setCertLayout($layout);
            $courses->method('assertInstructorOfCourse')->willReturn($course);
        } elseif ($instructor === false) {
            $courses->method('assertInstructorOfCourse')->willThrowException(new ForbiddenException('No permission'));
        } else {
            $courses->method('assertInstructorOfCourse')->willThrowException(new DoesNotExistException('no course'));
        }
        return new CertLayoutController(
            'learning',
            $this->createMock(IRequest::class),
            $courses,
            $layouts ?? $this->createMock(CertLayoutService::class),
            $this->createMock(LoggerInterface::class),
            'teacher',
            $this->createMock(CertLogoLibrary::class),
            $library,
        );
    }

    public function testOnlyTheCoursesInstructorsSeeAndPickBackgrounds(): void {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->expects($this->never())->method('list');
        $library->expects($this->never())->method('content');
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->never())->method('selectBackground');

        $controller = $this->courseController(false, $library, $layouts);
        $this->assertSame(403, $controller->backgrounds(7)->getStatus());
        $this->assertSame(403, $controller->backgroundImage(7, self::ID)->getStatus());
        $this->assertSame(403, $controller->selectBackground(7, self::ID, 100)->getStatus());

        $missing = $this->courseController(null, $library, $layouts);
        $this->assertSame(404, $missing->backgrounds(99)->getStatus());
        $this->assertSame(404, $missing->backgroundImage(99, self::ID)->getStatus());
        $this->assertSame(404, $missing->selectBackground(99, self::ID, 100)->getStatus());
    }

    public function testInstructorsListBackgroundsWithoutTheirBytes(): void {
        $library = $this->createMock(CertBackgroundLibrary::class);
        $library->method('list')->willReturn([['id' => self::ID, 'name' => 'Gold frame', 'mime' => 'image/png', 'size' => 9, 'created_at' => 1]]);
        $library->method('content')->willReturnCallback(fn(string $id): array => $id === self::ID
            ? ['mime' => 'image/png', 'bytes' => 'PNG']
            : throw new \OCP\Files\NotFoundException('no such background'));
        $layout = CertLayout::defaults();
        $layout['background_builtin'] = 'corners';
        $layout['background_intensity'] = 60;
        $controller = $this->courseController(true, $library, null, CertLayout::encode($layout));

        $this->assertSame([
            'backgrounds' => [['id' => self::ID, 'name' => 'Gold frame']],
            'builtins' => ['frame', 'corners', 'lines'],
            'selected' => 'builtin:corners',
            'intensity' => 60,
        ], $controller->backgrounds(7)->getData());
        $this->assertInstanceOf(DataDisplayResponse::class, $controller->backgroundImage(7, self::ID));
        $this->assertSame(404, $controller->backgroundImage(7, str_repeat('f', 64))->getStatus());
        $this->assertSame(404, $controller->backgroundImage(7, '../secret')->getStatus());

        $none = $this->courseController(true, $library, null, null);
        $this->assertSame('', $none->backgrounds(7)->getData()['selected']);
        $this->assertSame(100, $none->backgrounds(7)->getData()['intensity']);
    }

    public function testPickingAnUnknownBackgroundOrStrengthIsABadRequest(): void {
        foreach (['background_not_found', 'invalid_intensity'] as $code) {
            $layouts = $this->createMock(CertLayoutService::class);
            $layouts->method('selectBackground')->willThrowException(new \InvalidArgumentException($code));

            $response = $this->courseController(true, $this->createMock(CertBackgroundLibrary::class), $layouts)->selectBackground(7, self::ID, 100);

            $this->assertSame(400, $response->getStatus());
            $this->assertSame(['error' => $code], $response->getData());
        }
    }

    public function testPickingABackgroundAnswersWithThePreview(): void {
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->once())->method('selectBackground')
            ->with($this->isInstanceOf(Course::class), 'builtin:frame', 50)
            ->willReturnArgument(0);
        $layouts->method('presentationForCourse')->willReturn(['background_builtin' => 'frame', 'background_intensity' => 50]);

        $response = $this->courseController(true, $this->createMock(CertBackgroundLibrary::class), $layouts)->selectBackground(7, 'builtin:frame', 50);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['background_builtin' => 'frame', 'background_intensity' => 50], $response->getData());
    }
}
