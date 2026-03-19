<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\NoAdminRequired;
use OCP\AppFramework\Http\Attributes\NoCSRFRequired;
use OCP\AppFramework\Http\Attributes\PublicPage;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;

class ExportController extends Controller {
    private QuestionService $questionService;
    private IDBConnection $db;
    private IConfig $config;
    private IURLGenerator $urlGenerator;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionService $questionService,
        IDBConnection $db,
        IConfig $config,
        IURLGenerator $urlGenerator,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->questionService = $questionService;
        $this->db = $db;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
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
        $cal = $this->buildIcsBody($this->userId);
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
        $nonce = $this->config->getUserValue($this->userId, 'learning', 'ics_nonce', '');
        if ($nonce === '') {
            $nonce = bin2hex(random_bytes(16));
            $this->config->setUserValue($this->userId, 'learning', 'ics_nonce', $nonce);
        }
        $token = $this->buildIcsToken($this->userId, $nonce);
        $url = $this->urlGenerator->linkToRouteAbsolute('learning.export.exportIcsPublic', ['token' => $token]);
        return new DataResponse(['token' => $token, 'url' => $url]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function regenerateCalendarToken(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $nonce = bin2hex(random_bytes(16));
        $this->config->setUserValue($this->userId, 'learning', 'ics_nonce', $nonce);
        // Remove old plaintext token if present from previous version
        $this->config->deleteUserValue($this->userId, 'learning', 'ics_token');
        $token = $this->buildIcsToken($this->userId, $nonce);
        $url = $this->urlGenerator->linkToRouteAbsolute('learning.export.exportIcsPublic', ['token' => $token]);
        return new DataResponse(['token' => $token, 'url' => $url]);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function exportIcsPublic(string $token): Http\Response {
        $userId = $this->validateIcsToken($token);
        if ($userId === null) {
            return new DataResponse(['error' => 'Invalid token'], Http::STATUS_FORBIDDEN);
        }
        $cal = $this->buildIcsBody($userId);
        return new DataDownloadResponse($cal, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }

    private function buildIcsBody(string $userId): string {
        $now = time();
        $horizon = $now + (30 * 24 * 3600); // 30 days ahead

        $qb = $this->db->getQueryBuilder();
        $qb->select('l.pool_id', 'p.name AS pool_name',
                    $qb->createFunction('COUNT(l.id) AS card_count'),
                    $qb->createFunction('MIN(l.next_review) AS earliest_due'))
            ->from('learning_leitner_items', 'l')
            ->join('l', 'learning_pools', 'p', $qb->expr()->eq('l.pool_id', 'p.id'))
            ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->lte('l.next_review', $qb->createNamedParameter($horizon)))
            ->groupBy('l.pool_id', 'p.name')
            ->orderBy('earliest_due', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // Use NC hostname for UIDs
        $host = parse_url($this->urlGenerator->getBaseUrl(), PHP_URL_HOST) ?: 'nextcloud';

        $dtstamp = gmdate('Ymd\THis\Z', $now);
        $events = [];

        foreach ($rows as $row) {
            $poolId   = (int)$row['pool_id'];
            $poolName = $row['pool_name'];
            $count    = (int)$row['card_count'];
            $due      = (int)$row['earliest_due'];

            // Overdue items appear as today
            $eventDay = $due < $now ? $now : $due;
            $dateStr  = gmdate('Ymd', $eventDay);
            $nextDay  = gmdate('Ymd', $eventDay + 86400);

            $uid     = 'learning-nc-' . md5($userId . '-' . $poolId) . '-' . $dateStr . '@' . $host;
            $summary = '📚 ' . $count . ' ' . ($count === 1 ? 'Karte' : 'Karten') . ' fällig — ' . $poolName;
            $desc    = $count . ' ' . ($count === 1 ? 'Karte wartet' : 'Karten warten') . ' auf Wiederholung in: ' . $poolName;

            $events[] = implode("\r\n", [
                'BEGIN:VEVENT',
                'UID:' . $uid,
                'DTSTAMP:' . $dtstamp,
                'DTSTART;VALUE=DATE:' . $dateStr,
                'DTEND;VALUE=DATE:' . $nextDay,
                'SUMMARY:' . $this->icsEscape($summary),
                'DESCRIPTION:' . $this->icsEscape($desc),
                'CATEGORIES:Lernen',
                'END:VEVENT',
            ]);
        }

        $eventBlock = count($events) > 0 ? "\r\n" . implode("\r\n", $events) : '';

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Learning-NC//Nextcloud//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Learning-NC Lernplan',
            'X-WR-CALDESC:Deine fälligen Karteikarten im Überblick',
            'X-WR-TIMEZONE:UTC',
        ]) . $eventBlock . "\r\nEND:VCALENDAR\r\n";
    }

    private function getOrCreateAppSecret(): string {
        $secret = $this->config->getAppValue('learning', 'ics_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->config->setAppValue('learning', 'ics_secret', $secret);
        }
        return $secret;
    }

    private function buildIcsToken(string $userId, string $nonce): string {
        $sig = hash_hmac('sha256', $userId . ':' . $nonce, $this->getOrCreateAppSecret());
        return rtrim(strtr(base64_encode($userId), '+/', '-_'), '=') . '.' . $sig;
    }

    private function validateIcsToken(string $token): ?string {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || strlen($parts[1]) !== 64) {
            return null;
        }
        $userId = base64_decode(strtr($parts[0], '-_', '+/'));
        if ($userId === false || $userId === '') {
            return null;
        }
        $nonce = $this->config->getUserValue($userId, 'learning', 'ics_nonce', '');
        if ($nonce === '') {
            return null;
        }
        $expected = hash_hmac('sha256', $userId . ':' . $nonce, $this->getOrCreateAppSecret());
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        return $userId;
    }

    private function icsEscape(string $value): string {
        $value = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $value);
        // Fold long lines at 75 chars
        return wordwrap($value, 70, "\r\n ", true);
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
