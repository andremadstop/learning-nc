<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\PoolGeneratorService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PoolGeneratorController extends Controller {
    private PoolGeneratorService $service;
    private LoggerInterface $logger;
    private ?string $userId;
    private TelosService $telosService;

    public function __construct(
        string $appName,
        IRequest $request,
        PoolGeneratorService $service,
        LoggerInterface $logger,
        TelosService $telosService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->logger = $logger;
        $this->telosService = $telosService;
        $this->userId = $userId;
    }

    /**
     * AUDIT v5.2.1 (HIGH-03 consistency): per-user GDPR consent gate. Returns a consent_required
     * response when consent is missing (frontend routes the user to the global VirtuProf dialog).
     */
    private function aiConsentResponse(): ?JSONResponse {
        if ($this->userId === null || !$this->telosService->hasAiConsent($this->userId)) {
            return new JSONResponse(['error' => 'AI consent required', 'consent_required' => true], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * Get provider info (which AI backend is active).
     *
     * @NoAdminRequired
     */
    public function providerInfo(): JSONResponse {
        return new JSONResponse($this->service->getProviderInfo());
    }

    /**
     * Generate draft questions from pasted text.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function fromText(string $text, int $questionCount = 20): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (($consent = $this->aiConsentResponse()) !== null) {
            return $consent;
        }

        try {
            $drafts = $this->service->generateFromText($text, $this->userId, $questionCount);
            return new JSONResponse(['questions' => $drafts]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('PoolGenerator fromText error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate draft questions from a Nextcloud file.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function fromFile(string $filePath, int $questionCount = 20): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (($consent = $this->aiConsentResponse()) !== null) {
            return $consent;
        }

        try {
            $drafts = $this->service->generateFromFile($filePath, $this->userId, $questionCount);
            return new JSONResponse(['questions' => $drafts]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('PoolGenerator fromFile error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a pool from accepted draft questions.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function createPool(string $title, array $questions): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->service->createPool($title, $questions, $this->userId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('PoolGenerator createPool error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Pool creation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
