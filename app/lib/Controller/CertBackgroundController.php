<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CertBackgroundLibrary;
use OCA\Learning\Service\CertImageLibrary;
use OCA\Learning\Service\CertLibraryConflictException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GitHub #93: the certificate background library in Administration → Learning. Upload (PNG, JPEG or
 * WebP, checked by content and re-encoded), rename, delete, and the images themselves for the list.
 *
 * Admin-only. The routes carry no #[NoAdminRequired], and every method checks again. Deleting a
 * background that courses use leaves those courses without background; certificates already issued
 * keep their own copy (CertLayoutService::resetBackground).
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CertBackgroundController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CertBackgroundLibrary $library,
        private CertLayoutService $layoutService,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * The library with the number of courses that use each background.
     *
     * @return DataResponse<Http::STATUS_OK, array{backgrounds: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int, revision: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    public function index(): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return new DataResponse($this->describe());
    }

    /**
     * Add a background: multipart field `background` (the file) and `name`.
     *
     * @param string $name the name instructors choose the background by
     * @return DataResponse<Http::STATUS_OK, array{backgrounds: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int, revision: string, added: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_CONFLICT|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function upload(string $name = ''): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        $file = $this->request->getUploadedFile('background');
        if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $code = is_array($file) && in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'too_large'
                : 'no_file';
            return new DataResponse(['error' => $code], Http::STATUS_BAD_REQUEST);
        }
        if ((int)($file['size'] ?? 0) > CertBackgroundLibrary::MAX_BYTES) {
            return new DataResponse(['error' => 'too_large'], Http::STATUS_BAD_REQUEST);
        }
        $bytes = is_string($file['tmp_name'] ?? null) ? @file_get_contents($file['tmp_name'], false, null, 0, CertBackgroundLibrary::MAX_BYTES + 1) : false;
        if (!is_string($bytes) || $bytes === '') {
            return new DataResponse(['error' => 'no_file'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $entry = $this->library->add($name, $bytes);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (CertLibraryConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('certificate background upload failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'internal_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new DataResponse($this->describe() + ['added' => $entry['id']]);
    }

    /**
     * @param string $id the background's id (sha256 of its stored bytes)
     * @param string $name the new name
     * @param string $revision the revision of the list the name was changed in (GitHub #102); a list changed since answers 409 library_changed
     * @return DataResponse<Http::STATUS_OK, array{backgrounds: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int, revision: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function rename(string $id, string $name = '', string $revision = ''): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        try {
            $this->library->rename($id, $name, $revision);
        } catch (CertLibraryConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        return new DataResponse($this->describe());
    }

    /**
     * Delete a background. Courses that use it issue their next certificates without background
     * (courses_reset says how many).
     *
     * @param string $id the background's id
     * @param string $revision the revision of the list the background was deleted from (GitHub #102); a list changed since answers 409 library_changed
     * @return DataResponse<Http::STATUS_OK, array{backgrounds: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int, revision: string, courses_reset: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function delete(string $id, string $revision = ''): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        try {
            $this->library->delete($id, $revision);
        } catch (CertLibraryConflictException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        }
        $reset = $this->layoutService->resetBackground($id);
        return new DataResponse($this->describe() + ['courses_reset' => $reset]);
    }

    /**
     * The image of a library background, for the admin list.
     *
     * @param string $id the background's id
     * @return DataDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 300, period: 60)]
    public function image(string $id): DataDisplayResponse|DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return CertLogoController::imageResponse($this->library, $id);
    }

    /** @return array{backgrounds: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int, revision: string} */
    private function describe(): array {
        $list = $this->library->list();
        $backgrounds = [];
        foreach ($list as $entry) {
            $backgrounds[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'mime' => $entry['mime'],
                'size' => $entry['size'],
                'courses' => $this->layoutService->countCoursesUsingBackground($entry['id']),
            ];
        }
        return ['backgrounds' => $backgrounds, 'max' => CertBackgroundLibrary::MAX_BACKGROUNDS, 'revision' => CertImageLibrary::revisionOf($list)];
    }

    private function isAdmin(): bool {
        return $this->userId !== null && $this->userId !== '' && $this->groupManager->isAdmin($this->userId);
    }

    /** @return DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}> */
    private function forbidden(): DataResponse {
        return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
    }
}
