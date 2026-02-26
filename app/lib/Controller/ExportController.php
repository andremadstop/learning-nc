<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class ExportController extends Controller {
    private QuestionService $questionService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionService $questionService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->questionService = $questionService;
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
