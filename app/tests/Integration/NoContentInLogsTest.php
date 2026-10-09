<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\NoteGeneratorController;
use OCA\Learning\Controller\SupportTicketController;
use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Tests\Integration\Support\ContainerObjects;
use OCA\Learning\Tests\Integration\Support\MockLlmServer;
use OCP\AppFramework\App;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Plan Task E1 (spec 5.5): prompt, answer, API key and the provider's error text never reach
 * nextcloud.log or learning_audit_events — through chat, note, ticket triage and the error paths
 * (provider error, output blocked by the validator, admin connection test), against the real
 * server, the real gateway and MockLlmServer.
 *
 * The detector is proven live in every test before it may report "absent": the log level is set
 * to debug for the test (at the default WARN the gateway's info metric and every debug line would
 * never be written, and the test would pass on an empty file), a debug canary must be found in the
 * log file, every marker must be found where it is supposed to be (the mock's request log, the
 * answer the user gets), and the gateway's ai_call audit rows must exist. Markers are lowercase
 * hex only, so no log encoding (JSON escaping, lowercased file names) can hide them.
 */
final class NoContentInLogsTest extends TestCase {
    use ContainerObjects;

    private ContainerInterface $container;
    private IDBConnection $db;
    private IAppConfig $appConfig;
    private IConfig $config;
    private string $uid;
    private ?IUser $user = null;
    private mixed $previousLogLevel = null;
    private bool $hadLogLevel = false;
    private string $logFile;
    private int $logOffset;
    private string $promptMarker;
    private string $replyMarker;
    private string $keyMarker;

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->db = Server::get(IDBConnection::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->config = Server::get(IConfig::class);

        $this->previousLogLevel = $this->config->getSystemValue('loglevel', null);
        $this->hadLogLevel = $this->previousLogLevel !== null;
        $this->config->setSystemValue('loglevel', 0);   // debug: every line the app writes is written
        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data'), '/');
        $this->logFile = (string)$this->config->getSystemValue('logfile', $dataDir . '/nextcloud.log');
        clearstatcache();
        $this->logOffset = is_file($this->logFile) ? (int)filesize($this->logFile) : 0;

        $this->clearConnection();
        MockLlmServer::reset();

        // Shorter than 24 characters: LlmHttpClient redacts every longer [A-Za-z0-9_-] run from a
        // provider's error text, which would hide a longer marker from this test's error path.
        $this->promptMarker = 'e1prompt' . bin2hex(random_bytes(6));
        $this->replyMarker = 'e1reply' . bin2hex(random_bytes(6));
        $this->keyMarker = 'e1key' . bin2hex(random_bytes(6));

        $this->uid = 'e1-logs-' . bin2hex(random_bytes(4));
        $user = Server::get(IUserManager::class)->createUser($this->uid, bin2hex(random_bytes(16)) . 'Aa1!');
        $this->assertInstanceOf(IUser::class, $user, 'test user created');
        $this->user = $user;
    }

    protected function tearDown(): void {
        try {
            $poolIds = $this->ids('learning_pools', 'user_id', $this->uid);
            if ($poolIds !== []) {
                $this->deleteWhere('learning_sessions', 'pool_id', $poolIds);
                $this->deleteWhere('learning_pools', 'id', $poolIds);
            }
            foreach (['learning_support_tickets', 'learning_ai_chat_memory', 'learning_user_telos'] as $table) {
                $this->deleteWhere($table, 'user_id', [$this->uid]);
            }
            $this->user?->delete();
            foreach ($this->appConfig->getKeys('learning') as $key) {
                if (str_starts_with($key, ApiKeyStore::KEY_PREFIX)) {
                    $this->appConfig->deleteKey('learning', $key);
                }
            }
            $this->clearConnection();
            MockLlmServer::reset();
        } finally {
            if ($this->hadLogLevel) {
                $this->config->setSystemValue('loglevel', $this->previousLogLevel);
            } else {
                $this->config->deleteSystemValue('loglevel');
            }
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function clearConnection(): void {
        foreach ($this->appConfig->getKeys('learning') as $key) {
            if ($key === ConnectionStore::KEY || str_starts_with($key, ConnectionStore::SAVED_PREFIX)
                || $key === ConsentService::RECIPIENTS_KEY) {
                $this->appConfig->deleteKey('learning', $key);
            }
        }
    }

    /** @return list<int> */
    private function ids(string $table, string $column, string $value): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
        $r = $qb->executeQuery();
        $ids = array_map('intval', array_column($r->fetchAll(), 'id'));
        $r->closeCursor();
        return $ids;
    }

    /** @param list<string|int> $values */
    private function deleteWhere(string $table, string $col, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $type = is_int($values[0] ?? null) ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY;
        $qb->delete($table)->where($qb->expr()->in($col, $qb->createNamedParameter($values, $type)))->executeStatement();
    }

    /** Saves $c as the admin would and gives the test user consent to exactly that recipient. */
    private function connectAndConsent(LlmConnection $c): LlmConnection {
        $store = $this->container->get(ConnectionStore::class);
        $store->save($c, 'admin');
        $saved = $store->load();
        $consent = $this->container->get(ConsentService::class);
        $consent->grant($this->uid, $saved, $saved->recipientFingerprint(), $consent->currentVersion(), 'dialog');
        return $saved;
    }

    private static function ollamaOnMock(): LlmConnection {
        return LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => MockLlmServer::ollamaBaseUrl(),
            'model' => 'mock-model', 'allowInternalNetwork' => true, 'processingLocal' => false,
        ]);
    }

    /** Gemini's request shape, sent to the mock (which answers 404 with the body echoed) — never to Google. */
    private static function geminiOnMock(): LlmConnection {
        return LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'gemini', 'baseUrl' => MockLlmServer::ollamaBaseUrl() . '/v1beta',
            'model' => 'gemini-2.5-flash', 'allowInternalNetwork' => true,
        ]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $params
     * @return T
     */
    private function controller(string $class, array $params = []): object {
        return $this->controllerFor($this->container, $class, $this->uid, [], $params);
    }

    private function requestBodies(): string {
        return implode("\n", array_map(static fn (array $r): string => $r['path'] . ' ' . $r['body'], MockLlmServer::requests()));
    }

    /** @return list<array<string, mixed>> every audit row, every column */
    private function auditRows(): array {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('*')->from('learning_audit_events')->executeQuery();
        $rows = $r->fetchAll();
        $r->closeCursor();
        return $rows;
    }

    /** @return list<array<string, mixed>> the gateway's ai_call contexts for the test user */
    private function aiCalls(): array {
        $out = [];
        foreach ($this->auditRows() as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            if ($row['event_key'] === 'ai_call' && $row['user_id'] === $this->uid) {
                $ctx = json_decode((string)$row['context_json'], true);
                $out[] = is_array($ctx) ? $ctx : [];
            }
        }
        return $out;
    }

    /**
     * The measurement: a debug canary proves the log file is written at debug level, then neither
     * the appended log nor any column of any audit row may contain a marker.
     */
    private function assertNoMarkerLeaked(): void {
        $canary = 'e1canary' . bin2hex(random_bytes(8));
        Server::get(LoggerInterface::class)->debug('NoContentInLogsTest canary ' . $canary, ['app' => 'learning']);
        clearstatcache();
        $this->assertFileExists($this->logFile, 'nextcloud.log exists');
        $log = (string)file_get_contents($this->logFile, false, null, $this->logOffset);
        $this->assertStringContainsString($canary, $log,
            'the debug canary reached ' . $this->logFile . ' — otherwise "no marker in the log" proves nothing');

        $audit = json_encode($this->auditRows(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (['prompt' => $this->promptMarker, 'reply' => $this->replyMarker, 'key' => $this->keyMarker] as $what => $marker) {
            $this->assertSame([], self::entriesWith($log, $marker), "the $what marker is not in nextcloud.log");
            $this->assertStringNotContainsString($marker, $audit, "the $what marker is not in learning_audit_events");
        }
    }

    /**
     * The log entries that contain $marker, summarised (level, app, start of the message, where
     * the marker sits), so a failure names the writer instead of dumping the log.
     *
     * @return list<string>
     */
    private static function entriesWith(string $log, string $marker): array {
        $hits = [];
        foreach (explode("\n", $log) as $line) {
            if (!str_contains($line, $marker)) {
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                $hits[] = 'unparsable line: ' . substr($line, 0, 200);
                continue;
            }
            $message = is_string($entry['message'] ?? null) ? $entry['message'] : '';
            if (self::isCoreDebugTrace($entry, $message, $marker)) {
                continue;
            }
            $hits[] = sprintf('level=%s app=%s in=%s message=%s',
                (string)($entry['level'] ?? '?'), (string)($entry['app'] ?? '?'),
                str_contains($message, $marker) ? 'message' : 'exception/trace',
                substr($message, 0, 160));
        }
        return $hits;
    }

    /**
     * Nextcloud's own debug diagnostics (lib/private/DB, AppConfig) log a stack trace whose frames
     * carry the call arguments, so at log level debug any argument — the learner's message passed
     * to VirtuProfController::chat() — appears in their trace. They are written by the server, not
     * the app, and only at debug. Only those entries are excused, and only when the marker sits in
     * the trace, not in the message; any entry the app writes, an exception it logs included,
     * still counts at every level.
     *
     * @param array<mixed> $entry
     */
    private static function isCoreDebugTrace(array $entry, string $message, string $marker): bool {
        return ($entry['level'] ?? null) === 0
            && !str_contains($message, $marker)
            && preg_match('/^(dirty table reads: |The loading of lazy AppConfig values have been triggered by app )/', $message) === 1;
    }

    // ---- tests -------------------------------------------------------------------------------

    public function testChatNoteAndTriageLeaveNoContentInLogOrAudit(): void {
        $this->connectAndConsent(self::ollamaOnMock());
        MockLlmServer::setReply('Antwort ' . $this->replyMarker);

        // chat: the learner's message out, the model's answer back
        $resp = $this->controller(VirtuProfController::class)->chat('Was ist Subnetting ' . $this->promptMarker . '?');
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $this->assertStringContainsString($this->replyMarker, (string)json_encode($resp->getData()), 'the answer reached the learner');

        // note: the pool name is part of the prompt
        $poolId = $this->container->get(PoolService::class)
            ->create('Pool ' . $this->promptMarker, 'E1 note pool', $this->uid)->getId();
        $resp = $this->controller(NoteGeneratorController::class, ['pool_id' => $poolId])->generate();
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));

        // triage: subject and message of the ticket
        $resp = $this->controller(SupportTicketController::class)
            ->create('Login ' . $this->promptMarker, 'Ich komme nicht mehr rein ' . $this->promptMarker);
        $this->assertSame(201, $resp->getStatus(), (string)json_encode($resp->getData()));

        $requests = MockLlmServer::requests();
        $this->assertCount(3, $requests, 'chat, note and triage each sent once');
        foreach ($requests as $i => $request) {
            $this->assertStringContainsString($this->promptMarker, $request['body'], "request $i carried the marker");
        }
        $purposes = array_column($this->aiCalls(), 'purpose');
        sort($purposes);
        $this->assertSame(['chat', 'note', 'ticket_triage'], $purposes, 'the gateway audited each call');

        $this->assertNoMarkerLeaked();
    }

    public function testProviderErrorAndConnectionTestLeaveNoContentOrKeyInLogOrAudit(): void {
        // A stored key, the provider answers with an error that quotes the request.
        $connection = $this->connectAndConsent(self::geminiOnMock());
        $this->container->get(ApiKeyStore::class)->apply($connection, ['action' => 'replace', 'value' => $this->keyMarker]);

        $resp = $this->controller(VirtuProfController::class)->chat('Was ist Subnetting ' . $this->promptMarker . '?');
        $this->assertNotSame(500, $resp->getStatus(), (string)json_encode($resp->getData()));
        $this->assertStringNotContainsString($this->promptMarker, (string)json_encode($resp->getData()),
            'the provider error text does not reach the learner either');

        $this->assertStringContainsString(':generateContent', $this->requestBodies(), 'the Gemini request reached the mock');
        $this->assertStringContainsString($this->promptMarker, $this->requestBodies());
        $calls = $this->aiCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('bad_request', $calls[0]['category'] ?? null,
            'the 404 came back (with the stored key used, not a missing-key config error)');

        // The provider's error text does carry the learner's content — so the log check below is
        // a check of something that exists.
        $gateway = $this->container->get(LlmGateway::class);
        try {
            $gateway->send($gateway->begin($this->uid, LlmPurpose::Chat),
                new LlmRequest('System', 'Frage ' . $this->promptMarker, 0.7, 256));
            $this->fail('the mock answers 404');
        } catch (LlmProviderException $e) {
            $this->assertSame('bad_request', $e->category);
            $this->assertStringContainsString($this->promptMarker, $e->adminDetail, 'the error text quotes the prompt');
            $this->assertStringNotContainsString($this->promptMarker, $e->getMessage());
        }

        // The admin's connection test with a typed key: the detail the admin sees has the key redacted.
        $result = $gateway->testConnection(self::geminiOnMock(), $this->keyMarker);
        $this->assertFalse($result['ok']);
        $this->assertSame('bad_request', $result['category']);
        $this->assertStringNotContainsString($this->keyMarker, (string)$result['detail']);
        $this->assertCount(3, MockLlmServer::requests());

        $this->assertNoMarkerLeaked();
    }

    public function testAnAnswerBlockedByTheOutputValidatorLeavesNoContentInLogOrAudit(): void {
        $this->connectAndConsent(self::ollamaOnMock());
        MockLlmServer::setReply('password: ' . $this->replyMarker);

        $resp = $this->controller(VirtuProfController::class)->chat('Was ist Subnetting ' . $this->promptMarker . '?');
        $this->assertStringNotContainsString($this->replyMarker, (string)json_encode($resp->getData()), 'the blocked answer is withheld');
        $this->assertCount(1, MockLlmServer::requests());
        $this->assertStringContainsString($this->promptMarker, $this->requestBodies());

        $blocked = array_filter($this->auditRows(), function (array $row): bool {
            $row = array_change_key_case($row, CASE_LOWER);
            return $row['user_id'] === $this->uid && str_contains((string)$row['context_json'], 'output_blocked');
        });
        $this->assertCount(1, $blocked, 'the validator wrote its blocked row');

        $this->assertNoMarkerLeaked();
    }
}
