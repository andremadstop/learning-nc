<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * NoteGeneratorController — Phase 24: Note-Generator
 *
 * POST /api/notes/generate — generate an Obsidian-compatible Markdown summary
 *                            for a pool via Gemini and save it to /Learning/.
 *
 * Request body (JSON):
 *   { "pool_id": int, "course_id": int|null }
 *
 * Response (200):
 *   { "path": string, "updated": bool, "content": string }
 *
 * NOTE-01: Triggers Gemini summary generation
 * NOTE-02: Summary includes key points, common mistake, practice recommendation
 * NOTE-03: Wiki-links embedded in generated note
 * NOTE-04: Overwrites existing note when same pool is requested again
 */
class NoteGeneratorController extends Controller {
    private ?string $userId;
    private NoteGeneratorService $noteGeneratorService;
    private IDBConnection $db;
    private TelosService $telosService;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        NoteGeneratorService $noteGeneratorService,
        IDBConnection $db,
        TelosService $telosService
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->noteGeneratorService = $noteGeneratorService;
        $this->db = $db;
        $this->telosService = $telosService;
    }

    /**
     * Generate an Obsidian-compatible Markdown summary for a learning pool.
     *
     * Rate-limited to 3 requests/minute — Gemini calls are expensive.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 3, period: 60)]
    public function generate(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        // AUDIT v5.2.1 (HIGH-03 consistency): per-user GDPR consent gate before any LLM call.
        if (!$this->telosService->hasAiConsent($this->userId)) {
            return new DataResponse(['error' => 'AI consent required', 'consent_required' => true], Http::STATUS_FORBIDDEN);
        }

        $poolId  = (int)($this->request->getParam('pool_id', 0) ?? 0);
        $courseId = $this->request->getParam('course_id', null);
        $courseId = ($courseId !== null && $courseId !== '') ? (int)$courseId : null;

        if ($poolId <= 0) {
            return new DataResponse(['error' => 'pool_id is required and must be a positive integer'], Http::STATUS_BAD_REQUEST);
        }

        // Verify the user has access to this pool (ownership, share, or course membership)
        if (!$this->userCanAccessPool($this->userId, $poolId)) {
            return new DataResponse(['error' => 'Pool not found or access denied'], Http::STATUS_FORBIDDEN);
        }

        try {
            $result = $this->noteGeneratorService->generateSummary($this->userId, $poolId, $courseId);

            return new DataResponse([
                'path'    => $result['path'],
                'updated' => $result['updated'],
                'content' => $result['content'],
            ]);
        } catch (\RuntimeException $e) {
            // Check if it's a Gemini availability problem
            if (str_contains($e->getMessage(), 'API key not configured')) {
                return new DataResponse(
                    ['error' => 'AI service not configured. Please ask your administrator to configure the Gemini API key.'],
                    Http::STATUS_SERVICE_UNAVAILABLE
                );
            }
            if (str_contains($e->getMessage(), 'HTTP ') || str_contains($e->getMessage(), 'curl error')) {
                return new DataResponse(
                    ['error' => 'AI service temporarily unavailable. Please try again later.'],
                    Http::STATUS_SERVICE_UNAVAILABLE
                );
            }
            return new DataResponse(
                ['error' => 'Note generation failed: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Unexpected error during note generation'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the user can access the given pool.
     *
     * Access is granted if the user:
     * - owns the pool, OR
     * - has a pool_share record for it, OR
     * - is enrolled in a course that contains the pool, OR
     * - is the instructor of such a course
     */
    private function userCanAccessPool(string $userId, int $poolId): bool {
        // Check ownership
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('learning_pools')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $found = $result->fetch();
        $result->closeCursor();
        if ($found !== false) {
            return true;
        }

        // Check direct share
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('learning_pool_shares')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $found = $result->fetch();
        $result->closeCursor();
        if ($found !== false) {
            return true;
        }

        // Check course membership (enrolled or instructor)
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('cp.id')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
               $expr->eq('cm.course_id', 'c.id'),
               $expr->eq('cm.user_id', $qb->createNamedParameter($userId))
           ))
           ->where($expr->eq('cp.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($expr->orX(
               $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
               $expr->isNotNull('cm.id')
           ))
           ->setMaxResults(1);
        $result = $qb->executeQuery();
        $found = $result->fetch();
        $result->closeCursor();

        return $found !== false;
    }
}
