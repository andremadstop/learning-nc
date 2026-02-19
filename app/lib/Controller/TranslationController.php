<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\TranslationService;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class TranslationController extends Controller {
    private TranslationService $service;
    private QuestionService $questionService;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, TranslationService $service, QuestionService $questionService, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->questionService = $questionService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function questionTranslations(int $questionId): DataResponse {
        try {
            // Verify user has access to this question's pool
            $this->questionService->find($questionId, $this->userId);
            return new DataResponse($this->service->getQuestionTranslations($questionId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Question not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function setQuestionTranslation(int $questionId, string $lang, string $text, ?string $explanation = null): DataResponse {
        try {
            // SEC-MED-2: Require edit access, not just read access
            $question = $this->questionService->findEntity($questionId, $this->userId);
            $this->questionService->verifyEditAccess($question->getPoolId(), $this->userId);
            $trans = $this->service->setQuestionTranslation($questionId, $lang, $text, $explanation);
            return new DataResponse($trans, Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Question not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function deleteQuestionTranslation(int $questionId, string $lang): DataResponse {
        try {
            // SEC-MED-2: Require edit access, not just read access
            $question = $this->questionService->findEntity($questionId, $this->userId);
            $this->questionService->verifyEditAccess($question->getPoolId(), $this->userId);
            $this->service->deleteQuestionTranslation($questionId, $lang);
            return new DataResponse([], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Question not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function answerTranslations(int $answerId): DataResponse {
        try {
            // Verify access via the answer's question's pool
            $this->service->verifyAnswerAccess($answerId, $this->userId);
            return new DataResponse($this->service->getAnswerTranslations($answerId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Answer not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function setAnswerTranslation(int $answerId, string $lang, string $text): DataResponse {
        try {
            // SEC-MED-2: Require edit access, not just read access
            $this->service->verifyAnswerEditAccess($answerId, $this->userId);
            $trans = $this->service->setAnswerTranslation($answerId, $lang, $text);
            return new DataResponse($trans, Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Answer not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function deleteAnswerTranslation(int $answerId, string $lang): DataResponse {
        try {
            // SEC-MED-2: Require edit access, not just read access
            $this->service->verifyAnswerEditAccess($answerId, $this->userId);
            $this->service->deleteAnswerTranslation($answerId, $lang);
            return new DataResponse([], Http::STATUS_OK);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Answer not found or no access'], Http::STATUS_NOT_FOUND);
        }
    }
}
