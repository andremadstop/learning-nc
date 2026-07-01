<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\KeyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * AuditExportController — auditor-facing export of the compliance hash-chain (Phase 161, AUDIT-07).
 *
 * The Datenschutzbeauftragte / Betriebsrat downloads a deterministic JSONL event log, a detached
 * Ed25519 signature over the EXACT JSONL bytes, and a printable HTML report — WITHOUT admin rights
 * and WITHOUT shell access. Access is gated to members of the configurable 'learning-auditors' NC
 * group (@NoAdminRequired + IGroupManager::isInGroup), NOT to admins.
 *
 * Security invariants (do not relax):
 *   - @NoAdminRequired + @NoCSRFRequired are PHPDoc annotations (NOT PHP-8 attributes — the attribute
 *     form is not honoured in this NC build and 401s logged-in non-admins). The downloads/page are
 *     reached by browser navigation / <a> links (no requesttoken), so @NoCSRFRequired is required.
 *   - assertAuditorOrDie() is the FIRST line of every action; a non-auditor gets HTTP 403 and the DB
 *     is never touched (no event data leaks).
 *   - The JSONL NEVER carries user_id — only user_ref (the DSGVO pseudonymous HMAC identifier).
 *   - The signature is produced inline via KeyService (sodium_crypto_sign_detached), NOT via
 *     AuditCheckpointService (avoids cross-plan file conflicts); the secret is memzeroed immediately.
 *   - The .sig file is `bin2hex(sig) . "\n"` — a trailing newline. Verifiers MUST trim before hex2bin:
 *         sodium_crypto_sign_verify_detached(hex2bin(trim($sigFile)), $jsonlBytes, $pubKey)
 */
class AuditExportController extends Controller {
    private KeyService $keyService;
    private IGroupManager $groupManager;
    private IConfig $config;
    private IDBConnection $db;
    private IURLGenerator $urlGenerator;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        KeyService $keyService,
        IGroupManager $groupManager,
        IConfig $config,
        IDBConnection $db,
        IURLGenerator $urlGenerator,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->keyService = $keyService;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->db = $db;
        $this->urlGenerator = $urlGenerator;
        $this->userId = $userId;
    }

    /**
     * The auditor download page: GET /apps/learning/audit/export.
     *
     * A group-gated, self-contained server-rendered form (date-from / date-to / course-id + three
     * download links). Deliberately NOT embedded in AdminSettings (that needs admin and contradicts
     * @NoAdminRequired) — a non-admin auditor reaches it directly.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(): Http\Response {
        try {
            $this->assertAuditorOrDie();
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'Forbidden — auditor group required'], Http::STATUS_FORBIDDEN);
        }

        return new TemplateResponse('learning', 'audit-export-page', [
            'eventsUrl' => $this->urlGenerator->linkToRoute('learning.audit_export.events'),
            'sigUrl' => $this->urlGenerator->linkToRoute('learning.audit_export.sig'),
            'reportUrl' => $this->urlGenerator->linkToRoute('learning.audit_export.report'),
        ], 'user');
    }

    /**
     * GET /apps/learning/audit/export/events — deterministic JSONL event log.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function events(): Http\Response {
        try {
            $this->assertAuditorOrDie();
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'Forbidden — auditor group required'], Http::STATUS_FORBIDDEN);
        }

        $jsonl = $this->serializeJsonl($this->fetchEvents($this->parseFilters()));
        return new DataDownloadResponse($jsonl, 'audit-events.jsonl', 'application/jsonl');
    }

    /**
     * GET /apps/learning/audit/export/sig — detached Ed25519 signature over the exact JSONL bytes.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function sig(): Http\Response {
        try {
            $this->assertAuditorOrDie();
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'Forbidden — auditor group required'], Http::STATUS_FORBIDDEN);
        }

        // Regenerate the JSONL with the SAME filters — deterministic → byte-identical to /events → the
        // signature verifies against the downloaded .jsonl.
        $jsonlBytes = $this->serializeJsonl($this->fetchEvents($this->parseFilters()));
        $sigHex = $this->signJsonl($jsonlBytes);

        return new DataDownloadResponse($sigHex . "\n", 'audit-events.sig', 'application/octet-stream');
    }

    /**
     * GET /apps/learning/audit/export/report — printable HTML report (window.print() → PDF, zero deps).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function report(): Http\Response {
        try {
            $this->assertAuditorOrDie();
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'Forbidden — auditor group required'], Http::STATUS_FORBIDDEN);
        }

        $filters = $this->parseFilters();
        $events = $this->fetchEvents($filters);
        $jsonlBytes = $this->serializeJsonl($events);
        $sha256 = hash('sha256', $jsonlBytes);
        $sigHex = $this->signJsonl($jsonlBytes);

        $html = $this->renderReport($events, $filters, $sha256, $sigHex);
        return new DataDownloadResponse($html, 'audit-report.html', 'text/html');
    }

    /**
     * Hard gate: a member of the configurable auditor group only. Throws ForbiddenException otherwise;
     * every action catches it and returns a bare 403 (no event data, no DB access on the deny path).
     *
     * @throws ForbiddenException
     */
    private function assertAuditorOrDie(): void {
        $auditorGroup = $this->config->getAppValue('learning', 'auditor_group', 'learning-auditors');
        if ($this->userId === null || !$this->groupManager->isInGroup($this->userId, $auditorGroup)) {
            throw new ForbiddenException('Auditor role required');
        }
    }

    /**
     * Parse the optional query filters (unix timestamps + exact course id).
     *
     * @return array{from_date: ?int, to_date: ?int, course_id: ?int}
     */
    private function parseFilters(): array {
        $from = $this->request->getParam('from_date');
        $to = $this->request->getParam('to_date');
        $course = $this->request->getParam('course_id');

        return [
            'from_date' => ($from === null || $from === '') ? null : (int)$from,
            'to_date' => ($to === null || $to === '') ? null : (int)$to,
            'course_id' => ($course === null || $course === '') ? null : (int)$course,
        ];
    }

    /**
     * Fetch the compliance events (seq_num IS NOT NULL) for the given filters as ordered output rows.
     *
     * Date bounds are applied in SQL; the course_id filter is an EXACT PHP post-filter on the decoded
     * context_json (a LIKE '%"course_id":123%' is fragile — it also matches 1234 and other keys).
     * The output row NEVER contains user_id (DSGVO): only user_ref, plus course_id lifted from context.
     *
     * @param array{from_date: ?int, to_date: ?int, course_id: ?int} $filters
     * @return list<array{seq_num: int, event_key: string, user_ref: ?string, course_id: ?int, created_at: int, chain_hash: ?string, context_json: string}>
     */
    private function fetchEvents(array $filters): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('seq_num', 'event_key', 'user_ref', 'context_json', 'created_at', 'chain_hash')
            ->from('learning_audit_events')
            ->where($qb->expr()->isNotNull('seq_num'));

        if ($filters['from_date'] !== null) {
            $qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($filters['from_date'], IQueryBuilder::PARAM_INT)));
        }
        if ($filters['to_date'] !== null) {
            $qb->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($filters['to_date'], IQueryBuilder::PARAM_INT)));
        }
        $qb->orderBy('seq_num', 'ASC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $contextJson = (string)($row['context_json'] ?? '');
            $ctx = json_decode($contextJson, true);
            if (!is_array($ctx)) {
                $ctx = [];
            }
            $courseId = isset($ctx['course_id']) ? (int)$ctx['course_id'] : null;

            // Exact course match in PHP (skip non-matching rows entirely).
            if ($filters['course_id'] !== null && $courseId !== $filters['course_id']) {
                continue;
            }

            $out[] = [
                'seq_num' => (int)$row['seq_num'],
                'event_key' => (string)($row['event_key'] ?? ''),
                'user_ref' => isset($row['user_ref']) ? (string)$row['user_ref'] : null,
                'course_id' => $courseId,
                'created_at' => (int)($row['created_at'] ?? 0),
                'chain_hash' => isset($row['chain_hash']) ? (string)$row['chain_hash'] : null,
                'context_json' => $contextJson,
            ];
        }

        return $out;
    }

    /**
     * Serialize output rows to deterministic JSONL — one JSON object per line, fixed key order, same
     * flags every time, so the same filters always produce byte-identical output (→ a stable signature).
     *
     * @param list<array<string, mixed>> $events
     */
    private function serializeJsonl(array $events): string {
        $out = '';
        foreach ($events as $event) {
            $out .= (string)json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
        return $out;
    }

    /**
     * Sign the exact JSONL bytes with the active issuer Ed25519 key (INLINE — not via
     * AuditCheckpointService). Returns lowercase hex. The secret is memzeroed immediately after use.
     */
    private function signJsonl(string $jsonlBytes): string {
        $material = $this->keyService->getActiveSigningMaterial();
        $secretKeyRaw = $material['secret'];
        if (strlen($secretKeyRaw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Invalid Ed25519 key length');
        }
        $sigRaw = sodium_crypto_sign_detached($jsonlBytes, $secretKeyRaw);
        sodium_memzero($secretKeyRaw);
        return bin2hex($sigRaw);
    }

    /**
     * Render the self-contained HTML print report via output buffering (no NC template engine, no
     * external deps — fully portable HTML for browser print / PDF).
     *
     * @param list<array<string, mixed>> $events
     * @param array{from_date: ?int, to_date: ?int, course_id: ?int} $filters
     */
    private function renderReport(array $events, array $filters, string $sha256, string $sigHex): string {
        $vars = [
            'events' => $events,
            'filters' => $filters,
            'sha256' => $sha256,
            'sigHex' => $sigHex,
            'exportedAt' => time(),
            'total' => count($events),
        ];
        extract($vars, EXTR_OVERWRITE);
        ob_start();
        include __DIR__ . '/../../templates/audit-export-print.php';
        return (string)ob_get_clean();
    }
}
