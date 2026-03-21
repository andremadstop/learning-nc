<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\ImageService;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class ImageController extends Controller {
    private ImageService $imageService;
    private QuestionService $questionService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        ImageService $imageService,
        QuestionService $questionService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->imageService = $imageService;
        $this->questionService = $questionService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function upload(int $questionId): DataResponse {
        try {
            $file = $this->request->getUploadedFile('image');
            if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            $imagePath = $this->imageService->upload($this->userId, $file);
            try {
                $this->questionService->setImagePath($questionId, $imagePath, $this->userId);
            } catch (\Throwable $e) {
                $this->imageService->delete($imagePath); // rollback orphan file
                throw $e;
            }

            return new DataResponse(['image_path' => $imagePath], Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Image upload failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function serve(int $questionId): Response {
        try {
            $question = $this->questionService->findEntity($questionId, $this->userId);
            $imagePath = $question->getImagePath();

            if (empty($imagePath)) {
                return new DataResponse(['error' => 'No image'], Http::STATUS_NOT_FOUND);
            }

            $file = $this->imageService->getFile($imagePath);
            return new DataDownloadResponse(
                $file->getContent(),
                $file->getName(),
                $file->getMimeType()
            );
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Image not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function delete(int $questionId): DataResponse {
        try {
            $question = $this->questionService->findEntity($questionId, $this->userId);
            $imagePath = $question->getImagePath();

            if (!empty($imagePath)) {
                $this->questionService->setImagePath($questionId, null, $this->userId);
                $this->imageService->delete($imagePath);
            }

            return new DataResponse(null, Http::STATUS_NO_CONTENT);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Image not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
