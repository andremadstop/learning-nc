<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertBackgroundLibrary;
use OCA\Learning\Service\CertLayout;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CertLogoLibrary;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

/**
 * GitHub #43: the course's certificate layout preview and its stamp / signature images.
 *
 * Every action is for the course's instructors only (CourseService::assertInstructorOfCourse:
 * creator or co-instructor of THIS course). The text, the element switches and the signer are saved
 * with the other certification settings (PATCH cert-config, CourseController::updateCertConfig).
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CertLayoutController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CourseService $courseService,
        private CertLayoutService $layoutService,
        private LoggerInterface $logger,
        private ?string $userId,
        private CertLogoLibrary $logoLibrary,
        private CertBackgroundLibrary $backgroundLibrary,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * The course's current layout with its images, for the preview in the settings.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function show(int $courseId): JSONResponse {
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, (string)$this->userId);
            return new JSONResponse($this->layoutService->presentationForCourse($course));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Take a PNG from the instructor's Nextcloud files as stamp or signature.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function importImage(int $courseId, string $kind, string $path = ''): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        if (!in_array($kind, CertLayout::IMAGE_KINDS, true)) {
            return new JSONResponse(['error' => 'invalid_kind'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, $this->userId);
            $course = $this->layoutService->importImage($course, $kind, $this->userId, $path);
            return new JSONResponse($this->layoutService->presentationForCourse($course));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'file_not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('cert layout image import failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the course's stamp or signature. Certificates already issued keep theirs.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function removeImage(int $courseId, string $kind): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        if (!in_array($kind, CertLayout::IMAGE_KINDS, true)) {
            return new JSONResponse(['error' => 'invalid_kind'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, $this->userId);
            $course = $this->layoutService->removeImage($course, $kind);
            return new JSONResponse($this->layoutService->presentationForCourse($course));
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('cert layout image removal failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GitHub #78: the logos of the library the course can use, and the one it uses.
     *
     * @return DataResponse<Http::STATUS_OK, array{logos: list<array{id: string, name: string}>, selected: string|null}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function logos(int $courseId): DataResponse {
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, (string)$this->userId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        $logos = [];
        foreach ($this->logoLibrary->list() as $entry) {
            $logos[] = ['id' => $entry['id'], 'name' => $entry['name']];
        }
        return new DataResponse([
            'logos' => $logos,
            'selected' => CertLayout::decode($course->getCertLayout())['logo_sha'],
        ]);
    }

    /**
     * GitHub #78: a library logo's image, for the choice in the course settings. Only for the
     * course's instructors; the public verify page never uses this route (it shows the
     * certificate's own copy).
     *
     * @return DataDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 300, period: 60)]
    public function logoImage(int $courseId, string $logoId): DataDisplayResponse|DataResponse {
        try {
            $this->courseService->assertInstructorOfCourse($courseId, (string)$this->userId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        return CertLogoController::imageResponse($this->logoLibrary, $logoId);
    }

    /**
     * GitHub #78: use a library logo on the course's certificates ('' = the Theming logo again).
     * Takes effect at once, like stamp and signature; certificates already issued keep theirs.
     *
     * @param string $logoId the library logo's id, '' for the Theming logo
     * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function selectLogo(int $courseId, string $logoId = ''): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, $this->userId);
            $course = $this->layoutService->selectLogo($course, $logoId === '' ? null : $logoId);
            return new DataResponse($this->layoutService->presentationForCourse($course));
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('cert layout logo selection failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GitHub #93: the backgrounds the course can use (the library's and the built-in ones), the one it
     * uses and its strength.
     *
     * @return DataResponse<Http::STATUS_OK, array{backgrounds: list<array{id: string, name: string}>, builtins: list<string>, selected: string, intensity: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function backgrounds(int $courseId): DataResponse {
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, (string)$this->userId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        $backgrounds = [];
        foreach ($this->backgroundLibrary->list() as $entry) {
            $backgrounds[] = ['id' => $entry['id'], 'name' => $entry['name']];
        }
        $layout = CertLayout::decode($course->getCertLayout());
        $selected = $layout['background_sha'] ?? ($layout['background_builtin'] !== null ? 'builtin:' . $layout['background_builtin'] : '');
        return new DataResponse([
            'backgrounds' => $backgrounds,
            'builtins' => CertLayout::BACKGROUND_BUILTINS,
            'selected' => $selected,
            'intensity' => $layout['background_intensity'],
        ]);
    }

    /**
     * GitHub #93: a library background's image, for the choice in the course settings. Only for the
     * course's instructors.
     *
     * @return DataDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 300, period: 60)]
    public function backgroundImage(int $courseId, string $backgroundId): DataDisplayResponse|DataResponse {
        try {
            $this->courseService->assertInstructorOfCourse($courseId, (string)$this->userId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        return CertLogoController::imageResponse($this->backgroundLibrary, $backgroundId);
    }

    /**
     * GitHub #93: use a background on the course's certificates and set its strength. Takes effect at
     * once, like the logo; certificates already issued keep theirs.
     *
     * @param string $backgroundId a library background's id, 'builtin:<id>' for a built-in one, '' for none
     * @param int $intensity how strongly the background shows, 10–100 in steps of 10
     * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function selectBackground(int $courseId, string $backgroundId = '', int $intensity = CertLayout::BACKGROUND_INTENSITY_DEFAULT): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        }
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, $this->userId);
            $course = $this->layoutService->selectBackground($course, $backgroundId, $intensity);
            return new DataResponse($this->layoutService->presentationForCourse($course));
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'No permission'], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('cert layout background selection failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
