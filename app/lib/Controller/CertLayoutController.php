<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertLayout;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GitHub #43: the course's certificate layout preview and its stamp / signature images.
 *
 * Every action is for the course's instructors only (CourseService::assertInstructorOfCourse:
 * creator or co-instructor of THIS course). The text, the element switches and the signer are saved
 * with the other certification settings (PATCH cert-config, CourseController::updateCertConfig).
 */
class CertLayoutController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CourseService $courseService,
        private CertLayoutService $layoutService,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * The course's current layout with its images, for the preview in the settings.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
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
     *
     * @NoAdminRequired
     */
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
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
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
}
