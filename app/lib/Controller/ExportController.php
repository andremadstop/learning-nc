<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\IcsService;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\NoAdminRequired;
use OCP\AppFramework\Http\Attributes\NoCSRFRequired;
use OCP\AppFramework\Http\Attributes\PublicPage;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class ExportController extends Controller {
    private QuestionService $questionService;
    private IcsService $icsService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionService $questionService,
        IcsService $icsService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->questionService = $questionService;
        $this->icsService = $icsService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportCsv(int $poolId): Http\Response {
        try {
            $questions = $this->questionService->findByPool($poolId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no access'], Http::STATUS_FORBIDDEN);
        }

        $lines = [];
        foreach ($questions as $q) {
            $type = $q['question_type'] ?? 'single';
            $answers = $q['answers'] ?? [];

            if ($type === 'open') {
                // Open format: question,model_answer,open
                $modelAnswer = !empty($answers) ? $answers[0]['text'] : '';
                $lines[] = $this->csvLine([$q['text'], $modelAnswer, 'open']);
            } else {
                // MC format: question,answer1,...,answerN,correct_number[,explanation]
                $fields = [$q['text']];
                $correctIndex = null;
                foreach ($answers as $i => $a) {
                    $fields[] = $a['text'];
                    if (!empty($a['is_correct'])) {
                        $correctIndex = $i + 1;
                    }
                }
                $fields[] = (string)($correctIndex ?? 1);
                if (!empty($q['explanation'])) {
                    $fields[] = $q['explanation'];
                }
                $lines[] = $this->csvLine($fields);
            }
        }

        $csv = implode("\n", $lines);
        return new DataDownloadResponse($csv, 'pool-' . $poolId . '.csv', 'text/csv');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportJson(int $poolId): Http\Response {
        try {
            $questions = $this->questionService->findByPool($poolId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no access'], Http::STATUS_FORBIDDEN);
        }

        $export = [];
        foreach ($questions as $q) {
            $item = [
                'text' => $q['text'],
                'type' => $q['question_type'] ?? 'single',
            ];
            if (!empty($q['explanation'])) {
                $item['explanation'] = $q['explanation'];
            }
            $item['answers'] = array_map(function ($a) {
                return [
                    'text' => $a['text'],
                    'is_correct' => !empty($a['is_correct']),
                ];
            }, $q['answers'] ?? []);
            $export[] = $item;
        }

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return new DataDownloadResponse($json, 'pool-' . $poolId . '.json', 'application/json');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function exportIcs(): Http\Response {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $cal = $this->icsService->renderCalendarForUser($this->userId);
        return new DataDownloadResponse($cal, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function getCalendarToken(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse($this->icsService->ensureCalendarToken($this->userId));
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function regenerateCalendarToken(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse($this->icsService->regenerateCalendarToken($this->userId));
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function exportIcsPublic(string $token): Http\Response {
        $cal = $this->icsService->renderCalendarForToken($token);
        if ($cal === null) {
            return new DataResponse(['error' => 'Invalid token'], Http::STATUS_FORBIDDEN);
        }

        return new DataDownloadResponse($cal, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }

    private function csvLine(array $fields): string {
        // Escape formula injection: prefix dangerous start chars with tab
        $fields = array_map(function ($f) {
            if (is_string($f) && $f !== '' && in_array($f[0], ['=', '+', '-', '@'], true)) {
                return "\t" . $f;
            }
            return $f;
        }, $fields);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $fields);
        rewind($handle);
        $line = rtrim(stream_get_contents($handle), "\n");
        fclose($handle);
        return $line;
    }
}
