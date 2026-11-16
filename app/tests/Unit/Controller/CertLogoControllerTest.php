<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertLayoutController;
use OCA\Learning\Controller\CertLogoController;
use OCA\Learning\Db\Course;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CertLogoLibrary;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Tests\Unit\Service\CertLogoLibraryTest;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #78: the logo library is the administrators'; a course's instructors may list the logos,
 * see them and pick one, nobody else. Uploads are checked by content: an SVG or a file over the
 * limit never reaches the library.
 */
#[AllowMockObjectsWithoutExpectations]
class CertLogoControllerTest extends TestCase {
    private const ID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    /** @param array<string, mixed>|null $upload what getUploadedFile('logo') returns */
    private function controller(bool $admin, CertLogoLibrary $library, ?CertLayoutService $layouts = null, ?array $upload = null): CertLogoController {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->willReturnCallback(fn(string $key) => $key === 'logo' ? $upload : null);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn($admin);
        return new CertLogoController(
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
        $file = (string)tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($file, $bytes);
        $this->tmpFiles[] = $file;
        return ['name' => 'logo.png', 'tmp_name' => $file, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK, 'type' => $claimedType];
    }

    /** A library whose add() runs the real content check. */
    private function checkingLibrary(): CertLogoLibrary {
        $library = $this->createMock(CertLogoLibrary::class);
        $library->method('add')->willReturnCallback(fn(string $name, string $bytes): array => [
            'id' => hash('sha256', $bytes),
            'name' => $name,
            'mime' => CertLogoLibrary::detectImage($bytes),
            'size' => strlen($bytes),
            'created_at' => 1,
        ]);
        return $library;
    }

    public function testOnlyAdministratorsTouchTheLibrary(): void {
        $library = $this->createMock(CertLogoLibrary::class);
        $library->expects($this->never())->method('add');
        $library->expects($this->never())->method('rename');
        $library->expects($this->never())->method('delete');
        $library->expects($this->never())->method('content');
        $library->expects($this->never())->method('list');
        $controller = $this->controller(false, $library, null, $this->upload((string)base64_decode(CertLogoLibraryTest::PNG)));

        $this->assertSame(403, $controller->index()->getStatus());
        $this->assertSame(403, $controller->upload('Logo')->getStatus());
        $this->assertSame(403, $controller->rename(self::ID, 'x')->getStatus());
        $this->assertSame(403, $controller->delete(self::ID)->getStatus());
        $this->assertSame(403, $controller->image(self::ID)->getStatus());
    }

    public function testAnSvgIsRefusedWhateverTheBrowserClaims(): void {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>';
        $response = $this->controller(true, $this->checkingLibrary(), null, $this->upload($svg, 'image/png'))->upload('Logo');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(['error' => 'unsupported_type'], $response->getData());
    }

    public function testAFileOverTheLimitIsRefusedBeforeItIsRead(): void {
        $library = $this->createMock(CertLogoLibrary::class);
        $library->expects($this->never())->method('add');
        $upload = $this->upload('x');
        $upload['size'] = CertLogoLibrary::MAX_BYTES + 1;
        $response = $this->controller(true, $library, null, $upload)->upload('Logo');
        $this->assertSame(['error' => 'too_large'], $response->getData());

        $response = $this->controller(true, $library, null, ['error' => UPLOAD_ERR_INI_SIZE])->upload('Logo');
        $this->assertSame(['error' => 'too_large'], $response->getData());

        $response = $this->controller(true, $library, null, null)->upload('Logo');
        $this->assertSame(['error' => 'no_file'], $response->getData());
    }

    public function testAPngIsAdded(): void {
        $png = (string)base64_decode(CertLogoLibraryTest::PNG);
        $response = $this->controller(true, $this->checkingLibrary(), null, $this->upload($png))->upload('Primary');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(hash('sha256', $png), $response->getData()['added']);
    }

    public function testDeletingAUsedLogoResetsItsCourses(): void {
        $library = $this->createMock(CertLogoLibrary::class);
        $library->expects($this->once())->method('delete')->with(self::ID);
        $library->method('list')->willReturn([]);
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->once())->method('resetLogo')->with(self::ID)->willReturn(3);

        $response = $this->controller(true, $library, $layouts)->delete(self::ID);

        $this->assertSame(3, $response->getData()['courses_reset']);
    }

    public function testTheImageIsServedWithItsCheckedTypeAndNoSniffing(): void {
        $library = $this->createMock(CertLogoLibrary::class);
        $library->method('content')->willReturn(['mime' => 'image/webp', 'bytes' => 'RIFF....WEBP']);

        $response = $this->controller(true, $library)->image(self::ID);

        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        $this->assertSame('image/webp', $response->getHeaders()['Content-Type']);
        $this->assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
        $this->assertSame(404, $this->controller(true, $library)->image('../secret')->getStatus());
    }

    // ---- the course side ------------------------------------------------------------------

    private function courseController(bool $instructor, CertLogoLibrary $library, ?CertLayoutService $layouts = null): CertLayoutController {
        $courses = $this->createMock(CourseService::class);
        if ($instructor) {
            $course = new Course();
            $course->setId(7);
            $courses->method('assertInstructorOfCourse')->willReturn($course);
        } else {
            $courses->method('assertInstructorOfCourse')->willThrowException(new ForbiddenException('No permission'));
        }
        return new CertLayoutController(
            'learning',
            $this->createMock(IRequest::class),
            $courses,
            $layouts ?? $this->createMock(CertLayoutService::class),
            $this->createMock(LoggerInterface::class),
            'teacher',
            $library,
        );
    }

    public function testOnlyTheCoursesInstructorsSeeAndPickLibraryLogos(): void {
        $library = $this->createMock(CertLogoLibrary::class);
        $library->expects($this->never())->method('list');
        $library->expects($this->never())->method('content');
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->never())->method('selectLogo');
        $controller = $this->courseController(false, $library, $layouts);

        $this->assertSame(403, $controller->logos(7)->getStatus());
        $this->assertSame(403, $controller->logoImage(7, self::ID)->getStatus());
        $this->assertSame(403, $controller->selectLogo(7, self::ID)->getStatus());
    }

    public function testInstructorsListLogosWithoutTheirBytes(): void {
        $library = $this->createMock(CertLogoLibrary::class);
        $library->method('list')->willReturn([['id' => self::ID, 'name' => 'Primary', 'mime' => 'image/png', 'size' => 9, 'created_at' => 1]]);
        $library->method('content')->willReturn(['mime' => 'image/png', 'bytes' => 'PNG']);
        $controller = $this->courseController(true, $library);

        $this->assertSame(['logos' => [['id' => self::ID, 'name' => 'Primary']], 'selected' => null], $controller->logos(7)->getData());
        $this->assertInstanceOf(DataDisplayResponse::class, $controller->logoImage(7, self::ID));
    }

    public function testPickingAnUnknownLogoIsABadRequest(): void {
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->method('selectLogo')->willThrowException(new \InvalidArgumentException('logo_not_found'));

        $response = $this->courseController(true, $this->createMock(CertLogoLibrary::class), $layouts)->selectLogo(7, self::ID);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(['error' => 'logo_not_found'], $response->getData());
    }
}
