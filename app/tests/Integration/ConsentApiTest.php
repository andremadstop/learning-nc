<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\TelosController;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Tests\Integration\Support\ContainerObjects;
use OCA\Learning\Tests\Integration\Support\MockLlmServer;
use OCP\AppFramework\App;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Plan Task B6 / spec 3.4: the consent endpoints through the real TelosController, ConsentService,
 * ConnectionStore and database on both engines. GET names the reason and (after an admin switch)
 * the previous recipient; POST for an outdated recipient is 409 and stores nothing; DELETE
 * revokes for real and is audited.
 */
final class ConsentApiTest extends TestCase {
    use ContainerObjects;

    private const UID = 'b6-consent-api-probe';

    private ContainerInterface $container;
    private IDBConnection $db;
    private IAppConfig $appConfig;
    private ConnectionStore $store;
    private int $auditFloor = 0;

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->db = Server::get(IDBConnection::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->store = $this->container->get(ConnectionStore::class);
        $this->clean();
        $this->auditFloor = $this->maxAuditId();
    }

    protected function tearDown(): void {
        $this->clean();
    }

    private function clean(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_user_telos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::UID)))
            ->executeStatement();
        foreach ($this->appConfig->getKeys('learning') as $key) {
            if ($key === ConnectionStore::KEY || $key === ConsentService::RECIPIENTS_KEY
                || str_starts_with($key, ConnectionStore::SAVED_PREFIX)) {
                $this->appConfig->deleteKey('learning', $key);
            }
        }
    }

    private function api(): TelosController {
        return $this->controllerFor($this->container, TelosController::class, self::UID);
    }

    /** @return array<string, mixed> */
    private function consentStatus(): array {
        $resp = $this->api()->getConsentStatus();
        $this->assertSame(200, $resp->getStatus());
        $data = $resp->getData();
        $this->assertIsArray($data);
        return $data;
    }

    private function maxAuditId(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('id'))->from('learning_audit_events');
        $r = $qb->executeQuery();
        $max = $r->fetchOne();
        $r->closeCursor();
        return (int)$max;
    }

    /** @return list<array{event_key: string, context: array<string, mixed>}> events of the probe user since setUp */
    private function auditEvents(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('event_key', 'context_json')->from('learning_audit_events')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::UID)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($this->auditFloor, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        $r = $qb->executeQuery();
        $out = [];
        foreach ($r->fetchAll() as $row) {
            $ctx = json_decode((string)$row['context_json'], true);
            $out[] = ['event_key' => (string)$row['event_key'], 'context' => is_array($ctx) ? $ctx : []];
        }
        $r->closeCursor();
        return $out;
    }

    private function connectOllama(): void {
        $this->store->save(LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => MockLlmServer::ollamaBaseUrl(),
            'model' => 'api-model', 'allowInternalNetwork' => true, 'processingLocal' => false,
        ]), 'admin');
    }

    public function testGrantAdminSwitchConflictAndRevokeThroughTheEndpoints(): void {
        $this->connectOllama();
        $status = $this->consentStatus();
        $this->assertFalse($status['valid']);
        $this->assertSame('never', $status['reason']);
        $this->assertNull($status['previous_recipient_name']);
        $ollama = $status['recipient'];
        $this->assertSame('Ollama (127.0.0.1)', $ollama['name']);
        $this->assertSame('unknown_recipient', $ollama['warning_key']);

        // the user consents to the recipient the dialog showed
        $resp = $this->api()->saveConsent($status['current_version'], $ollama['fingerprint'], 'dialog');
        $this->assertSame(200, $resp->getStatus(), (string)json_encode($resp->getData()));
        $this->assertTrue($this->consentStatus()['valid']);

        // the admin switches to Gemini
        $this->store->save(ConnectionStore::legacyGemini(true), 'admin');
        $status = $this->consentStatus();
        $this->assertFalse($status['valid']);
        $this->assertSame('recipient_changed', $status['reason']);
        $this->assertSame('Ollama (127.0.0.1)', $status['previous_recipient_name'], '"changed from {old} to {new}"');
        $this->assertSame('Google Gemini', $status['recipient']['name']);
        $this->assertSame('third_country', $status['recipient']['warning_key']);

        // a dialog still showing Ollama: 409 with the new recipient, nothing stored
        $resp = $this->api()->saveConsent($status['current_version'], $ollama['fingerprint'], 'dialog');
        $this->assertSame(409, $resp->getStatus());
        $this->assertSame('recipient_changed', $resp->getData()['error']);
        $this->assertSame($status['recipient']['fingerprint'], $resp->getData()['recipient']['fingerprint']);
        $this->assertSame('recipient_changed', $this->consentStatus()['reason'], 'the 409 stored nothing');

        // consent to Gemini, then revoke
        $resp = $this->api()->saveConsent($status['current_version'], $status['recipient']['fingerprint'], 'dialog');
        $this->assertSame(200, $resp->getStatus());
        $this->assertTrue($this->consentStatus()['valid']);

        $resp = $this->api()->revokeConsent();
        $this->assertSame(200, $resp->getStatus());
        $this->assertFalse($resp->getData()['valid']);
        $status = $this->consentStatus();
        $this->assertFalse($status['valid']);
        $this->assertSame('never', $status['reason']);
        $this->assertNull($status['previous_recipient_name']);

        $events = $this->auditEvents();
        $this->assertSame(['ai_consent_given', 'ai_consent_given', 'ai_consent_revoked'],
            array_column($events, 'event_key'), 'two grants (the 409 is none) and the revocation');
        $this->assertSame('ollama', $events[0]['context']['provider'] ?? null);
        $this->assertSame('gemini', $events[1]['context']['provider'] ?? null);
    }

    /** The remembered names are stored per recipient, never per user. */
    public function testTheRememberedRecipientNamesHoldNoUserId(): void {
        $this->connectOllama();
        $status = $this->consentStatus();
        $this->assertSame(200, $this->api()->saveConsent($status['current_version'], $status['recipient']['fingerprint'], 'onboarding')->getStatus());

        $this->appConfig->clearCache();
        $raw = $this->appConfig->getValueString('learning', ConsentService::RECIPIENTS_KEY, '');
        $this->assertStringContainsString('Ollama (127.0.0.1)', $raw);
        $this->assertStringNotContainsString(self::UID, $raw);
    }
}
