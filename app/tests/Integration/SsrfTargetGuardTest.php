<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Tests\Integration\Support\ContainerObjects;
use OCA\Learning\Tests\Integration\Support\MockLlmServer;
use OCP\AppFramework\App;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Plan Task E2, spec 5.3, Review Focus 4: with allow_local_remote_servers=true in config.php —
 * which switches off Nextcloud's own local-address check and DNS pinning — a connection on a
 * loopback address (literal or a host name that resolves to it) without allowInternalNetwork is
 * refused by the app's TargetGuard with a `config` error and MockLlmServer sees 0 requests: in the
 * admin's connection test (LlmGateway::testConnection, what POST /api/admin/ai/test calls) and on
 * the real Ollama path of a learner's chat.
 *
 * The mock speaks plain HTTP, and a refused connection must use https (fromArray allows http only
 * with allowInternalNetwork). A TLS attempt against it would not be logged either, so "0 requests"
 * alone could not tell the guard from a failed handshake. The tests therefore also require the
 * guard's own refusal (category `config` with TargetGuard's internal-address detail, or a `config`
 * ai_call audit row instead of `network`), and two positive controls show that the flag really
 * disables Nextcloud's check and that the same target is reachable once the internal network is
 * allowed — the guard is the only thing standing in between.
 */
final class SsrfTargetGuardTest extends TestCase {
    use ContainerObjects;

    private const INTERNAL_DETAIL = 'Target resolves to an internal address; enable the internal network for this connection';
    /** Loopback as an IPv4 literal, an IPv6 literal, and a host name that resolves to it (/etc/hosts). */
    private const LOOPBACK_URLS = ['https://127.0.0.1:18080', 'https://[::1]:18080', 'https://localhost:18080'];

    private ContainerInterface $container;
    private IConfig $config;
    private IAppConfig $appConfig;
    private mixed $previousFlag = null;
    private ?IUser $user = null;

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->config = Server::get(IConfig::class);
        $this->appConfig = Server::get(IAppConfig::class);
        $this->previousFlag = $this->config->getSystemValue('allow_local_remote_servers', null);
        $this->config->setSystemValue('allow_local_remote_servers', true);
        $this->clearConnection();
        MockLlmServer::reset();
    }

    protected function tearDown(): void {
        try {
            if ($this->user !== null) {
                $uid = $this->user->getUID();
                $db = Server::get(IDBConnection::class);
                foreach (['learning_ai_chat_memory', 'learning_user_telos'] as $table) {
                    $qb = $db->getQueryBuilder();
                    $qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
                }
                $this->user->delete();
                $this->user = null;
            }
            $this->clearConnection();
            MockLlmServer::reset();
        } finally {
            if ($this->previousFlag === null) {
                $this->config->deleteSystemValue('allow_local_remote_servers');
            } else {
                $this->config->setSystemValue('allow_local_remote_servers', $this->previousFlag);
            }
        }
    }

    private function clearConnection(): void {
        foreach ($this->appConfig->getKeys('learning') as $key) {
            if ($key === ConnectionStore::KEY || str_starts_with($key, ConnectionStore::SAVED_PREFIX)
                || $key === ConsentService::RECIPIENTS_KEY) {
                $this->appConfig->deleteKey('learning', $key);
            }
        }
    }

    private static function ollama(string $baseUrl, bool $allowInternalNetwork): LlmConnection {
        return LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => $baseUrl,
            'model' => 'mock-model', 'allowInternalNetwork' => $allowInternalNetwork, 'processingLocal' => false,
        ]);
    }

    private static function sent(): int {
        return count(MockLlmServer::requests());
    }

    /** Positive control: the flag is really on, Nextcloud no longer refuses a local target itself. */
    public function testWithTheFlagNextcloudItselfLetsALocalAddressThrough(): void {
        $this->assertTrue($this->config->getSystemValueBool('allow_local_remote_servers', false));

        $response = Server::get(IClientService::class)->newClient()->post(MockLlmServer::baseUrl() . '/chat/completions', [
            'body' => '{"model":"e2-control"}',
            'headers' => ['Content-Type' => 'application/json'],
            'nextcloud' => ['allow_local_address' => false],
            'timeout' => 10,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, self::sent(), 'the request reached the mock: only the app\'s guard is left');
    }

    public function testTheConnectionTestRefusesLoopbackWithoutTheInternalNetwork(): void {
        $gateway = $this->container->get(LlmGateway::class);

        foreach (self::LOOPBACK_URLS as $url) {
            $result = $gateway->testConnection(self::ollama($url, false), null);

            $this->assertFalse($result['ok'], $url);
            $this->assertSame('config', $result['category'], "$url: refused before any connection, not a failed one");
            $this->assertSame(self::INTERNAL_DETAIL, $result['detail'], "$url: refused by the internal-address rule");
            $this->assertSame(0, self::sent(), "$url: no request");
        }

        // Positive control: the same target with the internal network allowed is reached.
        $result = $gateway->testConnection(self::ollama(MockLlmServer::ollamaBaseUrl(), true), null);
        $this->assertTrue($result['ok'], (string)json_encode($result));
        $this->assertSame(1, self::sent());
    }

    public function testALearnersChatOnASavedLoopbackOllamaSendsNothing(): void {
        $uid = 'e2-ssrf-' . bin2hex(random_bytes(4));
        $user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(16)) . 'Aa1!');
        $this->assertInstanceOf(IUser::class, $user);
        $this->user = $user;
        $store = $this->container->get(ConnectionStore::class);
        $consent = $this->container->get(ConsentService::class);

        foreach (['https://127.0.0.1:18080', 'https://localhost:18080'] as $url) {
            $store->save(self::ollama($url, false), 'admin');
            $c = $store->load();
            $this->assertSame('ollama', $c->provider);
            $this->assertFalse($c->allowInternalNetwork);
            $consent->grant($uid, $c, $c->recipientFingerprint(), $consent->currentVersion(), 'dialog');

            $resp = $this->controllerFor($this->container, VirtuProfController::class, $uid)->chat('Was ist Subnetting?');

            $this->assertStringNotContainsString(MockLlmServer::REPLY, (string)json_encode($resp->getData()), $url);
            $this->assertSame(0, self::sent(), "$url: no request left the instance");
        }
        // one ai_call per chat, each refused by the gateway (config) — not a failed connection (network)
        $this->assertSame(['config', 'config'], $this->aiCallCategories($uid));
    }

    /** @return list<?string> the category of every ai_call audit row of $uid, oldest first */
    private function aiCallCategories(string $uid): array {
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $qb->select('context_json')->from('learning_audit_events')
            ->where($qb->expr()->eq('event_key', $qb->createNamedParameter('ai_call')))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->orderBy('id', 'ASC');
        $r = $qb->executeQuery();
        $out = [];
        while (($row = $r->fetch()) !== false) {
            $ctx = json_decode((string)$row['context_json'], true);
            $out[] = is_array($ctx) ? ($ctx['category'] ?? null) : null;
        }
        $r->closeCursor();
        return $out;
    }
}
