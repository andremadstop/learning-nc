<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\LernplanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * LernplanController — Phase 25: Lernplan + Fortschritt
 *
 * POST /api/lernbot/plan/generate       — generate Lernplan.md via Gemini
 * POST /api/lernbot/fortschritt/generate — generate Fortschritt.md (data-driven, no AI)
 *
 * Request body (JSON, both endpoints):
 *   { "course_id": int|null }
 *
 * Response (200):
 *   { "path": string, "updated": bool, "content": string }
 *
 * PLAN-01: generatePlan triggers Gemini weekly plan creation
 * PLAN-02: Lernplan.md has - [ ] Montag: ... checklist
 * PLAN-03: Writes /Learning/Lernplan.md
 * PLAN-04: generateFortschritt writes /Learning/Fortschritt.md with box stats + trend
 */
class LernplanController extends Controller {
    private ?string $userId;
    private LernplanService $lernplanService;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        LernplanService $lernplanService
    ) {
        parent::__construct($appName, $request);
        $this->userId          = $userId;
        $this->lernplanService = $lernplanService;
    }

    /**
     * Generate or regenerate /Learning/Lernplan.md via Gemini.
     *
     * Rate-limited to 3 requests/minute — Gemini calls are expensive.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 3, period: 60)]
    public function generatePlan(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $courseId = $this->request->getParam('course_id', null);
        $courseId = ($courseId !== null && $courseId !== '') ? (int)$courseId : null;

        try {
            $result = $this->lernplanService->generateWeeklyPlan($this->userId, $courseId);

            return new DataResponse([
                'path'    => $result['path'],
                'updated' => $result['updated'],
                'content' => $result['content'],
            ]);
        } catch (\RuntimeException $e) {
            return $this->handleRuntimeException($e);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Unexpected error during plan generation'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Generate or regenerate /Learning/Fortschritt.md (data-driven, no AI).
     *
     * Rate-limited to 3 requests/minute.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 3, period: 60)]
    public function generateFortschritt(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $courseId = $this->request->getParam('course_id', null);
        $courseId = ($courseId !== null && $courseId !== '') ? (int)$courseId : null;

        try {
            $result = $this->lernplanService->generateFortschritt($this->userId, $courseId);

            return new DataResponse([
                'path'    => $result['path'],
                'updated' => $result['updated'],
                'content' => $result['content'],
            ]);
        } catch (\RuntimeException $e) {
            return $this->handleRuntimeException($e);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Unexpected error during Fortschritt generation'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Map known RuntimeException messages to appropriate HTTP responses.
     */
    private function handleRuntimeException(\RuntimeException $e): DataResponse {
        $msg = $e->getMessage();

        if (str_contains($msg, 'API key not configured')) {
            return new DataResponse(
                ['error' => 'AI service not configured. Please ask your administrator to configure the Gemini API key.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        if (str_contains($msg, 'HTTP ') || str_contains($msg, 'curl error')) {
            return new DataResponse(
                ['error' => 'AI service temporarily unavailable. Please try again later.'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        if (str_contains($msg, 'not found')) {
            return new DataResponse(
                ['error' => $msg],
                Http::STATUS_NOT_FOUND
            );
        }

        return new DataResponse(
            ['error' => 'Generation failed: ' . $msg],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }
}
