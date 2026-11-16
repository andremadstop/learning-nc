<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CertLogoLibrary;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GitHub #78: the certificate logo library in Administration → Learning. Upload (PNG, JPEG or
 * WebP, checked by content), rename, delete, and the images themselves for the list.
 *
 * Admin-only. The routes carry no @NoAdminRequired, and every method checks again. Deleting a logo
 * that courses use sets those courses back to the Theming logo; certificates already issued keep
 * their own copy (CertLayoutService::resetLogo).
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class CertLogoController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CertLogoLibrary $library,
        private CertLayoutService $layoutService,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * The library with the number of courses that use each logo.
     *
     * @return DataResponse<Http::STATUS_OK, array{logos: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    public function index(): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return new DataResponse($this->describe());
    }

    /**
     * Add a logo: multipart field `logo` (the file) and `name`.
     *
     * @param string $name the name instructors choose the logo by
     * @return DataResponse<Http::STATUS_OK, array{logos: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int, added: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function upload(string $name = ''): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        $file = $this->request->getUploadedFile('logo');
        if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $code = is_array($file) && in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'too_large'
                : 'no_file';
            return new DataResponse(['error' => $code], Http::STATUS_BAD_REQUEST);
        }
        if ((int)($file['size'] ?? 0) > CertLogoLibrary::MAX_BYTES) {
            return new DataResponse(['error' => 'too_large'], Http::STATUS_BAD_REQUEST);
        }
        $bytes = is_string($file['tmp_name'] ?? null) ? @file_get_contents($file['tmp_name'], false, null, 0, CertLogoLibrary::MAX_BYTES + 1) : false;
        if (!is_string($bytes) || $bytes === '') {
            return new DataResponse(['error' => 'no_file'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $entry = $this->library->add($name, $bytes);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('certificate logo upload failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'internal_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new DataResponse($this->describe() + ['added' => $entry['id']]);
    }

    /**
     * @param string $id the logo's id (sha256 of its bytes)
     * @param string $name the new name
     * @return DataResponse<Http::STATUS_OK, array{logos: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function rename(string $id, string $name = ''): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        try {
            $this->library->rename($id, $name);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        return new DataResponse($this->describe());
    }

    /**
     * Delete a logo. Courses that use it go back to the Theming logo (courses_reset says how many).
     *
     * @param string $id the logo's id
     * @return DataResponse<Http::STATUS_OK, array{logos: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int, courses_reset: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function delete(string $id): DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        try {
            $this->library->delete($id);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        }
        $reset = $this->layoutService->resetLogo($id);
        return new DataResponse($this->describe() + ['courses_reset' => $reset]);
    }

    /**
     * The image of a library logo, for the admin list.
     *
     * @NoCSRFRequired
     *
     * @param string $id the logo's id
     * @return DataDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    #[UserRateLimit(limit: 300, period: 60)]
    public function image(string $id): DataDisplayResponse|DataResponse {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }
        return self::imageResponse($this->library, $id);
    }

    /**
     * A library logo as an image response: the type checked from the content again, never sniffed
     * by the browser, private and not cached beyond the session.
     *
     * @return DataDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
     */
    public static function imageResponse(CertLogoLibrary $library, string $id): DataDisplayResponse|DataResponse {
        if (!CertLogoLibrary::isId($id)) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        }
        try {
            $logo = $library->content($id);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        }
        return new DataDisplayResponse($logo['bytes'], Http::STATUS_OK, [
            'Content-Type' => $logo['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @return array{logos: list<array{id: string, name: string, mime: string, size: int, courses: int}>, max: int} */
    private function describe(): array {
        $logos = [];
        foreach ($this->library->list() as $entry) {
            $logos[] = [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'mime' => $entry['mime'],
                'size' => $entry['size'],
                'courses' => $this->layoutService->countCoursesUsingLogo($entry['id']),
            ];
        }
        return ['logos' => $logos, 'max' => CertLogoLibrary::MAX_LOGOS];
    }

    private function isAdmin(): bool {
        return $this->userId !== null && $this->userId !== '' && $this->groupManager->isAdmin($this->userId);
    }

    /** @return DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}> */
    private function forbidden(): DataResponse {
        return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
    }
}
