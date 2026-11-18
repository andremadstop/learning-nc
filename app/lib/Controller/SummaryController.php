<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\CsvFormulaGuard;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class SummaryController extends Controller {
    private CourseSummaryService $summaryService;
    private CourseService $courseService;
    private TelosService $telosService;
    private XpService $xpService;
    private ?string $userId;
    private \OCA\Learning\Service\CsvExportL10n $csvL10n;

    public function __construct(
        string $appName,
        IRequest $request,
        CourseSummaryService $summaryService,
        CourseService $courseService,
        TelosService $telosService,
        XpService $xpService,
        ?string $userId,
        ?\OCP\IL10N $l10n = null
    ) {
        parent::__construct($appName, $request);
        $this->summaryService = $summaryService;
        $this->courseService = $courseService;
        $this->telosService = $telosService;
        $this->xpService = $xpService;
        $this->userId = $userId;
        $this->csvL10n = new \OCA\Learning\Service\CsvExportL10n($l10n);
    }

    /**
     * Student: get own course summary. Instructor: get class summary.
     */
    #[NoAdminRequired]
    public function getSummary(int $courseId): DataResponse {
        try {
            $course = $this->courseService->findById($courseId, $this->userId);
            $isInstructor = !empty($course['is_instructor']);

            if ($isInstructor) {
                $data = $this->summaryService->getClassSummary($courseId);
            } else {
                $data = $this->summaryService->getStudentSummary($courseId, $this->userId);
            }

            return new DataResponse($data);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Create a permanent snapshot of the student's course summary.
     */
    #[NoAdminRequired]
    public function createSnapshot(int $courseId): DataResponse {
        // findById() is the course access gate — every other endpoint in this controller passes
        // through it first. This one did not, so any logged-in user could snapshot an arbitrary
        // course id and read back what it contains.
        try {
            $this->courseService->findById($courseId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access to this course'], Http::STATUS_FORBIDDEN);
        }

        try {
            $id = $this->summaryService->createSnapshot($courseId, $this->userId);
            return new DataResponse(['id' => $id], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get an existing snapshot.
     */
    #[NoAdminRequired]
    public function getSnapshot(int $courseId): DataResponse {
        $snapshot = $this->summaryService->getSnapshot($courseId, $this->userId);
        if (!$snapshot) {
            return new DataResponse(['error' => 'No snapshot found'], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse($snapshot);
    }

    /**
     * Generate AI narrative portfolio for the student's course end summary.
     * Cached in snapshot on first generation. Returns null narrative if Gemini unconfigured.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 3600)]
    public function generateNarrative(int $courseId): DataResponse {
        try {
            $course = $this->courseService->findById($courseId, $this->userId);
            if (!empty($course['is_instructor'])) {
                return new DataResponse(['error' => 'Students only'], Http::STATUS_FORBIDDEN);
            }
            // DSGVO: Require AI consent before sending data to the AI provider. 5.5.2: through
            // hasAiConsent() (exact current version) — "not empty" accepted outdated consent.
            if ($this->userId === null || !$this->telosService->hasAiConsent($this->userId)) {
                return new DataResponse(['narrative' => null, 'cached' => false, 'consent_required' => true]);
            }
            $narrative = $this->summaryService->generateAndCacheNarrative($courseId, $this->userId);
            return new DataResponse(['narrative' => $narrative, 'cached' => $narrative !== null]);
        } catch (LlmConsentException $e) {
            // consent revoked or recipient changed after the check above: same answer as the check
            return new DataResponse(['narrative' => null, 'cached' => false, 'consent_required' => true]);
        } catch (\Exception $e) {
            return new DataResponse(['narrative' => null, 'cached' => false]);
        }
    }

    /**
     * Export class summary as CSV (instructor only).
     */
    #[NoAdminRequired]
    public function exportCsv(int $courseId): Http\Response {
        try {
            $course = $this->courseService->findById($courseId, $this->userId);
            if (empty($course['is_instructor'])) {
                return new DataResponse(['error' => 'Instructor only'], Http::STATUS_FORBIDDEN);
            }

            $data = $this->summaryService->getClassSummary($courseId);
            $columns = $this->classSummaryColumns($this->xpService->isGamificationEnabled());
            $handle = fopen('php://temp', 'r+');
            // GitHub #48: headers in the exporting user's language
            CsvFormulaGuard::writeRow($handle, array_column($columns, 0));

            foreach ($data['students'] as $uid => $s) {
                $row = [];
                foreach ($columns as [, $value]) {
                    $row[] = $value((string)$uid, $s);
                }
                CsvFormulaGuard::writeRow($handle, $row);
            }

            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            return new DataDownloadResponse(\OCA\Learning\Service\CsvExportL10n::BOM . $csv, 'class-summary-course-' . $courseId . '.csv', \OCA\Learning\Service\CsvExportL10n::CONTENT_TYPE);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Columns of the class summary CSV: header and value of each, in one list so that the header and
     * the rows cannot drift apart. GitHub #79: without gamification (the admin's switch, as in the
     * participant table since #36) XP, level, streak, badges, duels and swarm contributions are
     * frozen old values or zero, so those columns are left out.
     *
     * @return list<array{0: string, 1: callable(string, array<string, mixed>): (int|float|string)}>
     */
    private function classSummaryColumns(bool $gamificationEnabled): array {
        $header = $this->csvL10n->classSummaryHeader();
        $columns = [
            [$header[0], static fn (string $uid, array $s) => $uid, false],
            [$header[1], static fn (string $uid, array $s) => $s['xp']['total_xp'] ?? 0, true],
            [$header[2], static fn (string $uid, array $s) => $s['xp']['current_level'] ?? 1, true],
            [$header[3], static fn (string $uid, array $s) => $s['sessions']['overall_accuracy'] ?? 0, false],
            [$header[4], static fn (string $uid, array $s) => $s['mastery']['mastery_rate'] ?? 0, false],
            [$header[5], static fn (string $uid, array $s) => $s['sessions']['total_sessions'] ?? 0, false],
            [$header[6], static fn (string $uid, array $s) => $s['streak']['current_streak'] ?? 0, true],
            [$header[7], static fn (string $uid, array $s) => count($s['badges'] ?? []), true],
            [$header[8], static fn (string $uid, array $s) => $s['duels']['wins'] ?? 0, true],
            [$header[9], static fn (string $uid, array $s) => $s['swarm']['approved_contributions'] ?? 0, true],
        ];
        $kept = [];
        foreach ($columns as [$title, $value, $gamification]) {
            if ($gamification && !$gamificationEnabled) {
                continue;
            }
            $kept[] = [$title, $value];
        }
        return $kept;
    }
}
